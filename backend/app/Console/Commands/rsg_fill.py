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
        
        # 1. FTP 下載設定
        self.ftp_host = os.getenv(f"FTP_{self.site}_HOST")
        self.ftp_user = os.getenv(f"FTP_{self.site}_USER")
        self.ftp_pass = os.getenv(f"FTP_{self.site}_PASS")

        # 2. 補單網址設定
        base_url = os.getenv(f"{self.site}_BASE_URL")
        self.report_url = f"{base_url.rstrip('/')}/report/GeneralReport" if base_url else None
        
        # 3. 遠端 SSH/SCP 設定 (動態從 env 抓取 IP)
        remote_ip = os.getenv(f"{self.site}_IP")
        self.remote_user_host = f"terry@{remote_ip}" if remote_ip else None
        self.remote_upload_path = "/var/tmp/upload"

        # 4. 補單控制
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
            ftp = FTP(self.ftp_host)
            ftp.login(self.ftp_user, self.ftp_pass)
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
                        print(f"\n📂 下載目錄: {remote_dir}")
                        for idx, f_name in enumerate(files, 1):
                            with open(os.path.join(local_dir, f_name), 'wb') as f:
                                ftp.retrbinary(f"RETR {f_name}", f.write)
                            percent = (idx / total_files) * 100
                            print(f"\r   🚀 進度: [{idx}/{total_files}] {percent:.1f}% - {f_name}", end="")
                        print()
                    except: continue
                curr += timedelta(days=1)
            ftp.quit()
        except Exception as e:
            print(f"\n❌ FTP 下載錯誤: {e}")

    def process_data(self):
        """處理 CSV 並轉換 JSON"""
        csv_dir = os.path.join(self.site_path, 'csv_data')
        json_dir = os.path.join(self.site_path, 'json_data')
        
        for d in [csv_dir, json_dir]:
            if os.path.exists(d): shutil.rmtree(d)
            os.makedirs(d)

        print(f"\n--- 階段 1: 提取 CSV ---")
        for root, _, files in os.walk(self.site_path):
            if 'csv_data' in root or 'json_data' in root: continue
            date_label = next((p for p in root.split(os.sep) if len(p)==8 and p.isdigit()), "data")
            for file in files:
                if file.lower().endswith('.zip'):
                    self._extract_zip(os.path.join(root, file), date_label, csv_dir)

        print(f"--- 階段 2: 轉換 JSON ---")
        csv_files = [f for f in os.listdir(csv_dir) if f.endswith('.csv')]
        data_pool, file_count = [], 1
        for f_name in csv_files:
            try:
                df = pd.read_csv(os.path.join(csv_dir, f_name))
                data_pool.extend(df.where(pd.notnull(df), None).to_dict(orient='records'))
                while len(data_pool) >= 1000:
                    self._save_json(data_pool[:1000], file_count, json_dir)
                    data_pool = data_pool[1000:]; file_count += 1
            except: continue
        if data_pool: self._save_json(data_pool, file_count, json_dir)
        
        json_files = [f for f in os.listdir(json_dir) if f.endswith('.json')]
        print(f"📊 轉換完畢：共產出 {len(json_files)} 個 JSON 檔案")
        return len(json_files)

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

    def sync_to_remote(self):
        """透過 SSH 清空遠端並 SCP 上傳"""
        if not self.remote_user_host:
            print(f"\n⚠️ 跳過同步：找不到 {self.site}_IP 環境變數")
            return

        local_json_dir = os.path.join(self.site_path, 'json_data')
        
        print(f"\n🌐 準備同步至遠端 {self.remote_user_host}...")
        
        # 1. 透過 SSH 清空遠端目錄
        print(f"🗑️ 清空遠端目錄: {self.remote_upload_path}")
        ssh_cmd = f'ssh {self.remote_user_host} "rm -rf {self.remote_upload_path}/*"'
        os.system(ssh_cmd)
        
        # 2. 透過 SCP 上傳本地所有 JSON
        print(f"📤 執行 SCP 批次上傳...")
        scp_cmd = f'scp {local_json_dir}/*.json {self.remote_user_host}:{self.remote_upload_path}'
        exit_code = os.system(scp_cmd)
        
        if exit_code == 0:
            print("✅ 遠端同步完成")
        else:
            print("❌ 遠端同步失敗，請檢查網路連線或 SSH 權限")

    def run_report(self, file_count):
        """根據 JSON 檔案數量執行補單"""
        if not self.report_url or file_count == 0:
            print("❌ 無法補單：網址未設定或無檔案"); return

        print(f"\n🚀 啟動補單：{self.report_url}")
        print(f"🚀 範圍：tt=1 ~ tt={file_count}")
        
        success, fail = 0, 0
        with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            tasks = {executor.submit(self._fetch, tt): tt for tt in range(1, file_count + 1)}
            for i, future in enumerate(as_completed(tasks), 1):
                _, status = future.result()
                with self.lock:
                    if status == 200: success += 1
                    else: fail += 1
                print(f"\r   📡 補單進度: {i}/{file_count} [✅:{success} ❌:{fail}]", end="")
        print(f"\n\n🏁 流程結束！")

    def _fetch(self, tt):
        if not hasattr(self.thread_local, "session"): self.thread_local.session = requests.Session()
        try:
            resp = self.thread_local.session.get(self.report_url, params={"gm": self.gm, "tt": tt}, timeout=30)
            return tt, resp.status_code
        except: return tt, 999

if __name__ == "__main__":
    import sys
    if len(sys.argv) < 2:
        print("用法: python3 script.py {SITE} {START_DATE} {END_DATE?}")
    else:
        site_name = sys.argv[1].upper()
        s_date = sys.argv[2] if len(sys.argv) > 2 else datetime.now().strftime('%Y-%m-%d')
        e_date = sys.argv[3] if len(sys.argv) > 3 else None
        
        runner = RsgCompleteRunner(site_name)
        runner.download_ftp(s_date, e_date)
        json_files_num = runner.process_data() # 1. 取得 JSON 檔案數量
        runner.sync_to_remote()                # 2. 同步至對應 IP 機器
        runner.run_report(json_files_num)      # 3. 補單 (END = 檔案數量)