<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserRsgDOM extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-dom {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {account_number?} - 要點擊的帳號號碼（可選參數）
     */
    protected $signature = 'agent:scrape-rsg-dom-detail {url} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from RSG DOM elements using browser automation (optimized for DataTables AJAX pagination)';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');

        $this->info('=== Browser DOM Scraper (Optimized for DataTables) ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$dateStart}");
        $this->info("Date End: {$dateEnd}");
        $this->info("Account Number: {$accountNumber}");

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $dateStart, $dateEnd, $accountNumber);

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
     * 創建 Puppeteer 自動化腳本（從 DOM 提取資料）
     * @param string $url 要爬取的目標網址
     * @param string|null $dateStart 要選擇的開始日期（可選）
     * @param string|null $dateEnd 要選擇的結束日期（可選）
     * @param string|null $accountNumber 要點擊的帳號號碼（可選）
     * @return string 返回生成的腳本文件路徑
    */
    private function createPuppeteerScript($url, $dateStart = null, $dateEnd = null, $accountNumber = null)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段
        $cookiesCode = $this->generateRsgPuppeteerCookiesCode('page');

        // 將 account_number 轉換為 JavaScript 可用的格式
        // 使用 json_encode 確保正確的 JSON 格式，null 值會輸出為字符串 'null'
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';

        // 將 date 轉換為 JavaScript 可用的格式
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容（優化版本）
             * 針對 DataTables AJAX 分頁系統優化，使用串行爬取以獲得最佳性能
             */
            async function scrapeDOMContent() {
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

                    // 攔截並阻止不必要的資源載入（大幅提升速度）
                    await page.setRequestInterception(true);
                    page.on('request', (req) => {
                        const resourceType = req.resourceType();
                        // 只阻止圖片、字體、媒體檔案，保留 CSS 和 JS 以確保分頁功能正常
                        if (['image', 'font', 'media'].includes(resourceType)) {
                            req.abort();
                        } else {
                            req.continue();
                        }
                    });

                    $cookiesCode

                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            // console.log('❌ Browser console error:', msg.text());
                        }
                    });

                    console.log('🌐 Navigating to:', '$url');

                    // 導航到目標頁面
                    // 使用 'domcontentloaded' 替代 'networkidle2' 加快載入速度
                    // timeout: 30000 設定 30 秒超時
                    await page.goto('$url', {
                        waitUntil: 'domcontentloaded',
                        timeout: 30000
                    });

                    // 等待頁面穩定
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // ========== 步驟 1：處理日期範圍選擇（優先執行）==========
                    // 如果提供了 date_start 和 date_end，先填入日期
                    const dateStartProvided = $dateStartJs && $dateStartJs !== 'null';
                    const dateEndProvided = $dateEndJs && $dateEndJs !== 'null';
                    
                    if (dateStartProvided && dateEndProvided) {
                            try {                                
                                // 查找 reservation 欄位
                                const reservationField = await page.evaluate(() => {
                                    const element = document.querySelector('[id*="reservation"]');
                                    if (element) {
                                    return { found: true, id: element.id, value: element.value };
                                    }
                                    return { found: false };
                                });
                                
                                if (reservationField.found) {
                                    // 點擊 reservation 欄位以打開日期選擇器
                                    await page.evaluate(() => {
                                        const element = document.querySelector('[id*="reservation"]');
                                        if (element) {
                                            element.click();
                                            element.focus();
                                        }
                                    });
                                    
                                    // 等待日期選擇器出現
                                await new Promise(resolve => setTimeout(resolve, 1500));
                                
                                // 查找並點擊「Customize」選項
                                        const customizeClicked = await page.evaluate(() => {
                                            let dropdown = document.querySelector('.ranges');
                                            if (dropdown) {
                                                // 嘗試多個可能的選項名稱
                                                const possibleKeys = ['Custom Range', 'Customize', 'Custom', '自訂範圍', '自定义范围'];
                                                
                                                for (const key of possibleKeys) {
                                                    const customizeByAttr = dropdown.querySelector('[data-range-key="' + key + '"]');
                                                    if (customizeByAttr) {
                                                        customizeByAttr.click();
                                                        return { clicked: true, text: customizeByAttr.textContent, key: key };
                                                    }
                                                }
                                                
                                                // 如果沒有找到，嘗試通過文本內容查找
                                                const allOptions = Array.from(dropdown.querySelectorAll('li'));
                                                for (const option of allOptions) {
                                                    const text = option.textContent.trim().toLowerCase();
                                                    if (text.includes('custom') || text.includes('自訂') || text.includes('自定义')) {
                                                        option.click();
                                                        return { clicked: true, text: option.textContent.trim(), method: 'text-match' };
                                                    }
                                                }
                                            }
                                            return { clicked: false };
                                        });
                                        
                                        if (customizeClicked.clicked) {
                                            // 等待自定義日期輸入框出現
                                    await new Promise(resolve => setTimeout(resolve, 1500));
                                            
                                    // 先嘗試使用 daterangepicker API（最可靠的方法）
                                            const dateSetResult = await page.evaluate((dateStart, dateEnd) => {
                                                try {
                                            // 方法 1: 使用 daterangepicker 的 setStartDate 和 setEndDate API（優先使用 moment.js 對象）
                                                    const reservationInput = document.querySelector('[id*="reservation"]');
                                            if (reservationInput) {
                                                // 嘗試使用 moment.js（如果可用）
                                                if (typeof window.moment !== 'undefined') {
                                                    try {
                                                        const startMoment = window.moment(dateStart, 'YYYY-MM-DD');
                                                        const endMoment = window.moment(dateEnd, 'YYYY-MM-DD');
                                                        
                                                        if (reservationInput.daterangepicker) {
                                                            reservationInput.daterangepicker.setStartDate(startMoment);
                                                            reservationInput.daterangepicker.setEndDate(endMoment);
                                                            return { method: 'api-moment', success: true };
                                                        }
                                                        
                                                        // 通過 jQuery
                                                        if (typeof window.$ !== 'undefined') {
                                                            const jQueryReservation = window.$('[id*="reservation"]');
                                                            if (jQueryReservation.length > 0 && jQueryReservation.data('daterangepicker')) {
                                                                jQueryReservation.data('daterangepicker').setStartDate(startMoment);
                                                                jQueryReservation.data('daterangepicker').setEndDate(endMoment);
                                                                return { method: 'jquery-moment', success: true };
                                                            }
                                                        }
                                                    } catch (e) {
                                                        // moment 失敗，繼續嘗試其他方法
                                                    }
                                                }
                                                
                                                // 方法 2: 使用字符串格式的 API
                                                if (reservationInput.daterangepicker) {
                                                        reservationInput.daterangepicker.setStartDate(dateStart);
                                                        reservationInput.daterangepicker.setEndDate(dateEnd);
                                                    return { method: 'api-string', success: true };
                                                    }
                                                    
                                                // 方法 3: 通過 jQuery 使用字符串
                                                    if (typeof window.$ !== 'undefined') {
                                                        const jQueryReservation = window.$('[id*="reservation"]');
                                                        if (jQueryReservation.length > 0 && jQueryReservation.data('daterangepicker')) {
                                                            jQueryReservation.data('daterangepicker').setStartDate(dateStart);
                                                            jQueryReservation.data('daterangepicker').setEndDate(dateEnd);
                                                        return { method: 'jquery-string', success: true };
                                                    }
                                                }
                                            }
                                            
                                            return { success: false, reason: 'API methods not available' };
                                        } catch (e) {
                                            return { success: false, error: e.message };
                                        }
                                    }, $dateStartJs.replace(/"/g, ''), $dateEndJs.replace(/"/g, ''));
                                    
                                    // 如果 API 方法失敗，使用 Puppeteer 的 type 方法直接填入
                                    if (!dateSetResult.success) {
                                        // 查找日期輸入框
                                        const inputSelectors = [
                                            'input[name="daterangepicker_start"]',
                                            '.daterangepicker input[name="daterangepicker_start"]',
                                            '.daterangepicker .calendar.left input',
                                            '.daterangepicker .calendar.right input'
                                        ];
                                        
                                        let startInputSelector = null;
                                        let endInputSelector = null;
                                        
                                        for (const selector of inputSelectors) {
                                            try {
                                                const exists = await page.$(selector);
                                                if (exists) {
                                                    if (!startInputSelector) {
                                                        startInputSelector = selector;
                                                    } else if (!endInputSelector) {
                                                        endInputSelector = selector;
                                                        break;
                                                    }
                                                }
                                            } catch (e) {}
                                        }
                                        
                                        // 如果找不到 start，嘗試查找 end
                                        if (!endInputSelector) {
                                            const endSelectors = [
                                                'input[name="daterangepicker_end"]',
                                                '.daterangepicker input[name="daterangepicker_end"]',
                                                '.daterangepicker .calendar.right input'
                                            ];
                                            for (const selector of endSelectors) {
                                                try {
                                                    const exists = await page.$(selector);
                                                    if (exists) {
                                                        endInputSelector = selector;
                                                        break;
                                                    }
                                                } catch (e) {}
                                            }
                                        }
                                        
                                        if (startInputSelector && endInputSelector) {
                                            try {
                                                // 獲取輸入框元素
                                                const startInput = await page.$(startInputSelector);
                                                const endInput = await page.$(endInputSelector);
                                                
                                                if (startInput && endInput) {
                                                    // 方法 1: 使用 fill 方法（如果可用）
                                                    try {
                                                        // 先聚焦並選中所有文本
                                                        await startInput.click({ clickCount: 3 });
                                                        await startInput.type($dateStartJs.replace(/"/g, ''), { delay: 30 });
                                                        
                                                        await endInput.click({ clickCount: 3 });
                                                        await endInput.type($dateEndJs.replace(/"/g, ''), { delay: 30 });
                                                } catch (e) {
                                                        // 如果 type 失敗，使用 evaluate 直接設置
                                                        await page.evaluate((startSel, endSel, dateStart, dateEnd) => {
                                                            const startEl = document.querySelector(startSel);
                                                            const endEl = document.querySelector(endSel);
                                                            if (startEl) {
                                                                startEl.focus();
                                                                startEl.value = '';
                                                                startEl.value = dateStart;
                                                                startEl.dispatchEvent(new Event('input', { bubbles: true }));
                                                                startEl.dispatchEvent(new Event('change', { bubbles: true }));
                                                                if (window.$) window.$(startEl).trigger('input').trigger('change');
                                                            }
                                                            if (endEl) {
                                                                endEl.focus();
                                                                endEl.value = '';
                                                                endEl.value = dateEnd;
                                                                endEl.dispatchEvent(new Event('input', { bubbles: true }));
                                                                endEl.dispatchEvent(new Event('change', { bubbles: true }));
                                                                if (window.$) window.$(endEl).trigger('input').trigger('change');
                                                            }
                                                        }, startInputSelector, endInputSelector, $dateStartJs.replace(/"/g, ''), $dateEndJs.replace(/"/g, ''));
                                                    }
                                                }
                                            } catch (e) {
                                                console.log('⚠️  Error typing dates:', e.message);
                                            }
                                        } else {
                                            console.log('⚠️  Could not find date input selectors');
                                        }
                                    }
                                            
                                            // 等待一下，確保值已填入
                                    await new Promise(resolve => setTimeout(resolve, 1500));
                                    
                                    // 驗證日期是否真的被填入了
                                    const dateVerification = await page.evaluate((expectedStart, expectedEnd) => {
                                        const startInput = document.querySelector('input[name="daterangepicker_start"]') ||
                                                          document.querySelector('.daterangepicker input[name="daterangepicker_start"]');
                                        const endInput = document.querySelector('input[name="daterangepicker_end"]') ||
                                                        document.querySelector('.daterangepicker input[name="daterangepicker_end"]');
                                        
                                        const actualStart = startInput ? startInput.value : null;
                                        const actualEnd = endInput ? endInput.value : null;
                                        
                                        return {
                                            expectedStart: expectedStart,
                                            expectedEnd: expectedEnd,
                                            actualStart: actualStart,
                                            actualEnd: actualEnd,
                                            startMatch: actualStart === expectedStart,
                                            endMatch: actualEnd === expectedEnd,
                                            bothMatch: (actualStart === expectedStart && actualEnd === expectedEnd)
                                        };
                                    }, $dateStartJs.replace(/"/g, ''), $dateEndJs.replace(/"/g, ''));
                                            
                                            // 點擊 Apply 按鈕
                                            const applyResult = await page.evaluate(() => {
                                                const applyBtn = document.querySelector('.applyBtn') || 
                                                              document.querySelector('button.btn-success') ||
                                                              document.querySelector('button[type="button"].btn.btn-sm.btn-success') ||
                                                              document.querySelector('.daterangepicker button.applyBtn');
                                                if (applyBtn) {
                                                    applyBtn.click();
                                                    return { clicked: true, text: applyBtn.textContent };
                                                }
                                                return { clicked: false, reason: 'Apply button not found' };
                                            });
                                            
                                            // 等待日期選擇器關閉和數據載入
                                            await new Promise(resolve => setTimeout(resolve, 3000));
                                }
                            }
                        } catch (e) {
                            console.log('⚠️  Error handling date range selection: ' + e.message);
                            console.log('⚠️  Error stack:', e.stack);
                                        }
                                    } else {
                        console.log('📅 No date parameters provided, skipping date selection');
                    }
                    
                    // ========== 步驟 2：處理帳號號碼（在日期處理後）==========
                    // 解析帳號號碼（從 PHP 變量插值）
                    let accountNumber = null;
                    try {
                        // 嘗試解析 JSON（如果 $accountNumberJs 是 JSON 字符串）
                        if ($accountNumberJs && $accountNumberJs !== 'null' && $accountNumberJs !== '') {
                            accountNumber = JSON.parse($accountNumberJs);
                        }
                    } catch (e) {
                        // 如果不是 JSON，直接使用原始值
                        accountNumber = $accountNumberJs && $accountNumberJs !== 'null' ? $accountNumberJs : null;
                    }
                    
                    console.log('🔍 Account number to fill:', accountNumber);
                    
                    if (accountNumber && accountNumber !== null && accountNumber !== '') {
                        // 等待頁面穩定（日期處理後可能需要等待數據載入）
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        // 等待 tbody.dataContent 元素出現（如果存在）
                        try {
                            await page.waitForSelector('tbody.dataContent', { timeout: 10000 });
                        } catch (e) {
                            console.log('ℹ️  tbody.dataContent not found, continuing account search...');
                        }
                        
                        // 額外等待一下，確保表格數據已載入
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        try {
                            // 步驟 1: 點擊 "Designated account" 按鈕
                            const designatedAccountButton = await page.evaluate(() => {
                                // 查找按鈕
                                const button = document.querySelector('button.btn.btn-warning[onclick*="openSearchAccountModal"]');
                                if (button) {
                                    return {
                                        found: true,
                                        text: button.textContent.trim(),
                                        onclick: button.getAttribute('onclick')
                                    };
                                }
                                // 如果找不到，嘗試其他可能的選擇器
                                const buttons = Array.from(document.querySelectorAll('button.btn.btn-warning'));
                                for (const btn of buttons) {
                                    const text = btn.textContent.trim();
                                    if (text.includes('Designated account') || text.includes('account')) {
                                        return {
                                            found: true,
                                            text: text,
                                            onclick: btn.getAttribute('onclick')
                                        };
                                    }
                                }
                                return { found: false, reason: 'Button not found' };
                            });
                            
                            if (designatedAccountButton.found) {
                                console.log('✅ Found "Designated account" button, clicking...');
                                // 點擊按鈕
                                try {
                                    await page.evaluate(() => {
                                        const button = document.querySelector('button.btn.btn-warning[onclick*="openSearchAccountModal"]') ||
                                                      Array.from(document.querySelectorAll('button.btn.btn-warning')).find(btn => 
                                                          btn.textContent.trim().includes('Designated account') || 
                                                          btn.textContent.trim().includes('account')
                                                      );
                                        if (button) {
                                            button.click();
                                        }
                                    });
                                    
                                    // 等待彈窗出現（增加等待時間）
                                    await new Promise(resolve => setTimeout(resolve, 2500));
                                } catch (e) {
                                    console.log('⚠️  Error clicking "Designated account" button: ' + e.message);
                                }
                            } else {
                                console.log('⚠️  "Designated account" button not found: ' + (designatedAccountButton.reason || 'Unknown'));
                            }
                            
                            // 步驟 2: 等待輸入框出現並填入帳號
                            let accountFilled = false;
                            let retryCount = 0;
                            const maxRetries = 3;
                            
                            while (!accountFilled && retryCount < maxRetries) {
                                try {
                                    // 等待輸入框出現
                                    await page.waitForSelector('input#account[name="account"]', { timeout: 10000 });
                                    
                                    // 先清空輸入框
                                    await page.evaluate(() => {
                                        const input = document.querySelector('input#account[name="account"]');
                                        if (input) {
                                            input.focus();
                                            input.value = '';
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                        }
                                    });
                                    
                                    // 使用 Puppeteer 的 type 方法（更可靠）
                                    try {
                                        const accountInput = await page.$('input#account[name="account"]');
                                        if (accountInput) {
                                            await accountInput.click({ clickCount: 3 }); // 選中所有文本
                                            await accountInput.type(String(accountNumber), { delay: 50 });
                                            console.log('📝 Typed account number using Puppeteer type method');
                                        }
                                    } catch (e) {
                                        console.log('⚠️  Error typing account number with Puppeteer: ' + e.message);
                                    }
                                    
                                    // 也使用 evaluate 方法設置值（雙重保險）
                                    await page.evaluate((accountNum) => {
                                        const input = document.querySelector('input#account[name="account"]');
                                        if (input) {
                                            input.focus();
                                            input.value = String(accountNum);
                                            // 觸發事件
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('keyup', { bubbles: true }));
                                            if (window.$) {
                                                window.$(input).trigger('input').trigger('change').trigger('keyup');
                                            }
                                        }
                                    }, accountNumber);
                                    
                                    // 等待一下讓值設置完成
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    
                                    // 驗證帳號是否已填入
                                    const accountValue = await page.evaluate(() => {
                                        const input = document.querySelector('input#account[name="account"]');
                                        return input ? input.value : null;
                                    });
                                    
                                    console.log('🔍 Account value after filling:', accountValue);
                                    console.log('🔍 Expected account value:', accountNumber);
                                    
                                    if (accountValue === String(accountNumber)) {
                                        console.log('✅ Account number successfully filled!');
                                        accountFilled = true;
                                    } else {
                                        retryCount++;
                                        console.log('⚠️  Account value mismatch. Retry ' + retryCount + '/' + maxRetries);
                                        if (retryCount < maxRetries) {
                                            await new Promise(resolve => setTimeout(resolve, 1000));
                                        }
                                    }
                                } catch (e) {
                                    console.log('⚠️  Error finding or filling account input field: ' + e.message);
                                    retryCount++;
                                    
                                    if (retryCount < maxRetries) {
                                        // 嘗試其他選擇器
                                        const alternativeSelectors = [
                                            'input#account',
                                            'input[name="account"]',
                                            'input[placeholder*="account"]',
                                            'input.form-control[placeholder*="account"]',
                                            '#account',
                                            '[name="account"]'
                                        ];
                                        
                                        let inputFound = false;
                                        for (const selector of alternativeSelectors) {
                                            try {
                                                const input = await page.$(selector);
                                                if (input) {
                                                    await input.click({ clickCount: 3 });
                                                    await input.type(String(accountNumber), { delay: 50 });
                                                    inputFound = true;
                                                    console.log('✅ Found input using alternative selector: ' + selector);
                                                    
                                                    // 驗證
                                                    await new Promise(resolve => setTimeout(resolve, 500));
                                                    const verifyValue = await page.evaluate((sel) => {
                                                        const inp = document.querySelector(sel);
                                                        return inp ? inp.value : null;
                                                    }, selector);
                                                    
                                                    if (verifyValue === String(accountNumber)) {
                                                        accountFilled = true;
                                                        break;
                                                    }
                                                }
                                            } catch (e) {
                                                // 繼續嘗試下一個選擇器
                                            }
                                        }
                                        
                                        if (!inputFound && retryCount >= maxRetries) {
                                            console.log('⚠️  Could not find account input field with any selector after ' + maxRetries + ' retries');
                                        }
                                    }
                                }
                            }
                            
                            if (!accountFilled) {
                                console.log('❌ Failed to fill account number after ' + maxRetries + ' retries');
                            }
                            
                            // 步驟 3: 點擊搜索按鈕
                            try {
                                // 等待搜索按鈕出現
                                await page.waitForSelector('input.btn.bg-aqua[value="Search"][onclick*="searchAccount"]', { timeout: 5000 });
                                
                                // 點擊搜索按鈕
                                await page.evaluate(() => {
                                    const searchBtn = document.querySelector('input.btn.bg-aqua[value="Search"][onclick*="searchAccount"]') ||
                                                      document.querySelector('input.btn.bg-aqua[value="Search"]') ||
                                                      Array.from(document.querySelectorAll('input.btn.bg-aqua')).find(btn => 
                                                          btn.value === 'Search' || btn.getAttribute('onclick')?.includes('searchAccount')
                                                      );
                                    if (searchBtn) {
                                        searchBtn.click();
                                    }
                                });
                                
                                // 等待搜索結果載入
                                            await new Promise(resolve => setTimeout(resolve, 3000));
                            } catch (e) {
                                console.log('⚠️  Error finding or clicking Search button: ' + e.message);
                                
                                // 嘗試直接執行 searchAccount 函數
                                try {
                                    await page.evaluate((accountNum) => {
                                        if (typeof window.searchAccount === 'function') {
                                            window.searchAccount();
                                        } else if (typeof searchAccount === 'function') {
                                            searchAccount();
                                        }
                                    }, accountNumber);
                                    
                                    // 等待搜索結果載入
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                } catch (e2) {
                                    console.log('⚠️  Error executing searchAccount function: ' + e2.message);
                                }
                            }
                            
                            // 等待頁面穩定
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                        } catch (e) {
                            console.log('⚠️  Error in account search process: ' + e.message);
                            console.log('⚠️  Error stack:', e.stack);
                        }
                                        } else {
                        console.log('ℹ️  No account number provided, skipping account search');
                    }
                    
                    // ========== 步驟 3：點擊 Currency 連結（在日期和帳號處理後）==========
                    // 點擊 tbody.dataContent 中的 Currency 超連結
                    try {
                        // 等待 tbody.dataContent 元素出現
                        try {
                            await page.waitForSelector('tbody.dataContent', { timeout: 15000 });
                        } catch (e) {
                            console.log('⚠️  tbody.dataContent not found, trying to continue...');
                        }
                        
                        // 額外等待一下，確保表格數據已載入
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        
                        // 先檢查頁面狀態
                        const pageStatus = await page.evaluate(() => {
                            const dataContent = document.querySelector('tbody.dataContent');
                            const thead = document.querySelector('thead');
                            const allTables = document.querySelectorAll('table');
                            return {
                                hasDataContent: !!dataContent,
                                hasThead: !!thead,
                                tableCount: allTables.length,
                                dataContentRows: dataContent ? dataContent.querySelectorAll('tr').length : 0
                            };
                        });
                        
                        // 尋找 tbody.dataContent 中的超連結
                        const currencyLinkInfo = await page.evaluate(() => {
                            // 初始化 Currency 索引
                            let currencyColumnIndex = -1;
                            
                            // 檢查 thead 是否存在
                            const thead = document.querySelector('thead');
                            if (thead) {
                                const headerRow = thead.querySelector('tr');
                                if (headerRow) {
                                    // 查找表頭的第一行中的所有單元格
                                    const headers = Array.from(headerRow.querySelectorAll('th, td'));
                                    // 找到 Currency 的索引
                                    currencyColumnIndex = headers.findIndex(cell => {
                                        const text = cell.textContent.trim();
                                        return text === 'Currency';
                                    });
                                }
                            }
                            
                            // 查找所有行
                            const dataContent = document.querySelector('tbody.dataContent');
                            if (!dataContent) {
                                return { found: false, reason: 'tbody.dataContent not found' };
                            }
                            
                            const rows = Array.from(dataContent.querySelectorAll('tr'));
                            
                            // 如果找不到表頭，嘗試從第一行推斷
                            if (currencyColumnIndex === -1 && rows.length > 0) {
                                const firstRowCells = Array.from(rows[0].querySelectorAll('td'));
                                currencyColumnIndex = firstRowCells.findIndex(cell => {
                                    const text = cell.textContent.trim();
                                    return text.includes('Currency');
                                });
                            }
                            
                            // 如果還是找不到，嘗試查找所有包含 "Currency" 文本的元素
                            if (currencyColumnIndex === -1) {
                                // 查找所有包含 "Currency" 的單元格
                                for (let rowIdx = 0; rowIdx < rows.length; rowIdx++) {
                                    const cells = Array.from(rows[rowIdx].querySelectorAll('td'));
                                    for (let cellIdx = 0; cellIdx < cells.length; cellIdx++) {
                                        const cellText = cells[cellIdx].textContent.trim();
                                        if (cellText === 'Currency' || cellText.includes('Currency')) {
                                            currencyColumnIndex = cellIdx;
                                            break;
                                        }
                                    }
                                    if (currencyColumnIndex !== -1) break;
                                }
                            }
                            
                            // 如果找到 Currency，查找該列中的第一個超連結
                            if (currencyColumnIndex !== -1) {
                                // 調試：檢查該列的所有內容
                                const columnDebug = [];
                                for (let rowIdx = 0; rowIdx < rows.length; rowIdx++) {
                                    const cells = Array.from(rows[rowIdx].querySelectorAll('td'));
                                    if (cells[currencyColumnIndex]) {
                                        const cell = cells[currencyColumnIndex];
                                        const cellText = cell.textContent.trim();
                                        const cellHTML = cell.innerHTML.trim();
                                        const hasULink = cell.querySelector('u[onclick]');
                                        const hasALink = cell.querySelector('a');
                                        const hasU = cell.querySelector('u');
                                        const hasClickable = cell.querySelector('[onclick]');
                                        
                                        columnDebug.push({
                                            rowIndex: rowIdx,
                                            text: cellText,
                                            hasULink: !!hasULink,
                                            hasALink: !!hasALink,
                                            hasU: !!hasU,
                                            hasClickable: !!hasClickable,
                                            html: cellHTML.substring(0, 100) // 只取前100字符
                                        });
                                        
                                        // 優先查找 <u> 標籤（帶有 onclick 的）
                                        if (hasULink) {
                                            return {
                                                found: true,
                                                text: hasULink.textContent.trim(),
                                                columnIndex: currencyColumnIndex,
                                                isUTag: true,
                                                onclick: hasULink.getAttribute('onclick'),
                                                rowIndex: rowIdx
                                            };
                                        }
                                        
                                        // 查找任何 <u> 標籤（即使沒有 onclick）
                                        if (hasU) {
                                            return {
                                                found: true,
                                                text: hasU.textContent.trim(),
                                                columnIndex: currencyColumnIndex,
                                                isUTag: true,
                                                onclick: hasU.getAttribute('onclick') || null,
                                                rowIndex: rowIdx
                                            };
                                        }
                                        
                                        // 如果沒有 <u> 標籤，查找 <a> 標籤
                                        if (hasALink) {
                                            return {
                                                found: true,
                                                text: hasALink.textContent.trim(),
                                                columnIndex: currencyColumnIndex,
                                                isUTag: false,
                                                href: hasALink.getAttribute('href'),
                                                rowIndex: rowIdx
                                            };
                                        }
                                        
                                        // 查找任何可點擊的元素
                                        if (hasClickable) {
                                            const clickable = cell.querySelector('[onclick]');
                                            return {
                                                found: true,
                                                text: clickable.textContent.trim(),
                                                columnIndex: currencyColumnIndex,
                                                isUTag: clickable.tagName === 'U',
                                                onclick: clickable.getAttribute('onclick'),
                                                rowIndex: rowIdx
                                            };
                                        }
                                    }
                                }
                                
                                // 如果還是找不到，返回調試信息
                                return { 
                                    found: false, 
                                    reason: 'No clickable element found in Currency column', 
                                    columnIndex: currencyColumnIndex,
                                    debug: columnDebug
                                };
                            }
                            
                            // 如果還是找不到，嘗試直接查找所有包含 "Currency" 的連結
                            const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                            for (const uLink of allULinks) {
                                const text = uLink.textContent.trim();
                                // 如果連結文本看起來像貨幣代碼（通常是 3 個大寫字母）
                                if (text.length === 3 && text === text.toUpperCase() && /^[A-Z]+$/.test(text)) {
                                    // 檢查是否在 tbody.dataContent 中
                                    const parentRow = uLink.closest('tr');
                                    if (parentRow && parentRow.closest('tbody.dataContent')) {
                                        return {
                                            found: true,
                                            text: text,
                                            columnIndex: -1, // 未知列索引
                                            isUTag: true,
                                            onclick: uLink.getAttribute('onclick'),
                                            method: 'direct-search'
                                        };
                                    }
                                }
                            }
                            
                            return { found: false, reason: 'No link found in tbody.dataContent', columnIndex: currencyColumnIndex };
                        });
                        
                        if (currencyLinkInfo.found) {
                            // 點擊超連結，並等待可能的導航
                            try {
                                if (currencyLinkInfo.method === 'direct-search') {
                                    // 直接搜索找到的情況，使用 onclick 屬性
                                    if (currencyLinkInfo.onclick) {
                                        await page.evaluate((onclickValue) => {
                                            // 執行 onclick 函數
                                            eval(onclickValue);
                                        }, currencyLinkInfo.onclick);
                                } else {
                                        // 如果沒有 onclick，嘗試直接查找並點擊
                                        await page.evaluate((linkText) => {
                                            const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                                            for (const uLink of allULinks) {
                                                if (uLink.textContent.trim() === linkText) {
                                                    uLink.click();
                                                    return;
                                                }
                                            }
                                        }, currencyLinkInfo.text);
                                    }
                                } else {
                                    // 使用列索引的方式
                                    const clickResult = await page.evaluate((columnIndex, isUTag, rowIndex) => {
                                        const dataContent = document.querySelector('tbody.dataContent');
                                        if (!dataContent) return { success: false, reason: 'No dataContent' };
                                        
                                        const rows = Array.from(dataContent.querySelectorAll('tr'));
                                        if (columnIndex !== -1) {
                                            // 如果指定了行索引，只檢查該行
                                            if (rowIndex !== undefined && rowIndex >= 0 && rowIndex < rows.length) {
                                                const row = rows[rowIndex];
                                                const cells = Array.from(row.querySelectorAll('td'));
                                                if (cells[columnIndex]) {
                                                    const cell = cells[columnIndex];
                                                    
                                                    // 優先查找 <u> 標籤
                                                    if (isUTag) {
                                                        const uLink = cell.querySelector('u[onclick]') || cell.querySelector('u');
                                                        if (uLink) {
                                                            uLink.click();
                                                            return { success: true, method: 'u-tag-click', text: uLink.textContent.trim() };
                                                        }
                                                    }
                                                    
                                                    // 查找 <a> 標籤
                                                    const aLink = cell.querySelector('a');
                                                    if (aLink) {
                                                        aLink.click();
                                                        return { success: true, method: 'a-tag-click', text: aLink.textContent.trim() };
                                                    }
                                                    
                                                    // 查找任何可點擊的元素
                                                    const clickable = cell.querySelector('[onclick]');
                                                    if (clickable) {
                                                        clickable.click();
                                                        return { success: true, method: 'onclick-click', text: clickable.textContent.trim() };
                                                    }
                                                    
                                                    // 如果都沒有，嘗試點擊整個單元格
                                                    cell.click();
                                                    return { success: true, method: 'cell-click', text: cell.textContent.trim() };
                                                }
                                            } else {
                                                // 沒有指定行索引，遍歷所有行
                                                for (const row of rows) {
                                                    const cells = Array.from(row.querySelectorAll('td'));
                                                    if (cells[columnIndex]) {
                                                        const cell = cells[columnIndex];
                                                        
                                                        // 優先查找 <u> 標籤
                                                        if (isUTag) {
                                                            const uLink = cell.querySelector('u[onclick]') || cell.querySelector('u');
                                                            if (uLink) {
                                                                uLink.click();
                                                                return { success: true, method: 'u-tag-click', text: uLink.textContent.trim() };
                                                            }
                                                        }
                                                        
                                                        // 查找 <a> 標籤
                                                        const aLink = cell.querySelector('a');
                                                        if (aLink) {
                                                            aLink.click();
                                                            return { success: true, method: 'a-tag-click', text: aLink.textContent.trim() };
                                                        }
                                                        
                                                        // 查找任何可點擊的元素
                                                        const clickable = cell.querySelector('[onclick]');
                                                        if (clickable) {
                                                            clickable.click();
                                                            return { success: true, method: 'onclick-click', text: clickable.textContent.trim() };
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        return { success: false, reason: 'No clickable element found' };
                                    }, currencyLinkInfo.columnIndex, currencyLinkInfo.isUTag || false, currencyLinkInfo.rowIndex);
                                    
                                    // 等待導航（如果發生）
                                    try {
                                        await page.waitForNavigation({ 
                                            waitUntil: 'domcontentloaded',
                                            timeout: 10000 
                                        });
                            } catch (e) {
                                        // 如果沒有導航發生，忽略超時錯誤
                                        console.log('ℹ️  No navigation occurred (this is OK)');
                                    }
                                }
                            } catch (e) {
                                console.log('⚠️  Error clicking Currency link:', e.message);
                                // 即使出錯也繼續
                            }
                            
                            // 等待頁面穩定
                            await new Promise(resolve => setTimeout(resolve, 3000));
                            
                            // 查找並點擊 slim 連結
                            try {
                                const slimLinkInfo = await page.evaluate(() => {
                                    // 尋找包含 "slim" 文本的 <u onclick> 標籤
                                    const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                                    // 所有 u[onclick] 資料執行迴圈 
                                    for (const uLink of allULinks) {
                                        // 從 u[onclick] 中取得 text
                                        const text = uLink.textContent.trim();
                                        // 判斷是否包含 slim
                                        if (text === 'slim') {
                                            return {
                                                found: true,
                                                text: text,
                                                onclick: uLink.getAttribute('onclick')
                                            };
                                        }
                                    }
                                    return { found: false, reason: 'No slim link found' };
                                });
                                
                                if (slimLinkInfo.found) {
                                    // 點擊 slim 連結，並等待可能的導航
                                    try {
                                        await Promise.all([
                                            // 等待導航（如果發生）
                                            page.waitForNavigation({ 
                                                waitUntil: 'domcontentloaded',
                                                timeout: 10000 
                                            }).catch(() => {
                                                // 如果沒有導航發生，忽略超時錯誤
                                            }),
                                            // 點擊 slim 連結
                                            page.evaluate(() => {
                                                const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                                                for (const uLink of allULinks) {
                                                    const text = uLink.textContent.trim();
                                                    if (text === 'slim') {
                                                        uLink.click();
                                                        return;
                                                    }
                                                }
                                            })
                                        ]);
                                    } catch (e) {
                                        // 即使出錯也繼續
                                    }
                                    
                                    // 等待頁面穩定
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                }
                            } catch (e) {
                                console.log('⚠️  Error clicking slim link: ' + e.message);
                                // 即使出錯，也繼續執行後續的 DOM 提取
                            }
                        } else {
                            // 如果找到了 Currency 列索引，嘗試點擊該列的第一個單元格
                            if (currencyLinkInfo.columnIndex !== undefined && currencyLinkInfo.columnIndex !== -1) {
                                try {
                                    const clickResult = await page.evaluate((columnIndex) => {
                                        const dataContent = document.querySelector('tbody.dataContent');
                                        if (!dataContent) return { success: false, reason: 'No dataContent' };
                                        
                                        const rows = Array.from(dataContent.querySelectorAll('tr'));
                                        if (rows.length > 0) {
                                            const firstRow = rows[0];
                                            const cells = Array.from(firstRow.querySelectorAll('td'));
                                            if (cells[columnIndex]) {
                                                const cell = cells[columnIndex];
                                                
                                                // 嘗試點擊整個單元格
                                                cell.click();
                                                
                                                // 也嘗試點擊單元格內的所有元素
                                                const allClickable = cell.querySelectorAll('*');
                                                for (const elem of allClickable) {
                                                    if (elem.onclick || elem.tagName === 'U' || elem.tagName === 'A') {
                                                        elem.click();
                                                        break;
                                                    }
                                                }
                                                
                                        return {
                                                    success: true, 
                                                    method: 'cell-direct-click',
                                                    text: cell.textContent.trim(),
                                                    html: cell.innerHTML.substring(0, 100)
                                                };
                                            }
                                        }
                                        return { success: false, reason: 'No cell found' };
                                    }, currencyLinkInfo.columnIndex);
                                    
                                    // 等待可能的導航
                                    try {
                                        await page.waitForNavigation({ 
                                                waitUntil: 'domcontentloaded',
                                                timeout: 10000 
                                        });
                                    } catch (e) {
                                        console.log('ℹ️  No navigation occurred after direct click (this is OK)');
                                    }

                                    // 等待頁面穩定
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                } catch (e) {
                                    console.log('⚠️  Error clicking Currency column directly:', e.message);
                                }
                                } else {
                                // 如果連列索引都找不到，輸出調試信息
                                if (currencyLinkInfo.debug) {
                                    console.log('📋 Currency column debug info:', JSON.stringify(currencyLinkInfo.debug, null, 2));
                                }
                            }
                            
                            // 即使找不到 Currency 連結，也等待一下頁面穩定
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        }
                        
                        // ========== 點擊 "Slots (All)" 標籤（無論是否有 account_number）==========
                        try {
                            // 先列出所有可用的標籤（用於調試）
                            const allTabsInfo = await page.evaluate(() => {
                                const tabList = document.querySelector('ul#myTab');
                                if (!tabList) {
                                    return { found: false, tabs: [] };
                                }
                                
                                const tabs = Array.from(tabList.querySelectorAll('li'));
                                const tabsData = tabs.map((tab, idx) => {
                                    const link = tab.querySelector('a');
                                    return {
                                        index: idx,
                                        text: link ? link.textContent.trim() : '',
                                        isActive: tab.classList.contains('active'),
                                        href: link ? link.getAttribute('href') : ''
                                    };
                                });
                                
                                return { found: true, tabs: tabsData };
                            });
                            
                            if (allTabsInfo.found) {
                                allTabsInfo.tabs.forEach(tab => {
                                    const activeStatus = tab.isActive ? ' [ACTIVE]' : '';
                                });
                            }
                            
                            // 查找並點擊 Slots (All) 標籤
                            const slotsTabResult = await page.evaluate(() => {
                                // 查找 ul#myTab 中的所有 li 元素
                                const tabList = document.querySelector('ul#myTab');
                                if (!tabList) {
                                    return { found: false, reason: 'Tab list #myTab not found' };
                                }
                                
                                // 查找所有 li 元素
                                const tabs = Array.from(tabList.querySelectorAll('li'));
                                
                                // 查找包含 "Slots (All)" 文本的標籤
                                for (let i = 0; i < tabs.length; i++) {
                                    const tab = tabs[i];
                                    const link = tab.querySelector('a');
                                    if (link) {
                                        const text = link.textContent.trim();
                                        // 檢查是否為 "Slots (All)" 或包含 "Slots" 和 "All"
                                        if (text === 'Slots (All)' || (text.includes('Slots') && text.includes('All'))) {
                                            // 為連結添加唯一標識
                                            const uniqueId = 'slots-all-tab-' + Date.now();
                                            link.setAttribute('data-puppeteer-id', uniqueId);
                                            
                                            return {
                                                found: true,
                                                selector: 'a[data-puppeteer-id="' + uniqueId + '"]',
                                                text: text,
                                                href: link.getAttribute('href'),
                                                isActive: tab.classList.contains('active')
                                            };
                                        }
                                    }
                                }
                                
                                return { found: false, reason: 'Slots (All) tab not found' };
                            });
                            
                            if (slotsTabResult.found) {
                                // 使用 evaluate 內部點擊，更可靠
                                await page.evaluate((selector) => {
                                    const element = document.querySelector(selector);
                                    if (element) {
                                        element.click();
                                    }
                                }, slotsTabResult.selector);
                                
                                // 等待標籤內容載入（增加等待時間）
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                
                                // 驗證標籤是否真的被激活了
                                const verifyResult = await page.evaluate(() => {
                                    const tabList = document.querySelector('ul#myTab');
                                    if (!tabList) {
                                        return { activeTab: 'Unknown', contentVisible: false };
                                    }
                                    
                                    const activeTab = tabList.querySelector('li.active a');
                                    const activeTabText = activeTab ? activeTab.textContent.trim() : 'None';
                                    
                                    // 檢查 #data99991 是否可見（Slots (All) 的內容區域）
                                    const slotsAllContent = document.querySelector('#data99991');
                                    const contentVisible = slotsAllContent ? 
                                        (slotsAllContent.style.display !== 'none' && 
                                         slotsAllContent.classList.contains('active')) : false;
                                    
                                    return {
                                        activeTab: activeTabText,
                                        contentVisible: contentVisible,
                                        contentId: slotsAllContent ? slotsAllContent.id : 'Not found'
                                    };
                                });
                            } else {
                                console.log('⚠️  Could not find "Slots (All)" tab: ' + (slotsTabResult.reason || 'Unknown reason'));
                            }
                        } catch (e) {
                            console.log('⚠️  Error clicking "Slots (All)" tab: ' + e.message);
                        }
                    } catch (e) {
                        console.log('⚠️  Error clicking Currency link: ' + e.message);
                        // 即使出錯，也繼續執行後續的 DOM 提取
                    }

                    // ========== 步驟 2：提取第一頁資料並獲取分頁資訊 ==========
                    console.log('📄 Step 2: Extracting first page and pagination info...');
                    
                    // 先檢查頁面上有哪些表格
                    const tableInfo = await page.evaluate(() => {
                        const allTables = Array.from(document.querySelectorAll('table'));
                        return allTables.map((table, idx) => ({
                            index: idx,
                            id: table.id || 'no-id',
                            className: table.className || 'no-class',
                            rowCount: table.querySelectorAll('tr').length,
                            hasDataTable: table.id && table.id.includes('DataTables')
                        }));
                    });

                    // 定義提取當前頁資料的函數
                    const extractCurrentPageData = async (accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                        return await page.evaluate((accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                        // 先處理表格資料
                        // 優先查找 DataTables_Table_0，如果找不到則使用所有表格
                        let allTables = [];
                        const dataTable0 = document.querySelector('#DataTables_Table_0');
                        if (dataTable0) {
                            allTables = [dataTable0];
                        } else {
                            allTables = Array.from(document.querySelectorAll('table'));
                        }
                        // 存儲每個表格的表頭
                        const tableHeaders = {};
                        
                        // 第一遍：識別表頭表格（通常包含 th 標籤或 class 包含 header）
                        allTables.forEach((table, index) => {
                            // 獲取表格的所有行
                            const rows = Array.from(table.querySelectorAll('tr'));
                            // 獲取表格的 class 屬性
                            const tableClass = table.className || '';
                            // 檢查表格是否包含表頭
                            const isHeaderTable = tableClass.includes('header') || 
                                                tableClass.includes('Header') ||
                                                rows.some(row => row.querySelectorAll('th').length > 0);
                            // 如果表格包含表頭，則提取表頭
                            if (isHeaderTable && rows.length > 0) {
                                // 提取表頭
                                const headerRow = rows[0];
                                const headerCells = headerRow.querySelectorAll('th, td');
                                if (headerCells.length > 0) {
                                    const headers = Array.from(headerCells).map(cell => cell.textContent.trim());
                                    // 將表頭存儲，供後續表格使用
                                    tableHeaders[index] = headers;
                                }
                            }
                        });
                        
                        // 處理表格的函數
                        function processTable(table, tableIndex) {
                            // 獲取表格的所有行
                            let rows = Array.from(table.querySelectorAll('tr'));
                            // 獲取表格的 class 屬性
                            const tableClass = table.className || '';
                            
                            // 檢查是否有 thead 和 tbody 結構（RSG 特殊結構）
                            const thead = table.querySelector('thead');
                            const dataContent = table.querySelector('tbody.dataContent');
                            
                            // 如果有 thead，優先從 thead 中提取表頭
                            let headerRow = null;
                            // 初始化資料起始索引
                            let dataStartIndex = 0;
                            
                            if (thead) {
                                const headerRows = Array.from(thead.querySelectorAll('tr'));
                                if (headerRows.length > 0) {
                                    const headerCells = headerRows[0].querySelectorAll('th, td');
                                    if (headerCells.length > 0) {
                                        headerRow = Array.from(headerCells).map((cell, idx) => {
                                            const text = cell.textContent.trim();
                                            return text || 'column_' + idx;
                                        });
                                    }
                                }
                            }
                            
                            // 如果有 dataContent，優先從 tbody 中獲取行
                            if (dataContent) {
                                rows = Array.from(dataContent.querySelectorAll('tr'));
                            }
                            
                            // 檢查表格是否包含表頭（只有在沒有 thead 的情況下才檢查）
                            const isHeaderTable = !thead && (tableClass.includes('header') || 
                                                tableClass.includes('Header') ||
                                                (rows.length > 0 && rows[0].querySelectorAll('th').length > 0));
                            
                            // 如果是表頭表格，只提取表頭，不提取資料
                            if (isHeaderTable) {
                                // 如果表格的第一行存在，則獲取第一行的所有單元格
                                if (rows[0]) {
                                    // 獲取第一行的所有單元格
                                    const headerCells = rows[0].querySelectorAll('th, td');
                                    headerRow = Array.from(headerCells).map((cell, idx) => {
                                        const text = cell.textContent.trim();
                                        return text || 'column_' + idx;
                                    });
                                }
                                // 表頭表格通常沒有資料行
                                dataStartIndex = rows.length;
                            } else {
                                // 資料表格：嘗試找到對應的表頭
                                // 1. 如果已經從 thead 提取到表頭，使用它
                                if (headerRow) {
                                    // 所有行都是資料
                                    dataStartIndex = 0;
                                } else {
                                    // 2. 檢查前面的表格是否有表頭
                                    let foundHeader = null;
                                    for (let i = tableIndex - 1; i >= 0; i--) {
                                        if (tableHeaders[i]) {
                                            foundHeader = tableHeaders[i];
                                            break;
                                        }
                                    }
                                    
                                    // 3. 如果找到表頭，使用它
                                    if (foundHeader) {
                                        // 使用找到的表頭
                                        headerRow = foundHeader;
                                        // 所有行都是資料
                                        dataStartIndex = 0;
                                    } else {
                                        // 4. 否則檢查第一行是否包含 th（標準表頭）
                                        if (rows[0]) {
                                            // 獲取第一行的所有單元格
                                            const firstRowCells = rows[0].querySelectorAll('th, td');
                                            // 檢查第一行是否包含 th 標籤
                                            const hasTh = rows[0].querySelectorAll('th').length > 0;
                                            // 如果第一行包含 th 標籤，則使用第一行的所有單元格
                                            if (hasTh) {
                                                // 獲取第一行的所有單元格
                                                headerRow = Array.from(firstRowCells).map((cell, idx) => {
                                                    const text = cell.textContent.trim();
                                                    return text || 'column_' + idx;
                                                });
                                                dataStartIndex = 1;
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // 將資料行轉換為對象數組
                            const dataRows = rows.slice(dataStartIndex).map((row, rowIndex) => {
                                const cells = Array.from(row.querySelectorAll('td'));
                                const rowData = {};
                                
                                if (headerRow && headerRow.length > 0) {
                                    headerRow.forEach((header, colIndex) => {
                                        // 清理字段名（移除特殊字符，用於 JSON key）
                                        // 保留中文字符和基本字符
                                        let cleanHeader = header
                                            .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                            .replace(/^_+|_+$/g, '');
                                        
                                        // 如果清理後為空，使用索引
                                        if (!cleanHeader) {
                                            cleanHeader = 'column_' + colIndex;
                                        }
                                        
                                        // 確保字段名唯一（如果重複，添加索引）
                                        let finalHeader = cleanHeader;
                                        let counter = 1;
                                        while (rowData.hasOwnProperty(finalHeader)) {
                                            finalHeader = cleanHeader + '_' + counter;
                                            counter++;
                                        }
                                        
                                        rowData[finalHeader] = cells[colIndex] ? cells[colIndex].textContent.trim() : null;
                                    });
                                } else {
                                    // 如果沒有表頭，使用索引作為 key
                                    cells.forEach((cell, colIndex) => {
                                        rowData['column_' + colIndex] = cell ? cell.textContent.trim() : null;
                                    });
                                }
                                
                                // 添加原始行索引
                                rowData._rowIndex = rowIndex;
                                
                                return rowData;
                            });
                            
                            return {
                                tableIndex: tableIndex,
                                tableId: table.id || null,
                                tableClass: table.className || null,
                                isHeaderTable: isHeaderTable,
                                headers: headerRow || [],
                                headerCount: headerRow ? headerRow.length : 0,
                                rowCount: dataRows.length,
                                data: dataRows
                            };
                        }
                        
                        const result = {
                            // 基本頁面信息
                            pageInfo: {
                                title: document.title,
                                url: window.location.href,
                            },
                            
                            // 查詢參數
                            queryParams: {
                                accountNumber: accountNumberValue || null,
                                dateStart: dateStartValue || null,
                                dateEnd: dateEndValue || null
                            },
                            
                            // 提取所有文本內容
                            textContent: document.body.innerText.trim(),
                            
                            // 提取所有表格資料，並且過濾掉只有表頭沒有資料的表格
                            // 如果提供了 account_number，只保留最後一個有資料的表格
                            // 否則只保留包含 "Account number" 的表格
                            tables: (() => {
                                const processedTables = allTables.map((table, tableIndex) => processTable(table, tableIndex))
                                    .filter(table => {
                                        // 過濾掉只有表頭沒有資料的表格
                                        return table.rowCount > 0 || !table.isHeaderTable;
                                    });
                                
                                if (accountNumberProvided) {
                                    // 如果提供了 account_number，只保留最後一個有資料的表格
                                    if (processedTables.length > 0) {
                                        // 找到最後一個有資料的表格（rowCount > 0）
                                        let lastTable = null;
                                        for (let i = processedTables.length - 1; i >= 0; i--) {
                                            if (processedTables[i].rowCount > 0) {
                                                lastTable = processedTables[i];
                                                break;
                                            }
                                        }
                                        
                                        if (lastTable) {
                                            return [lastTable];
                                        }
                                    }
                                    return [];
                                } else {
                                    // 否則，保留所有有資料的表格（不限制必須包含 Account number）
                                    const filtered = processedTables.filter(table => table.rowCount > 0);
                                    return filtered;
                                }
                            })()
                        };
                        
                        return result;
                        }, accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue);
                    };
                    
                    // 提取單一頁面資料的函數（在 page 對象上操作，模擬點擊分頁按鈕）
                    // 這個函數需要在日期連結點擊代碼之前定義
                    const extractTableData = async (pageObject, accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                        return await pageObject.evaluate((accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                            // 先處理表格資料
                            // 優先查找 DataTables_Table_0
                            let allTables = [];
                            const dataTable0 = document.querySelector('#DataTables_Table_0');
                            if (dataTable0) {
                                allTables = [dataTable0];
                            } else {
                                allTables = Array.from(document.querySelectorAll('table'));
                            }
                            // 存儲每個表格的表頭
                            const tableHeaders = {};
                            
                            // 第一遍：識別表頭表格（通常包含 th 標籤或 class 包含 header）
                            allTables.forEach((table, index) => {
                                // 獲取表格的所有行
                                const rows = Array.from(table.querySelectorAll('tr'));
                                // 獲取表格的 class 屬性
                                const tableClass = table.className || '';
                                // 檢查表格是否包含表頭
                                const isHeaderTable = tableClass.includes('header') || 
                                                    tableClass.includes('Header') ||
                                                    rows.some(row => row.querySelectorAll('th').length > 0);
                                // 如果表格包含表頭，則提取表頭
                                if (isHeaderTable && rows.length > 0) {
                                    // 提取表頭
                                    const headerRow = rows[0];
                                    const headerCells = headerRow.querySelectorAll('th, td');
                                    if (headerCells.length > 0) {
                                        const headers = Array.from(headerCells).map(cell => cell.textContent.trim());
                                        // 將表頭存儲，供後續表格使用
                                        tableHeaders[index] = headers;
                                    }
                                }
                            });
                            
                            // 處理表格的函數
                            function processTable(table, tableIndex) {
                                // 獲取表格的所有行
                                let rows = Array.from(table.querySelectorAll('tr'));
                                // 獲取表格的 class 屬性
                                const tableClass = table.className || '';
                                
                                // 檢查是否有 thead 和 tbody 結構（RSG 特殊結構）
                                const thead = table.querySelector('thead');
                                const dataContent = table.querySelector('tbody.dataContent');
                                
                                // 如果有 thead，優先從 thead 中提取表頭
                                let headerRow = null;
                                // 初始化資料起始索引
                                let dataStartIndex = 0;
                                
                                if (thead) {
                                    const headerRows = Array.from(thead.querySelectorAll('tr'));
                                    if (headerRows.length > 0) {
                                        const headerCells = headerRows[0].querySelectorAll('th, td');
                                        if (headerCells.length > 0) {
                                            headerRow = Array.from(headerCells).map((cell, idx) => {
                                                const text = cell.textContent.trim();
                                                return text || 'column_' + idx;
                                            });
                                        }
                                    }
                                }
                                
                                // 如果有 dataContent，優先從 tbody 中獲取行
                                if (dataContent) {
                                    rows = Array.from(dataContent.querySelectorAll('tr'));
                                }
                                
                                // 檢查表格是否包含表頭（只有在沒有 thead 的情況下才檢查）
                                const isHeaderTable = !thead && (tableClass.includes('header') || 
                                                    tableClass.includes('Header') ||
                                                    (rows.length > 0 && rows[0].querySelectorAll('th').length > 0));
                                
                                // 如果是表頭表格，只提取表頭，不提取資料
                                if (isHeaderTable) {
                                    // 如果表格的第一行存在，則獲取第一行的所有單元格
                                    if (rows[0]) {
                                        // 獲取第一行的所有單元格
                                        const headerCells = rows[0].querySelectorAll('th, td');
                                        headerRow = Array.from(headerCells).map((cell, idx) => {
                                            const text = cell.textContent.trim();
                                            return text || 'column_' + idx;
                                        });
                                    }
                                    // 表頭表格通常沒有資料行
                                    dataStartIndex = rows.length;
                                } else {
                                    // 資料表格：嘗試找到對應的表頭
                                    // 1. 如果已經從 thead 提取到表頭，使用它
                                    if (headerRow) {
                                        // 所有行都是資料
                                        dataStartIndex = 0;
                                    } else {
                                        // 2. 檢查前面的表格是否有表頭
                                        let foundHeader = null;
                                        for (let i = tableIndex - 1; i >= 0; i--) {
                                            if (tableHeaders[i]) {
                                                foundHeader = tableHeaders[i];
                                                break;
                                            }
                                        }
                                        
                                        // 3. 如果找到表頭，使用它
                                        if (foundHeader) {
                                            // 使用找到的表頭
                                            headerRow = foundHeader;
                                            // 所有行都是資料
                                            dataStartIndex = 0;
                                        } else {
                                            // 4. 否則檢查第一行是否包含 th（標準表頭）
                                            if (rows[0]) {
                                                // 獲取第一行的所有單元格
                                                const firstRowCells = rows[0].querySelectorAll('th, td');
                                                // 檢查第一行是否包含 th 標籤
                                                const hasTh = rows[0].querySelectorAll('th').length > 0;
                                                // 如果第一行包含 th 標籤，則使用第一行的所有單元格
                                                if (hasTh) {
                                                    // 獲取第一行的所有單元格
                                                    headerRow = Array.from(firstRowCells).map((cell, idx) => {
                                                        const text = cell.textContent.trim();
                                                        return text || 'column_' + idx;
                                                    });
                                                    dataStartIndex = 1;
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                // 將資料行轉換為對象數組
                                const dataRows = rows.slice(dataStartIndex).map((row, rowIndex) => {
                                    const cells = Array.from(row.querySelectorAll('td'));
                                    const rowData = {};
                                    
                                    if (headerRow && headerRow.length > 0) {
                                        headerRow.forEach((header, colIndex) => {
                                            // 清理字段名（移除特殊字符，用於 JSON key）
                                            // 保留中文字符和基本字符
                                            let cleanHeader = header
                                                .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                .replace(/^_+|_+$/g, '');
                                            
                                            // 如果清理後為空，使用索引
                                            if (!cleanHeader) {
                                                cleanHeader = 'column_' + colIndex;
                                            }
                                            
                                            // 確保字段名唯一（如果重複，添加索引）
                                            let finalHeader = cleanHeader;
                                            let counter = 1;
                                            while (rowData.hasOwnProperty(finalHeader)) {
                                                finalHeader = cleanHeader + '_' + counter;
                                                counter++;
                                            }
                                            
                                            rowData[finalHeader] = cells[colIndex] ? cells[colIndex].textContent.trim() : null;
                                        });
                                    } else {
                                        // 如果沒有表頭，使用索引作為 key
                                        cells.forEach((cell, colIndex) => {
                                            rowData['column_' + colIndex] = cell ? cell.textContent.trim() : null;
                                        });
                                    }
                                    
                                    // 添加原始行索引
                                    rowData._rowIndex = rowIndex;
                                    
                                    return rowData;
                                });
                                
                                return {
                                    tableIndex: tableIndex,
                                    tableId: table.id || null,
                                    tableClass: table.className || null,
                                    isHeaderTable: isHeaderTable,
                                    headers: headerRow || [],
                                    headerCount: headerRow ? headerRow.length : 0,
                                    rowCount: dataRows.length,
                                    data: dataRows
                                };
                            }
                            
                            const result = {
                                tables: (() => {
                                    const processedTables = allTables.map((table, tableIndex) => processTable(table, tableIndex))
                                        .filter(table => {
                                            // 過濾掉只有表頭沒有資料的表格
                                            return table.rowCount > 0 || !table.isHeaderTable;
                                        });
                                    
                                    if (accountNumberProvided) {
                                        // 如果提供了 account_number，只保留最後一個有資料的表格
                                        if (processedTables.length > 0) {
                                            // 找到最後一個有資料的表格（rowCount > 0）
                                            let lastTable = null;
                                            for (let i = processedTables.length - 1; i >= 0; i--) {
                                                if (processedTables[i].rowCount > 0) {
                                                    lastTable = processedTables[i];
                                                    break;
                                                }
                                            }
                                            
                                            if (lastTable) {
                                                return [lastTable];
                                            }
                                        }
                                        return [];
                                    } else {
                                        // 否則，保留所有有資料的表格
                                        const filtered = processedTables.filter(table => table.rowCount > 0);
                                        return filtered;
                                    }
                                })()
                            };
                            
                            return result;
                        }, accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue);
                    };

                    // 定義檢查是否有下一頁的函數
                    const hasNextPage = async () => {
                        return await page.evaluate(() => {
                            const nextButton = document.querySelector('.paginate_button.next:not(.disabled)');
                            return nextButton !== null && !nextButton.classList.contains('disabled');
                        });
                    };

                    // 定義點擊下一頁的函數（用於查找帳號時翻頁）
                    const goToNextPageForAccount = async () => {
                        const nextPageInfo = await page.evaluate(() => {
                            const dataTablesNext = document.querySelector('.paginate_button.next:not(.disabled)');
                            if (dataTablesNext) {
                                const link = dataTablesNext.querySelector('a');
                                if (link) {
                                    const uniqueId = 'next-page-' + Date.now();
                                    link.setAttribute('data-puppeteer-id', uniqueId);
                                    return {
                                        found: true,
                                        selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                        text: link.textContent.trim()
                                    };
                                }
                            }
                            return { found: false };
                        });
                        
                        if (nextPageInfo.found) {
                            try {
                                await page.click(nextPageInfo.selector, { timeout: 5000 });
                                // 等待表格數據更新
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                return true;
                            } catch (e) {
                                return false;
                            }
                        }
                        return false;
                    };

                    // ========== 並發爬取分頁邏輯 ==========
                    // 解析參數
                    let accountNumberParsed = null;
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    
                    // 解析 account_number
                    if ($accountNumberJs && $accountNumberJs !== 'null' && $accountNumberJs !== '') {
                        try {
                            accountNumberParsed = JSON.parse($accountNumberJs);
                        } catch (e) {
                            accountNumberParsed = $accountNumberJs;
                        }
                    }
                    
                    // 解析 date_start
                    if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs !== '') {
                        try {
                            dateStartParsed = JSON.parse($dateStartJs);
                        } catch (e) {
                            dateStartParsed = $dateStartJs;
                        }
                    }
                    
                    // 解析 date_end
                    if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs !== '') {
                        try {
                            dateEndParsed = JSON.parse($dateEndJs);
                        } catch (e) {
                            dateEndParsed = $dateEndJs;
                        }
                    }
                    
                    // 判斷是否提供了 account_number
                    const accountNumberProvided = accountNumberParsed !== null && accountNumberParsed !== '' && accountNumberParsed !== undefined;
                    
                    // 確保表格已載入
                    await page.waitForSelector('tbody.dataContent tr', { timeout: 5000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // 提取第一頁的表格資料
                    const firstPageData = await extractCurrentPageData(accountNumberProvided, accountNumberParsed, dateStartParsed, dateEndParsed);
                    
                    if (!firstPageData || !firstPageData.tables || firstPageData.tables.length === 0) {
                        throw new Error('No table found on first page');
                    }
                    
                    // ========== 步驟 4：點擊所有 Date 欄位中的日期連結並截圖 ==========
                    try {
                        // 收集所有 Date 欄位中的日期連結
                        const dateLinks = await page.evaluate(() => {
                            const links = [];
                            
                            // 查找所有表格
                            const tables = document.querySelectorAll('table');
                            
                            tables.forEach((table, tableIndex) => {
                                // 查找表頭，找到 Date 欄位的索引
                                const thead = table.querySelector('thead');
                                let dateColumnIndex = -1;
                                
                                if (thead) {
                                    const headerRow = thead.querySelector('tr');
                                    if (headerRow) {
                                        const headers = Array.from(headerRow.querySelectorAll('th, td'));
                                        dateColumnIndex = headers.findIndex(cell => {
                                            const text = cell.textContent.trim();
                                            return text === 'Date' || text.includes('Date') || text === '日期';
                                        });
                                    }
                                }
                                
                                // 如果找到 Date 欄位，查找該欄位中的所有 <u onclick> 連結
                                if (dateColumnIndex !== -1) {
                                    const tbody = table.querySelector('tbody') || table.querySelector('tbody.dataContent');
                                    if (tbody) {
                                        const rows = Array.from(tbody.querySelectorAll('tr'));
                                        rows.forEach((row, rowIndex) => {
                                            const cells = Array.from(row.querySelectorAll('td'));
                                            if (cells[dateColumnIndex]) {
                                                const cell = cells[dateColumnIndex];
                                                const uLink = cell.querySelector('u[onclick]');
                                                if (uLink) {
                                                    const onclick = uLink.getAttribute('onclick');
                                                    const dateText = uLink.textContent.trim();
                                                    
                                                    // 為元素添加唯一標識
                                                    const uniqueId = 'date-link-' + tableIndex + '-' + rowIndex + '-' + Date.now();
                                                    uLink.setAttribute('data-puppeteer-date-id', uniqueId);
                                                    
                                                    links.push({
                                                        tableIndex: tableIndex,
                                                        rowIndex: rowIndex,
                                                        dateText: dateText,
                                                        onclick: onclick,
                                                        selector: 'u[data-puppeteer-date-id="' + uniqueId + '"]'
                                                    });
                                                }
                                            }
                                        });
                                    }
                                } else {
                                    // 如果找不到 Date 欄位，嘗試直接查找所有包含日期的 <u onclick> 連結
                                    const allULinks = Array.from(table.querySelectorAll('u[onclick]'));
                                    allULinks.forEach((uLink, linkIndex) => {
                                        const onclick = uLink.getAttribute('onclick');
                                        const text = uLink.textContent.trim();
                                        
                                        // 檢查是否看起來像日期（格式：YYYY-MM-DD）
                                        if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
                                            const uniqueId = 'date-link-direct-' + tableIndex + '-' + linkIndex + '-' + Date.now();
                                            uLink.setAttribute('data-puppeteer-date-id', uniqueId);
                                            
                                            links.push({
                                                tableIndex: tableIndex,
                                                rowIndex: linkIndex,
                                                dateText: text,
                                                onclick: onclick,
                                                selector: 'u[data-puppeteer-date-id="' + uniqueId + '"]',
                                                method: 'direct-search'
                                            });
                                        }
                                    });
                                }
                            });
                            
                            return links;
                        });
                        
                        if (dateLinks.length > 0) {
                            // 逐個點擊每個日期連結並截圖
                            for (let i = 0; i < dateLinks.length; i++) {
                                const dateLink = dateLinks[i];
                                
                                try {
                                    // 記錄當前 URL（用於判斷是否導航）
                                    const urlBeforeClick = await page.url();
                                    
                                    // 點擊日期連結
                                    if (dateLink.onclick) {
                                        // 方法 1: 直接執行 onclick 函數（最可靠）
                                        await page.evaluate((onclickValue) => {
                                            eval(onclickValue);
                                        }, dateLink.onclick);
                                    } else {
                                        // 方法 2: 使用選擇器點擊（需要重新查找，因為可能已經導航）
                                        // 先嘗試重新查找連結
                                        const linkFound = await page.evaluate((selector) => {
                                            const link = document.querySelector(selector);
                                            if (link) {
                                                link.click();
                                                return true;
                                            }
                                            return false;
                                        }, dateLink.selector);
                                        
                                        if (!linkFound) {
                                            // 如果找不到，嘗試通過日期文本查找
                                            await page.evaluate((dateText) => {
                                                const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                                                for (const uLink of allULinks) {
                                                    if (uLink.textContent.trim() === dateText) {
                                                        uLink.click();
                                                        return;
                                                    }
                                                }
                                            }, dateLink.dateText);
                                        }
                                    }
                                    
                                    // 等待頁面載入或導航
                                    let navigated = false;
                                    try {
                                        await page.waitForNavigation({ 
                                            waitUntil: 'domcontentloaded',
                                            timeout: 10000 
                                        });
                                        navigated = true;
                                    } catch (e) {
                                        console.log('ℹ️  No navigation occurred (this is OK)');
                                    }
                                    
                                    // 等待頁面穩定
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                    
                                    // ========== 爬取該日期頁面的所有分頁數據 ==========
                                    // 無論是否導航，都嘗試爬取數據（因為有些頁面使用 AJAX，不會觸發導航）
                                    
                                    try {
                                        // 等待頁面完全載入
                                            await page.waitForSelector('tbody.dataContent', { timeout: 15000 }).catch(() => {});
                                            await new Promise(resolve => setTimeout(resolve, 2000));
                                            
                                            // 獲取分頁資訊
                                            const datePagePaginationInfo = await page.evaluate(() => {
                                                const pageLinks = [];
                                                let maxPageNumber = 1;
                                                let currentPage = 1;

                                                // 方法 1: 使用 DataTables API（最可靠）
                                                try {
                                                    if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                                        const dt = window.$('#DataTables_Table_0').DataTable();
                                                        if (dt && dt.page && dt.page.info) {
                                                            const info = dt.page.info();
                                                            if (info) {
                                                                maxPageNumber = info.pages || 1;
                                                                currentPage = (info.page || 0) + 1;
                                                            }
                                                        }
                                                    }
                                                } catch (e) {
                                                    // DataTables API 不可用，繼續使用其他方法
                                                }

                                                // 方法 2: 從可見的頁碼按鈕中找最大頁碼
                                                const paginationContainer = document.querySelector('#DataTables_Table_0_paginate') ||
                                                                            document.querySelector('.dataTables_paginate') || 
                                                                            document.querySelector('.pagination');
                                                if (paginationContainer) {
                                                    const links = paginationContainer.querySelectorAll('.paginate_button:not(.previous):not(.next):not(.disabled)');
                                                    links.forEach(link => {
                                                        const pageText = link.textContent.trim();
                                                        const pageNum = parseInt(pageText);
                                                        if (!isNaN(pageNum) && pageText !== '…') {
                                                            pageLinks.push({
                                                                pageNumber: pageNum,
                                                                url: window.location.href
                                                            });
                                                            if (pageNum > maxPageNumber) {
                                                                maxPageNumber = pageNum;
                                                            }
                                                        }
                                                    });
                                                }

                                                // 方法 3: 從 "Showing X to Y of Z" 文本中提取
                                                const infoElements = document.querySelectorAll('.dataTables_info');
                                                for (const infoEl of infoElements) {
                                                    const infoContent = infoEl.textContent.trim();
                                                    const match = infoContent.match(/Showing\s+\d+\s+to\s+(\d+)\s+of\s+(\d+)/i);
                                                    if (match) {
                                                        const recordsPerPage = parseInt(match[1]);
                                                        const totalRecords = parseInt(match[2]);
                                                        if (recordsPerPage > 0 && totalRecords > 0) {
                                                            const calculatedPages = Math.ceil(totalRecords / recordsPerPage);
                                                            if (calculatedPages > maxPageNumber) {
                                                                maxPageNumber = calculatedPages;
                                                            }
                                                        }
                                                    }
                                                }

                                                // 如果還是只有 1 頁，檢查是否有 Next 按鈕
                                                if (maxPageNumber === 1) {
                                                    const nextLink = document.querySelector('#DataTables_Table_0_paginate .paginate_button.next:not(.disabled)') ||
                                                                     document.querySelector('.paginate_button.next:not(.disabled)');
                                                    if (nextLink) {
                                                        maxPageNumber = 2;
                                                    }
                                                }

                                                return {
                                                    totalPages: maxPageNumber,
                                                    currentPage: currentPage
                                                };
                                            });
                                            
                                            // 收集所有頁面的數據
                                            const allDatePageData = [];
                                            
                                            // 提取當前頁（第一頁）的數據
                                            const firstPageData = await extractTableData(page, accountNumberProvided, accountNumberParsed, dateStartParsed, dateEndParsed);
                                            
                                            if (firstPageData && firstPageData.tables && firstPageData.tables.length > 0) {
                                                // 計算第一頁的行數
                                                let firstPageRows = 0;
                                                firstPageData.tables.forEach(table => {
                                                    if (table.data) {
                                                        firstPageRows += table.data.length;
                                                    }
                                                });
                                                
                                                allDatePageData.push({
                                                    page: 1,
                                                    data: firstPageData
                                                });
                                            }
                                            
                                            // 遍歷剩餘頁面
                                            for (let pageNum = 2; pageNum <= datePagePaginationInfo.totalPages; pageNum++) {
                                                try {
                                                    // 點擊下一頁
                                                    const nextPageClicked = await page.evaluate((targetPage) => {
                                                        // 方法 1: 使用 DataTables API
                                                        try {
                                                            if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                                                const dt = window.$('#DataTables_Table_0').DataTable();
                                                                if (dt && dt.page) {
                                                                    dt.page(targetPage - 1).draw('page');
                                                                    return { success: true, method: 'datatables-api' };
                                                                }
                                                            }
                                                        } catch (e) {
                                                            // API 不可用，繼續使用其他方法
                                                        }
                                                        
                                                        // 方法 2: 點擊分頁按鈕
                                                        const paginationContainer = document.querySelector('#DataTables_Table_0_paginate') ||
                                                                                    document.querySelector('.dataTables_paginate') || 
                                                                                    document.querySelector('.pagination');
                                                        if (paginationContainer) {
                                                            const pageButtons = paginationContainer.querySelectorAll('.paginate_button:not(.previous):not(.next):not(.disabled)');
                                                            for (const btn of pageButtons) {
                                                                const pageText = btn.textContent.trim();
                                                                const pageNum = parseInt(pageText);
                                                                if (pageNum === targetPage) {
                                                                    btn.click();
                                                                    return { success: true, method: 'button-click' };
                                                                }
                                                            }
                                                            
                                                            // 如果找不到特定頁碼，嘗試點擊 Next 按鈕
                                                            if (targetPage > 1) {
                                                                const nextBtn = paginationContainer.querySelector('.paginate_button.next:not(.disabled)');
                                                                if (nextBtn) {
                                                                    nextBtn.click();
                                                                    return { success: true, method: 'next-button' };
                                                                }
                                                            }
                                                        }
                                                        
                                                        return { success: false, reason: 'No pagination method found' };
                                                    }, pageNum);
                                                    
                                                    if (nextPageClicked.success) {
                                                        // 等待表格數據更新
                                                        await new Promise(resolve => setTimeout(resolve, 3000));
                                                        
                                                        // 提取當前頁數據
                                                        const pageData = await extractTableData(page, accountNumberProvided, accountNumberParsed, dateStartParsed, dateEndParsed);
                                                        if (pageData && pageData.tables && pageData.tables.length > 0) {
                                                            allDatePageData.push({
                                                                page: pageNum,
                                                                data: pageData
                                                            });
                                                        }
                                                    } else {
                                                        console.log('⚠️  Could not navigate to page ' + pageNum);
                                                        break;
                                                    }
                                                } catch (e) {
                                                    console.log('⚠️  Error extracting page ' + pageNum + ': ' + e.message);
                                                    break;
                                                }
                                            }
                                            
                                            if (allDatePageData.length > 0) {
                                                const fs = require('fs');
                                                const path = require('path');
                                                const dateDataFileName = 'date_data_' + dateLink.dateText.replace(/-/g, '_') + '.json';
                                                const dateDataPath = path.resolve(dateDataFileName);
                                                
                                                // 獲取當前頁面 URL
                                                const currentUrl = await page.url();
                                                
                                                // 合併所有頁面的數據到一個數組中
                                                const mergedData = [];
                                                let totalRows = 0;
                                                let headers = null;
                                                
                                                // 遍歷所有頁面的數據
                                                allDatePageData.forEach(pageData => {
                                                    if (pageData.data && pageData.data.tables) {
                                                        pageData.data.tables.forEach(table => {
                                                            // 保存表頭（使用第一個表格的表頭）
                                                            if (!headers && table.headers && table.headers.length > 0) {
                                                                headers = table.headers;
                                                            }
                                                            
                                                            // 將該表格的所有數據行添加到合併數組中
                                                            if (table.data && table.data.length > 0) {
                                                                table.data.forEach(row => {
                                                                    // 添加頁碼信息到每一行（可選）
                                                                    const rowWithPageInfo = {
                                                                        ...row,
                                                                        _page: pageData.page
                                                                    };
                                                                    mergedData.push(rowWithPageInfo);
                                                                    totalRows++;
                                                                });
                                                            }
                                                        });
                                                    }
                                                });
                                                
                                                // 組裝元數據（參考 ScrapeBrowserFoqqDOMDetail.php 的格式）
                                                const dateDataToSave = {
                                                    metadata: {
                                                        timestamp: new Date().toISOString(),
                                                        date: dateLink.dateText,
                                                        url: currentUrl,
                                                        queryParams: {
                                                            date_start: dateStartParsed,
                                                            date_end: dateEndParsed,
                                                            account_number: accountNumberParsed
                                                        },
                                                        totalPages: datePagePaginationInfo.totalPages,
                                                        pagesScraped: allDatePageData.length,
                                                        totalRows: totalRows
                                                    },
                                                    headers: headers || [],
                                                    headerCount: headers ? headers.length : 0,
                                                    rowCount: totalRows,
                                                    data: mergedData  // 所有頁面的數據合併到一個數組中
                                                };
                                                
                                                try {
                                                    fs.writeFileSync(dateDataPath, JSON.stringify(dateDataToSave, null, 2), 'utf8');
                                                } catch (writeError) {
                                                    console.log('⚠️  Error writing file: ' + writeError.message);
                                                    console.log('⚠️  Error stack: ' + writeError.stack);
                                                }
                                            } else {
                                                console.log('⚠️  No data extracted for date: ' + dateLink.dateText);
                                            }
                                            
                                    } catch (e) {
                                        console.log('⚠️  Error scraping date page data: ' + e.message);
                                        console.log('⚠️  Error stack: ' + e.stack);
                                    }
                                    
                                    // 如果頁面導航了，返回上一頁以便繼續處理下一個連結
                                    if (navigated) {
                                        try {
                                            await page.goBack({ waitUntil: 'domcontentloaded' });
                                            await new Promise(resolve => setTimeout(resolve, 2000));
                                            
                                            // 重新等待表格載入
                                            await page.waitForSelector('tbody.dataContent', { timeout: 10000 }).catch(() => {});
                                            await new Promise(resolve => setTimeout(resolve, 1000));
                                        } catch (e) {
                                            console.log('⚠️  Error navigating back: ' + e.message);
                                            // 如果返回失敗，可能需要重新載入原始頁面
                                            // 但這裡我們先繼續，看看能否找到下一個連結
                                        }
                                    }
                                } catch (e) {
                                    console.log('⚠️  Error clicking date link ' + (i + 1) + ': ' + e.message);
                                }
                            }
                        } else {
                            console.log('ℹ️  No date links found in Date column');
                        }
                    } catch (e) {
                        console.log('⚠️  Error processing date links: ' + e.message);
                        console.log('⚠️  Error stack:', e.stack);
                    }
                    
                    // 獲取所有分頁資訊（DataTables 的分頁按鈕）
                    // 優先使用 DataTables API，再回退其他方式
                    const paginationInfo = await page.evaluate(() => {
                        const pageLinks = [];
                        let maxPageNumber = 1;
                        let currentPage = 1;

                        // 方法 1: 使用 DataTables API（最可靠）
                        try {
                            if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                const dt = window.$('#DataTables_Table_0').DataTable();
                                if (dt && dt.page && dt.page.info) {
                                    const info = dt.page.info();
                                    if (info) {
                                        maxPageNumber = info.pages || 1;
                                        currentPage = (info.page || 0) + 1;
                                    }
                                }
                            }
                        } catch (e) {
                            console.log('⚠️  DataTables API unavailable:', e.message);
                        }

                        // 方法 2: 從可見的頁碼按鈕中找最大頁碼（備援）
                        const paginationContainer = document.querySelector('#DataTables_Table_0_paginate') ||
                                                    document.querySelector('.dataTables_paginate') || 
                                                    document.querySelector('.pagination');
                        if (paginationContainer) {
                            const links = paginationContainer.querySelectorAll('.paginate_button:not(.previous):not(.next):not(.disabled)');
                            links.forEach(link => {
                                const pageText = link.textContent.trim();
                                const pageNum = parseInt(pageText);
                                if (!isNaN(pageNum) && pageText !== '…') {
                                    pageLinks.push({
                                        pageNumber: pageNum,
                                        url: window.location.href
                                    });
                                    if (pageNum > maxPageNumber) {
                                        maxPageNumber = pageNum;
                                    }
                                }
                            });

                            // 方法 3: 從 DataTables 信息文本中解析（Showing X to Y of Z entries）
                            const infoText = paginationContainer.parentElement?.querySelector('.dataTables_info') ||
                                             document.querySelector('.dataTables_info');
                            if (infoText && maxPageNumber === 1) {
                                const infoContent = infoText.textContent;
                                const match = infoContent.match(/Showing\s+\d+\s+to\s+(\d+)\s+of\s+(\d+)/i);
                                if (match) {
                                    const recordsPerPage = parseInt(match[1]);
                                    const totalRecords = parseInt(match[2]);
                                    if (recordsPerPage > 0 && totalRecords > 0) {
                                        const calculatedPages = Math.ceil(totalRecords / recordsPerPage);
                                        if (calculatedPages > maxPageNumber) {
                                            maxPageNumber = calculatedPages;
                                        }
                                    }
                                }
                            }
                        }

                        // 如果還是只有 1 頁，檢查是否有 Next 按鈕
                        if (maxPageNumber === 1) {
                            const nextLink = document.querySelector('#DataTables_Table_0_paginate .paginate_button.next:not(.disabled)') ||
                                             document.querySelector('.paginate_button.next:not(.disabled)');
                            if (nextLink) {
                                maxPageNumber = 2;
                            }
                        }

                        return {
                            totalPages: maxPageNumber,
                            currentPage: currentPage,
                            pageLinks: pageLinks,
                            currentUrl: window.location.href,
                            visiblePages: pageLinks.map(p => p.pageNumber).sort((a, b) => a - b)
                        };
                    });
                    
                    // ========== 步驟 3：提取所有數據（優化版本）==========
                    // 註：如果數據已經一次性載入到 DOM（客戶端分頁），直接提取所有數據
                    // 否則才使用翻頁方式逐頁提取
                    console.log('🚀 Step 3: Extracting all data (optimized)...');
                    
                    // 嘗試一次性獲取所有數據（如果數據已在 DOM 中）
                    const extractAllDataAtOnce = async () => {
                        return await page.evaluate((accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                            // 嘗試使用 DataTables API 獲取所有數據
                            try {
                                if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                    const dt = window.$('#DataTables_Table_0').DataTable();
                                    if (dt) {
                                        // 獲取所有行（包括隱藏的）
                                        const allRows = dt.rows({ page: 'all' });
                                        if (allRows && allRows.count && allRows.count() > 0) {
                                            console.log('✅ Found all data via DataTables API, total rows: ' + allRows.count());
                                            // 如果 API 可以獲取所有數據，使用 API
                                            const rowsData = [];
                                            allRows.every((row) => {
                                                const rowNode = row.node();
                                                if (rowNode) {
                                                    const cells = Array.from(rowNode.querySelectorAll('td'));
                                                    const rowData = {};
                                                    cells.forEach((cell, idx) => {
                                                        rowData['column_' + idx] = cell ? cell.textContent.trim() : null;
                                                    });
                                                    rowsData.push(rowData);
                                                }
                                                return true;
                                            });
                                            
                                            if (rowsData.length > 0) {
                                                return { success: true, data: rowsData, method: 'datatables-api' };
                                            }
                                        }
                                    }
                                }
                            } catch (e) {
                                console.log('⚠️  DataTables API method failed: ' + e.message);
                            }
                            
                            // 方法 2: 直接從 DOM 提取所有行（包括隱藏的）
                            try {
                                const dataContent = document.querySelector('tbody.dataContent');
                                if (dataContent) {
                                    // 獲取所有行（包括隱藏的，因為 DataTables 只是用 CSS 隱藏）
                                    const allRows = Array.from(dataContent.querySelectorAll('tr'));
                                    if (allRows.length > 0) {
                                        console.log('✅ Found all rows in DOM, total rows: ' + allRows.length);
                                        
                                        // 獲取表頭
                                        const thead = document.querySelector('thead');
                                        let headerRow = [];
                                        if (thead) {
                                            const headerRows = Array.from(thead.querySelectorAll('tr'));
                                            if (headerRows.length > 0) {
                                                const headerCells = headerRows[0].querySelectorAll('th, td');
                                                if (headerCells.length > 0) {
                                                    headerRow = Array.from(headerCells).map((cell, idx) => {
                                                        const text = cell.textContent.trim();
                                                        return text || 'column_' + idx;
                                                    });
                                                }
                                            }
                                        }
                                        
                                        // 提取所有行的數據
                                        const rowsData = allRows.map((row, rowIndex) => {
                                            const cells = Array.from(row.querySelectorAll('td'));
                                            const rowData = {};
                                            
                                            if (headerRow && headerRow.length > 0) {
                                                headerRow.forEach((header, colIndex) => {
                                                    let cleanHeader = header
                                                        .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                        .replace(/^_+|_+$/g, '');
                                                    if (!cleanHeader) {
                                                        cleanHeader = 'column_' + colIndex;
                                                    }
                                                    let finalHeader = cleanHeader;
                                                    let counter = 1;
                                                    while (rowData.hasOwnProperty(finalHeader)) {
                                                        finalHeader = cleanHeader + '_' + counter;
                                                        counter++;
                                                    }
                                                    rowData[finalHeader] = cells[colIndex] ? cells[colIndex].textContent.trim() : null;
                                                });
                                            } else {
                                                cells.forEach((cell, colIndex) => {
                                                    rowData['column_' + colIndex] = cell ? cell.textContent.trim() : null;
                                                });
                                            }
                                            
                                            rowData._rowIndex = rowIndex;
                                            return rowData;
                                        });
                                        
                                        if (rowsData.length > 0) {
                                            return { 
                                                success: true, 
                                                data: rowsData, 
                                                headers: headerRow,
                                                method: 'dom-direct',
                                                totalRows: rowsData.length
                                            };
                                        }
                                    }
                                }
                            } catch (e) {
                                console.log('⚠️  DOM direct method failed: ' + e.message);
                            }
                            
                            return { success: false, reason: 'No data found or method not applicable' };
                        }, accountNumberProvided, accountNumberParsed, dateStartParsed, dateEndParsed);
                    };
                    
                    // 先嘗試一次性獲取所有數據
                    const allDataResult = await extractAllDataAtOnce();
                    
                    let allPagesData = [];
                    
                    if (allDataResult.success && allDataResult.data && allDataResult.data.length > 0) {
                        console.log('✅ Successfully extracted all data at once using ' + allDataResult.method + ', total rows: ' + allDataResult.data.length);
                        
                        // 使用一次性獲取的數據
                        allPagesData = [{
                            pageNumber: 1,
                            tables: [{
                                headers: allDataResult.headers || [],
                                headerCount: allDataResult.headers ? allDataResult.headers.length : 0,
                                rowCount: allDataResult.data.length,
                                data: allDataResult.data
                            }]
                        }];
                    } else {
                        console.log('ℹ️  One-time extraction failed, falling back to pagination method...');
                        console.log('   Reason: ' + (allDataResult.reason || 'Unknown'));
                        
                        // 回退到翻頁方式
                        // extractTableData 函數已經在上面定義了，這裡不需要重複定義
                        
                        // 使用 DataTables API 依次翻頁（優先），若失敗再回退 Next 按鈕點擊
                        const goToNextPage = async () => {
                        // 先獲取當前頁碼與總頁數
                        const currentInfo = await page.evaluate(() => {
                            let currentPage = 1;
                            let totalPages = 1;
                            try {
                                if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                    const dt = window.$('#DataTables_Table_0').DataTable();
                                    if (dt && dt.page && dt.page.info) {
                                        const info = dt.page.info();
                                        currentPage = (info.page || 0) + 1;
                                        totalPages = info.pages || 1;
                                    }
                                }
                            } catch (e) {}
                            const hasNextButton = !!(document.querySelector('#DataTables_Table_0_paginate .paginate_button.next:not(.disabled)') ||
                                                     document.querySelector('.paginate_button.next:not(.disabled)'));
                            return { currentPage, totalPages, hasNextButton };
                        });

                        if (!currentInfo.hasNextButton || currentInfo.currentPage >= currentInfo.totalPages) {
                            return { success: false, hasNext: false, currentPage: currentInfo.currentPage };
                        }

                        // 優先使用 DataTables API 翻頁
                        const apiResult = await page.evaluate(() => {
                            try {
                                if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                    const dt = window.$('#DataTables_Table_0').DataTable();
                                    if (dt && dt.page) {
                                        dt.page('next').draw('page');
                                        return { success: true, method: 'api' };
                                    }
                                }
                            } catch (e) {
                                return { success: false, error: e.message };
                            }
                            return { success: false };
                        });

                        if (!apiResult.success) {
                            // 回退：點擊 Next 按鈕
                            const clickResult = await page.evaluate(() => {
                                const nextButton = document.querySelector('#DataTables_Table_0_paginate .paginate_button.next:not(.disabled)') ||
                                                   document.querySelector('.paginate_button.next:not(.disabled)');
                                if (nextButton) {
                                    nextButton.click();
                                    const link = nextButton.querySelector('a');
                                    if (link) {
                                        link.click();
                                    }
                                    return { success: true, method: 'click' };
                                }
                                return { success: false };
                            });
                            if (!clickResult.success) {
                                return { success: false, hasNext: false, currentPage: currentInfo.currentPage };
                            }
                        }

                        // 等待頁碼變化
                        try {
                            await page.waitForFunction(
                                (oldPage) => {
                                    const active = document.querySelector('#DataTables_Table_0_paginate .paginate_button.active') ||
                                                   document.querySelector('.paginate_button.active');
                                    const newPage = active ? parseInt(active.textContent.trim()) : 1;
                                    if (!isNaN(newPage)) return newPage > oldPage;
                                    return false;
                                },
                                { timeout: 5000 },
                                currentInfo.currentPage
                            );
                        } catch (e) {
                            console.log('⚠️  Wait for page change timeout, continue');
                        }

                        // 再次讀取當前頁碼
                        const newPageNum = await page.evaluate(() => {
                            const active = document.querySelector('#DataTables_Table_0_paginate .paginate_button.active') ||
                                           document.querySelector('.paginate_button.active');
                            const newPage = active ? parseInt(active.textContent.trim()) : 1;
                            return isNaN(newPage) ? 1 : newPage;
                        });

                        if (newPageNum > currentInfo.currentPage) {
                            return { success: true, hasNext: true, currentPage: newPageNum };
                        }

                        return { success: false, hasNext: false, currentPage: newPageNum };
                    };
                    
                        // 串行爬取所有其他頁面（使用 Next 按鈕）
                        const otherPagesData = [];
                        let currentPage = 1;
                        let maxPagesToScrape = paginationInfo.totalPages;
                        
                        // 繼續點擊 Next 按鈕直到沒有下一頁
                        let scrapeCount = 0;
                        
                        while (scrapeCount < maxPagesToScrape) {
                            const nextResult = await goToNextPage();
                            
                            if (!nextResult.success || !nextResult.hasNext) {
                                break;
                            }
                            
                            currentPage = nextResult.currentPage || (currentPage + 1);
                            scrapeCount++;
                            
                            // 提取當前頁面的表格資料
                            const tableData = await extractTableData(page, accountNumberProvided, accountNumberParsed, dateStartParsed, dateEndParsed);
                            
                            if (tableData.tables && tableData.tables.length > 0) {
                                otherPagesData.push({
                                    pageNumber: currentPage,
                                    tables: tableData.tables || []
                                });
                            }
                        }
                        
                        // 合併第一頁和其他頁面的資料
                        allPagesData = [
                            {
                                pageNumber: 1,
                                tables: firstPageData.tables || []
                            },
                            ...otherPagesData
                        ];
                    }
                    
                    // 獲取當前頁面信息
                    const currentPageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });
                    
                    // 合併所有分頁的資料
                    const mergedDomData = {
                        pageInfo: firstPageData.pageInfo || currentPageInfo,
                        queryParams: {
                            accountNumber: accountNumberParsed,
                            dateStart: dateStartParsed,
                            dateEnd: dateEndParsed
                        },
                        totalPages: allPagesData.length,
                        // 合併所有分頁的表格資料
                        tables: (() => {
                            if (allPagesData.length === 0) {
                                return [];
                            }
                            
                            // 如果提供了 account_number，合併所有分頁的最後一個表格
                            if (accountNumberProvided) {
                                const mergedTables = [];
                                
                                // 為每個分頁找到最後一個有資料的表格
                                allPagesData.forEach((pageData) => {
                                    const tables = pageData.tables || [];
                                    
                                    if (tables.length > 0) {
                                        // 找到最後一個有資料的表格
                                        let lastTable = null;
                                        for (let i = tables.length - 1; i >= 0; i--) {
                                            if (tables[i].rowCount > 0) {
                                                lastTable = tables[i];
                                                break;
                                            }
                                        }
                                        
                                        if (lastTable) {
                                            // 為資料添加頁碼標記
                                            const tableWithPageInfo = {
                                                ...lastTable,
                                                data: lastTable.data.map(row => ({
                                                    ...row,
                                                    _pageNumber: pageData.pageNumber
                                                }))
                                            };
                                            mergedTables.push(tableWithPageInfo);
                                        }
                                    }
                                });
                                
                                // 合併所有表格的數據到一個表格中
                                if (mergedTables.length > 0) {
                                    const firstTable = mergedTables[0];
                                    const allData = [];
                                    
                                    mergedTables.forEach((table) => {
                                        allData.push(...table.data);
                                    });
                                    
                                    return [{
                                        ...firstTable,
                                        headers: firstTable.headers,
                                        headerCount: firstTable.headerCount,
                                        rowCount: allData.length,
                                        data: allData,  // 所有頁面的數據都合併到這裡
                                    }];
                                }
                                
                                return [];
                            } else {
                                // 否則，合併所有表格
                                const mergedTables = [];
                                
                                allPagesData.forEach((pageData) => {
                                    const tables = pageData.tables || [];
                                    tables.forEach(table => {
                                        // 只保留有數據的表格
                                        if (table.rowCount > 0 && table.data && table.data.length > 0) {
                                            const tableWithPageInfo = {
                                                ...table,
                                                data: table.data.map(row => ({
                                                    ...row,
                                                    _pageNumber: pageData.pageNumber
                                                }))
                                            };
                                            mergedTables.push(tableWithPageInfo);
                                        }
                                    });
                                });
                                
                                // 如果有多個表格，合併成一個
                                if (mergedTables.length > 1) {
                                    const firstTable = mergedTables[0];
                                    const allData = [];
                                    
                                    mergedTables.forEach((table) => {
                                        allData.push(...table.data);
                                    });
                                    
                                    return [{
                                        ...firstTable,
                                        rowCount: allData.length,
                                        data: allData,  // 所有頁面的數據都合併到這裡
                                    }];
                                } else if (mergedTables.length === 1) {
                                    // 即使只有一個表格，也要確保數據已合併
                                    return mergedTables;
                                }
                                
                                return [];
                            }
                        })()
                    };
                    
                    const domData = mergedDomData;
                    
                    // 截圖：最終頁面（所有數據爬取完成後）
                    await page.screenshot({ 
                        path: 'step_13_final_page.png',
                        fullPage: false
                    });
                    console.log('📸 Screenshot saved: step_13_final_page.png');

                    // 合併所有提取的資料和捕獲的 DOM 資料
                    // accountNumberParsed, dateStartParsed 和 dateEndParsed 已經在上面定義過了
                    const result = {
                        timestamp: new Date().toISOString(),  // 時間戳
                        url: '$url',  // 目標 URL
                        queryParams: {
                            accountNumber: accountNumberParsed,
                            dateStart: dateStartParsed,
                            dateEnd: dateEndParsed
                        },
                        domData: domData,  // 捕獲的 DOM 資料
                        success: true  // 成功標記
                    };

                    // 將結果保存為 JSON 文件
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));

                    return result;
                } catch (error) {
                    // 如果發生錯誤，記錄錯誤並保存錯誤信息
                    console.error('❌ Error during scraping:', error);
                    fs.writeFileSync('scraped_result.json', JSON.stringify({
                        error: error.message,
                        success: false,
                        timestamp: new Date().toISOString()
                    }, null, 2));
                    throw error;
                } finally {
                    // 無論成功或失敗，都要關閉瀏覽器
                    await browser.close();
                    console.log('🏁 Browser closed');
                }
            }

            // 執行爬取函數並處理結果
            scrapeDOMContent().then(() => {
                console.log('✅ DOM scraping completed successfully');
                process.exit(0);  // 成功退出
            }).catch((error) => {
                console.error('💥 DOM scraping failed:', error);
                process.exit(1);  // 失敗退出
            });
        JS;

        // 設定腳本保存路徑
        $scriptPath = storage_path('app/temp/scraper_dom.js');

        // 確保臨時目錄存在
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // 將生成的腳本寫入文件
        file_put_contents($scriptPath, $script);

        $this->info("✅ Script created: {$scriptPath}");

        return $scriptPath;
    }
    
    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath Puppeteer 腳本文件路徑
     * @return array|null 返回解析後的結果資料，失敗時返回 null
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation...');

        // 獲取腳本所在目錄，並將工作目錄切換到該目錄
        // 這樣可以確保腳本生成的臨時文件（如截圖、結果文件）在同一目錄
        $workingDir = dirname($scriptPath);

        // 在指定目錄執行 Node.js 腳本
        // 增加超時時間到 60 分鐘（6000秒），因為需要爬取多頁數據
        $result = Process::path($workingDir)->timeout(6000)->run("node " . basename($scriptPath));

        // 顯示瀏覽器執行的輸出信息
        $this->line(""); // 空行
        $this->line("📋 Browser Output:");
        $this->line($result->output());

        // 檢查執行是否失敗
        if ($result->failed()) {
            $this->error("❌ Browser automation failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        // 讀取腳本生成的結果文件
        $resultFile = $workingDir . '/scraped_result.json';

        if (file_exists($resultFile)) {
            // 讀取並解析 JSON 文件
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }

        $this->error("❌ No result file found");
        return null;
    }
    
    /**
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        // 檢查爬取是否成功
        if (!$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }

        // 提取 DOM 資料
        $domData = $result['domData'] ?? [];
        
        // 獲取查詢參數（account_number 和 date）
        $queryParams = $result['queryParams'] ?? [];

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存合併後的表格資料到單一 JSON 文件（主要輸出文件）
        $allData = [];
        $totalRows = 0;
        $headers = [];
        
        // 首先嘗試從 tables 中提取數據
        if (!empty($domData['tables'])) {
            foreach ($domData['tables'] as $tableIndex => $table) {
                if (!empty($table['data'])) {
                    // 將當前表格的所有數據添加到總數組中
                    $allData = array_merge($allData, $table['data']);
                    $totalRows += count($table['data']);
                    
                    // 保存表頭（使用第一個表格的表頭）
                    if (empty($headers) && !empty($table['headers'])) {
                        $headers = $table['headers'];
                    }
                }
            }
        }
        
        // 如果 tables 為空或沒有數據，嘗試從 pages 中提取數據
        if (empty($allData) && !empty($domData['pages'])) {
            foreach ($domData['pages'] as $page) {
                if (!empty($page['tables'])) {
                    foreach ($page['tables'] as $table) {
                        if (!empty($table['data'])) {
                            $allData = array_merge($allData, $table['data']);
                            $totalRows += count($table['data']);
                            
                            // 保存表頭（使用第一個表格的表頭）
                            if (empty($headers) && !empty($table['headers'])) {
                                $headers = $table['headers'];
                            }
                        }
                    }
                }
            }
        }
        
        // 如果有數據，保存合併後的數據
        if (!empty($allData)) {
            // 創建合併後的數據結構（單一 table，所有數據在一個 data 數組中）
            $mergedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                    'queryParams' => $queryParams,
                ],
                'table' => [
                    'headers' => $headers,
                    'headerCount' => count($headers),
                    'totalPages' => $domData['totalPages'] ?? 1,
                    'rowCount' => $totalRows,
                    'data' => $allData  // 所有頁面的數據都在這裡
                ]
            ];
            
            // 保存合併後的數據到單一 JSON 文件
            $mergedFileName = "scraped_data/dom_merged_all_pages_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // 將截圖從臨時目錄移動到永久存儲目錄
        $screenshots = [
            'step_13_final_page.png' => "step_13_final_page_{$timestamp}.png",
        ];
        
        foreach ($screenshots as $srcName => $dstName) {
            $srcPath = storage_path('app/temp/' . $srcName);
            $dstPath = storage_path("app/scraped_data/{$dstName}");
            
            if (file_exists($srcPath)) {
                rename($srcPath, $dstPath);
                $this->info("📸 Screenshot saved: {$dstName}");
            }
        }
        
        // 移動所有日期連結的截圖文件（step_date_*.png）
        $tempDir = storage_path('app/temp');
        $scrapedDataDir = storage_path('app/scraped_data');
        
        // 確保目標目錄存在
        if (!is_dir($scrapedDataDir)) {
            mkdir($scrapedDataDir, 0755, true);
        }
        
        if (is_dir($tempDir)) {
            $dateScreenshots = glob($tempDir . '/step_date_*.png');
            $this->info("📸 Found " . count($dateScreenshots) . " date link screenshot(s)");
            
            foreach ($dateScreenshots as $srcPath) {
                $fileName = basename($srcPath);
                // 添加時間戳到文件名
                $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                $newFileName = $nameWithoutExt . '_' . $timestamp . '.' . $extension;
                $dstPath = $scrapedDataDir . '/' . $newFileName;
                
                if (file_exists($srcPath)) {
                    if (rename($srcPath, $dstPath)) {
                        $this->info("📸 Date link screenshot saved: {$newFileName}");
                    } else {
                        $this->warn("⚠️  Failed to move screenshot: {$fileName}");
                    }
                } else {
                    $this->warn("⚠️  Screenshot file not found: {$srcPath}");
                }
            }
        } else {
            $this->warn("⚠️  Temp directory not found: {$tempDir}");
        }
        
        // 移動所有日期數據文件（date_data_*.json）
        if (is_dir($tempDir)) {
            $dateDataFiles = glob($tempDir . '/date_data_*.json');
            $this->info("📊 Found " . count($dateDataFiles) . " date data file(s)");
            
            foreach ($dateDataFiles as $srcPath) {
                $fileName = basename($srcPath);
                // 添加時間戳到文件名
                $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                $newFileName = $nameWithoutExt . '_' . $timestamp . '.' . $extension;
                $dstPath = $scrapedDataDir . '/' . $newFileName;
                
                    if (file_exists($srcPath)) {
                        if (rename($srcPath, $dstPath)) {
                            $this->info("📊 Date data file saved: {$newFileName}");
                            
                            // 讀取並顯示數據摘要
                            try {
                                $dateData = json_decode(file_get_contents($dstPath), true);
                                if ($dateData) {
                                    // 從新的數據結構中讀取信息
                                    $metadata = $dateData['metadata'] ?? [];
                                    $totalRows = $dateData['rowCount'] ?? 0;
                                    $totalPages = $metadata['totalPages'] ?? 0;
                                    $pagesScraped = $metadata['pagesScraped'] ?? 0;
                                    $date = $metadata['date'] ?? 'N/A';
                                    $queryParams = $metadata['queryParams'] ?? [];
                                    
                                    $this->info("   📅 Date: {$date}");
                                    $this->info("   📄 Total pages: {$totalPages}");
                                    $this->info("   ✅ Pages scraped: {$pagesScraped}");
                                    $this->info("   📊 Total rows: {$totalRows}");
                                    
                                    if (!empty($queryParams)) {
                                        $this->info("   🔍 Query params:");
                                        if (!empty($queryParams['date_start'])) {
                                            $this->info("      - Date start: " . $queryParams['date_start']);
                                        }
                                        if (!empty($queryParams['date_end'])) {
                                            $this->info("      - Date end: " . $queryParams['date_end']);
                                        }
                                        if (!empty($queryParams['account_number'])) {
                                            $this->info("      - Account number: " . $queryParams['account_number']);
                                        }
                                    }
                                }
                            } catch (\Exception $e) {
                                $this->warn("   ⚠️  Could not read date data file: " . $e->getMessage());
                            }
                        } else {
                            $this->warn("⚠️  Failed to move date data file: {$fileName}");
                        }
                    } else {
                        $this->warn("⚠️  Date data file not found: {$srcPath}");
                    }
            }
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}

