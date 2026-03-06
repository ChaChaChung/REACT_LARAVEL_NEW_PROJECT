import os
import zipfile
import shutil
import tempfile

def extract_csv_from_zips(source_base_dir, target_dir):
    # 確保目標資料夾存在
    if not os.path.exists(target_dir):
        os.makedirs(target_dir)
        print(f"已建立目標資料夾: {target_dir}")

    # 1. 遍歷第一層子資料夾 (圖一中的四個子資料夾)
    for sub_dir in os.listdir(source_base_dir):
        sub_path = os.path.join(source_base_dir, sub_dir)
        
        # 跳過目標資料夾本身以及非資料夾的檔案
        if not os.path.isdir(sub_path) or sub_dir == os.path.basename(target_dir):
            continue

        # 2. 遍歷第二層日期資料夾 (圖二)
        for date_dir in os.listdir(sub_path):
            date_path = os.path.join(sub_path, date_dir)
            if not os.path.isdir(date_path):
                continue

            print(f"正在處理日期資料夾: {date_path}")

            # 3. 遍歷日期資料夾內的 ZIP 檔案 (圖三)
            for file_name in os.listdir(date_path):
                if file_name.lower().endswith('.zip'):
                    zip_file_path = os.path.join(date_path, file_name)
                    
                    # 建立臨時資料夾來解壓
                    with tempfile.TemporaryDirectory() as tmp_dir:
                        try:
                            with zipfile.ZipFile(zip_file_path, 'r') as zip_ref:
                                zip_ref.extractall(tmp_dir)
                            
                            # 4. 搜尋解壓後的所有檔案 (圖四：可能是資料夾也可能是單一 CSV)
                            for root, dirs, files in os.walk(tmp_dir):
                                for f in files:
                                    if f.lower().endswith('.csv'):
                                        src_file = os.path.join(root, f)
                                        
                                        # 為了方便辨識，將檔名加上日期前綴 (可選)
                                        # 新檔名 = 日期_原檔名
                                        new_name = f"{date_dir}_{f}"
                                        dst_file = os.path.join(target_dir, new_name)
                                        
                                        # 處理檔名重複衝突
                                        counter = 1
                                        base_name, extension = os.path.splitext(new_name)
                                        while os.path.exists(dst_file):
                                            dst_file = os.path.join(target_dir, f"{base_name}_{counter}{extension}")
                                            counter += 1
                                        
                                        shutil.copy2(src_file, dst_file)
                                        print(f"  [提取成功] {os.path.basename(dst_file)}")
                                        
                        except Exception as e:
                            print(f"  [錯誤] 無法讀取壓縮檔 {file_name}: {e}")

if __name__ == "__main__":
    # 你的路徑設定
    SOURCE_PATH = '/Users/chacha/Downloads/RSG'
    TARGET_PATH = '/Users/chacha/Downloads/RSG/Final_Data'
    
    extract_csv_from_zips(SOURCE_PATH, TARGET_PATH)
    print("\n所有 CSV 檔案已提取至 Final_Data 資料夾。")