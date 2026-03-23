import os
import zipfile
import shutil
import tempfile
import sys

def extract_csv_from_zips(source_base_dir):
    # 自動將 Csv_Data 設定在傳入路徑的下一層
    target_dir = os.path.join(source_base_dir, 'Csv_Data')
    
    if not os.path.exists(target_dir):
        os.makedirs(target_dir)
        print(f"已建立目標資料夾: {target_dir}")

    # 1. 遍歷第一層子資料夾
    for sub_dir in os.listdir(source_base_dir):
        sub_path = os.path.join(source_base_dir, sub_dir)
        
        # 排除 Csv_Data 本身以及非資料夾檔案
        if not os.path.isdir(sub_path) or sub_dir == 'Csv_Data':
            continue

        # 2. 遍歷第二層日期資料夾
        for date_dir in os.listdir(sub_path):
            date_path = os.path.join(sub_path, date_dir)
            if not os.path.isdir(date_path):
                continue

            print(f"正在處理日期資料夾: {date_path}")

            # 3. 遍歷 ZIP 檔案
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
                                        new_name = f"{date_dir}_{f}"
                                        dst_file = os.path.join(target_dir, new_name)
                                        
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
    # 判斷是否有帶入參數
    if len(sys.argv) > 1:
        input_path = sys.argv[1]
        if os.path.exists(input_path):
            extract_csv_from_zips(input_path)
            print(f"\n處理完成！檔案已存至 {input_path}/Csv_Data")
        else:
            print(f"錯誤：路徑 {input_path} 不存在。")
    else:
        print("請提供目標資料夾路徑，例如：")
        print("python extract_csv.py /Users/chacha/Downloads/RSG/FOQ")