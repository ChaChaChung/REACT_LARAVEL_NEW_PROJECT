import requests
from datetime import datetime, timedelta
import time

# 設定參數
# BASE_URL = "https://168swin2.com/report/GeneralReport"
BASE_URL = "https://web.fkf88.com/report/GeneralReport"
GM = 142
START_TIME = datetime(2026, 2, 1, 0, 0, 0)
END_TIME = datetime(2026, 3, 11, 23, 59, 59)
INTERVAL = timedelta(minutes=20)
DELAY_SECONDS = 1   # 每次請求之間的間隔秒數（避免打太快）
RETRY = 3            # 失敗重試次數
TIMEOUT = (10, 30)  # (connect timeout, read timeout)

def format_time(dt):
    return dt.strftime("%Y-%m-%d %H:%M:%S")

def run():
    current = START_TIME
    total = 0
    success = 0
    failed = 0

    while current <= END_TIME:
        s = current
        e = current + INTERVAL - timedelta(seconds=1)  # 結尾為 xx:xx:59

        params = {
            "gm": GM,
            "s": format_time(s),
            "e": format_time(e),
        }

        url = f"{BASE_URL}?gm={GM}&s={format_time(s).replace(' ', '%20')}&e={format_time(e).replace(' ', '%20')}"
        print(f"[{total+1}] 請求: {url}")

        for attempt in range(1, RETRY + 1):
            try:
                response = requests.get(BASE_URL, params=params, timeout=TIMEOUT, stream=False)
                status = response.status_code
                print(f"      ✅ 狀態碼: {status} | 長度: {len(response.text)} bytes")
                success += 1
                break
            except requests.exceptions.ConnectTimeout:
                print(f"      ⚠️  第 {attempt} 次失敗: 連線逾時 (connect timeout)")
            except requests.exceptions.ReadTimeout:
                print(f"      ⚠️  第 {attempt} 次失敗: 讀取逾時 (read timeout)")
            except requests.exceptions.ConnectionError as ex:
                print(f"      ⚠️  第 {attempt} 次失敗: 連線錯誤 {ex}")
            except Exception as ex:
                print(f"      ⚠️  第 {attempt} 次失敗: {ex}")

            if attempt < RETRY:
                print(f"      🔄 5 秒後重試...")
                time.sleep(5)
            else:
                print(f"      ❌ 重試 {RETRY} 次仍失敗，跳過")
                failed += 1

        total += 1
        current += INTERVAL

        if current <= END_TIME:
            time.sleep(DELAY_SECONDS)

    print(f"\n完成！共 {total} 筆，成功 {success}，失敗 {failed}")

if __name__ == "__main__":
    run()