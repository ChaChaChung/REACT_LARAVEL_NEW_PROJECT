<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserSplusDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-splus-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {--concurrency=4} - 併發數量（可選，預設為 4）
     */
    protected $signature = 'agent:scrape-splus-dom-detail {url} {date_start?} {date_end?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from Splus DOM elements using browser automation with detailed information';

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

        // 檢查必要的環境變數
        if (empty(env('SPLUS_AGENT_DOMAIN'))) {
            $this->error('❌ SPLUS_AGENT_DOMAIN environment variable is not set');
            $this->line('Please set SPLUS_AGENT_DOMAIN in your .env file');
            return 1;
        }
        if (empty(env('SPLUS_AGENT_ACCOUNT'))) {
            $this->error('❌ SPLUS_AGENT_ACCOUNT environment variable is not set');
            $this->line('Please set SPLUS_AGENT_ACCOUNT in your .env file');
            return 1;
        }
        if (empty(env('SPLUS_AGENT_PASSWORD'))) {
            $this->error('❌ SPLUS_AGENT_PASSWORD environment variable is not set');
            $this->line('Please set SPLUS_AGENT_PASSWORD in your .env file');
            return 1;
        }

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

        // 獲取 Splus 登入流程程式碼片段（主頁面用）
        $loginCodeForPage = $this->generateSplusPuppeteerLoginCode('page');
        // 獲取認證 cookies 程式碼片段（併發頁面用，登入後可以重用 cookies）
        $cookiesCodeForNewPage = $this->generateSplusPuppeteerLoginCode('newPage');

        // 獲取登入域名（用於結果保存）
        $loginDomain = env('SPLUS_AGENT_DOMAIN', '');
        $loginDomainJs = json_encode($loginDomain);

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
                
                // 所有要處理的項目執行迴圈
                for (const [index, item] of items.entries()) {
                    // 為每個項目創建一個 Promise
                    // Promise.resolve().then() 確保函數是異步執行的
                    const promise = Promise.resolve().then(() => fn(item, index));
                    
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
                        // 只阻止圖片、字體、媒體檔案，保留 CSS 和 JS 以確保分頁功能正常
                        if (['image', 'font', 'media'].includes(resourceType)) {
                            req.abort();
                        } else {
                            req.continue();
                        }
                    });

                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            // console.log('❌ Browser console error:', msg.text());
                        }
                    });

                    // ========== 執行 Splus 登入流程 ==========
                    $loginCodeForPage
                    
                    // 登入後獲取當前頁面信息
                    const loginPageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });
                    
                    console.log('✅ Login process completed!');
                    console.log('📍 Current URL:', loginPageInfo.url);
                    console.log('📄 Page Title:', loginPageInfo.title);
                    
                    // 獲取登入後的 cookies
                    const loginCookies = await page.cookies();
                    console.log('🍪 Login cookies obtained:', loginCookies.length);

                    // 導航到目標 URL
                    console.log('🌐 Navigating to target URL:', '$url');
                    await page.goto('$url', {
                        waitUntil: 'domcontentloaded',
                        timeout: 30000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 獲取目標頁面信息
                    const targetPageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });
                    
                    console.log('✅ Navigated to target page!');
                    console.log('📍 Target URL:', targetPageInfo.url);
                    console.log('📄 Page Title:', targetPageInfo.title);
                    
                    // 截圖（目標頁面）
                    console.log('📸 Taking screenshot of target page...');
                    await page.screenshot({ 
                        path: 'target_page_screenshot.png',
                        fullPage: false
                    });
                    console.log('✅ Screenshot saved: target_page_screenshot.png');

                    // 構建登入結果
                    const loginResult = {
                        timestamp: new Date().toISOString(),
                        loginUrl: $loginDomainJs,
                        targetUrl: '$url',
                        success: true,
                        loginPageInfo: loginPageInfo,
                        targetPageInfo: targetPageInfo,
                        cookiesCount: loginCookies.length,
                        cookies: loginCookies
                    };

                    // 將登入結果保存為 JSON 文件
                    fs.writeFileSync('login_result.json', JSON.stringify(loginResult, null, 2));
                    console.log('💾 Login result saved to: login_result.json');
                    
                    // 登入完成，直接返回結果，不繼續爬取表格
                    return loginResult;

                    // 導航到目標頁面
                    // 使用 'domcontentloaded' 替代 'networkidle2' 加快載入速度
                    // timeout: 30000 設定 30 秒超時
                    await page.goto('$url', {
                        waitUntil: 'domcontentloaded',
                        timeout: 30000
                    });

                    // 等待表格元素出現，而不是固定等待時間
                    await page.waitForSelector('#simple-table', { timeout: 10000 }).catch(() => {
                        console.log('⚠️  Table not found, waiting 2 seconds...');
                    });
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

                    // 如果提供了 date_start 和 date_end，填入 input#find1 和 input#find2
                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') && (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            // 查找 input#find1 和 input#find2 欄位
                            await page.waitForSelector('#find1', { timeout: 10000 });
                            await page.waitForSelector('#find2', { timeout: 10000 });
                            
                            // 清空並填入日期到兩個欄位
                            await page.evaluate((dateStartValue, dateEndValue) => {
                                const input1 = document.querySelector('#find1');
                                const input2 = document.querySelector('#find2');
                                
                                if (input1) {
                                    input1.value = '';
                                    input1.value = dateStartValue;
                                    input1.dispatchEvent(new Event('input', { bubbles: true }));
                                    input1.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                                
                                if (input2) {
                                    input2.value = '';
                                    input2.value = dateEndValue;
                                    input2.dispatchEvent(new Event('input', { bubbles: true }));
                                    input2.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }, dateStartParsed, dateEndParsed);

                            // 減少等待時間
                            await new Promise(resolve => setTimeout(resolve, 500));

                            // 查找並點擊搜尋按鈕
                            const searchButton = await page.evaluate(() => {
                                // 優先查找包含 "搜尋" 文本的按鈕
                                const allButtons = Array.from(document.querySelectorAll('button'));
                                let searchBtn = allButtons.find(btn => {
                                    const text = btn.textContent.trim();
                                    return text === '搜尋';
                                });

                                // 判斷 searchBtn 是否存在
                                if (searchBtn) {
                                    const uniqueId = 'search-btn-' + Date.now();
                                    searchBtn.setAttribute('data-puppeteer-id', uniqueId);
                                    return {
                                        found: true,
                                        selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                        text: searchBtn.textContent.trim()
                                    };
                                }
                                
                                return { found: false };
                            });

                            // 判斷是否有找到 searchButton
                            if (searchButton.found) {
                                await page.click(searchButton.selector, { timeout: 5000 });
                                
                                // 等待表格更新（使用更短的等待時間）
                                await page.waitForSelector('#simple-table', { timeout: 8000 }).catch(() => {});
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            }
                        } catch (e) {
                            console.log('⚠️  Error filling date: ' + e.message);
                        }
                    }

                    // 提取表格資料的函數（可重用）
                    // @param {Page} pageObject - Puppeteer 頁面對象（可以是 page 或 newPage）
                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            // 查找 id="simple-table" 的表格
                            const table = document.querySelector('#simple-table');
                            
                            if (!table) {
                                return {
                                    found: false,
                                    error: 'Table #simple-table not found'
                                };
                            }

                            // 提取表頭
                            let headers = [];
                            const thead = table.querySelector('thead');
                            if (thead) {
                                const headerRows = Array.from(thead.querySelectorAll('tr'));
                                if (headerRows.length > 0) {
                                    const headerCells = headerRows[0].querySelectorAll('th, td');
                                    headers = Array.from(headerCells).map(cell => cell.textContent.trim());
                                }
                            } else {
                                // 如果沒有 thead，嘗試從第一行提取表頭
                                const firstRow = table.querySelector('tr');
                                if (firstRow) {
                                    const headerCells = firstRow.querySelectorAll('th, td');
                                    headers = Array.from(headerCells).map(cell => cell.textContent.trim());
                                }
                            }

                            // 提取資料行
                            const tbody = table.querySelector('tbody');
                            let rows = [];
                            let dataStartIndex = 0;

                            if (tbody) {
                                rows = Array.from(tbody.querySelectorAll('tr'));
                            } else {
                                // 如果沒有 tbody，從表格直接獲取所有行
                                rows = Array.from(table.querySelectorAll('tr'));
                                // 如果有表頭，跳過第一行
                                if (thead || (rows.length > 0 && rows[0].querySelectorAll('th').length > 0)) {
                                    dataStartIndex = 1;
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
                                })
                                .filter(rowData => {
                                    // 過濾掉小計和總計行
                                    // 檢查第一個欄位（通常是日期欄位）是否包含"小計"或"總計"
                                    const firstValue = Object.values(rowData)[0];
                                    return firstValue !== '小計' && firstValue !== '總計';
                                });

                            return {
                                found: true,
                                tableId: table.id || null,
                                tableClass: table.className || null,
                                headers: headers,
                                headerCount: headers.length,
                                rowCount: dataRows.length,
                                rawRows: rows.slice(dataStartIndex)
                                    .map(row => Array.from(row.querySelectorAll('td')).map(cell => cell.textContent.trim()))
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
                    
                    // 確保表格已載入
                    await page.waitForSelector('#simple-table tbody tr', { timeout: 5000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // 提取第一頁的表格資料
                    const firstPageData = await extractTableData(page);
                    
                    if (!firstPageData.found) {
                        throw new Error('No table found on first page');
                    }
                    
                    // 獲取所有分頁連結
                    const paginationInfo = await page.evaluate(() => {
                        const pageLinks = [];
                        
                        // 查找分頁區域（通常在 .pagination 或 ul.pagination 中）
                        const paginationContainer = document.querySelector('.pagination') || 
                                                   document.querySelector('ul.pagination') ||
                                                   document.querySelector('[class*="pag"]');
                        
                        if (paginationContainer) {
                            // 獲取所有分頁連結
                            const links = paginationContainer.querySelectorAll('a[data-ci-pagination-page]');
                            links.forEach(link => {
                                const pageNum = parseInt(link.getAttribute('data-ci-pagination-page'));
                                if (!isNaN(pageNum) && link.href) {
                                    pageLinks.push({
                                        pageNumber: pageNum,
                                        url: link.href
                                    });
                                }
                            });
                        }
                        
                        // 如果沒有找到分頁連結，嘗試查找「下一頁」連結來推測總頁數
                        if (pageLinks.length === 0) {
                            const nextLink = document.querySelector('a[rel="next"]');
                            if (nextLink) {
                                // 至少有 2 頁
                                pageLinks.push({ pageNumber: 1, url: window.location.href });
                                pageLinks.push({ pageNumber: 2, url: nextLink.href });
                            } else {
                                // 只有 1 頁
                                pageLinks.push({ pageNumber: 1, url: window.location.href });
                            }
                        }
                        
                        return {
                            totalPages: pageLinks.length > 0 ? Math.max(...pageLinks.map(p => p.pageNumber)) : 1,
                            pageLinks: pageLinks,
                            currentUrl: window.location.href
                        };
                    });
                    
                    // ========== 步驟 2：並行爬取所有頁面 ==========
                    console.log('🚀 Step 2: Starting concurrent scraping for all pages...');
                    
                    // 定義併發數量
                    const CONCURRENCY_LIMIT = $concurrency;
                    
                    // 準備要爬取的頁面列表（從第 2 頁開始，因為第 1 頁已經爬了）
                    const pagesToScrape = [];
                    for (let i = 2; i <= paginationInfo.totalPages; i++) {
                        // 嘗試從 pageLinks 中找到對應的 URL
                        const pageLink = paginationInfo.pageLinks.find(p => p.pageNumber === i);
                        const pageUrl = pageLink ? pageLink.url : paginationInfo.currentUrl + (paginationInfo.currentUrl.includes('?') ? '&' : '?') + 'page=' + i;
                        pagesToScrape.push({ pageNumber: i, url: pageUrl });
                    }
                    
                    // 並行爬取函數
                    const scrapePage = async (pageInfo, index) => {
                        const newPage = await browser.newPage();
                        
                        try {
                            // 設定視窗大小
                            await newPage.setViewport({ width: 1920, height: 1080 });
                            
                            // 設定 User Agent
                            await newPage.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
                            
                            // 設定資源攔截
                            await newPage.setRequestInterception(true);
                            newPage.on('request', (req) => {
                                const resourceType = req.resourceType();
                                if (['image', 'font', 'media'].includes(resourceType)) {
                                    req.abort();
                                } else {
                                    req.continue();
                                }
                            });
                            
                            // 從主頁面複製登入後的 cookies 到新頁面
                            if (loginCookies && loginCookies.length > 0) {
                                await newPage.setCookie(...loginCookies);
                            }
                            
                            // 導航到頁面
                            await newPage.goto(pageInfo.url, {
                                waitUntil: 'domcontentloaded',
                                timeout: 30000
                            });
                            
                            // 等待表格載入
                            await newPage.waitForSelector('#simple-table tbody tr', { timeout: 8000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 500));
                            
                            // 提取表格資料
                            const tableData = await extractTableData(newPage);
                            
                            return {
                                pageNumber: pageInfo.pageNumber,
                                tables: [tableData]
                            };
                            
                        } catch (error) {
                            console.error('❌ [Page ' + pageInfo.pageNumber + '] Error: ' + error.message);
                            return {
                                pageNumber: pageInfo.pageNumber,
                                tables: [],
                                error: error.message
                            };
                        } finally {
                            await newPage.close();
                        }
                    };
                    
                    // 使用併發控制並行爬取所有頁面
                    const otherPagesData = await promiseAllWithLimit(
                        pagesToScrape,
                        CONCURRENCY_LIMIT,
                        scrapePage
                    );
                    
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
                    allPagesData.forEach(pageData => {
                        if (pageData.tables && pageData.tables.length > 0) {
                            allTables.push(...pageData.tables);
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

                    // 截圖（用於調試和驗證）- 只截取可見區域，不截全頁（大幅提升速度）
                    await page.screenshot({ 
                        path: 'scraped_page_screenshot.png',
                        fullPage: false  // 改為 false，只截可見區域，速度更快
                    });

                    console.log('📸 Screenshot saved: scraped_page_screenshot.png');

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
                    console.log('📊 DOM elements extracted:');

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

        // 讀取腳本生成的結果文件（優先讀取登入結果，如果沒有則讀取爬取結果）
        $loginResultFile = $workingDir . '/login_result.json';
        $scrapedResultFile = $workingDir . '/scraped_result.json';

        if (file_exists($loginResultFile)) {
            // 讀取並解析登入結果 JSON 文件
            $content = file_get_contents($loginResultFile);
            return json_decode($content, true);
        } elseif (file_exists($scrapedResultFile)) {
            // 讀取並解析爬取結果 JSON 文件
            $content = file_get_contents($scrapedResultFile);
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

        // 檢查是否為登入結果（只有登入，沒有爬取表格）
        if (isset($result['loginUrl']) && !isset($result['domData'])) {
            $this->info('✅ Login process completed successfully!');
            $this->info('📍 Login URL: ' . ($result['loginUrl'] ?? 'N/A'));
            if (isset($result['loginPageInfo'])) {
                $this->info('📄 Login Page: ' . ($result['loginPageInfo']['url'] ?? 'N/A'));
            }
            if (isset($result['targetPageInfo'])) {
                $this->info('🌐 Target URL: ' . ($result['targetUrl'] ?? 'N/A'));
                $this->info('📄 Target Page: ' . ($result['targetPageInfo']['url'] ?? 'N/A'));
                $this->info('📄 Target Page Title: ' . ($result['targetPageInfo']['title'] ?? 'N/A'));
            }
            $this->info('🍪 Cookies obtained: ' . ($result['cookiesCount'] ?? 0));
            
            // 處理所有截圖
            $timestamp = date('Y-m-d_H-i-s');
            $screenshots = [
                'login_form_screenshot.png',
                'login_after_screenshot.png',
                'login_error_screenshot.png',
                'target_page_screenshot.png'
            ];
            
            foreach ($screenshots as $screenshot) {
                $screenshotSrc = storage_path('app/temp/' . $screenshot);
                if (file_exists($screenshotSrc)) {
                    $screenshotDst = storage_path("app/scraped_data/{$screenshot}_{$timestamp}.png");
                    rename($screenshotSrc, $screenshotDst);
                    $this->info("📸 Screenshot saved: {$screenshotDst}");
                }
            }
            
            $this->info('End of command at: ' . date('Y-m-d H:i:s'));
            $this->info("✅ Login and navigation process completed!");
            return;
        }

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

        // 初始化合併後的檔案名稱
        $mergedFileName = null;
        
        // 如果有資料，保存合併後的資料
        if (!empty($allData)) {
            // 清理"代理"欄位：移除"公司主站代理線"字樣
            foreach ($allData as &$row) {
                if (isset($row['代理'])) {
                    // 移除"公司主站代理線"，只保留前面的部分
                    $row['代理'] = str_replace('公司主站代理線', '', $row['代理']);
                    // 去除多餘的空白
                    $row['代理'] = trim($row['代理']);
                }
            }
            unset($row); // 解除引用
            
            // 按照平台分類數資料
            $platformData = [];
            // 平台欄位名稱
            $platformField = '平台';
            
            // 所有資料執行迴圈
            foreach ($allData as $row) {
                // 取出平台名稱
                $platform = $row[$platformField] ?? 'Unknown';
                
                // 如果平台資料不存在，創建新的平台資料
                if (!isset($platformData[$platform])) {
                    // 創建新的平台資料
                    $platformData[$platform] = [
                        'rowCount' => 0,
                        'data' => []
                    ];
                }
                
                // 將資料加入平台資料
                $platformData[$platform]['data'][] = $row;
                // 增加平台資料的行數
                $platformData[$platform]['rowCount']++;
            }
            
            // 按照平台名稱排序
            ksort($platformData);
            
            // 創建合併後的數據結構（按平台分類）
            $mergedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                    'queryParams' => $queryParams,
                    'totalPages' => $domData['totalPages'] ?? 1,
                    'totalRows' => $totalRows,
                    'platformCount' => count($platformData),
                    'platforms' => array_keys($platformData)
                ],
                'headers' => $headers,
                'headerCount' => count($headers),
                'rowCount' => $totalRows,
                'platforms' => $platformData
            ];
            
            // 保存合併後的資料到單一 JSON 檔案
            $mergedFileName = "scraped_data/scraped_data_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // 為每個平台單獨保存檔案
            foreach ($platformData as $platform => $data) {
                // 取出平台名稱
                $safePlatformName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '_', $platform);
                
                // 創建平台專屬的資料結構
                $platformFileData = [
                    'metadata' => [
                        'timestamp' => $timestamp,
                        'platform' => $platform,
                        'url' => $result['url'] ?? '',
                        'queryParams' => $queryParams,
                        'totalPages' => $domData['totalPages'] ?? 1,
                        'totalRows' => $data['rowCount']
                    ],
                    'headers' => $headers,
                    'headerCount' => count($headers),
                    'rowCount' => $data['rowCount'],
                    'data' => $data['data']
                ];
                
                // 保存平台專屬檔案
                $platformFileName = "scraped_data/platform_{$safePlatformName}_{$timestamp}.json";
                Storage::put($platformFileName, json_encode($platformFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            
            $this->info("✅ All platform-specific files saved!");
        }

        if (!$mergedFileName) {
            $this->warn("⚠️ No data to save.");
        }

        // 將截圖從臨時目錄移動到永久儲存目錄
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/dom_screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }
        
        // 將登入表單截圖從臨時目錄移動到永久儲存目錄
        $loginScreenshotSrc = storage_path('app/temp/login_form_screenshot.png');
        $loginScreenshotDst = storage_path("app/scraped_data/login_form_screenshot_{$timestamp}.png");
        
        if (file_exists($loginScreenshotSrc)) {
            rename($loginScreenshotSrc, $loginScreenshotDst);
            $this->info("📸 Login form screenshot saved to: {$loginScreenshotDst}");
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}

