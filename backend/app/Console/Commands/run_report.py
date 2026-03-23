#!/usr/bin/env python3
"""
補單腳本（並發版）：自動訪問 GeneralReport，tt=START 到 tt=END
使用 ThreadPoolExecutor 大幅縮短執行時間
"""

import requests
import time
import threading
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed

BASE_URL = "https://168swin2.com/report/GeneralReport"

GM = 152
START = 1
END = 912

# 可調整設定
MAX_WORKERS = 20          # 並發執行緒數（可依伺服器承受能力調整，建議 10~30）
TIMEOUT = 30              # 請求超時秒數
RETRY_TIMES = 3           # 失敗重試次數
LOG_FILE = "run_report_log.txt"

# Thread-safe 計數器
lock = threading.Lock()
success_count = 0
fail_count = 0

# 每個 thread 用獨立 session 較安全
thread_local = threading.local()

def get_session() -> requests.Session:
    if not hasattr(thread_local, "session"):
        thread_local.session = requests.Session()
        # 如需登入 Cookie，在此設定：
        # thread_local.session.cookies.set("your_cookie_name", "your_cookie_value")
        # thread_local.session.headers.update({"Authorization": "Bearer YOUR_TOKEN"})
    return thread_local.session


def fetch(tt: int) -> tuple[int, int, str]:
    """回傳 (tt, status_code, preview)"""
    url = f"{BASE_URL}?gm={GM}&tt={tt}"
    session = get_session()
    for attempt in range(1, RETRY_TIMES + 1):
        try:
            resp = session.get(url, timeout=TIMEOUT)
            return tt, resp.status_code, resp.text[:100]
        except requests.exceptions.RequestException as e:
            if attempt == RETRY_TIMES:
                return tt, -1, str(e)
            time.sleep(2)
    return tt, -1, "Unknown error"


def main():
    global success_count, fail_count
    failed = []
    start_time = datetime.now()
    total = END - START + 1

    print(f"目標：{BASE_URL}?gm={GM}")
    print(f"開始時間：{start_time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"執行範圍：tt={START} ~ tt={END}，共 {total} 筆")
    print(f"並發執行緒：{MAX_WORKERS}\n")

    log_lines = []

    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
        futures = {executor.submit(fetch, tt): tt for tt in range(START, END + 1)}

        completed = 0
        for future in as_completed(futures):
            tt, status, preview = future.result()
            timestamp = datetime.now().strftime("%H:%M:%S")
            completed += 1

            with lock:
                if status == 200:
                    success_count += 1
                    msg = f"[{timestamp}] tt={tt:>4}  ✅ 成功 (HTTP {status})"
                else:
                    fail_count += 1
                    failed.append(tt)
                    msg = f"[{timestamp}] tt={tt:>4}  ❌ 失敗 (HTTP {status}) - {preview}"

                log_lines.append(msg)
                print(msg)

                # 進度顯示
                if completed % 100 == 0:
                    elapsed = (datetime.now() - start_time).seconds
                    print(f"\n  ── 進度：{completed}/{total}，已耗時 {elapsed}s ──\n")

    # 寫入 log
    with open(LOG_FILE, "w", encoding="utf-8") as log:
        log.write(f"補單執行紀錄 - {start_time}\n")
        log.write(f"URL: {BASE_URL}?gm={GM}&tt=[{START}~{END}]\n")
        log.write(f"並發執行緒：{MAX_WORKERS}\n")
        log.write("-" * 60 + "\n")
        # 依 tt 排序後寫入
        for line in sorted(log_lines, key=lambda x: int(x.split("tt=")[1].split()[0])):
            log.write(line + "\n")

    # 結尾摘要
    end_time = datetime.now()
    total_sec = (end_time - start_time).seconds
    print("\n" + "=" * 50)
    print(f"完成時間：{end_time.strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"總耗時：{total_sec} 秒")
    print(f"成功：{success_count} 筆")
    print(f"失敗：{fail_count} 筆")
    if failed:
        print(f"失敗的 tt 值：{sorted(failed)}")
    print(f"詳細紀錄：{LOG_FILE}")


if __name__ == "__main__":
    main()