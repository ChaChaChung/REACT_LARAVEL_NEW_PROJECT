<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - WOW (使用 sessionStorage 登入)
 * 此命令會使用 sessionStorage 中的 dashboardToken 進行登入，然後截圖
 */
class ScrapeBrowserWowDomDetail extends Command
{
    use HasAgentAuth;
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-wow-dom-detail {url} {date_start?} {date_end?}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 開始日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     * {date_end?} - 結束日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     */
    protected $signature = 'agent:scrape-wow-dom-detail {url} {date_start?} {date_end?} {--concurrency=4}';

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
        $concurrency = $this->option('concurrency');
        
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
        $this->info("Concurrency: {$concurrency}");
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
        $scriptPath = $this->createPuppeteerScript($domain, $url, $token, $dateStart, $dateEnd, $lang, $concurrency);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理截圖和數據
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
     * 創建 Puppeteer 自動化腳本（使用 sessionStorage 登入）
     * @param string $domain 登入頁面網址
     * @param string $url 要爬取的目標網址
     * @param string $token 登入 Token
     * @param string|null $dateStart 開始日期（格式：YYYY-MM-DD）
     * @param string|null $dateEnd 結束日期（格式：YYYY-MM-DD）
     * @param string|null $lang 語言設定
     * @param int $concurrency 併發數量
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $url, $token, $dateStart = null, $dateEnd = null, $lang = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $urlJs = json_encode($url);
        // 將 date 轉換為 JavaScript 可用的格式（與 GLC 一致）
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';
        
        // 獲取 WOW sessionStorage 登入程式碼片段（主頁面用）
        $wowLoginCode = $this->generateWowPuppeteerLoginInfoCode('page', $token, $lang);
        // 獲取 WOW sessionStorage 登入程式碼片段（併發頁面用）
        $wowLoginCodeForNewPage = $this->generateWowPuppeteerLoginInfoCode('newPage', $token, $lang);
        
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
             * 併發控制器：限制同時執行的 Promise 數量
             * @param {Array} items - 準備要處理的項目列表
             * @param {Number} limit - 併發數量上限
             * @param {Function} fn - 要執行的函數，接收 (item, index) 兩個參數
             * @return {Promise<Array>} 返回所有執行結果的陣列
             */
            async function promiseAllWithLimit(items, limit, fn) {
                const results = [];
                const executing = [];
                let completedCount = 0;
                const totalItems = items.length;
                
                for (const [index, item] of items.entries()) {
                    const promise = Promise.resolve().then(() => fn(item, index))
                        .then((result) => {
                            completedCount++;
                            return result;
                        });
                    
                    results.push(promise);
                    
                    if (limit <= items.length) {
                        const executing_promise = promise.then(() => 
                            executing.splice(executing.indexOf(executing_promise), 1)
                        );
                        
                        executing.push(executing_promise);
                        
                        if (executing.length >= limit) {
                            await Promise.race(executing);
                        }
                    }
                }
                
                console.log('⏳ Waiting for all pages to complete...');
                return Promise.all(results);
            }

            /**
             * WOW 使用 sessionStorage 登入流程
             */
            async function loginWithSessionStorageAndScreenshot() {
                // 啟動無頭瀏覽器（在背景執行）
                const browser = await puppeteer.launch({
                    headless: 'new', // 無頭模式，在背景執行
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
                    const targetUrl = $urlJs.replace(/^"|"\$/g, '');
                    
                    // 使用 trait 中的方法設置 sessionStorage 和 cookie
                    $wowLoginCode
                    
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
                            }
                            
                            // 等待日期選擇器出現（增加等待時間確保完全打開）
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 驗證日期選擇器是否已打開
                            const datePickerOpened = await page.evaluate(() => {
                                const startDateInput = document.querySelector('input.el-input__inner[placeholder="开始日期"], input.el-input__inner[placeholder="Start Date"]');
                                const endDateInput = document.querySelector('input.el-input__inner[placeholder="结束日期"], input.el-input__inner[placeholder="End Date"]');
                                return !!(startDateInput || endDateInput);
                            });
                            
                            if (!datePickerOpened) {
                                await new Promise(resolve => setTimeout(resolve, 2000));
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
                                
                                await new Promise(resolve => setTimeout(resolve, 500));
                            }

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
                                    console.log('✅ Search button clicked');
                                }
                            } catch (e) {
                                console.log('⚠️  Method 1 failed: ' + e.message);
                            }
                            
                            if (buttonClicked) {
                                // 等待搜索結果載入
                                console.log('⏳ Waiting for search results...');
                                
                                // 等待表格出現
                                await page.waitForSelector('table.el-table, table.el-table__header, table.el-table__body, table[class*="el-table"], .el-table, .el-table__header, .el-table__body', { timeout: 15000 }).catch(() => {
                                    console.log('⚠️  Table not found after search');
                                });
                                
                                // 等待表格數據行出現
                                await page.waitForSelector('table.el-table tbody tr, table.el-table__body tbody tr, table[class*="el-table"] tbody tr, .el-table tbody tr', { timeout: 10000 }).catch(() => {});
                                
                                await new Promise(resolve => setTimeout(resolve, 2000));
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
                    
                    // ========== 步驟 1：爬取第一頁，獲取分頁資訊 ==========
                    console.log('📄 Step 1: Extracting first page and pagination info...');
                    
                    // 確保表格已載入
                    let tableFound = false;
                    for (let retry = 0; retry < 5; retry++) {
                        try {
                            await page.waitForSelector('table.el-table, table.el-table__header, table.el-table__body, table[class*="el-table"], .el-table, .el-table__header, .el-table__body', { timeout: 10000 }).catch(() => {});
                            await page.waitForSelector('table.el-table tbody tr, table.el-table__body tbody tr, table[class*="el-table"] tbody tr, .el-table tbody tr', { timeout: 5000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            const tableCheck = await page.evaluate(() => {
                                let table = document.querySelector('table.el-table');
                                let bodyTable = document.querySelector('table.el-table__body');
                                
                                if (!table && !bodyTable) {
                                    const allTables = document.querySelectorAll('table');
                                    for (let t of allTables) {
                                        if (t.className && (t.className.includes('el-table') || t.className.includes('el-table__header') || t.className.includes('el-table__body'))) {
                                            if (t.className.includes('el-table__body')) {
                                                bodyTable = t;
                                            } else if (!table) {
                                                table = t;
                                            }
                                        }
                                    }
                                }
                                
                                const finalTable = bodyTable || table;
                                if (!finalTable) return false;
                                const rows = finalTable.querySelectorAll('tbody tr, tr');
                                return rows.length > 0;
                            });
                            
                            if (tableCheck) {
                                tableFound = true;
                                break;
                            }
                        } catch (e) {
                            console.log('⚠️  Retry ' + (retry + 1) + '/5: Table not found yet, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        }
                    }
                    
                    // 提取表格資料的函數（與 GLC 一致）
                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            // 嘗試多種方式查找 Element UI 表格
                            let headerTable = document.querySelector('table.el-table__header');
                            let bodyTable = document.querySelector('table.el-table__body');
                            let table = document.querySelector('table.el-table');
                            
                            // 如果找不到完整的表格，嘗試查找包含 el-table 類的表格
                            if (!table && !headerTable) {
                                const allTables = document.querySelectorAll('table');
                                for (let t of allTables) {
                                    if (t.className && (t.className.includes('el-table') || t.className.includes('el-table__header') || t.className.includes('el-table__body'))) {
                                        if (t.className.includes('el-table__header')) {
                                            headerTable = t;
                                        } else if (t.className.includes('el-table__body')) {
                                            bodyTable = t;
                                        } else if (!table) {
                                            table = t;
                                        }
                                    }
                                }
                            }
                            
                            // 如果完全找不到表格，返回錯誤
                            if (!table && !headerTable && !bodyTable) {
                                return {
                                    found: false,
                                    error: 'Table el-table not found'
                                };
                            }

                            // 提取表頭
                            let headers = [];
                            let thead = null;
                            
                            if (headerTable) {
                                thead = headerTable.querySelector('thead');
                            } else if (table) {
                                thead = table.querySelector('thead');
                            }
                            
                            if (thead) {
                                const headerRows = Array.from(thead.querySelectorAll('tr'));
                                if (headerRows.length > 0) {
                                    const headerCells = headerRows[0].querySelectorAll('th, td');
                                    headers = Array.from(headerCells).map(cell => {
                                        const cellDiv = cell.querySelector('div.cell');
                                        if (cellDiv) {
                                            return cellDiv.textContent.trim();
                                        }
                                        return cell.textContent.trim();
                                    });
                                }
                            }

                            // 提取資料行
                            let rows = [];
                            let dataStartIndex = 0;
                            let tbody = null;
                            
                            if (bodyTable) {
                                tbody = bodyTable.querySelector('tbody');
                                if (tbody) {
                                    rows = Array.from(tbody.querySelectorAll('tr'));
                                } else {
                                    rows = Array.from(bodyTable.querySelectorAll('tr'));
                                }
                            } else if (table) {
                                tbody = table.querySelector('tbody');
                                if (tbody) {
                                    rows = Array.from(tbody.querySelectorAll('tr'));
                                } else {
                                    rows = Array.from(table.querySelectorAll('tr'));
                                    if (thead || (rows.length > 0 && rows[0].querySelectorAll('th').length > 0)) {
                                        dataStartIndex = 1;
                                    }
                                }
                            }

                            // 將資料行轉換為對象數組
                            const dataRows = rows.slice(dataStartIndex)
                                .map((row, rowIndex) => {
                                    const cells = Array.from(row.querySelectorAll('td'));
                                    const rowData = {};
                                    
                                    if (headers && headers.length > 0) {
                                        headers.forEach((header, colIndex) => {
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
                                            
                                            let cellValue = null;
                                            if (cells[colIndex]) {
                                                const cellDiv = cells[colIndex].querySelector('div.cell');
                                                if (cellDiv) {
                                                    cellValue = cellDiv.textContent.trim();
                                                } else {
                                                    cellValue = cells[colIndex].textContent.trim();
                                                }
                                            }
                                            rowData[finalHeader] = cellValue;
                                        });
                                    } else {
                                        cells.forEach((cell, colIndex) => {
                                            const cellDiv = cell.querySelector('div.cell');
                                            let cellValue = null;
                                            if (cellDiv) {
                                                cellValue = cellDiv.textContent.trim();
                                            } else {
                                                cellValue = cell ? cell.textContent.trim() : null;
                                            }
                                            rowData['column_' + colIndex] = cellValue;
                                        });
                                    }
                                    
                                    rowData._rowIndex = rowIndex;
                                    return rowData;
                                })
                                .filter(rowData => {
                                    const firstValue = Object.values(rowData)[0];
                                    return firstValue !== '小計' && firstValue !== '總計';
                                });

                            const finalTable = bodyTable || table || headerTable;
                            
                            return {
                                found: true,
                                headers: headers,
                                headerCount: headers.length,
                                rowCount: dataRows.length,
                                data: dataRows
                            };
                        });
                    };
                    
                    // 提取第一頁的表格資料
                    const firstPageData = await extractTableData(page);
                    
                    if (!firstPageData.found) {
                        const errorMsg = firstPageData.error || 'No table found on first page';
                        console.error('❌ ' + errorMsg);
                        throw new Error(errorMsg);
                    }
                    
                    console.log('✅ First page data scraped: ' + firstPageData.rowCount + ' rows found');
                    
                    // 等待分頁組件載入
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 滾動到頁面底部，確保分頁組件可見
                    await page.evaluate(() => {
                        window.scrollTo(0, document.body.scrollHeight);
                    });
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 獲取所有分頁資訊
                    const paginationInfo = await page.evaluate(() => {
                        let totalPages = 1;
                        
                        // 優先方式：查找總頁數顯示（Element UI 分頁組件）
                        const elPagination = document.querySelector('.el-pagination');
                        if (elPagination) {
                            const paginationText = elPagination.textContent || elPagination.innerText || '';
                            
                            // 查找 "1 / 2" 格式
                            const slashMatch = paginationText.match(/(\d+)\s*\/\s*(\d+)/);
                            if (slashMatch && slashMatch[2]) {
                                totalPages = parseInt(slashMatch[2]);
                            } else {
                                // 查找 "共 2 頁" 或 "total 2 pages" 格式
                                const totalMatch = paginationText.match(/(?:共|總|total|of)\s*(\d+)\s*(?:頁|page|pages)/i);
                                if (totalMatch && totalMatch[1]) {
                                    totalPages = parseInt(totalMatch[1]);
                                }
                            }
                            
                            // 如果還是沒找到，嘗試查找分頁按鈕中的最大數字
                            if (totalPages === 1) {
                                const numberButtons = elPagination.querySelectorAll('.number');
                                let maxPageNum = 1;
                                numberButtons.forEach(btn => {
                                    const text = btn.textContent.trim();
                                    const pageNum = parseInt(text);
                                    if (!isNaN(pageNum) && pageNum > 0 && pageNum < 1000) {
                                        if (pageNum > maxPageNum) {
                                            maxPageNum = pageNum;
                                        }
                                    }
                                });
                                if (maxPageNum > 1) {
                                    totalPages = maxPageNum;
                                }
                            }
                        }
                        
                        // 方式2：檢查是否有「下一頁」按鈕
                        if (totalPages === 1) {
                            const nextLink = document.querySelector('a[rel="next"], .btn-next:not(.disabled), button.el-pagination__next:not(.disabled)');
                            if (nextLink && nextLink.offsetParent !== null) {
                                totalPages = 2;
                            }
                        }
                        
                        // 確保至少有 1 頁
                        if (totalPages < 1) {
                            totalPages = 1;
                        }
                        
                        return {
                            totalPages: totalPages,
                            currentUrl: window.location.href,
                            paginationFound: totalPages > 1
                        };
                    });
                    
                    console.log('📄 Total pages found: ' + paginationInfo.totalPages);
                    
                    // 如果總頁數為1，但第一頁有數據，檢查是否有下一頁按鈕
                    if (paginationInfo.totalPages === 1 && firstPageData.rowCount > 0) {
                        const hasNextPage = await page.evaluate(() => {
                            const nextLink = document.querySelector('a[rel="next"], .btn-next:not(.disabled), button.el-pagination__next:not(.disabled)');
                            return nextLink && nextLink.offsetParent !== null;
                        });
                        if (hasNextPage) {
                            paginationInfo.totalPages = 2;
                            console.log('📄 Updated total pages to 2 (found next button)');
                        }
                    }
                    
                    // ========== 步驟 2：順序爬取所有頁面（在同一頁面上點擊分頁按鈕，維持日期條件）==========
                    console.log('🚀 Step 2: Starting sequential scraping for all pages...');
                    
                    // 準備要爬取的頁面列表（從第 2 頁開始，因為第 1 頁已經爬了）
                    const pagesToScrape = [];
                    for (let i = 2; i <= paginationInfo.totalPages; i++) {
                        pagesToScrape.push({ pageNumber: i });
                    }
                    
                    // 順序爬取函數（在第一頁點擊分頁按鈕切換，維持日期條件）
                    const scrapePage = async (pageInfo, index) => {
                        const startTime = Date.now();
                        
                        try {
                            // 查找並點擊對應頁碼的分頁按鈕
                            const buttonClicked = await page.evaluate((targetPageNumber) => {
                                // 查找 Element UI 的分頁按鈕
                                const elPagination = document.querySelector('.el-pagination');
                                if (elPagination) {
                                    // 查找所有分頁按鈕（.number 類）
                                    const numberButtons = elPagination.querySelectorAll('.number');
                                    for (let btn of numberButtons) {
                                        const text = btn.textContent.trim();
                                        const pageNum = parseInt(text);
                                        if (pageNum === targetPageNumber) {
                                            btn.click();
                                            return true;
                                        }
                                    }
                                }
                                
                                return false;
                            }, pageInfo.pageNumber);

                            if (!buttonClicked) {
                                console.log('⚠️  Could not find page ' + pageInfo.pageNumber + ' button');
                            }

                            // 等待頁面切換和數據加載（減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 等待表格更新（使用較短的超時時間）
                            await page.waitForSelector('table.el-table, table.el-table__header, table.el-table__body, table[class*="el-table"], .el-table, .el-table__header, .el-table__body', { timeout: 10000 }).catch(() => { });
                            
                            // 等待數據完全加載（優化：減少等待次數和時間）
                            let rowCount = 0;
                            let waitAttempts = 0;
                            const maxWaitAttempts = 5; // 減少最大等待次數
                            
                            while (waitAttempts < maxWaitAttempts) {
                                await new Promise(resolve => setTimeout(resolve, 300)); // 減少等待時間
                                
                                rowCount = await page.evaluate(() => {
                                    const bodyTable = document.querySelector('table.el-table__body');
                                    const table = document.querySelector('table.el-table');
                                    const finalTable = bodyTable || table;
                                    if (finalTable) {
                                        const rows = finalTable.querySelectorAll('tbody tr, tr');
                                        return rows.length;
                                    }
                                    return 0;
                                });
                                
                                // 如果行數穩定（連續兩次檢查相同），認為數據已加載完成
                                if (waitAttempts > 0 && rowCount > 0) {
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    const rowCount2 = await page.evaluate(() => {
                                        const bodyTable = document.querySelector('table.el-table__body');
                                        const table = document.querySelector('table.el-table');
                                        const finalTable = bodyTable || table;
                                        if (finalTable) {
                                            const rows = finalTable.querySelectorAll('tbody tr, tr');
                                            return rows.length;
                                        }
                                        return 0;
                                    });
                                    
                                    if (rowCount === rowCount2) {
                                        break;
                                    }
                                }
                                
                                waitAttempts++;
                            }
                            
                            // 減少額外等待時間
                            await new Promise(resolve => setTimeout(resolve, 500));
                            
                            // 提取表格資料
                            const tableData = await extractTableData(page);
                            
                            const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
                            
                            return {
                                pageNumber: pageInfo.pageNumber,
                                tables: [tableData]
                            };
                            
                        } catch (error) {
                            const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
                            console.error('❌ [Page ' + pageInfo.pageNumber + '] Error after ' + elapsed + 's: ' + error.message);
                            return {
                                pageNumber: pageInfo.pageNumber,
                                tables: [],
                                error: error.message
                            };
                        }
                    };
                    
                    // 順序爬取所有頁面（在第一頁點擊分頁按鈕，維持日期條件）
                    const startTime = Date.now();
                    
                    const otherPagesData = [];
                    for (let i = 0; i < pagesToScrape.length; i++) {
                        const pageData = await scrapePage(pagesToScrape[i], i);
                        otherPagesData.push(pageData);
                    }
                    
                    const totalElapsed = ((Date.now() - startTime) / 1000).toFixed(2);
                    console.log('⏱️  Total time for all pages: ' + totalElapsed + 's');
                    
                    // 合併第一頁和其他頁面的資料
                    const allPagesData = [
                        {
                            pageNumber: 1,
                            tables: [firstPageData]
                        },
                        ...otherPagesData
                    ];
                    
                    // 按頁碼排序
                    allPagesData.sort((a, b) => a.pageNumber - b.pageNumber);
                    
                    // 計算總行數
                    const totalRowsFromPages = allPagesData.reduce((sum, pageData) => {
                        if (pageData.tables && pageData.tables.length > 0) {
                            return sum + pageData.tables.reduce((s, table) => s + (table.rowCount || 0), 0);
                        }
                        return sum;
                    }, 0);
                    console.log('📊 Total rows from all pages: ' + totalRowsFromPages);
                    
                    console.log('✅ All pages scraped successfully!');
                    
                    // 合併所有頁面的表格資料
                    const allTables = [];
                    let totalDataRows = 0;
                    allPagesData.forEach(pageData => {
                        if (pageData.tables && pageData.tables.length > 0) {
                            pageData.tables.forEach(table => {
                                if (table.data && table.data.length > 0) {
                                    totalDataRows += table.data.length;
                                }
                                allTables.push(table);
                            });
                        }
                    });
                    
                    // 合併所有數據行
                    const allDataRows = [];
                    allPagesData.forEach(pageData => {
                        if (pageData.tables && pageData.tables.length > 0) {
                            pageData.tables.forEach(table => {
                                if (table.data && table.data.length > 0) {
                                    allDataRows.push(...table.data);
                                }
                            });
                        }
                    });
                    
                    // 使用第一頁的表頭
                    const headers = firstPageData.headers || [];
                    
                    // 構建最終的表格數據
                    const mergedTableData = {
                        found: true,
                        headers: headers,
                        headerCount: headers.length,
                        rowCount: allDataRows.length,
                        totalPages: allPagesData.length,
                        data: allDataRows
                    };
                    
                    console.log('📊 Merged table data: ' + mergedTableData.rowCount + ' total rows from ' + mergedTableData.totalPages + ' pages');
                    
                    // 截圖
                    console.log('📸 Taking screenshot...');
                    const screenshotPath = path.join(workingDir, 'wow_screenshot.png');
                    await page.screenshot({
                        path: screenshotPath,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshotPath);
                    
                    // 保存表格數據為 JSON 文件
                    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').substring(0, 19);
                    const tableDataPath = path.join(workingDir, 'table_data.json');
                    const tableDataFile = {
                        metadata: {
                            timestamp: timestamp,
                            url: page.url(),
                            queryParams: {
                                date_start: dateStartParsed,
                                date_end: dateEndParsed
                            },
                            totalPages: mergedTableData.totalPages || 1
                        },
                        headers: mergedTableData.headers || [],
                        totalRows: mergedTableData.rowCount || 0,
                        data: mergedTableData.data || []
                    };
                    fs.writeFileSync(tableDataPath, JSON.stringify(tableDataFile, null, 2));
                    console.log('💾 Table data saved to: ' + tableDataPath);
                    
                    // 返回結果
                    const result = {
                        success: true,
                        url: page.url(),
                        title: await page.title(),
                        screenshot: screenshotPath,
                        tableData: mergedTableData,
                        tableDataPath: tableDataPath
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
     * 處理爬取的數據
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('');
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');

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
     * 處理表格數據文件，將它從臨時目錄移動到永久儲存目錄
     * @param string $workingDir 工作目錄（臨時目錄）
     * @param string $timestamp 時間戳
     */
    private function processTableData($workingDir, $timestamp)
    {
        $this->info('📊 Processing table data...');

        // 處理截圖
        $screenshotsDir = storage_path('app/scraped_data');
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }

        // 處理截圖文件
        $screenshotFile = 'wow_screenshot.png';
        $screenshotSrc = $workingDir . '/' . $screenshotFile;
        if (file_exists($screenshotSrc)) {
            $screenshotDst = $screenshotsDir . '/wow_' . $timestamp . '_' . $screenshotFile;
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved: {$screenshotDst}");
        }

        // 處理表格數據文件
        $tableDataFile = $workingDir . '/table_data.json';
        if (file_exists($tableDataFile)) {
            $dataDir = storage_path('app/scraped_data');
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            // 讀取原始數據
            $content = file_get_contents($tableDataFile);
            $rawData = json_decode($content, true);

            // 重新組織數據結構（與 ZGSLOT 保持一致）
            $processedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $rawData['metadata']['url'] ?? '',
                    'queryParams' => $rawData['metadata']['queryParams'] ?? []
                ],
                'headers' => $rawData['headers'] ?? [],
                'totalPages' => $rawData['totalPages'] ?? 1,
                'totalRows' => $rawData['totalRows'] ?? 0,
                'data' => $rawData['data'] ?? []
            ];

            // 保存處理後的數據
            $destFilename = "wow_table_data_{$timestamp}.json";
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
