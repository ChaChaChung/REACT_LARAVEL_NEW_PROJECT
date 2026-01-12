<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - ZGSLOT (手動輸入模式)
 * 此命令會打開非無頭瀏覽器，讓使用者手動輸入帳號密碼及驗證碼
 */
class ScrapeBrowserZgslotDOMDetail extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-zgslot-dom-detail {url} {start_date?} {end_date?}
     * {url} - 要爬取的目標網址（必需參數）
     * {start_date?} - 開始日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     * {end_date?} - 結束日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     */
    protected $signature = 'agent:scrape-zgslot-dom-detail {url} {start_date?} {end_date?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from ZGSLOT DOM elements using browser automation with manual login input';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $startDateRaw = $this->argument('start_date');
        $endDateRaw = $this->argument('end_date');
        
        // 轉換日期格式（支持 YYYYMMDD 和 YYYY-MM-DD）
        $startDate = $this->normalizeDate($startDateRaw);
        $endDate = $this->normalizeDate($endDateRaw);

        $this->info('=== ZGSLOT DOM Data Scraper (Manual Input Mode) ===');
        $this->info("Target URL: {$url}");
        if ($startDate) {
            $this->info("Start Date: {$startDate}");
        }
        if ($endDate) {
            $this->info("End Date: {$endDate}");
        }
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 從環境變數獲取登入域名
        $domain = env('ZGSLOT_AGENT_DOMAIN', '');
        
        if (empty($domain)) {
            $this->error('❌ ZGSLOT_AGENT_DOMAIN environment variable is not set');
            $this->line('Please set ZGSLOT_AGENT_DOMAIN in your .env file');
            return 1;
        }
        
        $this->info("Login Domain: {$domain}");

        // 創建 Puppeteer 腳本（手動輸入模式）
        $scriptPath = $this->createPuppeteerScript($domain, $url, $startDate, $endDate);

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
     * 創建 Puppeteer 自動化腳本（手動輸入模式）
     * @param string $domain 登入頁面網址
     * @param string $url 要爬取的目標網址
     * @param string|null $startDate 開始日期（格式：YYYY-MM-DD）
     * @param string|null $endDate 結束日期（格式：YYYY-MM-DD）
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $url, $startDate = null, $endDate = null)
    {
        $this->info('2. Creating browser automation script (manual input mode)...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $urlJs = json_encode($url);
        $startDateJs = json_encode($startDate);
        $endDateJs = json_encode($endDate);
        
        // 獲取工作目錄的絕對路徑
        $workingDir = storage_path('app/temp');
        $workingDirJs = json_encode($workingDir);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            const readline = require('readline');
            
            // 工作目錄（json_encode 已經生成了正確的 JavaScript 字符串）
            const workingDir = $workingDirJs;
            console.log('📁 Working directory: ' + workingDir);

            /**
             * 設置每頁筆數的輔助函數
             */
            async function setPageSize(page, size) {
                try {
                    console.log('📄 Setting page size to ' + size + '...');
                    
                    // 查找分頁器的每頁筆數選擇器
                    const pageSizeSelect = await page.$('mat-select#mat-select-4, mat-select[aria-label*="每页笔数"], mat-select[aria-label*="每頁筆數"]').catch(() => null);
                    
                    if (!pageSizeSelect) {
                        // 嘗試其他選擇器
                        const selectors = [
                            'mat-select.mat-paginator-page-size-select',
                            'mat-select.mat-select',
                            '.mat-paginator-page-size-select mat-select'
                        ];
                        
                        for (const selector of selectors) {
                            const select = await page.$(selector).catch(() => null);
                            if (select) {
                                await select.click();
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                // 等待下拉菜單出現
                                const option = await page.waitForSelector('mat-option .mat-option-text', { timeout: 5000 }).catch(() => null);
                                if (option) {
                                    // 查找包含目標數字的選項
                                    const targetOption = await page.evaluateHandle((targetSize) => {
                                        const options = Array.from(document.querySelectorAll('mat-option'));
                                        return options.find(opt => {
                                            const text = opt.textContent || opt.innerText || '';
                                            return text.includes(targetSize.toString());
                                        });
                                    }, size).catch(() => null);
                                    
                                    if (targetOption && targetOption.asElement()) {
                                        await targetOption.asElement().click();
                                        console.log('✅ Page size set to ' + size);
                                        await new Promise(resolve => setTimeout(resolve, 3000));
                                        return;
                                    }
                                }
                            }
                        }
                        
                        console.log('⚠️  Page size selector not found, trying alternative method...');
                    } else {
                        // 點擊選擇器打開下拉菜單
                        await pageSizeSelect.click();
                        console.log('✅ Page size selector clicked');
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        // 等待下拉菜單出現並選擇目標值
                        const targetOption = await page.evaluateHandle((targetSize) => {
                            const options = Array.from(document.querySelectorAll('mat-option'));
                            return options.find(opt => {
                                const text = (opt.textContent || opt.innerText || '').trim();
                                return text === targetSize.toString() || text.includes(targetSize.toString());
                            });
                        }, size).catch(() => null);
                        
                        if (targetOption && targetOption.asElement()) {
                            await targetOption.asElement().click();
                            console.log('✅ Page size set to ' + size);
                            
                            // 等待數據重新載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        } else {
                            console.log('⚠️  Option with value ' + size + ' not found');
                            // 按 ESC 關閉下拉菜單
                            await page.keyboard.press('Escape');
                        }
                    }
                } catch (e) {
                    console.log('⚠️  Error setting page size: ' + e.message);
                    console.log('   Error stack: ' + e.stack);
                }
            }

            /**
             * ZGSLOT 手動登入流程
             * 使用 Puppeteer 打開非無頭瀏覽器，讓使用者手動輸入帳號密碼及驗證碼
             */
            async function manualLoginAndNavigate() {
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

                    console.log('🔐 Starting ZGSLOT manual login process...');
                    console.log('📝 Please manually enter your account, password, and verification code in the browser window.');
                    console.log('⏳ Waiting for you to complete the login...');
                    
                    // 導航到登入頁面
                    await page.goto($domainJs, {
                        waitUntil: 'load',
                        timeout: 60000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 截圖：初始登入頁面
                    try {
                        const screenshotPath1 = path.join(workingDir, '01_initial_login_page.png');
                        await page.screenshot({ path: screenshotPath1, fullPage: true });
                        console.log('📸 Screenshot 01: Initial login page saved at: ' + screenshotPath1);
                    } catch (e) {
                        console.log('⚠️  Error taking screenshot: ' + e.message);
                    }
                    
                    // 提示使用者開始手動輸入
                    console.log('');
                    console.log('═══════════════════════════════════════════════════════════');
                    console.log('👤 MANUAL LOGIN INSTRUCTIONS:');
                    console.log('   1. Please enter your account in the browser window');
                    console.log('   2. Please enter your password in the browser window');
                    console.log('   3. Please enter the verification code in the browser window');
                    console.log('   4. Click the login button');
                    console.log('   5. Wait for the login to complete');
                    console.log('═══════════════════════════════════════════════════════════');
                    console.log('');
                    
                    // 等待使用者完成登入
                    // 我們會監聽 URL 變化或等待特定元素出現來判斷登入是否完成
                    let loginCompleted = false;
                    let checkAttempts = 0;
                    const maxCheckAttempts = 300; // 最多等待 5 分鐘（300 * 1秒）
                    
                    console.log('⏳ Monitoring login status...');
                    
                    while (!loginCompleted && checkAttempts < maxCheckAttempts) {
                        checkAttempts++;
                        
                        // 檢查當前 URL 是否改變（表示可能已登入並跳轉）
                        const currentUrl = page.url();
                        
                        // 檢查是否出現登入成功的標誌（例如：URL 改變、特定元素出現等）
                        const loginSuccess = await page.evaluate(() => {
                            // 檢查 URL 是否不再是登入頁面
                            const url = window.location.href;
                            if (url && !url.includes('/login') && !url.includes('login')) {
                                return true;
                            }
                            
                            // 檢查是否有登入成功的元素（根據實際網站調整）
                            const successIndicators = [
                                document.querySelector('[class*="dashboard"]'),
                                document.querySelector('[class*="home"]'),
                                document.querySelector('[id*="dashboard"]'),
                                document.querySelector('[id*="home"]'),
                                document.querySelector('a[href*="logout"]'),
                                document.querySelector('button[class*="logout"]')
                            ];
                            
                            return successIndicators.some(el => el !== null);
                        });
                        
                        if (loginSuccess) {
                            loginCompleted = true;
                            console.log('✅ Login detected as completed!');
                            break;
                        }
                        
                        // 每 10 秒輸出一次提示
                        if (checkAttempts % 10 === 0) {
                            console.log('⏳ Still waiting... (checked ' + checkAttempts + ' times, max ' + maxCheckAttempts + ')');
                        }
                        
                        // 等待 1 秒後再次檢查
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                    
                    if (!loginCompleted) {
                        console.log('⚠️  Login completion not detected automatically.');
                        console.log('   Please press Enter in the terminal when you have completed the login...');
                        
                        // 使用 readline 等待使用者按 Enter
                        const rl = readline.createInterface({
                            input: process.stdin,
                            output: process.stdout
                        });
                        
                        await new Promise((resolve) => {
                            rl.question('Press Enter after you have completed the login: ', () => {
                                rl.close();
                                resolve();
                            });
                        });
                    }
                    
                    // 等待頁面穩定
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 截圖：登入完成後的頁面
                    try {
                        const screenshotPath2 = path.join(workingDir, '02_after_login.png');
                        await page.screenshot({ path: screenshotPath2, fullPage: true });
                        console.log('📸 Screenshot 02: After login saved at: ' + screenshotPath2);
                    } catch (e) {
                        console.log('⚠️  Error taking screenshot: ' + e.message);
                    }
                    
                    // 獲取登入後的 Cookies
                    const cookies = await page.cookies();
                    console.log('✅ Login completed, obtained ' + cookies.length + ' cookie(s)');

                    // 如果提供了目標 URL，導航到目標 URL
                    let targetUrlReached = false;
                    if ($urlJs && $urlJs !== '') {
                        const currentUrl = page.url();
                        const targetUrl = $urlJs.replace(/^"|"$/g, ''); // 移除 JSON 編碼的引號
                        
                        // 比較 URL（不包含協議和尾部斜線）
                        const normalizeUrl = (url) => {
                            return url.replace(/^https?:\/\//, '').replace(/\/$/, '').toLowerCase();
                        };
                        
                        if (normalizeUrl(currentUrl) !== normalizeUrl(targetUrl)) {
                            console.log('🌐 Navigating to target URL:', targetUrl);
                            try {
                                // 導航到目標 URL，等待網絡空閒（確保頁面完全載入）
                                await page.goto(targetUrl, {
                                    waitUntil: 'networkidle2', // 等待網絡空閒，確保頁面完全載入
                                    timeout: 60000
                                });
                                console.log('✅ Successfully navigated to target URL');
                                targetUrlReached = true;
                                
                                // 額外等待頁面完全渲染（動態內容可能需要時間）
                                console.log('⏳ Waiting for page to fully render...');
                                await new Promise(resolve => setTimeout(resolve, 5000));
                            } catch (e) {
                                console.log('⚠️  Error navigating to target URL: ' + e.message);
                                console.log('   Current URL: ' + page.url());
                                targetUrlReached = false;
                            }
                        } else {
                            console.log('ℹ️  Target URL is same as current URL, skipping navigation');
                            console.log('   Current URL: ' + page.url());
                            targetUrlReached = true; // URL 相同也算成功
                        }
                    } else {
                        console.log('ℹ️  No target URL provided, using current page');
                        targetUrlReached = true; // 沒有目標 URL，使用當前頁面
                    }
                    
                    // 如果提供了開始日期和結束日期，填寫日期並點擊查詢按鈕
                    const startDate = $startDateJs && $startDateJs !== 'null' ? $startDateJs.replace(/^"|"$/g, '') : null;
                    const endDate = $endDateJs && $endDateJs !== 'null' ? $endDateJs.replace(/^"|"$/g, '') : null;
                    
                    if (startDate || endDate) {
                        console.log('📅 Filling date range...');
                        console.log('   Start Date: ' + (startDate || 'Not provided'));
                        console.log('   End Date: ' + (endDate || 'Not provided'));
                        
                        try {
                            // 等待頁面完全載入
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 填寫開始日期
                            if (startDate) {
                                console.log('📝 Filling start date: ' + startDate);
                                const startDateInput = await page.waitForSelector('input#mat-input-9, input[placeholder*="结算时间 开始"], input[placeholder*="結算時間 開始"]', { timeout: 10000 }).catch(() => null);
                                
                                if (startDateInput) {
                                    // 點擊輸入框
                                    await startDateInput.click();
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    
                                    // 清空輸入框
                                    await startDateInput.click({ clickCount: 3 });
                                    await page.keyboard.press('Backspace');
                                    await new Promise(resolve => setTimeout(resolve, 200));
                                    
                                    // 輸入日期
                                    await startDateInput.type(startDate, { delay: 50 });
                                    
                                    // 觸發事件確保 Angular 檢測到變化
                                    await page.evaluate(() => {
                                        const input = document.querySelector('input#mat-input-9') || 
                                                     document.querySelector('input[placeholder*="结算时间 开始"]') ||
                                                     document.querySelector('input[placeholder*="結算時間 開始"]');
                                        if (input) {
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    });
                                    
                                    console.log('✅ Start date filled');
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                } else {
                                    console.log('⚠️  Start date input field not found');
                                }
                            }
                            
                            // 填寫結束日期
                            if (endDate) {
                                console.log('📝 Filling end date: ' + endDate);
                                const endDateInput = await page.waitForSelector('input#mat-input-10, input[placeholder*="结算时间 结束"], input[placeholder*="結算時間 結束"]', { timeout: 10000 }).catch(() => null);
                                
                                if (endDateInput) {
                                    // 點擊輸入框
                                    await endDateInput.click();
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    
                                    // 清空輸入框
                                    await endDateInput.click({ clickCount: 3 });
                                    await page.keyboard.press('Backspace');
                                    await new Promise(resolve => setTimeout(resolve, 200));
                                    
                                    // 輸入日期
                                    await endDateInput.type(endDate, { delay: 50 });
                                    
                                    // 觸發事件確保 Angular 檢測到變化
                                    await page.evaluate(() => {
                                        const input = document.querySelector('input#mat-input-10') || 
                                                     document.querySelector('input[placeholder*="结算时间 结束"]') ||
                                                     document.querySelector('input[placeholder*="結算時間 結束"]');
                                        if (input) {
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    });
                                    
                                    console.log('✅ End date filled');
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                } else {
                                    console.log('⚠️  End date input field not found');
                                }
                            }
                            
                            // 點擊查詢按鈕
                            console.log('🔍 Clicking query button...');
                            
                            // 使用 evaluate 查找包含"查询"或"查詢"文字的按鈕
                            const queryButtonFound = await page.evaluate(() => {
                                const buttons = Array.from(document.querySelectorAll('button.mat-flat-button.mat-primary, button.mat-flat-button'));
                                const button = buttons.find(btn => {
                                    const text = (btn.textContent || btn.innerText || '').trim();
                                    return text.includes('查询') || text.includes('查詢') || text === '查询' || text === '查詢';
                                });
                                
                                if (button) {
                                    // 標記按鈕以便後續選擇
                                    button.setAttribute('data-query-button', 'true');
                                    return true;
                                }
                                return false;
                            });
                            
                            if (queryButtonFound) {
                                // 使用標記選擇按鈕
                                const queryButton = await page.$('button[data-query-button="true"]');
                                if (queryButton) {
                                    await queryButton.click();
                                    console.log('✅ Query button clicked');
                                    
                                    // 等待查詢結果載入
                                    console.log('⏳ Waiting for query results...');
                                    await new Promise(resolve => setTimeout(resolve, 5000));
                                    
                                    // 設置每頁筆數為 10000
                                    await setPageSize(page, 10000);
                                } else {
                                    console.log('⚠️  Query button element not found after marking');
                                }
                            } else {
                                // 嘗試直接使用選擇器
                                const queryButtonDirect = await page.$('button.mat-flat-button.mat-primary').catch(() => null);
                                if (queryButtonDirect) {
                                    await queryButtonDirect.click();
                                    console.log('✅ Query button clicked (direct selector)');
                                    await new Promise(resolve => setTimeout(resolve, 5000));
                                    
                                    // 設置每頁筆數為 10000
                                    await setPageSize(page, 10000);
                                } else {
                                    console.log('⚠️  Query button not found');
                                }
                            }
                        } catch (e) {
                            console.log('⚠️  Error filling dates or clicking query button: ' + e.message);
                            console.log('   Error stack: ' + e.stack);
                        }
                    } else {
                        // 即使沒有填寫日期，也嘗試設置每頁筆數
                        await setPageSize(page, 10000);
                    }
                    
                    // 無論是否跳轉成功，都要截圖當前頁面
                    console.log('📸 Taking screenshot of current page...');
                    console.log('   Current URL: ' + page.url());
                    console.log('   Working directory: ' + workingDir);
                    
                    try {
                        const screenshotPath = path.join(workingDir, '03_target_page.png');
                        const screenshotViewportPath = path.join(workingDir, '03_target_page_viewport.png');
                        
                        console.log('   Full page screenshot path: ' + screenshotPath);
                        console.log('   Viewport screenshot path: ' + screenshotViewportPath);
                        
                        // 確保目錄存在
                        if (!fs.existsSync(workingDir)) {
                            fs.mkdirSync(workingDir, { recursive: true });
                            console.log('   Created working directory: ' + workingDir);
                        }
                        
                        // 截取完整頁面（PNG 不支持 quality 參數，只有 JPEG 支持）
                        await page.screenshot({ 
                            path: screenshotPath, 
                            fullPage: true
                        });
                        console.log('✅ Screenshot 03: Current page saved (full page) at: ' + screenshotPath);
                        
                        // 也截取可見區域的截圖
                        await page.screenshot({ 
                            path: screenshotViewportPath, 
                            fullPage: false
                        });
                        console.log('✅ Screenshot 03 (viewport): Current page viewport saved at: ' + screenshotViewportPath);
                        
                        // 驗證文件是否存在
                        if (fs.existsSync(screenshotPath)) {
                            const stats = fs.statSync(screenshotPath);
                            console.log('   ✅ Screenshot file exists, size: ' + stats.size + ' bytes');
                        } else {
                            console.log('   ⚠️  Screenshot file not found after saving!');
                        }
                    } catch (e) {
                        console.log('⚠️  Error taking screenshot: ' + e.message);
                        console.log('   Error stack: ' + e.stack);
                        console.log('   Working directory: ' + workingDir);
                        console.log('   Directory exists: ' + fs.existsSync(workingDir));
                    }
                            
                    // 獲取頁面標題
                    const pageTitle = await page.title();
                    
                    // 爬取 mat-table 表格數據
                    console.log('📊 Scraping mat-table data...');
                    const tableData = await page.evaluate(() => {
                        const table = document.querySelector('mat-table.mat-table');
                        if (!table) {
                            return { error: 'Table not found', rows: [] };
                        }
                        
                        // 獲取表頭
                        const headers = [];
                        const headerRow = table.querySelector('thead tr, mat-header-row');
                        if (headerRow) {
                            const headerCells = headerRow.querySelectorAll('mat-header-cell, th');
                            headerCells.forEach(cell => {
                                const text = (cell.textContent || '').trim();
                                if (text) {
                                    headers.push(text);
                                }
                            });
                        }
                        
                        // 如果沒有找到表頭，嘗試從 mat-column 屬性獲取
                        if (headers.length === 0) {
                            const firstRow = table.querySelector('mat-row, tbody tr');
                            if (firstRow) {
                                const cells = firstRow.querySelectorAll('mat-cell, td');
                                cells.forEach((cell, index) => {
                                    const columnDef = cell.getAttribute('mat-column') || 
                                                     cell.getAttribute('cdk-column') ||
                                                     ('column_' + index);
                                    headers.push(columnDef);
                                });
                            }
                        }
                        
                        // 獲取表格行數據
                        const rows = [];
                        const dataRows = table.querySelectorAll('mat-row, tbody tr');
                        
                        dataRows.forEach((row, rowIndex) => {
                            const rowData = {};
                            const cells = row.querySelectorAll('mat-cell, td');
                            
                            cells.forEach((cell, cellIndex) => {
                                const text = (cell.textContent || '').trim();
                                const header = headers[cellIndex] || ('column_' + cellIndex);
                                
                                // 獲取更多信息（如果有）
                                const columnDef = cell.getAttribute('mat-column') || 
                                                 cell.getAttribute('cdk-column') || 
                                                 header;
                                
                                rowData[columnDef] = text;
                            });
                            
                            if (Object.keys(rowData).length > 0) {
                                rows.push(rowData);
                            }
                        });
                        
                        return {
                            headers: headers,
                            rows: rows,
                            rowCount: rows.length
                        };
                    });
                    
                    console.log('✅ Table data scraped: ' + tableData.rowCount + ' rows found');
                    
                    // 返回結果
                    const result = {
                        success: true,
                        url: page.url(),
                        title: pageTitle,
                        cookies: cookies,
                        tableData: tableData,
                        targetUrlReached: targetUrlReached,
                        message: targetUrlReached ? 'Login and navigation completed successfully' : 'Login completed, but navigation to target URL was skipped or failed'
                    };
                    
                    // 保存結果
                    const resultPath = path.join(workingDir, 'scrape_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    
                    // 保存表格數據為單獨的 JSON 文件（使用與 Splus 類似的結構）
                    // 生成時間戳（格式：YYYY-MM-DD_HH-MM-SS）
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = String(now.getMonth() + 1).padStart(2, '0');
                    const day = String(now.getDate()).padStart(2, '0');
                    const hours = String(now.getHours()).padStart(2, '0');
                    const minutes = String(now.getMinutes()).padStart(2, '0');
                    const seconds = String(now.getSeconds()).padStart(2, '0');
                    const timestamp = year + '-' + month + '-' + day + '_' + hours + '-' + minutes + '-' + seconds;
                    
                    const tableDataFile = {
                        metadata: {
                            timestamp: timestamp,
                            url: page.url(),
                            dateStart: startDate || null,
                            dateEnd: endDate || null,
                            totalPages: 1,
                            totalRows: tableData.rowCount || 0,
                            headers: tableData.headers || [],
                            source: 'DOM'
                        },
                        headers: tableData.headers || [],
                        totalPages: 1,
                        totalRows: tableData.rowCount || 0,
                        data: tableData.rows || []
                    };
                    
                    const tableDataPath = path.join(workingDir, 'table_data.json');
                    fs.writeFileSync(tableDataPath, JSON.stringify(tableDataFile, null, 2));
                    console.log('💾 Table data saved to: ' + tableDataPath);
                    
                    console.log('');
                    console.log('═══════════════════════════════════════════════════════════');
                    console.log('✅ SCRAPING COMPLETED!');
                    console.log('   URL: ' + page.url());
                    console.log('   Title: ' + pageTitle);
                    console.log('   Table Rows: ' + (tableData.rowCount || 0));
                    console.log('   Table Headers: ' + (tableData.headers ? tableData.headers.length : 0));
                    console.log('   Cookies: ' + cookies.length + ' cookie(s)');
                    console.log('   Target URL Reached: ' + (targetUrlReached ? 'Yes' : 'No'));
                    console.log('═══════════════════════════════════════════════════════════');
                    console.log('');
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
                    fs.writeFileSync('scrape_result.json', JSON.stringify(errorResult, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    throw error;
                }
            }

            manualLoginAndNavigate().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_zgslot_manual.js');
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
        $this->info('   A browser window will open. Please complete the login manually.');
        
        $workingDir = dirname($scriptPath);
        // 增加超時時間到 10 分鐘（600秒），因為使用者可能需要時間手動輸入
        $this->info('⏳ Script timeout set to 10 minutes (you may need time to enter login info)...');
        
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
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('');
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        // 處理截圖文件
        $this->processScreenshots($workingDir, $timestamp);
        
        // 處理表格數據文件
        $this->processTableData($workingDir, $timestamp);

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('📄 Title: ' . ($result['title'] ?? 'N/A'));
            
            // 顯示表格數據信息
            if (isset($result['tableData'])) {
                $tableData = $result['tableData'];
                $this->info('📊 Table Rows: ' . ($tableData['rowCount'] ?? 0));
                $this->info('📋 Table Headers: ' . (isset($tableData['headers']) ? count($tableData['headers']) : 0));
                if (isset($tableData['error'])) {
                    $this->warn('⚠️  Table Error: ' . $tableData['error']);
                }
            } else {
                $this->warn('⚠️  No table data found');
            }
            
            $this->info('🍪 Cookies: ' . (count($result['cookies'] ?? []) . ' cookie(s)'));
            
            if (isset($result['error'])) {
                $this->warn('⚠️  Warning: ' . $result['error']);
            }
        } else {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('');
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
            '02_after_login.png',
            '03_target_page.png',
            '03_target_page_viewport.png',
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
                $destFilename = "zgslot_manual_{$timestamp}_{$pattern}";
                $destPath = $screenshotsDir . '/' . $destFilename;
                if (rename($srcPath, $destPath)) {
                    $screenshotCount++;
                    $this->line("   ✅ {$destFilename}");
                }
            }
        }
        
        if ($screenshotCount > 0) {
            $this->info("✅ {$screenshotCount} screenshot(s) saved to: {$screenshotsDir}");
        } else {
            $this->warn('⚠️  No screenshots found');
        }
    }

    /**
     * 處理表格數據文件，將它從臨時目錄移動到永久儲存目錄
     * @param string $workingDir 工作目錄（臨時目錄）
     * @param string $timestamp 時間戳
     */
    private function processTableData($workingDir, $timestamp)
    {
        $this->info('📊 Processing table data...');
        
        $tableDataFile = $workingDir . '/table_data.json';
        if (file_exists($tableDataFile)) {
            $dataDir = storage_path('app/scraped_data');
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            
            // 讀取原始數據
            $content = file_get_contents($tableDataFile);
            $rawData = json_decode($content, true);
            
            // 重新組織數據結構（與 Splus 保持一致）
            $processedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $rawData['metadata']['url'] ?? '',
                    'dateStart' => $rawData['metadata']['dateStart'] ?? null,
                    'dateEnd' => $rawData['metadata']['dateEnd'] ?? null,
                    'totalPages' => $rawData['totalPages'] ?? 1,
                    'totalRows' => $rawData['totalRows'] ?? 0,
                    'headers' => $rawData['headers'] ?? [],
                    'source' => 'DOM'
                ],
                'headers' => $rawData['headers'] ?? [],
                'totalPages' => $rawData['totalPages'] ?? 1,
                'totalRows' => $rawData['totalRows'] ?? 0,
                'data' => $rawData['data'] ?? []
            ];
            
            // 保存處理後的數據
            $destFilename = "zgslot_manual_{$timestamp}_table_data.json";
            $destPath = $dataDir . '/' . $destFilename;
            
            file_put_contents($destPath, json_encode($processedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $this->info("✅ Table data saved to: {$destPath}");
            
            // 顯示表格數據摘要
            if (isset($processedData['totalRows'])) {
                $this->line("   📋 Total Rows: {$processedData['totalRows']}");
                $this->line("   📄 Total Pages: {$processedData['totalPages']}");
                if (isset($processedData['headers']) && count($processedData['headers']) > 0) {
                    $headerPreview = implode(', ', array_slice($processedData['headers'], 0, 5));
                    if (count($processedData['headers']) > 5) {
                        $headerPreview .= '...';
                    }
                    $this->line("   📑 Headers (" . count($processedData['headers']) . "): {$headerPreview}");
                }
            }
        } else {
            $this->warn('⚠️  Table data file not found');
        }
    }
}

