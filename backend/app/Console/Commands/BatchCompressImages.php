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
        if (! $sourceDir || ! is_dir($sourceDir)) {
            $this->error("執行失敗 - 來源資料夾不存在: {$sourceArg}");
            $this->line("請先建立 storage/app/images 資料夾並放入要壓縮的圖片");

            return 1;
        }

        // 輸出資料夾不存在
        if (! is_dir($outputArg)) {
            // 建立輸出資料夾
            mkdir($outputArg, 0755, true);
        }
        // 輸出資料夾的實際路徑
        $outputDir = realpath($outputArg);

        // 最大邊長
        $maxDimension = 1280;
        // 目標大小
        $targetBytes = 300 * 1024;

        $this->info('');
        $this->info("📁 來源: {$sourceDir}");
        $this->info("📂 輸出: {$outputDir}");
        $this->info("📏 壓縮門檻: 大於 {$thresholdKB} KB 的圖片將被壓縮");
        $this->info('');

        // 取得所有圖片檔案
        $imageFiles = $this->getImageFiles($sourceDir);

        // 如果沒有找到圖片檔案
        if (empty($imageFiles)) {
            // 提示
            $this->warn('未找到支援的圖片 (jpg, jpeg, png, webp)');
        }

        // 壓縮計數
        $compressedCount = 0;
        // 錯誤計數
        $errorCount = 0;
        // 總共節省的位元組數
        $totalSavedBytes = 0;

        // 所有圖片檔案執行迴圈
        foreach ($imageFiles as $file) {
            // 輸出路徑
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $file['relativePath'];
            // 檔案統計資訊
            $stats = stat($file['fullPath']);
            // 檔案大小
            $sizeKB = number_format($stats['size'] / 1024, 1);

            try {
                // 輸出資料夾路徑
                $outputDirPath = dirname($outputPath);
                // 如果輸出資料夾不存在
                if (! is_dir($outputDirPath)) {
                    // 建立輸出資料夾
                    mkdir($outputDirPath, 0755, true);
                }

                // 如果檔案大小大於壓縮門檻
                if ($stats['size'] > $thresholdBytes) {
                    // 原始檔案大小
                    $originalSize = $stats['size'];
                    // 壓縮圖片
                    $compressResult = $this->compressImage(
                        $file['fullPath'],
                        $outputPath,
                        $file['ext'],
                        $targetBytes,
                        $maxDimension
                    );

                    // 實際輸出路徑
                    $actualOutputPath = $compressResult['outputPath'] ?? $outputPath;
                    // 如果實際輸出路徑存在
                    if (file_exists($actualOutputPath)) {
                        // 新檔案大小
                        $newSize = filesize($actualOutputPath);
                        // 節省的位元組數
                        $savedBytes = $originalSize - $newSize;
                        // 總共節省的位元組數
                        $totalSavedBytes += $savedBytes;
                        // 新檔案大小
                        $newSizeKB = number_format($newSize / 1024, 1);
                        // 節省的百分比
                        $savedPercent = number_format(($savedBytes / $originalSize) * 100, 1);
                        // 壓縮方法
                        $method = $compressResult['method'] ?? '壓縮';
                        // 提示
                        $this->line("✅ {$method}: {$file['relativePath']} ({$sizeKB}KB → {$newSizeKB}KB, 節省 {$savedPercent}%)");
                        $compressedCount++;
                    } else {
                        throw new \RuntimeException('壓縮後檔案不存在');
                    }
                }
            } catch (\Throwable $e) {
                // 提示
                $this->error("執行失敗 - {$file['relativePath']} - {$e->getMessage()}");
                $errorCount++;
            }
        }

        return 0;
    }

    /**
     * 遞迴取得所有圖片檔案
     * @return array
     */
    private function getImageFiles($dir, $baseDir = null): array
    {
        // 基礎目錄
        $baseDir = $baseDir ?? $dir;
        // 檔案列表
        $files = [];
        // 目錄列表
        $entries = scandir($dir);

        // 目錄列表執行迴圈
        foreach ($entries as $entry) {
            // 跳過當前目錄和父目錄
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // 完整路徑
            $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
            // 相對路徑
            $relativePath = substr($fullPath, strlen($baseDir) + 1);

            // 如果路徑是目錄
            if (is_dir($fullPath)) {
                // 遞迴取得所有圖片檔案
                $files = array_merge($files, $this->getImageFiles($fullPath, $baseDir));
            } elseif (is_file($fullPath)) {
                // 檔案副檔名
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                // 檔案副檔名加上點
                $extWithDot = '.' . $ext;
                // 如果檔案副檔名在支援的副檔名列表中
                if (in_array($extWithDot, self::SUPPORTED_EXTENSIONS, true)) {
                    // 添加到檔案列表
                    $files[] = [
                        'fullPath' => $fullPath,
                        'relativePath' => $relativePath,
                        'ext' => $extWithDot,
                    ];
                }
            }
        }

        return $files;
    }

    /**
     * 使用 GD 壓縮圖片，盡量壓到目標大小以下
     * @return array
     */
    private function compressImage($inputPath, $outputPath, $ext, $targetBytes, $maxDimension): array
    {
        // 原始輸出路徑
        $originalOutputPath = $outputPath;
        // 載入圖片
        $image = $this->loadImage($inputPath, $ext);
        // 如果無法載入圖片
        if ($image === false) {
            throw new \RuntimeException("無法載入圖片 - {$inputPath}");
        }

        // 縮小尺寸（超過最大邊長時）
        $width = imagesx($image);
        $height = imagesy($image);
        // 是否需要縮小尺寸
        $wasResized = $width > $maxDimension || $height > $maxDimension;
        // 如果需要縮小尺寸
        if ($wasResized) {
            // 縮小圖片尺寸
            $image = $this->resizeImage($image, $maxDimension);
        }

        // 原始檔案大小
        $originalSize = filesize($inputPath);
        // 維持原本格式，不轉換
        $outputExt = $ext;

        // 迭代壓縮 - 選用「最高品質」且「不超過目標大小」的結果（盡量接近目標）
        $bestPath = null;
        // 最佳大小
        $bestSize = 0;
        // 備用路徑
        $fallbackPath = null;
        // 備用大小
        $fallbackSize = PHP_INT_MAX;
        // PNG 用壓縮等級 0-9（9 壓最大）；JPG/WebP 用品質 95-30
        $qualities = [95, 90, 85, 80, 75, 70, 65, 60, 55, 50, 45, 40, 35, 30];
        $tryValues = ($outputExt === '.png') ? range(0, 9) : $qualities;

        foreach ($tryValues as $param) {
            // 測試路徑
            $testPath = $outputPath . '.tmp.' . $param;
            // 保存圖片（PNG 時 param 為壓縮等級 0-9，否則為品質）
            $this->saveImage($image, $testPath, $outputExt, $param);

            // 如果測試路徑存在
            if (file_exists($testPath)) {
                // 檔案大小
                $size = filesize($testPath);
                // 如果檔案大小小於備用大小
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

        // 銷毀圖片
        imagedestroy($image);

        // 若無符合目標的，使用壓縮後最小的
        if ($bestPath === null && $fallbackPath !== null) {
            // 最佳路徑
            $bestPath = $fallbackPath;
            // 最佳大小
            $bestSize = $fallbackSize;
        }

        // 若壓縮後比原檔大，先嘗試 pngquant（僅 PNG），再決定複製原檔
        if ($bestPath === null || $bestSize > $originalSize) {
            foreach ($tryValues as $param) {
                $tmpPath = $outputPath . '.tmp.' . $param;
                if (file_exists($tmpPath)) {
                    unlink($tmpPath);
                }
            }

            // PNG 且 GD 無法壓更小：嘗試 pngquant（若已安裝）
            if ($ext === '.png') {
                $pngquantResult = $this->tryPngquant($inputPath, $originalOutputPath, $originalSize);
                if ($pngquantResult !== null) {
                    return $pngquantResult;
                }
            }

            copy($inputPath, $originalOutputPath);
            return ['method' => '複製(壓縮後更大)', 'outputPath' => $originalOutputPath];
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

        // 重命名最佳路徑
        rename($bestPath, $outputPath);

        // 壓縮方法
        $method = $outputExt !== $ext ? '轉 JPEG' : '壓縮';
        // 如果需要縮小尺寸
        if ($wasResized) {
            // 壓縮方法加上縮圖
            $method .= '+縮圖';
        }

        return ['method' => $method, 'outputPath' => $outputPath];
    }

    /**
     * 若系統有 pngquant，嘗試壓縮 PNG（有損但維持 PNG），成功且比原檔小才採用
     * @return array|null 成功則回傳 ['method' => 'pngquant', 'outputPath' => ...]，否則 null
     */
    private function tryPngquant(string $inputPath, string $outputPath, int $originalSize): ?array
    {
        $candidates = [
            trim((string) shell_exec('which pngquant 2>/dev/null')),
            '/opt/homebrew/bin/pngquant',
            '/usr/local/bin/pngquant',
            'pngquant',
        ];
        $pngquant = null;
        foreach ($candidates as $c) {
            if ($c === '') {
                continue;
            }
            if ($c === 'pngquant') {
                $pngquant = 'pngquant';
                break;
            }
            if (is_executable($c)) {
                $pngquant = $c;
                break;
            }
        }
        if ($pngquant === null) {
            return null;
        }

        $tmpPath = $outputPath . '.pngquant.tmp';
        $quality = '50-85';
        $cmd = sprintf(
            '%s --quality=%s --skip-if-larger --output %s -- %s 2>/dev/null',
            escapeshellarg($pngquant),
            $quality,
            escapeshellarg($tmpPath),
            escapeshellarg($inputPath)
        );
        exec($cmd);

        if (! is_file($tmpPath)) {
            return null;
        }
        $newSize = filesize($tmpPath);
        if ($newSize >= $originalSize) {
            unlink($tmpPath);
            return null;
        }
        rename($tmpPath, $outputPath);
        return ['method' => 'pngquant', 'outputPath' => $outputPath];
    }

    /**
     * 縮小圖片尺寸
     * @return \GdImage|false
     */
    private function resizeImage($image, $maxDimension)
    {
        // 圖片寬度
        $width = imagesx($image);
        // 圖片高度
        $height = imagesy($image);

        // 判斷 圖片寬度是否 <= 最大邊長 且 圖片高度是否 <= 最大邊長
        if ($width <= $maxDimension && $height <= $maxDimension) {
            // 返回原圖
            return $image;
        }

        // 計算比例
        $ratio = min($maxDimension / $width, $maxDimension / $height);
        // 新寬度
        $newWidth = (int) round($width * $ratio);
        // 新高度
        $newHeight = (int) round($height * $ratio);

        // 創建新圖片
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        // 如果創建新圖片失敗
        if ($resized === false) {
            return $image;
        }

        // 設置透明通道
        imagealphablending($resized, false);
        // 保存透明通道
        imagesavealpha($resized, true);
        // 設置透明顏色
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        // 填充透明顏色
        imagefill($resized, 0, 0, $transparent);
        // 複製圖片
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        // 銷毀原圖
        imagedestroy($image);

        // 返回新圖片
        return $resized;
    }

    /**
     * 判斷圖片是否有透明通道
     * @return bool
     */
    private function imageHasTransparency($image, $ext): bool
    {
        // 如果副檔名不是 png
        if ($ext !== '.png') {
            return false;
        }

        // 如果圖片不是 truecolor
        if (! imageistruecolor($image)) {
            // 返回圖片是否有透明通道
            return imagecolortransparent($image) >= 0;
        }
        
        // 圖片寬度
        $width = imagesx($image);
        // 圖片高度
        $height = imagesy($image);
        // 步長
        $step = max(1, (int) min($width, $height) / 20);
        
        // 執行迴圈
        for ($x = 0; $x < $width; $x += $step) {
            // 執行迴圈
            for ($y = 0; $y < $height; $y += $step) {
                // 取得顏色 (32-bit: 0xAARRGGBB)
                $color = imagecolorat($image, $x, $y);
                // 提取 alpha 通道：右移 24 位取高 8 位，& 0x7F 取得透明度 (GD 中 0=不透明, 127=全透明)
                $alpha = ($color >> 24) & 0x7F;
                // 判斷 alpha 是否 > 0
                if ($alpha > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 儲存圖片
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
                // 壓縮等級 0-9：若傳入 0-9 則直接當等級，否則由品質換算
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
                // 載入 PNG 圖片
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
