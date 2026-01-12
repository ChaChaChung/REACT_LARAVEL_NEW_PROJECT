<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - ZGSLOT
 */
class ScrapeBrowserZgslotDOMDetail2 extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-zgslot-dom-detail-auto {url}
     * {url} - 要爬取的目標網址（必需參數）
     */
    protected $signature = 'agent:scrape-zgslot-dom-detail-auto {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from ZGSLOT DOM elements using browser automation with login';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');

        $this->info('=== ZGSLOT DOM Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理爬取的資料
        if ($result) {
            $this->processScrapedData($result);
            return 0;
        }

        return 1;
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

        // 檢查是否有 Tesseract.js 套件（用於 OCR 識別驗證碼，必需）
        $tesseractCheck = Process::run('npm list tesseract.js --depth=0');

        if ($tesseractCheck->failed()) {
            $this->error('❌ Tesseract.js not found. OCR recognition is required.');
            $this->line('Please install: npm install tesseract.js');
            $this->line('Note: OCR recognition is required for automatic verification code filling');
            return false;
        } else {
            $this->info('✅ Tesseract.js found (OCR enabled)');
        }
        
        // 檢查是否有 sharp 套件（用於圖像預處理，可選但推薦）
        $sharpCheck = Process::run('npm list sharp --depth=0');
        
        if ($sharpCheck->failed()) {
            $this->warn('⚠️  Sharp not found. Image preprocessing will be disabled.');
            $this->line('To improve OCR accuracy, install: npm install sharp');
            $this->line('Note: Sharp helps preprocess images for better OCR recognition');
        } else {
            $this->info('✅ Sharp found (image preprocessing enabled)');
        }

        return true;
    }

    /**
     * 創建 Puppeteer 自動化腳本（登入流程）
     * @param string $url 要爬取的目標網址
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url)
    {
        $this->info('2. Creating browser automation script...');

        // 從環境變數獲取登入資訊
        $domain = env('ZGSLOT_AGENT_DOMAIN', '');
        $account = env('ZGSLOT_AGENT_ACCOUNT', '');
        $password = env('ZGSLOT_AGENT_PASSWORD', '');
        $verificationCode = env('ZGSLOT_AGENT_VERIFICATION_CODE', '');
        
        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);
        $verificationCodeJs = json_encode($verificationCode);
        $urlJs = json_encode($url);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            
            // 嘗試載入 tesseract.js（如果已安裝）
            let Tesseract = null;
            try {
                Tesseract = require('tesseract.js');
                console.log('✅ Tesseract.js loaded');
            } catch (e) {
                console.log('⚠️  Tesseract.js not found, OCR will be disabled');
                console.log('   To enable OCR, run: npm install tesseract.js');
            }
            
            // 嘗試載入 sharp（如果已安裝）
            let sharp = null;
            try {
                sharp = require('sharp');
                console.log('✅ Sharp loaded');
            } catch (e) {
                console.log('⚠️  Sharp not found, image preprocessing will be disabled');
                console.log('   To enable image preprocessing, run: npm install sharp');
            }

            /**
             * ZGSLOT 登入流程
             * 使用 Puppeteer 自動化瀏覽器來完成登入
             */
            async function loginAndNavigate() {
                // 啟動無頭瀏覽器（headless mode）
                const browser = await puppeteer.launch({
                    headless: 'new',
                    args: [
                        // 安全性相關參數（用於容器環境）
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        // 性能優化參數
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--no-zygote',
                        '--single-process',
                        '--disable-gpu',
                        '--disable-software-rasterizer',
                        // 背景處理優化
                        '--disable-background-timer-throttling',
                        '--disable-backgrounding-occluded-windows',
                        '--disable-renderer-backgrounding',
                        // 功能禁用（減少資源使用）
                        '--disable-features=TranslateUI',
                        '--disable-ipc-flooding-protection',
                        '--disable-crash-reporter',
                        '--disable-breakpad',
                        '--disable-default-apps',
                        '--disable-extensions',
                        '--disable-plugins',
                        '--disable-web-security',
                        '--disable-features=VizDisplayCompositor',
                        '--temp-profile',
                        '--memory-pressure-off',
                        // 額外的性能優化
                        '--disable-javascript-harmony-shipping',
                        '--disable-sync'
                    ],
                    // 如果環境變數中指定了 Chrome 路徑，則使用該路徑
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    // 創建新的瀏覽器頁面
                    const page = await browser.newPage();

                    // 設定視窗大小為 1920x1080（模擬桌面瀏覽器）
                    await page.setViewport({ width: 1920, height: 1080 });

                    // 設定 User Agent，模擬真實的瀏覽器請求
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

                    console.log('🔐 Starting ZGSLOT login process...');
                    
                    // 導航到登入頁面
                    await page.goto($domainJs, {
                        waitUntil: 'load',
                        timeout: 60000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 截圖：初始登入頁面
                    try {
                        await page.screenshot({ path: '01_initial_login_page.png', fullPage: true });
                        console.log('📸 Screenshot 01: Initial login page saved');
                    } catch (e) {
                        console.log('⚠️  Error taking screenshot: ' + e.message);
                    }
                    
                    // 步驟 1: 查找並填入帳號欄位
                    console.log('📝 Step 1: Filling account...');
                    const accountInput = await page.waitForSelector('input#mat-input-0, input[placeholder="用户名"]', { timeout: 10000 }).catch(() => null);
                    
                    if (accountInput) {
                        try {
                            // 先點擊輸入框以獲得焦點
                            await accountInput.click();
                            await new Promise(resolve => setTimeout(resolve, 200));
                            
                            // 清空輸入框（選中所有內容並刪除）
                            await accountInput.click({ clickCount: 3 });
                            await page.keyboard.press('Backspace');
                            await new Promise(resolve => setTimeout(resolve, 100));
                            
                            // 使用 type 方法模擬真實輸入（Angular Material 需要）
                            // $accountJs 已經是 JSON 編碼的字符串，直接使用（去掉引號）
                            const accountValue = $accountJs && $accountJs !== 'null' ? $accountJs.replace(/^"|"$/g, '') : '';
                            await accountInput.type(accountValue, { delay: 50 });
                            
                            // 觸發額外的事件確保 Angular 檢測到變化
                            await page.evaluate(() => {
                                const input = document.querySelector('input#mat-input-0') || document.querySelector('input[placeholder="用户名"]');
                                if (input) {
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                            });
                            
                            console.log('✅ Account filled');
                            await new Promise(resolve => setTimeout(resolve, 500));
                        } catch (e) {
                            console.log('⚠️  Error filling account: ' + e.message);
                            // 如果 type 失敗，嘗試使用 evaluate 方法
                            await page.evaluate((account) => {
                                const input = document.querySelector('input#mat-input-0') || document.querySelector('input[placeholder="用户名"]');
                                if (input) {
                                    input.focus();
                                    input.value = '';
                                    input.value = account;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                            }, $accountJs && $accountJs !== 'null' ? $accountJs.replace(/^"|"$/g, '') : '');
                        }
                        
                        // 截圖：填入帳號後
                        try {
                            await page.screenshot({ path: '02_after_account_filled.png', fullPage: true });
                            console.log('📸 Screenshot 02: After account filled saved');
                        } catch (e) {
                            console.log('⚠️  Error taking screenshot: ' + e.message);
                        }
                    } else {
                        console.log('⚠️  Account input field not found');
                    }
                    
                    // 步驟 2: 查找並填入密碼欄位
                    console.log('📝 Step 2: Filling password...');
                    const passwordInput = await page.waitForSelector('input#mat-input-1, input[type="password"][placeholder="密码英或数半形"]', { timeout: 10000 }).catch(() => null);
                    
                    if (passwordInput) {
                        try {
                            // 先點擊輸入框以獲得焦點
                            await passwordInput.click();
                            await new Promise(resolve => setTimeout(resolve, 200));
                            
                            // 清空輸入框（選中所有內容並刪除）
                            await passwordInput.click({ clickCount: 3 });
                            await page.keyboard.press('Backspace');
                            await new Promise(resolve => setTimeout(resolve, 100));
                            
                            // 使用 type 方法模擬真實輸入（Angular Material 需要）
                            // $passwordJs 已經是 JSON 編碼的字符串，直接使用（去掉引號）
                            const passwordValue = $passwordJs && $passwordJs !== 'null' ? $passwordJs.replace(/^"|"$/g, '') : '';
                            await passwordInput.type(passwordValue, { delay: 50 });
                            
                            // 觸發額外的事件確保 Angular 檢測到變化
                            await page.evaluate(() => {
                                const input = document.querySelector('input#mat-input-1') || document.querySelector('input[type="password"][placeholder="密码英或数半形"]');
                                if (input) {
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                            });
                            
                            console.log('✅ Password filled');
                            await new Promise(resolve => setTimeout(resolve, 500));
                        } catch (e) {
                            console.log('⚠️  Error filling password: ' + e.message);
                            // 如果 type 失敗，嘗試使用 evaluate 方法
                            await page.evaluate((password) => {
                                const input = document.querySelector('input#mat-input-1') || document.querySelector('input[type="password"][placeholder="密码英或数半形"]');
                                if (input) {
                                    input.focus();
                                    input.value = '';
                                    input.value = password;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                            }, $passwordJs && $passwordJs !== 'null' ? $passwordJs.replace(/^"|"$/g, '') : '');
                        }
                        
                        // 截圖：填入密碼後
                        try {
                            await page.screenshot({ path: '03_after_password_filled.png', fullPage: true });
                            console.log('📸 Screenshot 03: After password filled saved');
                        } catch (e) {
                            console.log('⚠️  Error taking screenshot: ' + e.message);
                        }
                    } else {
                        console.log('⚠️  Password input field not found');
                    }
                    
                    // 步驟 3: 查找並填入驗證碼欄位
                    console.log('📝 Step 3: Filling verification code...');
                    
                    // 等待驗證碼輸入框出現
                    const verificationInput = await page.waitForSelector('input#mat-input-2, input[placeholder="验证码"]', { timeout: 10000 }).catch(() => null);
                    
                    let finalCaptchaCode = '';
                    let captchaSuccess = false;
                    let retries = 0;
                    
                    // 驗證碼辨識迴圈（使用更靈活的圖片查找方法）
                    while (!captchaSuccess && retries < 5) {
                        retries++;
                        console.log(`🔍 OCR Attempt \${retries}...`);

                        // 使用更靈活的方法查找驗證碼圖片（類似舊代碼的方法）
                        const verificationImageInfo = await page.evaluate(() => {
                            const selectors = [
                                'img[alt*="验证码"]',
                                'img[alt*="驗證碼"]',
                                'img[alt*="captcha"]',
                                'img[alt*="code"]',
                                'img[src*="captcha"]',
                                'img[src*="verification"]',
                                'img[src*="code"]',
                                'img[src*="verify"]',
                                'img.captcha',
                                'img.verification-code',
                                'img.verify-code',
                                'canvas',
                                '[class*="captcha"] img',
                                '[class*="verification"] img',
                                '[class*="verify"] img'
                            ];
                            
                            let imageElement = null;
                            for (const selector of selectors) {
                                try {
                                    const element = document.querySelector(selector);
                                    if (element && (element.tagName === 'IMG' || element.tagName === 'CANVAS')) {
                                        const rect = element.getBoundingClientRect();
                                        if (rect.width > 0 && rect.height > 0) {
                                            imageElement = element;
                                            break;
                                        }
                                    }
                                } catch (e) {
                                    continue;
                                }
                            }
                            
                            // 如果沒找到，嘗試在驗證碼輸入框附近查找
                            if (!imageElement) {
                                const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                                if (input) {
                                    let parent = input.parentElement;
                                    let depth = 0;
                                    while (parent && depth < 5) {
                                        const images = parent.querySelectorAll('img, canvas');
                                        for (const img of images) {
                                            const rect = img.getBoundingClientRect();
                                            if (rect.width > 0 && rect.height > 0 && rect.width < 500 && rect.height < 500) {
                                                imageElement = img;
                                                break;
                                            }
                                        }
                                        if (imageElement) break;
                                        parent = parent.parentElement;
                                        depth++;
                                    }
                                }
                            }
                            
                            if (imageElement) {
                                return {
                                    found: true,
                                    tagName: imageElement.tagName,
                                    src: imageElement.src || imageElement.getAttribute('src') || '',
                                    alt: imageElement.alt || imageElement.getAttribute('alt') || ''
                                };
                            }
                            
                            return { found: false };
                        });
                        
                        if (!verificationImageInfo.found) {
                            console.log('⚠️  Verification code image not found, waiting...');
                            await new Promise(r => setTimeout(r, 2000));
                            continue;
                        }
                        
                        // 使用 evaluateHandle 獲取圖片元素
                        const imageElement = await page.evaluateHandle(() => {
                            const selectors = [
                                'img[alt*="验证码"]',
                                'img[alt*="驗證碼"]',
                                'img[alt*="captcha"]',
                                'img[alt*="code"]',
                                'img[src*="captcha"]',
                                'img[src*="verification"]',
                                'img[src*="code"]',
                                'img[src*="verify"]',
                                'img.captcha',
                                'img.verification-code',
                                'img.verify-code',
                                'canvas',
                                '[class*="captcha"] img',
                                '[class*="verification"] img',
                                '[class*="verify"] img'
                            ];
                            
                            let imageElement = null;
                            for (const selector of selectors) {
                                try {
                                    const element = document.querySelector(selector);
                                    if (element && (element.tagName === 'IMG' || element.tagName === 'CANVAS')) {
                                        const rect = element.getBoundingClientRect();
                                        if (rect.width > 0 && rect.height > 0) {
                                            imageElement = element;
                                            break;
                                        }
                                    }
                                } catch (e) {
                                    continue;
                                }
                            }
                            
                            if (!imageElement) {
                                const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                                if (input) {
                                    let parent = input.parentElement;
                                    let depth = 0;
                                    while (parent && depth < 5) {
                                        const images = parent.querySelectorAll('img, canvas');
                                        for (const img of images) {
                                            const rect = img.getBoundingClientRect();
                                            if (rect.width > 0 && rect.height > 0 && rect.width < 500 && rect.height < 500) {
                                                imageElement = img;
                                                break;
                                            }
                                        }
                                        if (imageElement) break;
                                        parent = parent.parentElement;
                                        depth++;
                                    }
                                }
                            }
                            
                            return imageElement;
                        });
                        
                        if (!imageElement || !imageElement.asElement()) {
                            console.log('⚠️  Could not get image element handle');
                            await new Promise(r => setTimeout(r, 2000));
                            continue;
                        }
                        
                        const rawPath = 'captcha_raw.png';
                        const processedPath = 'captcha_processed.png';
                        await imageElement.asElement().screenshot({ path: rawPath });

                        // 針對 5 位數純數字進行圖像優化（針對中間有干擾線的驗證碼）
                        let imageToRecognize = rawPath;
                        if (sharp) {
                            try {
                                await sharp(rawPath)
                                    .resize(500) // 放大倍率提高
                                    .greyscale()
                                    .median(7) // 強去噪，去除中間干擾線
                                    .normalize()
                                    .linear(2.5, -(128 * 0.5)) // 增強對比度
                                    .sharpen({ sigma: 2.0, m1: 1, m2: 2 })
                                    .threshold(220) // 提高閾值以過濾背景雜訊和干擾線
                                    .toFile(processedPath);
                                imageToRecognize = processedPath;
                                console.log('   ✅ Image preprocessed with sharp');
                            } catch (sharpError) {
                                console.log('   ⚠️  Sharp preprocessing failed: ' + sharpError.message);
                                console.log('   💡 Using original image');
                            }
                        } else {
                            console.log('   ⚠️  Sharp not available, using original image');
                        }

                        // 檢查 Tesseract 是否可用
                        if (!Tesseract) {
                            console.log('❌ Tesseract.js not available, cannot recognize captcha');
                            await new Promise(r => setTimeout(r, 2000));
                            continue;
                        }

                        const { data: { text } } = await Tesseract.recognize(imageToRecognize, 'eng', {
                            tessedit_char_whitelist: '0123456789',
                            tessedit_pageseg_mode: '8' // 視為單個單詞
                        });

                        const recognized = text.replace(/[^0-9]/g, '').trim();
                        console.log(`📝 Recognized: "\${recognized}"`);

                        // 根據你的案例 19396，驗證碼長度應為 5 位
                        if (recognized.length >= 4 && recognized.length <= 6) {
                            finalCaptchaCode = recognized;
                            captchaSuccess = true;
                            console.log(`✅ Captcha recognized successfully: "\${finalCaptchaCode}"`);
                        } else {
                            console.log(`⚠️  Invalid captcha length (\${recognized.length}), refreshing...`);
                            // 點擊驗證碼圖片刷新
                            try {
                                await imageElement.asElement().click();
                                await new Promise(r => setTimeout(r, 1500));
                            } catch (e) {
                                console.log('⚠️  Could not click image, waiting...');
                                await new Promise(r => setTimeout(r, 2000));
                            }
                        }
                    }

                    // 定義 OCR 識別的驗證碼變數和圖片處理路徑（在外部作用域）
                    let recognizedCode = null;
                    let processedPath = null;
                    let processedPath2 = null;
                    let processedPath3 = null;
                    let processedPath4 = null;
                    let processedPath5 = null;
                    let processedPath6 = null;
                    let processedPath7 = null;
                    let processedPath8 = null;
                    let processedPath9 = null;
                    let processedPath10 = null;
                    let processedPath11 = null;
                    let processedPath12 = null;
                    let processedPath13 = null;
                    let processedPath14 = null;
                    let processedPath15 = null;
                    
                    if (verificationInput) {
                        console.log('✅ Verification code input field found');
                        
                        // 如果新循環識別成功，跳過舊的 OCR 方法
                        let verificationImageInfo = null;
                        if (finalCaptchaCode && finalCaptchaCode.length > 0) {
                            console.log('✅ Using verification code from new OCR loop: "' + finalCaptchaCode + '"');
                            recognizedCode = finalCaptchaCode; // 設置 recognizedCode 以便後續使用
                            
                            // 仍然需要獲取驗證碼圖片信息以便標記
                            verificationImageInfo = await page.evaluate(() => {
                                const selectors = [
                                    'img[alt*="验证码"]',
                                    'img[alt*="驗證碼"]',
                                    'img[alt*="captcha"]',
                                    'img[alt*="code"]',
                                    'img[src*="captcha"]',
                                    'img[src*="verification"]',
                                    'img[src*="code"]',
                                    'img[src*="verify"]',
                                    'img.captcha',
                                    'img.verification-code',
                                    'img.verify-code',
                                    'canvas',
                                    '[class*="captcha"] img',
                                    '[class*="verification"] img',
                                    '[class*="verify"] img'
                                ];
                                
                                let imageElement = null;
                                for (const selector of selectors) {
                                    try {
                                        const element = document.querySelector(selector);
                                        if (element && (element.tagName === 'IMG' || element.tagName === 'CANVAS')) {
                                            const rect = element.getBoundingClientRect();
                                            if (rect.width > 0 && rect.height > 0) {
                                                imageElement = element;
                                                break;
                                            }
                                        }
                                    } catch (e) {
                                        continue;
                                    }
                                }
                                
                                if (!imageElement) {
                                    const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                                    if (input) {
                                        let parent = input.parentElement;
                                        let depth = 0;
                                        while (parent && depth < 5) {
                                            const images = parent.querySelectorAll('img, canvas');
                                            for (const img of images) {
                                                const rect = img.getBoundingClientRect();
                                                if (rect.width > 0 && rect.height > 0 && rect.width < 500 && rect.height < 500) {
                                                    imageElement = img;
                                                    break;
                                                }
                                            }
                                            if (imageElement) break;
                                            parent = parent.parentElement;
                                            depth++;
                                        }
                                    }
                                }
                                
                                if (imageElement) {
                                    return {
                                        found: true,
                                        tagName: imageElement.tagName,
                                        src: imageElement.src || imageElement.getAttribute('src') || '',
                                        alt: imageElement.alt || imageElement.getAttribute('alt') || ''
                                    };
                                }
                                
                                return { found: false };
                            });
                        } else {
                            // 查找驗證碼圖片（舊的 OCR 方法）
                            console.log('🔍 Looking for verification code image...');
                        const verificationImageInfo = await page.evaluate(() => {
                            // 嘗試多種選擇器查找驗證碼圖片
                            const selectors = [
                                'img[alt*="验证码"]',
                                'img[alt*="驗證碼"]',
                                'img[alt*="captcha"]',
                                'img[alt*="code"]',
                                'img[src*="captcha"]',
                                'img[src*="verification"]',
                                'img[src*="code"]',
                                'img[src*="verify"]',
                                'img.captcha',
                                'img.verification-code',
                                'img.verify-code',
                                'canvas', // 有些驗證碼使用 canvas
                                '[class*="captcha"] img',
                                '[class*="verification"] img',
                                '[class*="verify"] img',
                                '[id*="captcha"] img',
                                '[id*="verification"] img',
                                '[id*="verify"] img'
                            ];
                            
                            let imageElement = null;
                            for (const selector of selectors) {
                                try {
                                    const element = document.querySelector(selector);
                                    if (element && (element.tagName === 'IMG' || element.tagName === 'CANVAS')) {
                                        // 檢查元素是否可見
                                        const rect = element.getBoundingClientRect();
                                        if (rect.width > 0 && rect.height > 0) {
                                            imageElement = element;
                                            break;
                                        }
                                    }
                                } catch (e) {
                                    continue;
                                }
                            }
                            
                            // 如果沒找到，嘗試在驗證碼輸入框附近查找圖片
                            if (!imageElement) {
                                const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                                if (input) {
                                    // 查找輸入框的父容器
                                    let parent = input.parentElement;
                                    let depth = 0;
                                    while (parent && depth < 5) {
                                        const images = parent.querySelectorAll('img, canvas');
                                        for (const img of images) {
                                            const rect = img.getBoundingClientRect();
                                            if (rect.width > 0 && rect.height > 0 && rect.width < 500 && rect.height < 500) {
                                                // 可能是驗證碼圖片（尺寸合理）
                                                imageElement = img;
                                                break;
                                            }
                                        }
                                        if (imageElement) break;
                                        parent = parent.parentElement;
                                        depth++;
                                    }
                                }
                            }
                            
                            if (imageElement) {
                                return {
                                    found: true,
                                    tagName: imageElement.tagName,
                                    src: imageElement.src || imageElement.getAttribute('src') || '',
                                    alt: imageElement.alt || imageElement.getAttribute('alt') || '',
                                    className: imageElement.className || '',
                                    id: imageElement.id || ''
                                };
                            }
                            
                            return { found: false };
                        });
                        
                        if (verificationImageInfo.found) {
                            console.log('✅ Verification code image found:', verificationImageInfo.tagName, verificationImageInfo.src || verificationImageInfo.alt || '');
                            
                            // processedPath, processedPath2, processedPath3 已在外部作用域聲明
                            
                            // 嘗試使用 OCR 識別驗證碼
                            if (Tesseract) {
                                try {
                                    console.log('🔍 Attempting to recognize verification code using OCR...');
                                    
                                    // 截取驗證碼圖片
                                    const imageElement = await page.evaluateHandle(() => {
                                        const selectors = [
                                            'img[alt*="验证码"]',
                                            'img[alt*="驗證碼"]',
                                            'img[alt*="captcha"]',
                                            'img[alt*="code"]',
                                            'img[src*="captcha"]',
                                            'img[src*="verification"]',
                                            'img[src*="code"]',
                                            'img[src*="verify"]',
                                            'img.captcha',
                                            'img.verification-code',
                                            'img.verify-code',
                                            'canvas',
                                            '[class*="captcha"] img',
                                            '[class*="verification"] img',
                                            '[class*="verify"] img'
                                        ];
                                        
                                        let imageElement = null;
                                        for (const selector of selectors) {
                                            try {
                                                const element = document.querySelector(selector);
                                                if (element && (element.tagName === 'IMG' || element.tagName === 'CANVAS')) {
                                                    const rect = element.getBoundingClientRect();
                                                    if (rect.width > 0 && rect.height > 0) {
                                                        imageElement = element;
                                                        break;
                                                    }
                                                }
                                            } catch (e) {
                                                continue;
                                            }
                                        }
                                        
                                        // 如果沒找到，嘗試在驗證碼輸入框附近查找
                                        if (!imageElement) {
                                            const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                                            if (input) {
                                                let parent = input.parentElement;
                                                let depth = 0;
                                                while (parent && depth < 5) {
                                                    const images = parent.querySelectorAll('img, canvas');
                                                    for (const img of images) {
                                                        const rect = img.getBoundingClientRect();
                                                        if (rect.width > 0 && rect.height > 0 && rect.width < 500 && rect.height < 500) {
                                                            imageElement = img;
                                                            break;
                                                        }
                                                    }
                                                    if (imageElement) break;
                                                    parent = parent.parentElement;
                                                    depth++;
                                                }
                                            }
                                        }
                                        
                                        return imageElement;
                                    });
                                    
                                    if (imageElement && imageElement.asElement()) {
                                        // 截取驗證碼圖片區域（高分辨率）
                                        const screenshotPath = 'verification_code_image.png';
                                        await imageElement.asElement().screenshot({ 
                                            path: screenshotPath,
                                            type: 'png'
                                        });
                                        console.log('📸 Verification code image saved:', screenshotPath);
                                        
                                        // 嘗試使用 sharp 進行圖像預處理以提高 OCR 識別率
                                        let imageToRecognize = screenshotPath;
                                        // processedPath, processedPath2, processedPath3 已在外部作用域聲明
                                        
                                        try {
                                            const sharp = require('sharp');
                                            console.log('🖼️  Preprocessing image to improve OCR accuracy...');
                                            const fs = require('fs');
                                            
                                            // 檢查原始圖片是否存在
                                            if (!fs.existsSync(screenshotPath)) {
                                                throw new Error('Original image file not found: ' + screenshotPath);
                                            }
                                            
                                            const imageBuffer = fs.readFileSync(screenshotPath);
                                            
                                            // 預處理方式2：中等閾值（平衡干擾和細節）
                                            try {
                                                processedPath2 = 'verification_code_image_processed2.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .median(2) // 添加去噪
                                                    .normalize()
                                                    .linear(1.8, -(128 * 0.3)) // 增強對比度
                                                    .sharpen({ sigma: 2.5, m1: 1, m2: 2, x1: 2, y2: 12, y3: 25 })
                                                    .threshold(130) // 提高閾值
                                                    .toFile(processedPath2);
                                                console.log('   ✅ Method 2: Medium threshold (130) - saved:', processedPath2);
                                            } catch (e2) {
                                                console.log('   ⚠️  Method 2 failed: ' + e2.message);
                                            }
                                            
                                            // 預處理方式3：低閾值+對比度增強（保留更多細節，適合像素化字體）
                                            try {
                                                processedPath3 = 'verification_code_image_processed3.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .linear(2.0, -(128 * 0.5)) // 更強對比度增強
                                                    .normalize()
                                                    .sharpen({ sigma: 1.5, m1: 1, m2: 2 })
                                                    .threshold(80) // 較低閾值，保留像素化細節
                                                    .toFile(processedPath3);
                                                console.log('   ✅ Method 3: Low threshold + contrast (85) - saved:', processedPath3);
                                            } catch (e3) {
                                                console.log('   ⚠️  Method 3 failed: ' + e3.message);
                                            }
                                            
                                            // 預處理方式4：放大圖片 + 高對比度（提高分辨率有助於識別）
                                            try {
                                                processedPath4 = 'verification_code_image_processed4.png';
                                                const metadata = await sharp(imageBuffer).metadata();
                                                const scaleFactor = 3; // 放大3倍
                                                await sharp(imageBuffer)
                                                    .resize(metadata.width * scaleFactor, metadata.height * scaleFactor, {
                                                        kernel: 'lanczos3' // 高質量放大
                                                    })
                                                    .greyscale()
                                                    .normalize()
                                                    .linear(1.5, -(128 * 0.3))
                                                    .sharpen({ sigma: 1.0, m1: 1, m2: 2 })
                                                    .threshold(120)
                                                    .toFile(processedPath4);
                                                console.log('   ✅ Method 4: Upscaled (3x) + high contrast - saved:', processedPath4);
                                            } catch (e4) {
                                                console.log('   ⚠️  Method 4 failed: ' + e4.message);
                                            }
                                            
                                            // 預處理方式5：反色處理（有些驗證碼是白底黑字，有些是黑底白字）
                                            try {
                                                processedPath5 = 'verification_code_image_processed5.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .negate() // 反色
                                                    .normalize()
                                                    .sharpen({ sigma: 2.0, m1: 1, m2: 2 })
                                                    .threshold(130)
                                                    .toFile(processedPath5);
                                                console.log('   ✅ Method 5: Inverted colors - saved:', processedPath5);
                                            } catch (e5) {
                                                console.log('   ⚠️  Method 5 failed: ' + e5.message);
                                            }
                                            
                                            // 預處理方式6：極高對比度 + 去噪
                                            try {
                                                processedPath6 = 'verification_code_image_processed6.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .median(4) // 強去噪
                                                    .linear(3.5, -(128 * 0.7)) // 極高對比度
                                                    .normalize()
                                                    .sharpen({ sigma: 3.5, m1: 1, m2: 3, x1: 4, y2: 20, y3: 40 })
                                                    .threshold(170) // 提高閾值
                                                    .toFile(processedPath6);
                                                console.log('   ✅ Method 6: Extreme contrast + denoise (threshold 170) - saved:', processedPath6);
                                            } catch (e6) {
                                                console.log('   ⚠️  Method 6 failed: ' + e6.message);
                                            }
                                            
                                            // 預處理方式7：更大倍數放大（5倍）+ 去噪（適合小字體驗證碼）
                                            try {
                                                processedPath7 = 'verification_code_image_processed7.png';
                                                const metadata7 = await sharp(imageBuffer).metadata();
                                                const scaleFactor7 = 5; // 放大5倍
                                                await sharp(imageBuffer)
                                                    .resize(metadata7.width * scaleFactor7, metadata7.height * scaleFactor7, {
                                                        kernel: 'lanczos3'
                                                    })
                                                    .greyscale()
                                                    .median(3) // 去噪
                                                    .normalize()
                                                    .linear(1.8, -(128 * 0.4))
                                                    .sharpen({ sigma: 1.5, m1: 1, m2: 2 })
                                                    .threshold(125)
                                                    .toFile(processedPath7);
                                                console.log('   ✅ Method 7: Upscaled (5x) + denoise - saved:', processedPath7);
                                            } catch (e7) {
                                                console.log('   ⚠️  Method 7 failed: ' + e7.message);
                                            }
                                            
                                            // 預處理方式8：自適應閾值（使用 normalize 後再 threshold）
                                            try {
                                                processedPath8 = 'verification_code_image_processed8.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .normalize() // 正規化後再處理
                                                    .median(2) // 輕微去噪
                                                    .linear(2.2, -(128 * 0.5)) // 增強對比度
                                                    .sharpen({ sigma: 2.5, m1: 1, m2: 2 })
                                                    .threshold(100) // 較低閾值，保留更多細節
                                                    .toFile(processedPath8);
                                                console.log('   ✅ Method 8: Adaptive threshold - saved:', processedPath8);
                                            } catch (e8) {
                                                console.log('   ⚠️  Method 8 failed: ' + e8.message);
                                            }
                                            
                                            // 預處理方式9：雙重處理（先放大再增強）
                                            try {
                                                processedPath9 = 'verification_code_image_processed9.png';
                                                const metadata9 = await sharp(imageBuffer).metadata();
                                                const scaleFactor9 = 4; // 放大4倍
                                                await sharp(imageBuffer)
                                                    .resize(metadata9.width * scaleFactor9, metadata9.height * scaleFactor9, {
                                                        kernel: 'lanczos3'
                                                    })
                                                    .greyscale()
                                                    .normalize()
                                                    .linear(2.5, -(128 * 0.5))
                                                    .median(2)
                                                    .sharpen({ sigma: 2.0, m1: 1, m2: 2 })
                                                    .threshold(110)
                                                    .toFile(processedPath9);
                                                console.log('   ✅ Method 9: Upscaled (4x) + enhanced - saved:', processedPath9);
                                            } catch (e9) {
                                                console.log('   ⚠️  Method 9 failed: ' + e9.message);
                                            }
                                            
                                            // 預處理方式10：極大倍數放大（8倍）+ 強去噪（適合非常小的驗證碼）
                                            try {
                                                processedPath10 = 'verification_code_image_processed10.png';
                                                const metadata10 = await sharp(imageBuffer).metadata();
                                                const scaleFactor10 = 8; // 放大8倍
                                                await sharp(imageBuffer)
                                                    .resize(metadata10.width * scaleFactor10, metadata10.height * scaleFactor10, {
                                                        kernel: 'lanczos3'
                                                    })
                                                    .greyscale()
                                                    .median(5) // 強去噪
                                                    .normalize()
                                                    .linear(2.0, -(128 * 0.4))
                                                    .sharpen({ sigma: 2.0, m1: 1, m2: 2 })
                                                    .threshold(120)
                                                    .toFile(processedPath10);
                                                console.log('   ✅ Method 10: Upscaled (8x) + strong denoise - saved:', processedPath10);
                                            } catch (e10) {
                                                console.log('   ⚠️  Method 10 failed: ' + e10.message);
                                            }
                                            
                                            // 預處理方式11：極高閾值 + 強對比度（去除所有干擾）
                                            try {
                                                processedPath11 = 'verification_code_image_processed11.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .median(5) // 強去噪
                                                    .linear(4.0, -(128 * 0.7)) // 極強對比度
                                                    .normalize()
                                                    .sharpen({ sigma: 3.5, m1: 1, m2: 3, x1: 3, y2: 20, y3: 40 })
                                                    .threshold(180) // 極高閾值，只保留最深的像素
                                                    .toFile(processedPath11);
                                                console.log('   ✅ Method 11: Extreme threshold (180) + strong contrast - saved:', processedPath11);
                                            } catch (e11) {
                                                console.log('   ⚠️  Method 11 failed: ' + e11.message);
                                            }
                                            
                                            // 預處理方式12：低閾值 + 強銳化（保留所有細節）
                                            try {
                                                processedPath12 = 'verification_code_image_processed12.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .normalize()
                                                    .linear(3.0, -(128 * 0.6)) // 強對比度
                                                    .sharpen({ sigma: 4.0, m1: 1, m2: 3, x1: 4, y2: 25, y3: 50 }) // 極強銳化
                                                    .median(1) // 輕微去噪
                                                    .threshold(70) // 低閾值，保留更多細節
                                                    .toFile(processedPath12);
                                                console.log('   ✅ Method 12: Low threshold (70) + extreme sharpen - saved:', processedPath12);
                                            } catch (e12) {
                                                console.log('   ⚠️  Method 12 failed: ' + e12.message);
                                            }
                                            
                                            // 預處理方式13：專門去除中間干擾線 - 極高閾值 + 多次去噪
                                            try {
                                                processedPath13 = 'verification_code_image_processed13.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .median(7) // 極強去噪，去除干擾線
                                                    .median(7) // 二次去噪，確保去除線條
                                                    .linear(2.5, -(128 * 0.5)) // 增強對比度
                                                    .normalize()
                                                    .sharpen({ sigma: 2.0, m1: 1, m2: 2 })
                                                    .threshold(220) // 極高閾值，過濾掉淺色干擾線
                                                    .toFile(processedPath13);
                                                console.log('   ✅ Method 13: Remove interference line (threshold 220, double median) - saved:', processedPath13);
                                            } catch (e13) {
                                                console.log('   ⚠️  Method 13 failed: ' + e13.message);
                                            }
                                            
                                            // 預處理方式14：專門去除中間干擾線 - 放大後處理
                                            try {
                                                processedPath14 = 'verification_code_image_processed14.png';
                                                const metadata14 = await sharp(imageBuffer).metadata();
                                                const scaleFactor14 = 6; // 放大6倍
                                                await sharp(imageBuffer)
                                                    .resize(metadata14.width * scaleFactor14, metadata14.height * scaleFactor14, {
                                                        kernel: 'lanczos3'
                                                    })
                                                    .greyscale()
                                                    .median(9) // 極強去噪，去除放大後的干擾線
                                                    .normalize()
                                                    .linear(2.2, -(128 * 0.4))
                                                    .sharpen({ sigma: 1.5, m1: 1, m2: 2 })
                                                    .threshold(200) // 高閾值過濾干擾線
                                                    .toFile(processedPath14);
                                                console.log('   ✅ Method 14: Remove interference line (upscaled 6x, threshold 200) - saved:', processedPath14);
                                            } catch (e14) {
                                                console.log('   ⚠️  Method 14 failed: ' + e14.message);
                                            }
                                            
                                            // 預處理方式15：專門去除中間干擾線 - 極強對比度 + 極高閾值
                                            try {
                                                processedPath15 = 'verification_code_image_processed15.png';
                                                await sharp(imageBuffer)
                                                    .greyscale()
                                                    .median(9) // 極強去噪
                                                    .linear(5.0, -(128 * 0.8)) // 極強對比度，拉開數字與干擾線的差距
                                                    .normalize()
                                                    .sharpen({ sigma: 3.0, m1: 1, m2: 3, x1: 3, y2: 20, y3: 40 })
                                                    .threshold(240) // 極高閾值，只保留最深的數字
                                                    .toFile(processedPath15);
                                                console.log('   ✅ Method 15: Remove interference line (threshold 240, extreme contrast) - saved:', processedPath15);
                                            } catch (e15) {
                                                console.log('   ⚠️  Method 15 failed: ' + e15.message);
                                            }
                                            
                                            // 優先使用處理後的圖片
                                            if (processedPath && fs.existsSync(processedPath)) {
                                                imageToRecognize = processedPath;
                                            }
                                        } catch (sharpError) {
                                            console.log('   ⚠️  Sharp not available, using original image');
                                            console.log('   💡 To improve OCR accuracy, install: npm install sharp');
                                        }
                                        
                                        // 使用 Tesseract OCR 識別（針對純數字驗證碼優化，多次嘗試）
                                        console.log('🔍 Recognizing digits from image (multiple attempts with preprocessing)...');
                                        
                                        // 驗證圖片文件是否存在
                                        const fs = require('fs');
                                        if (!fs.existsSync(screenshotPath)) {
                                            throw new Error('Verification code image file not found: ' + screenshotPath);
                                        }
                                        console.log('   ✅ Original image exists: ' + screenshotPath);
                                        if (processedPath && fs.existsSync(processedPath)) {
                                            console.log('   ✅ Processed image 1 exists: ' + processedPath);
                                        }
                                        if (processedPath2 && fs.existsSync(processedPath2)) {
                                            console.log('   ✅ Processed image 2 exists: ' + processedPath2);
                                        }
                                        if (processedPath3 && fs.existsSync(processedPath3)) {
                                            console.log('   ✅ Processed image 3 exists: ' + processedPath3);
                                        }
                                        if (processedPath4 && fs.existsSync(processedPath4)) {
                                            console.log('   ✅ Processed image 4 exists: ' + processedPath4);
                                        }
                                        if (processedPath5 && fs.existsSync(processedPath5)) {
                                            console.log('   ✅ Processed image 5 exists: ' + processedPath5);
                                        }
                                        if (processedPath6 && fs.existsSync(processedPath6)) {
                                            console.log('   ✅ Processed image 6 exists: ' + processedPath6);
                                        }
                                        if (processedPath7 && fs.existsSync(processedPath7)) {
                                            console.log('   ✅ Processed image 7 exists: ' + processedPath7);
                                        }
                                        if (processedPath8 && fs.existsSync(processedPath8)) {
                                            console.log('   ✅ Processed image 8 exists: ' + processedPath8);
                                        }
                                        if (processedPath9 && fs.existsSync(processedPath9)) {
                                            console.log('   ✅ Processed image 9 exists: ' + processedPath9);
                                        }
                                        if (processedPath10 && fs.existsSync(processedPath10)) {
                                            console.log('   ✅ Processed image 10 exists: ' + processedPath10);
                                        }
                                        if (processedPath11 && fs.existsSync(processedPath11)) {
                                            console.log('   ✅ Processed image 11 exists: ' + processedPath11);
                                        }
                                        if (processedPath12 && fs.existsSync(processedPath12)) {
                                            console.log('   ✅ Processed image 12 exists: ' + processedPath12);
                                        }
                                        if (processedPath13 && fs.existsSync(processedPath13)) {
                                            console.log('   ✅ Processed image 13 exists: ' + processedPath13);
                                        }
                                        if (processedPath14 && fs.existsSync(processedPath14)) {
                                            console.log('   ✅ Processed image 14 exists: ' + processedPath14);
                                        }
                                        if (processedPath15 && fs.existsSync(processedPath15)) {
                                            console.log('   ✅ Processed image 15 exists: ' + processedPath15);
                                        }
                                        console.log('');
                                        
                                        // 嘗試多種配置以提高識別率（使用處理後的圖片）
                                        // 注意：驗證碼只包含數字，所有配置都只允許數字
                                        const ocrAttempts = [
                                            {
                                                name: 'Attempt 1: Processed image, digits only, single word',
                                                image: imageToRecognize,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8', // 單詞模式
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 2: Processed image, digits only, single line',
                                                image: imageToRecognize,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7', // 單行文本
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 3: Original image, digits only, single word',
                                                image: screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 4: Processed image, digits only, extract digits only',
                                                image: imageToRecognize,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 5: Original image, digits only, extract digits only',
                                                image: screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 6: Processed image, digits only, sparse text',
                                                image: imageToRecognize,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '11', // 稀疏文本
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 7: Processed image 2 (medium threshold), digits only',
                                                image: processedPath2 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 8: Processed image 3 (low threshold), digits only',
                                                image: processedPath3 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 9: Original image, digits only, auto page seg',
                                                image: screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '6', // 單一統一文本塊
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 10: Processed image 2, digits only, extract digits only',
                                                image: processedPath2 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 11: Processed image 3, digits only, extract digits only',
                                                image: processedPath3 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 12: Processed image 4 (upscaled), digits only',
                                                image: processedPath4 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 13: Processed image 5 (inverted), digits only',
                                                image: processedPath5 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 14: Processed image 6 (extreme contrast), digits only',
                                                image: processedPath6 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 15: Processed image 4 (upscaled), digits only',
                                                image: processedPath4 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 16: Processed image 5 (inverted), digits only',
                                                image: processedPath5 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 17: Processed image 6 (extreme contrast), digits only',
                                                image: processedPath6 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 18: Original image, digits only, traditional engine',
                                                image: screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1' // 傳統引擎
                                                }
                                            },
                                            {
                                                name: 'Attempt 19: Processed image 1, digits only, traditional engine',
                                                image: processedPath || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 20: Processed image 4 (upscaled), digits only, traditional engine',
                                                image: processedPath4 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 21: Digits only, Raw Line',
                                                image: processedPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '1',
                                                }
                                            },
                                            {
                                                name: 'Attempt 22: Processed image 7 (upscaled 5x), digits only',
                                                image: processedPath7 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 23: Processed image 7 (upscaled 5x), digits only, single line',
                                                image: processedPath7 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 24: Processed image 8 (adaptive threshold), digits only',
                                                image: processedPath8 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 25: Processed image 8 (adaptive threshold), digits only, single line',
                                                image: processedPath8 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 26: Processed image 9 (upscaled 4x enhanced), digits only',
                                                image: processedPath9 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 27: Processed image 9 (upscaled 4x enhanced), digits only, single line',
                                                image: processedPath9 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 28: Processed image 7 (upscaled 5x), digits only, traditional engine',
                                                image: processedPath7 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 29: Processed image 8 (adaptive threshold), digits only, traditional engine',
                                                image: processedPath8 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 30: Processed image 9 (upscaled 4x enhanced), digits only, traditional engine',
                                                image: processedPath9 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 31: Processed image 10 (upscaled 8x), digits only',
                                                image: processedPath10 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 32: Processed image 10 (upscaled 8x), digits only, single line',
                                                image: processedPath10 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 33: Processed image 11 (extreme threshold 180), digits only',
                                                image: processedPath11 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 34: Processed image 11 (extreme threshold 180), digits only, single line',
                                                image: processedPath11 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 35: Processed image 12 (low threshold 70 + extreme sharpen), digits only',
                                                image: processedPath12 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 36: Processed image 12 (low threshold 70 + extreme sharpen), digits only, single line',
                                                image: processedPath12 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 37: Processed image 10 (upscaled 8x), digits only, traditional engine',
                                                image: processedPath10 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 38: Processed image 11 (extreme threshold 180), digits only, traditional engine',
                                                image: processedPath11 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 39: Processed image 12 (low threshold 70), digits only, traditional engine',
                                                image: processedPath12 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 40: Processed image 13 (remove interference line, threshold 220), digits only',
                                                image: processedPath13 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 41: Processed image 13 (remove interference line), digits only, single line',
                                                image: processedPath13 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 42: Processed image 14 (remove interference line, upscaled 6x), digits only',
                                                image: processedPath14 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 43: Processed image 14 (remove interference line, upscaled 6x), digits only, single line',
                                                image: processedPath14 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 44: Processed image 15 (remove interference line, threshold 240), digits only',
                                                image: processedPath15 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 45: Processed image 15 (remove interference line, threshold 240), digits only, single line',
                                                image: processedPath15 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '7',
                                                    tessedit_ocr_engine_mode: '3'
                                                }
                                            },
                                            {
                                                name: 'Attempt 46: Processed image 13 (remove interference line), digits only, traditional engine',
                                                image: processedPath13 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 47: Processed image 14 (remove interference line, upscaled 6x), digits only, traditional engine',
                                                image: processedPath14 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            },
                                            {
                                                name: 'Attempt 48: Processed image 15 (remove interference line, threshold 240), digits only, traditional engine',
                                                image: processedPath15 || screenshotPath,
                                                config: {
                                                    tessedit_char_whitelist: '0123456789',
                                                    tessedit_pageseg_mode: '8',
                                                    tessedit_ocr_engine_mode: '1'
                                                }
                                            }
                                        ];
                                        
                                        let bestResult = null;
                                        let bestScore = 0;
                                        let allResults = []; // 收集所有識別結果用於投票
                                        
                                        console.log('🔍 Starting OCR recognition with ' + ocrAttempts.length + ' attempts...');
                                        console.log('');
                                        
                                        for (const attempt of ocrAttempts) {
                                            try {
                                                console.log('   ' + attempt.name + '...');
                                                const startTime = Date.now();
                                                const { data: { text } } = await Tesseract.recognize(
                                                    attempt.image || screenshotPath,
                                                    'eng',
                                                    {
                                                        logger: m => {
                                                            // 顯示 OCR 進度（只在關鍵階段）
                                                            if (m.status === 'recognizing text' && m.progress > 0.95) {
                                                                // 接近完成
                                                            }
                                                        },
                                                        ...attempt.config
                                                    }
                                                );
                                                const duration = Date.now() - startTime;
                                                
                                                // 清理識別結果：驗證碼只包含數字，只保留數字
                                                // 移除空格、換行等無關字符（使用 \\s 來匹配所有空白字符）
                                                const cleanedFull = text.replace(/\\s+/g, '').trim();
                                                // 只保留數字（驗證碼只包含數字，如果包含字母則為識別錯誤）
                                                const cleanedDigits = cleanedFull.replace(/[^0-9]/g, '').trim();
                                                
                                                const originalText = text.trim();
                                                
                                                console.log('      ⏱️  Duration: ' + duration + 'ms');
                                                console.log('      📝 Raw text: "' + originalText + '"');
                                                
                                                // 檢查原始文本是否包含字母（如果包含，表示識別錯誤）
                                                const hasLetters = /[A-Za-z]/.test(originalText);
                                                if (hasLetters) {
                                                    console.log('      ⚠️  Warning: Raw text contains letters, this indicates OCR error (verification code should only contain digits)');
                                                }
                                                
                                                // 只使用純數字結果
                                                let cleaned = null;
                                                
                                                if (cleanedDigits && cleanedDigits.length > 0) {
                                                    cleaned = cleanedDigits;
                                                    
                                                    // 如果原始文本包含字母，降低分數（表示識別可能有誤）
                                                    let score = cleaned.length;
                                                    if (cleaned.length >= 4 && cleaned.length <= 6) {
                                                        score += 10; // 長度合理的額外加分
                                                    }
                                                    // 如果原始文本中有效字符比例高，額外加分
                                                    const validRatio = cleaned.length / (originalText.length || 1);
                                                    if (validRatio > 0.8) {
                                                        score += 5; // 有效字符比例高的額外加分
                                                    }
                                                    // 如果原始文本包含字母，降低分數（識別錯誤的懲罰）
                                                    if (hasLetters) {
                                                        score -= 5; // 包含字母的懲罰分數
                                                        console.log('      ⚠️  Result contains letters in raw text, score reduced: "' + cleaned + '"');
                                                    }
                                                    
                                                    console.log('      ✅ Result: "' + cleaned + '" (digits only, length: ' + cleaned.length + ', score: ' + score + ', valid ratio: ' + (validRatio * 100).toFixed(1) + '%)');
                                                    
                                                    // 收集所有結果用於投票（只收集長度合理的純數字結果）
                                                    if (cleaned.length >= 3 && cleaned.length <= 7) {
                                                        allResults.push({
                                                            code: cleaned,
                                                            score: score,
                                                            length: cleaned.length,
                                                            originalText: originalText,
                                                            hasLetters: hasLetters
                                                        });
                                                    }
                                                    
                                                    if (score > bestScore) {
                                                        bestScore = score;
                                                        bestResult = cleaned;
                                                    }
                                                    
                                                    // 如果結果看起來很合理（4-6位），繼續收集更多結果用於投票
                                                    if (cleaned.length >= 4 && cleaned.length <= 6) {
                                                        console.log('   ✅ Found reasonable result, continuing to collect more for voting...');
                                                        // 不立即結束，繼續收集更多結果
                                                    }
                                                } else {
                                                    console.log('      ⚠️  No valid digits found in result: "' + originalText + '"');
                                                    // 如果原始文本包含字母但沒有數字，明確標記為識別錯誤
                                                    if (hasLetters) {
                                                        console.log('      ❌ OCR error: Text contains letters but no digits - verification code should only contain digits');
                                                    }
                                                    // 即使沒有有效字符，也記錄原始文本，可能包含有用的信息
                                                    if (originalText && originalText.length > 0) {
                                                        console.log('      💡 Original text: "' + originalText + '"');
                                                    }
                                                }
                                            } catch (attemptError) {
                                                console.log('      ❌ Failed: ' + attemptError.message);
                                                // 顯示更詳細的錯誤信息
                                                if (attemptError.message.includes('ENOENT') || attemptError.message.includes('not found')) {
                                                    console.log('      💡 Image file may not exist: ' + (attempt.image || 'unknown'));
                                                    const fs = require('fs');
                                                    const path = require('path');
                                                    const imagePath = attempt.image || screenshotPath;
                                                    const fullPath = path.resolve(imagePath);
                                                    console.log('      💡 Full path: ' + fullPath);
                                                    console.log('      💡 File exists: ' + fs.existsSync(fullPath));
                                                } else if (attemptError.message.includes('Tesseract')) {
                                                    console.log('      💡 Tesseract.js error - check if tesseract.js is properly installed');
                                                }
                                                continue;
                                            }
                                        }
                                        
                                        // 顯示所有收集到的結果摘要
                                        if (allResults.length > 0) {
                                            console.log('');
                                            console.log('📊 Summary of all OCR results:');
                                            const summary = {};
                                            allResults.forEach(r => {
                                                if (!summary[r.code]) {
                                                    summary[r.code] = { count: 0, scores: [] };
                                                }
                                                summary[r.code].count++;
                                                summary[r.code].scores.push(r.score);
                                            });
                                            for (const [code, data] of Object.entries(summary)) {
                                                const avgScore = data.scores.reduce((a, b) => a + b, 0) / data.scores.length;
                                                console.log('   "' + code + '": ' + data.count + ' time(s), avg score: ' + avgScore.toFixed(2));
                                            }
                                            console.log('');
                                        }
                                        
                                        if (bestResult) {
                                            // 使用投票機制選擇最佳結果（如果有多個結果）
                                            if (allResults.length > 1) {
                                                console.log('');
                                                console.log('🗳️  Using voting mechanism to select best result from ' + allResults.length + ' candidates...');
                                                
                                                // 統計每個結果的出現次數和總分
                                                // 注意：只考慮純數字結果，如果結果包含字母則為識別錯誤
                                                const resultVotes = {};
                                                allResults.forEach(r => {
                                                    // 驗證結果只包含數字（安全檢查）
                                                    if (!/^[0-9]+$/.test(r.code)) {
                                                        console.log('   ⚠️  Skipping invalid result (contains non-digits): "' + r.code + '"');
                                                        return; // 跳過包含非數字的結果
                                                    }
                                                    
                                                    if (!resultVotes[r.code]) {
                                                        resultVotes[r.code] = { count: 0, totalScore: 0, length: r.length, hasLetters: r.hasLetters || false };
                                                    }
                                                    resultVotes[r.code].count++;
                                                    resultVotes[r.code].totalScore += r.score;
                                                    // 如果原始文本包含字母，標記為有字母
                                                    if (r.hasLetters) {
                                                        resultVotes[r.code].hasLetters = true;
                                                    }
                                                });
                                                
                                                // 找出投票最多的結果
                                                let votedResult = null;
                                                let maxVotes = 0;
                                                let maxVoteScore = 0;
                                                
                                                for (const [code, vote] of Object.entries(resultVotes)) {
                                                    // 投票分數 = 出現次數 * 20 + 總分 + 長度合理性加分
                                                    let voteScore = vote.count * 20 + vote.totalScore;
                                                    if (vote.length >= 4 && vote.length <= 6) {
                                                        voteScore += 15; // 長度合理的額外加分
                                                    }
                                                    // 如果原始文本包含字母，降低分數（識別錯誤的懲罰）
                                                    if (vote.hasLetters) {
                                                        voteScore -= 10; // 包含字母的懲罰分數
                                                    }
                                                    
                                                    const warning = vote.hasLetters ? ' (⚠️ contains letters in raw text)' : '';
                                                    console.log('   Code "' + code + '": ' + vote.count + ' votes, total score: ' + vote.totalScore + ', vote score: ' + voteScore + warning);
                                                    
                                                    if (voteScore > maxVoteScore || (voteScore === maxVoteScore && vote.count > maxVotes)) {
                                                        maxVoteScore = voteScore;
                                                        maxVotes = vote.count;
                                                        votedResult = code;
                                                    }
                                                }
                                                
                                                if (votedResult) {
                                                    if (votedResult !== bestResult) {
                                                        console.log('   ✅ Voting selected: "' + votedResult + '" (appeared ' + resultVotes[votedResult].count + ' times) over "' + bestResult + '"');
                                                        recognizedCode = votedResult;
                                                    } else {
                                                        console.log('   ✅ Voting confirmed: "' + votedResult + '" (appeared ' + resultVotes[votedResult].count + ' times)');
                                                        recognizedCode = bestResult;
                                                    }
                                                } else {
                                                    recognizedCode = bestResult;
                                                }
                                            } else {
                                                recognizedCode = bestResult;
                                            }
                                            
                                            // 最終驗證：確保結果只包含數字（如果包含字母則為識別錯誤）
                                            if (recognizedCode) {
                                                // 移除所有非數字字符
                                                const finalCleaned = recognizedCode.replace(/[^0-9]/g, '');
                                                if (finalCleaned !== recognizedCode) {
                                                    console.log('⚠️  Warning: Final result contained non-digit characters, cleaned: "' + recognizedCode + '" -> "' + finalCleaned + '"');
                                                    recognizedCode = finalCleaned;
                                                }
                                                
                                                // 驗證最終結果只包含數字
                                                if (!/^[0-9]+$/.test(recognizedCode)) {
                                                    console.log('❌ Error: Final result still contains non-digit characters: "' + recognizedCode + '"');
                                                    recognizedCode = null; // 拒絕包含非數字的結果
                                                }
                                            }
                                            
                                            if (recognizedCode) {
                                                console.log('✅ Final OCR result: "' + recognizedCode + '" (length: ' + recognizedCode.length + ' digits, digits only)');
                                            } else {
                                                console.log('❌ Final OCR result validation failed: result contains non-digit characters');
                                            }
                                            
                                            // 如果結果長度不在理想範圍（4-6位），嘗試進一步驗證和優化
                                            if (recognizedCode.length < 4 || recognizedCode.length > 6) {
                                                console.log('⚠️  Result length is not ideal (' + recognizedCode.length + ' digits), but will try to use it');
                                                console.log('   Trying additional verification with different images...');
                                                
                                                // 嘗試使用其他處理後的圖片再次識別
                                                const additionalImages = [processedPath2, processedPath3, processedPath4, processedPath5, processedPath6, processedPath7, processedPath8, processedPath9, processedPath10, processedPath11, processedPath12, processedPath13, processedPath14, processedPath15, screenshotPath].filter(Boolean);
                                                for (const altImage of additionalImages) {
                                                    if (altImage === imageToRecognize) continue; // 跳過已經用過的
                                                    
                                                    try {
                                                        console.log('   Trying alternative image: ' + altImage);
                                                        const { data: { text: altText } } = await Tesseract.recognize(
                                                            altImage,
                                                            'eng',
                                                            {
                                                                tessedit_char_whitelist: '0123456789',
                                                                tessedit_pageseg_mode: '8',
                                                                tessedit_ocr_engine_mode: '3'
                                                            }
                                                        );
                                                        // 只提取純數字（驗證碼只包含數字）
                                                        const altCodeDigits = altText.replace(/[^0-9]/g, '').trim();
                                                        const altCode = altCodeDigits;
                                                        
                                                        // 檢查原始文本是否包含字母（識別錯誤的標誌）
                                                        const altHasLetters = /[A-Za-z]/.test(altText);
                                                        if (altHasLetters) {
                                                            console.log('   ⚠️  Warning: Alternative result contains letters in raw text (OCR error)');
                                                        }
                                                        
                                                        console.log('   Alternative result: "' + altCode + '" (length: ' + altCode.length + ', original: "' + altText.trim() + '")');
                                                        if (altCode && altCode.length >= 4 && altCode.length <= 6) {
                                                            console.log('   ✅ Found better result from alternative image: "' + altCode + '"');
                                                            recognizedCode = altCode;
                                                            break;
                                                        } else if (altCode && altCode.length >= 3 && altCode.length <= 7 && (!recognizedCode || recognizedCode.length < 3)) {
                                                            // 如果沒有更好的結果，使用這個（但會警告如果包含字母）
                                                            if (altHasLetters) {
                                                                console.log('   ⚠️  Using alternative result with warning (contains letters): "' + altCode + '"');
                                                            } else {
                                                                console.log('   ⚠️  Using alternative result: "' + altCode + '"');
                                                            }
                                                            recognizedCode = altCode;
                                                        }
                                                    } catch (e) {
                                                        console.log('   ❌ Alternative image failed: ' + e.message);
                                                        continue;
                                                    }
                                                }
                                                
                                                // 即使長度不理想，也使用識別出的結果
                                                if (recognizedCode && recognizedCode.length > 0) {
                                                    console.log('   ✅ Will use recognized code despite non-ideal length: "' + recognizedCode + '"');
                                                }
                                            }
                                        } else {
                                            console.log('');
                                            console.log('⚠️  All ' + ocrAttempts.length + ' OCR attempts failed to recognize digits');
                                            console.log('   📊 Summary:');
                                            console.log('      - Total attempts: ' + ocrAttempts.length);
                                            console.log('      - Results collected: ' + allResults.length);
                                            if (allResults.length > 0) {
                                                console.log('      - Best score: ' + bestScore);
                                                console.log('      - Best result: ' + (bestResult || 'None'));
                                            }
                                            console.log('');
                                            console.log('   📸 Images saved:');
                                            console.log('      - Original: ' + screenshotPath);
                                            if (processedPath) {
                                                console.log('      - Processed 1: ' + processedPath);
                                            }
                                            if (processedPath2) {
                                                console.log('      - Processed 2: ' + processedPath2);
                                            }
                                            if (processedPath3) {
                                                console.log('      - Processed 3: ' + processedPath3);
                                            }
                                            if (processedPath4) {
                                                console.log('      - Processed 4: ' + processedPath4);
                                            }
                                            if (processedPath5) {
                                                console.log('      - Processed 5: ' + processedPath5);
                                            }
                                            if (processedPath6) {
                                                console.log('      - Processed 6: ' + processedPath6);
                                            }
                                            if (processedPath7) {
                                                console.log('      - Processed 7: ' + processedPath7);
                                            }
                                            if (processedPath8) {
                                                console.log('      - Processed 8: ' + processedPath8);
                                            }
                                            if (processedPath9) {
                                                console.log('      - Processed 9: ' + processedPath9);
                                            }
                                            if (processedPath10) {
                                                console.log('      - Processed 10: ' + processedPath10);
                                            }
                                            if (processedPath11) {
                                                console.log('      - Processed 11: ' + processedPath11);
                                            }
                                            if (processedPath12) {
                                                console.log('      - Processed 12: ' + processedPath12);
                                            }
                                            if (processedPath13) {
                                                console.log('      - Processed 13: ' + processedPath13);
                                            }
                                            if (processedPath14) {
                                                console.log('      - Processed 14: ' + processedPath14);
                                            }
                                            if (processedPath15) {
                                                console.log('      - Processed 15: ' + processedPath15);
                                            }
                                            console.log('');
                                            console.log('💡 Trying final attempt with different approach...');
                                            
                                            // 最後幾次嘗試：使用不同的配置和圖片
                                            const finalAttempts = [
                                                {
                                                    name: 'Final 1: Processed image 2, traditional OCR engine',
                                                    image: processedPath2 || imageToRecognize,
                                                    config: {
                                                        tessedit_char_whitelist: '0123456789',
                                                        tessedit_pageseg_mode: '13',
                                                        tessedit_ocr_engine_mode: '1' // 傳統 OCR 引擎
                                                    }
                                                },
                                                {
                                                    name: 'Final 2: Processed image 3, traditional OCR engine',
                                                    image: processedPath3 || imageToRecognize,
                                                    config: {
                                                        tessedit_char_whitelist: '0123456789',
                                                        tessedit_pageseg_mode: '13',
                                                        tessedit_ocr_engine_mode: '1'
                                                    }
                                                },
                                                {
                                                    name: 'Final 3: Original image, traditional OCR engine',
                                                    image: screenshotPath,
                                                    config: {
                                                        tessedit_char_whitelist: '0123456789',
                                                        tessedit_pageseg_mode: '13',
                                                        tessedit_ocr_engine_mode: '1'
                                                    }
                                                }
                                            ];
                                            
                                            for (const finalAttempt of finalAttempts) {
                                                try {
                                                    console.log('   ' + finalAttempt.name + '...');
                                                    const { data: { text: finalText } } = await Tesseract.recognize(
                                                        finalAttempt.image,
                                                        'eng',
                                                        finalAttempt.config
                                                    );
                                                    // 只提取純數字（驗證碼只包含數字）
                                                    const finalCodeDigits = finalText.replace(/[^0-9]/g, '').trim();
                                                    const finalCode = finalCodeDigits;
                                                    
                                                    // 檢查原始文本是否包含字母（識別錯誤的標誌）
                                                    const finalHasLetters = /[A-Za-z]/.test(finalText);
                                                    if (finalHasLetters) {
                                                        console.log('   ⚠️  Warning: Final attempt result contains letters in raw text (OCR error)');
                                                    }
                                                    
                                                    console.log('   Result: "' + finalCode + '" (length: ' + finalCode.length + ', original: "' + finalText.trim() + '")');
                                                    
                                                    if (finalCode && finalCode.length > 0) {
                                                        // 如果之前沒有結果，或者這個結果更好，使用它
                                                        if (!recognizedCode) {
                                                            recognizedCode = finalCode;
                                                            if (finalHasLetters) {
                                                                console.log('   ⚠️  Using result with warning (contains letters): "' + recognizedCode + '"');
                                                            } else {
                                                                console.log('   ✅ Using result: "' + recognizedCode + '"');
                                                            }
                                                        } else if (finalCode.length >= 4 && finalCode.length <= 6) {
                                                            // 如果這個結果長度更理想，使用它
                                                            recognizedCode = finalCode;
                                                            if (finalHasLetters) {
                                                                console.log('   ⚠️  Found better result with warning (contains letters): "' + recognizedCode + '"');
                                                            } else {
                                                                console.log('   ✅ Found better result: "' + recognizedCode + '"');
                                                            }
                                                            break;
                                                        } else if (finalCode.length >= 3 && finalCode.length <= 7 && recognizedCode.length < 3) {
                                                            // 如果之前的結果太短，使用這個
                                                            recognizedCode = finalCode;
                                                            if (finalHasLetters) {
                                                                console.log('   ⚠️  Using better result with warning (contains letters): "' + recognizedCode + '"');
                                                            } else {
                                                                console.log('   ✅ Using better result: "' + recognizedCode + '"');
                                                            }
                                                        }
                                                    } else {
                                                        console.log('   ⚠️  No digits found in result');
                                                        if (finalHasLetters) {
                                                            console.log('   ❌ OCR error: Text contains letters but no digits - verification code should only contain digits');
                                                        }
                                                    }
                                                } catch (finalError) {
                                                    console.log('   Failed: ' + finalError.message);
                                                    continue;
                                                }
                                            }
                                            
                                            if (!recognizedCode) {
                                                console.log('');
                                                console.log('❌ OCR recognition completely failed after all attempts');
                                                console.log('📊 Total attempts made: ' + ocrAttempts.length + ' + ' + finalAttempts.length + ' final attempts');
                                                console.log('📊 Total results collected: ' + allResults.length);
                                                
                                                if (allResults.length > 0) {
                                                    console.log('');
                                                    console.log('📋 All collected results (even if not ideal):');
                                                    allResults.forEach((r, idx) => {
                                                        console.log('   ' + (idx + 1) + '. "' + r.code + '" (length: ' + r.length + ', score: ' + r.score + ')');
                                                    });
                                                    console.log('');
                                                    console.log('💡 Trying to use the most frequent result even if not ideal...');
                                                    
                                                    // 統計最頻繁的結果
                                                    const freqMap = {};
                                                    allResults.forEach(r => {
                                                        freqMap[r.code] = (freqMap[r.code] || 0) + 1;
                                                    });
                                                    
                                                    let mostFrequent = null;
                                                    let maxFreq = 0;
                                                    for (const [code, freq] of Object.entries(freqMap)) {
                                                        if (freq > maxFreq) {
                                                            maxFreq = freq;
                                                            mostFrequent = code;
                                                        }
                                                    }
                                                    
                                                    if (mostFrequent) {
                                                        recognizedCode = mostFrequent;
                                                        console.log('✅ Using most frequent result: "' + recognizedCode + '" (appeared ' + maxFreq + ' times)');
                                                    } else {
                                                        // 如果沒有最頻繁的結果，使用第一個結果（即使不理想）
                                                        if (allResults.length > 0) {
                                                            recognizedCode = allResults[0].code;
                                                            console.log('⚠️  Using first available result (may not be ideal): "' + recognizedCode + '"');
                                                        }
                                                    }
                                                }
                                                
                                                if (!recognizedCode) {
                                                    console.log('');
                                                    console.log('❌ No valid result found after all attempts');
                                                    console.log('');
                                                    console.log('📊 Attempt Summary:');
                                                    console.log('   - Main attempts: ' + ocrAttempts.length);
                                                    console.log('   - Final attempts: ' + finalAttempts.length);
                                                    console.log('   - Total results collected: ' + allResults.length);
                                                    console.log('');
                                                    console.log('📸 Please check the saved images:');
                                                    console.log('   - Original: ' + screenshotPath);
                                                    if (processedPath) {
                                                        console.log('   - Processed (method 1): ' + processedPath);
                                                    }
                                                    if (processedPath2) {
                                                        console.log('   - Processed (method 2): ' + processedPath2);
                                                    }
                                                    if (processedPath3) {
                                                        console.log('   - Processed (method 3): ' + processedPath3);
                                                    }
                                                    if (processedPath4) {
                                                        console.log('   - Processed (method 4): ' + processedPath4);
                                                    }
                                                    if (processedPath5) {
                                                        console.log('   - Processed (method 5): ' + processedPath5);
                                                    }
                                                    if (processedPath6) {
                                                        console.log('   - Processed (method 6): ' + processedPath6);
                                                    }
                                                    if (processedPath7) {
                                                        console.log('   - Processed (method 7): ' + processedPath7);
                                                    }
                                                    if (processedPath8) {
                                                        console.log('   - Processed (method 8): ' + processedPath8);
                                                    }
                                                    if (processedPath9) {
                                                        console.log('   - Processed (method 9): ' + processedPath9);
                                                    }
                                                    if (processedPath10) {
                                                        console.log('   - Processed (method 10): ' + processedPath10);
                                                    }
                                                    if (processedPath11) {
                                                        console.log('   - Processed (method 11): ' + processedPath11);
                                                    }
                                                    if (processedPath12) {
                                                        console.log('   - Processed (method 12): ' + processedPath12);
                                                    }
                                                    if (processedPath13) {
                                                        console.log('   - Processed (method 13): ' + processedPath13);
                                                    }
                                                    if (processedPath14) {
                                                        console.log('   - Processed (method 14): ' + processedPath14);
                                                    }
                                                    if (processedPath15) {
                                                        console.log('   - Processed (method 15): ' + processedPath15);
                                                    }
                                                    console.log('');
                                                    console.log('💡 Suggestions to improve OCR:');
                                                    console.log('   1. Check the saved images and verify they are clear');
                                                    console.log('   2. Verify Tesseract.js is properly installed: npm install tesseract.js');
                                                    console.log('   3. Verify sharp is installed for preprocessing: npm install sharp');
                                                    console.log('   4. The verification code may be too distorted or have heavy interference');
                                                    console.log('');
                                                }
                                                // 不立即拋出錯誤，讓後續代碼檢查環境變數
                                            }
                                        }
                                    }
                                } catch (ocrError) {
                                    console.log('');
                                    console.log('❌ OCR recognition error: ' + ocrError.message);
                                    console.log('   Stack trace: ' + (ocrError.stack || 'No stack trace'));
                                    console.log('');
                                    // 不重新拋出錯誤，讓後續代碼檢查 recognizedCode
                                }
                            } else {
                                console.log('⚠️  OCR not available (Tesseract.js not installed)');
                            }
                        } else {
                            console.log('⚠️  Verification code image not found');
                        }
                        } // 結束舊 OCR 方法的 else 塊
                        
                        // 標記驗證碼輸入框和圖片（添加視覺標記）
                        if (verificationImageInfo) {
                            await page.evaluate((imageInfo) => {
                            // 標記驗證碼輸入框
                            const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                            if (input) {
                                // 保存原始樣式
                                const originalStyle = {
                                    border: input.style.border,
                                    outline: input.style.outline,
                                    backgroundColor: input.style.backgroundColor,
                                    boxShadow: input.style.boxShadow
                                };
                                
                                // 添加明顯的視覺標記（紅色邊框和背景）
                                input.style.border = '3px solid #ff0000';
                                input.style.outline = '2px solid #ff0000';
                                input.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
                                input.style.boxShadow = '0 0 10px rgba(255, 0, 0, 0.5)';
                                
                                // 將原始樣式存儲在元素上，以便後續恢復
                                input.setAttribute('data-original-border', originalStyle.border || '');
                                input.setAttribute('data-original-outline', originalStyle.outline || '');
                                input.setAttribute('data-original-backgroundColor', originalStyle.backgroundColor || '');
                                input.setAttribute('data-original-boxShadow', originalStyle.boxShadow || '');
                            }
                            
                            // 標記驗證碼圖片
                            if (imageInfo.found) {
                                const selectors = [
                                    'img[alt*="验证码"]',
                                    'img[alt*="驗證碼"]',
                                    'img[alt*="captcha"]',
                                    'img[alt*="code"]',
                                    'img[src*="captcha"]',
                                    'img[src*="verification"]',
                                    'img[src*="code"]',
                                    'img[src*="verify"]',
                                    'img.captcha',
                                    'img.verification-code',
                                    'img.verify-code',
                                    'canvas',
                                    '[class*="captcha"] img',
                                    '[class*="verification"] img',
                                    '[class*="verify"] img'
                                ];
                                
                                let imageElement = null;
                                for (const selector of selectors) {
                                    try {
                                        const element = document.querySelector(selector);
                                        if (element && (element.tagName === 'IMG' || element.tagName === 'CANVAS')) {
                                            const rect = element.getBoundingClientRect();
                                            if (rect.width > 0 && rect.height > 0) {
                                                imageElement = element;
                                                break;
                                            }
                                        }
                                    } catch (e) {
                                        continue;
                                    }
                                }
                                
                                // 如果沒找到，嘗試在驗證碼輸入框附近查找
                                if (!imageElement && input) {
                                    let parent = input.parentElement;
                                    let depth = 0;
                                    while (parent && depth < 5) {
                                        const images = parent.querySelectorAll('img, canvas');
                                        for (const img of images) {
                                            const rect = img.getBoundingClientRect();
                                            if (rect.width > 0 && rect.height > 0 && rect.width < 500 && rect.height < 500) {
                                                imageElement = img;
                                                break;
                                            }
                                        }
                                        if (imageElement) break;
                                        parent = parent.parentElement;
                                        depth++;
                                    }
                                }
                                
                                if (imageElement) {
                                    // 保存原始樣式
                                    const originalImageStyle = {
                                        border: imageElement.style.border,
                                        outline: imageElement.style.outline,
                                        boxShadow: imageElement.style.boxShadow
                                    };
                                    
                                    // 添加明顯的視覺標記（藍色邊框，與輸入框區分）
                                    imageElement.style.border = '4px solid #0066ff';
                                    imageElement.style.outline = '3px solid #0066ff';
                                    imageElement.style.boxShadow = '0 0 15px rgba(0, 102, 255, 0.8)';
                                    
                                    // 將原始樣式存儲在元素上
                                    imageElement.setAttribute('data-original-border', originalImageStyle.border || '');
                                    imageElement.setAttribute('data-original-outline', originalImageStyle.outline || '');
                                    imageElement.setAttribute('data-original-boxShadow', originalImageStyle.boxShadow || '');
                                    
                                    // 滾動到驗證碼區域（輸入框或圖片）
                                    const scrollTarget = imageElement || input;
                                    if (scrollTarget) {
                                        scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    }
                                }
                            } else {
                                // 如果沒找到圖片，只滾動到輸入框
                                if (input) {
                                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                            }
                        }, verificationImageInfo);
                        } // 結束 if (verificationImageInfo) 塊
                        
                        // 等待標記完成和滾動動畫
                        await new Promise(resolve => setTimeout(resolve, 800));
                        
                        // 截圖：找到驗證碼輸入框和圖片（已標記）
                        try {
                            await page.screenshot({ path: '04_verification_code_input_found_marked.png', fullPage: true });
                            console.log('📸 Screenshot 04: Verification code input and image found (marked) saved');
                        } catch (e) {
                            console.log('⚠️  Error taking screenshot: ' + e.message);
                        }
                        
                        // 恢復原始樣式（可選，如果需要保持頁面原樣）
                        // await page.evaluate(() => {
                        //     const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                        //     if (input) {
                        //         input.style.border = input.getAttribute('data-original-border') || '';
                        //         input.style.outline = input.getAttribute('data-original-outline') || '';
                        //         input.style.backgroundColor = input.getAttribute('data-original-backgroundColor') || '';
                        //         input.style.boxShadow = input.getAttribute('data-original-boxShadow') || '';
                        //     }
                        // });
                        
                        // 檢查是否有從環境變數提供的驗證碼或 OCR 識別的驗證碼
                        // $verificationCodeJs 已經是 JSON 編碼的字符串，直接使用（去掉引號）
                        const verificationCodeFromEnv = $verificationCodeJs && $verificationCodeJs !== '' && $verificationCodeJs !== 'null' ? $verificationCodeJs.replace(/^"|"$/g, '') : null;
                        
                        // 優先順序：1. 新循環識別的驗證碼 (finalCaptchaCode) 2. 環境變數 3. 舊 OCR 識別的驗證碼
                        const verificationCodeToFill = finalCaptchaCode || verificationCodeFromEnv || recognizedCode;
                        
                        if (verificationCodeToFill) {
                            // 填入驗證碼（優先順序：新循環識別 > 環境變數 > 舊 OCR 識別）
                            let codeSource = 'OCR recognition';
                            if (finalCaptchaCode) {
                                codeSource = 'new OCR loop recognition';
                            } else if (verificationCodeFromEnv) {
                                codeSource = 'environment variable';
                            }
                            console.log('📝 Filling verification code from ' + codeSource + '...');
                            try {
                                // 先點擊輸入框以獲得焦點
                                await verificationInput.click();
                                await new Promise(resolve => setTimeout(resolve, 200));
                                
                                // 清空輸入框（選中所有內容並刪除）
                                await verificationInput.click({ clickCount: 3 });
                                await page.keyboard.press('Backspace');
                                await new Promise(resolve => setTimeout(resolve, 100));
                                
                                // 使用 type 方法模擬真實輸入（Angular Material 需要）
                                await verificationInput.type(verificationCodeToFill, { delay: 50 });
                                
                                // 觸發額外的事件確保 Angular 檢測到變化
                                await page.evaluate(() => {
                                    const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                                    if (input) {
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                });
                                
                                console.log('✅ Verification code filled from ' + codeSource + ': ' + verificationCodeToFill);
                                await new Promise(resolve => setTimeout(resolve, 500));
                            } catch (e) {
                                console.log('⚠️  Error filling verification code: ' + e.message);
                                // 如果 type 失敗，嘗試使用 evaluate 方法
                                await page.evaluate((code) => {
                                    const input = document.querySelector('input#mat-input-2') || document.querySelector('input[placeholder="验证码"]');
                                    if (input) {
                                        input.focus();
                                        input.value = '';
                                        input.value = code;
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                }, verificationCodeToFill);
                            }
                        } else {
                            // 如果沒有環境變數提供的驗證碼且 OCR 也失敗，拋出錯誤
                            if (!recognizedCode) {
                                console.log('');
                                console.log('❌ Verification code is required but OCR recognition failed after all attempts');
                                console.log('');
                                console.log('📸 Debug images saved at:');
                                console.log('   - ' + process.cwd() + '/verification_code_image.png');
                                if (processedPath) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed.png');
                                }
                                if (processedPath2) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed2.png');
                                }
                                if (processedPath3) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed3.png');
                                }
                                if (processedPath4) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed4.png');
                                }
                                if (processedPath5) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed5.png');
                                }
                                if (processedPath6) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed6.png');
                                }
                                if (processedPath7) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed7.png');
                                }
                                if (processedPath8) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed8.png');
                                }
                                if (processedPath9) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed9.png');
                                }
                                if (processedPath10) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed10.png');
                                }
                                if (processedPath11) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed11.png');
                                }
                                if (processedPath12) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed12.png');
                                }
                                if (processedPath13) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed13.png');
                                }
                                if (processedPath14) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed14.png');
                                }
                                if (processedPath15) {
                                    console.log('   - ' + process.cwd() + '/verification_code_image_processed15.png');
                                }
                                console.log('');
                                console.log('💡 Please check the saved images and verify:');
                                console.log('   1. The verification code image is clear and readable');
                                console.log('   2. The image contains only digits (0-9)');
                                console.log('   3. Tesseract.js is properly installed: npm install tesseract.js');
                                console.log('   4. Sharp is installed for preprocessing: npm install sharp');
                                console.log('');
                                throw new Error('Verification code is required but OCR recognition failed. Please check the saved images and ensure OCR can recognize the code.');
                            }
                            
                            // 如果 OCR 識別成功，應該已經在之前填入
                            console.log('✅ Using OCR recognized code: ' + recognizedCode);
                        }
                        
                        // 截圖：填入驗證碼後
                        try {
                            await page.screenshot({ path: '05_after_verification_code_filled.png', fullPage: true });
                            console.log('📸 Screenshot 05: After verification code filled saved');
                        } catch (e) {
                            console.log('⚠️  Error taking screenshot: ' + e.message);
                        }
                    } else {
                        console.log('⚠️  Verification code input field not found');
                        
                        // 截圖：未找到驗證碼輸入框
                        try {
                            await page.screenshot({ path: '04_verification_code_input_not_found.png', fullPage: true });
                            console.log('📸 Screenshot 04: Verification code input not found saved');
                        } catch (e) {
                            console.log('⚠️  Error taking screenshot: ' + e.message);
                        }
                    }
                    
                    // 步驟 4: 查找並點擊登入按鈕
                    console.log('📝 Step 4: Looking for login button...');
                    
                    const loginButtonClicked = await page.evaluate(() => {
                        // 查找所有可能的登入按鈕
                        const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], a[class*="btn"]'));
                        const loginBtn = buttons.find(btn => {
                            const text = (btn.textContent || btn.value || btn.innerText || '').trim().toLowerCase();
                            const className = (btn.className || '').toLowerCase();
                            const id = (btn.id || '').toLowerCase();
                            
                            // 檢查是否包含登入相關的文字
                            return text.includes('登入') || 
                                   text.includes('登录') || 
                                   text.includes('login') ||
                                   text.includes('提交') ||
                                   text.includes('submit');
                        });
                        
                        if (loginBtn) {
                            // 先嘗試點擊
                            try {
                                loginBtn.click();
                                return { found: true, method: 'click', text: loginBtn.textContent || loginBtn.value || loginBtn.innerText };
                            } catch (e) {
                                // 如果點擊失敗，嘗試觸發事件
                                const event = new MouseEvent('click', { bubbles: true, cancelable: true });
                                loginBtn.dispatchEvent(event);
                                return { found: true, method: 'dispatchEvent', text: loginBtn.textContent || loginBtn.value || loginBtn.innerText };
                            }
                        }
                        
                        // 如果找不到按鈕，嘗試提交表單
                        const forms = document.querySelectorAll('form');
                        if (forms.length > 0) {
                            forms[0].submit();
                            return { found: true, method: 'formSubmit', text: 'form submit' };
                        }
                        
                        return { found: false };
                    });
                    
                    if (loginButtonClicked.found) {
                        console.log('✅ Login button clicked using method: ' + loginButtonClicked.method);
                        console.log('   Button text: ' + loginButtonClicked.text);
                        
                        // 截圖：點擊登入按鈕後
                        try {
                            await page.screenshot({ path: '06_after_login_button_clicked.png', fullPage: true });
                            console.log('📸 Screenshot 06: After login button clicked saved');
                        } catch (e) {
                            console.log('⚠️  Error taking screenshot: ' + e.message);
                        }
                        
                        // 記錄點擊前的 URL
                        const urlBeforeLogin = page.url();
                        console.log('📍 URL before login: ' + urlBeforeLogin);
                        
                        // 等待登入完成（等待頁面導航或 URL 變化）
                        let navigationSuccess = false;
                        try {
                            await page.waitForNavigation({ 
                                waitUntil: 'load',
                                timeout: 30000 
                            });
                            navigationSuccess = true;
                            console.log('✅ Navigation detected');
                        } catch (e) {
                            console.log('⚠️  Navigation timeout, waiting 3 seconds...');
                            // 即使沒有導航，也等待一下
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        }
                        
                        // 額外等待確保頁面完全載入
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        // 檢查登入是否成功
                        const urlAfterLogin = page.url();
                        console.log('📍 URL after login: ' + urlAfterLogin);
                        
                        // 檢查 URL 是否改變（登入成功通常會跳轉）
                        const urlChanged = urlBeforeLogin !== urlAfterLogin;
                        
                        // 檢查頁面內容是否包含登入成功的標識
                        const loginSuccessCheck = await page.evaluate(() => {
                            // 檢查是否還在登入頁面（通常登入頁面會有登入表單）
                            const loginForm = document.querySelector('input[placeholder="用户名"], input[placeholder="密码"], input#mat-input-0, input#mat-input-1');
                            const isStillOnLoginPage = !!loginForm;
                            
                            // 檢查是否有錯誤訊息
                            const errorMessages = document.querySelectorAll('[class*="error"], [class*="Error"], [id*="error"], [id*="Error"]');
                            let hasError = false;
                            let errorText = '';
                            errorMessages.forEach(el => {
                                const text = (el.textContent || '').trim();
                                if (text && (text.includes('错误') || text.includes('錯誤') || text.includes('error') || text.includes('失败') || text.includes('失敗'))) {
                                    hasError = true;
                                    errorText = text;
                                }
                            });
                            
                            return {
                                isStillOnLoginPage: isStillOnLoginPage,
                                hasError: hasError,
                                errorText: errorText,
                                currentUrl: window.location.href
                            };
                        });
                        
                        // 截圖：登入完成後的最終頁面
                        try {
                            await page.screenshot({ path: '07_login_completed_final_page.png', fullPage: true });
                            console.log('📸 Screenshot 07: Login completed final page saved');
                        } catch (e) {
                            console.log('⚠️  Error taking screenshot: ' + e.message);
                        }
                        
                        // 判斷登入是否成功
                        if (urlChanged && !loginSuccessCheck.isStillOnLoginPage) {
                            console.log('✅ Login process completed successfully!');
                            console.log('📍 Current URL: ' + urlAfterLogin);
                        } else if (loginSuccessCheck.hasError) {
                            console.log('❌ Login failed: ' + loginSuccessCheck.errorText);
                            console.log('📍 Current URL: ' + urlAfterLogin);
                        } else if (loginSuccessCheck.isStillOnLoginPage) {
                            console.log('⚠️  Login may have failed - still on login page');
                            console.log('📍 Current URL: ' + urlAfterLogin);
                            console.log('   URL changed: ' + urlChanged);
                        } else {
                            console.log('✅ Login process completed!');
                            console.log('📍 Current URL: ' + urlAfterLogin);
                        }
                    } else {
                        console.log('⚠️  Login button not found');
                    }
                    
                    // 獲取登入後的 cookies 和認證資訊
                    let authData = { cookies: '', localStorage: {} };
                    try {
                        // 確保頁面穩定
                        await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        authData = await page.evaluate(() => {
                            const cookies = document.cookie;
                            const localStorage = {};
                            for (let i = 0; i < window.localStorage.length; i++) {
                                const key = window.localStorage.key(i);
                                localStorage[key] = window.localStorage.getItem(key);
                            }
                            return { cookies, localStorage };
                        });
                    } catch (e) {
                        console.log('⚠️  Error getting auth data: ' + e.message);
                    }

                    const cookies = await page.cookies();
                    console.log('✅ Login completed, obtained ' + cookies.length + ' cookies');

                    // 如果提供了目標 URL，導航到目標 URL
                    if ($urlJs && $urlJs !== '' && $urlJs !== $domainJs) {
                        console.log('🌐 Navigating to target URL:', $urlJs);
                        try {
                            await page.goto($urlJs, {
                                waitUntil: 'load',
                                timeout: 60000
                            });
                            console.log('✅ Successfully navigated to target URL');
                            
                            // 等待頁面完全載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                            
                            // 截圖：目標頁面
                            try {
                                await page.screenshot({ path: '08_target_page.png', fullPage: true });
                                console.log('📸 Screenshot 08: Target page saved');
                            } catch (e) {
                                console.log('⚠️  Error taking screenshot: ' + e.message);
                            }
                        } catch (e) {
                            console.log('⚠️  Error navigating to target URL: ' + e.message);
                        }
                    }

                    // 返回結果
                    const result = {
                        success: true,
                        url: page.url(),
                        cookies: cookies,
                        authData: authData,
                        message: 'Login completed successfully'
                    };

                    // 保存結果
                    fs.writeFileSync('scrape_result.json', JSON.stringify(result, null, 2));

                    await browser.close();
                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    const errorResult = {
                        success: false,
                        error: error.message
                    };
                    fs.writeFileSync('scrape_result.json', JSON.stringify(errorResult, null, 2));
                    await browser.close();
                    throw error;
                }
            }

            loginAndNavigate().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_zgslot.js');
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        
        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath 腳本文件路徑
     * @return array|null 返回爬取的結果，失敗時返回 null
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation script...');
        
        $workingDir = dirname($scriptPath);
        // 增加超時時間到 5 分鐘（300秒），因為 OCR 識別可能需要時間
        $this->info('⏳ Script timeout set to 5 minutes (OCR recognition may take time)...');
        
        // 使用 Process 執行腳本，並實時輸出日誌
        $process = Process::path($workingDir)
            ->timeout(300)
            ->tty(false); // 不使用 TTY，以便捕獲所有輸出
        
        // 執行腳本並實時輸出
        $result = $process->run("node " . basename($scriptPath), function ($type, $output) {
            // 實時輸出腳本的控制台日誌
            echo $output;
        });

        if ($result->failed()) {
            $this->error("❌ Script execution failed");
            $errorOutput = $result->errorOutput();
            $stdOutput = $result->output();
            
            // 顯示完整的錯誤信息
            if (!empty($errorOutput)) {
                $this->line("Error output: " . $errorOutput);
            }
            if (!empty($stdOutput)) {
                $this->line("Standard output: " . $stdOutput);
            }
            
            return null;
        }

        $resultFile = $workingDir . '/scrape_result.json';
        if (file_exists($resultFile)) {
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }

        return null;
    }

    /**
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        // 處理截圖文件
        $this->processScreenshots($workingDir, $timestamp);

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Login completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('🍪 Cookies: ' . (count($result['cookies'] ?? []) . ' cookie(s)'));
        } else {
            $this->error('❌ Login failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }

    /**
     * 處理截圖文件，將它們從臨時目錄移動到永久儲存目錄
     * @param string $workingDir 工作目錄（臨時目錄）
     * @param string $timestamp 時間戳
     */
    private function processScreenshots($workingDir, $timestamp)
    {
        $this->info('📸 Processing screenshots...');
        
        // 截圖文件名模式
        $screenshotPatterns = [
            '01_initial_login_page.png',
            '02_after_account_filled.png',
            '03_after_password_filled.png',
            '04_verification_code_input_found.png',
            '04_verification_code_input_found_marked.png',
            '04_verification_code_input_not_found.png',
            '05_after_verification_code_filled.png',
            '06_after_login_button_clicked.png',
            '07_login_completed_final_page.png',
            '08_target_page.png',
        ];
        
        // 截圖直接保存在 scraped_data 資料夾
        $screenshotsDir = storage_path('app/scraped_data');
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }
        
        $screenshotCount = 0;
        
        // 處理所有截圖文件
        foreach ($screenshotPatterns as $pattern) {
            $srcPath = $workingDir . '/' . $pattern;
            if (file_exists($srcPath)) {
                // 添加時間戳前綴以避免文件名衝突
                $destFilename = "zgslot_{$timestamp}_{$pattern}";
                $destPath = $screenshotsDir . '/' . $destFilename;
                if (rename($srcPath, $destPath)) {
                    $screenshotCount++;
                    $this->line("   ✅ {$destFilename}");
                }
            }
        }
        
        // 也嘗試移動所有以數字開頭的 PNG 文件（備用方案）
        $allScreenshots = glob($workingDir . '/[0-9]*.png');
        foreach ($allScreenshots as $file) {
            $filename = basename($file);
            // 添加時間戳前綴以避免文件名衝突
            $destFilename = "zgslot_{$timestamp}_{$filename}";
            $destPath = $screenshotsDir . '/' . $destFilename;
            if (!file_exists($destPath) && rename($file, $destPath)) {
                $screenshotCount++;
                $this->line("   ✅ {$destFilename}");
            }
        }
        
        if ($screenshotCount > 0) {
            $this->info("✅ {$screenshotCount} screenshot(s) saved to: {$screenshotsDir}");
        } else {
            $this->warn('⚠️  No screenshots found');
        }
    }
}

