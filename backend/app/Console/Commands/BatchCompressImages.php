<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 批量壓縮圖片，當圖片大於指定大小時壓縮，輸出到目標資料夾
 */
class BatchCompressImages extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan images:compress {source} {output}
     * {source} - 要壓縮的圖片來源資料夾（必需參數）
     * {output} - 壓縮後的圖片輸出資料夾（必需參數）
     * --threshold - 壓縮門檻（預設：500 KB）
     */
    protected $signature = 'images:compress {source?} {output?} {--threshold=500}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Batch compress images: compress images larger than threshold and output to target folder';

    /**
     * 支援的圖片格式
     */
    private const SUPPORTED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.webp'];

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle(): int
    {
        // 來源資料夾
        $sourceArg = $this->argument('source') ?? storage_path('app/images');
        // 輸出資料夾
        $outputArg = $this->argument('output') ?? storage_path('app/compressed');

        // 來源資料夾的實際路徑
        $sourceDir = realpath($sourceArg);

        // 壓縮門檻
        $thresholdKB = (int) $this->option('threshold');
        // 壓縮門檻的位元組數
        $thresholdBytes = $thresholdKB * 1024;

        // 來源資料夾不存在
        if (!$sourceDir || !is_dir($sourceDir)) {
            $this->error("執行失敗 - 來源資料夾不存在: {$sourceArg}");
            $this->line('請先建立 storage/app/images 資料夾並放入要壓縮的圖片');

            return 1;
        }

        // 輸出資料夾不存在
        if (!is_dir($outputArg)) {
            // 建立輸出資料夾
            mkdir($outputArg, 0755, true);
        }
        // 輸出資料夾的實際路徑
        $outputDir = realpath($outputArg);

        // 目標大小（約 450 KB，壓到約 400–500 KB）
        $targetBytes = 450 * 1024;

        // 取得所有圖片檔案
        $imageFiles = $this->getImageFiles($sourceDir);

        // 如果沒有找到圖片檔案
        if (empty($imageFiles)) {
            $this->warn('未找到支援的圖片 (jpg, jpeg, png, webp)');

            return 0;
        }

        // 壓縮圖片數量
        $compressedCount = 0;
        // 錯誤數量
        $errorCount = 0;
        // 節省的位元組數
        $totalSavedBytes = 0;

        // 所有圖片檔案執行迴圈
        foreach ($imageFiles as $file) {
            // 輸出路徑（非 .png 一律輸出為 .png）
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $file['relativePath'];
            
            // 如果副檔名不是 .png，則轉成 png
            if ($file['ext'] !== '.png') {
                // 轉成 png 的輸出路徑
                $outputPath = pathinfo($outputPath, PATHINFO_DIRNAME) . DIRECTORY_SEPARATOR
                    . pathinfo($outputPath, PATHINFO_FILENAME) . '.png';
            }

            // 檔案統計資訊
            $stats = stat($file['fullPath']);

            try {
                // 輸出資料夾路徑
                $outputDirPath = dirname($outputPath);

                // 如果輸出資料夾不存在
                if (!is_dir($outputDirPath)) {
                    // 建立輸出資料夾
                    mkdir($outputDirPath, 0755, true);
                }

                // 如果檔案大小大於壓縮門檻：壓縮（非 .png 會一併轉成 .png）
                if ($stats['size'] > $thresholdBytes) {
                    // 原始檔案大小
                    $originalSize = $stats['size'];
                    // 壓縮圖片（超過 500 KB 且非 .png → 壓縮後轉成 .png）
                    $compressResult = $this->compressImage(
                        $file['fullPath'],
                        $outputPath,
                        $file['ext'],
                        $targetBytes
                    );

                    // 壓縮圖片後回傳的輸出路徑字串
                    $actualOutputPath = $compressResult;

                    // 如果實際輸出路徑存在
                    if (file_exists($actualOutputPath)) {
                        // 新檔案大小
                        $newSize = filesize($actualOutputPath);
                        // 節省的位元組數
                        $savedBytes = $originalSize - $newSize;
                        // 總共節省的位元組數
                        $totalSavedBytes += $savedBytes;
                        // 壓縮圖片數量增加
                        $compressedCount++;
                    } else {
                        // 拋出異常
                        throw new \RuntimeException('壓縮後檔案不存在');
                    }
                } else {
                    // 未超過門檻：若非 .png 則轉成 png 輸出；已是 .png 則不轉也不複製（略過）
                    if ($file['ext'] !== '.png') {
                        // 載入圖片
                        $image = $this->loadImage($file['fullPath'], $file['ext']);

                        // 如果無法載入圖片
                        if ($image === false) {
                            throw new \RuntimeException("無法載入圖片 - {$file['fullPath']}");
                        }

                        // 保存圖片
                        $this->saveImage($image, $outputPath, '.png', 6);
                    }
                }
            } catch (\Throwable $e) {
                $this->error("執行失敗 - {$file['relativePath']} - {$e->getMessage()}");
                $errorCount++;
            }
        }

        $savedKB = number_format($totalSavedBytes / 1024, 1);
        $this->info("完成：壓縮 {$compressedCount} 張，錯誤 {$errorCount} 筆，節省 {$savedKB} KB");

        return 0;
    }

    /**
     * 取得所有圖片檔案
     * @param string $dir 資料夾路徑
     * @return array
     */
    private function getImageFiles($dir): array
    {
        // 檔案列表
        $files = [];
        // 目錄中的項目名稱列表
        $entries = scandir($dir);

        // 資料夾中的檔案列表執行迴圈
        foreach ($entries as $entry) {
            // 跳過當前和父資料夾
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // 完整路徑
            $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
            // 相對路徑
            $relativePath = substr($fullPath, strlen($dir) + 1);
            // 檔案副檔名
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            // 檔案副檔名加上點
            $extWithDot = '.' . $ext;

            // 如果檔案副檔名在支援的副檔名列表中
            if (in_array($extWithDot, self::SUPPORTED_EXTENSIONS, true)) {
                // 加入到檔案列表
                $files[] = [
                    'fullPath' => $fullPath,
                    'relativePath' => $relativePath,
                    'ext' => $extWithDot,
                ];
            }
        }

        return $files;
    }

    /**
     * 壓縮圖片
     * @param string $inputPath 原始圖片路徑
     * @param string $outputPath 輸出路徑
     * @param string $ext 副檔名
     * @param int $targetBytes 目標大小
     * @return string
     */
    private function compressImage($inputPath, $outputPath, $ext, $targetBytes): string
    {
        // 載入圖片
        $image = $this->loadImage($inputPath, $ext);

        // 如果無法載入圖片
        if ($image === false) {
            throw new \RuntimeException("無法載入圖片 - {$inputPath}");
        }

        // 原始檔案大小
        $originalSize = filesize($inputPath);
        // 副檔名不是 png 則一律輸出為 png
        $outputExt = ($ext === '.png') ? $ext : '.png';

        // 迭代壓縮 - 選用「最高品質」且「不超過目標大小」的結果（盡量接近目標）
        $bestPath = null;
        // 最佳大小
        $bestSize = 0;
        // 備用路徑
        $fallbackPath = null;
        // 備用大小
        $fallbackSize = PHP_INT_MAX;
        // png 用壓縮等級 0-9（9 壓最大）；JPG/WebP 用品質 95-30
        $qualities = [95, 90, 85, 80, 75, 70, 65, 60, 55, 50, 45, 40, 35, 30];
        // 如果副檔名是 .png，則使用壓縮等級 0-9；否則使用品質 95-30
        $tryValues = ($outputExt === '.png') ? range(0, 9) : $qualities;

        // 壓縮品質參數執行迴圈
        foreach ($tryValues as $param) {
            // 測試路徑
            $testPath = $outputPath . '.tmp.' . $param;
            // 保存圖片（png 時 param 為壓縮等級 0-9，否則為品質）
            $this->saveImage($image, $testPath, $outputExt, $param);

            // 如果測試路徑存在
            if (file_exists($testPath)) {
                // 檔案大小
                $size = filesize($testPath);
                // 如果檔案大小 < 備用大小
                if ($size < $fallbackSize) {
                    // 備用大小
                    $fallbackSize = $size;
                    // 備用路徑
                    $fallbackPath = $testPath;
                }
                // 選用不超過目標、且檔案最大的（畫質最好）
                if ($size <= $targetBytes && $size > $bestSize) {
                    // 最佳大小
                    $bestSize = $size;
                    // 最佳路徑
                    $bestPath = $testPath;
                }
            }
        }

        // 若無符合目標的，使用壓縮後最小的
        if ($bestPath === null && $fallbackPath !== null) {
            // 最佳路徑
            $bestPath = $fallbackPath;
            // 最佳大小
            $bestSize = $fallbackSize;
        }

        // 若壓縮後比原檔大，先嘗試 pngquant（僅 png），再決定複製原檔
        if ($bestPath === null || $bestSize > $originalSize) {
            // 壓縮品質參數執行迴圈
            foreach ($tryValues as $param) {
                // 測試路徑
                $tmpPath = $outputPath . '.tmp.' . $param;
                // 如果測試路徑存在
                if (file_exists($tmpPath)) {
                    // 刪除測試路徑
                    unlink($tmpPath);
                }
            }

            // 若副檔名爲 png 且 GD 無法壓更小，嘗試 pngquant（若已安裝）
            if ($ext === '.png') {
                // 嘗試 pngquant
                $pngquantResult = $this->tryPngquant($inputPath, $outputPath, $originalSize);
                // 如果 pngquant 結果不為 null
                if ($pngquantResult !== null) {
                    // 回傳 pngquant 結果
                    return $pngquantResult;
                }
            }

            // 非 png 來源但輸出為 png，必須轉成 png，不能直接複製
            if ($outputExt === '.png' && $ext !== '.png') {
                // 載入圖片
                $imageForConvert = $this->loadImage($inputPath, $ext);
                // 如果無法載入圖片
                if ($imageForConvert !== false) {
                    // 保存圖片
                    $this->saveImage($imageForConvert, $outputPath, '.png', 6);
                    // 回傳結果
                    return $outputPath;
                }
            }

            // 複製原檔案
            copy($inputPath, $outputPath);

            return $outputPath;
        }

        // 清理其他暫存檔
        foreach ($tryValues as $param) {
            // 測試路徑
            $tmpPath = $outputPath . '.tmp.' . $param;
            // 如果測試路徑存在且不是最佳路徑
            if (file_exists($tmpPath) && $tmpPath !== $bestPath) {
                // 刪除測試路徑
                unlink($tmpPath);
            }
        }

        // 重命名最佳路徑（先輸出 GD 結果）
        rename($bestPath, $outputPath);

        // PNG 且仍超過目標大小，用 pngquant 再壓一次
        if ($ext === '.png' && $bestSize > $targetBytes) {
            $this->tryPngquant($inputPath, $outputPath, $originalSize);
        }

        // 若目前輸出仍 > 目標：對「當前檔」再跑 pngquant（更激進品質），可多段直到達標
        if ($ext === '.png' && file_exists($outputPath) && filesize($outputPath) > $targetBytes) {
            // 更激進品質
            $aggressiveQualities = ['25-55', '20-50', '15-45'];
            // 更激進品質執行迴圈
            foreach ($aggressiveQualities as $quaility) {
                // 如果檔案大小 <= 目標大小，則跳出迴圈
                if (filesize($outputPath) <= $targetBytes) {
                    break;
                }
                // 第二個路徑
                $secondPath = $outputPath . '.2nd';
                // 當前檔案大小
                $currentSize = filesize($outputPath);
                // 嘗試 pngquant
                if ($this->tryPngquant($outputPath, $secondPath, $currentSize, $quaility) !== null) {
                    // 重命名原檔案
                    rename($outputPath, $outputPath . '.old');
                    rename($secondPath, $outputPath);
                    @unlink($outputPath . '.old');
                }
            }
        }

        // 最後手段，若壓縮出來的圖片還是 > 目標尺寸，則縮小尺寸再存 PNG（像素變少，檔案變小）
        if ($ext === '.png' && file_exists($outputPath) && filesize($outputPath) > $targetBytes) {
            // 載入圖片
            $img = $this->loadImage($outputPath, '.png');
            // 如果無法載入圖片
            if ($img !== false) {
                // 圖片寬度
                $w = imagesx($img);
                // 圖片高度
                $h = imagesy($img);
                // 暫存路徑
                $tmpResized = $outputPath . '.resized.tmp';
                // 縮小尺寸比例
                $scales = [0.85, 0.75, 0.65, 0.55, 0.5, 0.45, 0.4];
                // 縮小尺寸比例執行迴圈
                foreach ($scales as $scale) {
                    // 如果當前檔案大小 <= 目標大小，則跳出迴圈
                    if (filesize($outputPath) <= $targetBytes) {
                        break;
                    }
                    // 新寬度
                    $nw = max(1, (int) round($w * $scale));
                    // 新高度
                    $nh = max(1, (int) round($h * $scale));
                    // 縮小圖片
                    $resized = imagescale($img, $nw, $nh);
                    // 如果縮小圖片成功
                    if ($resized !== false) {
                        // 保存圖片
                        $this->saveImage($resized, $tmpResized, '.png', 9);
                        // 如果暫存路徑存在且檔案大小 <= 目標大小，則重命名
                        if (is_file($tmpResized) && filesize($tmpResized) <= $targetBytes) {
                            rename($tmpResized, $outputPath);
                            break;
                        }
                        // 如果暫存路徑存在且檔案大小 < 原檔案大小，則重命名
                        if (is_file($tmpResized) && filesize($tmpResized) < filesize($outputPath)) {
                            rename($tmpResized, $outputPath);
                        // 如果暫存路徑存在，則刪除
                        } else if (is_file($tmpResized)) {
                            @unlink($tmpResized);
                        }
                    }
                }
                @unlink($tmpResized);
            }
        }

        return $outputPath;
    }

    /**
     * 若系統有 pngquant，嘗試壓縮 png
     * @param string $inputPath 原始圖片路徑
     * @param string $outputPath 輸出路徑
     * @param int $originalSize 原始檔案大小
     * @param string $quality 品質區間
     * @return string|null
     */
    private function tryPngquant($inputPath, $outputPath, $originalSize, $quality = '35-68'): ?string
    {
        // 暫存路徑
        $tmpPath = $outputPath . '.pngquant.tmp';

        // 命令
        $cmd = sprintf(
            '%s --quality=%s --skip-if-larger --output %s -- %s 2>/dev/null',
            'pngquant',
            $quality,
            escapeshellarg($tmpPath),
            escapeshellarg($inputPath)
        );
        // 執行命令
        exec($cmd);

        // 如果暫存路徑不存在
        if (!is_file($tmpPath)) {
            return null;
        }

        // 新檔案大小
        $newSize = filesize($tmpPath);
        // 如果新檔案大小 >= 原始檔案大小
        if ($newSize >= $originalSize) {
            // 刪除暫存路徑
            unlink($tmpPath);
            return null;
        }

        // 重命名暫存路徑
        rename($tmpPath, $outputPath);

        // 回傳結果
        return $outputPath;
    }

    /**
     * 儲存圖片
     * @param \GdImage $image 圖片
     * @param string $path 輸出路徑
     * @param string $ext 副檔名
     * @param int $quality 品質
     * @return void
     */
    private function saveImage($image, $path, $ext, $quality): void
    {
        // 判斷副檔名
        switch ($ext) {
            case '.jpg':
            case '.jpeg':
                // 儲存 JPEG 圖片
                imagejpeg($image, $path, $quality);
                break;
            case '.png':
                // 設置透明通道
                imagealphablending($image, false);
                // 保存透明通道
                imagesavealpha($image, true);
                // 壓縮等級 0-9，若傳入 0-9 則直接當等級，否則由品質換算
                $pngLevel = ($quality >= 0 && $quality <= 9) ? (int) $quality : (int) round(9 * (100 - $quality) / 100);
                imagepng($image, $path, max(0, min(9, $pngLevel)));
                break;
            case '.webp':
                // 儲存 WebP 圖片
                imagewebp($image, $path, $quality);
                break;
            default:
                // 儲存 JPEG 圖片
                imagejpeg($image, $path, $quality);
        }
    }

    /**
     * 使用 GD 載入圖片
     * @param string $path 圖片路徑
     * @param string $ext 副檔名
     * @return \GdImage|false
     */
    private function loadImage(string $path, string $ext)
    {
        // 判斷副檔名
        switch ($ext) {
            case '.jpg':
            case '.jpeg':
                // 載入 JPEG 圖片
                return imagecreatefromjpeg($path);
            case '.png':
                // 載入 png 圖片
                return imagecreatefrompng($path);
            case '.webp':
                // 如果 PHP GD 支援 WebP 格式
                if (function_exists('imagecreatefromwebp')) {
                    // 載入 WebP 圖片
                    return imagecreatefromwebp($path);
                }
                throw new \RuntimeException('PHP GD 未支援 WebP 格式');
            default:
                return imagecreatefromjpeg($path);
        }
    }
}
