import os
import zipfile
import shutil
import tempfile
import sys
import pandas as pd
import json

def run_process(base_path):
    # 直接在傳入的路徑下建立兩個資料夾
    csv_output_dir = os.path.join(base_path, 'csv_data')
    json_output_dir = os.path.join(base_path, 'json_data')
    
    # 建立目錄
    for d in [csv_output_dir, json_output_dir]:
        if not os.path.exists(d):
            os.makedirs(d)
            print(f"已建立資料夾: {d}")

    # --- 第一階段：提取 CSV ---
    print(f"\n--- 階段 1: 開始提取 CSV 至 csv_data ---")
    for sub_dir in os.listdir(base_path):
        sub_path = os.path.join(base_path, sub_dir)
        
        # 排除我們剛建立的輸出資料夾，避免無窮遞迴
        if not os.path.isdir(sub_path) or sub_dir in ['csv_data', 'json_data']:
            continue

        for date_dir in os.listdir(sub_path):
            date_path = os.path.join(sub_path, date_dir)
            if not os.path.isdir(date_path):
                continue

            for file_name in os.listdir(date_path):
                if file_name.lower().endswith('.zip'):
                    zip_file_path = os.path.join(date_path, file_name)
                    with tempfile.TemporaryDirectory() as tmp_dir:
                        try:
                            with zipfile.ZipFile(zip_file_path, 'r') as zip_ref:
                                zip_ref.extractall(tmp_dir)
                            for root, dirs, files in os.walk(tmp_dir):
                                for f in files:
                                    if f.lower().endswith('.csv'):
                                        src_file = os.path.join(root, f)
                                        # 檔名維持：日期_原檔名
                                        dst_file = os.path.join(csv_output_dir, f"{date_dir}_{f}")
                                        shutil.copy2(src_file, dst_file)
                                        print(f"  [提取] {os.path.basename(dst_file)}")
                        except Exception as e:
                            print(f"  [錯誤] 解壓 {file_name} 失敗: {e}")

    # --- 第二階段：轉換 JSON ---
    print(f"\n--- 階段 2: 開始轉換 JSON 至 json_data (每 1000 筆分塊) ---")
    csv_files = sorted([f for f in os.listdir(csv_output_dir) if f.endswith('.csv')])
    
    if not csv_files:
        print("未發現 CSV 檔案，停止轉換。")
        return

    data_pool = []
    file_count = 1
    target_size = 1000

    for csv_file in csv_files:
        file_path = os.path.join(csv_output_dir, csv_file)
        try:
            df = pd.read_csv(file_path)
            records = df.to_dict(orient='records')
            data_pool.extend(records)
            
            while len(data_pool) >= target_size:
                chunk = data_pool[:target_size]
                data_pool = data_pool[target_size:]
                save_json(chunk, file_count, json_output_dir)
                file_count += 1
        except Exception as e:
            print(f"  [錯誤] 讀取 {csv_file} 時出錯: {e}")

    if data_pool:
        save_json(data_pool, file_count, json_output_dir)

    print(f"\n處理完畢！")
    print(f"CSV 資料夾：{csv_output_dir}")
    print(f"JSON 資料夾：{json_output_dir}")

def save_json(data, index, output_path):
    filename = f"combined_part_{index}.json"
    full_path = os.path.join(output_path, filename)
    with open(full_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)
    print(f"  [匯出] {filename} ({len(data)} 筆)")

if __name__ == "__main__":
    if len(sys.argv) > 1:
        input_path = sys.argv[1]
        if os.path.exists(input_path):
            run_process(input_path)
        else:
            print(f"錯誤：找不到路徑 {input_path}")
    else:
        print("用法: python process_data.py [目標資料夾路徑]")