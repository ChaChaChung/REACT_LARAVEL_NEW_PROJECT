<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - OMG
 */
class ScrapeBrowserOMGDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     */
    protected $signature = 'agent:scrape-omg-dom-detail {url} {date_start?} {date_end?} {account_number?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from OMG DOM elements using browser automation with detailed information';

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
        $concurrency = $this->option('concurrency');

        $this->info('=== OMG DOM Data Scraper (LocalStorage Login) ===');
        $this->info("Target URL: {$url}");
        $this->info("Start Date: {$date_start}");
        $this->info("End Date: {$date_end}");
        $this->info("Account Number: {$account_number}");
        $this->info("Concurrency: {$concurrency}");

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $date_start, $date_end, $concurrency, $account_number);

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

        $result = Process::run('node --version');

        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            return false;
        }

        $this->info('✅ Node.js found: ' . trim($result->output()));

        $puppeteerCheck = Process::run('npm list puppeteer --depth=0');

        if ($puppeteerCheck->failed()) {
            $this->warn('⚠️  Puppeteer not found. Installing...');
            $install = Process::run('npm install puppeteer');

            if ($install->failed()) {
                $this->error('❌ Failed to install Puppeteer');
                return false;
            }

            $this->info('✅ Puppeteer installed successfully');
        } else {
            $this->info('✅ Puppeteer found');
        }

        return true;
    }

    /**
     * 創建 Puppeteer 自動化腳本（使用 localStorage 登入）
     */
    private function createPuppeteerScript($url, $dateStart = null, $dateEnd = null, $concurrency = 4, $accountNumber = null)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取 OMG localStorage 登入程式碼片段
        $omgLoginCode = $this->generateOmgPuppeteerLoginInfoCode('page');

        // 轉義 JavaScript 字符串
        $urlJs = json_encode($url);
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';
        $concurrencyJs = json_encode($concurrency);

        $workingDir = storage_path('app/scraped_data');
        $workingDirJs = json_encode($workingDir);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            
            const workingDir = $workingDirJs;

            /**
             * OMG 使用 localStorage 登入流程
             */
            async function loginWithLocalStorageAndScreenshot() {
                const browser = await puppeteer.launch({
                    headless: 'new',
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--no-zygote',
                        '--single-process',
                        '--disable-gpu',
                    ],
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

                    console.log('🔐 Starting OMG localStorage login process...');
                    
                    // 使用 trait 中的方法設置 localStorage
                    $omgLoginCode
                    
                    const targetUrl = $urlJs.replace(/^"|"\$/g, '');
                    
                    // 這裡可以加入具體的爬蟲邏輯，與 Wow 類似的 extractTableData 等
                    // 為簡化，這裡暫時保留核心登入邏輯，並做簡單截圖
                    
                    console.log('🌐 Navigating to target URL: ' + targetUrl);
                    await page.goto(targetUrl, {
                        waitUntil: 'networkidle2',
                        timeout: 60000
                    });
                     
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    /**
                     * 提取 vxe-table 表格數據的函數（類似 PGONE 的 extractTableData）
                     */
                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            try {
                                // vxe-table 結構：
                                // .vxe-table--header-wrapper (表頭)
                                // .vxe-table--body-wrapper (表體)
                                
                                let headers = [];
                                let data = [];
                                
                                // 方式1：查找 vxe-table 表頭
                                let headerCells = Array.from(document.querySelectorAll('.vxe-table--header-wrapper th .vxe-cell, .vxe-header--column .vxe-cell'));
                                
                                // 方式2：如果找不到，嘗試查找標準 thead
                                if (headerCells.length === 0) {
                                    headerCells = Array.from(document.querySelectorAll('thead th'));
                                }
                                
                                // 方式3：如果還是找不到，查找任何包含 vxe 相關類的元素
                                if (headerCells.length === 0) {
                                    const vxeTable = document.querySelector('.vxe-table, [class*="vxe-table"]');
                                    if (vxeTable) {
                                        headerCells = Array.from(vxeTable.querySelectorAll('th .vxe-cell, th'));
                                    }
                                }
                                
                                if (headerCells.length > 0) {
                                    headers = headerCells.map(cell => {
                                        const text = cell.innerText || cell.textContent || '';
                                        return text.trim();
                                    }).filter(t => t); // 過濾空值
                                } else {
                                    return {
                                        found: false,
                                        error: 'No headers found',
                                        debug: {
                                            vxeTableElements: document.querySelectorAll('.vxe-table, [class*="vxe-table"]').length,
                                            headerWrappers: document.querySelectorAll('.vxe-table--header-wrapper').length,
                                            theadElements: document.querySelectorAll('thead').length
                                        }
                                    };
                                }
                                
                                // 提取資料行
                                // 方式1：查找 vxe-table 表體行
                                let rows = Array.from(document.querySelectorAll('.vxe-table--body-wrapper .vxe-body--row'));
                                
                                // 方式2：如果找不到，嘗試標準 tr
                                if (rows.length === 0) {
                                    rows = Array.from(document.querySelectorAll('.vxe-table--body-wrapper tr'));
                                }
                                
                                // 方式3：如果還是找不到，查找任何包含 vxe 相關類的表格行
                                if (rows.length === 0) {
                                    const vxeTable = document.querySelector('.vxe-table, [class*="vxe-table"]');
                                    if (vxeTable) {
                                        rows = Array.from(vxeTable.querySelectorAll('tbody tr, .vxe-body--row'));
                                    }
                                }
                                
                                // 將資料行轉換為對象數組
                                const dataRows = rows.map((row, rowIndex) => {
                                    const rowData = {};
                                    
                                    // 查找單元格
                                    let cells = Array.from(row.querySelectorAll('.vxe-body--column'));
                                    if (cells.length === 0) {
                                        cells = Array.from(row.querySelectorAll('td'));
                                    }
                                    
                                    if (headers && headers.length > 0) {
                                        headers.forEach((header, colIndex) => {
                                            // 清理字段名（類似 PGONE）
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
                                            
                                            // 提取單元格內容
                                            let cellValue = null;
                                            if (cells[colIndex]) {
                                                const cell = cells[colIndex];
                                                // vxe-table 的內容通常在 .vxe-cell 中
                                                const contentDiv = cell.querySelector('.vxe-cell');
                                                if (contentDiv) {
                                                    cellValue = contentDiv.innerText.trim();
                                                } else {
                                                    cellValue = cell.innerText.trim();
                                                }
                                            }
                                            
                                            rowData[finalHeader] = cellValue;
                                        });
                                    } else {
                                        // 如果沒有表頭，使用索引作為 key
                                        cells.forEach((cell, colIndex) => {
                                            const contentDiv = cell.querySelector('.vxe-cell');
                                            let cellValue = null;
                                            if (contentDiv) {
                                                cellValue = contentDiv.innerText.trim();
                                            } else {
                                                cellValue = cell ? cell.innerText.trim() : null;
                                            }
                                            rowData['column_' + colIndex] = cellValue;
                                        });
                                    }
                                    
                                    // 添加原始行索引
                                    rowData._rowIndex = rowIndex;
                                    
                                    return rowData;
                                }).filter(rowData => {
                                    // 過濾掉小計和總計行（類似 PGONE）
                                    const firstValue = Object.values(rowData)[0];
                                    return firstValue !== '小計' && firstValue !== '總計' && firstValue !== 'Subtotal' && firstValue !== 'Total';
                                });
                                
                                return {
                                    found: true,
                                    headers: headers,
                                    headerCount: headers.length,
                                    rowCount: dataRows.length,
                                    data: dataRows
                                };
                            } catch (e) {
                                return {
                                    found: false,
                                    error: e.toString()
                                };
                            }
                        });
                    };

                    // DEBUG: Click language button
                    try {
                        console.log('👆 Debug: Clicking Language Button (#radix-vue-dropdown-menu-trigger-v-3)...');
                        await page.waitForSelector('#radix-vue-dropdown-menu-trigger-v-3', { timeout: 5000 });
                        await page.click('#radix-vue-dropdown-menu-trigger-v-3');
                        await new Promise(r => setTimeout(r, 1000));
                        await page.screenshot({ path: path.join(workingDir, 'omg_debug_language_clicked.png'), fullPage: true });
                        console.log('📸 Debug screenshot saved: omg_debug_language_clicked.png');
                        
                        // Click English menu item
                        await new Promise(r => setTimeout(r, 500));
                        console.log('👆 Debug: Clicking English menu item...');
                        const englishClicked = await page.evaluate(() => {
                            const items = Array.from(document.querySelectorAll('div[role="menuitem"]'));
                            const englishItem = items.find(el => el.textContent.trim().includes('English'));
                            
                            if (englishItem) {
                                englishItem.click();
                                return true;
                            }
                            return false;
                        });
                        
                        if (englishClicked) {
                            console.log('✅ Debug: Clicked English menu item');
                            console.log('⏳ Waiting 5s for language switch...');
                            await new Promise(r => setTimeout(r, 5000)); // Wait for language switch
                            await page.screenshot({ path: path.join(workingDir, 'omg_debug_english_selected.png'), fullPage: true });
                        } else {
                            console.log('⚠️ Debug: English menu item not found');
                        }
                    } catch (e) {
                        console.error('❌ Debug language button click failed:', e.message);
                    }
                    
                    // 解析日期參數
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    try {
                        dateStartParsed = $dateStartJs !== 'null' ? $dateStartJs.replace(/^"|"\$/g, '') : null;
                        dateEndParsed = $dateEndJs !== 'null' ? $dateEndJs.replace(/^"|"\$/g, '') : null;
                    } catch (e) {
                         console.error('Date parsing error', e);
                    }

                    // 填入日期
                    if (dateStartParsed || dateEndParsed) {
                         // DEBUG: Click date input, find specific date, and screenshot
                         try {
                             console.log('👆 Debug: Clicking Start Date input...');
                             
                             // 使用 ID 或 Placeholder 尋找，因為 ID "v-16" 可能是動態生成的
                             const startDateSelector = '#v-16-form-item, input[placeholder="Start date"]';
                             
                             try {
                                 await page.waitForSelector(startDateSelector, { timeout: 5000 });
                                 await page.click(startDateSelector);
                                 console.log('✅ Start Date input clicked');
                             } catch (clickErr) {
                                 console.error('⚠️ Failed to click Start Date input (' + clickErr.message + '), taking screenshot anyway...');
                             }
                             
                             await new Promise(r => setTimeout(r, 1000)); // Wait for picker (if any)

                             // Screenshot after clicking input (User Request)
                             // 無論點擊是否成功都截圖，以便除錯
                             await page.screenshot({ path: path.join(workingDir, 'omg_debug_start_date_clicked.png'), fullPage: true });
                             console.log('📸 Debug screenshot saved: omg_debug_start_date_clicked.png');
                             
                             if (dateStartParsed) {
                                 console.log('🔍 Debug: Looking for element with date: ' + dateStartParsed);
                                 const clickedDate = await page.evaluate((dateStr) => {
                                     // 嘗試查找 Ant Design 或其他常見 UI 庫的日期單元格
                                     // AntD 通常使用 title="YYYY-MM-DD"
                                     let el = document.querySelector(`td[title="\${dateStr}"]`);
                                     
                                     // 如果找不到，嘗試 aria-label
                                     if (!el) {
                                          el = document.querySelector(`td[aria-label="\${dateStr}"]`);
                                     }
                                     
                                     // 嘗試查找包含該日期的 div 或 span (精確匹配內容)
                                     if (!el) {
                                         // 假設 dateStr 是 YYYY-MM-DD，取出 Day 部分
                                         const day = parseInt(dateStr.split('-')[2], 10).toString();
                                         // 查找所有可能的日期單元格
                                         const cells = Array.from(document.querySelectorAll('.ant-picker-cell-inner, .el-date-table__cell'));
                                         el = cells.find(c => c.textContent.trim() === day);
                                     }
                                     
                                     if (el) {
                                         el.click();
                                         return true;
                                     }
                                     return false;
                                 }, dateStartParsed);
                                 
                                 if (clickedDate) {
                                     console.log('✅ Debug: Found and clicked date element for ' + dateStartParsed);
                                     
                                     // Screenshot after choosing date (before OK)
                                     await new Promise(r => setTimeout(r, 500));
                                     await page.screenshot({ path: path.join(workingDir, 'omg_debug_start_date_chosen.png'), fullPage: true });
                                     console.log('📸 Debug screenshot saved: omg_debug_start_date_chosen.png');
                                     
                                     // Click OK button
                                     await new Promise(r => setTimeout(r, 500));
                                     const okClicked = await page.evaluate(() => {
                                         const buttons = Array.from(document.querySelectorAll('button'));
                                         const okBtn = buttons.find(b => 
                                             b.textContent.trim() === 'Ok' || 
                                             b.querySelector('span')?.textContent.trim() === 'Ok'
                                         );
                                         if (okBtn) {
                                             okBtn.click();
                                             return true;
                                         }
                                         return false;
                                     });
                                     
                                     if (okClicked) {
                                         console.log('✅ Debug: Clicked OK button');
                                     } else {
                                         console.log('⚠️ Debug: OK button not found');
                                     }
                                 } else {
                                     console.log('⚠️ Debug: Specific date element not found for ' + dateStartParsed);
                                 }
                             }
                             
                             await new Promise(r => setTimeout(r, 1000));
                             await page.screenshot({ path: path.join(workingDir, 'omg_debug_start_date_confirmed.png'), fullPage: true });
                             console.log('📸 Debug screenshot saved: omg_debug_start_date_confirmed.png');
                             
                             // --- End Date Handling ---
                             if (dateEndParsed) {
                                 console.log('👆 Debug: Clicking End Date input...');
                                 const endDateSelector = 'input[placeholder="End date"]';
                                 
                                 try {
                                     await page.waitForSelector(endDateSelector, { timeout: 5000 });
                                     await page.click(endDateSelector);
                                     console.log('✅ End Date input clicked');
                                     
                                     // Screenshot after clicking End Date input
                                     await new Promise(r => setTimeout(r, 1000));
                                     await page.screenshot({ path: path.join(workingDir, 'omg_debug_end_date_clicked.png'), fullPage: true });
                                     console.log('📸 Debug screenshot saved: omg_debug_end_date_clicked.png');
                                     
                                     // Find and click End Date
                                     console.log('🔍 Debug: Looking for element with date: ' + dateEndParsed);
                                     const clickedEnd = await page.evaluate((dateStr) => {
                                         // Same logic as Start Date
                                         let el = document.querySelector(`td[title="\${dateStr}"]`);
                                         if (!el) el = document.querySelector(`td[aria-label="\${dateStr}"]`);
                                         if (!el) {
                                             const day = parseInt(dateStr.split('-')[2], 10).toString();
                                             const cells = Array.from(document.querySelectorAll('.ant-picker-cell-inner, .el-date-table__cell'));
                                             el = cells.find(c => c.textContent.trim() === day);
                                         }
                                         
                                         if (el) {
                                             el.click();
                                             return true;
                                         }
                                         return false;
                                     }, dateEndParsed);
                                     
                                     if (clickedEnd) {
                                         console.log('✅ Debug: Found and clicked End Date element');
                                         
                                         // Screenshot after choosing End Date (before OK)
                                         await new Promise(r => setTimeout(r, 500));
                                         await page.screenshot({ path: path.join(workingDir, 'omg_debug_end_date_chosen.png'), fullPage: true });
                                         console.log('📸 Debug screenshot saved: omg_debug_end_date_chosen.png');
                                         
                                         // Click OK button (re-use logic)
                                         await new Promise(r => setTimeout(r, 500));
                                         const okClicked = await page.evaluate(() => {
                                             const buttons = Array.from(document.querySelectorAll('button'));
                                             const okBtn = buttons.find(b => 
                                                 b.textContent.trim() === 'Ok' || 
                                                 b.querySelector('span')?.textContent.trim() === 'Ok'
                                             );
                                             if (okBtn) {
                                                 okBtn.click();
                                                 return true;
                                             }
                                             return false;
                                         });
                                         
                                         if (okClicked) console.log('✅ Debug: Clicked OK button for End Date');
                                         
                                         await new Promise(r => setTimeout(r, 1000));
                                         await page.screenshot({ path: path.join(workingDir, 'omg_debug_end_date_confirmed.png'), fullPage: true });
                                         console.log('📸 Debug screenshot saved: omg_debug_end_date_confirmed.png');
                                         
                                     } else {
                                         console.log('⚠️ Debug: End Date element not found');
                                     }
                                     
                                 } catch (err) {
                                     console.error('❌ Error handling End Date:', err.message);
                                 }
                             }
                             
                             // --- Platform ID Dropdown Handling ---
                             console.log('👆 Debug: Clicking Platform ID dropdown...');
                             try {
                                 // Use XPath to find the span with title "Platform ID" and get its parent .ant-select-selector
                                 // This is more robust than evaluate click
                                 const platformDropdown = await page.waitForFunction(() => {
                                     const span = document.querySelector('span[title="Platform ID"]');
                                     return span ? span.closest('.ant-select-selector') : null;
                                 }, { timeout: 5000 });
                                 
                                 if (platformDropdown) {
                                     await platformDropdown.click();
                                     console.log('✅ Platform ID dropdown clicked (native)');
                                 } else {
                                     console.log('⚠️ Platform ID dropdown selector not found');
                                 }
                             } catch (err) {
                                 console.error('❌ Failed to click Platform ID dropdown:', err.message);
                             }
                             
                             await new Promise(r => setTimeout(r, 1000));
                             await page.screenshot({ path: path.join(workingDir, 'omg_debug_platform_id_clicked.png'), fullPage: true });
                             console.log('📸 Debug screenshot saved: omg_debug_platform_id_clicked.png');
                             
                             // Select "Player ID"
                             console.log('👆 Debug: Selecting "Player ID"...');
                             const playerIdSelected = await page.evaluate(() => {
                                 // Ant Design dropdown items usually have these classes
                                 const items = Array.from(document.querySelectorAll('.ant-select-item-option-content, .ant-select-item-option'));
                                 const target = items.find(el => el.textContent.trim() === 'Player ID');
                                 if (target) {
                                     target.click();
                                     return true;
                                 }
                                 return false;
                             });
                             
                             if (playerIdSelected) {
                                 console.log('✅ "Player ID" selected');
                             } else {
                                 console.log('⚠️ "Player ID" option not found');
                             }
                             
                             await new Promise(r => setTimeout(r, 1000));
                             await page.screenshot({ path: path.join(workingDir, 'omg_debug_player_id_selected.png'), fullPage: true });
                             console.log('📸 Debug screenshot saved: omg_debug_player_id_selected.png');
                             
                             // Fill Player ID
                             const accountNumber = $accountNumberJs;
                             if (accountNumber && accountNumber !== 'null') {
                                 console.log('👆 Debug: Filling Player ID with: ' + accountNumber);
                                 try {
                                     const playerInputSelector = 'input[placeholder="Please input Player ID"]';
                                     await page.waitForSelector(playerInputSelector, { timeout: 5000 });
                                     
                                     // Clear input and type
                                     await page.click(playerInputSelector, { clickCount: 3 });
                                     await page.keyboard.press('Backspace');
                                     await page.type(playerInputSelector, accountNumber.replace(/^"|"\$/g, ''));
                                     console.log('✅ Player ID filled');
                                     
                                     await new Promise(r => setTimeout(r, 1000));
                                     await page.screenshot({ path: path.join(workingDir, 'omg_debug_player_id_filled.png'), fullPage: true });
                                     console.log('📸 Debug screenshot saved: omg_debug_player_id_filled.png');
                                     
                                     // Click Search Button (Native)
                                     console.log('👆 Debug: Clicking Search button (native)...');
                                     try {
                                         const searchButton = await page.waitForFunction(() => {
                                             const buttons = Array.from(document.querySelectorAll('button'));
                                             return buttons.find(b => 
                                                 b.textContent.trim() === 'Search' || 
                                                 b.querySelector('span')?.textContent.trim() === 'Search'
                                             );
                                         }, { timeout: 5000 });
                                         
                                         if (searchButton) {
                                             await searchButton.click();
                                             console.log('✅ Search button clicked (native)');
                                         } else {
                                             console.log('⚠️ Search button element not found');
                                         }
                                     } catch (err) {
                                         console.error('❌ Failed to click Search button:', err.message);
                                     }
                                     
                                     // Wait for query/rendering (Smart Wait)
                                     console.log('⏳ Waiting for loading to finish (max 60s)...');
                                     try {
                                        // Wait for spinner to appear and then disappear, or just wait for table rows
                                        // Strategy: Wait 2s, then wait for .ant-spin-spinning to be GONE
                                        await new Promise(r => setTimeout(r, 2000));
                                        
                                        await page.waitForFunction(() => {
                                            return !document.querySelector('.ant-spin-spinning');
                                        }, { timeout: 60000 });
                                        
                                        console.log('✅ Loading spinner disappeared');
                                     } catch (e) {
                                         console.log('⚠️ Wait for loading finish timed out or failed, proceeding to screenshot...');
                                     }
                                     
                                     await page.screenshot({ path: path.join(workingDir, 'omg_debug_after_fill_wait.png'), fullPage: true });
                                     console.log('📸 Debug screenshot saved: omg_debug_after_fill_wait.png');
                                     
                                     // --- Scrape Table Data ---
                                     console.log('📊 Scraping vxe-table data...');
                                     const tableData = await extractTableData(page);
                                     
                                     if (!tableData.found) {
                                         const errorMsg = tableData.error || 'No table found';
                                         console.error('❌ ' + errorMsg);
                                         if (tableData.debug) {
                                             console.error('📋 Debug info:', JSON.stringify(tableData.debug, null, 2));
                                         }
                                         
                                         // 重試一次（類似 PGONE）
                                         console.log('⏳ Waiting and retrying...');
                                         await new Promise(resolve => setTimeout(resolve, 2000));
                                         await page.evaluate(() => {
                                             window.scrollTo(0, document.body.scrollHeight);
                                         });
                                         await new Promise(resolve => setTimeout(resolve, 500));
                                         await page.evaluate(() => {
                                             window.scrollTo(0, 0);
                                         });
                                         await new Promise(resolve => setTimeout(resolve, 500));
                                         
                                         const retryData = await extractTableData(page);
                                         if (!retryData.found) {
                                             console.error('❌ Retry also failed: ' + (retryData.error || 'Unknown error'));
                                         } else {
                                             console.log('✅ Table found on retry!');
                                             Object.assign(tableData, retryData);
                                         }
                                     } else {
                                         console.log('✅ Scraped ' + (tableData.rowCount || 0) + ' rows.');
                                         console.log('✅ Found ' + (tableData.headerCount || 0) + ' headers.');
                                     }
                                     
                                     // Save to file (包含 headers 和 data)
                                     const dataPath = path.join(workingDir, 'omg_scraped_data.json');
                                     fs.writeFileSync(dataPath, JSON.stringify(tableData, null, 2));
                                     console.log('💾 Data saved to: ' + dataPath);
                                     

                                     
                                 } catch (err) {
                                     console.error('❌ Failed to fill Player ID:', err.message);
                                 }
                             } else {
                                 console.log('⚠️ No account number provided to fill');
                                 
                                 // 即使沒有 accountNumber，也嘗試點擊搜索按鈕並爬取數據
                                 console.log('👆 Debug: Clicking Search button (without Player ID)...');
                                 try {
                                     const searchButton = await page.waitForFunction(() => {
                                         const buttons = Array.from(document.querySelectorAll('button'));
                                         return buttons.find(b => 
                                             b.textContent.trim() === 'Search' || 
                                             b.querySelector('span')?.textContent.trim() === 'Search'
                                         );
                                     }, { timeout: 5000 });
                                     
                                     if (searchButton) {
                                         await searchButton.click();
                                         console.log('✅ Search button clicked (native)');
                                     } else {
                                         console.log('⚠️ Search button element not found');
                                     }
                                 } catch (err) {
                                     console.error('❌ Failed to click Search button:', err.message);
                                 }
                                 
                                 // Wait for query/rendering
                                 console.log('⏳ Waiting for loading to finish (max 60s)...');
                                 try {
                                    await new Promise(r => setTimeout(r, 2000));
                                    
                                    await page.waitForFunction(() => {
                                        return !document.querySelector('.ant-spin-spinning');
                                    }, { timeout: 60000 });
                                    
                                    console.log('✅ Loading spinner disappeared');
                                 } catch (e) {
                                     console.log('⚠️ Wait for loading finish timed out or failed, proceeding...');
                                 }
                                 
                                 await page.screenshot({ path: path.join(workingDir, 'omg_debug_after_search_wait.png'), fullPage: true });
                                 console.log('📸 Debug screenshot saved: omg_debug_after_search_wait.png');
                                 
                                 // --- Scrape Table Data (even without account number) ---
                                 console.log('📊 Scraping vxe-table data...');
                                 const tableData = await extractTableData(page);
                                 
                                 if (!tableData.found) {
                                     const errorMsg = tableData.error || 'No table found';
                                     console.error('❌ ' + errorMsg);
                                     if (tableData.debug) {
                                         console.error('📋 Debug info:', JSON.stringify(tableData.debug, null, 2));
                                     }
                                     
                                     // 重試一次
                                     console.log('⏳ Waiting and retrying...');
                                     await new Promise(resolve => setTimeout(resolve, 2000));
                                     await page.evaluate(() => {
                                         window.scrollTo(0, document.body.scrollHeight);
                                     });
                                     await new Promise(resolve => setTimeout(resolve, 500));
                                     await page.evaluate(() => {
                                         window.scrollTo(0, 0);
                                     });
                                     await new Promise(resolve => setTimeout(resolve, 500));
                                     
                                     const retryData = await extractTableData(page);
                                     if (!retryData.found) {
                                         console.error('❌ Retry also failed: ' + (retryData.error || 'Unknown error'));
                                     } else {
                                         console.log('✅ Table found on retry!');
                                         Object.assign(tableData, retryData);
                                     }
                                 } else {
                                     console.log('✅ Scraped ' + (tableData.rowCount || 0) + ' rows.');
                                     console.log('✅ Found ' + (tableData.headerCount || 0) + ' headers.');
                                 }
                                 
                                 // Save to file (包含 headers 和 data)
                                 const dataPath = path.join(workingDir, 'omg_scraped_data.json');
                                 fs.writeFileSync(dataPath, JSON.stringify(tableData, null, 2));
                                 console.log('💾 Data saved to: ' + dataPath);
                             }
                             


                         } catch (e) {
                             console.error('❌ Debug interaction failed:', e.message);
                         }
                    }

                    
                    // 截圖
                    const screenshotPath = path.join(workingDir, 'omg_scraped_result.png');
                    await page.screenshot({ path: screenshotPath, fullPage: true });
                    console.log('📸 Screenshot saved to: ' + screenshotPath);
                    
                    // 讀取爬取的數據（如果有的話）
                    let scrapedHeaders = [];
                    let scrapedData = [];
                    const dataPath = path.join(workingDir, 'omg_scraped_data.json');
                    try {
                        if (fs.existsSync(dataPath)) {
                            const dataContent = fs.readFileSync(dataPath, 'utf8');
                            const parsedData = JSON.parse(dataContent);
                            
                            // 檢查數據格式：可能是新格式 {headers, data} 或舊格式 [data]
                            if (parsedData && parsedData.headers && parsedData.data) {
                                scrapedHeaders = parsedData.headers;
                                scrapedData = parsedData.data;
                                console.log('✅ Loaded scraped data: ' + scrapedData.length + ' rows, ' + scrapedHeaders.length + ' headers');
                            } else if (Array.isArray(parsedData)) {
                                // 舊格式：只有數據數組，需要重新爬取表頭
                                scrapedData = parsedData;
                                console.log('⚠️ Old format detected, attempting to scrape headers...');
                                const tableData = await page.evaluate(() => {
                                    try {
                                        let headers = [];
                                        let headerCells = Array.from(document.querySelectorAll('.vxe-table--header-wrapper th .vxe-cell, .vxe-header--column .vxe-cell'));
                                        if (headerCells.length === 0) {
                                            headerCells = Array.from(document.querySelectorAll('thead th'));
                                        }
                                        if (headerCells.length > 0) {
                                            headers = headerCells.map(cell => cell.innerText.trim()).filter(t => t);
                                        }
                                        return { headers: headers, data: [] };
                                    } catch (e) {
                                        return { error: e.toString() };
                                    }
                                });
                                if (tableData && tableData.headers) {
                                    scrapedHeaders = tableData.headers;
                                }
                            } else {
                                console.log('⚠️ Unknown data format');
                            }
                        } else {
                            console.log('⚠️ No scraped data file found, attempting to scrape now...');
                            // 如果沒有數據文件，嘗試現在爬取
                            const tableData = await page.evaluate(() => {
                                try {
                                    let headers = [];
                                    let data = [];
                                    
                                    // 嘗試查找表頭
                                    let headerCells = Array.from(document.querySelectorAll('.vxe-table--header-wrapper th .vxe-cell, .vxe-header--column .vxe-cell'));
                                    
                                    if (headerCells.length === 0) {
                                        headerCells = Array.from(document.querySelectorAll('thead th'));
                                    }
                                    
                                    if (headerCells.length > 0) {
                                        headers = headerCells.map(cell => cell.innerText.trim()).filter(t => t);
                                        console.log('Found headers (' + headers.length + '):', headers);
                                    } else {
                                        console.log('⚠️ No headers found!');
                                        return { error: 'No headers found' };
                                    }
                                    
                                    // 查找表格行
                                    let rows = Array.from(document.querySelectorAll('.vxe-table--body-wrapper .vxe-body--row'));
                                    
                                    if (rows.length === 0) {
                                        rows = Array.from(document.querySelectorAll('.vxe-table--body-wrapper tr'));
                                    }
                                    
                                    console.log('Found rows: ' + rows.length);
                                    
                                    data = rows.map(row => {
                                        let rowObj = {};
                                        let cells = Array.from(row.querySelectorAll('.vxe-body--column'));
                                        if (cells.length === 0) {
                                            cells = Array.from(row.querySelectorAll('td'));
                                        }
                                        
                                        headers.forEach((header, index) => {
                                            const cell = cells[index];
                                            if (cell) {
                                                const contentDiv = cell.querySelector('.vxe-cell');
                                                rowObj[header] = contentDiv ? contentDiv.innerText.trim() : cell.innerText.trim();
                                            } else {
                                                rowObj[header] = '';
                                            }
                                        });
                                        return rowObj;
                                    });
                                    
                                    return { headers: headers, data: data };
                                } catch (e) {
                                    return { error: e.toString() };
                                }
                            });
                            
                            if (tableData && !tableData.error && tableData.headers && tableData.data) {
                                scrapedHeaders = tableData.headers;
                                scrapedData = tableData.data;
                                // 保存數據（新格式）
                                fs.writeFileSync(dataPath, JSON.stringify(tableData, null, 2));
                                console.log('💾 Data saved to: ' + dataPath);
                            } else if (tableData && tableData.error) {
                                console.error('❌ Scraping error:', tableData.error);
                            }
                        }
                    } catch (err) {
                        console.error('❌ Error reading scraped data:', err.message);
                    }
                    
                    // 構建查詢參數對象（使用已存在的變量）
                    // targetUrl、dateStartParsed、dateEndParsed 已在函數開始處聲明，這裡直接使用
                    // 只需要解析 accountNumber
                    const accountNumberParsed = $accountNumberJs !== 'null' ? $accountNumberJs.replace(/^"|"\$/g, '') : null;
                    
                    // 獲取當前頁面信息（類似 PGONE）
                    const pageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });
                    
                    // 構建表格數據結構（類似 PGONE）
                    const allTables = [];
                    let totalDataRows = 0;
                    
                    // 如果有爬取的數據，構建表格對象
                    if (scrapedHeaders.length > 0 && scrapedData.length > 0) {
                        const tableData = {
                            found: true,
                            headers: scrapedHeaders,
                            headerCount: scrapedHeaders.length,
                            rowCount: scrapedData.length,
                            data: scrapedData
                        };
                        allTables.push(tableData);
                        totalDataRows += scrapedData.length;
                    }
                    
                    // 構建 domData 結構（類似 PGONE）
                    const domData = {
                        pageInfo: pageInfo,
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed,
                            account_number: accountNumberParsed
                        },
                        totalPages: 1,
                        pages: [{
                            pageNumber: 1,
                            tables: allTables
                        }],
                        tables: allTables
                    };
                    
                    // 計算總資料筆數
                    let totalRows = 0;
                    allTables.forEach(table => {
                        totalRows += table.rowCount || 0;
                    });
                    
                    // 構建最終結果（類似 PGONE）
                    const result = {
                        timestamp: new Date().toISOString(),
                        url: targetUrl,
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed,
                            account_number: accountNumberParsed
                        },
                        domData: domData,
                        success: true
                    };
                    
                    // 將結果保存為 JSON 文件（類似 PGONE）
                    const resultPath = path.join(workingDir, 'scrape_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: ' + resultPath);
                    
                    return result;
                    
                } catch (error) {
                    console.error('❌ Error in Puppeteer script:', error);
                    return { success: false, error: error.message };
                } finally {
                    await browser.close();
                }
            }
            
            loginWithLocalStorageAndScreenshot().then(result => {
                console.log(JSON.stringify(result));
            }).catch(err => {
                console.error(err);
                process.exit(1);
            });
        JS;

        // 確保目錄存在
        if (!file_exists($workingDir)) {
            mkdir($workingDir, 0755, true);
        }

        $scriptPath = $workingDir . '/scrape_omg_' . time() . '.js';
        file_put_contents($scriptPath, $script);

        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本（類似 PGONE）
     * @param string $scriptPath Puppeteer 腳本文件路徑
     * @return array|null 返回解析後的結果資料，失敗時返回 null
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation...');

        // 獲取腳本所在目錄，並將工作目錄切換到該目錄（類似 PGONE）
        $workingDir = dirname($scriptPath);

        // 在指定目錄執行 Node.js 腳本（類似 PGONE）
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

        // 讀取腳本生成的結果文件（類似 PGONE）
        $resultFile = $workingDir . '/scrape_result.json';

        if (file_exists($resultFile)) {
            // 讀取並解析 JSON 文件
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }

        $this->error("❌ No result file found");
        return null;
    }

    /**
     * 處理和保存爬取的資料（類似 PGONE）
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        // 檢查爬取是否成功
        if (!$result || !isset($result['success']) || !$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }

        // 提取 DOM 資料（類似 PGONE）
        $domData = $result['domData'] ?? [];
        
        // 獲取查詢參數
        $queryParams = $result['queryParams'] ?? [];

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存合併後的表格資料到單一 JSON 文件（主要輸出文件）（類似 PGONE）
        $allData = [];
        $totalRows = 0;
        $headers = [];
        
        // 優先從 pages 中提取數據（因為數據是按頁面組織的）（類似 PGONE）
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
        
        // 如果 pages 為空，嘗試從 tables 中提取（類似 PGONE）
        if (empty($allData) && !empty($domData['tables'])) {
            foreach ($domData['tables'] as $table) {
                if (!empty($table['data'])) {
                    $allData = array_merge($allData, $table['data']);
                    $totalRows += count($table['data']);
                    
                    if (empty($headers) && !empty($table['headers'])) {
                        $headers = $table['headers'];
                    }
                }
            }
        }
        
        $this->info('📊 Total rows extracted: ' . $totalRows);

        // 初始化合併後的檔案名稱
        $mergedFileName = null;
        
        // 如果有資料，保存合併後的資料（類似 PGONE）
        if (!empty($allData)) {
            // 創建合併後的數據結構（類似 PGONE）
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
            
            // 保存合併後的資料到單一 JSON 檔案（類似 PGONE）
            $mergedFileName = "scraped_data/scraped_data_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $this->info("✅ Data saved successfully!");
            $this->info("📁 File: {$mergedFileName}");
            $this->info("📊 Total rows: {$totalRows}");
            $this->info("📋 Headers: " . count($headers));
        }

        if (!$mergedFileName) {
            $this->warn("⚠️ No data to save.");
        }

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}
