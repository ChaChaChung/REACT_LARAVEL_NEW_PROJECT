<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserFoqqDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-dom {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {account_number?} - 要點擊的帳號號碼（可選參數）
     * {date?} - 要選擇的日期（可選參數）
     */
    protected $signature = 'agent:scrape-foqq-dom-detail {url} {account_number?} {date?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from FoqQ DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $accountNumber = $this->argument('account_number');
        $date = $this->argument('date');

        $this->info('=== Browser DOM Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Account Number: {$accountNumber}");
        $this->info("Date: {$date}");

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $accountNumber, $date);

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
     * @param string|null $accountNumber 要點擊的帳號號碼（可選）
     * @param string|null $date 要選擇的日期（可選）
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $accountNumber = null, $date = null)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段
        $cookiesCode = $this->generateFoqqPuppeteerCookiesCode();

        // 將 account_number 轉換為 JavaScript 可用的格式
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';

        // 將 date 轉換為 JavaScript 可用的格式
        $dateJs = $date ? json_encode(date('Y-m-d', strtotime($date))) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容
             */
            async function scrapeDOMContent() {
                console.log('🚀 Starting browser automation for DOM scraping...');

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

                    $cookiesCode

                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            console.log('❌ Browser console error:', msg.text());
                        }
                    });

                    console.log('🌐 Navigating to:', '$url');

                    // 導航到目標頁面
                    // waitUntil: 'networkidle2' 表示等待網路空閒（沒有超過 2 個網路連接）時才繼續
                    // timeout: 30000 設定 30 秒超時
                    await page.goto('$url', {
                        waitUntil: 'networkidle2',
                        timeout: 30000
                    });

                    // 等待額外 5 秒，確保動態內容完全載入
                    await new Promise(resolve => setTimeout(resolve, 5000));

                    // 解析 account_number 和 date
                    let accountNumberParsed = null;
                    let dateParsed = null;
                    
                    try {
                        if ($accountNumberJs && $accountNumberJs !== 'null' && $accountNumberJs !== '') {
                            accountNumberParsed = JSON.parse($accountNumberJs);
                        }
                    } catch (e) {
                        accountNumberParsed = $accountNumberJs !== 'null' ? $accountNumberJs : null;
                    }

                    // 如果提供了 account_number，填入 input#find4 並點擊搜尋按鈕
                    if (accountNumberParsed && accountNumberParsed !== null && accountNumberParsed !== '') {
                        try {
                            // 查找 input#find4 欄位
                            await page.waitForSelector('#find4', { timeout: 10000 });
                            
                            // 清空並填入 account number
                            await page.evaluate((accountNum) => {
                                const input = document.querySelector('#find4');
                                if (input) {
                                    input.value = '';
                                    input.value = accountNum;
                                    // 觸發 input 事件，確保頁面知道值已改變
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }, accountNumberParsed);

                            // 等待一下讓輸入完成
                            await new Promise(resolve => setTimeout(resolve, 1000));

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
                                
                                // 等待頁面載入和表格更新
                                await new Promise(resolve => setTimeout(resolve, 5000));
                            }
                        } catch (e) {
                            console.log('⚠️  Error filling account number: ' + e.message);
                        }
                    }
                    
                    try {
                        if ($dateJs && $dateJs !== 'null' && $dateJs !== '') {
                            dateParsed = JSON.parse($dateJs);
                        }
                    } catch (e) {
                        dateParsed = $dateJs !== 'null' ? $dateJs : null;
                    }

                    // 如果提供了 date，填入 input#find1 和 input#find2
                    if (dateParsed && dateParsed !== null && dateParsed !== '') {
                        try {
                            // 查找 input#find1 和 input#find2 欄位
                            await page.waitForSelector('#find1', { timeout: 10000 });
                            await page.waitForSelector('#find2', { timeout: 10000 });
                            
                            // 清空並填入日期到兩個欄位
                            await page.evaluate((dateValue) => {
                                const input1 = document.querySelector('#find1');
                                const input2 = document.querySelector('#find2');
                                
                                if (input1) {
                                    input1.value = '';
                                    input1.value = dateValue;
                                    input1.dispatchEvent(new Event('input', { bubbles: true }));
                                    input1.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                                
                                if (input2) {
                                    input2.value = '';
                                    input2.value = dateValue;
                                    input2.dispatchEvent(new Event('input', { bubbles: true }));
                                    input2.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            }, dateParsed);

                            // 等待一下讓輸入完成
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        } catch (e) {
                            console.log('⚠️  Error filling date: ' + e.message);
                        }
                    }

                    // 提取表格資料的函數
                    const extractTableData = async () => {
                        return await page.evaluate(() => {
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
                            const dataRows = rows.slice(dataStartIndex).map((row, rowIndex) => {
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
                            });

                            return {
                                found: true,
                                tableId: table.id || null,
                                tableClass: table.className || null,
                                headers: headers,
                                headerCount: headers.length,
                                rowCount: dataRows.length,
                                rawRows: rows.slice(dataStartIndex).map(row => 
                                    Array.from(row.querySelectorAll('td')).map(cell => cell.textContent.trim())
                                ),
                                data: dataRows
                            };
                        });
                    };

                    // 提取表格資料
                    const tableData = await extractTableData();

                    if (!tableData.found) {
                        throw new Error(tableData.error || 'Failed to extract table data');
                    }

                    // 獲取當前頁面信息
                    const pageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });

                    // 構建結果數據結構
                    const domData = {
                        pageInfo: pageInfo,
                        queryParams: {
                            accountNumber: accountNumberParsed,
                            date: dateParsed
                        },
                        totalPages: 1,
                        pages: [{
                            pageNumber: 1,
                            tables: [tableData]
                        }],
                        tables: [tableData]
                    };

                    // 截圖（用於調試和驗證）
                    await page.screenshot({ 
                        path: 'scraped_page_screenshot.png',
                        fullPage: true 
                    });

                    console.log('📸 Screenshot saved: scraped_page_screenshot.png');

                    // 查找並點擊所有帳號超連結
                    console.log('🔍 Looking for account links in the table...');
                    const accountLinks = await page.evaluate(() => {
                        const table = document.querySelector('#simple-table');
                        if (!table) return [];
                        
                        // 查找所有包含 game_report_details 的 <a> 標籤
                        const links = Array.from(table.querySelectorAll('a[href*="game_report_details"]'));
                        return links.map((link, index) => {
                            const accountText = link.textContent.trim();
                            const href = link.getAttribute('href');
                            return {
                                index: index,
                                account: accountText,
                                href: href
                            };
                        });
                    });

                    console.log('📋 Found ' + accountLinks.length + ' account link(s)');

                    // 點擊每個連結並截圖
                    const linkScreenshots = [];
                    for (let i = 0; i < accountLinks.length; i++) {
                        const linkInfo = accountLinks[i];
                        console.log('🔗 Clicking link ' + (i + 1) + '/' + accountLinks.length + ': ' + linkInfo.account);
                        
                        try {
                            // 記錄當前頁面數量
                            const pagesBefore = (await browser.pages()).length;

                            // 使用 evaluateHandle 獲取連結元素並點擊
                            const linkElement = await page.evaluateHandle((href, accountText) => {
                                const table = document.querySelector('#simple-table');
                                if (!table) return null;
                                
                                const links = Array.from(table.querySelectorAll('a[href*="game_report_details"]'));
                                const link = links.find(l => 
                                    l.getAttribute('href') === href && 
                                    l.textContent.trim() === accountText
                                );
                                return link;
                            }, linkInfo.href, linkInfo.account);

                            if (!linkElement) {
                                throw new Error('Link element not found');
                            }

                            // 點擊連結（會在新標籤頁打開，因為 target="_blank"）
                            await linkElement.click();
                            linkElement.dispose();

                            // 等待新頁面打開 - 使用輪詢方式檢查頁面數量
                            let newPage = null;
                            const maxWaitTime = 10000; // 10秒超時
                            const checkInterval = 100; // 每100ms檢查一次
                            const startTime = Date.now();
                            
                            while (!newPage && (Date.now() - startTime) < maxWaitTime) {
                                const pages = await browser.pages();
                                if (pages.length > pagesBefore) {
                                    // 找到新頁面（最後一個打開的頁面）
                                    newPage = pages[pages.length - 1];
                                    // 確保不是當前頁面
                                    if (newPage === page) {
                                        newPage = null;
                                    }
                                }
                                if (!newPage) {
                                    await new Promise(resolve => setTimeout(resolve, checkInterval));
                                }
                            }

                            if (!newPage || newPage === page) {
                                throw new Error('New page did not open within timeout');
                            }

                            // 等待新頁面載入完成
                            try {
                                await newPage.waitForNavigation({ 
                                    waitUntil: 'networkidle2',
                                    timeout: 30000 
                                });
                            } catch (navError) {
                                // 如果導航超時，繼續執行
                                console.log('⚠️  Navigation timeout, continuing...');
                            }

                            // 額外等待確保內容載入
                            await new Promise(resolve => setTimeout(resolve, 3000));

                            // 截圖新頁面
                            const accountSafeName = linkInfo.account.replace(/[^a-zA-Z0-9]/g, '_');
                            const screenshotPath = 'account_link_' + (i + 1) + '_' + accountSafeName + '_screenshot.png';
                            await newPage.screenshot({ 
                                path: screenshotPath,
                                fullPage: true 
                            });

                            console.log('📸 Screenshot saved: ' + screenshotPath);

                            const newPageUrl = await newPage.url();
                            linkScreenshots.push({
                                account: linkInfo.account,
                                href: linkInfo.href,
                                screenshot: screenshotPath,
                                url: newPageUrl
                            });

                            // 關閉新標籤頁
                            await newPage.close();

                            // 切換回原頁面
                            await page.bringToFront();
                            
                            // 等待一下再處理下一個連結
                            await new Promise(resolve => setTimeout(resolve, 1000));

                        } catch (error) {
                            console.log('⚠️  Error clicking link ' + (i + 1) + ': ' + error.message);
                            linkScreenshots.push({
                                account: linkInfo.account,
                                href: linkInfo.href,
                                error: error.message
                            });
                        }
                    }

                    // 將連結截圖信息添加到結果中
                    domData.accountLinkScreenshots = linkScreenshots;

                    // 合併所有提取的資料
                    const result = {
                        timestamp: new Date().toISOString(),
                        url: '$url',
                        queryParams: {
                            accountNumber: accountNumberParsed,
                            date: dateParsed
                        },
                        domData: domData,
                        success: true
                    };

                    // 將結果保存為 JSON 文件
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');
                    console.log('📊 Table rows extracted:', tableData.rowCount);

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
        // 增加超時時間到 10 分鐘（600秒），因為需要爬取多頁數據
        $result = Process::path($workingDir)->timeout(600)->run("node " . basename($scriptPath));

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
                    'totalPages' => $domData['totalPages'] ?? 1,
                    'pagesCollected' => $domData['pages'] ?? [],
                    'totalRows' => $totalRows,
                    'totalTablesMerged' => count($domData['tables'])
                ],
                'table' => [
                    'headers' => $headers,
                    'headerCount' => count($headers),
                    'rowCount' => $totalRows,
                    'data' => $allData  // 所有頁面的數據都在這裡
                ]
            ];
            
            // 保存合併後的數據到單一 JSON 文件
            $mergedFileName = "scraped_data/dom_merged_all_pages_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $tablesData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                    'queryParams' => $queryParams,
                ],
                'tables' => $domData['tables']
            ];
            
            // 可選：也保存完整表格信息（包含每個表格的詳細信息）
            Storage::put("scraped_data/dom_tables_{$timestamp}.json", json_encode($tablesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // 將截圖從臨時目錄移動到永久存儲目錄
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/dom_screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }

        // 處理帳號連結的截圖
        $accountLinkScreenshots = $domData['accountLinkScreenshots'] ?? [];
        if (!empty($accountLinkScreenshots)) {
            $this->info("📸 Processing " . count($accountLinkScreenshots) . " account link screenshot(s)...");
            
            foreach ($accountLinkScreenshots as $index => $linkInfo) {
                if (!empty($linkInfo['screenshot'])) {
                    $screenshotFileName = basename($linkInfo['screenshot']);
                    $screenshotSrc = storage_path('app/temp/' . $screenshotFileName);
                    $accountSafeName = preg_replace('/[^a-zA-Z0-9]/', '_', $linkInfo['account'] ?? 'unknown');
                    $linkIndex = $index + 1;
                    $screenshotDst = storage_path("app/scraped_data/account_link_{$linkIndex}_{$accountSafeName}_{$timestamp}.png");
                    
                    if (file_exists($screenshotSrc)) {
                        rename($screenshotSrc, $screenshotDst);
                        $this->info("📸 Account link screenshot saved: {$screenshotDst}");
                    }
                } elseif (!empty($linkInfo['error'])) {
                    $this->warn("⚠️  Failed to screenshot account link {$linkInfo['account']}: {$linkInfo['error']}");
                }
            }
        }
        
        $this->info("✅ Data processing completed!");
    }
}

