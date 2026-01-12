<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 驗證碼識別命令
 */
class CrackCaptcha extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan crack:captcha {url}
     * {url} - 包含驗證碼的目標網址（必需參數）
     */
    protected $signature = 'crack:captcha {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Crack captcha from a webpage using OCR';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');

        $this->info('=== Captcha Cracker ===');
        $this->info("Target URL: {$url}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 檢查 Tesseract OCR 是否安裝
        if (!$this->checkTesseract()) {
            return 1;
        }

        // 創建 Puppeteer 腳本來提取驗證碼圖片
        $scriptPath = $this->createPuppeteerScript($url);

        // 執行腳本獲取驗證碼圖片
        $captchaImagePath = $this->runPuppeteerScript($scriptPath);

        if (!$captchaImagePath) {
            $this->error('Failed to extract captcha image');
            return 1;
        }

        // 預處理圖片（放大、增強對比度等）
        $processedImagePath = $this->preprocessImage($captchaImagePath);

        // 將圖片轉換為 PNG 格式（避免 JPEG 壓縮影響 OCR）
        $pngImagePath = $this->convertToPng($processedImagePath ?: $captchaImagePath);

        // 使用 OCR 識別驗證碼（優先使用 PNG 格式的圖片）
        $captchaText = $this->recognizeCaptcha($pngImagePath ?: ($processedImagePath ?: $captchaImagePath));
        
        // 如果處理後的圖片識別失敗，嘗試原始圖片
        if (!$captchaText && $processedImagePath) {
            $this->warn('');
            $this->warn('⚠️  Trying original image (without preprocessing)...');
            $originalPngPath = $this->convertToPng($captchaImagePath);
            $captchaText = $this->recognizeCaptcha($originalPngPath ?: $captchaImagePath);
        }

        if ($captchaText) {
            $this->info('');
            $this->info('✅ Captcha recognized successfully!');
            $this->info("📝 Captcha text: {$captchaText}");
            $this->info("💾 Captcha image saved to: {$captchaImagePath}");
            $this->info('');
            
            // 清理臨時文件（只清理腳本，保留圖片）
            $this->cleanup($captchaImagePath, $scriptPath);
            
            return 0;
        } else {
            $this->error('❌ Failed to recognize captcha');
            $this->info("💾 Captcha image saved to: {$captchaImagePath}");
            $this->cleanup($captchaImagePath, $scriptPath);
            return 1;
        }
    }

    /**
     * 檢查 Node.js 和 Puppeteer 環境
     * @return bool 返回 true 表示環境檢查通過，false 表示失敗
     */
    private function checkNodeJs()
    {
        $this->info('1. Checking Node.js installation...');

        // 檢查 Node.js 是否已安裝
        $result = Process::run('node --version');

        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            $this->line('Please install Node.js from: https://nodejs.org/');
            return false;
        }

        $this->info('✅ Node.js found: ' . trim($result->output()));

        // 檢查是否有 Puppeteer 套件
        $puppeteerCheck = Process::run('npm list puppeteer --depth=0');

        // Puppeteer 未安裝，嘗試自動安裝
        if ($puppeteerCheck->failed()) {
            $this->warn('⚠️  Puppeteer not found. Installing...');
            $this->info('Installing Puppeteer (this may take a few minutes)...');

            $install = Process::run('npm install puppeteer');

            if ($install->failed()) {
                $this->error('❌ Failed to install Puppeteer');
                $this->line('Please manually install: npm install puppeteer');
                return false;
            }

            $this->info('✅ Puppeteer installed successfully');
        } else {
            $this->info('✅ Puppeteer found');
        }

        return true;
    }

    /**
     * 檢查 Tesseract OCR 是否安裝
     * @return bool 返回 true 表示 Tesseract 已安裝，false 表示未安裝
     */
    private function checkTesseract()
    {
        $this->info('2. Checking Tesseract OCR installation...');

        $result = Process::run('tesseract --version');

        if ($result->failed()) {
            $this->error('❌ Tesseract OCR is not installed');
            $this->line('');
            $this->line('Please install Tesseract OCR:');
            $this->line('  macOS: brew install tesseract');
            $this->line('  Ubuntu/Debian: sudo apt-get install tesseract-ocr');
            $this->line('  Windows: Download from https://github.com/UB-Mannheim/tesseract/wiki');
            $this->line('');
            return false;
        }

        $version = trim($result->output());
        $versionLine = explode("\n", $version)[0] ?? $version;
        $this->info('✅ Tesseract OCR found: ' . $versionLine);

        return true;
    }

    /**
     * 創建 Puppeteer 腳本來提取驗證碼圖片
     * @param string $url 目標網址
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url)
    {
        $this->info('3. Creating browser automation script...');

        // 生成臨時腳本文件路徑
        $scriptPath = storage_path('app/temp/crack_captcha_' . time() . '.js');

        // 確保目錄存在
        $scriptDir = dirname($scriptPath);
        if (!is_dir($scriptDir)) {
            mkdir($scriptDir, 0755, true);
        }

        // 確保 scraped_data 目錄存在
        $scrapedDataDir = storage_path('app/scraped_data');
        if (!is_dir($scrapedDataDir)) {
            mkdir($scrapedDataDir, 0755, true);
        }

        // 生成驗證碼圖片保存路徑（保存到 scraped_data 資料夾）
        $imagePath = storage_path('app/scraped_data/captcha_' . date('Y-m-d_His') . '.png');

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');

            /**
             * 提取驗證碼圖片
             */
            async function extractCaptcha() {
                console.log('🚀 Starting browser automation...');

                const browser = await puppeteer.launch({
                    headless: 'new',
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        '--disable-gpu',
                    ],
                });

                try {
                    const page = await browser.newPage();
                    
                    // 設置視窗大小
                    await page.setViewport({ width: 1920, height: 1080 });
                    
                    // 訪問目標網址
                    console.log('📡 Navigating to: {$url}');
                    await page.goto('{$url}', {
                        waitUntil: 'networkidle2',
                        timeout: 30000,
                    });

                    // 等待頁面載入（使用 Promise 替代已棄用的 waitForTimeout）
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 嘗試多種方式找到驗證碼圖片
                    let captchaElement = null;
                    let imagePath = '{$imagePath}';

                    // 方法1: 查找常見的驗證碼選擇器
                    const captchaSelectors = [
                        'img[src*="captcha"]',
                        'img[src*="verify"]',
                        'img[src*="code"]',
                        'img[alt*="captcha" i]',
                        'img[alt*="驗證碼" i]',
                        'img[alt*="验证码" i]',
                        '.captcha img',
                        '#captcha img',
                        '.verify-code img',
                        '#verify-code img',
                        'img[src^="data:image"]', // 查找 base64 圖片
                        'canvas',
                    ];

                    for (const selector of captchaSelectors) {
                        try {
                            const element = await page.$(selector);
                            if (element) {
                                captchaElement = element;
                                console.log('✅ Found captcha element with selector: ' + selector);
                                break;
                            }
                        } catch (e) {
                            // 繼續嘗試下一個選擇器
                        }
                    }

                    if (!captchaElement) {
                        // 方法2: 查找所有 base64 圖片
                        const allImages = await page.$$('img[src^="data:image"]');
                        if (allImages.length > 0) {
                            captchaElement = allImages[0];
                            console.log('✅ Found base64 image');
                        }
                    }

                    if (!captchaElement) {
                        // 方法3: 查找所有圖片，讓用戶選擇或自動選擇第一個
                        const allImages = await page.$$('img');
                        if (allImages.length > 0) {
                            // 選擇第一個圖片作為候選
                            captchaElement = allImages[0];
                            console.log('⚠️  Using first image as captcha candidate');
                        }
                    }

                    if (!captchaElement) {
                        throw new Error('Could not find captcha element on the page');
                    }

                    // 檢查是否為 base64 圖片
                    const src = await captchaElement.evaluate(el => el.src || el.getAttribute('src'));
                    
                    if (src && src.startsWith('data:image')) {
                        // 處理 base64 圖片
                        console.log('📸 Processing base64 image...');
                        const base64Data = src.split(',')[1]; // 提取 base64 數據部分
                        if (!base64Data) {
                            throw new Error('Invalid base64 image data');
                        }
                        
                        // 檢測圖片格式並調整文件擴展名
                        let fileExtension = 'png';
                        if (src.includes('data:image/jpeg') || src.includes('data:image/jpg')) {
                            fileExtension = 'jpg';
                            imagePath = imagePath.replace('.png', '.jpg');
                        } else if (src.includes('data:image/gif')) {
                            fileExtension = 'gif';
                            imagePath = imagePath.replace('.png', '.gif');
                        } else if (src.includes('data:image/webp')) {
                            fileExtension = 'webp';
                            imagePath = imagePath.replace('.png', '.webp');
                        }
                        
                        // 將 base64 數據轉換為 Buffer 並保存
                        const imageBuffer = Buffer.from(base64Data, 'base64');
                        fs.writeFileSync(imagePath, imageBuffer);
                        console.log('📸 Base64 captcha image saved to: ' + imagePath);
                        console.log('📏 Image size: ' + imageBuffer.length + ' bytes');
                        console.log('📏 Base64 data length: ' + base64Data.length + ' characters');
                        
                        // 嘗試獲取圖片尺寸信息
                        try {
                            const sharp = require('sharp');
                            const metadata = await sharp(imageBuffer).metadata();
                            console.log('📐 Image dimensions: ' + metadata.width + 'x' + metadata.height + ' pixels');
                        } catch (e) {
                            // sharp 不可用，跳過
                        }
                    } else {
                        // 使用截圖方式，直接截取元素（不裁剪，保持原始尺寸）
                        const boundingBox = await captchaElement.boundingBox();
                        if (boundingBox) {
                            console.log('📐 Element size: ' + boundingBox.width + 'x' + boundingBox.height);
                        }
                        
                        await captchaElement.screenshot({ 
                            path: imagePath
                        });
                        
                        const stats = fs.statSync(imagePath);
                        console.log('📸 Captcha image saved to: ' + imagePath);
                        console.log('📏 Image size: ' + stats.size + ' bytes');
                    }

                    // 輸出圖片路徑供 PHP 使用
                    console.log('IMAGE_PATH:' + imagePath);

                    await browser.close();
                    return imagePath;
                } catch (error) {
                    console.error('❌ Error:', error.message);
                    await browser.close();
                    process.exit(1);
                }
            }

            extractCaptcha()
                .then((imagePath) => {
                    console.log('SUCCESS:' + imagePath);
                    process.exit(0);
                })
                .catch((error) => {
                    console.error('FAILED:' + error.message);
                    process.exit(1);
                });
        JS;

        // 寫入腳本文件
        file_put_contents($scriptPath, $script);

        $this->info('✅ Script created: ' . $scriptPath);

        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath 腳本文件路徑
     * @return string|false 返回驗證碼圖片路徑，失敗返回 false
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('4. Running browser automation script...');

        // 切換到 backend 目錄執行腳本
        $backendPath = base_path();
        $result = Process::path($backendPath)->run("node {$scriptPath}");

        if ($result->failed()) {
            $this->error('❌ Failed to run Puppeteer script');
            $this->error($result->errorOutput());
            return false;
        }

        $output = $result->output();
        
        // 從輸出中提取圖片路徑
        $imagePath = null;
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            if (strpos($line, 'IMAGE_PATH:') !== false) {
                $imagePath = trim(str_replace('IMAGE_PATH:', '', $line));
                break;
            }
            if (strpos($line, 'SUCCESS:') !== false) {
                $imagePath = trim(str_replace('SUCCESS:', '', $line));
                break;
            }
        }

        if ($imagePath && file_exists($imagePath)) {
            $this->info('✅ Captcha image extracted: ' . $imagePath);
            return $imagePath;
        } else {
            $this->error('❌ Failed to extract captcha image');
            $this->line('Output: ' . $output);
            return false;
        }
    }

    /**
     * 預處理驗證碼圖片（放大、增強對比度等）
     * @param string $imagePath 原始圖片路徑
     * @return string|false 返回處理後的圖片路徑，失敗返回 false
     */
    private function preprocessImage($imagePath)
    {
        if (!file_exists($imagePath)) {
            return false;
        }

        $this->info('6. Preprocessing captcha image...');

        // 生成處理後的圖片路徑
        $pathInfo = pathinfo($imagePath);
        $processedPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_processed.' . $pathInfo['extension'];

        // 方法1: 嘗試使用 ImageMagick（多種處理方式）
        $convertResult = Process::run("which convert 2>/dev/null");
        if (!$convertResult->failed() && trim($convertResult->output())) {
            $this->line('   Using ImageMagick for preprocessing...');
            
            // 嘗試方法1: 放大 + 反色 + 二值化（針對白底黑字驗證碼）
            $result = Process::run("convert '{$imagePath}' -resize 800% -negate -normalize -threshold 40% -type Grayscale '{$processedPath}' 2>&1");
            
            if (!$result->failed() && file_exists($processedPath)) {
                $this->info('   ✅ Image preprocessed with ImageMagick (8x, inverted, threshold)');
                return $processedPath;
            }
            
            // 嘗試方法2: 放大 + 強二值化（黑底白字）
            $result2 = Process::run("convert '{$imagePath}' -resize 800% -normalize -threshold 30% -type Grayscale '{$processedPath}' 2>&1");
            
            if (!$result2->failed() && file_exists($processedPath)) {
                $this->info('   ✅ Image preprocessed with ImageMagick (8x, strong threshold)');
                return $processedPath;
            }
            
            // 嘗試方法3: 放大 + 增強對比度 + 銳化
            $result3 = Process::run("convert '{$imagePath}' -resize 800% -normalize -contrast-stretch 0%x100% -sharpen 0x3 -type Grayscale '{$processedPath}' 2>&1");
            
            if (!$result3->failed() && file_exists($processedPath)) {
                $this->info('   ✅ Image preprocessed with ImageMagick (8x, high contrast)');
                return $processedPath;
            }
            
            // 嘗試方法4: 放大 + 邊緣增強 + 二值化
            $result4 = Process::run("convert '{$imagePath}' -resize 800% -normalize -edge 1 -threshold 50% -type Grayscale '{$processedPath}' 2>&1");
            
            if (!$result4->failed() && file_exists($processedPath)) {
                $this->info('   ✅ Image preprocessed with ImageMagick (8x, edge enhanced)');
                return $processedPath;
            }
            
            // 嘗試方法5: 簡單放大
            $result5 = Process::run("convert '{$imagePath}' -resize 600% '{$processedPath}' 2>&1");
            
            if (!$result5->failed() && file_exists($processedPath)) {
                $this->info('   ✅ Image preprocessed with ImageMagick (6x enlarged)');
                return $processedPath;
            }
            
            $this->line('   ⚠️  ImageMagick processing failed');
        }

        // 方法2: 嘗試使用 Node.js 的 sharp 庫（如果已安裝）
        $this->line('   Trying Node.js sharp library...');
        $sharpScript = storage_path('app/temp/preprocess_image_' . time() . '.js');
        $sharpCode = <<<JS
            const sharp = require('sharp');
            const fs = require('fs');

            async function preprocessImage() {
                try {
                    const inputPath = '{$imagePath}';
                    const outputPath = '{$processedPath}';

                    const metadata = await sharp(inputPath).metadata();
                    // 針對小圖片，使用更大的放大倍數
                    const scale = metadata.width < 100 ? 8 : (metadata.width < 200 ? 6 : 4);
                    const newWidth = Math.round(metadata.width * scale);
                    const newHeight = Math.round(metadata.height * scale);
                    
                    // 方法1: 放大 + 強二值化（低閾值，保留更多細節）
                    try {
                        await sharp(inputPath)
                            .resize(newWidth, newHeight, {
                                kernel: sharp.kernel.lanczos3
                            })
                            .normalize()
                            .greyscale()
                            .threshold(60)
                            .toFile(outputPath);

                        console.log('SUCCESS:' + outputPath);
                        process.exit(0);
                    } catch (e1) {
                        // 方法2: 放大 + 強二值化
                        try {
                            await sharp(inputPath)
                                .resize(newWidth, newHeight, {
                                    kernel: sharp.kernel.lanczos3
                                })
                                .normalize()
                                .greyscale()
                                .threshold(80)
                                .toFile(outputPath);

                            console.log('SUCCESS:' + outputPath);
                            process.exit(0);
                        } catch (e2) {
                            // 方法3: 放大 + 增強對比度 + 銳化
                            try {
                                await sharp(inputPath)
                                    .resize(newWidth, newHeight, {
                                        kernel: sharp.kernel.lanczos3
                                    })
                                    .normalize()
                                    .linear(1.5, -(128 * 0.5))
                                    .sharpen({ sigma: 2, flat: 1, jagged: 2 })
                                    .greyscale()
                                    .modulate({ brightness: 1.2, saturation: 0 })
                                    .toFile(outputPath);

                                console.log('SUCCESS:' + outputPath);
                                process.exit(0);
                            } catch (e3) {
                                // 方法4: 最簡單 - 只放大
                                await sharp(inputPath)
                                    .resize(newWidth, newHeight, {
                                        kernel: sharp.kernel.lanczos3
                                    })
                                    .toFile(outputPath);

                                console.log('SUCCESS:' + outputPath);
                                process.exit(0);
                            }
                        }
                    }
                } catch (error) {
                    if (error.code === 'MODULE_NOT_FOUND') {
                        console.log('SKIP:sharp not installed');
                    } else {
                        console.error('FAILED:' + error.message);
                    }
                    process.exit(1);
                }
            }

            preprocessImage();
        JS;

        file_put_contents($sharpScript, $sharpCode);
        $result = Process::run("node {$sharpScript} 2>&1");

        // 清理臨時腳本
        if (file_exists($sharpScript)) {
            @unlink($sharpScript);
        }

        $output = trim($result->output());
        if (strpos($output, 'SUCCESS:') !== false) {
            $successPath = trim(str_replace('SUCCESS:', '', $output));
            if (file_exists($successPath)) {
                $this->info('   ✅ Image preprocessed with sharp (4x enlarged)');
                return $successPath;
            }
        }

        // 方法3: 如果都失敗，返回原始圖片路徑
        $this->warn('   ⚠️  Image preprocessing tools not available, using original image');
        $this->warn('   💡 Install ImageMagick (brew install imagemagick) or sharp (npm install sharp) for better results');
        return false;
    }

    /**
     * 將圖片轉換為 PNG 格式（避免 JPEG 壓縮影響 OCR）
     * @param string $imagePath 原始圖片路徑
     * @return string|false 返回 PNG 圖片路徑，失敗返回 false
     */
    private function convertToPng($imagePath)
    {
        if (!file_exists($imagePath)) {
            return false;
        }

        $pathInfo = pathinfo($imagePath);
        
        // 如果已經是 PNG，直接返回
        if (strtolower($pathInfo['extension']) === 'png') {
            return $imagePath;
        }

        $pngPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.png';

        // 方法1: 使用 ImageMagick
        $convertResult = Process::run("which convert 2>/dev/null");
        if (!$convertResult->failed() && trim($convertResult->output())) {
            $result = Process::run("convert '{$imagePath}' '{$pngPath}' 2>&1");
            if (!$result->failed() && file_exists($pngPath)) {
                return $pngPath;
            }
        }

        // 方法2: 使用 sharp
        $sharpScript = storage_path('app/temp/convert_png_' . time() . '.js');
        $sharpCode = <<<JS
            const sharp = require('sharp');
            (async function() {
                try {
                    await sharp('{$imagePath}')
                        .png()
                        .toFile('{$pngPath}');
                    console.log('SUCCESS:' + '{$pngPath}');
                    process.exit(0);
                } catch (error) {
                    if (error.code === 'MODULE_NOT_FOUND') {
                        console.log('SKIP:sharp not installed');
                    } else {
                        console.error('FAILED:' + error.message);
                    }
                    process.exit(1);
                }
            })();
        JS;

        file_put_contents($sharpScript, $sharpCode);
        $result = Process::run("node {$sharpScript} 2>&1");
        
        if (file_exists($sharpScript)) {
            @unlink($sharpScript);
        }

        if (file_exists($pngPath)) {
            return $pngPath;
        }

        // 如果轉換失敗，返回原始路徑
        return $imagePath;
    }

    /**
     * 驗證識別結果是否符合驗證碼格式（4-5位數字）
     * @param string $text 識別的文字
     * @return bool 返回 true 表示符合格式，false 表示不符合
     */
    private function validateCaptchaText($text)
    {
        // 驗證碼必須是4-5位純數字
        return !empty($text) && preg_match('/^\d{4,5}$/', $text);
    }

    /**
     * 使用 Tesseract OCR 識別驗證碼
     * @param string $imagePath 驗證碼圖片路徑
     * @return string|false 返回識別的文字，失敗返回 false
     */
    private function recognizeCaptcha($imagePath)
    {
        $this->info('5. Recognizing captcha with OCR...');
        $this->info('   Expected format: 4-5 digits');

        if (!file_exists($imagePath)) {
            $this->error('❌ Captcha image file not found: ' . $imagePath);
            return false;
        }

        // 顯示圖片信息
        $imageSize = filesize($imagePath);
        $imageInfo = @getimagesize($imagePath);
        $this->line("   Image file: {$imagePath}");
        $this->line("   Image size: " . number_format($imageSize) . " bytes");
        $isSmallImage = false;
        if ($imageInfo) {
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $this->line("   Image dimensions: {$width}x{$height} pixels");
            // 如果圖片很小，標記為小圖片
            $isSmallImage = ($width < 300 || $height < 100);
            if ($isSmallImage) {
                $this->warn("   ⚠️  Small image detected, using enhanced OCR settings");
            }
        }

        // 嘗試多種 PSM 模式
        // PSM 模式說明：
        // 7: 將圖片視為單行文字（適合連續數字）
        // 8: 將圖片視為單字（適合分開的數字）
        // 6: 將圖片視為單一文字塊
        // 13: 原始行，不做任何特定處理
        // 11: 稀疏文字，尋找盡可能多的文字
        // 10: 單個字符
        // 12: 稀疏文字，單行
        $psmModes = [7, 8, 6, 13, 11, 10, 12];
        // 純數字驗證碼，只使用數字白名單
        $characterWhitelist = '0123456789';

        // 針對小圖片，使用特殊的 OCR 參數
        $smallImageParams = $isSmallImage ? '-c tessedit_pageseg_mode=7 -c tessedit_char_whitelist=0123456789 --psm 7' : '';
        
        // 收集所有識別結果，用於後續組合
        $allRecognizedDigits = [];
        
        // 首先嘗試最基礎的 OCR 命令（不使用任何參數）
        $this->line("   Trying basic OCR (no parameters)...");
        $result = Process::run("tesseract '{$imagePath}' stdout 2>&1");
        
        if (!$result->failed()) {
            $rawText = trim($result->output());
            preg_match_all('/\d/', $rawText, $matches);
            $extractedDigits = implode('', $matches[0] ?? []);
            
            if ($this->validateCaptchaText($extractedDigits)) {
                $this->info("   ✅ Success with basic OCR");
                $this->info('✅ OCR recognition completed');
                return $extractedDigits;
            } elseif (!empty($extractedDigits)) {
                $allRecognizedDigits[] = $extractedDigits;
                $this->line("   📄 Basic OCR output: '{$rawText}'");
                $this->line("   ⚠️  Extracted digits: '{$extractedDigits}' (length: " . strlen($extractedDigits) . ")");
            }
        } else {
            $errorOutput = trim($result->errorOutput());
            if (!empty($errorOutput) && strpos($errorOutput, 'Empty page') === false) {
                $this->line("   ⚠️  OCR error: {$errorOutput}");
            }
        }

        foreach ($psmModes as $psm) {
            $this->line("   Trying PSM mode {$psm}...");
            
            // 優先嘗試使用數字白名單 + 數字模式
            $result = Process::run("tesseract '{$imagePath}' stdout -l eng --psm {$psm} -c tessedit_char_whitelist={$characterWhitelist} -c classify_bln_numeric_mode=1 2>&1");
            
            if (!$result->failed()) {
                $captchaText = trim($result->output());
                $captchaText = preg_replace('/\s+/', '', $captchaText);
                
                // 驗證結果是否符合格式（4-5位數字）
                if ($this->validateCaptchaText($captchaText)) {
                    $this->info("   ✅ Success with PSM {$psm} (numeric mode + whitelist)");
                    $this->info('✅ OCR recognition completed');
                    return $captchaText;
                } elseif (!empty($captchaText) && preg_match('/^\d+$/', $captchaText)) {
                    $allRecognizedDigits[] = $captchaText;
                    $this->line("   ⚠️  Recognized: '{$captchaText}' (length: " . strlen($captchaText) . ", expected: 4-5)");
                } elseif (!empty($captchaText)) {
                    $this->line("   📄 OCR output: '{$captchaText}'");
                    // 從輸出中提取數字
                    preg_match_all('/\d/', $captchaText, $matches);
                    $extracted = implode('', $matches[0] ?? []);
                    if (!empty($extracted)) {
                        $allRecognizedDigits[] = $extracted;
                    }
                }
            } else {
                $errorOutput = trim($result->errorOutput());
                if (!empty($errorOutput) && strpos($errorOutput, 'Warning') === false && strpos($errorOutput, 'Empty page') === false) {
                    $this->line("   ⚠️  OCR error: {$errorOutput}");
                }
            }
            
            // 嘗試使用數字白名單（不使用數字模式）
            $result = Process::run("tesseract '{$imagePath}' stdout -l eng --psm {$psm} -c tessedit_char_whitelist={$characterWhitelist} 2>&1");
            
            if (!$result->failed()) {
                $captchaText = trim($result->output());
                $captchaText = preg_replace('/\s+/', '', $captchaText);
                
                // 驗證結果是否符合格式（4-5位數字）
                if ($this->validateCaptchaText($captchaText)) {
                    $this->info("   ✅ Success with PSM {$psm} (with numeric whitelist)");
                    $this->info('✅ OCR recognition completed');
                    return $captchaText;
                } elseif (!empty($captchaText) && preg_match('/^\d+$/', $captchaText)) {
                    $allRecognizedDigits[] = $captchaText;
                    $this->line("   ⚠️  Recognized: '{$captchaText}' (length: " . strlen($captchaText) . ", expected: 4-5)");
                } elseif (!empty($captchaText)) {
                    $this->line("   📄 OCR output: '{$captchaText}'");
                    // 從輸出中提取數字
                    preg_match_all('/\d/', $captchaText, $matches);
                    $extracted = implode('', $matches[0] ?? []);
                    if (!empty($extracted)) {
                        $allRecognizedDigits[] = $extracted;
                    }
                }
            }
        }

        // 所有模式都失敗，嘗試使用更寬鬆的設置
        $this->warn('   ⚠️  All PSM modes failed, trying relaxed settings...');
        $result = Process::run("tesseract {$imagePath} stdout -l eng --psm 11 -c tessedit_char_whitelist={$characterWhitelist} -c classify_bln_numeric_mode=1 2>/dev/null");
        
        if (!$result->failed()) {
            $captchaText = trim($result->output());
            $captchaText = preg_replace('/\s+/', '', $captchaText);
            
            // 驗證結果是否符合格式（4-5位數字）
            if ($this->validateCaptchaText($captchaText)) {
                $this->info('   ✅ Success with relaxed settings (numeric mode)');
                $this->info('✅ OCR recognition completed');
                return $captchaText;
            } elseif (!empty($captchaText) && preg_match('/^\d+$/', $captchaText)) {
                $this->line("   ⚠️  Recognized: '{$captchaText}' (length: " . strlen($captchaText) . ", expected: 4-5)");
            }
        }
        
        // 最後嘗試：不使用數字模式，但使用數字白名單
        $result = Process::run("tesseract {$imagePath} stdout -l eng --psm 7 -c tessedit_char_whitelist={$characterWhitelist} 2>/dev/null");
        
        if (!$result->failed()) {
            $captchaText = trim($result->output());
            $captchaText = preg_replace('/\s+/', '', $captchaText);
            
            // 驗證結果是否符合格式（4-5位數字）
            if ($this->validateCaptchaText($captchaText)) {
                $this->info('   ✅ Success with final attempt');
                $this->info('✅ OCR recognition completed');
                return $captchaText;
            } elseif (!empty($captchaText) && preg_match('/^\d+$/', $captchaText)) {
                $this->line("   ⚠️  Recognized: '{$captchaText}' (length: " . strlen($captchaText) . ", expected: 4-5)");
            }
        }

        // 額外嘗試：不使用白名單，從結果中提取數字
        $this->warn('   ⚠️  Trying without whitelist to extract digits...');
        $result = Process::run("tesseract {$imagePath} stdout -l eng --psm 7 2>&1");
        
        if (!$result->failed()) {
            $rawText = trim($result->output());
            $this->line("   📄 Raw OCR output: '{$rawText}'");
            
            // 提取所有數字
            preg_match_all('/\d/', $rawText, $matches);
            $extractedDigits = implode('', $matches[0] ?? []);
            
            if ($this->validateCaptchaText($extractedDigits)) {
                $this->info('   ✅ Success by extracting digits from raw OCR result');
                $this->info('✅ OCR recognition completed');
                return $extractedDigits;
            } elseif (!empty($extractedDigits)) {
                $this->line("   ⚠️  Extracted digits: '{$extractedDigits}' (length: " . strlen($extractedDigits) . ", expected: 4-5)");
            } else {
                $this->line("   ⚠️  No digits found in OCR result");
            }
        } else {
            $this->line("   ⚠️  OCR command failed: " . trim($result->errorOutput()));
        }
        
        // 最後嘗試：使用最寬鬆的設置，並顯示詳細輸出
        $this->warn('   ⚠️  Trying with very relaxed settings...');
        $result = Process::run("tesseract '{$imagePath}' stdout -l eng --psm 6 -c tessedit_char_whitelist={$characterWhitelist} 2>&1");
        
        if (!$result->failed()) {
            $rawText = trim($result->output());
            $this->line("   📄 Relaxed OCR output: '{$rawText}'");
            
            $captchaText = preg_replace('/\s+/', '', $rawText);
            if ($this->validateCaptchaText($captchaText)) {
                $this->info('   ✅ Success with relaxed settings');
                $this->info('✅ OCR recognition completed');
                return $captchaText;
            } elseif (!empty($captchaText) && preg_match('/^\d+$/', $captchaText)) {
                $allRecognizedDigits[] = $captchaText;
            } else {
                // 從輸出中提取數字
                preg_match_all('/\d/', $rawText, $matches);
                $extracted = implode('', $matches[0] ?? []);
                if (!empty($extracted)) {
                    $allRecognizedDigits[] = $extracted;
                }
            }
        }
        
        // 針對小圖片，嘗試使用特殊參數
        if ($isSmallImage) {
            $this->warn('   ⚠️  Trying special settings for small images...');
            $result = Process::run("tesseract '{$imagePath}' stdout -l eng --psm 7 -c tessedit_char_whitelist={$characterWhitelist} -c classify_bln_numeric_mode=1 -c textord_min_linesize=2.5 2>&1");
            
            if (!$result->failed()) {
                $rawText = trim($result->output());
                $this->line("   📄 Small image OCR output: '{$rawText}'");
                
                $captchaText = preg_replace('/\s+/', '', $rawText);
                if ($this->validateCaptchaText($captchaText)) {
                    $this->info('   ✅ Success with small image settings');
                    $this->info('✅ OCR recognition completed');
                    return $captchaText;
                } elseif (!empty($captchaText) && preg_match('/^\d+$/', $captchaText)) {
                    $allRecognizedDigits[] = $captchaText;
                    $this->line("   ⚠️  Recognized: '{$captchaText}' (length: " . strlen($captchaText) . ", expected: 4-5)");
                } else {
                    // 從輸出中提取數字
                    preg_match_all('/\d/', $rawText, $matches);
                    $extracted = implode('', $matches[0] ?? []);
                    if (!empty($extracted)) {
                        $allRecognizedDigits[] = $extracted;
                    }
                }
            }
        }

        // 嘗試組合所有識別出的數字
        if (!empty($allRecognizedDigits)) {
            $this->warn('   ⚠️  Trying to combine recognized digits...');
            $this->line("   📊 Collected digits: " . implode(', ', array_unique($allRecognizedDigits)));
            
            // 方法1: 直接組合所有數字（去除重複）
            $combined = implode('', array_unique(str_split(implode('', $allRecognizedDigits))));
            if ($this->validateCaptchaText($combined)) {
                $this->info('   ✅ Success by combining all recognized digits');
                $this->info('✅ OCR recognition completed');
                return $combined;
            }
            
            // 方法2: 選擇最長的識別結果
            usort($allRecognizedDigits, function($a, $b) {
                return strlen($b) - strlen($a);
            });
            $longest = $allRecognizedDigits[0];
            if (strlen($longest) >= 3) {
                $this->line("   📝 Longest recognized: '{$longest}' (length: " . strlen($longest) . ")");
            }
            
            // 方法3: 嘗試拼接不同的結果
            foreach ($allRecognizedDigits as $i => $digits1) {
                foreach ($allRecognizedDigits as $j => $digits2) {
                    if ($i !== $j) {
                        $combined = $digits1 . $digits2;
                        if ($this->validateCaptchaText($combined)) {
                            $this->info("   ✅ Success by combining '{$digits1}' + '{$digits2}'");
                            $this->info('✅ OCR recognition completed');
                            return $combined;
                        }
                    }
                }
            }
            
            // 方法4: 如果有多個結果，嘗試按順序組合
            if (count($allRecognizedDigits) >= 2) {
                $combined = implode('', $allRecognizedDigits);
                $combined = preg_replace('/(\d)\1+/', '$1', $combined); // 去除連續重複
                if ($this->validateCaptchaText($combined)) {
                    $this->info('   ✅ Success by sequential combination');
                    $this->info('✅ OCR recognition completed');
                    return $combined;
                }
            }
        }

        $this->error('❌ All OCR attempts failed');
        if (!empty($allRecognizedDigits)) {
            $this->warn("   📊 Recognized digits (partial): " . implode(', ', array_unique($allRecognizedDigits)));
        }
        $this->warn("   💡 Tip: The captcha image has been saved to: {$imagePath}");
        $this->warn("   💡 You can manually check the image to see if it's readable");
        $this->warn("   💡 The image might be too small or have poor quality");
        return false;
    }

    /**
     * 清理臨時文件
     * @param string|null $imagePath 圖片路徑
     * @param string|null $scriptPath 腳本路徑
     */
    private function cleanup($imagePath = null, $scriptPath = null)
    {
        // 不刪除圖片文件，因為它們保存在 scraped_data 資料夾中供後續使用
        // if ($imagePath && file_exists($imagePath)) {
        //     @unlink($imagePath);
        // }

        // 只清理臨時腳本文件
        if ($scriptPath && file_exists($scriptPath)) {
            @unlink($scriptPath);
        }
    }
}

