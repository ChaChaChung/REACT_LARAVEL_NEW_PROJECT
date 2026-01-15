<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - WOW (使用 sessionStorage 登入)
 * 此命令會使用 sessionStorage 中的 dashboardToken 進行登入，然後截圖
 */
class ScrapeBrowserWowDomDetail extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-wow-dom-detail {url} {date_start?} {date_end?}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 開始日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     * {date_end?} - 結束日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     */
    protected $signature = 'agent:scrape-wow-dom-detail {url} {date_start?} {date_end?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from WOW DOM elements using browser automation with sessionStorage login';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $dateStartRaw = $this->argument('date_start');
        $dateEndRaw = $this->argument('date_end');
        
        // 轉換日期格式（支持 YYYYMMDD 和 YYYY-MM-DD）
        $dateStart = $this->normalizeDate($dateStartRaw);
        $dateEnd = $this->normalizeDate($dateEndRaw);

        $this->info('=== WOW DOM Data Scraper (SessionStorage Login) ===');
        $this->info("Target URL: {$url}");
        if ($dateStart) {
            $this->info("Start Date: {$dateStart}");
        }
        if ($dateEnd) {
            $this->info("End Date: {$dateEnd}");
        }
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 從環境變數獲取登入域名
        $domain = env('WOW_AGENT_DOMAIN', '');
        
        if (empty($domain)) {
            $this->error('❌ WOW_AGENT_DOMAIN environment variable is not set');
            $this->line('Please set WOW_AGENT_DOMAIN in your .env file');
            return 1;
        }

        // 從環境變數獲取登入 Token
        $token = env('WOW_AGENT_TOKEN', '');
        
        if (empty($token)) {
            $this->error('❌ WOW_AGENT_TOKEN environment variable is not set');
            $this->line('Please set WOW_AGENT_TOKEN in your .env file');
            return 1;
        }

        // 從環境變數獲取語言設定
        $lang = env('WOW_AGENT_LANG', '');

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($domain, $url, $token, $dateStart, $dateEnd, $lang);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理截圖
        if ($result) {
            $this->processScreenshot($result);
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

        return true;
    }

    /**
     * 標準化日期格式
     * 將 YYYYMMDD 轉換為 YYYY-MM-DD
     * @param string|null $date 日期字符串
     * @return string|null 標準化後的日期字符串
     */
    private function normalizeDate($date)
    {
        if (empty($date)) {
            return null;
        }
        
        // 如果已經是 YYYY-MM-DD 格式，直接返回
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // 如果是 YYYYMMDD 格式，轉換為 YYYY-MM-DD
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        
        // 如果格式不正確，返回原值（讓 JavaScript 端處理錯誤）
        return $date;
    }

    /**
     * 創建 Puppeteer 自動化腳本（使用 sessionStorage 登入）
     * @param string $domain 登入頁面網址
     * @param string $url 要爬取的目標網址
     * @param string $token 登入 Token
     * @param string|null $dateStart 開始日期（格式：YYYY-MM-DD）
     * @param string|null $dateEnd 結束日期（格式：YYYY-MM-DD）
     * @param string|null $lang 語言設定
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $url, $token, $dateStart = null, $dateEnd = null, $lang = null)
    {
        $this->info('2. Creating browser automation script...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $urlJs = json_encode($url);
        $tokenJs = json_encode($token);
        $langJs = json_encode($lang);
        // 將 date 轉換為 JavaScript 可用的格式（與 GLC 一致）
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';
        
        // 獲取工作目錄的絕對路徑
        $workingDir = storage_path('app/temp');
        $workingDirJs = json_encode($workingDir);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            
            // 工作目錄
            const workingDir = $workingDirJs;

            /**
             * WOW 使用 sessionStorage 登入流程
             */
            async function loginWithSessionStorageAndScreenshot() {
                // 啟動非無頭瀏覽器（headless: false，讓使用者可以看到瀏覽器）
                const browser = await puppeteer.launch({
                    headless: false, // 非無頭模式，讓使用者可以看到瀏覽器
                    args: [
                        // 安全性相關參數（用於容器環境）
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        // 性能優化參數
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--disable-gpu',
                        // 功能禁用（減少資源使用）
                        '--disable-features=TranslateUI',
                        '--disable-crash-reporter',
                        '--disable-breakpad',
                        '--disable-default-apps',
                        '--disable-extensions',
                        '--disable-plugins',
                        '--disable-web-security',
                        '--disable-features=VizDisplayCompositor',
                        '--memory-pressure-off'
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

                    console.log('🔐 Starting WOW sessionStorage login process...');
                    
                    // 移除 JSON 編碼的引號
                    const loginDomain = $domainJs.replace(/^"|"\$/g, '');
                    const targetUrl = $urlJs.replace(/^"|"\$/g, '');
                    const dashboardToken = $tokenJs.replace(/^"|"\$/g, '');
                    
                    // 先導航到登入頁面
                    console.log('🌐 Navigating to login domain: ' + loginDomain);
                    await page.goto(loginDomain, {
                        waitUntil: 'load',
                        timeout: 60000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 設置 sessionStorage 中的 dashboardToken
                    console.log('💾 Setting sessionStorage[dashboardToken]...');
                    await page.evaluate((token) => {
                        try {
                            // 設置 dashboardToken 到 sessionStorage
                            sessionStorage.setItem('dashboardToken', token);
                            console.log('✅ Set sessionStorage[dashboardToken]');
                            
                            // 觸發 storage 事件，讓應用知道 sessionStorage 已更新
                            window.dispatchEvent(new StorageEvent('storage', {
                                key: 'dashboardToken',
                                newValue: token,
                                oldValue: null,
                                storageArea: sessionStorage
                            }));
                            
                            // 如果頁面有監聽器，可能需要觸發自定義事件
                            window.dispatchEvent(new Event('sessionStorageUpdated'));
                            
                            return true;
                        } catch (e) {
                            console.error('❌ Error setting sessionStorage:', e.message);
                            return false;
                        }
                    }, dashboardToken);
                    
                    // 等待一下讓頁面處理 sessionStorage 更新
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 設置 cookie 中的 site_lang（如果提供了語言設定）
                    const siteLang = $langJs && $langJs !== 'null' ? $langJs.replace(/^"|"\$/g, '') : null;
                    if (siteLang && siteLang !== '') {
                        console.log('🌐 Setting cookie[site_lang] = ' + siteLang);
                        try {
                            // 獲取當前頁面的域名
                            const currentUrl = page.url();
                            const urlObj = new URL(currentUrl);
                            const domain = urlObj.hostname;
                            
                            // 設置 cookie
                            await page.setCookie({
                                name: 'site_lang',
                                value: siteLang,
                                domain: domain,
                                path: '/'
                            });
                            console.log('✅ Cookie[site_lang] set successfully');
                        } catch (e) {
                            console.log('⚠️  Error setting cookie: ' + e.message);
                        }
                    }
                    
                    // 刷新頁面讓應用讀取新的 sessionStorage 和 cookie
                    console.log('🔄 Reloading page to apply sessionStorage and cookie...');
                    await page.reload({
                        waitUntil: 'networkidle2',
                        timeout: 60000
                    });
                    
                    // 等待頁面完全載入
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 驗證 sessionStorage 是否已設置
                    const sessionStorageSet = await page.evaluate(() => {
                        const token = sessionStorage.getItem('dashboardToken');
                        return token !== null && token !== '';
                    });
                    
                    if (sessionStorageSet) {
                        console.log('✅ sessionStorage[dashboardToken] successfully set and page reloaded');
                    } else {
                        console.log('⚠️  sessionStorage[dashboardToken] may not be set correctly');
                    }
                    
                    // 等待頁面穩定
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 導航到目標 URL
                    console.log('🌐 Navigating to target URL: ' + targetUrl);
                    await page.goto(targetUrl, {
                        waitUntil: 'networkidle2',
                        timeout: 60000
                    });
                    
                    // 等待頁面完全載入
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 解析日期參數
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    
                    try {
                        if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs !== '') {
                            dateStartParsed = JSON.parse($dateStartJs);
                        }
                        if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs !== '') {
                            dateEndParsed = JSON.parse($dateEndJs);
                        }
                    } catch (e) {
                        dateStartParsed = $dateStartJs !== 'null' ? $dateStartJs.replace(/^"|"\$/g, '') : null;
                        dateEndParsed = $dateEndJs !== 'null' ? $dateEndJs.replace(/^"|"\$/g, '') : null;
                    }
                    
                    // 如果提供了 date_start 或 date_end，點擊日期選擇器打開
                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') || 
                        (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            console.log('📅 Setting up date range...');
                            
                            // 等待日期選擇器元素出現
                            await page.waitForSelector('div.el-date-editor.el-range-editor.el-input__inner.filter-item.date-picker.el-date-editor--datetimerange, div.el-date-editor.el-range-editor, div.date-picker.el-range-editor', { timeout: 10000 }).catch(() => {
                                console.log('⚠️  Date picker element not found');
                            });
                            
                            // 截圖：點擊日期選擇器之前
                            try {
                                const screenshotBeforeClick = path.join(workingDir, '01_before_click_date_picker.png');
                                await page.screenshot({ path: screenshotBeforeClick, fullPage: true });
                                console.log('📸 Screenshot 01: Before clicking date picker saved');
                            } catch (e) {
                                console.log('⚠️  Error taking screenshot 01: ' + e.message);
                            }
                            
                            // 點擊日期選擇器來打開日期選擇面板
                            const datePickerClicked = await page.evaluate(() => {
                                // 優先查找完整選擇器
                                const datePicker = document.querySelector('div.el-date-editor.el-range-editor.el-input__inner.filter-item.date-picker.el-date-editor--datetimerange') ||
                                                   document.querySelector('div.el-date-editor.el-range-editor.el-input__inner.filter-item.date-picker') ||
                                                   document.querySelector('div.el-date-editor.el-range-editor') ||
                                                   document.querySelector('div.date-picker.el-range-editor');
                                
                                if (datePicker) {
                                    datePicker.click();
                                    return true;
                                }
                                return false;
                            });
                            
                            if (!datePickerClicked) {
                                // 如果 evaluate 方法失敗，嘗試使用 Puppeteer 的 click 方法
                                await page.click('div.el-date-editor.el-range-editor.el-input__inner.filter-item.date-picker.el-date-editor--datetimerange, div.el-date-editor.el-range-editor, div.date-picker.el-range-editor', { timeout: 5000 }).catch(() => {
                                    console.log('⚠️  Could not click date picker');
                                });
                            } else {
                                console.log('✅ Date picker clicked');
                            }
                            
                            // 等待日期選擇器出現（增加等待時間確保完全打開）
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 截圖：點擊日期選擇器之後（日期選擇面板應該已打開）
                            try {
                                const screenshotAfterClick = path.join(workingDir, '02_after_click_date_picker.png');
                                await page.screenshot({ path: screenshotAfterClick, fullPage: true });
                                console.log('📸 Screenshot 02: After clicking date picker saved');
                            } catch (e) {
                                console.log('⚠️  Error taking screenshot 02: ' + e.message);
                            }
                            
                            // 驗證日期選擇器是否已打開
                            const datePickerOpened = await page.evaluate(() => {
                                const startDateInput = document.querySelector('input.el-input__inner[placeholder="开始日期"], input.el-input__inner[placeholder="Start Date"]');
                                const endDateInput = document.querySelector('input.el-input__inner[placeholder="结束日期"], input.el-input__inner[placeholder="End Date"]');
                                return !!(startDateInput || endDateInput);
                            });
                            
                            if (!datePickerOpened) {
                                console.log('⚠️  Date picker may not be fully opened, waiting more...');
                                await new Promise(resolve => setTimeout(resolve, 2000));
                            } else {
                                console.log('✅ Date picker opened successfully');
                            }
                            
                            // 如果提供了 date_start，填入 Start Date
                            if (dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') {
                                console.log('📅 Filling Start Date: ' + dateStartParsed);
                                
                                // 等待 Start Date input 出現（支持中英文）
                                await page.waitForSelector('input.el-input__inner[placeholder="开始日期"], input.el-input__inner[placeholder="Start Date"]', { timeout: 5000 }).catch(() => {
                                    console.log('⚠️  Start Date input not found');
                                });
                                
                                // 填入開始日期（支持中英文占位符）
                                await page.evaluate((dateStartValue) => {
                                    const startDateInput = document.querySelector('input.el-input__inner[placeholder="开始日期"], input.el-input__inner[placeholder="Start Date"]');
                                    
                                    if (startDateInput) {
                                        startDateInput.value = '';
                                        startDateInput.value = dateStartValue;
                                        startDateInput.dispatchEvent(new Event('input', { bubbles: true }));
                                        startDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                                        startDateInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                        
                                        // 觸發 focus 和 blur 來確保驗證
                                        startDateInput.focus();
                                        startDateInput.blur();
                                    } else {
                                        console.error('❌ Start Date input not found in DOM');
                                    }
                                }, dateStartParsed);
                                
                                // 驗證日期是否正確填入
                                await new Promise(resolve => setTimeout(resolve, 500));
                                const startDateValue = await page.evaluate(() => {
                                    const input = document.querySelector('input.el-input__inner[placeholder="开始日期"], input.el-input__inner[placeholder="Start Date"]');
                                    return input ? input.value : null;
                                });
                                
                                if (startDateValue && startDateValue.includes(dateStartParsed)) {
                                    console.log('✅ Start date filled and verified: ' + startDateValue);
                                } else {
                                    console.log('⚠️  Start date may not be set correctly. Expected: ' + dateStartParsed + ', Got: ' + startDateValue);
                                    // 重試一次
                                    await page.evaluate((dateStartValue) => {
                                        const input = document.querySelector('input.el-input__inner[placeholder="开始日期"], input.el-input__inner[placeholder="Start Date"]');
                                        if (input) {
                                            input.focus();
                                            input.select();
                                            input.value = dateStartValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    }, dateStartParsed);
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                                
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                // 截圖：填入開始日期後
                                try {
                                    const screenshotAfterStartDate = path.join(workingDir, '03_after_fill_start_date.png');
                                    await page.screenshot({ path: screenshotAfterStartDate, fullPage: true });
                                    console.log('📸 Screenshot 03: After filling start date saved');
                                } catch (e) {
                                    console.log('⚠️  Error taking screenshot 03: ' + e.message);
                                }
                            }
                            
                            // 如果提供了 date_end，填入 End Date
                            if (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '') {
                                console.log('📅 Filling End Date: ' + dateEndParsed);
                                
                                // 等待 End Date input 出現（支持中英文）
                                await page.waitForSelector('input.el-input__inner[placeholder="结束日期"], input.el-input__inner[placeholder="End Date"]', { timeout: 5000 }).catch(() => {
                                    console.log('⚠️  End Date input not found');
                                });
                                
                                // 填入結束日期（支持中英文占位符）
                                await page.evaluate((dateEndValue) => {
                                    const endDateInput = document.querySelector('input.el-input__inner[placeholder="结束日期"], input.el-input__inner[placeholder="End Date"]');
                                    
                                    if (endDateInput) {
                                        endDateInput.value = '';
                                        endDateInput.value = dateEndValue;
                                        endDateInput.dispatchEvent(new Event('input', { bubbles: true }));
                                        endDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                                        endDateInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                        
                                        // 觸發 focus 和 blur 來確保驗證
                                        endDateInput.focus();
                                        endDateInput.blur();
                                    } else {
                                        console.error('❌ End Date input not found in DOM');
                                    }
                                }, dateEndParsed);
                                
                                // 驗證日期是否正確填入
                                await new Promise(resolve => setTimeout(resolve, 500));
                                const endDateValue = await page.evaluate(() => {
                                    const input = document.querySelector('input.el-input__inner[placeholder="结束日期"], input.el-input__inner[placeholder="End Date"]');
                                    return input ? input.value : null;
                                });
                                
                                if (endDateValue && endDateValue.includes(dateEndParsed)) {
                                    console.log('✅ End date filled and verified: ' + endDateValue);
                                } else {
                                    console.log('⚠️  End date may not be set correctly. Expected: ' + dateEndParsed + ', Got: ' + endDateValue);
                                    // 重試一次
                                    await page.evaluate((dateEndValue) => {
                                        const input = document.querySelector('input.el-input__inner[placeholder="结束日期"], input.el-input__inner[placeholder="End Date"]');
                                        if (input) {
                                            input.focus();
                                            input.select();
                                            input.value = dateEndValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    }, dateEndParsed);
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                                
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                // 截圖：填入結束日期後
                                try {
                                    const screenshotAfterEndDate = path.join(workingDir, '04_after_fill_end_date.png');
                                    await page.screenshot({ path: screenshotAfterEndDate, fullPage: true });
                                    console.log('📸 Screenshot 04: After filling end date saved');
                                } catch (e) {
                                    console.log('⚠️  Error taking screenshot 04: ' + e.message);
                                }
                            }
                            
                            // 點擊 OK 按鈕確認日期選擇
                            console.log('🔘 Looking for OK button...');
                            
                            // 等待 OK 按鈕出現
                            await page.waitForSelector('button.el-button.el-picker-panel__link-btn.el-button--default.el-button--mini.is-plain', { timeout: 5000 }).catch(() => {
                                console.log('⚠️  OK button not found by selector');
                            });
                            
                            // 查找並點擊 OK 按鈕
                            const okButtonClicked = await page.evaluate(() => {
                                // 優先查找指定的 OK 按鈕
                                const okButtons = Array.from(document.querySelectorAll('button.el-button.el-picker-panel__link-btn.el-button--default.el-button--mini.is-plain'));
                                for (let btn of okButtons) {
                                    const text = btn.textContent.trim();
                                    if (text === 'OK') {
                                        btn.click();
                                        return true;
                                    }
                                }
                                
                                // 如果找不到，嘗試查找任何包含 "OK" 文本的按鈕
                                const allButtons = Array.from(document.querySelectorAll('button.el-button'));
                                for (let btn of allButtons) {
                                    const text = btn.textContent.trim();
                                    if (text === 'OK') {
                                        btn.click();
                                        return true;
                                    }
                                }
                                
                                return false;
                            });
                            
                            if (okButtonClicked) {
                                console.log('✅ OK button clicked');
                            } else {
                                console.log('⚠️  OK button not found');
                            }
                            
                            // 等待日期選擇器關閉
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 截圖：點擊 OK 按鈕後（日期選擇器應該已關閉）
                            try {
                                const screenshotAfterOK = path.join(workingDir, '05_after_click_ok.png');
                                await page.screenshot({ path: screenshotAfterOK, fullPage: true });
                                console.log('📸 Screenshot 05: After clicking OK button saved');
                            } catch (e) {
                                console.log('⚠️  Error taking screenshot 05: ' + e.message);
                            }
                            
                            console.log('✅ Date range set successfully');
                        } catch (e) {
                            console.log('⚠️  Error filling date: ' + e.message);
                            console.error(e);
                        }
                    }

                    // 如果至少填入了其中一個日期，嘗試點擊搜尋按鈕
                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') || 
                        (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            // 等待一下讓日期輸入完成
                            await new Promise(resolve => setTimeout(resolve, 1000));

                            // 查找並點擊搜尋按鈕
                            console.log('🔍 Looking for Search button...');
                            
                            // 使用多種方式查找並點擊按鈕
                            let buttonClicked = false;
                            
                            // 方式1：使用用戶提供的選擇器查找搜索按鈕
                            try {
                                const searchButton = await page.$('button.el-button.filter-item.search-btn.el-button--primary.el-button--mini').catch(() => null);
                                
                                if (searchButton) {
                                    // 滾動到按鈕位置
                                    await page.evaluate(() => {
                                        const btn = document.querySelector('button.el-button.filter-item.search-btn.el-button--primary.el-button--mini');
                                        if (btn) {
                                            btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        }
                                    });
                                    
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    
                                    // 點擊按鈕
                                    await searchButton.click();
                                    buttonClicked = true;
                                    console.log('✅ Search button clicked (method 1)');
                                }
                            } catch (e) {
                                console.log('⚠️  Method 1 failed: ' + e.message);
                            }
                            
                            // 方式2：通過文本內容查找包含 "Search" 的按鈕
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button.el-button'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text.includes('Search') || text === 'Search') {
                                                return btn;
                                            }
                                        }
                                        return null;
                                    });
                                    
                                    if (buttonHandle && buttonHandle.asElement()) {
                                        await buttonHandle.asElement().scrollIntoView();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        await buttonHandle.asElement().click();
                                        await buttonHandle.dispose();
                                        
                                        buttonClicked = true;
                                        console.log('✅ Search button clicked (method 2)');
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 2 failed: ' + e.message);
                                }
                            }
                            
                            // 方式3：查找任何包含 "Search" 文本的按鈕
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text.includes('Search') || text === 'Search') {
                                                return btn;
                                            }
                                        }
                                        return null;
                                    });
                                    
                                    if (buttonHandle && buttonHandle.asElement()) {
                                        await buttonHandle.asElement().scrollIntoView();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        await buttonHandle.asElement().click();
                                        await buttonHandle.dispose();
                                        
                                        buttonClicked = true;
                                        console.log('✅ Search button clicked (method 3)');
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 3 failed: ' + e.message);
                                }
                            }
                            
                            if (buttonClicked) {
                                // 等待搜索結果載入
                                console.log('⏳ Waiting for search results...');
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                console.log('✅ Search completed');
                            } else {
                                console.log('⚠️  Search button not found');
                            }
                        } catch (e) {
                            console.log('⚠️  Error clicking search button: ' + e.message);
                            console.error(e);
                        }
                    }
                    
                    // 等待頁面穩定
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 截圖
                    console.log('📸 Taking screenshot...');
                    const screenshotPath = path.join(workingDir, 'wow_screenshot.png');
                    await page.screenshot({
                        path: screenshotPath,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshotPath);
                    
                    // 返回結果
                    const result = {
                        success: true,
                        url: page.url(),
                        title: await page.title(),
                        screenshot: screenshotPath
                    };
                    
                    // 保存結果
                    const resultPath = path.join(workingDir, 'scrape_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    
                    // 等待 5 秒讓使用者查看結果
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    const errorResult = {
                        success: false,
                        error: error.message,
                        stack: error.stack
                    };
                    fs.writeFileSync(path.join(workingDir, 'scrape_result.json'), JSON.stringify(errorResult, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    throw error;
                }
            }

            loginWithSessionStorageAndScreenshot().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_wow_sessionstorage.js');
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
        
        // 使用 Process 執行腳本，並實時輸出日誌
        $process = Process::path($workingDir)
            ->timeout(600)
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
     * 處理和保存截圖
     * @param array $result 爬取的結果資料
     */
    private function processScreenshot($result)
    {
        $this->info('');
        $this->info('4. Processing screenshot...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('📄 Title: ' . ($result['title'] ?? 'N/A'));
            
            // 處理截圖
            $screenshotsDir = storage_path('app/scraped_data');
            if (!is_dir($screenshotsDir)) {
                mkdir($screenshotsDir, 0755, true);
            }
            
            // 處理所有步驟截圖
            $screenshotFiles = [
                '01_before_click_date_picker.png',
                '02_after_click_date_picker.png',
                '03_after_fill_start_date.png',
                '04_after_fill_end_date.png',
                '05_after_click_ok.png',
                'wow_screenshot.png'
            ];
            
            $screenshotCount = 0;
            foreach ($screenshotFiles as $screenshotFile) {
                $screenshotSrc = $workingDir . '/' . $screenshotFile;
                if (file_exists($screenshotSrc)) {
                    $screenshotDst = $screenshotsDir . '/wow_' . $timestamp . '_' . $screenshotFile;
                    rename($screenshotSrc, $screenshotDst);
                    $this->info("📸 Screenshot saved: {$screenshotDst}");
                    $screenshotCount++;
                }
            }
            
            // 處理主截圖（如果存在）
            if (isset($result['screenshot'])) {
                $screenshotSrc = $result['screenshot'];
                if (file_exists($screenshotSrc) && !in_array(basename($screenshotSrc), $screenshotFiles)) {
                    $screenshotDst = $screenshotsDir . '/wow_screenshot_' . $timestamp . '.png';
                    rename($screenshotSrc, $screenshotDst);
                    $this->info("📸 Main screenshot saved to: {$screenshotDst}");
                    $screenshotCount++;
                }
            }
            
            if ($screenshotCount === 0) {
                $this->warn('⚠️  No screenshots found');
            } else {
                $this->info("✅ Total {$screenshotCount} screenshot(s) saved");
            }
            
            if (isset($result['error'])) {
                $this->warn('⚠️  Warning: ' . $result['error']);
            }
        } else {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('');
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }
}
