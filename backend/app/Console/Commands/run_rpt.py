import requests
from datetime import datetime, timedelta
import time

# 設定參數
BASE_URL = "https://web.fkf88.com/report/Rpt"
START_DATE = datetime(2026, 1, 1)
END_DATE = datetime(2026, 2, 24)
DELAY_SECONDS = 1  # 每次請求之間的間隔秒數

def run():
    current = START_DATE
    total = 0
    success = 0
    failed = 0

    while current <= END_DATE:
        date_str = current.strftime("%Y-%m-%d")
        url = f"{BASE_URL}?date={date_str}"
        print(f"[{total+1}] 請求: {url}")

        try:
            response = requests.get(BASE_URL, params={"date": date_str}, timeout=30)
            status = response.status_code
            print(f"      ✅ 狀態碼: {status} | 長度: {len(response.text)} bytes")
            success += 1
        except Exception as ex:
            print(f"      ❌ 錯誤: {ex}")
            failed += 1

        total += 1
        current += timedelta(days=1)

        if current <= END_DATE:
            time.sleep(DELAY_SECONDS)

    print(f"\n完成！共 {total} 筆，成功 {success}，失敗 {failed}")

if __name__ == "__main__":
    run()