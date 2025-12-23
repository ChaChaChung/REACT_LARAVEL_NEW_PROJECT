<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowser168DOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-168-dom-detail {url} {date_start?} {date_end?} {--concurrency=4}
     * {url} - 要爬取的目標網址（必需參數）
     * {account_number?} - 要點擊的帳號號碼（可選參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {--concurrency=4} - 併發數量（可選，預設為 4）
     */
    protected $signature = 'agent:scrape-168-dom-detail {url} {account_number?} {date_start?} {date_end?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from 168 DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $account_number = $this->argument('account_number');
        $date_start = $this->argument('date_start');
        $date_end = $this->argument('date_end');
        $concurrency = $this->option('concurrency');

        $this->info('=== Browser DOM Scraper (Concurrent) ===');
        $this->info("Target URL: {$url}");
        $this->info("Account Number: {$account_number}");
        $this->info("Date Start: {$date_start}");
        $this->info("Date End: {$date_end}");
        $this->info("Concurrency: {$concurrency}");

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $account_number, $date_start, $date_end, $concurrency);

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
     * @param string|null $account_number 要點擊的帳號號碼（可選）
     * @param string|null $date_start 要選擇的開始日期（可選）
     * @param string|null $date_end 要選擇的結束日期（可選）
     * @param int $concurrency 併發數量
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $account_number = null, $date_start = null, $date_end = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段（只需要一個頁面）
        $cookiesCodeForPage = $this->generate168PuppeteerCookiesCode('page');

        // 將 account_number 轉換為 JavaScript 可用的格式
        $accountNumberJs = $account_number ? json_encode($account_number) : 'null';

        // 將 date 轉換為 JavaScript 可用的格式
        $dateStartJs = $date_start ? json_encode(date('Y-m-d', strtotime($date_start))) : 'null';
        $dateEndJs = $date_end ? json_encode(date('Y-m-d', strtotime($date_end))) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容
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
                    // 創建新的瀏覽器頁面（使用 let 因為可能會切換到新視窗）
                    let page = await browser.newPage();

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
                    // timeout: 30000 設定 30 秒超時
                    await page.goto('$url', {
                        waitUntil: 'domcontentloaded',
                        timeout: 30000
                    });

                    // 等待頁面加載完成
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 解析帳號號碼
                    let accountNumberParsed = $accountNumberJs;
                    
                    // 如果提供了帳號號碼參數，填入 account_number 欄位
                    if (accountNumberParsed && accountNumberParsed !== null) {
                        await page.evaluate((accountNumber) => {
                            // 填入帳號號碼
                            if (accountNumber) {
                                const accountNumberInput = document.querySelector('input[name="username"]');
                                if (accountNumberInput) {
                                    accountNumberInput.value = accountNumber;
                                    accountNumberInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    accountNumberInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                        }, accountNumberParsed);
                        
                        // 等待一下讓表單處理完成
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }

                    // 解析日期參數（PHP 的 json_encode 已經處理好了，直接使用）
                    let dateStartParsed = $dateStartJs;
                    let dateEndParsed = $dateEndJs;
                    
                    // 如果提供了日期參數，填入 starttime 和 endtime 欄位
                    if ((dateStartParsed && dateStartParsed !== null) || (dateEndParsed && dateEndParsed !== null)) {
                        await page.evaluate((startDate, endDate) => {
                            // 填入開始日期
                            if (startDate) {
                                const startInput = document.querySelector('input[name="starttime"]');
                                if (startInput) {
                                    startInput.value = startDate;
                                    startInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    startInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                            
                            // 填入結束日期
                            if (endDate) {
                                const endInput = document.querySelector('input[name="endtime"]');
                                if (endInput) {
                                    endInput.value = endDate;
                                    endInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    endInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }
                        }, dateStartParsed, dateEndParsed);
                        
                        // 等待一下讓表單處理完成
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }
                    
                    // 提取「開始查詢」按鈕觸發的 URL 並直接導航（不打開新窗口）
                    const reportUrl = await page.evaluate(() => {
                        // 查找「開始查詢」按鈕
                        const buttons = Array.from(document.querySelectorAll('input[type="button"], button'));
                        const startButton = buttons.find(btn => 
                            btn.value === '開始查詢' || btn.textContent.trim() === '開始查詢'
                        );
                        
                        if (startButton && startButton.onclick) {
                            // Hook window.open 來捕獲 URL（不管是直接調用還是通過函數調用）
                            let capturedUrl = null;
                            const originalOpen = window.open;
                            
                            window.open = function(url) {
                                capturedUrl = url;
                                return null;  // 返回 null 避免真的打開新窗口
                            };
                            
                            try {
                                // 執行 onclick（會觸發 window.open，但被我們 hook 了）
                                startButton.onclick.call(startButton);
                            } catch (e) {
                                console.log('Error calling onclick:', e);
                            }
                            
                            // 恢復原始的 window.open
                            window.open = originalOpen;
                            
                            return capturedUrl;
                        }
                        return null;
                    });
                    
                    if (reportUrl) {
                        // 將相對路徑轉換為絕對路徑
                        const absoluteUrl = reportUrl.startsWith('http') ? reportUrl : new URL(reportUrl, page.url()).href;
                        console.log('📍 Navigating to report page:', absoluteUrl);
                        // 直接導航到報表頁面（不打開新窗口）
                        await page.goto(absoluteUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    }
                    
                    // 等待表格元素出現
                    await page.waitForSelector('.report_list, table.num_table, table.table-bordered.bg-white', { timeout: 15000 }).catch(() => {
                        console.log('⚠️  Table not found after waiting...');
                    });
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 查找並點擊表格中的第一個代理商鏈接
                    const agentLinkClicked = await page.evaluate(() => {
                        // 查找表格中帶有 upField=up6_id 的鏈接（代理商詳細頁面鏈接）
                        const table = document.querySelector('table.table-bordered');
                        if (table) {
                            const links = table.querySelectorAll('a[href*="upField=up6_id"]');
                            if (links.length > 0) {
                                const firstLink = links[0];
                                firstLink.click();
                                return {
                                    clicked: true,
                                    agentName: firstLink.textContent.trim(),
                                    url: firstLink.href
                                };
                            }
                        }
                        return { clicked: false };
                    });
                    
                    if (agentLinkClicked.clicked) {
                        // 等待頁面導航到詳細頁面
                        await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    }
                    
                    // 無論是否點擊了代理商鏈接，都嘗試在當前頁面查找並點擊會員鏈接（會打開新視窗）
                    {
                        // 如果指定了 accountNumber，就點擊該會員；否則點擊第一個會員
                        const targetAccount = accountNumberParsed;
                        
                        // 提取會員鏈接的 URL 並直接導航（不打開新窗口）
                        const memberLinkInfo = await page.evaluate((targetAccountName) => {
                            // 查找表格中的會員鏈接
                            const table = document.querySelector('table.table-bordered');
                            if (table) {
                                const rows = table.querySelectorAll('tbody tr');
                                if (rows.length > 0) {
                                    // 跳過標題行，找到目標會員
                                    for (let i = 0; i < rows.length; i++) {
                                        const row = rows[i];
                                        
                                        // 找第一個 td（會員名稱列）
                                        const firstCell = row.querySelector('td');
                                        if (firstCell) {
                                            // 找第一個 a 標籤（會員名稱鏈接）
                                            const link = firstCell.querySelector('a');
                                            if (link) {
                                                const memberName = link.textContent.trim();
                                                
                                                // 排除非會員行
                                                if (memberName && memberName !== '無搜尋資料' && memberName !== '小計' && memberName !== '總計') {
                                                    // 如果指定了目標帳號，檢查是否匹配
                                                    if (targetAccountName) {
                                                        // 檢查會員名稱是否包含目標帳號
                                                        if (memberName.includes(targetAccountName)) {
                                                            // 提取 onclick 中的 URL
                                                            const onclick = link.getAttribute('onclick');
                                                            let url = null;
                                                            if (onclick) {
                                                                const match = onclick.match(/window\\.open\\s*\\(\\s*['"]([^'"]+)['"]/);
                                                                if (match) {
                                                                    url = match[1];
                                                                }
                                                            }
                                                            return {
                                                                found: true,
                                                                memberName: memberName,
                                                                url: url
                                                            };
                                                        }
                                                    } else {
                                                        // 如果沒有指定目標帳號，使用第一個會員
                                                        const onclick = link.getAttribute('onclick');
                                                        let url = null;
                                                        if (onclick) {
                                                            const match = onclick.match(/window\\.open\\s*\\(\\s*['"]([^'"]+)['"]/);
                                                            if (match) {
                                                                url = match[1];
                                                            }
                                                        }
                                                        return {
                                                            found: true,
                                                            memberName: memberName,
                                                            url: url
                                                        };
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            return { found: false };
                        }, targetAccount);
                        
                        if (memberLinkInfo.found && memberLinkInfo.url) {
                            // 將相對路徑轉換為絕對路徑
                            const absoluteMemberUrl = memberLinkInfo.url.startsWith('http') ? memberLinkInfo.url : new URL(memberLinkInfo.url, page.url()).href;
                            console.log('📍 Navigating to member detail page:', memberLinkInfo.memberName);
                            // 直接導航到會員詳細頁面（不打開新窗口）
                            await page.goto(absoluteMemberUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        } else {
                            console.log('⚠️  No member link found, will scrape current page');
                        }
                    }

                    // 提取表格資料的函數（可重用）- 提取所有表格
                    // @param {Page} pageObject - Puppeteer 頁面對象（可以是 page 或 newPage）
                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            // 查找所有表格（不限制 class，只要是 table 標籤）
                            const tables = document.querySelectorAll('table');
                            
                            if (tables.length === 0) {
                                return {
                                    found: false,
                                    error: 'No tables found'
                                };
                            }
                            
                            // 提取所有表格的數據
                            const allTablesData = [];
                            
                            tables.forEach((table, tableIndex) => {
                            
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
                                            
                                            // 特殊處理：如果是「注單內容」欄位，提取藍色字體（投注內容）
                                            if (finalHeader === '注單內容' && cells[colIndex]) {
                                                const blueText = cells[colIndex].querySelector('.text_color_blue');
                                                if (blueText) {
                                                    rowData['投注內容'] = blueText.textContent.trim();
                                                }
                                            }
                                        });
                                    } else {
                                        // 如果沒有表頭，使用索引作為 key
                                        cells.forEach((cell, colIndex) => {
                                            rowData['column_' + colIndex] = cell ? cell.textContent.trim() : null;
                                        });
                                    }
                                    
                                    // 解析特定欄位
                                    // 1. 注單編號欄位：拆分成「注單編號」、「投注方式」、「IP」
                                    if (rowData['注單編號']) {
                                        const lines = rowData['注單編號'].split('\\n').map(line => line.trim()).filter(line => line);
                                        if (lines.length >= 1) {
                                            rowData['注單編號'] = lines[0];  // 第一行：BK28770853
                                        }
                                        if (lines.length >= 2) {
                                            // 第二行：[手機投注] -> 移除方括號
                                            rowData['投注方式'] = lines[1].replace(/[\\[\\]]/g, '');
                                        }
                                        if (lines.length >= 3) {
                                            rowData['IP'] = lines[2];  // 第三行：101.8.77.10 (TW)
                                        }
                                    }
                                    
                                    // 2. 球類/玩法欄位：拆分成「球類」、「玩法」、「歸帳日」
                                    if (rowData['球類_玩法']) {
                                        const lines = rowData['球類_玩法'].split('\\n').map(line => line.trim()).filter(line => line);
                                        if (lines.length >= 1) {
                                            rowData['球類'] = lines[0];  // 第一行：美籃 A盤
                                        }
                                        if (lines.length >= 2) {
                                            rowData['玩法'] = lines[1];  // 第二行：全場讓分
                                        }
                                        if (lines.length >= 3) {
                                            // 第三行：[歸帳日:25-12-04] -> 提取日期
                                            const match = lines[2].match(/歸帳日[：:]\\s*(\\S+)/);
                                            if (match) {
                                                rowData['歸帳日'] = match[1].replace(/[\\[\\]]/g, '');
                                            }
                                        }
                                        // 刪除原始欄位
                                        delete rowData['球類_玩法'];
                                    }
                                    
                                    // 3. 注單內容欄位：解析比賽詳情
                                    if (rowData['注單內容']) {
                                        const content = rowData['注單內容'];
                                        
                                        // 檢查是哪種格式
                                        // 格式1: [109] 底特律活塞 4.5 密爾瓦基公鹿 [主] [113] - 客隊在前
                                        // 格式2: [112] 奧蘭多魔術 [主] 8 聖安東尼奧馬刺 [114] - 主隊在前
                                        
                                        // 先嘗試格式1（客隊在前）
                                        const format1Match = content.match(/\\[(\\d+)\\]\\s*([^\\n]+?)\\s+([\\d.-]+)\\s+([\\u4e00-\\u9fa5]+)\\s*\\[主\\]\\s*\\[(\\d+)\\]/);
                                        if (format1Match) {
                                            rowData['客隊分數'] = format1Match[1];
                                            rowData['客隊'] = format1Match[2].trim();
                                            rowData['盤口'] = format1Match[3].trim();
                                            rowData['主隊'] = format1Match[4].trim();
                                            rowData['主隊分數'] = format1Match[5];
                                        } else {
                                            // 嘗試格式2（主隊在前）
                                            const format2Match = content.match(/\\[(\\d+)\\]\\s*([^\\n]+?)\\s*\\[主\\]\\s*([\\d.-]+)\\s+([\\u4e00-\\u9fa5]+)\\s*\\[(\\d+)\\]/);
                                            if (format2Match) {
                                                rowData['主隊分數'] = format2Match[1];
                                                rowData['主隊'] = format2Match[2].trim();
                                                rowData['盤口'] = format2Match[3].trim();
                                                rowData['客隊'] = format2Match[4].trim();
                                                rowData['客隊分數'] = format2Match[5];
                                            }
                                        }
                                        
                                        // 提取比賽時間和聯賽：[25-12-04 09:00] [美國NBA]
                                        const gameInfoMatch = content.match(/\\[([\\d-]+\\s[\\d:]+)\\]\\s*\\[([^\\]]+)\\]/);
                                        if (gameInfoMatch) {
                                            rowData['比賽時間'] = gameInfoMatch[1];
                                            rowData['聯賽'] = gameInfoMatch[2];
                                        }
                                        
                                        // 提取賠率：@ 0.970
                                        const oddsMatch = content.match(/@\\s*([\\d.]+)/);
                                        if (oddsMatch) {
                                            rowData['賠率'] = oddsMatch[1];
                                        }
                                        
                                        // 提取結算時間：[結算:25-12-04 11:39:13]
                                        const settlementMatch = content.match(/結算[：:]\\s*([\\d-]+\\s[\\d:]+)/);
                                        if (settlementMatch) {
                                            rowData['結算時間'] = settlementMatch[1];
                                        }
                                        
                                        // 移除原始的注單內容欄位（已經提取了所有需要的資訊）
                                        delete rowData['注單內容'];
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

                                allTablesData.push({
                                    tableIndex: tableIndex,
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
                                });
                            });
                            
                            // 返回所有表格的數據
                            return {
                                found: true,
                                tableCount: allTablesData.length,
                                tables: allTablesData,
                                // 保持向後兼容，返回第一個表格的數據作為主數據
                                ...allTablesData[0]
                            };
                        });
                    };

                    // ========== 提取表格資料 ==========
                    console.log('📄 Extracting table data...');
                    
                    // 確保表格已載入
                    await page.waitForSelector('.report_list tbody tr, table.num_table tbody tr, table.table-bordered tbody tr', { timeout: 5000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // Debug: Check what elements exist on the page
                    const pageDebugInfo = await page.evaluate(() => {
                        const tables = document.querySelectorAll('table');
                        const tableInfo = Array.from(tables).map(table => ({
                            id: table.id || 'no-id',
                            className: table.className || 'no-class',
                            rowCount: table.querySelectorAll('tr').length
                        }));
                        
                        return {
                            title: document.title,
                            url: window.location.href,
                            tableCount: tables.length,
                            tables: tableInfo,
                            hasReportListTable: !!document.querySelector('.report_list')
                        };
                    });
                    
                    // 提取表格資料
                    const tableData = await extractTableData(page);
                    
                    if (!tableData.found) {
                        // Take a screenshot for debugging
                        await page.screenshot({ 
                            path: 'debug_screenshot.png',
                            fullPage: true
                        });
                        console.log('📸 Debug screenshot saved: debug_screenshot.png');
                        
                        // Save page HTML for debugging
                        const html = await page.content();
                        fs.writeFileSync('debug_page.html', html);
                        
                        throw new Error('No table found on page');
                    }
                    
                    console.log('✅ Table data extracted successfully!');

                    // 獲取當前頁面信息
                    const pageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });

                    // 構建結果數據結構（單頁）
                    const domData = {
                        pageInfo: pageInfo,
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed
                        },
                        totalPages: 1,
                        pages: [
                            {
                                pageNumber: 1,
                                tables: [tableData]
                            }
                        ],
                        tables: [tableData]
                    };

                    // 計算總資料筆數
                    const totalRows = tableData.rowCount || 0;

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

        // 保存所有表格的資料（分別保存，不合併）
        $allTablesData = [];
        $totalRows = 0;
        
        // 首先嘗試從 tables 中提取數據
        if (!empty($domData['tables'])) {
            foreach ($domData['tables'] as $tableIndex => $table) {
                // 檢查是否有嵌套的 tables 屬性（新格式）
                if (!empty($table['tables'])) {
                    foreach ($table['tables'] as $subTableIndex => $subTable) {
                        if (!empty($subTable['data'])) {
                            $allTablesData[] = [
                                'tableIndex' => $subTableIndex,
                                'tableId' => $subTable['tableId'] ?? null,
                                'tableClass' => $subTable['tableClass'] ?? null,
                                'headers' => $subTable['headers'] ?? [],
                                'headerCount' => count($subTable['headers'] ?? []),
                                'rowCount' => count($subTable['data']),
                                'data' => $subTable['data']
                            ];
                            $totalRows += count($subTable['data']);
                        }
                    }
                } elseif (!empty($table['data'])) {
                    // 舊格式：直接處理表格數據
                    $allTablesData[] = [
                        'tableIndex' => $tableIndex,
                        'tableId' => $table['tableId'] ?? null,
                        'tableClass' => $table['tableClass'] ?? null,
                        'headers' => $table['headers'] ?? [],
                        'headerCount' => count($table['headers'] ?? []),
                        'rowCount' => count($table['data']),
                        'data' => $table['data']
                    ];
                    $totalRows += count($table['data']);
                }
            }
        }
        
        // 如果 tables 為空或沒有數據，嘗試從 pages 中提取數據
        if (empty($allTablesData) && !empty($domData['pages'])) {
            foreach ($domData['pages'] as $page) {
                if (!empty($page['tables'])) {
                    foreach ($page['tables'] as $tableIndex => $table) {
                        if (!empty($table['data'])) {
                            $allTablesData[] = [
                                'tableIndex' => $tableIndex,
                                'tableId' => $table['tableId'] ?? null,
                                'tableClass' => $table['tableClass'] ?? null,
                                'headers' => $table['headers'] ?? [],
                                'headerCount' => count($table['headers'] ?? []),
                                'rowCount' => count($table['data']),
                                'data' => $table['data']
                            ];
                            $totalRows += count($table['data']);
                        }
                    }
                }
            }
        }

        // 如果有資料，分別保存每個表格的資料
        if (!empty($allTablesData)) {
            // 為每個表格創建單獨的文件
            foreach ($allTablesData as $tableIndex => $tableData) {
                $tableFileName = "scraped_data/table_{$tableIndex}_{$timestamp}.json";
                $tableFileData = [
                    'metadata' => [
                        'timestamp' => $timestamp,
                        'url' => $result['url'] ?? '',
                        'queryParams' => $queryParams,
                        'tableIndex' => $tableIndex,
                        'tableId' => $tableData['tableId'],
                        'tableClass' => $tableData['tableClass'],
                        'totalRows' => $tableData['rowCount']
                    ],
                    'headers' => $tableData['headers'],
                    'headerCount' => $tableData['headerCount'],
                    'rowCount' => $tableData['rowCount'],
                    'data' => $tableData['data']
                ];
                
                Storage::put($tableFileName, json_encode($tableFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("✅ Table {$tableIndex} data saved to: {$tableFileName}");
            }
            
            // 同時保存舊格式的合併數據（向後兼容，使用第一個表格的數據）
            $firstTable = $allTablesData[0];
            $allData = $firstTable['data'];
            $headers = $firstTable['headers'];
            
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
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}

