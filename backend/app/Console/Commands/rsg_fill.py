import os
import zipfile
import shutil
import tempfile
import json
import pandas as pd
import requests
import threading
from ftplib import FTP
from datetime import datetime, timedelta
from dotenv import load_dotenv
from concurrent.futures import ThreadPoolExecutor, as_completed

# 加載環境變數
load_dotenv()

class RsgCompleteRunner:
    def __init__(self, site):
        self.site = site.upper()
        self.base_root = '/Users/chacha/Downloads/RSG'
        self.site_path = os.path.join(self.base_root, self.site)
        
        # 1. FTP 設定
        self.host = os.getenv(f"FTP_{self.site}_HOST")
        self.user = os.getenv(f"FTP_{self.site}_USER")
        self.password = os.getenv(f"FTP_{self.site}_PASS")

        # 2. 補單網址設定：根據 SITE 取得對應的 BASE_URL 並加上路徑
        base_url = os.getenv(f"{self.site}_BASE_URL")
        self.report_url = f"{base_url.rstrip('/')}/report/GeneralReport" if base_url else None
        
        # 3. 補單參數
        self.gm = 152
        self.max_workers = 20
        self.lock = threading.Lock()
        self.thread_local = threading.local()

    def download_ftp(self, start_date_str, end_date_str=None):
        """下載 FTP 檔案並顯示進度"""
        if not end_date_str: end_date_str = start_date_str
        start_date = datetime.strptime(start_date_str, '%Y-%m-%d')
        end_date = datetime.strptime(end_date_str, '%Y-%m-%d')

        try:
            ftp = FTP(self.host)
            ftp.login(self.user, self.password)
            ftp.set_pasv(True)
            
            root_folders = [f for f in ftp.nlst() if f not in ['report_daily', '.', '..']]
            
            curr = start_date
            while curr <= end_date:
                date_str = curr.strftime('%Y%m%d')
                for root_f in root_folders:
                    remote_dir = f"{root_f}/{date_str}"
                    try:
                        ftp.cwd('/')
                        ftp.cwd(remote_dir)
                        files = [f for f in ftp.nlst() if f not in ['.', '..']]
                        if not files: continue

                        local_dir = os.path.join(self.site_path, date_str, root_f)
                        os.makedirs(local_dir, exist_ok=True)
                        
                        total_files = len(files)
                        print(f"\n📂 進入目錄: {remote_dir} (共 {total_files} 個檔案)")

                        for idx, f_name in enumerate(files, 1):
                            local_file_path = os.path.join(local_dir, f_name)
                            with open(local_file_path, 'wb') as f:
                                ftp.retrbinary(f"RETR {f_name}", f.write)
                            
                            # 顯示下載進度
                            percent = (idx / total_files) * 100
                            print(f"\r   🚀 下載進度: [{idx}/{total_files}] {percent:.1f}% - {f_name}", end="")
                        print() 
                    except: continue
                curr += timedelta(days=1)
            ftp.quit()
        except Exception as e:
            print(f"\n❌ FTP 錯誤: {e}")

    def process_and_count_files(self):
        """處理資料並統計生成的 JSON 檔案數量"""
        csv_dir = os.path.join(self.site_path, 'csv_data')
        json_dir = os.path.join(self.site_path, 'json_data')
        
        if os.path.exists(csv_dir): shutil.rmtree(csv_dir)
        if os.path.exists(json_dir): shutil.rmtree(json_dir)
        os.makedirs(csv_dir, exist_ok=True)
        os.makedirs(json_dir, exist_ok=True)

        print(f"\n--- 階段 1: 提取 CSV ---")
        for root, _, files in os.walk(self.site_path):
            if 'csv_data' in root or 'json_data' in root: continue
            date_label = next((p for p in root.split(os.sep) if len(p)==8 and p.isdigit()), "data")
            for file in files:
                if file.lower().endswith('.zip'):
                    self._extract_zip(os.path.join(root, file), date_label, csv_dir)

        print(f"--- 階段 2: 轉換 JSON (每 1000 筆分塊) ---")
        csv_files = [f for f in os.listdir(csv_dir) if f.endswith('.csv')]
        data_pool = []
        file_count = 1
        for f_name in csv_files:
            try:
                df = pd.read_csv(os.path.join(csv_dir, f_name))
                data_pool.extend(df.where(pd.notnull(df), None).to_dict(orient='records'))
                while len(data_pool) >= 1000:
                    self._save_json(data_pool[:1000], file_count, json_dir)
                    data_pool = data_pool[1000:]; file_count += 1
            except: continue
        if data_pool: self._save_json(data_pool, file_count, json_dir)

        # --- 核心修改：計算 JSON 檔案數量 ---
        json_files = [f for f in os.listdir(json_dir) if f.endswith('.json')]
        total_json_files = len(json_files)
        
        print(f"📊 檢查結果：最終產出 {total_json_files} 個 JSON 檔案")
        return total_json_files

    def _extract_zip(self, zip_path, date_label, output_dir):
        with tempfile.TemporaryDirectory() as tmp:
            try:
                with zipfile.ZipFile(zip_path, 'r') as z: z.extractall(tmp)
                for r, _, files in os.walk(tmp):
                    for f in files:
                        if f.lower().endswith('.csv'):
                            shutil.copy2(os.path.join(r, f), os.path.join(output_dir, f"{date_label}_{f}"))
            except: pass

    def _save_json(self, data, i, path):
        with open(os.path.join(path, f"combined_part_{i}.json"), 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=4)

    # --- 補單 API ---
    def get_session(self):
        if not hasattr(self.thread_local, "session"):
            self.thread_local.session = requests.Session()
        return self.thread_local.session

    def run_report(self, end_count):
        """根據 JSON 檔案數量執行補單"""
        if not self.report_url:
            print(f"❌ 錯誤：未設定 {self.site}_BASE_URL，無法補單。"); return
        if end_count == 0:
            print("❌ JSON 檔案數量為 0，取消補單。"); return

        print(f"\n🚀 啟動 API 補單：{self.report_url}")
        print(f"🚀 目標範圍：tt=1 ~ tt={end_count} (對應 {end_count} 個 JSON 檔案)")
        
        success, fail = 0, 0
        with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            tasks = {executor.submit(self._fetch, tt): tt for tt in range(1, end_count + 1)}
            for i, future in enumerate(as_completed(tasks), 1):
                tt, status = future.result()
                with self.lock:
                    if status == 200: success += 1
                    else: fail += 1
                if i % 5 == 0 or i == end_count:
                    print(f"\r   📡 補單進度: {i}/{end_count} [✅:{success} ❌:{fail}]", end="")
        print(f"\n\n🏁 所有任務執行完畢！")

    def _fetch(self, tt):
        session = self.get_session()
        try:
            resp = session.get(self.report_url, params={"gm": self.gm, "tt": tt}, timeout=30)
            return tt, resp.status_code
        except: return tt, 999

if __name__ == "__main__":
    import sys
    if len(sys.argv) < 3:
        print("用法: python3 rsg_complete_runner.py {SITE} {START_DATE} {END_DATE?}")
    else:
        site_name = sys.argv[1].upper()
        s_date, e_date = sys.argv[2], (sys.argv[3] if len(sys.argv) > 3 else None)
        
        runner = RsgCompleteRunner(site_name)
        # 1. 下載
        runner.download_ftp(s_date, e_date)
        # 2. 處理並取得 JSON 檔案數量
        file_count = runner.process_and_count_files()
        # 3. 執行補單 (tt 從 1 到 檔案數量)
        runner.run_report(file_count)