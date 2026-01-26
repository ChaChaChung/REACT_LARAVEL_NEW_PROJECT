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
                                // .vxe-table--header-wrapper (表頭) - 只用於提取 headers
                                // .vxe-table--body-wrapper (表體) - 用於提取資料行
                                // vxe-table 可能有多個區域（左固定、中間滾動、右固定）
                                
                                let headers = [];
                                let data = [];
                                
                                // ========== 1. 提取表頭 ==========
                                // 優先從 body wrapper 中的第一個表格提取 headers（更準確）
                                const bodyWrapper = document.querySelector('.vxe-table--body-wrapper');
                                let headerCells = [];
                                
                                if (bodyWrapper) {
                                    // 從 body wrapper 的表格中提取表頭（如果有的話）
                                    const bodyTable = bodyWrapper.querySelector('table');
                                    if (bodyTable) {
                                        const thead = bodyTable.querySelector('thead');
                                        if (thead) {
                                            headerCells = Array.from(thead.querySelectorAll('th')).map(th => {
                                                const vxeCell = th.querySelector('.vxe-cell');
                                                return vxeCell || th;
                                            });
                                        }
                                    }
                                }
                                
                                // 如果 body wrapper 沒有表頭，從 header wrapper 提取
                                if (headerCells.length === 0) {
                                    const headerWrapper = document.querySelector('.vxe-table--header-wrapper');
                                    if (headerWrapper) {
                                        // 只從第一個 header wrapper 提取（避免重複）
                                        headerCells = Array.from(headerWrapper.querySelectorAll('th .vxe-cell'));
                                        if (headerCells.length === 0) {
                                            headerCells = Array.from(headerWrapper.querySelectorAll('th'));
                                        }
                                    }
                                }
                                
                                // 備用：從任何 thead 提取
                                if (headerCells.length === 0) {
                                    const firstThead = document.querySelector('thead');
                                    if (firstThead) {
                                        headerCells = Array.from(firstThead.querySelectorAll('th')).map(th => {
                                            const vxeCell = th.querySelector('.vxe-cell');
                                            return vxeCell || th;
                                        });
                                    }
                                }
                                
                                if (headerCells.length > 0) {
                                    // 提取表頭文本並去重
                                    const rawHeaders = headerCells.map(cell => {
                                        const text = cell.innerText || cell.textContent || '';
                                        return text.trim();
                                    }).filter(t => t); // 過濾空值
                                    
                                    // 去重 headers（保留順序，只保留第一次出現的）
                                    const seenHeaders = new Set();
                                    headers = rawHeaders.filter(header => {
                                        if (seenHeaders.has(header)) {
                                            return false;
                                        }
                                        seenHeaders.add(header);
                                        return true;
                                    });
                                    
                                }
                                
                                // ========== 2. 提取資料行 ==========
                                let rows = [];
                                
                                // 方式1：優先從 .vxe-table--body-wrapper 提取（這才是資料區域）
                                if (bodyWrapper) {
                                    rows = Array.from(bodyWrapper.querySelectorAll('.vxe-body--row'));
                                    if (rows.length === 0) {
                                        rows = Array.from(bodyWrapper.querySelectorAll('tbody tr'));
                                    }
                                    if (rows.length === 0) {
                                        rows = Array.from(bodyWrapper.querySelectorAll('table tr')).filter(tr => !tr.querySelector('th'));
                                    }
                                }
                                
                                if (rows.length === 0) {
                                    const vxeTable = document.querySelector('.vxe-table, [class*="vxe-table"]');
                                    if (vxeTable) {
                                        rows = Array.from(vxeTable.querySelectorAll('.vxe-body--row'));
                                    }
                                }
                                
                                if (rows.length === 0) {
                                    const bodyTable = document.querySelector('table.vxe-table--body, table[class*="vxe-table--body"]');
                                    if (bodyTable) {
                                        rows = Array.from(bodyTable.querySelectorAll('tbody tr'));
                                    }
                                }
                                
                                if (rows.length === 0) {
                                    const allTables = Array.from(document.querySelectorAll('table'));
                                    for (const table of allTables) {
                                        if (table.className && table.className.includes('header')) continue;
                                        const tbody = table.querySelector('tbody');
                                        if (tbody) {
                                            const trs = Array.from(tbody.querySelectorAll('tr')).filter(tr => !tr.querySelector('th'));
                                            if (trs.length > 0) {
                                                rows = trs;
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // ========== 3. 檢測固定列區域 ==========
                                const leftWrapper = document.querySelector('.vxe-table--fixed-left-wrapper');
                                const rightWrapper = document.querySelector('.vxe-table--fixed-right-wrapper');
                                
                                // 從固定列區域提取行
                                let leftRows = [];
                                let rightRows = [];
                                
                                if (leftWrapper) {
                                    leftRows = Array.from(leftWrapper.querySelectorAll('.vxe-body--row'));
                                    if (leftRows.length === 0) {
                                        leftRows = Array.from(leftWrapper.querySelectorAll('tbody tr')).filter(tr => !tr.querySelector('th'));
                                    }
                                }
                                
                                if (rightWrapper) {
                                    rightRows = Array.from(rightWrapper.querySelectorAll('.vxe-body--row'));
                                    if (rightRows.length === 0) {
                                        rightRows = Array.from(rightWrapper.querySelectorAll('tbody tr')).filter(tr => !tr.querySelector('th'));
                                    }
                                }
                                
                                // 提取單元格內容的輔助函數
                                const extractCellText = (cell) => {
                                    if (!cell) return '';
                                    const vxeCell = cell.querySelector('.vxe-cell');
                                    if (vxeCell) {
                                        return (vxeCell.innerText || vxeCell.textContent || '').trim();
                                    }
                                    if (cell.classList && cell.classList.contains('vxe-cell')) {
                                        return (cell.innerText || cell.textContent || '').trim();
                                    }
                                    return (cell.innerText || cell.textContent || '').trim();
                                };
                                
                                // 從行中提取 cells 的輔助函數
                                const extractCellsFromRow = (row) => {
                                    if (!row) return [];
                                    let cells = Array.from(row.querySelectorAll('.vxe-body--column'));
                                    if (cells.length === 0) {
                                        cells = Array.from(row.querySelectorAll('td'));
                                    }
                                    return cells;
                                };
                                
                                // 計算左側和右側固定列的數量
                                const leftCellCount = leftRows.length > 0 ? extractCellsFromRow(leftRows[0]).length : 0;
                                const rightCellCount = rightRows.length > 0 ? extractCellsFromRow(rightRows[0]).length : 0;
                                
                                // ========== 4. 提取單元格資料（用固定列的值替換空值）==========
                                const dataRows = rows.map((row, rowIndex) => {
                                    const rowData = {};
                                    
                                    // 從中間區域提取 cells（這是主要資料，bodyCells 包含所有欄位）
                                    const bodyCells = extractCellsFromRow(row);
                                    
                                    // 從左側固定列提取 cells
                                    const leftCells = leftRows[rowIndex] ? extractCellsFromRow(leftRows[rowIndex]) : [];
                                    
                                    // 從右側固定列提取 cells
                                    const rightCells = rightRows[rowIndex] ? extractCellsFromRow(rightRows[rowIndex]) : [];
                                    
                                    if (headers && headers.length > 0) {
                                        // 使用 headers 作為 key
                                        headers.forEach((header, index) => {
                                            // 先從 bodyCells 取值（直接按 index 對應）
                                            let cellValue = bodyCells[index] ? extractCellText(bodyCells[index]) : '';
                                            
                                            // 如果是左側固定列的位置，且值為空或 bodyCells 數量不足，從左側固定列覆蓋
                                            if (index < leftCellCount && leftCells[index]) {
                                                const leftValue = extractCellText(leftCells[index]);
                                                if (leftValue || !cellValue) {
                                                    cellValue = leftValue;
                                                }
                                            }
                                            
                                            // 如果是右側固定列的位置，且值為空或需要覆蓋，從右側固定列覆蓋
                                            if (index >= headers.length - rightCellCount && rightCellCount > 0) {
                                                const rightIndex = index - (headers.length - rightCellCount);
                                                if (rightCells[rightIndex]) {
                                                    const rightValue = extractCellText(rightCells[rightIndex]);
                                                    if (rightValue || !cellValue) {
                                                        cellValue = rightValue;
                                                    }
                                                }
                                            }
                                            
                                            rowData[header] = cellValue;
                                        });
                                    } else {
                                        // 如果沒有表頭，使用索引作為 key
                                        bodyCells.forEach((cell, colIndex) => {
                                            rowData['column_' + colIndex] = extractCellText(cell);
                                        });
                                    }
                                    
                                    // 添加原始行索引
                                    rowData._rowIndex = rowIndex;
                                    
                                    return rowData;
                                }).filter(rowData => {
                                    // 過濾掉空行、小計和總計行
                                    if (!rowData) return false;
                                    
                                    // 過濾掉 header 行（值完全匹配 headers 的數據行）
                                    if (headers && headers.length > 0) {
                                        const rowValues = Object.entries(rowData)
                                            .filter(([key]) => key !== '_rowIndex')
                                            .map(([, value]) => value ? String(value).trim() : '');
                                        
                                        if (rowValues.length === headers.length) {
                                            let isHeaderRow = true;
                                            for (let i = 0; i < headers.length; i++) {
                                                const header = String(headers[i]).trim();
                                                const rowValue = rowValues[i] || '';
                                                if (rowValue !== header) {
                                                    isHeaderRow = false;
                                                    break;
                                                }
                                            }
                                            if (isHeaderRow) {
                                                return false;
                                            }
                                        }
                                    }
                                    
                                    // 排除 _rowIndex 字段，只檢查實際數據值
                                    const values = Object.entries(rowData)
                                        .filter(([key]) => key !== '_rowIndex')
                                        .map(([, value]) => value)
                                        .filter(v => v !== null && v !== undefined && v !== '');
                                    if (values.length === 0) return false;
                                    const firstValue = values[0];
                                    return firstValue !== '小計' && firstValue !== '總計' && firstValue !== 'Subtotal' && firstValue !== 'Total';
                                });
                                
                                if (dataRows.length === 0) {
                                    return {
                                        found: false,
                                        error: 'No data found'
                                    };
                                }
                                
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

                    // Click language button to switch to English
                    try {
                        await page.waitForSelector('#radix-vue-dropdown-menu-trigger-v-3', { timeout: 5000 });
                        await page.click('#radix-vue-dropdown-menu-trigger-v-3');
                        await new Promise(r => setTimeout(r, 1000));
                        
                        await new Promise(r => setTimeout(r, 500));
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
                            await new Promise(r => setTimeout(r, 5000));
                        }
                    } catch (e) {
                        // Language switch failed, continue with current language
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
                    
                    // 全域變數用於保存爬取結果
                    let globalScrapedData = [];
                    let globalScrapedHeaders = [];
                    let globalPaginationInfo = null;
                    let globalAllPagesData = [];

                    // 填入日期
                    if (dateStartParsed || dateEndParsed) {
                         try {
                             const startDateSelector = '#v-16-form-item, input[placeholder="Start date"]';
                             
                             try {
                                 await page.waitForSelector(startDateSelector, { timeout: 5000 });
                                 await page.click(startDateSelector);
                             } catch (clickErr) {
                                 // Start date click failed
                             }
                             
                             await new Promise(r => setTimeout(r, 1000));
                             
                             if (dateStartParsed) {
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
                                     await new Promise(r => setTimeout(r, 500));
                                     await page.evaluate(() => {
                                         const buttons = Array.from(document.querySelectorAll('button'));
                                         const okBtn = buttons.find(b => 
                                             b.textContent.trim() === 'Ok' || 
                                             b.querySelector('span')?.textContent.trim() === 'Ok'
                                         );
                                         if (okBtn) okBtn.click();
                                     });
                                 }
                             }
                             
                             await new Promise(r => setTimeout(r, 1000));
                             
                             if (dateEndParsed) {
                                 const endDateSelector = 'input[placeholder="End date"]';
                                 try {
                                     await page.waitForSelector(endDateSelector, { timeout: 5000 });
                                     await page.click(endDateSelector);
                                     await new Promise(r => setTimeout(r, 1000));
                                     
                                     const clickedEnd = await page.evaluate((dateStr) => {
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
                                         await new Promise(r => setTimeout(r, 500));
                                         await page.evaluate(() => {
                                             const buttons = Array.from(document.querySelectorAll('button'));
                                             const okBtn = buttons.find(b => 
                                                 b.textContent.trim() === 'Ok' || 
                                                 b.querySelector('span')?.textContent.trim() === 'Ok'
                                             );
                                             if (okBtn) okBtn.click();
                                         });
                                         await new Promise(r => setTimeout(r, 1000));
                                     }
                                 } catch (err) {
                                     // End date handling failed
                                 }
                             }
                             
                             // Platform ID Dropdown
                             try {
                                 const platformDropdown = await page.waitForFunction(() => {
                                     const span = document.querySelector('span[title="Platform ID"]');
                                     return span ? span.closest('.ant-select-selector') : null;
                                 }, { timeout: 5000 });
                                 if (platformDropdown) await platformDropdown.click();
                             } catch (err) {
                                 // Platform dropdown click failed
                             }
                             
                             await new Promise(r => setTimeout(r, 1000));
                             
                             // Select "Player ID"
                             await page.evaluate(() => {
                                 const items = Array.from(document.querySelectorAll('.ant-select-item-option-content, .ant-select-item-option'));
                                 const target = items.find(el => el.textContent.trim() === 'Player ID');
                                 if (target) target.click();
                             });
                             
                             await new Promise(r => setTimeout(r, 1000));
                             
                             // Fill Player ID
                             const accountNumber = $accountNumberJs;
                             if (accountNumber && accountNumber !== 'null') {
                                 try {
                                     const playerInputSelector = 'input[placeholder="Please input Player ID"]';
                                     await page.waitForSelector(playerInputSelector, { timeout: 5000 });
                                     await page.click(playerInputSelector, { clickCount: 3 });
                                     await page.keyboard.press('Backspace');
                                     await page.type(playerInputSelector, accountNumber.replace(/^"|"\$/g, ''));
                                     
                                     await new Promise(r => setTimeout(r, 1000));
                                     
                                     // Click Search Button
                                     try {
                                         const searchButton = await page.waitForFunction(() => {
                                             const buttons = Array.from(document.querySelectorAll('button'));
                                             return buttons.find(b => 
                                                 b.textContent.trim() === 'Search' || 
                                                 b.querySelector('span')?.textContent.trim() === 'Search'
                                             );
                                         }, { timeout: 5000 });
                                         if (searchButton) await searchButton.click();
                                     } catch (err) {
                                         // Search button click failed
                                     }
                                     
                                    // Wait for loading to finish
                                    try {
                                       // 策略：等待表格出現且有數據，同時檢查 loading 狀態
                                       await new Promise(r => setTimeout(r, 2000)); // 先等 2 秒讓請求發送
                                       
                                       // 等待表格數據出現（更可靠的指標）
                                       await page.waitForFunction(() => {
                                           // 檢查多種 loading 指示器
                                           const spinners = [
                                               '.ant-spin-spinning',
                                               '.ant-spin',
                                               '[class*="loading"]',
                                               '[class*="spinner"]',
                                               '.vxe-loading'
                                           ];
                                           const hasLoading = spinners.some(selector => {
                                               const el = document.querySelector(selector);
                                               return el && (el.offsetParent !== null || window.getComputedStyle(el).display !== 'none');
                                           });
                                           
                                           // 檢查表格是否出現且有數據
                                           const hasTable = document.querySelector('table[class*="vxe-table--header"], table.vxe-table--header, .vxe-table, [class*="vxe-table"]');
                                           const hasData = hasTable && (
                                               hasTable.querySelectorAll('tbody tr').length > 0 ||
                                               hasTable.querySelectorAll('.vxe-table--body-wrapper tr').length > 0 ||
                                               hasTable.querySelectorAll('tr').length > 1 // 至少有表頭和一行數據
                                           );
                                           
                                           // 如果沒有 loading 且表格有數據，則完成
                                           return !hasLoading && hasData;
                                       }, { 
                                           timeout: 60000,
                                           polling: 500 // 每 500ms 檢查一次
                                       });
                                       
                                       console.log('✅ Loading finished and table data appeared');
                                    } catch (e) {
                                        console.log('⚠️ Wait for loading finish timed out, checking if table exists anyway...');
                                        // 即使超時，也檢查表格是否存在
                                        const tableExists = await page.evaluate(() => {
                                            return !!document.querySelector('table[class*="vxe-table--header"], table.vxe-table--header, .vxe-table, [class*="vxe-table"]');
                                        });
                                        if (tableExists) {
                                            console.log('✅ Table exists, proceeding...');
                                        } else {
                                            console.log('⚠️ Table not found, but proceeding anyway...');
                                        }
                                    }
                                    
                                    // 額外等待一下，確保數據完全渲染
                                    await new Promise(r => setTimeout(r, 1000));
                                    
                                    // 獲取分頁資訊
                                    const paginationInfo = await page.evaluate(() => {
                                        let totalPages = 1;
                                        let totalRecords = 0;
                                        
                                        const totalSpan = document.querySelector('.vxe-pager--total');
                                        if (totalSpan) {
                                            const totalText = totalSpan.textContent || totalSpan.innerText || '';
                                            let totalMatch = totalText.match(/total\s+(\d+)\s+records?/i);
                                            if (totalMatch && totalMatch[1]) {
                                                totalRecords = parseInt(totalMatch[1]);
                                            } else {
                                                totalMatch = totalText.match(/共\s*(\d+)\s*条记录/i);
                                                if (totalMatch && totalMatch[1]) {
                                                    totalRecords = parseInt(totalMatch[1]);
                                                } else {
                                                    const numberMatch = totalText.match(/(\d+)/);
                                                    if (numberMatch && numberMatch[1]) {
                                                        totalRecords = parseInt(numberMatch[1]);
                                                    }
                                                }
                                            }
                                        }
                                        
                                        const pageButtons = document.querySelectorAll('.vxe-pager--num-btn');
                                        let maxPageNum = 1;
                                        if (pageButtons.length > 0) {
                                            pageButtons.forEach(btn => {
                                                const text = btn.textContent.trim();
                                                const pageNum = parseInt(text);
                                                if (!isNaN(pageNum) && pageNum > 0 && pageNum <= 10000 && pageNum > maxPageNum) {
                                                    maxPageNum = pageNum;
                                                }
                                            });
                                        }
                                        
                                        let perPage = 50;
                                        const sizeSelector = document.querySelector('.vxe-pager--sizes-select, .vxe-pager--sizes select, .vxe-select');
                                        if (sizeSelector) {
                                            const sizeText = sizeSelector.textContent || sizeSelector.value || '';
                                            const sizeMatch = sizeText.match(/(\d+)/);
                                            if (sizeMatch && sizeMatch[1]) perPage = parseInt(sizeMatch[1]);
                                        }
                                        if (perPage === 50) {
                                            const perPageText = document.querySelector('.vxe-pager')?.textContent || '';
                                            const perPageMatch = perPageText.match(/(\d+)\s*\/\s*page/i);
                                            if (perPageMatch && perPageMatch[1]) perPage = parseInt(perPageMatch[1]);
                                        }
                                        
                                        if (totalRecords > 0) {
                                            totalPages = Math.ceil(totalRecords / perPage);
                                        } else if (maxPageNum > 1) {
                                            totalPages = maxPageNum;
                                        }
                                        
                                        return { totalPages, totalRecords, maxPageFromButtons: maxPageNum, perPage };
                                    });
                                    
                                    const allPagesData = [];
                                    const scrapedHeaders = [];
                                    const maxPages = Math.min(paginationInfo.totalPages, 100);
                                    console.log('📄 Scraping ' + maxPages + ' pages (' + paginationInfo.totalRecords + ' records)...');
                                    
                                    for (let pageNum = 1; pageNum <= maxPages; pageNum++) {
                                        try {
                                            if (pageNum > 1) {
                                                
                                                // 智能分頁導航：先嘗試直接點擊目標頁碼，如果找不到則點擊"下一頁"
                                                let pageNavigated = false;
                                                let attempts = 0;
                                                const maxAttempts = 20; // 最多嘗試20次（防止無限循環）
                                                
                                                while (!pageNavigated && attempts < maxAttempts) {
                                                    attempts++;
                                                    
                                                    const navigationResult = await page.evaluate((targetPage) => {
                                                        // 先檢查當前是否已經在目標頁面
                                                        const activeBtn = document.querySelector('.vxe-pager--num-btn.is--active');
                                                        if (activeBtn) {
                                                            const activePageText = activeBtn.textContent.trim();
                                                            const activePageNum = parseInt(activePageText);
                                                            if (activePageNum === targetPage) {
                                                                return { success: true, message: 'Already on target page' };
                                                            }
                                                        }
                                                        
                                                        // 嘗試直接點擊目標頁碼按鈕
                                                        const buttons = Array.from(document.querySelectorAll('.vxe-pager--num-btn'));
                                                        const targetBtn = buttons.find(btn => {
                                                            const text = btn.textContent.trim();
                                                            return parseInt(text) === targetPage;
                                                        });
                                                        
                                                        if (targetBtn && !targetBtn.classList.contains('is--active')) {
                                                            targetBtn.click();
                                                            return { success: true, message: 'Clicked target page button directly' };
                                                        }
                                                        
                                                        // 如果找不到目標頁碼按鈕，嘗試點擊"下一頁"按鈕
                                                        const nextBtn = document.querySelector('.vxe-pager--btn-next:not(.is--disabled), .vxe-pager--next-btn:not(.is--disabled), button[aria-label*="next" i], button[aria-label*="下一頁" i]');
                                                        if (nextBtn && !nextBtn.disabled && !nextBtn.classList.contains('is--disabled')) {
                                                            nextBtn.click();
                                                            return { success: true, message: 'Clicked next page button' };
                                                        }
                                                        
                                                        return { success: false, message: 'Target page button not found and next button unavailable' };
                                                    }, pageNum);
                                                    
                                                    if (navigationResult.success) {
                                                        await new Promise(r => setTimeout(r, 1500));
                                                        const currentPageCheck = await page.evaluate((targetPage) => {
                                                            const activeBtn = document.querySelector('.vxe-pager--num-btn.is--active');
                                                            if (activeBtn) {
                                                                return parseInt(activeBtn.textContent.trim()) === targetPage;
                                                            }
                                                            return false;
                                                        }, pageNum);
                                                        
                                                        if (currentPageCheck) {
                                                            pageNavigated = true;
                                                        }
                                                    } else {
                                                        break;
                                                    }
                                                }
                                                
                                                // 等待 loading 消失和數據出現
                                                try {
                                                    await Promise.race([
                                                        page.waitForFunction(() => {
                                                            const spinners = [
                                                                '.ant-spin-spinning',
                                                                '.ant-spin',
                                                                '[class*="loading"]',
                                                                '[class*="spinner"]',
                                                                '.vxe-loading'
                                                            ];
                                                            const hasLoading = spinners.some(selector => {
                                                                const el = document.querySelector(selector);
                                                                return el && (el.offsetParent !== null || window.getComputedStyle(el).display !== 'none');
                                                            });
                                                            
                                                            const hasTable = document.querySelector('table[class*="vxe-table--header"], table.vxe-table--header, .vxe-table, [class*="vxe-table"]');
                                                            const hasData = hasTable && (
                                                                hasTable.querySelectorAll('tbody tr').length > 0 ||
                                                                hasTable.querySelectorAll('.vxe-table--body-wrapper tr').length > 0 ||
                                                                hasTable.querySelectorAll('tr').length > 1
                                                            );
                                                            
                                                            return !hasLoading && hasData;
                                                        }, { 
                                                            timeout: 15000,
                                                            polling: 500
                                                        }),
                                                        new Promise((resolve) => setTimeout(() => resolve(), 10000)) // 最多等10秒
                                                    ]);
                                                } catch (e) {
                                                    // Page load timeout
                                                }
                                            }
                                            const extractPromise = extractTableData(page);
                                            const tableData = await Promise.race([
                                                extractPromise,
                                                new Promise((resolve) => setTimeout(() => resolve({ found: false, error: 'Extract timeout' }), 10000))
                                            ]);
                                            
                                            if (tableData && tableData.found) {
                                                // 保存表頭（使用第一頁的表頭）
                                                if (pageNum === 1 && tableData.headers && tableData.headers.length > 0) {
                                                    scrapedHeaders.push(...tableData.headers);
                                                }
                                                
                                                allPagesData.push({
                                                    pageNumber: pageNum,
                                                    headers: tableData.headers || scrapedHeaders,
                                                    rowCount: tableData.rowCount || 0,
                                                    data: tableData.data || []
                                                });
                                                
                                                console.log('✅ Page ' + pageNum + ': Scraped ' + (tableData.rowCount || 0) + ' rows');
                                            } else {
                                                console.error('❌ Page ' + pageNum + ': Failed to extract data');
                                                if (tableData && tableData.debug) {
                                                    console.error('📋 Debug info:', JSON.stringify(tableData.debug, null, 2));
                                                }
                                                
                                                // 如果連續3頁都失敗，停止爬取
                                                if (pageNum > 3 && allPagesData.length === 0) {
                                                    console.log('⚠️ Too many failed pages, stopping pagination');
                                                    break;
                                                }
                                                
                                                // 重試一次（快速重試）
                                                if (pageNum <= 3) {
                                                    console.log('⏳ Retrying page ' + pageNum + '...');
                                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                                    const retryData = await Promise.race([
                                                        extractTableData(page),
                                                        new Promise((resolve) => setTimeout(() => resolve({ found: false }), 5000))
                                                    ]);
                                                    if (retryData && retryData.found) {
                                                        allPagesData.push({
                                                            pageNumber: pageNum,
                                                            headers: retryData.headers || scrapedHeaders,
                                                            rowCount: retryData.rowCount || 0,
                                                            data: retryData.data || []
                                                        });
                                                        console.log('✅ Page ' + pageNum + ' (retry): Scraped ' + (retryData.rowCount || 0) + ' rows');
                                                    }
                                                }
                                            }
                                        } catch (err) {
                                            console.error('❌ Error on page ' + pageNum + ':', err.message);
                                            // 繼續下一頁，不要因為單頁錯誤而停止
                                        }
                                        
                                        // 頁面間稍作延遲，避免請求過快
                                        if (pageNum < maxPages) {
                                            await new Promise(r => setTimeout(r, 300));
                                        }
                                    }
                                    
                                    // 合併所有頁面的數據
                                    const scrapedData = [];
                                    allPagesData.forEach(pageData => {
                                        if (pageData.data && pageData.data.length > 0) {
                                            scrapedData.push(...pageData.data);
                                        }
                                    });
                                    
                                    console.log('\\n✅ Total scraped: ' + scrapedData.length + ' rows from ' + allPagesData.length + ' pages');
                                    
                                    // 保存到全域變數
                                    globalScrapedData = scrapedData;
                                    globalScrapedHeaders = scrapedHeaders.length > 0 ? scrapedHeaders : (allPagesData[0]?.headers || []);
                                    globalPaginationInfo = paginationInfo;
                                    globalAllPagesData = allPagesData;
                                    
                                 } catch (err) {
                                     console.error('❌ Failed to fill Player ID:', err.message);
                                 }
                             } else {
                                 // No account number, click Search directly
                                 try {
                                     const searchButton = await page.waitForFunction(() => {
                                         const buttons = Array.from(document.querySelectorAll('button'));
                                         return buttons.find(b => 
                                             b.textContent.trim() === 'Search' || 
                                             b.querySelector('span')?.textContent.trim() === 'Search'
                                         );
                                     }, { timeout: 5000 });
                                     if (searchButton) await searchButton.click();
                                 } catch (err) {
                                     // Search button click failed
                                 }
                                 
                                // Wait for loading
                                try {
                                   await new Promise(r => setTimeout(r, 2000));
                                   await page.waitForFunction(() => {
                                       const spinners = ['.ant-spin-spinning', '.ant-spin', '[class*="loading"]', '[class*="spinner"]', '.vxe-loading'];
                                       const hasLoading = spinners.some(selector => {
                                           const el = document.querySelector(selector);
                                           return el && (el.offsetParent !== null || window.getComputedStyle(el).display !== 'none');
                                       });
                                       const hasTable = document.querySelector('table[class*="vxe-table--header"], table.vxe-table--header, .vxe-table, [class*="vxe-table"]');
                                       const hasData = hasTable && (
                                           hasTable.querySelectorAll('tbody tr').length > 0 ||
                                           hasTable.querySelectorAll('.vxe-table--body-wrapper tr').length > 0 ||
                                           hasTable.querySelectorAll('tr').length > 1
                                       );
                                       return !hasLoading && hasData;
                                   }, { timeout: 60000, polling: 500 });
                                } catch (e) {
                                    // Wait timeout, proceed anyway
                                }
                                
                                await new Promise(r => setTimeout(r, 1000));
                                
                                // 獲取分頁資訊
                                const paginationInfo = await page.evaluate(() => {
                                    let totalPages = 1;
                                    let totalRecords = 0;
                                    
                                    const totalSpan = document.querySelector('.vxe-pager--total');
                                    if (totalSpan) {
                                        const totalText = totalSpan.textContent || totalSpan.innerText || '';
                                        let totalMatch = totalText.match(/total\s+(\d+)\s+records?/i);
                                        if (totalMatch && totalMatch[1]) {
                                            totalRecords = parseInt(totalMatch[1]);
                                        } else {
                                            totalMatch = totalText.match(/共\s*(\d+)\s*条记录/i);
                                            if (totalMatch && totalMatch[1]) {
                                                totalRecords = parseInt(totalMatch[1]);
                                            } else {
                                                const numberMatch = totalText.match(/(\d+)/);
                                                if (numberMatch && numberMatch[1]) {
                                                    totalRecords = parseInt(numberMatch[1]);
                                                }
                                            }
                                        }
                                    }
                                    
                                    const pageButtons = document.querySelectorAll('.vxe-pager--num-btn');
                                    let maxPageNum = 1;
                                    if (pageButtons.length > 0) {
                                        pageButtons.forEach(btn => {
                                            const text = btn.textContent.trim();
                                            const pageNum = parseInt(text);
                                            if (!isNaN(pageNum) && pageNum > 0 && pageNum <= 10000 && pageNum > maxPageNum) {
                                                maxPageNum = pageNum;
                                            }
                                        });
                                    }
                                    
                                    let perPage = 50;
                                    const sizeSelector = document.querySelector('.vxe-pager--sizes-select, .vxe-pager--sizes select, .vxe-select');
                                    if (sizeSelector) {
                                        const sizeText = sizeSelector.textContent || sizeSelector.value || '';
                                        const sizeMatch = sizeText.match(/(\d+)/);
                                        if (sizeMatch && sizeMatch[1]) perPage = parseInt(sizeMatch[1]);
                                    }
                                    if (perPage === 50) {
                                        const perPageText = document.querySelector('.vxe-pager')?.textContent || '';
                                        const perPageMatch = perPageText.match(/(\d+)\s*\/\s*page/i);
                                        if (perPageMatch && perPageMatch[1]) perPage = parseInt(perPageMatch[1]);
                                    }
                                    
                                    if (totalRecords > 0) {
                                        totalPages = Math.ceil(totalRecords / perPage);
                                    } else if (maxPageNum > 1) {
                                        totalPages = maxPageNum;
                                    }
                                    
                                    return { totalPages, totalRecords, maxPageFromButtons: maxPageNum, perPage };
                                });
                                
                                const allPagesData = [];
                                const scrapedHeaders = [];
                                const maxPages = Math.min(paginationInfo.totalPages, 100);
                                console.log('📄 Scraping ' + maxPages + ' pages (' + paginationInfo.totalRecords + ' records)...');
                                
                                for (let pageNum = 1; pageNum <= maxPages; pageNum++) {
                                    try {
                                        if (pageNum > 1) {
                                            let pageNavigated = false;
                                            let attempts = 0;
                                            const maxAttempts = 20;
                                            
                                            while (!pageNavigated && attempts < maxAttempts) {
                                                attempts++;
                                                const navigationResult = await page.evaluate((targetPage) => {
                                                    const activeBtn = document.querySelector('.vxe-pager--num-btn.is--active');
                                                    if (activeBtn && parseInt(activeBtn.textContent.trim()) === targetPage) {
                                                        return { success: true };
                                                    }
                                                    const buttons = Array.from(document.querySelectorAll('.vxe-pager--num-btn'));
                                                    const targetBtn = buttons.find(btn => parseInt(btn.textContent.trim()) === targetPage);
                                                    if (targetBtn && !targetBtn.classList.contains('is--active')) {
                                                        targetBtn.click();
                                                        return { success: true };
                                                    }
                                                    const nextBtn = document.querySelector('.vxe-pager--btn-next:not(.is--disabled), .vxe-pager--next-btn:not(.is--disabled)');
                                                    if (nextBtn && !nextBtn.disabled && !nextBtn.classList.contains('is--disabled')) {
                                                        nextBtn.click();
                                                        return { success: true };
                                                    }
                                                    return { success: false };
                                                }, pageNum);
                                                
                                                if (navigationResult.success) {
                                                    await new Promise(r => setTimeout(r, 1500));
                                                    const currentPageCheck = await page.evaluate((targetPage) => {
                                                        const activeBtn = document.querySelector('.vxe-pager--num-btn.is--active');
                                                        return activeBtn && parseInt(activeBtn.textContent.trim()) === targetPage;
                                                    }, pageNum);
                                                    if (currentPageCheck) pageNavigated = true;
                                                } else {
                                                    break;
                                                }
                                            }
                                            
                                            try {
                                                await Promise.race([
                                                    page.waitForFunction(() => {
                                                        const spinners = ['.ant-spin-spinning', '.ant-spin', '[class*="loading"]', '[class*="spinner"]', '.vxe-loading'];
                                                        const hasLoading = spinners.some(selector => {
                                                            const el = document.querySelector(selector);
                                                            return el && (el.offsetParent !== null || window.getComputedStyle(el).display !== 'none');
                                                        });
                                                        const hasTable = document.querySelector('.vxe-table, [class*="vxe-table"]');
                                                        const hasData = hasTable && hasTable.querySelectorAll('tbody tr').length > 0;
                                                        return !hasLoading && hasData;
                                                    }, { timeout: 15000, polling: 500 }),
                                                    new Promise((resolve) => setTimeout(() => resolve(), 10000))
                                                ]);
                                            } catch (e) {
                                                // Page load timeout
                                            }
                                        }
                                        const extractPromise = extractTableData(page);
                                        const tableData = await Promise.race([
                                            extractPromise,
                                            new Promise((resolve) => setTimeout(() => resolve({ found: false, error: 'Extract timeout' }), 10000))
                                        ]);
                                        
                                        if (tableData && tableData.found) {
                                            // 保存表頭（使用第一頁的表頭）
                                            if (pageNum === 1 && tableData.headers && tableData.headers.length > 0) {
                                                scrapedHeaders.push(...tableData.headers);
                                            }
                                            
                                            allPagesData.push({
                                                pageNumber: pageNum,
                                                headers: tableData.headers || scrapedHeaders,
                                                rowCount: tableData.rowCount || 0,
                                                data: tableData.data || []
                                            });
                                            
                                            console.log('✅ Page ' + pageNum + ': Scraped ' + (tableData.rowCount || 0) + ' rows');
                                        } else {
                                            console.error('❌ Page ' + pageNum + ': Failed to extract data');
                                            if (tableData && tableData.debug) {
                                                console.error('📋 Debug info:', JSON.stringify(tableData.debug, null, 2));
                                            }
                                            
                                            // 如果連續3頁都失敗，停止爬取
                                            if (pageNum > 3 && allPagesData.length === 0) {
                                                console.log('⚠️ Too many failed pages, stopping pagination');
                                                break;
                                            }
                                            
                                            // 重試一次（快速重試）
                                            if (pageNum <= 3) {
                                                console.log('⏳ Retrying page ' + pageNum + '...');
                                                await new Promise(resolve => setTimeout(resolve, 1000));
                                                const retryData = await Promise.race([
                                                    extractTableData(page),
                                                    new Promise((resolve) => setTimeout(() => resolve({ found: false }), 5000))
                                                ]);
                                                if (retryData && retryData.found) {
                                                    allPagesData.push({
                                                        pageNumber: pageNum,
                                                        headers: retryData.headers || scrapedHeaders,
                                                        rowCount: retryData.rowCount || 0,
                                                        data: retryData.data || []
                                                    });
                                                    console.log('✅ Page ' + pageNum + ' (retry): Scraped ' + (retryData.rowCount || 0) + ' rows');
                                                }
                                            }
                                        }
                                    } catch (err) {
                                        console.error('❌ Error on page ' + pageNum + ':', err.message);
                                        // 繼續下一頁，不要因為單頁錯誤而停止
                                    }
                                    
                                    // 頁面間稍作延遲，避免請求過快
                                    if (pageNum < maxPages) {
                                        await new Promise(r => setTimeout(r, 300));
                                    }
                                }
                                
                                // 合併所有頁面的數據
                                const scrapedData = [];
                                allPagesData.forEach(pageData => {
                                    if (pageData.data && pageData.data.length > 0) {
                                        scrapedData.push(...pageData.data);
                                    }
                                });
                                
                                console.log('\\n✅ Total scraped: ' + scrapedData.length + ' rows from ' + allPagesData.length + ' pages');
                                
                                // 保存到全域變數
                                globalScrapedData = scrapedData;
                                globalScrapedHeaders = scrapedHeaders.length > 0 ? scrapedHeaders : (allPagesData[0]?.headers || []);
                                globalPaginationInfo = paginationInfo;
                                globalAllPagesData = allPagesData;
                             }
                             


                         } catch (e) {
                             console.error('❌ Debug interaction failed:', e.message);
                         }
                    }

                    
                    // 截圖
                    const screenshotPath = path.join(workingDir, 'omg_scraped_result.png');
                    await page.screenshot({ path: screenshotPath, fullPage: true });
                    console.log('📸 Screenshot saved to: ' + screenshotPath);
                    
                    // 使用全域變數中的爬取數據
                    const scrapedHeaders = globalScrapedHeaders;
                    const scrapedData = globalScrapedData;
                    const paginationInfo = globalPaginationInfo;
                    const allPagesData = globalAllPagesData;
                    
                    console.log('✅ Using scraped data: ' + scrapedData.length + ' rows, ' + scrapedHeaders.length + ' headers');
                    if (paginationInfo) {
                        console.log('✅ Pagination info: ' + paginationInfo.totalPages + ' pages, ' + paginationInfo.totalRecords + ' total records');
                    }
                    
                    // 構建查詢參數對象（使用已存在的變量）
                    // targetUrl、dateStartParsed、dateEndParsed 已在函數開始處聲明，這裡直接使用
                    // 只需要解析 accountNumber
                    const accountNumberParsed = ($accountNumberJs && $accountNumberJs !== 'null') ? String($accountNumberJs).replace(/^"|"\$/g, '') : null;
                    
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
                    // paginationInfo 和 allPagesData 已經在上面讀取數據時設置了
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
                    const totalPages = paginationInfo ? paginationInfo.totalPages : (allPagesData.length > 0 ? allPagesData.length : 1);
                    
                    // 如果有分頁數據，構建分頁結構
                    let pages = [];
                    if (allPagesData.length > 0) {
                        pages = allPagesData.map(pageData => ({
                            pageNumber: pageData.pageNumber,
                            tables: [{
                                found: true,
                                headers: pageData.headers || scrapedHeaders,
                                headerCount: (pageData.headers || scrapedHeaders).length,
                                rowCount: pageData.rowCount || 0,
                                data: pageData.data || []
                            }]
                        }));
                    } else {
                        // 沒有分頁數據，使用單頁結構
                        pages = [{
                            pageNumber: 1,
                            tables: allTables
                        }];
                    }
                    
                    const domData = {
                        pageInfo: pageInfo,
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed,
                            account_number: accountNumberParsed
                        },
                        totalPages: totalPages,
                        pages: pages,
                        tables: allTables,
                        paginationInfo: paginationInfo
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
        // $this->line($result->output());

        // 執行完成後刪除腳本檔案
        if (file_exists($scriptPath)) {
            unlink($scriptPath);
        }

        // 檢查執行是否失敗
        if ($result->failed()) {
            $this->error("❌ Browser automation failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        // 從 stdout 讀取 JSON 結果（最後一行應該是 JSON）
        $output = $result->output();
        $lines = explode("\n", trim($output));
        
        // 從最後一行開始找有效的 JSON
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (!empty($line) && $line[0] === '{') {
                $decoded = json_decode($line, true);
                if ($decoded !== null) {
                    return $decoded;
                }
            }
        }

        $this->error("❌ No valid JSON result found in output");
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
                            // 保存表頭（使用第一個表格的表頭）
                            if (empty($headers) && !empty($table['headers'])) {
                                $headers = $table['headers'];
                            }
                            
                            // 過濾掉 header 行（值完全匹配 headers 的數據行）
                            $filteredData = array_filter($table['data'], function($row) use ($headers) {
                                if (empty($headers) || !is_array($row)) {
                                    return true; // 如果沒有 headers 或 row 不是數組，保留該行
                                }
                                
                                // 檢查該行的值是否完全匹配 headers 數組
                                $rowValues = array_values(array_filter($row, function($key) {
                                    return $key !== '_rowIndex'; // 排除 _rowIndex 字段
                                }, ARRAY_FILTER_USE_KEY));
                                
                                // 如果行的值數組與 headers 數組完全匹配，則認為這是 header 行
                                if (count($rowValues) === count($headers)) {
                                    $isHeaderRow = true;
                                    foreach ($headers as $index => $header) {
                                        $rowValue = $rowValues[$index] ?? '';
                                        if (trim($rowValue) !== trim($header)) {
                                            $isHeaderRow = false;
                                            break;
                                        }
                                    }
                                    if ($isHeaderRow) {
                                        return false; // 過濾掉 header 行
                                    }
                                }
                                
                                return true; // 保留數據行
                            });
                            
                            $pageRowCount = count($filteredData);
                            
                            // 將過濾後的數據添加到總數組中
                            $allData = array_merge($allData, array_values($filteredData));
                            $totalRows += $pageRowCount;
                        }
                    }
                }
            }
        }
        
        // 如果 pages 為空，嘗試從 tables 中提取（類似 PGONE）
        if (empty($allData) && !empty($domData['tables'])) {
            foreach ($domData['tables'] as $table) {
                if (!empty($table['data'])) {
                    // 保存表頭（使用第一個表格的表頭）
                    if (empty($headers) && !empty($table['headers'])) {
                        $headers = $table['headers'];
                    }
                    
                    // 過濾掉 header 行（值完全匹配 headers 的數據行）
                    $filteredData = array_filter($table['data'], function($row) use ($headers) {
                        if (empty($headers) || !is_array($row)) {
                            return true; // 如果沒有 headers 或 row 不是數組，保留該行
                        }
                        
                        // 檢查該行的值是否完全匹配 headers 數組
                        $rowValues = array_values(array_filter($row, function($key) {
                            return $key !== '_rowIndex'; // 排除 _rowIndex 字段
                        }, ARRAY_FILTER_USE_KEY));
                        
                        // 如果行的值數組與 headers 數組完全匹配，則認為這是 header 行
                        if (count($rowValues) === count($headers)) {
                            $isHeaderRow = true;
                            foreach ($headers as $index => $header) {
                                $rowValue = $rowValues[$index] ?? '';
                                if (trim($rowValue) !== trim($header)) {
                                    $isHeaderRow = false;
                                    break;
                                }
                            }
                            if ($isHeaderRow) {
                                return false; // 過濾掉 header 行
                            }
                        }
                        
                        return true; // 保留數據行
                    });
                    
                    $allData = array_merge($allData, array_values($filteredData));
                    $totalRows += count($filteredData);
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
