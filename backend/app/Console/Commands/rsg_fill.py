import os
import zipfile
import shutil
import tempfile
import json
import pandas as pd
import requests
import threading
import time
import paramiko
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

        # 2. 補單與報表網址設定
        base_url = os.getenv(f"{self.site}_BASE_URL")
        self.base_url_stripped = base_url.rstrip('/') if base_url else ""
        self.general_report_url = f"{self.base_url_stripped}/report/GeneralReport"
        self.rpt_url = f"{self.base_url_stripped}/report/Rpt"
        
        # 3. 遠端 SSH/SCP 設定
        remote_ip = os.getenv(f"{self.site}_IP")
        self.remote_user_host = f"terry@{remote_ip}" if remote_ip else None
        self.remote_upload_path = "/var/tmp/upload"

        # 4. 控制參數
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
        os.system(f'ssh {self.remote_user_host} "rm -rf {self.remote_upload_path}/*"')
        exit_code = os.system(f'scp {local_json_dir}/*.json {self.remote_user_host}:{self.remote_upload_path}')
        if exit_code == 0: print("✅ 遠端同步完成")
        else: print("❌ 遠端同步失敗")

    def run_general_report(self, file_count):
        """補單 API: tt=1 ~ 檔案數量 (含失敗追蹤與自動重試)"""
        if not self.general_report_url or file_count == 0:
            print("❌ 跳過補單：網址未設定或無檔案"); return

        print(f"\n🚀 啟動補單：{self.general_report_url} (tt=1 ~ {file_count})")
        
        failed_tt = self._execute_batch(list(range(1, file_count + 1)))

        # 如果有失敗，自動進行一次重試
        if failed_tt:
            print(f"\n🔄 正在針對 {len(failed_tt)} 筆失敗項目進行自動重試...")
            failed_tt = self._execute_batch(failed_tt)

        if failed_tt:
            failed_tt.sort()
            print(f"\n⚠️ 最終補單失敗編號：{failed_tt}")
            log_path = os.path.join(self.site_path, "failed_report.json")
            with open(log_path, 'w') as f:
                json.dump(failed_list, f)
            print(f"📝 失敗紀錄已存至: {log_path}")
        else:
            print(f"\n✨ 所有補單任務均成功完成！")

    def _execute_batch(self, tt_list):
        """執行批次請求的核心邏輯"""
        success, fail = 0, 0
        failed_results = []
        total = len(tt_list)

        with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            tasks = {executor.submit(self._fetch_general, tt): tt for tt in tt_list}
            for i, future in enumerate(as_completed(tasks), 1):
                tt, status = future.result()
                with self.lock:
                    if status == 200: success += 1
                    else:
                        fail += 1
                        failed_results.append(tt)
                print(f"\r   📡 進度: {i}/{total} [✅:{success} ❌:{fail}]", end="")
        return failed_results

    def _fetch_general(self, tt):
        if not hasattr(self.thread_local, "session"): self.thread_local.session = requests.Session()
        try:
            resp = self.thread_local.session.get(self.general_report_url, params={"gm": self.gm, "tt": tt}, timeout=30)
            return tt, resp.status_code
        except: return tt, 999

    def run_daily_rpt(self, start_date_str, end_date_str=None):
        """產出報表 API (run_rpt.py 邏輯)"""
        if not self.rpt_url:
            print("❌ 跳過報表產出：Rpt 網址未設定"); return
        
        if not end_date_str: end_date_str = start_date_str
        current = datetime.strptime(start_date_str, '%Y-%m-%d')
        end = datetime.strptime(end_date_str, '%Y-%m-%d')

        print(f"\n🚀 啟動報表產出：{self.rpt_url}")
        while current <= end:
            date_str = current.strftime("%Y-%m-%d")
            try:
                resp = requests.get(self.rpt_url, params={"date": date_str}, timeout=30)
                print(f"   📅 {date_str} ✅ 狀態碼: {resp.status_code}")
            except Exception as e:
                print(f"   📅 {date_str} ❌ 錯誤: {e}")
            current += timedelta(days=1)
            if current <= end: time.sleep(1)
        print(f"🏁 報表產出完成")

if __name__ == "__main__":
    import sys
    if len(sys.argv) < 2:
        print("用法: python3 rsg_complete_runner.py {SITE} {START_DATE} {END_DATE?}")
    else:
        site_name = sys.argv[1].upper()
        s_date = sys.argv[2]
        e_date = sys.argv[3] if len(sys.argv) > 3 else None
        
        runner = RsgCompleteRunner(site_name)
        runner.download_ftp(s_date, e_date)
        json_num = runner.process_data()
        runner.sync_to_remote()
        runner.run_general_report(json_num)
        runner.run_daily_rpt(s_date, e_date)