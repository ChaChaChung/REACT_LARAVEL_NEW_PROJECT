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
     * {account_number?} - 要點擊的帳號號碼（可選參數）
     * {date?} - 要選擇的日期（可選參數）
     */
    protected $signature = 'agent:scrape-rsg-dom-detail {url} {account_number?} {date?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from RSG DOM elements using browser automation with detailed information';

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
        $cookiesCode = $this->generateRsgPuppeteerCookiesCode();

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
                // 使用多個 Chrome 參數來優化性能和穩定性
                const browser = await puppeteer.launch({
                    headless: 'new', // 使用新的 headless 模式
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

                    // 點擊 tbody.dataContent 中的 Currency 超連結
                    try {
                        // 尋找 tbody.dataContent 中的超連結
                        const currencyLinkInfo = await page.evaluate(() => {
                            // 初始化 Currency 索引
                            let currencyColumnIndex = -1;
                            // 查找表頭的第一行中的所有單元格
                            const headers = Array.from(document.querySelector('thead').querySelector('tr').querySelectorAll('th, td'));
                            // 找到 Currency 的索引
                            currencyColumnIndex = headers.findIndex(cell => {
                                const text = cell.textContent.trim();
                                return text === 'Currency';
                            });
                            
                            // 查找所有行
                            const rows = Array.from(document.querySelector('tbody.dataContent').querySelectorAll('tr'));
                            // 如果找不到表頭，嘗試從第一行推斷
                            if (currencyColumnIndex === -1 && rows.length > 0) {
                                const firstRowCells = Array.from(rows[0].querySelectorAll('td'));
                                currencyColumnIndex = firstRowCells.findIndex(cell => {
                                    const text = cell.textContent.trim();
                                    return text.includes('Currency');
                                });
                            }
                            
                            // 如果找到 Currency，查找該列中的第一個超連結
                            if (currencyColumnIndex !== -1) {
                                for (const row of rows) {
                                    const cells = Array.from(row.querySelectorAll('td'));
                                    if (cells[currencyColumnIndex]) {
                                        // 優先查找 <u> 標籤（帶有 onclick 的）
                                        const uLink = cells[currencyColumnIndex].querySelector('u[onclick]');
                                        if (uLink) {
                                            return {
                                                found: true,
                                                text: uLink.textContent.trim(),
                                                columnIndex: currencyColumnIndex,
                                                isUTag: true,
                                                onclick: uLink.getAttribute('onclick')
                                            };
                                        }
                                    }
                                }
                            }
                            
                            return { found: false, reason: 'No link found in tbody.dataContent' };
                        });
                        
                        if (currencyLinkInfo.found) {                            
                            // 點擊超連結
                            await page.evaluate((columnIndex, isUTag) => {
                                // 查找所有行
                                const rows = Array.from(document.querySelector('tbody.dataContent').querySelectorAll('tr'));
                                // 判斷如果 Index 不是 -1，則在該列中查找超連結 
                                if (columnIndex !== -1) {
                                    // 所有行資料執行迴圈 
                                    for (const row of rows) {
                                        const cells = Array.from(row.querySelectorAll('td'));
                                        if (cells[columnIndex]) {
                                            // 判斷是否有 <u> 標籤
                                            if (isUTag) {
                                                const uLink = cells[columnIndex].querySelector('u[onclick]');
                                                if (uLink) {
                                                    uLink.click();
                                                    return;
                                                }
                                            }
                                        }
                                    }
                                }
                            }, currencyLinkInfo.columnIndex, currencyLinkInfo.isUTag || false);
                            
                            // 等待額外 3 秒，確保新頁面內容完全載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                            
                            console.log('✅ Navigated to Currency detail page');
                            
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
                                    // 點擊 slim 連結
                                    await page.evaluate(() => {
                                        const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                                        // 所有 u[onclick] 資料執行迴圈 
                                        for (const uLink of allULinks) {
                                            // 從 u[onclick] 中取得 text
                                            const text = uLink.textContent.trim();
                                            // 判斷是否包含 slim，如果包含則點擊
                                            if (text === 'slim') {
                                                uLink.click();
                                                return;
                                            }
                                        }
                                    });
                                    
                                    // 等待額外 3 秒，確保新頁面內容完全載入
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                } else {
                                    console.log('⚠️  Could not find slim link: ' + (slimLinkInfo.reason || 'Unknown reason'));
                                }
                            } catch (e) {
                                console.log('⚠️  Error clicking slim link: ' + e.message);
                                // 即使出錯，也繼續執行後續的 DOM 提取
                            }
                        } else {
                            console.log('⚠️  Could not find Currency link: ' + (currencyLinkInfo.reason || 'Unknown reason'));
                        }

                        // 如果提供了 account_number，查找並點擊該帳號的連結
                        const accountNumber = $accountNumberJs;
                        const date = $dateJs;
                        if (accountNumber && accountNumber !== null && accountNumber !== '') {
                            try {
                                // 尋找包含指定 account number 的 <u onclick> 標籤
                                const accountLinkInfo = await page.evaluate((accountNum) => {
                                    // 尋找所有 <u onclick> 標籤
                                    const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                                    
                                    // 所有 u[onclick] 資料執行迴圈 
                                    for (let i = 0; i < allULinks.length; i++) {
                                        const uLink = allULinks[i];
                                        // 從 u[onclick] 中取得 text
                                        const text = uLink.textContent.trim();
                                        // 判斷是否完全匹配 account number
                                        if (text === accountNum) {
                                            // 為元素添加唯一標識，方便 Puppeteer 選擇
                                            const uniqueId = 'account-link-' + Date.now() + '-' + i;
                                            uLink.setAttribute('data-puppeteer-id', uniqueId);
                                            
                                            return {
                                                found: true,
                                                text: text,
                                                onclick: uLink.getAttribute('onclick'),
                                                selector: 'u[data-puppeteer-id="' + uniqueId + '"]'
                                            };
                                        }
                                    }
                                    return { found: false, reason: 'Account number not found in initial page: ' + accountNum };
                                }, accountNumber);

                                // 判斷是否找到帳號連結
                                if (accountLinkInfo.found) {
                                    // 點擊帳號連結
                                    await page.click(accountLinkInfo.selector, { timeout: 3000 });                                    
                                    // 等待額外 3 秒，確保動態內容完全載入
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                } else {
                                    console.log('⚠️  Could not find account number link: ' + (accountLinkInfo.reason || 'Unknown reason'));
                                }
                            } catch (e) {
                                console.log('⚠️  Error looking for account number: ' + e.message);
                            }
                            
                            // 如果提供了 date，在表格的 Date 列中查找並點擊該日期的連結
                            if (date && date !== null && date !== '') {
                                try {
                                    // 等待頁面完全載入，確保表格已渲染
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                    
                                    // 在表格的 Date 列中尋找包含指定 date 的 <u onclick> 標籤
                                    const dateLinkInfo = await page.evaluate((dateNum) => {
                                        // 尋找所有表格
                                        const allTables = Array.from(document.querySelectorAll('table'));
                                        
                                        // 表格資料執行迴圈
                                        for (const table of allTables) {                                            
                                            // 尋找表頭中的 Date 列索引
                                            const headerRows = Array.from(table.querySelector('thead').querySelectorAll('tr'));
                                            if (headerRows.length === 0) continue;
                                            // 尋找表頭中的所有資料
                                            const headerCells = Array.from(headerRows[0].querySelectorAll('th, td'));
                                            // 尋找表頭中的 Date 列的 Index
                                            const dateColumnIndex = headerCells.findIndex(cell => {
                                                const text = cell.textContent.trim().toLowerCase();
                                                return text === 'date';
                                            });
                                            
                                            // 如果找到 Date 列，在該列中查找日期連結
                                            if (dateColumnIndex !== -1) {
                                                const rows = Array.from(table.querySelector('tbody').querySelectorAll('tr'));
                                                // Table 中的資料執行迴圈
                                                for (let rowIndex = 0; rowIndex < rows.length; rowIndex++) {
                                                    const row = rows[rowIndex];
                                                    const cells = Array.from(row.querySelectorAll('td'));
                                                    
                                                    if (cells[dateColumnIndex]) {
                                                        // 在 Date 列的資料中查找 <u onclick> 標籤
                                                        const uLink = cells[dateColumnIndex].querySelector('u[onclick]');
                                                        // 判斷是否找到 <u onclick> 標籤
                                                        if (uLink) {
                                                            const text = uLink.textContent.trim();
                                                            
                                                            // 判斷是否完全匹配 date
                                                            if (text === dateNum) {
                                                                // 為元素添加唯一標識，方便 Puppeteer 選擇
                                                                const uniqueId = 'date-link-' + Date.now() + '-' + rowIndex;
                                                                uLink.setAttribute('data-puppeteer-id', uniqueId);
                                                                
                                                                return {
                                                                    found: true,
                                                                    text: text,
                                                                    onclick: uLink.getAttribute('onclick'),
                                                                    selector: 'u[data-puppeteer-id="' + uniqueId + '"]',
                                                                    tableIndex: allTables.indexOf(table),
                                                                    rowIndex: rowIndex
                                                                };
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        
                                        return { found: false, reason: 'Date not found in any table: ' + dateNum };
                                    }, date);

                                    // 判斷是否找到日期連結
                                    if (dateLinkInfo.found) {
                                        // 點擊帳號連結
                                        await page.click(accountLinkInfo.selector, { timeout: 3000 });                                    
                                        // 等待額外 3 秒，確保動態內容完全載入
                                        await new Promise(resolve => setTimeout(resolve, 3000));
                                    } else {
                                        console.log('⚠️  Could not find date link: ' + (dateLinkInfo.reason || 'Unknown reason'));
                                    }
                                } catch (e) {
                                    console.log('⚠️  Error looking for date: ' + e.message);
                                }
                            }
                        }
                    } catch (e) {
                        console.log('⚠️  Error clicking Currency link: ' + e.message);
                        // 即使出錯，也繼續執行後續的 DOM 提取
                    }

                    // 處理 reservation 日期選擇器：點擊並選擇「Yesterday」
                    // try {
                    //     // 查找 reservation 欄位（可能是 input、div 或其他元素）
                    //     const reservationField = await page.evaluate(() => {
                    //         // 尋找 reservation 欄位
                    //         const element = document.querySelector('[id*="reservation"]');
                    //         if (element) {
                    //             return {
                    //                 found: true,
                    //                 tagName: element.tagName,
                    //                 id: element.id,
                    //                 name: element.name,
                    //                 className: element.className
                    //             };
                    //         }
                    //         return { found: false };
                    //     });
                        
                    //     if (reservationField.found) {
                    //         // 點擊 reservation 欄位以打開日期選擇器
                    //         await page.evaluate(() => {
                    //             const element = document.querySelector('[id*="reservation"]');
                    //             if (element) {
                    //                 element.click();
                    //                 element.focus();
                    //                 return;
                    //             }
                    //         });
                            
                    //         // 等待日期選擇器出現
                    //         await new Promise(resolve => setTimeout(resolve, 1000));
                            
                    //         // 查找並點擊「Yesterday」選項
                    //         const yesterdayClicked = await page.evaluate(() => {
                    //             // 查找日期選擇器的下拉選單
                    //             let dropdown = document.querySelector('.ranges');
                    //             if (dropdown) {
                    //                 // 優先使用 data-range-key 屬性查找「Yesterday」選項
                    //                 const yesterdayByAttr = dropdown.querySelector('[data-range-key="Yesterday"]');
                    //                 if (yesterdayByAttr) {
                    //                     yesterdayByAttr.click();
                    //                     return { clicked: true, text: yesterdayByAttr.textContent, method: 'data-range-key' };
                    //                 }
                    //             }
                    //             return { clicked: false };
                    //         });
                            
                    //         if (yesterdayClicked.clicked) {
                    //             console.log('✅ Clicked "Yesterday" option: ' + yesterdayClicked.text + ' (method: ' + (yesterdayClicked.method || 'unknown') + ')');
                    //             // 等待日期選擇器關閉
                    //             await new Promise(resolve => setTimeout(resolve, 1000));
                                
                    //             // 檢查是否需要點擊查詢按鈕來觸發資料載入
                    //             console.log('🔍 Checking if query button needs to be clicked...');
                    //             try {
                    //                 const queryButton = await page.evaluate(() => {
                    //                     const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], input[type="button"]'));
                    //                     const queryBtn = buttons.find(btn => {
                    //                         const text = btn.textContent || btn.value || '';
                    //                         return text.includes('查詢') || text.includes('查詢') || btn.type === 'submit';
                    //                     });
                    //                     return queryBtn ? { found: true, text: queryBtn.textContent || queryBtn.value } : { found: false };
                    //                 });
                                    
                    //                 if (queryButton.found) {
                    //                     console.log('📋 Found query button, clicking to load data...');
                    //                     await page.evaluate(() => {
                    //                         const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], input[type="button"]'));
                    //                         const queryBtn = buttons.find(btn => {
                    //                             const text = btn.textContent || btn.value || '';
                    //                             return text.includes('查詢') || text.includes('查詢') || btn.type === 'submit';
                    //                         });
                    //                         if (queryBtn) {
                    //                             queryBtn.click();
                    //                         }
                    //                     });
                    //                     console.log('✅ Clicked query button, waiting for data to load...');
                    //                     // 等待 AJAX 請求完成和表格資料載入
                    //                     await new Promise(resolve => setTimeout(resolve, 5000));
                                        
                    //                     // 等待表格資料出現（最多等待 10 秒）
                    //                     try {
                    //                         await page.waitForFunction(() => {
                    //                             const tables = document.querySelectorAll('table');
                    //                             return Array.from(tables).some(table => {
                    //                                 const rows = table.querySelectorAll('tr');
                    //                                 // 檢查是否有包含實際資料的行（不是表頭）
                    //                                 return Array.from(rows).some((row, idx) => {
                    //                                     if (idx === 0) return false; // 跳過第一行（可能是表頭）
                    //                                     const cells = row.querySelectorAll('td');
                    //                                     if (cells.length === 0) return false;
                    //                                     // 檢查是否有非空且不是表頭文字的單元格
                    //                                     return Array.from(cells).some(cell => {
                    //                                         const text = cell.textContent.trim();
                    //                                         return text !== '' && 
                    //                                                text !== '幣別' && 
                    //                                                text !== '帳號' && 
                    //                                                text !== '下注' &&
                    //                                                text !== '尚未有任何記錄';
                    //                                     });
                    //                                 });
                    //                             });
                    //                         }, { timeout: 10000 });
                    //                         console.log('✅ Table data loaded');
                    //                     } catch (e) {
                    //                         console.log('⚠️  Timeout waiting for table data, continuing anyway...');
                    //                     }
                    //                 } else {
                    //                     console.log('⚠️  No query button found, data may load automatically');
                    //                     // 即使沒有查詢按鈕，也等待一下讓可能的自動載入完成
                    //                     await new Promise(resolve => setTimeout(resolve, 3000));
                    //                 }
                    //             } catch (e) {
                    //                 console.log('⚠️  Error checking for query button: ' + e.message);
                    //                 // 即使出錯，也等待一下
                    //                 await new Promise(resolve => setTimeout(resolve, 3000));
                    //             }
                    //         } else {
                    //             console.log('⚠️  Could not find "Yesterday" option in date picker');
                    //             // 輸出調試信息
                    //             await page.evaluate(() => {
                    //                 const dropdowns = document.querySelectorAll('.dropdown-menu, [class*="dropdown"], [class*="picker"]');
                    //                 console.log('Found ' + dropdowns.length + ' dropdown/picker elements');
                    //                 dropdowns.forEach((dropdown, idx) => {
                    //                     const options = dropdown.querySelectorAll('a, button, li');
                    //                     console.log('Dropdown ' + idx + ' has ' + options.length + ' options');
                    //                     options.forEach((opt, optIdx) => {
                    //                         if (optIdx < 10) { // 只輸出前10個
                    //                             console.log('  Option ' + optIdx + ': ' + (opt.textContent || opt.innerText || '').trim());
                    //                         }
                    //                     });
                    //                 });
                    //             });
                    //         }
                    //     }
                    // } catch (e) {
                    //     console.log('⚠️  Error handling reservation field: ' + e.message);
                    // }

                    console.log('📄 Extracting DOM content...');

                    // 使用 page.evaluate() 在瀏覽器環境中執行 JavaScript 來提取 DOM 資料
                    const accountNumberForFilter = $accountNumberJs;
                    const domData = await page.evaluate((accountNumberProvided) => {
                        // 先處理表格資料
                        const allTables = Array.from(document.querySelectorAll('table'));
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
                                // 原始格式（保留以備用）
                                rawRows: rows.slice(dataStartIndex).map(row => 
                                    Array.from(row.querySelectorAll('td')).map(cell => cell.textContent.trim())
                                ),
                                // 結構化資料（推薦使用）
                                data: dataRows
                            };
                        }
                        
                        const result = {
                            // 基本頁面信息
                            pageInfo: {
                                title: document.title,
                                url: window.location.href,
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
                                    // 如果提供了 account_number，只保留最後一個表格
                                    if (processedTables.length > 0) {
                                        return [processedTables[processedTables.length - 1]];
                                    }
                                    return [];
                                } else {
                                    // 否則，只保留包含 "Account number" 的表格
                                    return processedTables.filter(table => {
                                        const hasAccountNumber = table.headers.some(header => {
                                            const headerText = header.toLowerCase();
                                            return headerText.includes('account number');
                                        });
                                        return hasAccountNumber;
                                    });
                                }
                            })()
                        };
                        
                        return result;
                    }, accountNumberForFilter && accountNumberForFilter !== null && accountNumberForFilter !== '');

                    // 截圖（用於調試和驗證）
                    // fullPage: true 表示截取整個頁面，而不只是可見區域
                    await page.screenshot({ 
                        path: 'scraped_page_screenshot.png',
                        fullPage: true 
                    });

                    console.log('📸 Screenshot saved: scraped_page_screenshot.png');

                    // 合併所有提取的資料和捕獲的 DOM 資料
                    const result = {
                        timestamp: new Date().toISOString(),  // 時間戳
                        url: '$url',  // 目標 URL
                        domData: domData,  // 捕獲的 DOM 資料
                        success: true  // 成功標記
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
        $result = Process::path($workingDir)->run("node " . basename($scriptPath));

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

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存完整結果為 JSON 文件
        $fullResultPath = storage_path("app/scraped_data/dom_scrape_full_{$timestamp}.json");
        Storage::put("scraped_data/dom_scrape_full_{$timestamp}.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 保存表格資料（包含原始和結構化格式）
        if (!empty($domData['tables'])) {
            // 保存完整表格信息（包含原始和結構化資料）
            Storage::put("scraped_data/dom_tables_{$timestamp}.json", json_encode($domData['tables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 為每個表格單獨保存結構化資料（推薦使用）
            foreach ($domData['tables'] as $tableIndex => $table) {
                if (!empty($table['data'])) {
                    $tableFileName = "scraped_data/dom_table_{$tableIndex}_structured_{$timestamp}.json";
                    Storage::put($tableFileName, json_encode([
                        'tableIndex' => $table['tableIndex'],
                        'headers' => $table['headers'],
                        'rowCount' => $table['rowCount'],
                        'data' => $table['data']
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->info("   📋 Table {$tableIndex} structured data saved");
                }
            }
            $this->info("📊 Tables saved separately");
        }

        $this->info("💾 Full results saved to: {$fullResultPath}");

        // 將截圖從臨時目錄移動到永久存儲目錄
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/dom_screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }
        
        $this->info("✅ Data processing completed!");
    }
}

