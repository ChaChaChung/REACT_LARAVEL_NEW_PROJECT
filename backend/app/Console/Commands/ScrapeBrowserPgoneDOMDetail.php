<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserPgoneDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-pgone-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {--concurrency=8} - 併發數量（可選，預設為 8，建議 8-16 以加快速度）
     */
    protected $signature = 'agent:scrape-pgone-dom-detail {url} {date_start?} {date_end?} {--concurrency=8}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from PGONE DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $date_start = $this->argument('date_start');
        $date_end = $this->argument('date_end');
        $concurrency = $this->option('concurrency');

        $this->info('=== Browser DOM Scraper (Concurrent) ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$date_start}");
        $this->info("Date End: {$date_end}");
        $this->info("Concurrency: {$concurrency}");

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $date_start, $date_end, $concurrency);

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
     * @param string|null $date_start 要選擇的開始日期（可選）
     * @param string|null $date_end 要選擇的結束日期（可選）
     * @param int $concurrency 併發數量
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date_start = null, $date_end = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段（主頁面用）
        $cookiesCodeForPage = $this->generatePgonePuppeteerCookiesCode('page');
        // 獲取認證 cookies 程式碼片段（併發頁面用）
        $cookiesCodeForNewPage = $this->generatePgonePuppeteerCookiesCode('newPage');

        // 將 date 轉換為 JavaScript 可用的格式
        $dateStartJs = $date_start ? json_encode(date('Y-m-d', strtotime($date_start))) : 'null';
        $dateEndJs = $date_end ? json_encode(date('Y-m-d', strtotime($date_end))) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 併發控制器：限制同時執行的 Promise 數量
             * @param {Array} items - 準備要處理的項目列表（例如要爬取的頁面資訊）
             * @param {Number} limit - 併發數量上限（同時最多執行幾個任務）
             * @param {Function} fn - 要執行的函數，接收 (item, index) 兩個參數
             * @return {Promise<Array>} 返回所有執行結果的陣列
             */
            async function promiseAllWithLimit(items, limit, fn) {
                // 創建兩個陣列來追蹤任務狀態
                const results = [];   // 儲存所有任務的 Promise（包含已完成和未完成的）
                const executing = []; // 儲存「正在執行中」的任務 Promise
                let completedCount = 0;
                const totalItems = items.length;
                
                // 所有要處理的項目執行迴圈
                for (const [index, item] of items.entries()) {
                    // 為每個項目創建一個 Promise
                    // Promise.resolve().then() 確保函數是異步執行的
                    const promise = Promise.resolve().then(() => fn(item, index))
                        .then((result) => {
                            completedCount++;
                            return result;
                        });
                    
                    // 將這個 Promise 加入結果陣列
                    // 注意：這裡只是「記錄」這個 Promise，任務可能還沒開始執行
                    results.push(promise);
                    
                    // 併發控制邏輯（核心部分）
                    if (limit <= items.length) {
                        // 創建一個「可追蹤」的 Promise
                        // 當原始 Promise 完成時，自動從 executing 陣列中移除自己
                        const executing_promise = promise.then(() => 
                            executing.splice(executing.indexOf(executing_promise), 1)
                        );
                        
                        // 將這個任務加入「執行中」的任務池
                        executing.push(executing_promise);
                        
                        // 如果執行中的任務數量達到上限
                        if (executing.length >= limit) {
                            // 使用 Promise.race 等待「任何一個」任務完成
                            // Promise.race 的特性：只要陣列中有一個 Promise 完成，就會 resolve
                            // 這樣可以確保：當一個任務完成後，立即可以開始下一個任務
                            await Promise.race(executing);
                            
                            // 執行到這裡時，表示至少有一個任務完成了
                            // 該任務已經自動從 executing 陣列中移除（見上面的 splice）
                            // 現在 executing.length < limit，可以繼續添加新任務
                        }
                    }
                }
                
                // 等待所有任務完成
                // Promise.all 會等待 results 陣列中的所有 Promise 都完成
                // 返回一個包含所有結果的陣列
                console.log('⏳ Waiting for all pages to complete...');
                return Promise.all(results);
            }

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容（併發版本）
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
                        const url = req.url();
                        // 更激進的資源攔截：阻止圖片、字體、媒體、CSS（如果不需要樣式）、websocket、manifest 等
                        // 只保留必要的 JS 和 XHR/fetch 請求
                        if (['image', 'font', 'media', 'stylesheet', 'websocket', 'manifest', 'texttrack'].includes(resourceType)) {
                            req.abort();
                        } else if (resourceType === 'script' && !url.includes('api') && !url.includes('ajax') && !url.includes('data')) {
                            // 阻止非 API 相關的 JS（可選，如果頁面需要這些 JS 可以註釋掉）
                            // req.abort();
                            req.continue();
                        } else {
                            req.continue();
                        }
                    });

                    $cookiesCodeForPage

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
                    // timeout: 20000 減少超時時間以加快速度
                    await page.goto('$url', {
                        waitUntil: 'domcontentloaded',
                        timeout: 20000
                    });
                    
                    // 等待頁面基本載入（減少等待時間）
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 簡化滾動操作（只滾動一次，減少等待時間）
                    await page.evaluate(() => {
                        window.scrollTo(0, document.body.scrollHeight);
                    });
                    await new Promise(resolve => setTimeout(resolve, 500));
                    await page.evaluate(() => {
                        window.scrollTo(0, 0);
                    });
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    let tableFound = false;
                    for (let retry = 0; retry < 15; retry++) {
                        const tableCheck = await page.evaluate(() => {
                            // 檢查外層容器
                            const elTableDiv = document.querySelector('div.el-table, .el-table');
                            // 檢查表頭表格
                            const headerTable = document.querySelector('table.el-table__header');
                            // 檢查表體表格
                            const bodyTable = document.querySelector('table.el-table__body');
                            // 檢查是否有任何包含 el-table 的元素
                            const anyElTable = document.querySelector('[class*="el-table"]');
                            
                            // 檢查表格是否有數據行
                            let hasDataRows = false;
                            if (bodyTable) {
                                const rows = bodyTable.querySelectorAll('tbody tr, tr');
                                hasDataRows = rows.length > 0;
                            } else if (elTableDiv) {
                                const innerBodyTable = elTableDiv.querySelector('table.el-table__body');
                                if (innerBodyTable) {
                                    const rows = innerBodyTable.querySelectorAll('tbody tr, tr');
                                    hasDataRows = rows.length > 0;
                                }
                            }
                            
                            return {
                                hasElTableDiv: !!elTableDiv,
                                hasHeaderTable: !!headerTable,
                                hasBodyTable: !!bodyTable,
                                hasAnyElTable: !!anyElTable,
                                hasDataRows: hasDataRows,
                                found: !!(elTableDiv || headerTable || bodyTable || anyElTable)
                            };
                        });
                        
                        if (tableCheck.found) {
                            console.log('✅ Table found!', JSON.stringify(tableCheck));
                            tableFound = true;
                            // 如果找到表格但沒有數據行，再等待一下
                            if (!tableCheck.hasDataRows) {
                                console.log('⚠️  Table found but no data rows yet, waiting...');
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            } else {
                                break;
                            }
                        }
                        
                        if (retry < 14) {
                            console.log('⏳ Retry ' + (retry + 1) + '/15: Waiting for table...');
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                    }
                    
                    if (!tableFound) {
                        // 獲取調試信息
                        const debugInfo = await page.evaluate(() => {
                            const allElTableElements = document.querySelectorAll('[class*="el-table"]');
                            const allTables = document.querySelectorAll('table');
                            return {
                                url: window.location.href,
                                title: document.title,
                                hasElTableDiv: !!document.querySelector('div.el-table, .el-table'),
                                hasHeaderTable: !!document.querySelector('table.el-table__header'),
                                hasBodyTable: !!document.querySelector('table.el-table__body'),
                                allTables: allTables.length,
                                allElTableElements: allElTableElements.length,
                                tableClasses: Array.from(allTables).map(t => t.className).slice(0, 5),
                                elTableClasses: Array.from(allElTableElements).map(el => el.className).slice(0, 5),
                                bodyHTML: document.body ? document.body.innerHTML.substring(0, 2000) : 'No body'
                            };
                        });
                        console.error('⚠️  Table not found after retries. Debug info:', JSON.stringify(debugInfo, null, 2));
                        console.log('⚠️  Continuing anyway, will try again later...');
                    }
                    
                    // 額外等待確保表格完全渲染
                    await new Promise(resolve => setTimeout(resolve, 1000));

                    // 解析 date
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
                        dateStartParsed = $dateStartJs !== 'null' ? $dateStartJs : null;
                        dateEndParsed = $dateEndJs !== 'null' ? $dateEndJs : null;
                    }

                    // 如果提供了 date_start 或 date_end，點擊 Start time input 打開日期選擇器
                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') || 
                        (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            console.log('📅 Setting up date range...');
                            
                            // 等待 Start time input 出現
                            await page.waitForSelector('input.el-range-input[placeholder="Start Time"]', { timeout: 10000 }).catch(() => {
                                console.log('⚠️  Start time input not found');
                            });
                            
                            // 點擊 Start time input 來打開日期選擇器
                            await page.click('input.el-range-input[placeholder="Start Time"]', { timeout: 5000 }).catch(() => {
                                console.log('⚠️  Could not click Start time input');
                            });
                            
                            // 等待日期選擇器出現（減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 300));
                            
                            // 如果提供了 date_start，填入 Start Date
                            if (dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') {
                                console.log('📅 Filling Start Date: ' + dateStartParsed);
                                
                                // 等待 Start Date input 出現
                                await page.waitForSelector('input.el-input__inner[placeholder="Start Date"]', { timeout: 5000 }).catch(() => {
                                    console.log('⚠️  Start Date input not found');
                                });
                                
                                // 填入開始日期
                                await page.evaluate((dateStartValue) => {
                                    const startDateInput = document.querySelector('input.el-input__inner[placeholder="Start Date"]');
                                    
                                    if (startDateInput) {
                                        startDateInput.value = '';
                                        startDateInput.value = dateStartValue;
                                        startDateInput.dispatchEvent(new Event('input', { bubbles: true }));
                                        startDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                                        startDateInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                }, dateStartParsed);
                                
                                await new Promise(resolve => setTimeout(resolve, 200));
                            }
                            
                            // 如果提供了 date_end，填入 End Date
                            if (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '') {
                                console.log('📅 Filling End Date: ' + dateEndParsed);
                                
                                // 等待 End Date input 出現（減少超時時間）
                                await page.waitForSelector('input.el-input__inner[placeholder="End Date"]', { timeout: 3000 }).catch(() => {
                                    console.log('⚠️  End Date input not found');
                                });
                                
                                // 填入結束日期
                                await page.evaluate((dateEndValue) => {
                                    const endDateInput = document.querySelector('input.el-input__inner[placeholder="End Date"]');
                                    
                                    if (endDateInput) {
                                        endDateInput.value = '';
                                        endDateInput.value = dateEndValue;
                                        endDateInput.dispatchEvent(new Event('input', { bubbles: true }));
                                        endDateInput.dispatchEvent(new Event('change', { bubbles: true }));
                                        endDateInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                }, dateEndParsed);
                                
                                await new Promise(resolve => setTimeout(resolve, 200));
                            }
                            
                            // 點擊 OK 按鈕確認日期選擇
                            console.log('🔘 Looking for OK button...');
                            
                            // 等待 OK 按鈕出現（減少超時時間）
                            await page.waitForSelector('button.el-button.el-picker-panel__link-btn.el-button--default.el-button--mini.is-plain', { timeout: 3000 }).catch(() => {
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
                            
                            // 等待日期選擇器關閉（減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 300));
                            
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
                            
                            // 先獲取點擊前的數據行數（用於驗證）
                            const rowCountBefore = await page.evaluate(() => {
                                // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                let bodyTable = null;
                                const elTableDiv = document.querySelector('div.el-table, .el-table');
                                if (elTableDiv) {
                                    bodyTable = elTableDiv.querySelector('table.el-table__body');
                                }
                                if (!bodyTable) {
                                    bodyTable = document.querySelector('table.el-table__body');
                                }
                                const finalTable = bodyTable;
                                if (finalTable) {
                                    const rows = finalTable.querySelectorAll('tbody tr, tr');
                                    return rows.length;
                                }
                                return 0;
                            });
                            console.log('📊 Rows before search: ' + rowCountBefore);
                            
                            // 使用多種方式查找並點擊按鈕
                            let buttonClicked = false;
                            
                            // 方式1：使用 Puppeteer 的原生方法點擊
                            try {
                                // 等待按鈕出現並可點擊
                                await page.waitForSelector('button.el-button.el-button--primary', { timeout: 5000, visible: true }).catch(() => {});
                                
                                // 查找按鈕並獲取其選擇器
                                const buttonInfo = await page.evaluate(() => {
                                    const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--primary'));
                                    for (let btn of buttons) {
                                        const text = btn.textContent.trim();
                                        if (text === 'Query') {
                                            // 添加唯一標識
                                            const uniqueId = 'search-btn-' + Date.now();
                                            btn.setAttribute('data-puppeteer-search-id', uniqueId);
                                            return {
                                                found: true,
                                                selector: 'button[data-puppeteer-search-id="' + uniqueId + '"]',
                                                text: text
                                            };
                                        }
                                    }
                                    return { found: false };
                                });
                                
                                if (buttonInfo.found) {
                                    // 滾動到按鈕位置
                                    await page.evaluate((selector) => {
                                        const btn = document.querySelector(selector);
                                        if (btn) {
                                            btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        }
                                    }, buttonInfo.selector);
                                    
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    
                                    // 高亮按鈕（添加紅色邊框）
                                    await page.evaluate((selector) => {
                                        const btn = document.querySelector(selector);
                                        if (btn) {
                                            // 保存原始樣式
                                            btn.setAttribute('data-original-style', btn.getAttribute('style') || '');
                                            // 添加紅色邊框高亮
                                            btn.style.border = '5px solid red';
                                            btn.style.boxShadow = '0 0 20px red';
                                            btn.style.zIndex = '9999';
                                            btn.style.position = 'relative';
                                        }
                                    }, buttonInfo.selector);
                                    
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    
                                    // 恢復原始樣式
                                    await page.evaluate((selector) => {
                                        const btn = document.querySelector(selector);
                                        if (btn) {
                                            const originalStyle = btn.getAttribute('data-original-style');
                                            if (originalStyle) {
                                                btn.setAttribute('style', originalStyle);
                                            } else {
                                                btn.removeAttribute('style');
                                            }
                                        }
                                    }, buttonInfo.selector);
                                    
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    
                                    // 使用 Puppeteer 的 click 方法
                                    await page.click(buttonInfo.selector, { timeout: 5000 });
                                    
                                    buttonClicked = true;
                                }
                            } catch (e) {
                                console.log('⚠️  Method 1 failed: ' + e.message);
                            }
                            
                            // 方式2：如果方式1失敗，使用 evaluateHandle
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--default'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text === 'Search') {
                                                return btn;
                                            }
                                        }
                                        return null;
                                    });
                                    
                                    if (buttonHandle && buttonHandle.asElement()) {
                                        // 滾動到按鈕
                                        await buttonHandle.asElement().scrollIntoView();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        
                                        // 高亮按鈕（通過選擇器）
                                        await page.evaluate(() => {
                                            const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--default'));
                                            for (let btn of buttons) {
                                                const text = btn.textContent.trim();
                                                if (text === 'Search') {
                                                    btn.setAttribute('data-original-style', btn.getAttribute('style') || '');
                                                    btn.style.border = '5px solid red';
                                                    btn.style.boxShadow = '0 0 20px red';
                                                    btn.style.zIndex = '9999';
                                                    btn.style.position = 'relative';
                                                    break;
                                                }
                                            }
                                        });
                                        
                                        // 恢復樣式
                                        await page.evaluate(() => {
                                            const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--default'));
                                            for (let btn of buttons) {
                                                const text = btn.textContent.trim();
                                                if (text === 'Search') {
                                                    const originalStyle = btn.getAttribute('data-original-style');
                                                    if (originalStyle) {
                                                        btn.setAttribute('style', originalStyle);
                                                    } else {
                                                        btn.removeAttribute('style');
                                                    }
                                                    break;
                                                }
                                            }
                                        });
                                        
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        
                                        await buttonHandle.asElement().click();
                                        await buttonHandle.dispose();
                                        
                                        buttonClicked = true;
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 2 failed: ' + e.message);
                                }
                            }
                            
                            // 方式3：如果還是失敗，嘗試查找任何包含 "Search" 的按鈕
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text === 'Search') {
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
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 3 failed: ' + e.message);
                                }
                            }
                            
                            // 方式4：向後兼容，查找 "搜尋" 按鈕
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text === '搜尋') {
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
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 4 failed: ' + e.message);
                                }
                            }
                            
                            if (buttonClicked) {
                                // 智能等待：等待表格數據真正更新
                                // 監聽表格內容變化，或者等待足夠時間
                                let rowCountAfter = 0;
                                let waitAttempts = 0;
                                const maxWaitAttempts = 15; // 最多等待15次，每次1秒（總共15秒）
                                let dataChanged = false;
                                
                                // 先等待初始加載
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                while (waitAttempts < maxWaitAttempts) {
                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                    
                                    rowCountAfter = await page.evaluate(() => {
                                        // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                        let bodyTable = null;
                                        const elTableDiv = document.querySelector('div.el-table, .el-table');
                                        if (elTableDiv) {
                                            bodyTable = elTableDiv.querySelector('table.el-table__body');
                                        }
                                        if (!bodyTable) {
                                            bodyTable = document.querySelector('table.el-table__body');
                                        }
                                        const finalTable = bodyTable;
                                        if (finalTable) {
                                            const rows = finalTable.querySelectorAll('tbody tr, tr');
                                            return rows.length;
                                        }
                                        return 0;
                                    });
                                    
                                    // 檢查行數是否變化
                                    if (rowCountAfter !== rowCountBefore) {
                                        dataChanged = true;
                                        // 再等待1秒確保數據完全加載
                                        await new Promise(resolve => setTimeout(resolve, 1000));
                                        break;
                                    }
                                    
                                    // 如果已經等待足夠時間（至少5秒），繼續執行
                                    // 可能是日期範圍內沒有新數據，或者數據已經正確加載
                                    if (waitAttempts >= 4) {
                                        // 再等待1秒後繼續
                                        await new Promise(resolve => setTimeout(resolve, 1000));
                                        break;
                                    }
                                    
                                    waitAttempts++;
                                }
                                
                                // 確保表格出現
                                await page.waitForSelector('table.el-table, table.el-table__header, table.el-table__body, table[class*="el-table"], .el-table, .el-table__header, .el-table__body', { timeout: 10000 }).catch(() => {});
                                
                                // 等待表格數據行出現
                                await page.waitForSelector('table.el-table tbody tr, table.el-table__body tbody tr, table[class*="el-table"] tbody tr, .el-table tbody tr', { timeout: 10000 }).catch(() => {});
                                
                                // 最後一次檢查行數
                                rowCountAfter = await page.evaluate(() => {
                                    // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                    let bodyTable = null;
                                    const elTableDiv = document.querySelector('div.el-table, .el-table');
                                    if (elTableDiv) {
                                        bodyTable = elTableDiv.querySelector('table.el-table__body');
                                    }
                                    if (!bodyTable) {
                                        bodyTable = document.querySelector('table.el-table__body');
                                    }
                                    const finalTable = bodyTable;
                                    if (finalTable) {
                                        const rows = finalTable.querySelectorAll('tbody tr, tr');
                                        return rows.length;
                                    }
                                    return 0;
                                });
                                
                                // 滾動到分頁組件位置，確保頁數和筆數可見
                                await page.evaluate(() => {
                                    const pagination = document.querySelector('.el-pagination');
                                    if (pagination) {
                                        pagination.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    }
                                });
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                // 點擊查詢按鈕後的畫面截圖（包含頁數和筆數）
                                await page.screenshot({ 
                                    path: 'screenshot_02_after_date_selection.png',
                                    fullPage: false
                                });
                                console.log('📸 Screenshot saved: screenshot_02_after_date_selection.png');
                            }
                        } catch (e) {
                            console.log('⚠️  Error clicking search button: ' + e.message);
                            console.error(e);
                        }
                    }

                    // 提取表格資料的函數（可重用）
                    // @param {Page} pageObject - Puppeteer 頁面對象（可以是 page 或 newPage）
                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            // Element UI 表格結構：
                            // 外層：div.el-table
                            // 內層：table.el-table__header (表頭) 和 table.el-table__body (表體)
                            
                            let headerTable = null;
                            let bodyTable = null;
                            let table = null;
                            
                            // 方式1：優先查找外層 div.el-table，然後在裡面找表格
                            const elTableDiv = document.querySelector('div.el-table, .el-table');
                            if (elTableDiv) {
                                // 在 div.el-table 內部查找表頭和表體
                                headerTable = elTableDiv.querySelector('table.el-table__header');
                                bodyTable = elTableDiv.querySelector('table.el-table__body');
                                
                                // 如果找不到，嘗試查找任何 table 元素
                                if (!headerTable && !bodyTable) {
                                    const tables = elTableDiv.querySelectorAll('table');
                                    for (let t of tables) {
                                        if (t.className && t.className.includes('el-table__header')) {
                                            headerTable = t;
                                        } else if (t.className && t.className.includes('el-table__body')) {
                                            bodyTable = t;
                                        }
                                    }
                                }
                            }
                            
                            // 方式2：如果方式1失敗，直接查找 table 元素
                            if (!headerTable && !bodyTable && !table) {
                                headerTable = document.querySelector('table.el-table__header');
                                bodyTable = document.querySelector('table.el-table__body');
                                table = document.querySelector('table.el-table');
                            }
                            
                            // 方式3：如果還是找不到，遍歷所有表格
                            if (!table && !headerTable && !bodyTable) {
                                const allTables = document.querySelectorAll('table');
                                for (let t of allTables) {
                                    if (t.className) {
                                        if (t.className.includes('el-table__header') && !headerTable) {
                                            headerTable = t;
                                        } else if (t.className.includes('el-table__body') && !bodyTable) {
                                            bodyTable = t;
                                        } else if (t.className.includes('el-table') && !table) {
                                            table = t;
                                        }
                                    }
                                }
                            }
                            
                            // 方式4：查找任何包含 el-table 的元素，然後向上或向下查找 table
                            if (!table && !headerTable && !bodyTable) {
                                const elTableElement = document.querySelector('[class*="el-table"]');
                                if (elTableElement) {
                                    // 如果是 table 元素
                                    if (elTableElement.tagName === 'TABLE') {
                                        if (elTableElement.className.includes('el-table__header')) {
                                            headerTable = elTableElement;
                                        } else if (elTableElement.className.includes('el-table__body')) {
                                            bodyTable = elTableElement;
                                        } else {
                                            table = elTableElement;
                                        }
                                    } else {
                                        // 如果不是 table，向下查找 table 元素
                                        const childTables = elTableElement.querySelectorAll('table');
                                        for (let t of childTables) {
                                            if (t.className && t.className.includes('el-table__header') && !headerTable) {
                                                headerTable = t;
                                            } else if (t.className && t.className.includes('el-table__body') && !bodyTable) {
                                                bodyTable = t;
                                            }
                                        }
                                        
                                        // 如果還是找不到，向上查找 table 元素
                                        if (!headerTable && !bodyTable) {
                                            let parent = elTableElement.parentElement;
                                            while (parent && parent.tagName !== 'TABLE' && parent !== document.body) {
                                                parent = parent.parentElement;
                                            }
                                            if (parent && parent.tagName === 'TABLE') {
                                                if (parent.className && parent.className.includes('el-table__header')) {
                                                    headerTable = parent;
                                                } else if (parent.className && parent.className.includes('el-table__body')) {
                                                    bodyTable = parent;
                                                } else {
                                                    table = parent;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // 如果完全找不到表格，返回錯誤
                            if (!table && !headerTable && !bodyTable) {
                                // 獲取調試信息
                                const debugInfo = {
                                    allTables: document.querySelectorAll('table').length,
                                    elTableElements: document.querySelectorAll('.el-table, [class*="el-table"]').length,
                                    tableClasses: Array.from(document.querySelectorAll('table')).map(t => t.className)
                                };
                                return {
                                    found: false,
                                    error: 'Table el-table not found',
                                    debug: debugInfo
                                };
                            }

                            // 提取表頭（優先從 headerTable，其次從 table）
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
                                        // Element UI 表格的表頭內容在 div.cell 中
                                        const cellDiv = cell.querySelector('div.cell');
                                        if (cellDiv) {
                                            return cellDiv.textContent.trim();
                                        }
                                        return cell.textContent.trim();
                                    });
                                }
                            } else {
                                // 如果沒有 thead，嘗試從第一個表格的第一行提取表頭
                                const sourceTable = headerTable || table;
                                if (sourceTable) {
                                    const firstRow = sourceTable.querySelector('tr');
                                    if (firstRow) {
                                        const headerCells = firstRow.querySelectorAll('th, td');
                                        headers = Array.from(headerCells).map(cell => {
                                            const cellDiv = cell.querySelector('div.cell');
                                            if (cellDiv) {
                                                return cellDiv.textContent.trim();
                                            }
                                            return cell.textContent.trim();
                                        });
                                    }
                                }
                            }

                            // 提取資料行（優先從 bodyTable，其次從 table）
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
                                    // 如果有表頭，跳過第一行
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
                                            // 清理字段名
                                            let cleanHeader = header
                                                .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                .replace(/^_+|_+$/g, '');
                                            
                                            if (!cleanHeader) {
                                                cleanHeader = 'column_' + colIndex;
                                            }
                                            
                                            // 確保字段名唯一
                                            let finalHeader = cleanHeader;
                                            let counter = 1;
                                            while (rowData.hasOwnProperty(finalHeader)) {
                                                finalHeader = cleanHeader + '_' + counter;
                                                counter++;
                                            }
                                            
                                            // Element UI 表格的數據內容也在 div.cell 中
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
                                        // 如果沒有表頭，使用索引作為 key
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
                                    
                                    // 添加原始行索引
                                    rowData._rowIndex = rowIndex;
                                    
                                    return rowData;
                                })
                                .filter(rowData => {
                                    // 過濾掉小計和總計行
                                    // 檢查第一個欄位（通常是日期欄位）是否包含"小計"或"總計"
                                    const firstValue = Object.values(rowData)[0];
                                    return firstValue !== '小計' && firstValue !== '總計';
                                });

                            // 確定使用的表格對象（用於返回信息）
                            const finalTable = bodyTable || table || headerTable;
                            
                            return {
                                found: true,
                                tableId: finalTable ? finalTable.id || null : null,
                                tableClass: finalTable ? finalTable.className || null : null,
                                hasHeaderTable: !!headerTable,
                                hasBodyTable: !!bodyTable,
                                headers: headers,
                                headerCount: headers.length,
                                rowCount: dataRows.length,
                                rawRows: rows.slice(dataStartIndex)
                                    .map(row => {
                                        return Array.from(row.querySelectorAll('td')).map(cell => {
                                            const cellDiv = cell.querySelector('div.cell');
                                            if (cellDiv) {
                                                return cellDiv.textContent.trim();
                                            }
                                            return cell.textContent.trim();
                                        });
                                    })
                                    .filter(rowArray => {
                                        // 過濾掉小計和總計行
                                        return rowArray.length > 0 && rowArray[0] !== '小計' && rowArray[0] !== '總計';
                                    }),
                                data: dataRows
                            };
                        });
                    };

                    // 檢查是否有下一頁的函數
                    const checkNextPage = async () => {
                        return await page.evaluate(() => {
                            // 查找包含 rel="next" 的分頁連結
                            const nextLink = document.querySelector('a[rel="next"]');
                            
                            if (nextLink && nextLink.href) {
                                // 檢查連結是否可見和可點擊
                                const style = window.getComputedStyle(nextLink);
                                const isVisible = style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
                                
                                return {
                                    hasNext: true,
                                    nextUrl: nextLink.href,
                                    pageNumber: nextLink.getAttribute('data-ci-pagination-page'),
                                    isVisible: isVisible,
                                    text: nextLink.textContent.trim()
                                };
                            }
                            
                            return {
                                hasNext: false,
                                nextUrl: null,
                                pageNumber: null
                            };
                        });
                    };

                    // ========== 步驟 1：爬取第一頁，獲取分頁資訊 ==========
                    console.log('📄 Step 1: Extracting first page and pagination info...');
                    
                    // 確保表格已載入（增加等待時間和重試機制）
                    // Element UI 表格 (el-table)
                    console.log('🔍 Step 1: Waiting for table to load...');
                    
                    // 額外等待並滾動頁面
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    await page.evaluate(() => {
                        window.scrollTo(0, document.body.scrollHeight);
                    });
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    await page.evaluate(() => {
                        window.scrollTo(0, 0);
                    });
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    tableFound = false;
                    for (let retry = 0; retry < 10; retry++) {
                        try {
                            // 檢查表格是否存在且有內容
                            const tableCheck = await page.evaluate(() => {
                                // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                let bodyTable = null;
                                let table = null;
                                
                                // 方式1：優先查找外層 div.el-table，然後在裡面找表格
                                const elTableDiv = document.querySelector('div.el-table, .el-table');
                                if (elTableDiv) {
                                    bodyTable = elTableDiv.querySelector('table.el-table__body');
                                    if (!bodyTable) {
                                        const tables = elTableDiv.querySelectorAll('table');
                                        for (let t of tables) {
                                            if (t.className && t.className.includes('el-table__body')) {
                                                bodyTable = t;
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // 方式2：如果方式1失敗，直接查找 table 元素
                                if (!bodyTable) {
                                    bodyTable = document.querySelector('table.el-table__body');
                                    table = document.querySelector('table.el-table');
                                }
                                
                                // 方式3：如果還是找不到，遍歷所有表格
                                if (!bodyTable && !table) {
                                    const allTables = document.querySelectorAll('table');
                                    for (let t of allTables) {
                                        if (t.className) {
                                            if (t.className.includes('el-table__body') && !bodyTable) {
                                                bodyTable = t;
                                            } else if (t.className.includes('el-table') && !table) {
                                                table = t;
                                            }
                                        }
                                    }
                                }
                                
                                const finalTable = bodyTable || table;
                                if (!finalTable) return { found: false, hasTable: false };
                                const rows = finalTable.querySelectorAll('tbody tr, tr');
                                return {
                                    found: rows.length > 0,
                                    hasTable: true,
                                    rowCount: rows.length
                                };
                            });
                            
                            if (tableCheck.found) {
                                console.log('✅ Table found with ' + tableCheck.rowCount + ' rows!');
                                tableFound = true;
                                break;
                            } else if (tableCheck.hasTable) {
                                console.log('⚠️  Table found but no data rows yet (rowCount: ' + tableCheck.rowCount + '), waiting...');
                            }
                            
                            if (retry < 9) {
                                console.log('⏳ Retry ' + (retry + 1) + '/10: Waiting for table...');
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            }
                        } catch (e) {
                            console.log('⚠️  Retry ' + (retry + 1) + '/10: Error checking table - ' + e.message);
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                    }
                    
                    // 如果還是找不到表格，獲取頁面信息以便調試
                    if (!tableFound) {
                        // 獲取頁面信息以便調試
                        const pageInfo = await page.evaluate(() => {
                            const allTables = Array.from(document.querySelectorAll('table'));
                            const elTableElements = Array.from(document.querySelectorAll('.el-table, [class*="el-table"]'));
                            return {
                                url: window.location.href,
                                title: document.title,
                                hasElTable: !!document.querySelector('table.el-table'),
                                hasElTableClass: elTableElements.length > 0,
                                tableCount: allTables.length,
                                tableClasses: allTables.map(t => t.className),
                                elTableClasses: elTableElements.map(el => el.className),
                                bodyText: document.body ? document.body.innerText.substring(0, 500) : 'No body'
                            };
                        });
                    }
                    
                    // 提取第一頁的表格資料
                    const firstPageData = await extractTableData(page);
                    
                    if (!firstPageData.found) {
                        const errorMsg = firstPageData.error || 'No table found on first page';
                        console.error('❌ ' + errorMsg);
                        if (firstPageData.debug) {
                            console.error('📋 Debug info:', JSON.stringify(firstPageData.debug, null, 2));
                        }
                        
                        // 再等待一下並重試一次（減少等待時間）
                        console.log('⏳ Waiting 2 more seconds and retrying...');
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        await page.evaluate(() => {
                            window.scrollTo(0, document.body.scrollHeight);
                        });
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        await page.evaluate(() => {
                            window.scrollTo(0, 0);
                        });
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        const retryData = await extractTableData(page);
                        if (!retryData.found) {
                            throw new Error(errorMsg + ' (retry also failed)');
                        } else {
                            console.log('✅ Table found on retry!');
                            // 使用重試成功的數據
                            Object.assign(firstPageData, retryData);
                        }
                    }
                    
                    // 滾動到分頁組件位置，確保頁數和筆數可見
                    await page.evaluate(() => {
                        const pagination = document.querySelector('.el-pagination');
                        if (pagination) {
                            pagination.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    });
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // 等待分頁組件載入（Element UI 的分頁組件）
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 滾動到頁面底部，確保分頁組件可見
                    await page.evaluate(() => {
                        window.scrollTo(0, document.body.scrollHeight);
                    });
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 獲取所有分頁連結（支持多種分頁組件）
                    const paginationInfo = await page.evaluate(() => {
                        const pageLinks = [];
                        let totalPages = 1;
                        
                        // 優先方式：查找總頁數顯示（最準確）
                        // Element UI 分頁組件通常會顯示 "1 / 2" 或 "共 2 頁" 等格式
                        const elPagination = document.querySelector('.el-pagination');
                        if (elPagination) {
                            const paginationText = elPagination.textContent || elPagination.innerText || '';
                            console.log('📄 Pagination text:', paginationText);
                            
                            // 方式1（最優先）：查找 input[type="number"] 的 max 屬性（最可靠）
                            // 例如：<input type="number" max="123"> 表示總頁數是 123
                            const pageInput = elPagination.querySelector('input[type="number"]');
                            if (pageInput && pageInput.hasAttribute('max')) {
                                const maxValue = parseInt(pageInput.getAttribute('max'));
                                if (!isNaN(maxValue) && maxValue > 0 && maxValue <= 10000) {
                                    totalPages = maxValue;
                                    console.log('✅ Found total pages from input max attribute:', totalPages);
                                }
                            }
                            
                            // 方式2：從 "Total 1227" 文本中提取總記錄數，然後計算總頁數
                            if (totalPages === 1) {
                                const totalMatch = paginationText.match(/total\s+(\d+)/i);
                                if (totalMatch && totalMatch[1]) {
                                    const totalRecords = parseInt(totalMatch[1]);
                                    // 查找每頁數量（從 select 或當前設置）
                                    let perPage = 10; // 默認值
                                    const perPageSelect = elPagination.querySelector('.el-select-dropdown__item.selected');
                                    if (perPageSelect) {
                                        const perPageText = perPageSelect.textContent || '';
                                        const perPageMatch = perPageText.match(/(\d+)\/page/i);
                                        if (perPageMatch && perPageMatch[1]) {
                                            perPage = parseInt(perPageMatch[1]);
                                        }
                                    }
                                    // 如果找不到，嘗試從 URL 參數中獲取
                                    if (perPage === 10) {
                                        const urlParams = new URLSearchParams(window.location.search);
                                        const limitParam = urlParams.get('limit');
                                        if (limitParam) {
                                            perPage = parseInt(limitParam);
                                        }
                                    }
                                    if (totalRecords > 0 && perPage > 0) {
                                        totalPages = Math.ceil(totalRecords / perPage);
                                        console.log('✅ Calculated total pages from Total records:', totalPages, '(records:', totalRecords, ', perPage:', perPage + ')');
                                    }
                                }
                            }
                            
                            // 方式3：查找 "1 / 1892" 或 "1/1892" 格式
                            if (totalPages === 1) {
                                const slashMatch = paginationText.match(/(\d+)\s*\/\s*(\d+)/);
                                if (slashMatch && slashMatch[2]) {
                                    totalPages = parseInt(slashMatch[2]);
                                    console.log('✅ Found total pages from slash format:', totalPages);
                                }
                            }
                            
                            // 方式4：查找 "共 1892 頁" 或 "total 1892 pages" 格式
                            if (totalPages === 1) {
                                const totalMatch = paginationText.match(/(?:共|總|total|of)\s*(\d+)\s*(?:頁|page|pages)/i);
                                if (totalMatch && totalMatch[1]) {
                                    totalPages = parseInt(totalMatch[1]);
                                    console.log('✅ Found total pages from text format:', totalPages);
                                }
                            }
                            
                            // 方式5：查找分頁按鈕中的最大數字（特別是最後一個按鈕，如 "123"）
                            if (totalPages === 1) {
                                const numberButtons = elPagination.querySelectorAll('.number, .el-pager li.number, button.number');
                                let maxPageNum = 1;
                                numberButtons.forEach(btn => {
                                    const text = btn.textContent.trim();
                                    const pageNum = parseInt(text);
                                    // 移除上限限制，允許更大的頁碼（1-10000）
                                    if (!isNaN(pageNum) && pageNum > 0 && pageNum <= 10000) {
                                        if (pageNum > maxPageNum) {
                                            maxPageNum = pageNum;
                                        }
                                    }
                                });
                                if (maxPageNum > 1) {
                                    totalPages = maxPageNum;
                                    console.log('✅ Found total pages from buttons:', totalPages);
                                }
                            }
                        }
                        
                        // 方式2：查找傳統分頁組件
                        if (totalPages === 1) {
                            const paginationContainer = document.querySelector('.pagination') || 
                                                       document.querySelector('ul.pagination');
                            
                            if (paginationContainer) {
                                // 優先查找總頁數顯示
                                const containerText = paginationContainer.textContent || paginationContainer.innerText || '';
                                const slashMatch = containerText.match(/(\d+)\s*\/\s*(\d+)/);
                                if (slashMatch && slashMatch[2]) {
                                    totalPages = parseInt(slashMatch[2]);
                                } else {
                                    // 查找所有分頁連結中的最大頁碼
                                    const links = paginationContainer.querySelectorAll('a[data-ci-pagination-page], a[data-page]');
                                    let maxPageNum = 1;
                                    links.forEach(link => {
                                        const pageNum = parseInt(link.getAttribute('data-ci-pagination-page') || link.getAttribute('data-page') || '0');
                                        if (!isNaN(pageNum) && pageNum > 0 && pageNum < 100) {
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
                        }
                        
                        // 方式3：檢查是否有「下一頁」按鈕（表示至少有2頁）
                        if (totalPages === 1) {
                            const nextLink = document.querySelector('a[rel="next"], .btn-next:not(.disabled), button.el-pagination__next:not(.disabled)');
                            if (nextLink && nextLink.offsetParent !== null) { // 檢查是否可見
                                totalPages = 2;
                            }
                        }
                        
                        // 確保至少有 1 頁
                        if (totalPages < 1) {
                            totalPages = 1;
                        }
                        
                        // 生成頁面連結（僅用於構建 URL，不影響總頁數判斷）
                        for (let i = 1; i <= totalPages; i++) {
                            pageLinks.push({
                                pageNumber: i,
                                url: window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'page=' + i
                            });
                        }
                        
                        return {
                            totalPages: totalPages,
                            pageLinks: pageLinks,
                            currentUrl: window.location.href,
                            paginationFound: totalPages > 1
                        };
                    });
                    
                    // 如果總頁數為1，但第一頁有數據，檢查是否有下一頁按鈕
                    if (paginationInfo.totalPages === 1 && firstPageData.rowCount > 0) {
                        const hasNextPage = await page.evaluate(() => {
                            const nextLink = document.querySelector('a[rel="next"], .btn-next:not(.disabled), button.el-pagination__next:not(.disabled)');
                            return nextLink && nextLink.offsetParent !== null;
                        });
                        if (hasNextPage) {
                            paginationInfo.totalPages = 2;
                        }
                    }
                    
                    // 獲取分頁組件的詳細信息以便調試
                    const paginationDebug = await page.evaluate(() => {
                        const elPagination = document.querySelector('.el-pagination');
                        const paginationContainer = document.querySelector('.pagination') || 
                                                   document.querySelector('ul.pagination') ||
                                                   document.querySelector('[class*="pag"]');
                        
                        return {
                            hasElPagination: !!elPagination,
                            hasPaginationContainer: !!paginationContainer,
                            elPaginationHTML: elPagination ? elPagination.innerHTML.substring(0, 500) : null,
                            paginationContainerHTML: paginationContainer ? paginationContainer.innerHTML.substring(0, 500) : null,
                            allPaginationElements: Array.from(document.querySelectorAll('[class*="pagination"], [class*="pager"], [class*="page"]')).map(el => ({
                                className: el.className,
                                text: el.textContent.trim().substring(0, 50)
                            }))
                        };
                    });
                    
                    // ========== 步驟 2：並行爬取所有頁面 ==========
                    console.log('🚀 Step 2: Starting concurrent scraping for all pages...');
                    
                    // 定義併發數量
                    const CONCURRENCY_LIMIT = $concurrency;
                    
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
                                
                                // 如果找不到，嘗試查找傳統分頁
                                const paginationContainer = document.querySelector('.pagination') || 
                                                           document.querySelector('ul.pagination');
                                if (paginationContainer) {
                                    const links = paginationContainer.querySelectorAll('a[data-ci-pagination-page], a[data-page]');
                                    for (let link of links) {
                                        const pageNum = parseInt(link.getAttribute('data-ci-pagination-page') || link.getAttribute('data-page') || '0');
                                        if (pageNum === targetPageNumber) {
                                            link.click();
                                            return true;
                                        }
                                    }
                                }
                                
                                return false;
                            }, pageInfo.pageNumber);

                            // 等待頁面切換和數據加載
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 等待表格更新
                            await page.waitForSelector('table.el-table, table.el-table__header, table.el-table__body, table[class*="el-table"], .el-table, .el-table__header, .el-table__body', { timeout: 15000 }).catch(() => { });
                            
                            // 等待數據完全加載（智能等待）
                            let rowCount = 0;
                            let waitAttempts = 0;
                            const maxWaitAttempts = 10;
                            
                            while (waitAttempts < maxWaitAttempts) {
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                rowCount = await page.evaluate(() => {
                                    // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                    let bodyTable = null;
                                    const elTableDiv = document.querySelector('div.el-table, .el-table');
                                    if (elTableDiv) {
                                        bodyTable = elTableDiv.querySelector('table.el-table__body');
                                    }
                                    if (!bodyTable) {
                                        bodyTable = document.querySelector('table.el-table__body');
                                    }
                                    const finalTable = bodyTable;
                                    if (finalTable) {
                                        const rows = finalTable.querySelectorAll('tbody tr, tr');
                                        return rows.length;
                                    }
                                    return 0;
                                });
                                
                                // 如果行數穩定（連續兩次檢查相同），認為數據已加載完成
                                if (waitAttempts > 0 && rowCount > 0) {
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    const rowCount2 = await page.evaluate(() => {
                                        // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                        let bodyTable = null;
                                        const elTableDiv = document.querySelector('div.el-table, .el-table');
                                        if (elTableDiv) {
                                            bodyTable = elTableDiv.querySelector('table.el-table__body');
                                        }
                                        if (!bodyTable) {
                                            bodyTable = document.querySelector('table.el-table__body');
                                        }
                                        const finalTable = bodyTable;
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
                            
                            // 再等待一下確保數據完全渲染
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
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
                    
                    // 顯示每頁的數據統計
                    allPagesData.forEach(pageData => {
                        const pageRowCount = pageData.tables && pageData.tables.length > 0 
                            ? pageData.tables.reduce((sum, table) => sum + (table.rowCount || 0), 0)
                            : 0;
                    });
                    
                    const totalRowsFromPages = allPagesData.reduce((sum, pageData) => {
                        if (pageData.tables && pageData.tables.length > 0) {
                            return sum + pageData.tables.reduce((s, table) => s + (table.rowCount || 0), 0);
                        }
                        return sum;
                    }, 0);
                    console.log('📊 Total rows from all pages: ' + totalRowsFromPages);
                    
                    console.log('✅ All pages scraped successfully!');

                    // 獲取當前頁面信息
                    const pageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });

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

                    // 構建結果數據結構
                    const domData = {
                        pageInfo: pageInfo,
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed
                        },
                        totalPages: allPagesData.length,
                        pages: allPagesData,
                        tables: allTables
                    };

                    // 計算總資料筆數
                    let totalRows = 0;
                    allTables.forEach(table => {
                        totalRows += table.rowCount || 0;
                    });

                    // 合併所有提取的資料
                    const result = {
                        timestamp: new Date().toISOString(),
                        url: '$url',
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed
                        },
                        domData: domData,
                        success: true
                    };

                    // 將結果保存為 JSON 文件
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');

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
                process.exit(0);
            }).catch((error) => {
                console.error('💥 DOM scraping failed:', error);
                process.exit(1);
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
        
        // 獲取查詢參數
        $queryParams = $result['queryParams'] ?? [];

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存合併後的表格資料到單一 JSON 文件（主要輸出文件）
        $allData = [];
        $totalRows = 0;
        $headers = [];
        
        // 優先從 pages 中提取數據（因為數據是按頁面組織的）
        if (!empty($domData['pages'])) {
            $this->info('📄 Extracting data from ' . count($domData['pages']) . ' pages...');
            foreach ($domData['pages'] as $page) {
                if (!empty($page['tables'])) {
                    foreach ($page['tables'] as $table) {
                        if (!empty($table['data'])) {
                            $pageRowCount = count($table['data']);
                            
                            // 將當前表格的所有數據添加到總數組中
                            $allData = array_merge($allData, $table['data']);
                            $totalRows += $pageRowCount;
                            
                            // 保存表頭（使用第一個表格的表頭）
                            if (empty($headers) && !empty($table['headers'])) {
                                $headers = $table['headers'];
                            }
                        }
                    }
                }
            }
        }
        
        $this->info('📊 Total rows extracted: ' . $totalRows);

        // 初始化合併後的檔案名稱
        $mergedFileName = null;
        
        // 如果有資料，保存合併後的資料
        if (!empty($allData)) {
            unset($row);
            
            // 創建合併後的數據結構
            $mergedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                    'queryParams' => $queryParams,
                    'totalPages' => $domData['totalPages'] ?? 1,
                    'totalRows' => $totalRows
                ],
                'headers' => $headers,
                'data' => $allData
            ];
            
            // 保存合併後的資料到單一 JSON 檔案
            $mergedFileName = "scraped_data/scraped_data_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $this->info("✅ Data saved successfully!");
        }

        if (!$mergedFileName) {
            $this->warn("⚠️ No data to save.");
        }

        // 將截圖從臨時目錄移動到永久儲存目錄
        $timestamp = date('Y-m-d_H-i-s');
        $screenshotFiles = [
            'screenshot_02_after_date_selection.png'
        ];
        
        foreach ($screenshotFiles as $screenshotFile) {
            $screenshotSrc = storage_path('app/temp/' . $screenshotFile);
            if (file_exists($screenshotSrc)) {
                $screenshotDst = storage_path("app/scraped_data/{$timestamp}_{$screenshotFile}");
                rename($screenshotSrc, $screenshotDst);
                $this->info("📸 Screenshot saved: {$screenshotDst}");
            }
        }

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}

