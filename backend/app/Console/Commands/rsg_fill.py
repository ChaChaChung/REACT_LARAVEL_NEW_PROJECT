import os
import zipfile
import shutil
import tempfile
import json
import pandas as pd
from ftplib import FTP
from datetime import datetime, timedelta
from dotenv import load_dotenv

# 加載 .env
load_dotenv()

class RsgProcessor:
    def __init__(self, site):
        self.site = site.upper()
        # 基礎路徑
        self.base_root = '/Users/chacha/Downloads/RSG'
        # site 路徑 (所有的 csv_data, json_data 都會在這裡面)
        self.site_path = os.path.join(self.base_root, self.site)
        
        self.host = os.getenv(f"FTP_{self.site}_HOST")
        self.user = os.getenv(f"FTP_{self.site}_USER")
        self.password = os.getenv(f"FTP_{self.site}_PASS")

    def download(self, start_date_str, end_date_str=None):
        """下載 FTP 檔案"""
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
                        files = ftp.nlst()
                        
                        # 存放在 /RSG/{site}/{date_str}/{root_f}
                        local_dir = os.path.join(self.site_path, date_str, root_f)
                        os.makedirs(local_dir, exist_ok=True)
                        
                        for f_name in files:
                            if f_name in ['.', '..']: continue
                            with open(os.path.join(local_dir, f_name), 'wb') as f:
                                ftp.retrbinary(f"RETR {f_name}", f.write)
                        print(f"已下載日期 {date_str} 的 {root_f}")
                    except:
                        continue
                curr += timedelta(days=1)
            ftp.quit()
        except Exception as e:
            print(f"FTP 下載階段發生錯誤: {e}")

    def process(self):
        """提取與轉換 (路徑修正版)"""
        # 指定 csv_data 和 json_data 必須在 site 資料夾內
        csv_dir = os.path.join(self.site_path, 'csv_data')
        json_dir = os.path.join(self.site_path, 'json_data')
        
        os.makedirs(csv_dir, exist_ok=True)
        os.makedirs(json_dir, exist_ok=True)

        print(f"\n--- 開始處理 {self.site} 內的檔案 ---")
        
        found_zip = False
        # 只掃描 site_path 下的內容
        for root, dirs, files in os.walk(self.site_path):
            # 排除掉輸出的資料夾本身，避免重複處理
            if 'csv_data' in root or 'json_data' in root:
                continue
                
            # 嘗試抓取路徑中的 8 位數日期作為前綴
            path_parts = root.split(os.sep)
            date_label = "data"
            for part in path_parts:
                if len(part) == 8 and part.isdigit():
                    date_label = part

            for file in files:
                if file.lower().endswith('.zip'):
                    found_zip = True
                    zip_path = os.path.join(root, file)
                    print(f"  提取中: {file}")
                    self._extract_csv(zip_path, date_label, csv_dir)

        if not found_zip:
            print(f"警告：在 {self.site_path} 內沒有找到任何 ZIP 檔案可供處理。")
            return

        self._to_json(csv_dir, json_dir)

    def _extract_csv(self, zip_path, date_label, output_dir):
        with tempfile.TemporaryDirectory() as tmp:
            try:
                with zipfile.ZipFile(zip_path, 'r') as z:
                    z.extractall(tmp)
                for r, d, files in os.walk(tmp):
                    for f in files:
                        if f.lower().endswith('.csv'):
                            # 加上日期前綴存入 csv_data
                            new_name = f"{date_label}_{f}"
                            shutil.copy2(os.path.join(r, f), os.path.join(output_dir, new_name))
            except Exception as e:
                print(f"    解壓縮出錯: {e}")

    def _to_json(self, csv_dir, json_dir):
        print("\n--- 開始 CSV 轉 JSON ---")
        csv_files = [f for f in os.listdir(csv_dir) if f.endswith('.csv')]
        
        if not csv_files:
            print("沒有找到 CSV 檔案。")
            return

        all_data = []
        part = 1
        for f_name in csv_files:
            try:
                df = pd.read_csv(os.path.join(csv_dir, f_name))
                # 處理 NaN
                all_data.extend(df.where(pd.notnull(df), None).to_dict(orient='records'))
                
                while len(all_data) >= 1000:
                    self._save_json(all_data[:1000], part, json_dir)
                    all_data = all_data[1000:]
                    part += 1
            except Exception as e:
                print(f"  轉換 {f_name} 失敗: {e}")

        if all_data:
            self._save_json(all_data, part, json_dir)
        
        print(f"\n處理完成！")
        print(f"CSV 位置: {csv_dir}")
        print(f"JSON 位置: {json_dir}")

    def _save_json(self, data, i, path):
        filename = f"combined_part_{i}.json"
        with open(os.path.join(path, filename), 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=4)

if __name__ == "__main__":
    import sys
    if len(sys.argv) < 3:
        print("用法: python3 integrated_rsg.py {SITE} {START_DATE} {END_DATE?}")
    else:
        site_name = sys.argv[1]
        start = sys.argv[2]
        end = sys.argv[3] if len(sys.argv) > 3 else None
        
        job = RsgProcessor(site_name)
        job.download(start, end)
        job.process()