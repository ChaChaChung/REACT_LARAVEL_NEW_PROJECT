import os
import zipfile
import shutil # 檔案操作
import tempfile # 建立臨時目錄
import json
import pandas as pd
import requests # HTTP 庫
import threading # 多執行緒
import time
from ftplib import FTP
from datetime import datetime, timedelta
from dotenv import load_dotenv
from concurrent.futures import ThreadPoolExecutor, as_completed # 相對於 threading 更高階的介面

# 加載環境變數
load_dotenv()

class RsgCompleteRunner:
    def __init__(self, site):
        # 先將傳入的 site 轉為大寫
        self.site = site.upper()
        # 基礎路徑
        self.base_root = '/Users/chacha/Downloads/RSG'
        # 下載的資料路徑，使用 os.path.join 把目錄和資料夾名合成一個路徑
        self.site_path = os.path.join(self.base_root, self.site)
        
        # 1. FTP 下載設定
        self.ftp_host = os.getenv(f"FTP_{self.site}_HOST")
        self.ftp_user = os.getenv(f"FTP_{self.site}_USER")
        self.ftp_pass = os.getenv(f"FTP_{self.site}_PASS")

        # 2. 補單與報表網址設定
        base_url = os.getenv(f"{self.site}_BASE_URL")
        # 如果 base_url 存在，就使用 .rstrip('/') 去除字串右側所有的斜線
        self.base_url_stripped = base_url.rstrip('/') if base_url else ""
        # 組合出補單和壓縮的完整網址
        self.general_report_url = f"{self.base_url_stripped}/report/GeneralReport"
        self.rpt_url = f"{self.base_url_stripped}/report/Rpt"
        
        # 3. 遠端 SSH/SCP 設定
        remote_ip = os.getenv(f"{self.site}_IP")
        self.remote_user_host = f"terry@{remote_ip}" if remote_ip else None
        self.remote_upload_path = "/var/tmp/upload"

        # 4. 控制參數
        self.gm = 152
        # 定義執行緒池的最大容量，限制程式同時執行的執行緒數量為 20
        self.max_workers = 20
        # 建立一個「鎖」機制，確保同一時間只有一個執行緒可以執行特定的程式碼區塊
        self.lock = threading.Lock()
        # 建立執行緒的「私有儲存空間」，讓每個執行緒擁有獨立的變數複本，互不干擾
        self.thread_local = threading.local()

    def download_ftp(self, start_date_str, end_date_str=None):
        """下載 FTP 檔案並顯示進度"""

        # 如果沒有提供 end_date_str，會自動將它設定成與 start_date_str 相同
        if not end_date_str: end_date_str = start_date_str
        # strptime: 根據指定的格式解析成 python 的 datetime 物件
        start_date = datetime.strptime(start_date_str, '%Y-%m-%d')
        end_date = datetime.strptime(end_date_str, '%Y-%m-%d')

        try:
            ftp = FTP(self.ftp_host)
            ftp.login(self.ftp_user, self.ftp_pass)
            # 將 FTP 連線模式切換為被動模式，最常用
            ftp.set_pasv(True)
            # 過濾 FTP 根目錄下的資料夾清單，nlst 類似於 Linux 的 ls 指令，清單中會排除掉以下三個對象：report_daily、.、..
            root_folders = [f for f in ftp.nlst() if f not in ['report_daily', '.', '..']]
            
            curr = start_date
            while curr <= end_date:
                date_str = curr.strftime('%Y%m%d')
                for root_f in root_folders:
                    remote_dir = f"{root_f}/{date_str}"
                    try:
                        # 強制回到 FTP 的根目錄
                        ftp.cwd('/')
                        # 切換到變數 remote_dir 所指定的資料夾
                        ftp.cwd(remote_dir)
                        # 獲取該資料夾下的所有內容，並排除掉 Unix 系統中常見的當前目錄 . 和上層目錄 ..，此時 files 會包含該目錄下的所有檔案與子資料夾名稱
                        files = [f for f in ftp.nlst() if f not in ['.', '..']]
                        # 如果 files 是空的，則直接跳過本次迴圈的剩餘部分
                        if not files: continue

                        # 根據作業系統串接路徑
                        local_dir = os.path.join(self.site_path, date_str, root_f)
                        # 建立本地資料夾。exist_ok=True 確保如果資料夾已存在，程式不會報錯中斷
                        os.makedirs(local_dir, exist_ok=True)
                        
                        total_files = len(files)
                        print(f"\n📂 下載目錄: {remote_dir}")

                        # 遍歷該目錄下的每一個檔案，enumerate(files, 1) 會從 1 開始計數，方便計算百分比
                        for idx, f_name in enumerate(files, 1):
                            # 在本機建立一個檔案，'wb' 表示以二進位寫入模式打開
                            with open(os.path.join(local_dir, f_name), 'wb') as f:
                                # 執行下載，RETR 是 FTP 的原始指令，告訴伺服器我要抓這個檔案
                                ftp.retrbinary(f"RETR {f_name}", f.write)
                            # 計算當前已下載檔案數佔總數的百分比
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

        # 建立一個名為 csv_data 的資料夾，用來存放解壓縮後的 CSV 檔案
        csv_dir = os.path.join(self.site_path, 'csv_data')
        # 建立一個名為 json_data 的資料夾，用來存放轉換後的 JSON 檔案
        json_dir = os.path.join(self.site_path, 'json_data')
        
        # 確保資料夾是空的且重新開始，遍歷 csv_dir 和 json_dir 這兩個路徑
        for d in [csv_dir, json_dir]:
            # 檢查該路徑是否存在，如果存在，就用 rmtree 刪除該資料夾以及裡面所有的檔案、子資料夾
            if os.path.exists(d): shutil.rmtree(d)
            # 刪除完（或是原本就不存在），立刻建立一個全新的資料夾
            os.makedirs(d)

        print(f"\n--- 階段 1: 提取 CSV ---")
        # 遍歷下載好的資料夾，找出所有的 ZIP 壓縮檔並解壓縮
        # os.walk(self.site_path) 會遍歷 self.site_path 下所有的資料夾
        for root, _, files in os.walk(self.site_path):
            # 如果目前的目錄是在 csv_data 或 json_data 裡面，就跳過
            if 'csv_data' in root or 'json_data' in root: continue
            # 使用 root.split(os.sep) 將路徑拆開，找出資料夾名稱中，長度為 8 且為數字的字串，並將其命名為 date_label
            date_label = next((p for p in root.split(os.sep) if len(p)==8 and p.isdigit()), "data")
            # 遍歷目前資料夾下的所有檔案
            for file in files:
                # 檢查副檔名是不是 .zip
                if file.lower().endswith('.zip'):
                    # 如果是 ZIP 檔，就呼叫 _extract_zip 進行解壓縮
                    self._extract_zip(os.path.join(root, file), date_label, csv_dir)

        print(f"--- 階段 2: 轉換 JSON ---")
        # 找出剛才解壓縮出來的所有 .csv 檔案
        csv_files = [f for f in os.listdir(csv_dir) if f.endswith('.csv')]

        # data_pool 用來存放從各個 CSV 讀取進來的資料，file_count 用來幫產出的 JSON 檔案編號
        data_pool, file_count = [], 1
        # 遍歷所有 CSV 檔案
        for f_name in csv_files:
            try:
                # 使用 Pandas 讀取 CSV
                df = pd.read_csv(os.path.join(csv_dir, f_name))
                # data_pool.extend 會把 CSV 的所有通通丟進 data_pool
                # df.where(pd.notnull(df), None) 把 CSV 裡的空值轉成 Python 的 None，避免格式報錯
                # to_dict(orient='records') 把每一列轉成一個字典
                data_pool.extend(df.where(pd.notnull(df), None).to_dict(orient='records'))
                # 如果 data_pool 累積到 1000 筆，就存成一個 JSON 檔案，並清空 data_pool，準備裝下一批
                while len(data_pool) >= 1000:
                    # 呼叫 _save_json 存檔
                    self._save_json(data_pool[:1000], file_count, json_dir)
                    # 移除已存檔的資料，並更新計數
                    data_pool = data_pool[1000:]; file_count += 1
            except: continue
        # 把剩下的也存成最後一個 JSON 檔案
        if data_pool: self._save_json(data_pool, file_count, json_dir)
        
        # 找出剛才解壓縮出來的所有 .json 檔案
        json_files = [f for f in os.listdir(json_dir) if f.endswith('.json')]
        print(f"📊 轉換完畢：共產出 {len(json_files)} 個 JSON 檔案")
        return len(json_files)

    def _extract_zip(self, zip_path, date_label, output_dir):
        """解壓縮 ZIP 檔案並重新命名"""

        # 在記憶體或暫存區開一個臨時資料夾，命名為 tmp
        with tempfile.TemporaryDirectory() as tmp:
            try:
                # 打開 ZIP 壓縮檔，並解壓縮到 tmp
                with zipfile.ZipFile(zip_path, 'r') as z: z.extractall(tmp)
                # 遍歷 tmp 資料夾下的所有檔案
                for r, _, files in os.walk(tmp):
                    # 遍歷目前資料夾下的所有檔案
                    for f in files:
                        # 只找後綴是 .csv 的檔案
                        if f.lower().endswith('.csv'):
                            # 把找到的 CSV 搬到最終目的地，並重新命名，例如 20260323_data.csv
                            shutil.copy2(os.path.join(r, f), os.path.join(output_dir, f"{date_label}_{f}"))
            except: pass

    def _save_json(self, data, i, path):
        """將處理好的資料正式寫入成一個 JSON 檔案"""

        # 使用 F-string 格式化檔名，例如 combined_part_1.json
        with open(os.path.join(path, f"combined_part_{i}.json"), 'w', encoding='utf-8') as f:
            # json.dump() 會把 Python 物件轉成 JSON 格式寫入檔案，ensure_ascii=False 確保中文能正常顯示，indent=4 讓檔案排版更漂亮
            json.dump(data, f, ensure_ascii=False, indent=4)

    def sync_to_remote(self):
        """透過 SSH 清空遠端並 SCP 上傳"""

        # 檢查遠端 IP 是否存在
        if not self.remote_user_host:
            print(f"\n⚠️ 跳過同步：找不到 {self.site}_IP 環境變數")
            return
        # 取得本地 JSON 資料夾路徑
        local_json_dir = os.path.join(self.site_path, 'json_data')
        print(f"\n🌐 準備同步至遠端 {self.remote_user_host}...")
        # 先清空遠端資料夾
        os.system(f'ssh {self.remote_user_host} "rm -rf {self.remote_upload_path}/*"')
        # 透過 SCP 把本地 JSON 檔案複製到遠端
        exit_code = os.system(f'scp {local_json_dir}/*.json {self.remote_user_host}:{self.remote_upload_path}')
        # 檢查 SSH 指令的返回碼，0 代表成功
        if exit_code == 0: print("✅ 遠端同步完成")
        else: print("❌ 遠端同步失敗")

    def run_general_report(self, file_count):
        """補單 API: tt=1 ~ 檔案數量 (含失敗追蹤與自動重試)"""

        # 檢查補單網址和檔案數量
        if not self.general_report_url or file_count == 0:
            print("❌ 跳過補單：網址未設定或無檔案"); return

        print(f"\n🚀 啟動補單：{self.general_report_url} (tt=1 ~ {file_count})")
        
        # 執行批次請求
        failed_tt = self._execute_batch(list(range(1, file_count + 1)))

        # 如果有失敗，自動進行一次重試
        if failed_tt:
            print(f"\n🔄 正在針對 {len(failed_tt)} 筆失敗項目進行自動重試...")
            # 再次執行批次請求
            failed_tt = self._execute_batch(failed_tt)

        if failed_tt:
            # 排序失敗編號
            failed_tt.sort()
            print(f"\n⚠️ 最終補單失敗編號：{failed_tt}")
            # 寫入失敗紀錄
            log_path = os.path.join(self.site_path, "failed_report.json")
            with open(log_path, 'w') as f:
                json.dump(failed_list, f)
            print(f"📝 失敗紀錄已存至: {log_path}")
        else:
            print(f"\n✨ 所有補單任務均成功完成！")

    def _execute_batch(self, tt_list):
        """執行批次請求的核心邏輯"""

        # 初始化成功、失敗計數與結果列表
        success, fail = 0, 0
        failed_results = []
        total = len(tt_list)

        # 使用執行緒池並設定最大工作數
        with ThreadPoolExecutor(max_workers=self.max_workers) as executor:
            # 提交所有任務
            tasks = {executor.submit(self._fetch_general, tt): tt for tt in tt_list}
            # 遍歷所有任務
            for i, future in enumerate(as_completed(tasks), 1):
                # 取得任務結果
                tt, status = future.result()
                # 鎖定，避免多執行緒同時修改
                with self.lock:
                    # 判斷狀態碼
                    if status == 200: success += 1
                    else:
                        fail += 1
                        failed_results.append(tt)
                # 顯示進度
                print(f"\r   📡 進度: {i}/{total} [✅:{success} ❌:{fail}]", end="")
        return failed_results

    def _fetch_general(self, tt):
        """執行單一補單請求"""

        # 檢查 session 是否存在，不存在則建立，requests.Session() 可以保持連線狀態，避免每次請求都重新建立連線
        if not hasattr(self.thread_local, "session"): self.thread_local.session = requests.Session()
        try:
            # 建立請求，並帶上參數，且設定超時為 30 秒
            resp = self.thread_local.session.get(self.general_report_url, params={"gm": self.gm, "tt": tt}, timeout=30)
            # 回傳任務編號和狀態碼
            return tt, resp.status_code
        except: return tt, 999

    def run_daily_rpt(self, start_date_str, end_date_str=None):
        """執行單一壓縮請求"""

        # 檢查報表網址和日期
        if not self.rpt_url:
            print("❌ 跳過報表產出：Rpt 網址未設定"); return
        
        # 如果沒有結束日期，就用開始日期
        if not end_date_str: end_date_str = start_date_str
        current = datetime.strptime(start_date_str, '%Y-%m-%d')
        end = datetime.strptime(end_date_str, '%Y-%m-%d')

        print(f"\n🚀 啟動報表產出：{self.rpt_url}")
        # 遍歷日期
        while current <= end:
            date_str = current.strftime("%Y-%m-%d")
            try:
                # 建立請求，並帶上參數，且設定超時為 30 秒
                resp = requests.get(self.rpt_url, params={"date": date_str}, timeout=30)
                print(f"   📅 {date_str} ✅ 狀態碼: {resp.status_code}")
            except Exception as e:
                print(f"   📅 {date_str} ❌ 錯誤: {e}")
            current += timedelta(days=1)
            # 避免同一秒發送太多請求
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