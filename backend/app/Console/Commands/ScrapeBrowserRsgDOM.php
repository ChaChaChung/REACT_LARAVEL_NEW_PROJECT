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
     */
    protected $signature = 'agent:scrape-rsg-dom {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from RSG DOM elements using browser automation';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');

        $this->info('=== Browser DOM Scraper ===');
        $this->info("Target URL: {$url}");

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url);

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
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段
        $cookiesCode = $this->generateRsgPuppeteerCookiesCode();

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

                    // 監聽瀏覽器控制台的所有訊息（包括 console.log）
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            console.log('❌ Browser console error:', msg.text());
                        } else {
                            // 輸出所有瀏覽器控制台訊息（包括 console.log）
                            console.log('🌐 Browser console:', msg.text());
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

                    // 檢查是否有 iframe，如果有則切換到 iframe
                    const iframes = await page.$$('iframe');
                    console.log('🔍 Found ' + iframes.length + ' iframe(s)');
                    
                    let targetPage = page;

                    // 嘗試等待表格出現（最多等待 15 秒）
                    try {
                        await targetPage.waitForSelector('table', { timeout: 15000 });
                        console.log('✅ Table found, waiting for content to load...');
                        await new Promise(resolve => setTimeout(resolve, 3000));
                    } catch (e) {
                        console.log('⚠️  No table selector found, trying alternative selectors...');
                        // 嘗試等待包含表格文字的容器
                        try {
                            await targetPage.waitForSelector('tbody, tr, [class*="table"], [role="table"]', { timeout: 10000 });
                            console.log('✅ Found table-like elements, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        } catch (e2) {
                            console.log('⚠️  No table-like elements found either, continuing...');
                        }
                    }

                    // 額外等待，確保所有動態內容載入
                    await new Promise(resolve => setTimeout(resolve, 5000));

                    // 處理 reservation 日期選擇器：點擊並選擇「昨天」
                    try {
                        console.log('📅 Looking for reservation date field...');
                        
                        // 查找 reservation 欄位（可能是 input、div 或其他元素）
                        const reservationField = await targetPage.evaluate(() => {
                            // 嘗試多種方式查找 reservation 欄位
                            const selectors = [
                                'input[name*="reservation" i]',
                                'input[id*="reservation" i]',
                                'input[placeholder*="reservation" i]',
                                '[name*="reservation" i]',
                                '[id*="reservation" i]',
                                '[class*="reservation" i]'
                            ];
                            
                            for (const selector of selectors) {
                                const element = document.querySelector(selector);
                                if (element) {
                                    return {
                                        found: true,
                                        tagName: element.tagName,
                                        id: element.id,
                                        name: element.name,
                                        className: element.className
                                    };
                                }
                            }
                            return { found: false };
                        });
                        
                        if (reservationField.found) {
                            console.log('✅ Found reservation field, clicking to open date picker...');
                            
                            // 點擊 reservation 欄位以打開日期選擇器
                            await targetPage.evaluate(() => {
                                const selectors = [
                                    'input[name*="reservation" i]',
                                    'input[id*="reservation" i]',
                                    'input[placeholder*="reservation" i]',
                                    '[name*="reservation" i]',
                                    '[id*="reservation" i]',
                                    '[class*="reservation" i]'
                                ];
                                
                                for (const selector of selectors) {
                                    const element = document.querySelector(selector);
                                    if (element) {
                                        element.click();
                                        element.focus();
                                        return;
                                    }
                                }
                            });
                            
                            // 等待日期選擇器出現
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 查找並點擊「昨天」選項
                            console.log('🔍 Looking for "昨天" option in date picker...');
                            const yesterdayClicked = await targetPage.evaluate(() => {
                                // 查找日期選擇器的下拉選單
                                const dropdownSelectors = [
                                    '.daterangepicker',
                                    '.dropdown-menu',
                                    '[class*="daterangepicker"]',
                                    '[class*="dropdown-menu"]',
                                    '.opensleft',
                                    '[class*="opensleft"]'
                                ];
                                
                                let dropdown = null;
                                for (const selector of dropdownSelectors) {
                                    dropdown = document.querySelector(selector);
                                    if (dropdown) {
                                        break;
                                    }
                                }
                                
                                if (!dropdown) {
                                    // 如果找不到，嘗試查找所有可見的下拉選單
                                    const allDropdowns = Array.from(document.querySelectorAll('.dropdown-menu, [class*="dropdown"], [class*="picker"]'));
                                    dropdown = allDropdowns.find(d => {
                                        const style = window.getComputedStyle(d);
                                        return style.display !== 'none' && style.visibility !== 'hidden';
                                    });
                                }
                                
                                if (dropdown) {
                                    // 優先使用 data-range-key 屬性查找「昨天」選項
                                    const yesterdayByAttr = dropdown.querySelector('[data-range-key="昨天"]');
                                    if (yesterdayByAttr) {
                                        yesterdayByAttr.click();
                                        return { clicked: true, text: yesterdayByAttr.textContent || '昨天', method: 'data-range-key' };
                                    }
                                    
                                    // 如果找不到，嘗試在所有元素中查找 data-range-key="昨天"
                                    const allYesterdayByAttr = document.querySelector('[data-range-key="昨天"]');
                                    if (allYesterdayByAttr) {
                                        allYesterdayByAttr.click();
                                        return { clicked: true, text: allYesterdayByAttr.textContent || '昨天', method: 'data-range-key (global)' };
                                    }
                                    
                                    // 備用方案：在下拉選單中查找「昨天」選項（通過文字）
                                    const options = dropdown.querySelectorAll('a, button, li, span, div');
                                    for (const option of options) {
                                        const text = option.textContent || option.innerText || '';
                                        if (text.includes('昨天') || text.includes('Yesterday') || text.trim() === '昨天') {
                                            option.click();
                                            return { clicked: true, text: text, method: 'text-content' };
                                        }
                                    }
                                    
                                    // 如果還是找不到，嘗試查找包含「昨天」的按鈕或連結
                                    const yesterdayElements = Array.from(document.querySelectorAll('*')).filter(el => {
                                        const text = el.textContent || el.innerText || '';
                                        return text.includes('昨天') && (el.tagName === 'A' || el.tagName === 'BUTTON' || el.tagName === 'LI' || el.onclick);
                                    });
                                    
                                    if (yesterdayElements.length > 0) {
                                        yesterdayElements[0].click();
                                        return { clicked: true, text: yesterdayElements[0].textContent, method: 'text-filter' };
                                    }
                                }
                                
                                // 如果下拉選單中找不到，嘗試在整個文檔中查找 data-range-key="昨天"
                                const globalYesterday = document.querySelector('[data-range-key="昨天"]');
                                if (globalYesterday) {
                                    globalYesterday.click();
                                    return { clicked: true, text: globalYesterday.textContent || '昨天', method: 'data-range-key (global fallback)' };
                                }
                                
                                return { clicked: false };
                            });
                            
                            if (yesterdayClicked.clicked) {
                                console.log('✅ Clicked "昨天" option: ' + yesterdayClicked.text + ' (method: ' + (yesterdayClicked.method || 'unknown') + ')');
                                // 等待日期選擇器關閉
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                // 檢查是否需要點擊查詢按鈕來觸發資料載入
                                console.log('🔍 Checking if query button needs to be clicked...');
                                try {
                                    const queryButton = await targetPage.evaluate(() => {
                                        const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], input[type="button"]'));
                                        const queryBtn = buttons.find(btn => {
                                            const text = btn.textContent || btn.value || '';
                                            return text.includes('查詢') || text.includes('查詢') || btn.type === 'submit';
                                        });
                                        return queryBtn ? { found: true, text: queryBtn.textContent || queryBtn.value } : { found: false };
                                    });
                                    
                                    if (queryButton.found) {
                                        console.log('📋 Found query button, clicking to load data...');
                                        await targetPage.evaluate(() => {
                                            const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], input[type="button"]'));
                                            const queryBtn = buttons.find(btn => {
                                                const text = btn.textContent || btn.value || '';
                                                return text.includes('查詢') || text.includes('查詢') || btn.type === 'submit';
                                            });
                                            if (queryBtn) {
                                                queryBtn.click();
                                            }
                                        });
                                        console.log('✅ Clicked query button, waiting for data to load...');
                                        // 等待 AJAX 請求完成和表格資料載入
                                        await new Promise(resolve => setTimeout(resolve, 5000));
                                        
                                        // 等待表格資料出現（最多等待 10 秒）
                                        try {
                                            await targetPage.waitForFunction(() => {
                                                const tables = document.querySelectorAll('table');
                                                return Array.from(tables).some(table => {
                                                    const rows = table.querySelectorAll('tr');
                                                    // 檢查是否有包含實際資料的行（不是表頭）
                                                    return Array.from(rows).some((row, idx) => {
                                                        if (idx === 0) return false; // 跳過第一行（可能是表頭）
                                                        const cells = row.querySelectorAll('td');
                                                        if (cells.length === 0) return false;
                                                        // 檢查是否有非空且不是表頭文字的單元格
                                                        return Array.from(cells).some(cell => {
                                                            const text = cell.textContent.trim();
                                                            return text !== '' && 
                                                                   text !== '幣別' && 
                                                                   text !== '帳號' && 
                                                                   text !== '下注' &&
                                                                   text !== '尚未有任何記錄';
                                                        });
                                                    });
                                                });
                                            }, { timeout: 10000 });
                                            console.log('✅ Table data loaded');
                                        } catch (e) {
                                            console.log('⚠️  Timeout waiting for table data, continuing anyway...');
                                        }
                                    } else {
                                        console.log('⚠️  No query button found, data may load automatically');
                                        // 即使沒有查詢按鈕，也等待一下讓可能的自動載入完成
                                        await new Promise(resolve => setTimeout(resolve, 3000));
                                    }
                                } catch (e) {
                                    console.log('⚠️  Error checking for query button: ' + e.message);
                                    // 即使出錯，也等待一下
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                }
                            } else {
                                console.log('⚠️  Could not find "昨天" option in date picker');
                                // 輸出調試信息
                                await targetPage.evaluate(() => {
                                    const dropdowns = document.querySelectorAll('.dropdown-menu, [class*="dropdown"], [class*="picker"]');
                                    console.log('Found ' + dropdowns.length + ' dropdown/picker elements');
                                    dropdowns.forEach((dropdown, idx) => {
                                        const options = dropdown.querySelectorAll('a, button, li');
                                        console.log('Dropdown ' + idx + ' has ' + options.length + ' options');
                                        options.forEach((opt, optIdx) => {
                                            if (optIdx < 10) { // 只輸出前10個
                                                console.log('  Option ' + optIdx + ': ' + (opt.textContent || opt.innerText || '').trim());
                                            }
                                        });
                                    });
                                });
                            }
                        } else {
                            console.log('⚠️  Reservation field not found');
                        }
                    } catch (e) {
                        console.log('⚠️  Error handling reservation field: ' + e.message);
                    }

                    // 檢查是否有查詢/搜索按鈕，如果有且表格為空，嘗試點擊
                    try {
                        // 使用 evaluate 查找包含「查詢」文字的按鈕
                        const queryButtonInfo = await targetPage.evaluate(() => {
                            const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], input[type="button"]'));
                            const queryButton = buttons.find(btn => {
                                const text = btn.textContent || btn.value || '';
                                return text.includes('查詢') || text.includes('查詢') || btn.type === 'submit';
                            });
                            return queryButton ? { found: true, tagName: queryButton.tagName, type: queryButton.type } : { found: false };
                        });
                        
                        const hasTableData = await targetPage.evaluate(() => {
                            const tables = document.querySelectorAll('table');
                            return Array.from(tables).some(table => {
                                const rows = table.querySelectorAll('tr');
                                // 檢查是否有包含實際資料的行（不是表頭）
                                return Array.from(rows).some((row, idx) => {
                                    // 跳過第一行（可能是表頭）
                                    if (idx === 0) return false;
                                    const cells = row.querySelectorAll('td');
                                    if (cells.length === 0) return false;
                                    // 檢查是否有非空且不是表頭文字的單元格
                                    return Array.from(cells).some(cell => {
                                        const text = cell.textContent.trim();
                                        return text !== '' && 
                                               text !== '幣別' && 
                                               text !== '帳號' && 
                                               text !== '下注' &&
                                               text !== '彩金貢獻值' &&
                                               text !== '彩金' &&
                                               text !== '贏分' &&
                                               text !== '總贏分' &&
                                               text !== '淨輸贏' &&
                                               text !== 'RTP' &&
                                               text !== '筆數' &&
                                               text !== '尚未有任何記錄';
                                    });
                                });
                            });
                        });
                        
                        if (queryButtonInfo.found && !hasTableData) {
                            console.log('📋 Table appears empty, attempting to trigger query...');
                            try {
                                // 使用 CSS 選擇器點擊按鈕
                                await targetPage.evaluate(() => {
                                    const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], input[type="button"]'));
                                    const queryButton = buttons.find(btn => {
                                        const text = btn.textContent || btn.value || '';
                                        return text.includes('查詢') || text.includes('查詢') || btn.type === 'submit';
                                    });
                                    if (queryButton) {
                                        queryButton.click();
                                    }
                                });
                                console.log('✅ Clicked query button, waiting for results...');
                                await new Promise(resolve => setTimeout(resolve, 10000)); // 等待查詢結果
                            } catch (e) {
                                console.log('⚠️  Could not click query button: ' + e.message);
                            }
                        }
                    } catch (e) {
                        console.log('⚠️  Error checking for query button: ' + e.message);
                    }

                    console.log('📄 Extracting DOM content...');

                    // 等待 dataContent 元素出現（如果存在，可能是 tbody 或 div）
                    try {
                        await targetPage.waitForSelector('tbody.dataContent, .dataContent', { timeout: 10000 });
                        console.log('✅ Found .dataContent element, waiting for content to load...');
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    } catch (e) {
                        console.log('⚠️  .dataContent element not found or timeout, continuing with table extraction...');
                    }

                    // 使用 evaluate() 在瀏覽器環境中執行 JavaScript 來提取 DOM 資料
                    // 如果使用 iframe，在 iframe 的上下文中執行
                    const domData = await targetPage.evaluate(() => {
                        // 調試：檢查頁面結構
                        console.log('🔍 Checking page structure...');
                        console.log('Document body innerHTML length: ' + document.body.innerHTML.length);
                        
                        // 優先檢查 dataContent 元素（可能是 tbody 或 div）
                        const dataContentElements = Array.from(document.querySelectorAll('tbody.dataContent, .dataContent'));
                        console.log('dataContent elements count: ' + dataContentElements.length);
                        if (dataContentElements.length > 0) {
                            dataContentElements.forEach((el, idx) => {
                                console.log('dataContent ' + idx + ': tagName=' + el.tagName + ', id=' + el.id + ', className=' + el.className);
                                
                                // 如果 dataContent 是 tbody，找到包含它的 table
                                if (el.tagName === 'TBODY') {
                                    let parentTable = el.parentElement;
                                    while (parentTable && parentTable.tagName !== 'TABLE') {
                                        parentTable = parentTable.parentElement;
                                    }
                                    if (parentTable) {
                                        console.log('  Found parent table for tbody.dataContent: id=' + parentTable.id + ', className=' + parentTable.className);
                                    }
                                }
                                
                                const tablesInDataContent = el.querySelectorAll('table');
                                const trsInDataContent = el.querySelectorAll('tr');
                                const tdsInDataContent = el.querySelectorAll('td');
                                console.log('  Tables in dataContent ' + idx + ': ' + tablesInDataContent.length);
                                console.log('  TRs in dataContent ' + idx + ': ' + trsInDataContent.length);
                                console.log('  TDs in dataContent ' + idx + ': ' + tdsInDataContent.length);
                                if (trsInDataContent.length > 0) {
                                    // 輸出前幾行的信息
                                    Array.from(trsInDataContent).slice(0, 3).forEach((tr, trIdx) => {
                                        const tds = tr.querySelectorAll('td, th');
                                        const text = Array.from(tds).map(td => td.textContent.trim()).join(' | ');
                                        console.log('    TR ' + trIdx + ': ' + tds.length + ' cells, text=' + text.substring(0, 100));
                                    });
                                }
                            });
                        }
                        
                        // 檢查是否有 table 標籤
                        let allTables = Array.from(document.querySelectorAll('table'));
                        console.log('allTables count: ' + allTables.length);
                        
                        // 檢查是否有 div 模擬的表格結構
                        const divsWithTableRole = Array.from(document.querySelectorAll('div[role="table"], div.table, div[class*="table"]'));
                        console.log('divs with table role/class: ' + divsWithTableRole.length);
                        
                        // 檢查是否有 tbody
                        const tbodies = Array.from(document.querySelectorAll('tbody'));
                        console.log('tbody count: ' + tbodies.length);
                        
                        // 檢查是否有 tr（可能在 table 外）
                        const allTrs = Array.from(document.querySelectorAll('tr'));
                        console.log('all tr count: ' + allTrs.length);
                        
                        // 檢查是否有 td 或 th
                        const allTds = Array.from(document.querySelectorAll('td'));
                        const allThs = Array.from(document.querySelectorAll('th'));
                        console.log('all td count: ' + allTds.length + ', all th count: ' + allThs.length);
                        
                        if (allTables.length > 0) {
                            console.log('allTables: ' + JSON.stringify(allTables.map((t, i) => ({ 
                                index: i, 
                                id: t.id, 
                                className: t.className,
                                rowCount: t.querySelectorAll('tr').length,
                                cellCount: t.querySelectorAll('td, th').length,
                                innerHTML: t.innerHTML.substring(0, 200) // 前200字符
                            }))));
                        } else {
                            console.log('⚠️  No <table> tags found! Checking for alternative structures...');
                            // 檢查是否有包含表格文字的容器
                            const containersWithTableText = Array.from(document.querySelectorAll('div, section, main')).filter(el => {
                                const text = el.textContent || '';
                                return text.includes('幣別') || text.includes('帳號') || text.includes('下注');
                            });
                            console.log('Containers with table-like text: ' + containersWithTableText.length);
                            if (containersWithTableText.length > 0) {
                                containersWithTableText.forEach((container, idx) => {
                                    console.log('Container ' + idx + ': class=' + container.className + ', id=' + container.id);
                                });
                            }
                        }
                        // 優先從 dataContent 元素中查找表格
                        if (dataContentElements.length > 0) {
                            // 從 dataContent 中提取表格
                            const tablesInDataContent = [];
                            dataContentElements.forEach(dataContent => {
                                // 如果 dataContent 是 tbody，找到包含它的 table
                                if (dataContent.tagName === 'TBODY') {
                                    let parentTable = dataContent.parentElement;
                                    while (parentTable && parentTable.tagName !== 'TABLE') {
                                        parentTable = parentTable.parentElement;
                                    }
                                    if (parentTable && !allTables.includes(parentTable) && !tablesInDataContent.includes(parentTable)) {
                                        tablesInDataContent.push(parentTable);
                                        console.log('✅ Found parent table for tbody.dataContent');
                                    }
                                } else {
                                    // 如果 dataContent 是 div 或其他元素，查找其中的 table
                                    const tables = dataContent.querySelectorAll('table');
                                    tables.forEach(table => {
                                        if (!allTables.includes(table) && !tablesInDataContent.includes(table)) {
                                            tablesInDataContent.push(table);
                                        }
                                    });
                                    
                                    // 如果 dataContent 中沒有 table，但有多個 tr，嘗試找到包含這些 tr 的父 table
                                    if (tables.length === 0) {
                                        const trs = dataContent.querySelectorAll('tr');
                                        if (trs.length > 0) {
                                            console.log('⚠️  dataContent has ' + trs.length + ' tr elements but no table tag');
                                            // 嘗試找到包含這些 tr 的父元素（可能是 tbody 或 table）
                                            const firstTr = trs[0];
                                            if (firstTr && firstTr.parentElement) {
                                                let parent = firstTr.parentElement;
                                                // 向上查找，直到找到 table
                                                while (parent && parent !== dataContent) {
                                                    if (parent.tagName === 'TABLE') {
                                                        if (!allTables.includes(parent) && !tablesInDataContent.includes(parent)) {
                                                            tablesInDataContent.push(parent);
                                                            console.log('✅ Found parent table element for trs');
                                                        }
                                                        break;
                                                    }
                                                    parent = parent.parentElement;
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                            
                            if (tablesInDataContent.length > 0) {
                                console.log('✅ Found ' + tablesInDataContent.length + ' tables in dataContent elements');
                                // 將 dataContent 中的表格添加到處理列表（優先處理）
                                allTables = [...tablesInDataContent, ...allTables];
                            } else if (dataContentElements.length > 0) {
                                console.log('⚠️  dataContent found but no tables extracted from it');
                            }
                        }
                        
                        // 如果沒有找到標準 table 標籤，嘗試從其他結構提取
                        if (allTables.length === 0) {
                            console.log('⚠️  No standard tables found, attempting to extract from alternative structures...');
                            
                            // 嘗試從 dataContent 中查找表格結構
                            if (dataContentElements.length > 0) {
                                console.log('🔍 Checking dataContent for table-like structures...');
                                dataContentElements.forEach((dataContent, idx) => {
                                    const rows = dataContent.querySelectorAll('tr, div[class*="row"], div[role="row"]');
                                    console.log('dataContent ' + idx + ' has ' + rows.length + ' rows');
                                });
                            }
                            
                            // 嘗試從包含表格文字的容器中提取
                            const tableLikeContainers = Array.from(document.querySelectorAll('div, section, main')).filter(el => {
                                const text = el.textContent || '';
                                const hasTableHeaders = text.includes('幣別') || text.includes('帳號') || text.includes('下注');
                                const hasRows = el.querySelectorAll('tr, div[class*="row"], div[role="row"]').length > 0;
                                return hasTableHeaders && hasRows;
                            });
                            
                            console.log('Found ' + tableLikeContainers.length + ' table-like containers');
                            
                            // 如果還是找不到，返回空陣列
                            if (tableLikeContainers.length === 0) {
                                console.log('❌ No table structures found at all');
                                return {
                                    pageInfo: {
                                        title: document.title,
                                        url: window.location.href,
                                    },
                                    textContent: document.body.innerText.trim(),
                                    formDates: {},
                                    tables: [],
                                    debug: {
                                        tableCount: 0,
                                        trCount: allTrs.length,
                                        tdCount: allTds.length,
                                        thCount: allThs.length,
                                        hasTableTags: false,
                                        dataContentCount: dataContentElements.length
                                    }
                                };
                            }
                        }
                        
                        // 存儲每個表格的表頭
                        const tableHeaders = {};
                        
                        // 第一遍：識別表頭表格（通常包含 th 標籤或 class 包含 header）
                        allTables.forEach((table, index) => {
                            // 獲取表格的所有行
                            const rows = Array.from(table.querySelectorAll('tr'));
                            console.log('rows count: ' + rows.length);
                            console.log('rows: ' + JSON.stringify(rows.map((r, i) => ({ index: i, id: r.id, className: r.className }))));
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
                            // 檢查是否有 thead 和 tbody 結構
                            const thead = table.querySelector('thead');
                            const tbody = table.querySelector('tbody');
                            const tbodyDataContent = table.querySelector('tbody.dataContent');
                            
                            // 獲取表格的所有行
                            let rows = Array.from(table.querySelectorAll('tr'));
                            
                            // 如果有 tbody.dataContent，優先從 tbody 中獲取行
                            if (tbodyDataContent) {
                                rows = Array.from(tbodyDataContent.querySelectorAll('tr'));
                                console.log('Table ' + tableIndex + ': Found tbody.dataContent with ' + rows.length + ' rows');
                            } else if (tbody) {
                                rows = Array.from(tbody.querySelectorAll('tr'));
                                console.log('Table ' + tableIndex + ': Found tbody with ' + rows.length + ' rows');
                            }
                            
                            // 獲取表格的 class 屬性
                            const tableClass = table.className || '';
                            
                            // 從 thead 中提取表頭（如果存在）
                            let headerRow = null;
                            if (thead) {
                                const headerRows = thead.querySelectorAll('tr');
                                if (headerRows.length > 0) {
                                    const headerCells = headerRows[0].querySelectorAll('th, td');
                                    if (headerCells.length > 0) {
                                        headerRow = Array.from(headerCells).map(cell => cell.textContent.trim());
                                        console.log('Table ' + tableIndex + ': Extracted header from thead: ' + headerRow.length + ' columns');
                                    }
                                }
                            }
                            
                            // 檢查表格是否包含表頭
                            const isHeaderTable = tableClass.includes('header') || 
                                                tableClass.includes('Header') ||
                                                (thead !== null) ||
                                                (rows.length > 0 && rows[0].querySelectorAll('th').length > 0);
                            
                            // 初始化資料起始索引
                            let dataStartIndex = 0;
                            
                            // 如果沒有從 thead 獲取表頭，檢查第一行是否包含 th（標準表頭行）
                            const firstRowHasTh = rows.length > 0 && rows[0] && rows[0].querySelectorAll('th').length > 0;
                            
                            // 如果沒有從 thead 獲取表頭，且第一行包含 th，則第一行是表頭
                            if (!headerRow && firstRowHasTh) {
                                // 提取第一行作為表頭
                                const headerCells = rows[0].querySelectorAll('th, td');
                                headerRow = Array.from(headerCells).map((cell, idx) => {
                                    const text = cell.textContent.trim();
                                    return text || 'column_' + idx;
                                });
                                // 資料從第二行開始
                                dataStartIndex = 1;
                                
                                // 檢查是否有包含 td 的資料行
                                const hasDataRows = rows.slice(1).some(row => row.querySelectorAll('td').length > 0);
                                if (!hasDataRows) {
                                    console.log('Table ' + tableIndex + ': Has header but no data rows (td cells)');
                                }
                            } else if (!headerRow && isHeaderTable && tableClass.includes('header')) {
                                // 純表頭表格（class 包含 header 且沒有資料行）
                                if (rows[0]) {
                                    const headerCells = rows[0].querySelectorAll('th, td');
                                    headerRow = Array.from(headerCells).map((cell, idx) => {
                                        const text = cell.textContent.trim();
                                        return text || 'column_' + idx;
                                    });
                                }
                                dataStartIndex = rows.length;
                            } else {
                                // 資料表格：嘗試找到對應的表頭
                                // 1. 檢查前面的表格是否有表頭
                                let foundHeader = null;
                                for (let i = tableIndex - 1; i >= 0; i--) {
                                    if (tableHeaders[i]) {
                                        foundHeader = tableHeaders[i];
                                        break;
                                    }
                                }
                                
                                // 2. 如果找到表頭，使用它
                                if (foundHeader) {
                                    // 使用找到的表頭
                                    headerRow = foundHeader;
                                    // 所有行都是資料
                                    dataStartIndex = 0;
                                } else {
                                    // 3. 否則檢查第一行是否包含 th（標準表頭）
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
                            
                            // 檢查表格是否只有 th 沒有 td（特殊情況）
                            // 但如果有 tbody.dataContent，應該都是 td
                            const tableHasOnlyTh = !tbodyDataContent && rows.length > 0 && rows[dataStartIndex] && rows[dataStartIndex].querySelectorAll('td').length === 0;
                            
                            // 將資料行轉換為對象數組
                            // 過濾掉空行（沒有 td/th 或所有單元格都是空的）
                            const dataRows = rows.slice(dataStartIndex)
                                .filter(row => {
                                    // 如果表格只有 th，也檢查 th（但 tbody.dataContent 應該都是 td）
                                    const cells = (tableHasOnlyTh && !tbodyDataContent)
                                        ? row.querySelectorAll('th') 
                                        : row.querySelectorAll('td');
                                    // 保留有單元格且至少有一個非空內容的行
                                    // 排除表頭文字行
                                    const hasContent = cells.length > 0 && Array.from(cells).some(cell => {
                                        const text = cell.textContent.trim();
                                        return text !== '' && 
                                               text !== '幣別' && 
                                               text !== '帳號' && 
                                               text !== '下注' &&
                                               text !== '彩金貢獻值' &&
                                               text !== '彩金' &&
                                               text !== '贏分' &&
                                               text !== '總贏分' &&
                                               text !== '淨輸贏' &&
                                               text !== 'RTP' &&
                                               text !== '筆數';
                                    });
                                    return hasContent;
                                })
                                .map((row, rowIndex) => {
                                // 如果表格只有 th，也提取 th（但 tbody.dataContent 應該都是 td）
                                const cells = (tableHasOnlyTh && !tbodyDataContent)
                                    ? Array.from(row.querySelectorAll('th'))
                                    : Array.from(row.querySelectorAll('td'));
                                const rowData = {};
                                
                                // 如果有表頭，使用表頭作為 key
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
                            
                            // 調試信息：輸出處理後的表格信息
                            console.log('Table ' + tableIndex + ' processed: isHeaderTable=' + isHeaderTable + ', headerCount=' + (headerRow ? headerRow.length : 0) + ', dataRowCount=' + dataRows.length + ', totalRows=' + rows.length + ', dataStartIndex=' + dataStartIndex + ', hasOnlyTh=' + tableHasOnlyTh);
                            if (dataRows.length === 0 && rows.length > dataStartIndex) {
                                // 如果有行但沒有提取到資料，輸出調試信息
                                const skippedRows = rows.slice(dataStartIndex);
                                console.log('  Skipped rows info:');
                                skippedRows.forEach((row, idx) => {
                                    const tds = row.querySelectorAll('td');
                                    const ths = row.querySelectorAll('th');
                                    const text = row.textContent.trim().substring(0, 50);
                                    console.log('    Row ' + (dataStartIndex + idx) + ': tdCount=' + tds.length + ', thCount=' + ths.length + ', text=' + text);
                                });
                            }
                            
                            return {
                                tableIndex: tableIndex,
                                tableId: table.id || null,
                                tableClass: table.className || null,
                                isHeaderTable: isHeaderTable,
                                headers: headerRow || [],
                                headerCount: headerRow ? headerRow.length : 0,
                                rowCount: dataRows.length,
                                // 原始格式（保留以備用）
                                rawRows: rows.slice(dataStartIndex).map(row => {
                                    // 如果表格只有 th，也提取 th
                                    const cells = tableHasOnlyTh 
                                        ? row.querySelectorAll('th')
                                        : row.querySelectorAll('td');
                                    return Array.from(cells).map(cell => cell.textContent.trim());
                                }),
                                // 結構化資料（推薦使用）
                                data: dataRows
                            };
                        }
                        
                        // 提取表單中的日期輸入值
                        const formDates = {};
                        const dateInputs = document.querySelectorAll('input[type="date"], input[type="text"][placeholder*="date"], input[type="text"][placeholder*="日期"], input[name*="date"], input[name*="日期"]');
                        dateInputs.forEach(input => {
                            const name = input.name || input.id || input.placeholder || 'unknown';
                            const value = input.value || '';
                            if (value) {
                                formDates[name] = value;
                            }
                        });
                        
                        // 也查找日期範圍輸入（通常有 start 和 end）
                        const dateRangeInputs = document.querySelectorAll('input[type="date"], input[type="text"]');
                        dateRangeInputs.forEach(input => {
                            const name = (input.name || input.id || '').toLowerCase();
                            const value = input.value || '';
                            if (value && (name.includes('start') || name.includes('begin') || name.includes('from'))) {
                                formDates['start_date'] = value;
                            }
                            if (value && (name.includes('end') || name.includes('to') || name.includes('until'))) {
                                formDates['end_date'] = value;
                            }
                        });
                        
                        // 專門提取 placeholder 為 "Start time" 和 "End time" 的日期輸入
                        const startTimeInputs = document.querySelectorAll('input[placeholder="Start time"], input[placeholder*="Start time"]');
                        startTimeInputs.forEach(input => {
                            const value = input.value || '';
                            if (value) {
                                formDates['start_time'] = value;
                                formDates['start_time_original'] = value;
                            }
                        });
                        
                        const endTimeInputs = document.querySelectorAll('input[placeholder="End time"], input[placeholder*="End time"]');
                        endTimeInputs.forEach(input => {
                            const value = input.value || '';
                            if (value) {
                                formDates['end_time'] = value;
                                formDates['end_time_original'] = value;
                            }
                        });

                        // 提取所有表格資料
                        const processedTables = allTables.map((table, tableIndex) => processTable(table, tableIndex));
                        
                        // 調試信息：輸出所有處理後的表格
                        console.log('Processed tables summary:');
                        processedTables.forEach((table, idx) => {
                            console.log('  Table ' + idx + ': rowCount=' + table.rowCount + ', isHeaderTable=' + table.isHeaderTable + ', headerCount=' + table.headerCount);
                        });
                        
                            // 過濾表格：保留有資料的表格，或不是純表頭表格的表格
                            // 但即使沒有表頭，只要有資料行就保留
                            // 也保留有表頭但沒有資料的表格（可能是空的查詢結果）
                            const filteredTables = processedTables.filter(table => {
                                // 保留有資料行的表格
                                if (table.rowCount > 0) {
                                    return true;
                                }
                                // 保留有表頭的表格（即使沒有資料行，也有結構信息）
                                if (table.headerCount > 0) {
                                    return true;
                                }
                                // 保留不是純表頭表格的表格（即使沒有資料行，也可能有結構信息）
                                if (!table.isHeaderTable) {
                                    return true;
                                }
                                return false;
                            });
                        
                        console.log('Filtered tables count: ' + filteredTables.length + ' (from ' + processedTables.length + ' total)');

                        const result = {
                            // 基本頁面信息
                            pageInfo: {
                                title: document.title,
                                url: window.location.href,
                            },
                            
                            // 提取所有文本內容
                            textContent: document.body.innerText.trim(),
                            
                            // 提取表單日期
                            formDates: formDates,

                            // 提取所有表格資料
                            tables: filteredTables,
                            
                            // 調試信息
                            debug: {
                                tableCount: allTables.length,
                                trCount: allTrs.length,
                                tdCount: allTds.length,
                                thCount: allThs.length,
                                processedTablesCount: processedTables.length,
                                filteredTablesCount: filteredTables.length,
                                hasTableTags: allTables.length > 0,
                                dataContentCount: dataContentElements.length
                            }
                        };
                        
                        return result;
                    });

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

                    // 調試信息：輸出表格統計
                    if (domData && domData.tables) {
                        console.log('📊 Tables extracted: ' + domData.tables.length);
                        domData.tables.forEach((table, idx) => {
                            console.log('  Table ' + idx + ': ' + table.rowCount + ' rows, ' + table.headerCount + ' headers');
                        });
                    } else {
                        console.log('⚠️  No tables found in domData');
                    }

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

        // 處理表單日期
        $formDates = $domData['formDates'] ?? [];
        // 判斷是否有表單日期
        if (!empty($formDates)) {
            // 處理 Start time
            if (isset($formDates['start_time'])) {
                // 取得 Start time
                $startTime = $formDates['start_time'];
                // 轉換日期格式
                try {
                    $startDateTime = \Carbon\Carbon::parse($startTime);
                    $formDates['start_time_converted'] = $startDateTime->format('Y-m-d H:i:s');
                    $formDates['start_time_timestamp'] = $startDateTime->timestamp;
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Could not parse start_time: {$e->getMessage()}");
                }
            }
            // 處理 End time
            if (isset($formDates['end_time'])) {
                // 取得 End time
                $endTime = $formDates['end_time'];                
                // 轉換日期格式
                try {
                    $endDateTime = \Carbon\Carbon::parse($endTime);
                    $formDates['end_time_converted'] = $endDateTime->format('Y-m-d H:i:s');
                    $formDates['end_time_timestamp'] = $endDateTime->timestamp;
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Could not parse end_time: {$e->getMessage()}");
                }
            }
            
            // 更新 domData 中的 formDates
            $domData['formDates'] = $formDates;
        }

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

