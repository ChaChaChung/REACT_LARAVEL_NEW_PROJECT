import pandas as pd
import os
import json

# 設定路徑
source_dir = '/Users/chacha/Downloads/RSG/Final_Data'
output_dir = os.path.join(source_dir, 'output_combined_json')

# 如果輸出目錄不存在，則建立它
if not os.path.exists(output_dir):
    os.makedirs(output_dir)

def process_files_to_chunks(target_size=1000):
    # 獲取所有 CSV 檔案路徑
    csv_files = sorted([f for f in os.listdir(source_dir) if f.endswith('.csv')])
    
    if not csv_files:
        print("找不到任何 CSV 檔案。")
        return

    data_pool = []
    file_count = 1

    for csv_file in csv_files:
        file_path = os.path.join(source_dir, csv_file)
        print(f"正在讀取: {csv_file}...")
        
        try:
            # 讀取 CSV (若有編碼問題可加入 encoding='utf-8-sig')
            df = pd.read_csv(file_path)
            # 將 DataFrame 轉為 dict 列表
            records = df.to_dict(orient='records')
            data_pool.extend(records)
            
            # 當資料池超過目標大小時，開始分塊輸出
            while len(data_pool) >= target_size:
                chunk_to_save = data_pool[:target_size] # 取出前 1000 筆
                data_pool = data_pool[target_size:]     # 剩餘的留到下一次
                
                save_json(chunk_to_save, file_count)
                file_count += 1
                
        except Exception as e:
            print(f"讀取 {csv_file} 時出錯: {e}")

    # 處理最後剩餘的資料 (不足 1000 筆的部分)
    if data_pool:
        save_json(data_pool, file_count)
        print("所有檔案處理完畢。")

def save_json(data, index):
    output_filename = f"combined_part_{index}.json"
    output_path = os.path.join(output_dir, output_filename)
    
    with open(output_path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=4)
    
    print(f"--- 已儲存: {output_filename} (包含 {len(data)} 筆資料)")

if __name__ == "__main__":
    process_files_to_chunks(1000)