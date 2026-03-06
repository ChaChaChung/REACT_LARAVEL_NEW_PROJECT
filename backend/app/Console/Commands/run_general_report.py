#!/usr/bin/env python3
"""
補單腳本：自動訪問 GeneralReport，tt=1 到 tt=1707
"""

import requests
import time
import sys
from datetime import datetime

BASE_URL = "https://web.17lii.com/report/GeneralReport"
GM = 152
START = 1
END = 1707

# 可調整設定
DELAY_SECONDS = 0.5       # 每次請求間隔秒數（避免伺服器壓力）
TIMEOUT = 30              # 請求超時秒數
RETRY_TIMES = 3           # 失敗重試次數
LOG_FILE = "run_report_log.txt"

session = requests.Session()
# 如需登入 Cookie，請在下方填入
# session.cookies.set("your_cookie_name", "your_cookie_value")
# 或設定 headers
# session.headers.update({"Authorization": "Bearer YOUR_TOKEN"})


def fetch(tt: int) -> tuple[int, str]:
    url = f"{BASE_URL}?gm={GM}&tt={tt}"
    for attempt in range(1, RETRY_TIMES + 1):
        try:
            resp = session.get(url, timeout=TIMEOUT)
            return resp.status_code, resp.text[:100]  # 只記錄前100字
        except requests.exceptions.RequestException as e:
            if attempt == RETRY_TIMES:
                return -1, str(e)
            time.sleep(2)
    return -1, "Unknown error"


def main():
    failed = []
    start_time = datetime.now()
    print(f"開始時間：{start_time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"執行範圍：tt={START} ~ tt={END}，共 {END - START + 1} 筆\n")

    with open(LOG_FILE, "w", encoding="utf-8") as log:
        log.write(f"補單執行紀錄 - {start_time}\n")
        log.write(f"URL: {BASE_URL}?gm={GM}&tt=[1~{END}]\n")
        log.write("-" * 60 + "\n")

        for tt in range(START, END + 1):
            status, preview = fetch(tt)
            timestamp = datetime.now().strftime("%H:%M:%S")

            if status == 200:
                msg = f"[{timestamp}] tt={tt:>4}  ✅ 成功 (HTTP {status})"
            else:
                msg = f"[{timestamp}] tt={tt:>4}  ❌ 失敗 (HTTP {status}) - {preview}"
                failed.append(tt)

            print(msg)
            log.write(msg + "\n")
            log.flush()

            # 進度顯示
            if tt % 100 == 0:
                elapsed = (datetime.now() - start_time).seconds
                print(f"\n  ── 進度：{tt}/{END}，已耗時 {elapsed}s ──\n")

            time.sleep(DELAY_SECONDS)

    # 結尾摘要
    end_time = datetime.now()
    total_sec = (end_time - start_time).seconds
    print("\n" + "=" * 50)
    print(f"完成時間：{end_time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"總耗時：{total_sec} 秒")
    print(f"成功：{(END - START + 1) - len(failed)} 筆")
    print(f"失敗：{len(failed)} 筆")
    if failed:
        print(f"失敗的 tt 值：{failed}")
    print(f"詳細紀錄：{LOG_FILE}")


if __name__ == "__main__":
    main()