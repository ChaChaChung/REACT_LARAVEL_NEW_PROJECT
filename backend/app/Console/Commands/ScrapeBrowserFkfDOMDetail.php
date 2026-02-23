<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserFkfDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-fkf-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {account_number?} - 要填入的帳號（可選參數）
     * {platform?} - 要選擇的平台（可選參數）
     * {--concurrency=4} - 併發數量（可選，預設為 4）
     */
    protected $signature = 'agent:scrape-fkf-dom-detail {url} {date_start?} {date_end?} {platform?} {account_number?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from FKF DOM elements using browser automation with detailed information';

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
        $account_number = $this->argument('account_number');
        $platform = $this->argument('platform');
        $concurrency = $this->option('concurrency');

        $this->info('=== Browser DOM Scraper (Concurrent) ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Range: {$date_start} to {$date_end}");
        $this->info("Account Number: {$account_number}");
        $this->info("Platform: {$platform}");
        $this->info("Concurrency: {$concurrency}");

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 日期迴圈處理
        $start = strtotime($date_start);
        $end = strtotime($date_end);

        for ($current = $start; $current <= $end; $current += 86400) {
            $currentDate = date('Ymd', $current);
            $this->info("Processing date: {$currentDate}");

            // 創建 Puppeteer 腳本
            $scriptPath = $this->createPuppeteerScript($url, $currentDate, $currentDate, $account_number, $platform, $concurrency);

            // 執行腳本
            $result = $this->runPuppeteerScript($scriptPath);

            // 如果執行成功，處理爬取的資料
            if ($result) {
                $this->processScrapedDataFinal($result, $currentDate);
            } else {
                $this->error("Failed to process data for date: {$currentDate}");
            }
        }

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        return 0;
    }

    /**
     * 處理爬取的資料（詳細版本）
     * @param mixed $data 爬取的資料
     * @param string $date 當前處理的日期
     */
    private function processScrapedDataFinal($data, $date)
    {
        $this->info('4. Processing scraped data...');

        // 檢查爬取是否成功
        if (!$data['success']) {
            $this->error("❌ Scraping failed: " . ($data['error'] ?? 'Unknown error'));
            return;
        }

        // 處理成功的資料
        $data = $data['data'] ?? [];
        $fileName = "scraped_data_final.json";
        $filePath = storage_path("app/{$fileName}");

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Final data saved to: {$filePath}");
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
     * @param string|null $account_number 要填入的帳號（可選）
     * @param string|null $platform 要選擇的平台（可選）
     * @param int $concurrency 併發數量
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date_start = null, $date_end = null, $account_number = null, $platform = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段（主頁面用）
        $cookiesCodeForPage = $this->generateFkfPuppeteerCookiesCode('page');
        // 獲取認證 cookies 程式碼片段（併發頁面用）
        $cookiesCodeForNewPage = $this->generateFkfPuppeteerCookiesCode('newPage');

        // 將 date 轉換為 JavaScript 可用的格式（開始日期 00:00:00，結束日期 23:59:59）
        $dateStartJs = $date_start ? json_encode(date('Y-m-d 00:00:00', strtotime($date_start))) : 'null';
        $dateEndJs = $date_end ? json_encode(date('Y-m-d 23:59:59', strtotime($date_end))) : 'null';
        // 若同時有開始與結束日期，產生逐日陣列供迴圈使用（一天一天搜尋）
        $dateRangeJs = 'null';
        $dateRangeJsLiteral = '[]'; // 給 JS 用的字串字面，避免 heredoc 插值變成陣列
        if ($date_start && $date_end) {
            $start = strtotime($date_start);
            $end = strtotime($date_end);
            $dates = [];
            for ($t = $start; $t <= $end; $t += 86400) {
                $dates[] = date('Y-m-d', $t);
            }
            $dateRangeJs = json_encode($dates);
            $dateRangeJsLiteral = json_encode($dateRangeJs);
        }
        $accountNumberJs = $account_number ? json_encode($account_number) : 'null';
        $platformJs = $platform ? json_encode($platform) : 'null';

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
             * 輔助函數：查找並點擊搜尋按鈕
             * @param {Page} page - Puppeteer 頁面對象
             * @returns {Promise<boolean>} 返回是否成功點擊
             */
            async function clickSearchButton(page) {
                const searchButton = await page.evaluate(() => {
                    const allButtons = Array.from(document.querySelectorAll('button'));
                    let searchBtn = null;
                    
                    // 優先查找文本為 "Search" 的按鈕
                    searchBtn = allButtons.find(btn => {
                        const text = btn.textContent.trim();
                        return text === 'Search' || text === '搜尋';
                    });
                    
                    // 如果沒找到，嘗試通過 class 查找
                    if (!searchBtn) {
                        searchBtn = allButtons.find(btn => {
                            return btn.classList.contains('btn-primary') && 
                                   (btn.textContent.trim() === 'Search' || 
                                    btn.textContent.trim() === '搜尋' ||
                                    btn.textContent.trim().toLowerCase().includes('search'));
                        });
                    }
                    
                    // 如果還是沒找到，查找 type="submit" 或 type="sbumit" 的按鈕
                    if (!searchBtn) {
                        searchBtn = allButtons.find(btn => {
                            const type = btn.getAttribute('type');
                            return (type === 'submit' || type === 'sbumit') && 
                                   btn.classList.contains('btn-primary');
                        });
                    }

                    if (searchBtn) {
                        const uniqueId = 'search-btn-' + Date.now();
                        searchBtn.setAttribute('data-puppeteer-id', uniqueId);
                        return {
                            found: true,
                            selector: '[data-puppeteer-id="' + uniqueId + '"]',
                            text: searchBtn.textContent.trim(),
                            type: searchBtn.getAttribute('type')
                        };
                    }
                    
                    return { found: false };
                });

                if (searchButton.found) {
                    await page.click(searchButton.selector, { timeout: 5000 });
                    return true;
                }
                
                return false;
            }

            /**
             * 輔助函數：查找所有帳號連結（從表格中）
             * @param {Page} page - Puppeteer 頁面對象
             * @param {string} accountValue - 帳號值
             * @returns {Promise<Object>} 返回找到的所有連結信息或調試信息
             */
            async function findAllAccountLinks(page, accountValue) {
                return await page.evaluate((accountValue) => {
                    const table = document.querySelector('#simple-table') || document.querySelector('table');
                    if (!table) {
                        return { found: false, links: [], debug: { error: 'No table found' } };
                    }
                    
                    // 從表格中獲取所有連結
                    const tableLinks = Array.from(table.querySelectorAll('a'));
                    const matchedLinks = [];
                    
                    // 查找所有匹配的連結
                    tableLinks.forEach((link, index) => {
                        const text = link.textContent.trim();
                        // 完全匹配或包含帳號
                        if (text === accountValue || text.includes(accountValue)) {
                            // 為每個連結設定唯一 ID
                            const uniqueId = 'account-link-' + index + '-' + Date.now();
                            link.setAttribute('data-puppeteer-id', uniqueId);
                            
                            matchedLinks.push({
                                selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                href: link.href,
                                text: text,
                                index: index
                            });
                        }
                    });
                    
                    if (matchedLinks.length > 0) {
                        return {
                            found: true,
                            links: matchedLinks,
                            count: matchedLinks.length
                        };
                    }
                    
                    // 返回調試信息
                    const tableRows = Array.from(table.querySelectorAll('tbody tr'));
                    const sampleTableLinks = tableLinks.slice(0, 10).map(a => ({
                        text: a.textContent.trim(),
                        href: a.href
                    }));
                    
                    return {
                        found: false,
                        links: [],
                        debug: {
                            tableRows: tableRows.length,
                            tableLinks: tableLinks.length,
                            sampleTableLinks: sampleTableLinks,
                            accountValue: accountValue
                        }
                    };
                }, accountValue);
            }


            /**
             * 輔助函數：處理 URL（轉換相對路徑為絕對路徑）
             * @param {string} href - 原始 URL
             * @param {string} currentUrl - 當前頁面 URL
             * @returns {string} 處理後的完整 URL
             */
            function normalizeUrl(href, currentUrl) {
                if (href.startsWith('//')) {
                    return 'https:' + href;
                } else if (href.startsWith('/')) {
                    const urlObj = new URL(currentUrl);
                    return urlObj.origin + href;
                }
                return href;
            }

            /**
             * 輔助函數：導航到帳號連結
             * @param {Page} page - Puppeteer 頁面對象（可能被修改）
             * @param {Browser} browser - Puppeteer 瀏覽器對象
             * @param {Object} accountLink - 帳號連結信息
             * @returns {Promise<Page>} 返回當前使用的頁面對象
             */
            async function navigateToAccountLink(page, browser, accountLink) {
                // 處理 URL
                let currentUrl;
                try {
                    currentUrl = page.url();
                } catch (e) {
                    // 如果頁面已關閉，使用 accountLink.href 中的域名信息
                    currentUrl = accountLink.href.startsWith('//') ? 'https:' + accountLink.href : accountLink.href;
                    const urlObj = new URL(currentUrl);
                    currentUrl = urlObj.origin + urlObj.pathname;
                }
                
                const targetUrl = normalizeUrl(accountLink.href, currentUrl);
                
                // 檢查連結是否有 target="_blank" 屬性
                let linkInfo = null;
                try {
                    linkInfo = await page.evaluate((selector) => {
                        const link = document.querySelector(selector);
                        if (link) {
                            return {
                                hasTargetBlank: link.getAttribute('target') === '_blank',
                                href: link.href
                            };
                        }
                        return null;
                    }, accountLink.selector);
                } catch (e) {
                    console.log('⚠️  Could not evaluate link info, assuming direct navigation: ' + e.message);
                }
                
                if (linkInfo && linkInfo.hasTargetBlank) {
                    const pagesBefore = await browser.pages();
                    
                    try {
                        await page.evaluate((selector) => {
                            const link = document.querySelector(selector);
                            if (link) {
                                link.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                link.click();
                            }
                        }, accountLink.selector);
                    } catch (e) {
                        console.log('⚠️  Could not click link, navigating directly: ' + e.message);
                        // 如果點擊失敗，直接導航
                        await page.goto(targetUrl, {
                            waitUntil: 'domcontentloaded',
                            timeout: 30000
                        });
                        await page.waitForSelector('#simple-table, table', { timeout: 10000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        return page;
                    }
                    
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    const pagesAfter = await browser.pages();
                    const newPage = pagesAfter.length > pagesBefore.length 
                        ? pagesAfter.find(p => !pagesBefore.includes(p))
                        : null;
                    
                    if (newPage) {
                        // 不關閉原始頁面，因為可能需要用於後續連結
                        // await page.close();
                        page = newPage;
                        await page.waitForSelector('#simple-table, table', { timeout: 15000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        return page;
                    } else {
                        await page.goto(targetUrl, {
                            waitUntil: 'domcontentloaded',
                            timeout: 30000
                        });
                        await page.waitForSelector('#simple-table, table', { timeout: 10000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        return page;
                    }
                } else {
                    console.log('🔗 Navigating directly to URL...');
                    await page.goto(targetUrl, {
                        waitUntil: 'domcontentloaded',
                        timeout: 30000
                    });
                    await page.waitForSelector('#simple-table, table', { timeout: 10000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    return page;
                }
            }

            /**
             * 輔助函數：設置頁面基本配置（viewport, userAgent, request interception）
             * @param {Page} pageObject - Puppeteer 頁面對象
             * @returns {Promise<void>}
             */
            async function setupPage(pageObject) {
                await pageObject.setViewport({ width: 1920, height: 1080 });
                await pageObject.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
                
                // 設定資源攔截
                await pageObject.setRequestInterception(true);
                pageObject.on('request', (req) => {
                    const resourceType = req.resourceType();
                    if (['image', 'font', 'media'].includes(resourceType)) {
                        req.abort();
                    } else {
                        req.continue();
                    }
                });
            }
            async function scrollTableHeaderIntoView(pageObject) {
                await pageObject.evaluate(() => {
                    window.scrollTo(0, 0);
                    const table = document.querySelector('#simple-table') || document.querySelector('table');
                    if (table) {
                        table.scrollIntoView({ behavior: 'instant', block: 'start' });
                        const thead = table.querySelector('thead');
                        const firstRow = thead ? thead.querySelector('tr') : table.querySelector('tr');
                        if (firstRow) firstRow.scrollIntoView({ behavior: 'instant', block: 'start' });
                    }
                });
                await new Promise(resolve => setTimeout(resolve, 400));
            }
            async function scrollTotalRowIntoView(pageObject) {
                await pageObject.evaluate(() => {
                    const table = document.querySelector('#simple-table') || document.querySelector('table');
                    if (!table) return;
                    const rows = table.querySelectorAll('tbody tr, tr');
                    for (let i = 0; i < rows.length; i++) {
                        if (/Total[：:]|總計/.test(rows[i].textContent || '')) {
                            rows[i].scrollIntoView({ behavior: 'instant', block: 'center' });
                            break;
                        }
                    }
                });
                await new Promise(resolve => setTimeout(resolve, 300));
            }
            async function highlightScrapedTable(pageObject) {
                await pageObject.evaluate(() => {
                    let table = document.querySelector('#simple-table') || null;
                    if (!table) {
                        const tables = document.querySelectorAll('table');
                        let maxRows = 0;
                        tables.forEach(t => {
                            const rows = t.querySelectorAll('tbody tr').length || t.querySelectorAll('tr').length;
                            if (rows > maxRows) { maxRows = rows; table = t; }
                        });
                    }
                    if (table) {
                        table.scrollIntoView({ behavior: 'instant', block: 'center' });
                        table.style.setProperty('border', '6px solid #e60000', 'important');
                        table.style.setProperty('box-shadow', '0 0 0 4px #e60000', 'important');
                    }
                });
                await new Promise(resolve => setTimeout(resolve, 400));
            }

            /**
             * 提取表格資料的函數（可重用）
             * @param {Page} pageObject - Puppeteer 頁面對象（可以是 page 或 newPage）
             * @returns {Promise<Object>} 返回提取的表格資料
             */
            async function extractTableData(pageObject) {
                return await pageObject.evaluate(() => {
                    const table = document.querySelector('#simple-table') || (() => {
                        const tables = document.querySelectorAll('table');
                        let best = null, maxRows = 0;
                        tables.forEach(t => {
                            const rows = t.querySelectorAll('tbody tr').length || t.querySelectorAll('tr').length;
                            if (rows > maxRows) { maxRows = rows; best = t; }
                        });
                        return best;
                    })();
                    if (!table) {
                        return { found: false, error: 'No table found' };
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
                            const firstCellVal = cells[0] ? cells[0].textContent.trim() : '';
                            const totalMatch = firstCellVal.match(/Total[：:]|總計/);
                            if (totalMatch) {
                                rowData.Total_Bet_Times = totalMatch[1];
                                rowData.Bet = cells[1] ? cells[1].textContent.trim() : null;
                                rowData.Total_Bet_Amount = cells[2] ? cells[2].textContent.trim() : null;
                                rowData.Total_Win_Loss = cells[3] ? cells[3].textContent.trim() : null;
                                return rowData;
                            }
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
                                            
                                            const cellValue = cells[colIndex] ? cells[colIndex].textContent.trim() : null;
                                            
                                            // 特殊處理：如果字段是「投注時間_單號」或「投注時間/單號」，則分割成兩個字段
                                            if (cellValue && (cleanHeader === '投注時間_單號' || cleanHeader === '投注時間單號' || (header.includes('投注時間') && header.includes('單號')))) {
                                                // 分割換行符（使用 RegExp 構造函數避免 heredoc 換行問題）
                                                const parts = cellValue.split(new RegExp('[\\n\\r]+'));
                                                if (parts.length >= 2) {
                                                    // 分割成「投注時間」和「單號」兩個字段
                                                    rowData['投注時間'] = parts[0].trim();
                                                    // 合併剩餘部分並去除多餘空白
                                                    rowData['單號'] = parts.slice(1).map(p => p.trim()).filter(p => p).join('').trim();
                                                } else {
                                                    // 如果沒有換行符，保持原值
                                                    rowData[finalHeader] = cellValue;
                                                }
                                            } 
                                            // 特殊處理：如果字段是 Bet_Time_Order_Number，則分割成兩個字段
                                            else if (cellValue && (cleanHeader === 'Bet_Time_Order_Number' || cleanHeader.toLowerCase() === 'bet_time_order_number')) {
                                                const parts = cellValue.split(new RegExp('[\\n\\r]+'));
                                                if (parts.length >= 2) {
                                                    rowData['Bet_Time'] = parts[0].trim();
                                                    rowData['Order_Number'] = parts.slice(1).map(p => p.trim()).filter(p => p).join('').trim();
                                                } else {
                                                    rowData[finalHeader] = cellValue;
                                                }
                                            }
                                            // Account: 換行前為帳號，換行後為姓名
                                            else if (cellValue && (cleanHeader === 'Account' || cleanHeader === '帳號')) {
                                                const parts = cellValue.split(new RegExp('[\\n\\r]+')).map(p => p.trim()).filter(p => p);
                                                if (parts.length >= 2) {
                                                    rowData['帳號'] = parts[0];
                                                    rowData['姓名'] = parts.slice(1).join(' ').trim();
                                                } else {
                                                    rowData['帳號'] = parts[0] || cellValue.trim();
                                                    rowData['姓名'] = '';
                                                }
                                            }
                                            // Bet: 括號前為金額，括號內為數量
                                            else if (cellValue && (cleanHeader === 'Bet' || cleanHeader === '注額' || cleanHeader === '下注')) {
                                                const match = cellValue.match(/^(.+?)\s*\(([^)]+)\)\s*$/);
                                                if (match) {
                                                    rowData['Bet'] = match[1].trim();
                                                    rowData['Bet_Records'] = match[2].replace(/\D/g, '') || '';
                                                } else {
                                                    rowData[finalHeader] = cellValue;
                                                }
                                            } else {
                                                rowData[finalHeader] = cellValue;
                                            }
                                        });
                                    } else {
                                        // 如果沒有表頭，使用索引作為 key
                                        cells.forEach((cell, colIndex) => {
                                            rowData['column_' + colIndex] = cell ? cell.textContent.trim() : null;
                                        });
                                    }
                            return rowData;
                        })
                        .filter(rowData => {
                            if (rowData.Total_Bet_Times != null) return true;
                            const firstValue = Object.values(rowData)[0];
                            if (!firstValue) return false;
                            const valueStr = String(firstValue);
                            const lowerValue = valueStr.toLowerCase();
                            return firstValue === '總計' || valueStr === 'Total：' || valueStr.startsWith('Total：') || lowerValue === 'total' || lowerValue === 'current total';
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
                                if (rowArray.length === 0) return false;
                                const firstValue = rowArray[0];
                                if (!firstValue) return false;
                                const valueStr = String(firstValue);
                                const lowerValue = valueStr.toLowerCase();
                                return firstValue === '總計' || valueStr === 'Total：' || valueStr.startsWith('Total：') || lowerValue === 'total' || lowerValue === 'current total';
                            }),
                        data: dataRows
                    };
                });
            }

            /**
             * 輔助函數：處理單個帳號連結的詳情頁面（爬取數據並截圖，支持分頁）
             * @param {Page} detailPage - 詳情頁面對象
             * @param {Object} accountLink - 帳號連結信息
             * @param {number} linkIndex - 連結索引（用於文件命名）
             * @param {string} accountValue - 帳號值（用於文件命名）
             * @returns {Promise<Object>} 返回爬取的數據和文件路徑
             */
            async function processAccountLinkDetail(detailPage, accountLink, linkIndex, accountValue) {
                console.log('📄 Processing detail page ' + (linkIndex + 1) + ' for account: ' + accountValue);
                
                try {
                    await detailPage.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 8000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // 獲取當前頁面 URL（用於識別平台）
                    const pageUrl = detailPage.url();
                    const urlParams = new URL(pageUrl).searchParams;
                    const platform = urlParams.get('gm') || 'unknown';
                    
                    // 存儲所有頁面的數據
                    const allPagesData = [];
                    let currentPageNum = 1;
                    let hasNextPage = true;
                    
                    console.log('📑 Starting pagination crawl for detail page...');
                    
                    // 使用 while 循環和"下一頁"按鈕來遍歷所有頁面
                    while (hasNextPage) {
                        console.log('📄 Crawling page ' + currentPageNum + '...');
                        await detailPage.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 8000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 500));
                        // 提取當前頁面的數據
                        const pageData = await extractTableData(detailPage);
                        allPagesData.push({
                            pageNumber: currentPageNum,
                            tableData: pageData
                        });
                        
                        console.log('✅ Page ' + currentPageNum + ' done, rows: ' + (pageData.rowCount || 0));
                        
                        // 檢查是否有下一頁
                        const nextPageInfo = await detailPage.evaluate(() => {
                            const nextLink = document.querySelector('a[rel="next"]');
                            if (nextLink && nextLink.href) {
                                const style = window.getComputedStyle(nextLink);
                                const isVisible = style.display !== 'none' && 
                                               style.visibility !== 'hidden' && 
                                               style.opacity !== '0' &&
                                               !nextLink.classList.contains('disabled');
                                if (isVisible) {
                                    return {
                                        hasNext: true,
                                        nextUrl: nextLink.href
                                    };
                                }
                            }
                            return { hasNext: false };
                        });
                        
                        // 如果有下一頁，導航到下一頁
                        if (nextPageInfo.hasNext) {
                            try {
                                await detailPage.goto(nextPageInfo.nextUrl, {
                                    waitUntil: 'domcontentloaded',
                                    timeout: 30000
                                });
                                currentPageNum++;
                            } catch (e) {
                                console.log('⚠️  Failed to navigate to next page: ' + e.message);
                                hasNextPage = false;
                            }
                        } else {
                            hasNextPage = false;
                        }
                    }
                    
                    console.log('✅ Total pages crawled: ' + allPagesData.length);
                    
                    // 合併所有頁面的數據，並進行去重
                    const allData = [];
                    const seenRows = new Set(); // 用於追蹤已看到的行
                    let allHeaders = [];
                    
                    allPagesData.forEach(pageInfo => {
                        if (pageInfo.tableData && pageInfo.tableData.found) {
                            if (allHeaders.length === 0 && pageInfo.tableData.headers) {
                                allHeaders = pageInfo.tableData.headers;
                            }
                            if (pageInfo.tableData.data) {
                                pageInfo.tableData.data.forEach(row => {
                                    // 使用「單號」或 Order_Number 作為唯一標識符進行去重（因為已經分割了）
                                    // 如果沒有，則嘗試使用原始字段
                                    // 如果都沒有，使用整個行的 JSON 字符串作為標識符
                                    const rowKey = row['單號'] || row['Order_Number'] || row['投注時間_單號'] || row['投注時間/單號'] || row['Bet_Time_Order_Number'] || JSON.stringify(row);
                                    
                                    if (!seenRows.has(rowKey)) {
                                        seenRows.add(rowKey);
                                        allData.push(row);
                                    }
                                });
                            }
                        }
                    });
                    
                    // 創建合併後的表格數據對象
                    const mergedTableData = {
                        found: true,
                        headers: allHeaders,
                        headerCount: allHeaders.length,
                        rowCount: allData.length,
                        data: allData,
                        totalPages: allPagesData.length,
                        pages: allPagesData
                    };
                    
                    // 紅框標示表格後截圖
                    await highlightScrapedTable(detailPage);
                    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
                    const screenshotFilename = 'account_' + accountValue + '_link_' + (linkIndex + 1) + '_' + timestamp + '.png';
                    await detailPage.screenshot({ 
                        path: screenshotFilename,
                        fullPage: false
                    });
                    
                    return {
                        linkIndex: linkIndex,
                        accountLink: accountLink,
                        platform: platform,
                        tableData: mergedTableData,
                        screenshot: screenshotFilename,
                        pageUrl: pageUrl,
                        success: mergedTableData.found
                    };
                } catch (error) {
                    // 如果頁面已經關閉或無效，捕獲錯誤
                    console.error('❌ Error in processAccountLinkDetail: ' + error.message);
                    throw error;
                }
            }

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容（併發版本）
             */
            async function scrapeDOMContent() {
                // 存儲所有帳號詳情結果（在函數作用域內）
                let accountDetailResults = [];
                
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
                    // 創建新的瀏覽器頁面（使用 let 因為可能需要重新賦值）
                    let page = await browser.newPage();

                    // 設置頁面基本配置
                    await setupPage(page);

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

                    // 等待表格元素出現，而不是固定等待時間
                    await page.waitForSelector('#simple-table, table', { timeout: 10000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));

                    // 解析 date
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    let accountNumberParsed = null;
                    let platformParsed = null;
                    
                    try {
                        if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs !== '') {
                            dateStartParsed = JSON.parse($dateStartJs);
                        }
                        if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs !== '') {
                            dateEndParsed = JSON.parse($dateEndJs);
                        }
                        if ($accountNumberJs && $accountNumberJs !== 'null' && $accountNumberJs !== '') {
                            accountNumberParsed = JSON.parse($accountNumberJs);
                        }
                        if ($platformJs && $platformJs !== 'null' && $platformJs !== '') {
                            platformParsed = JSON.parse($platformJs);
                        }
                    } catch (e) {
                        dateStartParsed = $dateStartJs !== 'null' ? $dateStartJs : null;
                        dateEndParsed = $dateEndJs !== 'null' ? $dateEndJs : null;
                        accountNumberParsed = $accountNumberJs !== 'null' ? $accountNumberJs : null;
                        platformParsed = $platformJs !== 'null' ? $platformJs : null;
                    }

                    // 如果提供了 platform，選擇對應的平台（先試 gm，再試 find6）
                    if (platformParsed && platformParsed !== null && platformParsed !== '') {
                        try {
                            const gmSelect = await page.$('select[name="gm"]');
                            const find6Select = await page.$('select[name="find6"]');
                            if (gmSelect) {
                                await page.select('select[name="gm"]', platformParsed);
                                console.log('✅ Platform selected (gm): ' + platformParsed);
                            } else if (find6Select) {
                                await page.select('select[name="find6"]', platformParsed);
                                console.log('✅ Platform selected (find6): ' + platformParsed);
                            } else {
                                console.log('⚠️  No platform select (gm/find6) found');
                            }
                            await new Promise(resolve => setTimeout(resolve, 500));
                        } catch (e) {
                            console.log('⚠️  Error selecting platform: ' + e.message);
                        }
                    }

                    try {
                        const tzSelect = await page.$('select[name="find5"]');
                        if (tzSelect) {
                            await page.select('select[name="find5"]', '0');
                            console.log('✅ Timezone selected: 0');
                            await new Promise(resolve => setTimeout(resolve, 300));
                        }
                    } catch (e) {
                        console.log('⚠️  Timezone select (find5) skip: ' + e.message);
                    }

                    let dateRange = [];
                    try {
                        dateRange = JSON.parse($dateRangeJsLiteral);
                        if (!Array.isArray(dateRange)) dateRange = [];
                    } catch (e) {}

                    if (dateRange && dateRange.length > 0) {
                        console.log('📅 Day-by-day mode: ' + dateRange.length + ' day(s) from ' + dateRange[0] + ' to ' + dateRange[dateRange.length - 1]);
                        await page.waitForSelector('input[placeholder="Start Date"], input[placeholder="開始日期"]', { timeout: 10000 });
                        await page.waitForSelector('input[placeholder="End Date"], input[placeholder="結束日期"]', { timeout: 10000 });
                        const allCollectedTables = [];
                        for (let d = 0; d < dateRange.length; d++) {
                            const day = dateRange[d];
                            const startVal = day + ' 00:00:00';
                            const endVal = day + ' 23:59:59';
                            await page.evaluate((s, e) => {
                                const input1 = document.querySelector('input[placeholder="Start Date"]') || document.querySelector('input[placeholder="開始日期"]');
                                const input2 = document.querySelector('input[placeholder="End Date"]') || document.querySelector('input[placeholder="結束日期"]');
                                if (input1) { input1.value = s; input1.dispatchEvent(new Event('input', { bubbles: true })); input1.dispatchEvent(new Event('change', { bubbles: true })); }
                                if (input2) { input2.value = e; input2.dispatchEvent(new Event('input', { bubbles: true })); input2.dispatchEvent(new Event('change', { bubbles: true })); }
                            }, startVal, endVal);
                            await new Promise(resolve => setTimeout(resolve, 500));
                            await clickSearchButton(page);
                            console.log('⏳ [' + (d + 1) + '/' + dateRange.length + '] ' + day + ' - Waiting for table...');
                            await new Promise(resolve => setTimeout(resolve, 3000));
                            await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 15000 }).catch(() => {});
                            let firstPageData = await extractTableData(page);
                            let dayRows = Array.isArray(firstPageData.data) ? firstPageData.data : [];
                            let hasNextPage = true;
                            while (hasNextPage) {
                                const nextPageInfo = await page.evaluate(() => {
                                    const nextLink = document.querySelector('a[rel="next"]');
                                    if (nextLink && nextLink.href) {
                                        const style = window.getComputedStyle(nextLink);
                                        const visible = style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0' && !nextLink.classList.contains('disabled');
                                        if (visible) return { hasNext: true, nextUrl: nextLink.href };
                                    }
                                    return { hasNext: false };
                                });
                                if (nextPageInfo.hasNext) {
                                    await page.goto(nextPageInfo.nextUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
                                    await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 8000 }).catch(() => {});
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    const pageData = await extractTableData(page);
                                    if (pageData.data) dayRows = dayRows.concat(pageData.data);
                                } else {
                                    hasNextPage = false;
                                }
                            }
                            if (dayRows.length > 0) {
                                dayRows = dayRows.map(row => ({ Date: day, ...row }));
                                allCollectedTables.push({ data: dayRows, rowCount: dayRows.length });
                                console.log('✅ ' + day + ': ' + dayRows.length + ' row(s)');
                            }
                        }
                        const mergedData = [];
                        allCollectedTables.forEach(t => {
                            if (t.data) mergedData.push(...t.data);
                        });
                        const result = {
                            timestamp: new Date().toISOString(),
                            url: '$url',
                            queryParams: { date_start: dateRange[0] + ' 00:00:00', date_end: dateRange[dateRange.length - 1] + ' 23:59:59', account_number: accountNumberParsed, platform: platformParsed },
                            domData: {
                                pageInfo: { title: '', url: page.url() },
                                queryParams: { date_start: dateRange[0], date_end: dateRange[dateRange.length - 1] },
                                totalPages: dateRange.length,
                                pages: [],
                                tables: [{ data: mergedData, rowCount: mergedData.length }]
                            },
                            accountDetailResults: [],
                            success: true
                        };
                        fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                        console.log('💾 Results saved: ' + mergedData.length + ' total row(s)');
                        return result;
                    }

                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') && (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            await page.waitForSelector('input[placeholder="Start Date"], input[placeholder="開始日期"]', { timeout: 10000 });
                            await page.waitForSelector('input[placeholder="End Date"], input[placeholder="結束日期"]', { timeout: 10000 });
                            await page.evaluate((dateStartValue, dateEndValue) => {
                                const input1 = document.querySelector('input[placeholder="Start Date"]') || document.querySelector('input[placeholder="開始日期"]');
                                const input2 = document.querySelector('input[placeholder="End Date"]') || document.querySelector('input[placeholder="結束日期"]');
                                if (input1) { input1.value = ''; input1.value = dateStartValue; input1.dispatchEvent(new Event('input', { bubbles: true })); input1.dispatchEvent(new Event('change', { bubbles: true })); }
                                if (input2) { input2.value = ''; input2.value = dateEndValue; input2.dispatchEvent(new Event('input', { bubbles: true })); input2.dispatchEvent(new Event('change', { bubbles: true })); }
                            }, dateStartParsed, dateEndParsed);
                            await new Promise(resolve => setTimeout(resolve, 500));
                            await clickSearchButton(page);
                            console.log('⏳ Waiting 30s for search to complete...');
                            await new Promise(resolve => setTimeout(resolve, 30000));
                            await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 15000 }).catch(() => {});
                        } catch (e) {
                            console.log('⚠️  Error filling date: ' + e.message);
                        }
                    }

                    // 如果提供了 account_number，填入 input#find4 並點擊搜尋
                    if (accountNumberParsed && accountNumberParsed !== null && accountNumberParsed !== '') {
                        try {
                            // 查找 input#find4 欄位
                            await page.waitForSelector('#find4', { timeout: 10000 });
                            
                            // 清空並填入帳號到 find4 欄位
                            await page.evaluate((accountValue) => {
                                const input4 = document.querySelector('#find4');
                                
                                if (input4) {
                                    input4.value = '';
                                    input4.value = accountValue;
                                    input4.dispatchEvent(new Event('input', { bubbles: true }));
                                    input4.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }, accountNumberParsed);

                            // 減少等待時間
                            await new Promise(resolve => setTimeout(resolve, 500));

                            await clickSearchButton(page);
                            console.log('⏳ Waiting 30s for search to complete...');
                            await new Promise(resolve => setTimeout(resolve, 30000));
                            await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 15000 }).catch(() => {});
                        } catch (e) {
                            console.log('⚠️  Error filling account number: ' + e.message);
                        }
                    }

                    // 無論是否提供了 account_number，都要查找並處理所有分頁的帳號連結
                    try {
                        // ========== 步驟 1：遍歷所有分頁，收集所有帳號連結 ==========
                        console.log('📄 Step 1: Collecting all account links from all pages...');
                        
                        await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 8000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 500));
                        // 用於存儲所有頁面的帳號連結
                        const allAccountLinks = [];
                        let currentPageNum = 1;
                        let hasNextPage = true;
                        
                        // 遍歷所有分頁，收集帳號連結
                        while (hasNextPage && currentPageNum <= 100) { // 限制最多100頁
                            console.log('🔍 Page ' + currentPageNum + ': Searching for account links...');
                            
                            await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 8000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 500));
                            // 查找當前頁面的所有帳號連結
                            const pageAccountLinks = await page.evaluate((pageNum) => {
                                const table = document.querySelector('#simple-table') || document.querySelector('table');
                                if (!table) return [];
                                const tableLinks = Array.from(table.querySelectorAll('a'));
                                const matchedLinks = [];
                                
                                // 查找所有 Account 欄位的連結
                                tableLinks.forEach((link, index) => {
                                    const href = link.href;
                                    const text = link.textContent.trim();
                                    
                                    // 過濾條件：連結文字看起來像帳號
                                    if (text && text.length > 0 && (
                                        text.match(/^[a-zA-Z0-9]+$/) || // 純英數字組合
                                        href.includes('account') ||     // URL 包含 account
                                        href.includes('detail') ||      // URL 包含 detail
                                        href.includes('gm=')            // URL 包含遊戲參數
                                    )) {
                                        matchedLinks.push({
                                            href: link.href,
                                            text: text,
                                            pageNumber: pageNum,
                                            index: index
                                        });
                                    }
                                });
                                
                                return matchedLinks;
                            }, currentPageNum);
                            
                            // 將當前頁面的連結加入總列表
                            if (pageAccountLinks.length > 0) {
                                allAccountLinks.push(...pageAccountLinks);
                                console.log('✅ Page ' + currentPageNum + ': Found ' + pageAccountLinks.length + ' account link(s)');
                            } else {
                                console.log('⚠️  Page ' + currentPageNum + ': No account links found');
                            }
                            
                            // 檢查是否有下一頁
                            const nextPageInfo = await page.evaluate(() => {
                                const nextLink = document.querySelector('a[rel="next"]');
                                if (nextLink && nextLink.href) {
                                    const style = window.getComputedStyle(nextLink);
                                    const isVisible = style.display !== 'none' && 
                                                   style.visibility !== 'hidden' && 
                                                   style.opacity !== '0' &&
                                                   !nextLink.classList.contains('disabled');
                                    if (isVisible) {
                                        return {
                                            hasNext: true,
                                            nextUrl: nextLink.href
                                        };
                                    }
                                }
                                return { hasNext: false };
                            });
                            
                            // 如果有下一頁，導航到下一頁
                            if (nextPageInfo.hasNext) {
                                try {
                                    await page.goto(nextPageInfo.nextUrl, {
                                        waitUntil: 'domcontentloaded',
                                        timeout: 30000
                                    });
                                    currentPageNum++;
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                } catch (e) {
                                    console.log('⚠️  Failed to navigate to page ' + (currentPageNum + 1) + ': ' + e.message);
                                    hasNextPage = false;
                                }
                            } else {
                                hasNextPage = false;
                            }
                        }
                        
                        console.log('✅ Total account links collected: ' + allAccountLinks.length + ' from ' + currentPageNum + ' page(s)');
                        
                        // ========== 步驟 2：併發處理所有帳號連結（使用 --concurrency 控制同時處理數量）==========
                        if (allAccountLinks.length > 0) {
                            const DETAIL_CONCURRENCY = Math.min($concurrency, 4);
                            console.log('🚀 Step 2: Processing all account detail pages (concurrency: ' + DETAIL_CONCURRENCY + ')...');
                            
                            const itemsWithIndex = allAccountLinks.map((accountLink, i) => ({ accountLink, index: i }));
                            const allDetailResults = await promiseAllWithLimit(itemsWithIndex, DETAIL_CONCURRENCY, async (item) => {
                                const { accountLink, index: i } = item;
                                let detailPage = null;
                                try {
                                    console.log('📄 [' + (i + 1) + '/' + allAccountLinks.length + '] Processing account: ' + accountLink.text);
                                    detailPage = await browser.newPage();
                                    await setupPage(detailPage);
                                    const newPage = detailPage;
                                    $cookiesCodeForNewPage
                                    await detailPage.goto(accountLink.href, {
                                        waitUntil: 'domcontentloaded',
                                        timeout: 30000
                                    });
                                    await detailPage.waitForSelector('#simple-table, table', { timeout: 10000 }).catch(() => {});
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    const detailResult = await processAccountLinkDetail(detailPage, accountLink, i, accountLink.text);
                                    console.log('✅ [' + (i + 1) + '/' + allAccountLinks.length + '] Account ' + accountLink.text + ' done');
                                    return detailResult;
                                } catch (error) {
                                    console.error('❌ [' + (i + 1) + '/' + allAccountLinks.length + '] Error: ' + accountLink.text + ': ' + error.message);
                                    return {
                                        linkIndex: i,
                                        accountLink: accountLink,
                                        success: false,
                                        error: error.message,
                                        errorStack: error.stack || ''
                                    };
                                } finally {
                                    if (detailPage && !detailPage.isClosed()) {
                                        try { await detailPage.close(); } catch (e) {}
                                    }
                                }
                            });
                            
                            accountDetailResults = allDetailResults.sort((a, b) => (a.linkIndex || 0) - (b.linkIndex || 0));
                            
                            console.log('✅ All ' + allAccountLinks.length + ' account detail pages processed!');
                            
                            // 處理完所有帳號詳情連結後，直接返回結果
                            const result = {
                                timestamp: new Date().toISOString(),
                                url: '$url',
                                queryParams: {
                                    date_start: dateStartParsed,
                                    date_end: dateEndParsed,
                                    account_number: accountNumberParsed,
                                    platform: platformParsed
                                },
                                domData: {
                                    pageInfo: {
                                        title: 'Account Detail Pages (All Pages)',
                                        url: '$url'
                                    },
                                    queryParams: {
                                        date_start: dateStartParsed,
                                        date_end: dateEndParsed,
                                        account_number: accountNumberParsed,
                                        platform: platformParsed
                                    },
                                    totalPages: currentPageNum,
                                    totalAccountLinks: allAccountLinks.length,
                                    pages: [],
                                    tables: []
                                },
                                accountDetailResults: accountDetailResults,
                                success: true
                            };

                            try {
                                if (page && !page.isClosed && !page.isClosed()) {
                                    await highlightScrapedTable(page);
                                    await page.screenshot({ 
                                        path: 'scraped_page_screenshot.png',
                                        fullPage: false
                                    });
                                }
                            } catch (screenshotError) {
                                console.log('⚠️  Could not take screenshot: ' + screenshotError.message);
                            }

                            fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                            console.log('💾 Results saved to: scraped_result.json');
                            return result;
                        } else {
                            console.log('❌ No account links found in any page');
                        }
                    } catch (e) {
                        console.log('⚠️  Error processing account links: ' + e.message);
                    }


                    // ========== 步驟 1：爬取第一頁，獲取所有分頁 URL ==========
                    console.log('📄 Step 1: Extracting first page and collecting all page URLs...');
                    
                    await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 8000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    const firstPageData = await extractTableData(page);
                    
                    if (!firstPageData.found) {
                        console.log('⚠️  No table found on first page. Page may need more time to load.');
                        const result = {
                            timestamp: new Date().toISOString(),
                            url: '$url',
                            queryParams: { date_start: dateStartParsed, date_end: dateEndParsed, account_number: accountNumberParsed, platform: platformParsed },
                            domData: { pageInfo: { title: '', url: page.url() }, tables: [], totalPages: 0 },
                            accountDetailResults: [],
                            success: false,
                            error: 'No table found on first page. Check URL/date/platform or if the page loaded correctly.'
                        };
                        fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                        console.log('💾 Result (no data) saved to: scraped_result.json');
                        return result;
                    }
                    
                    // 使用"下一頁"按鈕來收集所有頁面的 URL
                    const allPageUrls = [];
                    allPageUrls.push({ pageNumber: 1, url: page.url() });
                    
                    let currentPageNum = 1;
                    let hasNextPage = true;
                    
                    console.log('🔍 Collecting all page URLs...');
                    
                    // 遍歷所有頁面，收集 URL（不提取數據）
                    while (hasNextPage && currentPageNum < 100) { // 限制最多100頁，防止無限循環
                        const nextPageInfo = await page.evaluate(() => {
                            const nextLink = document.querySelector('a[rel="next"]');
                            if (nextLink && nextLink.href) {
                                const style = window.getComputedStyle(nextLink);
                                const isVisible = style.display !== 'none' && 
                                               style.visibility !== 'hidden' && 
                                               style.opacity !== '0' &&
                                               !nextLink.classList.contains('disabled');
                                if (isVisible) {
                                    return {
                                        hasNext: true,
                                        nextUrl: nextLink.href
                                    };
                                }
                            }
                            return { hasNext: false };
                        });
                        
                        if (nextPageInfo.hasNext) {
                            currentPageNum++;
                            allPageUrls.push({ pageNumber: currentPageNum, url: nextPageInfo.nextUrl });
                            
                            // 導航到下一頁繼續收集
                            try {
                                await page.goto(nextPageInfo.nextUrl, {
                                    waitUntil: 'domcontentloaded',
                                    timeout: 30000
                                });
                                await page.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 5000 }).catch(() => {});
                                await new Promise(resolve => setTimeout(resolve, 500));
                            } catch (e) {
                                console.log('⚠️  Failed to navigate to page ' + currentPageNum + ': ' + e.message);
                                hasNextPage = false;
                            }
                        } else {
                            hasNextPage = false;
                        }
                    }
                    
                    console.log('✅ Found total ' + allPageUrls.length + ' page(s)');
                    
                    const paginationInfo = {
                        totalPages: allPageUrls.length,
                        pageLinks: allPageUrls,
                        currentUrl: allPageUrls[0].url
                    };
                    
                    // ========== 步驟 2：並行爬取所有頁面 ==========
                    console.log('🚀 Step 2: Starting concurrent scraping for all pages...');
                    
                    // 定義併發數量
                    const CONCURRENCY_LIMIT = $concurrency;
                    
                    // 準備要爬取的頁面列表（從第 2 頁開始，因為第 1 頁已經爬了）
                    const pagesToScrape = paginationInfo.pageLinks.slice(1); // 跳過第一頁，因為已經爬取了
                    
                    // 並行爬取函數
                    const scrapePage = async (pageInfo, index) => {
                        const newPage = await browser.newPage();
                        
                        try {
                            // 設置頁面基本配置
                            await setupPage(newPage);
                            
                            $cookiesCodeForNewPage
                            
                            // 導航到頁面
                            await newPage.goto(pageInfo.url, {
                                waitUntil: 'domcontentloaded',
                                timeout: 30000
                            });
                            
                            // 等待表格載入
                            await newPage.waitForSelector('#simple-table tbody tr, table tbody tr', { timeout: 8000 }).catch(() => {});
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
                            date_end: dateEndParsed,
                            account_number: accountNumberParsed,
                            platform: platformParsed
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

                    await highlightScrapedTable(page);
                    await page.screenshot({ 
                        path: 'scraped_page_screenshot.png',
                        fullPage: false
                    });

                    console.log('📸 Screenshot saved: scraped_page_screenshot.png');

                    // 合併所有提取的資料（accountDetailResults 已在函數作用域內定義）
                    const result = {
                        timestamp: new Date().toISOString(),
                        url: '$url',
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed,
                            account_number: accountNumberParsed,
                            platform: platformParsed
                        },
                        domData: domData,
                        accountDetailResults: accountDetailResults,
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

        // 讀取腳本生成的結果文件（寫在腳本所在目錄 = storage/app/temp/）
        $resultFile = $workingDir . '/scraped_result.json';

        if (file_exists($resultFile)) {
            $this->info("📄 Result file: " . realpath($resultFile));
            $content = file_get_contents($resultFile);
            $data = json_decode($content, true);
            $copyPath = storage_path('app/scraped_data/scraped_result_' . date('Y-m-d_H-i-s') . '.json');
            $copyDir = dirname($copyPath);
            if (!is_dir($copyDir)) {
                mkdir($copyDir, 0755, true);
            }
            copy($resultFile, $copyPath);
            $this->info("📋 Copy saved to: {$copyPath}");
            return $data;
        }

        $this->error("❌ No result file found at: {$resultFile}");
        return null;
    }
}

