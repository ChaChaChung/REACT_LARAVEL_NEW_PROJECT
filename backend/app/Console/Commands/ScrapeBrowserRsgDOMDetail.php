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
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {account_number?} - 要點擊的帳號號碼（可選參數）
     */
    protected $signature = 'agent:scrape-rsg-dom-detail {url} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from RSG DOM elements using browser automation (optimized for DataTables AJAX pagination)';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');

        $this->info('=== Browser DOM Scraper (Optimized for DataTables) ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$dateStart}");
        $this->info("Date End: {$dateEnd}");
        $this->info("Account Number: {$accountNumber}");

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $dateStart, $dateEnd, $accountNumber);

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
     * @param string|null $dateStart 要選擇的開始日期（可選）
     * @param string|null $dateEnd 要選擇的結束日期（可選）
     * @param string|null $accountNumber 要點擊的帳號號碼（可選）
     * @return string 返回生成的腳本文件路徑
    */
    private function createPuppeteerScript($url, $dateStart = null, $dateEnd = null, $accountNumber = null)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段
        $cookiesCode = $this->generateRsgPuppeteerCookiesCode('page');

        // 將 account_number 轉換為 JavaScript 可用的格式
        // 使用 json_encode 確保正確的 JSON 格式，null 值會輸出為字符串 'null'
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';

        // 將 date 轉換為 JavaScript 可用的格式
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容（優化版本）
             * 針對 DataTables AJAX 分頁系統優化，使用串行爬取以獲得最佳性能
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

                    $cookiesCode

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

                    // 等待頁面穩定
                    await new Promise(resolve => setTimeout(resolve, 2000));

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
                            // 點擊超連結，並等待可能的導航
                            try {
                                await Promise.all([
                                    // 等待導航（如果發生）
                                    page.waitForNavigation({ 
                                        waitUntil: 'domcontentloaded',
                                        timeout: 10000 
                                    }).catch(() => {
                                        // 如果沒有導航發生，忽略超時錯誤
                                    }),
                                    // 點擊 Currency 連結
                                    page.evaluate((columnIndex, isUTag) => {
                                        const rows = Array.from(document.querySelector('tbody.dataContent').querySelectorAll('tr'));
                                        if (columnIndex !== -1) {
                                            for (const row of rows) {
                                                const cells = Array.from(row.querySelectorAll('td'));
                                                if (cells[columnIndex]) {
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
                                    }, currencyLinkInfo.columnIndex, currencyLinkInfo.isUTag || false)
                                ]);
                            } catch (e) {
                                // 即使出錯也繼續
                            }
                            
                            // 等待頁面穩定
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // ========== 處理日期範圍選擇 ==========
                            // 如果提供了 date_start 和 date_end，使用 Customize 選項
                            // 否則，使用 Current month 選項
                            try {                                
                                // 查找 reservation 欄位
                                const reservationField = await page.evaluate(() => {
                                    const element = document.querySelector('[id*="reservation"]');
                                    if (element) {
                                        return { found: true };
                                    }
                                    return { found: false };
                                });
                                
                                if (reservationField.found) {
                                    // 點擊 reservation 欄位以打開日期選擇器
                                    await page.evaluate(() => {
                                        const element = document.querySelector('[id*="reservation"]');
                                        if (element) {
                                            element.click();
                                            element.focus();
                                        }
                                    });
                                    
                                    // 等待日期選擇器出現
                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                    
                                    // 判斷是否提供了自定義日期範圍
                                    const dateStartProvided = $dateStartJs && $dateStartJs !== 'null';
                                    const dateEndProvided = $dateEndJs && $dateEndJs !== 'null';
                                    
                                    if (dateStartProvided && dateEndProvided) {
                                        // ========== 使用 Customize 選項 ==========
                                        // 首先列出所有可用的日期範圍選項
                                        const availableRanges = await page.evaluate(() => {
                                            let dropdown = document.querySelector('.ranges');
                                            if (dropdown) {
                                                const allOptions = Array.from(dropdown.querySelectorAll('li'));
                                                return allOptions.map(li => ({
                                                    text: li.textContent.trim(),
                                                    dataKey: li.getAttribute('data-range-key')
                                                }));
                                            }
                                            return [];
                                        });
                                        
                                        // 查找並點擊「Customize」選項（嘗試多個可能的名稱）
                                        const customizeClicked = await page.evaluate(() => {
                                            let dropdown = document.querySelector('.ranges');
                                            if (dropdown) {
                                                // 嘗試多個可能的選項名稱
                                                const possibleKeys = ['Custom Range', 'Customize', 'Custom', '自訂範圍', '自定义范围'];
                                                
                                                for (const key of possibleKeys) {
                                                    const customizeByAttr = dropdown.querySelector('[data-range-key="' + key + '"]');
                                                    if (customizeByAttr) {
                                                        customizeByAttr.click();
                                                        return { clicked: true, text: customizeByAttr.textContent, key: key };
                                                    }
                                                }
                                                
                                                // 如果沒有找到，嘗試通過文本內容查找
                                                const allOptions = Array.from(dropdown.querySelectorAll('li'));
                                                for (const option of allOptions) {
                                                    const text = option.textContent.trim().toLowerCase();
                                                    if (text.includes('custom') || text.includes('自訂') || text.includes('自定义')) {
                                                        option.click();
                                                        return { clicked: true, text: option.textContent.trim(), method: 'text-match' };
                                                    }
                                                }
                                            }
                                            return { clicked: false };
                                        });
                                        
                                        if (customizeClicked.clicked) {
                                            // 等待自定義日期輸入框出現
                                            await new Promise(resolve => setTimeout(resolve, 1000));
                                            
                                            // 使用 daterangepicker 的 API 來設置日期（更可靠的方法）
                                            const dateSetResult = await page.evaluate((dateStart, dateEnd) => {
                                                try {
                                                    // 方法 1: 使用 daterangepicker 的 setStartDate 和 setEndDate API
                                                    const reservationInput = document.querySelector('[id*="reservation"]');
                                                    if (reservationInput && reservationInput.daterangepicker) {
                                                        // 使用 daterangepicker 插件的 API
                                                        reservationInput.daterangepicker.setStartDate(dateStart);
                                                        reservationInput.daterangepicker.setEndDate(dateEnd);
                                                        return { method: 'api', success: true };
                                                    }
                                                    
                                                    // 方法 2: 如果 API 不可用，嘗試通過 jQuery 觸發
                                                    if (typeof window.$ !== 'undefined') {
                                                        const jQueryReservation = window.$('[id*="reservation"]');
                                                        if (jQueryReservation.length > 0 && jQueryReservation.data('daterangepicker')) {
                                                            jQueryReservation.data('daterangepicker').setStartDate(dateStart);
                                                            jQueryReservation.data('daterangepicker').setEndDate(dateEnd);
                                                            return { method: 'jquery', success: true };
                                                        }
                                                    }
                                                    
                                                    // 方法 3: 直接填入並觸發完整的事件鏈
                                                    const startInput = document.querySelector('input[name="daterangepicker_start"]');
                                                    const endInput = document.querySelector('input[name="daterangepicker_end"]');
                                                    
                                                    if (startInput && endInput) {
                                                        // 清空現有值
                                                        startInput.value = '';
                                                        endInput.value = '';
                                                        
                                                        // 設置新值
                                                        startInput.value = dateStart;
                                                        endInput.value = dateEnd;
                                                        
                                                        // 觸發多個事件以確保 daterangepicker 識別變化
                                                        ['input', 'change', 'keyup', 'blur'].forEach(eventType => {
                                                            startInput.dispatchEvent(new Event(eventType, { bubbles: true }));
                                                            endInput.dispatchEvent(new Event(eventType, { bubbles: true }));
                                                        });
                                                        
                                                        // 如果有 jQuery，也用 jQuery 觸發事件
                                                        if (typeof window.$ !== 'undefined') {
                                                            window.$(startInput).trigger('change');
                                                            window.$(endInput).trigger('change');
                                                        }
                                                        
                                                        return { method: 'manual', success: true, startValue: startInput.value, endValue: endInput.value };
                                                    }
                                                    
                                                    return { success: false, reason: 'No suitable method found' };
                                                } catch (e) {
                                                    return { success: false, error: e.message };
                                                }
                                            }, $dateStartJs.replace(/"/g, ''), $dateEndJs.replace(/"/g, ''));
                                            
                                            // 等待一下，確保值已填入
                                            await new Promise(resolve => setTimeout(resolve, 1000));
                                            
                                            // 截圖：確認日期是否已填入
                                            await page.screenshot({ 
                                                path: 'after_date_input_screenshot.png',
                                                fullPage: false
                                            });
                                            
                                            // 點擊 Apply 按鈕
                                            const applyResult = await page.evaluate(() => {
                                                const applyBtn = document.querySelector('.applyBtn') || 
                                                              document.querySelector('button.btn-success') ||
                                                              document.querySelector('button[type="button"].btn.btn-sm.btn-success') ||
                                                              document.querySelector('.daterangepicker button.applyBtn');
                                                if (applyBtn) {
                                                    applyBtn.click();
                                                    return { clicked: true, text: applyBtn.textContent };
                                                }
                                                return { clicked: false, reason: 'Apply button not found' };
                                            });
                                            
                                            // 等待日期選擇器關閉和數據載入
                                            await new Promise(resolve => setTimeout(resolve, 3000));
                                        } else {
                                            console.log('⚠️  Could not find Customize option');
                                        }
                                    } else {
                                        // ========== 使用 Current month 選項 ==========
                                        const currentMonthClicked = await page.evaluate(() => {
                                            let dropdown = document.querySelector('.ranges');
                                            if (dropdown) {
                                                const currentMonthByAttr = dropdown.querySelector('[data-range-key="Current month"]');
                                                if (currentMonthByAttr) {
                                                    currentMonthByAttr.click();
                                                    return { clicked: true, text: currentMonthByAttr.textContent };
                                                }
                                            }
                                            return { clicked: false };
                                        });
                                        
                                        if (currentMonthClicked.clicked) {
                                            // 等待日期選擇器關閉和數據載入
                                            await new Promise(resolve => setTimeout(resolve, 3000));
                                        } else {
                                            console.log('⚠️  Could not find Current month option');
                                        }
                                    }
                                } else {
                                    console.log('⚠️  Could not find reservation field');
                                }
                            } catch (e) {
                                console.log('⚠️  Error handling date range selection: ' + e.message);
                            }
                            
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
                                    // 點擊 slim 連結，並等待可能的導航
                                    try {
                                        await Promise.all([
                                            // 等待導航（如果發生）
                                            page.waitForNavigation({ 
                                                waitUntil: 'domcontentloaded',
                                                timeout: 10000 
                                            }).catch(() => {
                                                // 如果沒有導航發生，忽略超時錯誤
                                            }),
                                            // 點擊 slim 連結
                                            page.evaluate(() => {
                                                const allULinks = Array.from(document.querySelectorAll('u[onclick]'));
                                                for (const uLink of allULinks) {
                                                    const text = uLink.textContent.trim();
                                                    if (text === 'slim') {
                                                        uLink.click();
                                                        return;
                                                    }
                                                }
                                            })
                                        ]);
                                    } catch (e) {
                                        // 即使出錯也繼續
                                    }
                                    
                                    // 等待頁面穩定
                                    await new Promise(resolve => setTimeout(resolve, 2000));
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
                        
                        // 解析帳號號碼（從 PHP 變量插值）
                        let accountNumber = $accountNumberJs;
                        
                        if (accountNumber && accountNumber !== null && accountNumber !== '') {
                            try {
                                // 帳號連結資訊
                                let accountLinkInfo = null;
                                // 是否找到帳號連結
                                let foundInPage = false;
                                // 當前頁碼
                                let currentPage = 1;
                                // 最多查找 100 頁，防止無限循環
                                const maxPages = 100;
                                
                                // 在頁面中查找 account number
                                const findAccountInCurrentPage = async () => {
                                    return await page.evaluate((accountNum) => {
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
                                        return { found: false };
                                    }, accountNumber);
                                };
                                
                                // 尋找並點擊下一頁
                                const goToNextPage = async () => {
                                    const nextPageInfo = await page.evaluate(() => {
                                        // 尋找下一頁按鈕
                                        const dataTablesNext = document.querySelector('.paginate_button.next:not(.disabled)');
                                        // 尋找下一頁按鈕中的連結
                                        const link = dataTablesNext.querySelector('a');
                                        // 為元素添加唯一標識，方便 Puppeteer 選擇
                                        const uniqueId = 'next-page-' + Date.now();
                                        link.setAttribute('data-puppeteer-id', uniqueId);
                                        return {
                                            found: true,
                                            selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                            method: 'click',
                                            text: link.textContent.trim()
                                        };
                                        
                                        return { found: false };
                                    });
                                    
                                    // 判斷是否找到下一頁按鈕
                                    if (nextPageInfo.found) {
                                        try {
                                            // 使用 Puppeteer click
                                            await page.click(nextPageInfo.selector, { timeout: 5000 });
                                            
                                            // 等待表格數據更新（DataTables 通常使用 AJAX，不會導航）
                                            await new Promise(resolve => setTimeout(resolve, 3000));
                                            
                                            return true;
                                        } catch (e) {
                                            console.log('⚠️  Error clicking next page: ' + e.message);
                                            return false;
                                        }
                                    }
                                    
                                    return false;
                                };
                                
                                // 開始查找：先檢查當前頁
                                accountLinkInfo = await findAccountInCurrentPage();
                                
                                // 如果當前頁找不到，開始翻頁查找
                                while (!accountLinkInfo.found && currentPage < maxPages) {
                                    const hasNextPage = await goToNextPageForAccount();
                                    
                                    if (!hasNextPage) {
                                        break;
                                    }
                                    
                                    currentPage++;
                                    
                                    // 在新頁面查找
                                    accountLinkInfo = await findAccountInCurrentPage();
                                    
                                    if (accountLinkInfo.found) {
                                        foundInPage = true;
                                        break;
                                    }
                                }

                                // 判斷是否找到帳號連結
                                if (accountLinkInfo && accountLinkInfo.found) {
                                    // 點擊帳號連結，並等待可能的導航
                                    try {
                                        await Promise.all([
                                            // 等待導航（如果發生）
                                            page.waitForNavigation({ 
                                                waitUntil: 'domcontentloaded',
                                                timeout: 10000 
                                            }).catch(() => {
                                                // 如果沒有導航發生，忽略超時錯誤
                                            }),
                                            // 點擊帳號連結
                                            page.click(accountLinkInfo.selector, { timeout: 5000 })
                                        ]);
                                    } catch (e) {
                                        // 即使出錯也繼續
                                    }

                                    // 等待頁面穩定
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                    
                                    // 截圖：進入 account 後的頁面（在點擊 date 之前）
                                    await page.screenshot({ 
                                        path: 'after_account_screenshot.png',
                                        fullPage: false
                                    });
                                } else {
                                    console.log('⚠️  Could not find account number link after searching ' + currentPage + ' pages');
                                }
                            } catch (e) {
                                console.log('⚠️  Error looking for account number: ' + e.message);
                            }
                        }
                        
                        // ========== 點擊 "Slots (All)" 標籤（無論是否有 account_number）==========
                        try {
                            // 先列出所有可用的標籤（用於調試）
                            const allTabsInfo = await page.evaluate(() => {
                                const tabList = document.querySelector('ul#myTab');
                                if (!tabList) {
                                    return { found: false, tabs: [] };
                                }
                                
                                const tabs = Array.from(tabList.querySelectorAll('li'));
                                const tabsData = tabs.map((tab, idx) => {
                                    const link = tab.querySelector('a');
                                    return {
                                        index: idx,
                                        text: link ? link.textContent.trim() : '',
                                        isActive: tab.classList.contains('active'),
                                        href: link ? link.getAttribute('href') : ''
                                    };
                                });
                                
                                return { found: true, tabs: tabsData };
                            });
                            
                            if (allTabsInfo.found) {
                                allTabsInfo.tabs.forEach(tab => {
                                    const activeStatus = tab.isActive ? ' [ACTIVE]' : '';
                                });
                            }
                            
                            // 查找並點擊 Slots (All) 標籤
                            const slotsTabResult = await page.evaluate(() => {
                                // 查找 ul#myTab 中的所有 li 元素
                                const tabList = document.querySelector('ul#myTab');
                                if (!tabList) {
                                    return { found: false, reason: 'Tab list #myTab not found' };
                                }
                                
                                // 查找所有 li 元素
                                const tabs = Array.from(tabList.querySelectorAll('li'));
                                
                                // 查找包含 "Slots (All)" 文本的標籤
                                for (let i = 0; i < tabs.length; i++) {
                                    const tab = tabs[i];
                                    const link = tab.querySelector('a');
                                    if (link) {
                                        const text = link.textContent.trim();
                                        // 檢查是否為 "Slots (All)" 或包含 "Slots" 和 "All"
                                        if (text === 'Slots (All)' || (text.includes('Slots') && text.includes('All'))) {
                                            // 為連結添加唯一標識
                                            const uniqueId = 'slots-all-tab-' + Date.now();
                                            link.setAttribute('data-puppeteer-id', uniqueId);
                                            
                                            return {
                                                found: true,
                                                selector: 'a[data-puppeteer-id="' + uniqueId + '"]',
                                                text: text,
                                                href: link.getAttribute('href'),
                                                isActive: tab.classList.contains('active')
                                            };
                                        }
                                    }
                                }
                                
                                return { found: false, reason: 'Slots (All) tab not found' };
                            });
                            
                            if (slotsTabResult.found) {
                                // 使用 evaluate 內部點擊，更可靠
                                await page.evaluate((selector) => {
                                    const element = document.querySelector(selector);
                                    if (element) {
                                        element.click();
                                    }
                                }, slotsTabResult.selector);
                                
                                // 等待標籤內容載入（增加等待時間）
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                
                                // 驗證標籤是否真的被激活了
                                const verifyResult = await page.evaluate(() => {
                                    const tabList = document.querySelector('ul#myTab');
                                    if (!tabList) {
                                        return { activeTab: 'Unknown', contentVisible: false };
                                    }
                                    
                                    const activeTab = tabList.querySelector('li.active a');
                                    const activeTabText = activeTab ? activeTab.textContent.trim() : 'None';
                                    
                                    // 檢查 #data99991 是否可見（Slots (All) 的內容區域）
                                    const slotsAllContent = document.querySelector('#data99991');
                                    const contentVisible = slotsAllContent ? 
                                        (slotsAllContent.style.display !== 'none' && 
                                         slotsAllContent.classList.contains('active')) : false;
                                    
                                    return {
                                        activeTab: activeTabText,
                                        contentVisible: contentVisible,
                                        contentId: slotsAllContent ? slotsAllContent.id : 'Not found'
                                    };
                                });
                                
                                // 截圖：點擊 Slots (All) 後的狀態
                                await page.screenshot({ 
                                    path: 'after_slots_all_tab_screenshot.png',
                                    fullPage: false
                                });
                            } else {
                                console.log('⚠️  Could not find "Slots (All)" tab: ' + (slotsTabResult.reason || 'Unknown reason'));
                            }
                        } catch (e) {
                            console.log('⚠️  Error clicking "Slots (All)" tab: ' + e.message);
                        }
                    } catch (e) {
                        console.log('⚠️  Error clicking Currency link: ' + e.message);
                        // 即使出錯，也繼續執行後續的 DOM 提取
                    }

                    // ========== 步驟 2：提取第一頁資料並獲取分頁資訊 ==========
                    console.log('📄 Step 2: Extracting first page and pagination info...');
                    
                    // 先檢查頁面上有哪些表格
                    const tableInfo = await page.evaluate(() => {
                        const allTables = Array.from(document.querySelectorAll('table'));
                        return allTables.map((table, idx) => ({
                            index: idx,
                            id: table.id || 'no-id',
                            className: table.className || 'no-class',
                            rowCount: table.querySelectorAll('tr').length,
                            hasDataTable: table.id && table.id.includes('DataTables')
                        }));
                    });

                    // 定義提取當前頁資料的函數
                    const extractCurrentPageData = async (accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                        return await page.evaluate((accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                        // 先處理表格資料
                        // 優先查找 DataTables_Table_0，如果找不到則使用所有表格
                        let allTables = [];
                        const dataTable0 = document.querySelector('#DataTables_Table_0');
                        if (dataTable0) {
                            allTables = [dataTable0];
                        } else {
                            allTables = Array.from(document.querySelectorAll('table'));
                        }
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
                                data: dataRows
                            };
                        }
                        
                        const result = {
                            // 基本頁面信息
                            pageInfo: {
                                title: document.title,
                                url: window.location.href,
                            },
                            
                            // 查詢參數
                            queryParams: {
                                accountNumber: accountNumberValue || null,
                                dateStart: dateStartValue || null,
                                dateEnd: dateEndValue || null
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
                                    // 如果提供了 account_number，只保留最後一個有資料的表格
                                    if (processedTables.length > 0) {
                                        // 找到最後一個有資料的表格（rowCount > 0）
                                        let lastTable = null;
                                        for (let i = processedTables.length - 1; i >= 0; i--) {
                                            if (processedTables[i].rowCount > 0) {
                                                lastTable = processedTables[i];
                                                break;
                                            }
                                        }
                                        
                                        if (lastTable) {
                                            return [lastTable];
                                        }
                                    }
                                    return [];
                                } else {
                                    // 否則，保留所有有資料的表格（不限制必須包含 Account number）
                                    const filtered = processedTables.filter(table => table.rowCount > 0);
                                    return filtered;
                                }
                            })()
                        };
                        
                        return result;
                        }, accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue);
                    };

                    // 定義檢查是否有下一頁的函數
                    const hasNextPage = async () => {
                        return await page.evaluate(() => {
                            const nextButton = document.querySelector('.paginate_button.next:not(.disabled)');
                            return nextButton !== null && !nextButton.classList.contains('disabled');
                        });
                    };

                    // 定義點擊下一頁的函數（用於查找帳號時翻頁）
                    const goToNextPageForAccount = async () => {
                        const nextPageInfo = await page.evaluate(() => {
                            const dataTablesNext = document.querySelector('.paginate_button.next:not(.disabled)');
                            if (dataTablesNext) {
                                const link = dataTablesNext.querySelector('a');
                                if (link) {
                                    const uniqueId = 'next-page-' + Date.now();
                                    link.setAttribute('data-puppeteer-id', uniqueId);
                                    return {
                                        found: true,
                                        selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                        text: link.textContent.trim()
                                    };
                                }
                            }
                            return { found: false };
                        });
                        
                        if (nextPageInfo.found) {
                            try {
                                await page.click(nextPageInfo.selector, { timeout: 5000 });
                                // 等待表格數據更新
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                return true;
                            } catch (e) {
                                return false;
                            }
                        }
                        return false;
                    };

                    // ========== 並發爬取分頁邏輯 ==========
                    // 解析參數
                    let accountNumberParsed = null;
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    
                    // 解析 account_number
                    if ($accountNumberJs && $accountNumberJs !== 'null' && $accountNumberJs !== '') {
                        try {
                            accountNumberParsed = JSON.parse($accountNumberJs);
                        } catch (e) {
                            accountNumberParsed = $accountNumberJs;
                        }
                    }
                    
                    // 解析 date_start
                    if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs !== '') {
                        try {
                            dateStartParsed = JSON.parse($dateStartJs);
                        } catch (e) {
                            dateStartParsed = $dateStartJs;
                        }
                    }
                    
                    // 解析 date_end
                    if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs !== '') {
                        try {
                            dateEndParsed = JSON.parse($dateEndJs);
                        } catch (e) {
                            dateEndParsed = $dateEndJs;
                        }
                    }
                    
                    // 判斷是否提供了 account_number
                    const accountNumberProvided = accountNumberParsed !== null && accountNumberParsed !== '' && accountNumberParsed !== undefined;
                    
                    // 確保表格已載入
                    await page.waitForSelector('tbody.dataContent tr', { timeout: 5000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // 提取第一頁的表格資料
                    const firstPageData = await extractCurrentPageData(accountNumberProvided, accountNumberParsed, dateStartParsed, dateEndParsed);
                    
                    if (!firstPageData || !firstPageData.tables || firstPageData.tables.length === 0) {
                        throw new Error('No table found on first page');
                    }
                    
                    // 獲取所有分頁資訊（DataTables 的分頁按鈕）
                    // 優先使用 DataTables API，再回退其他方式
                    const paginationInfo = await page.evaluate(() => {
                        const pageLinks = [];
                        let maxPageNumber = 1;
                        let currentPage = 1;

                        // 方法 1: 使用 DataTables API（最可靠）
                        try {
                            if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                const dt = window.$('#DataTables_Table_0').DataTable();
                                if (dt && dt.page && dt.page.info) {
                                    const info = dt.page.info();
                                    if (info) {
                                        maxPageNumber = info.pages || 1;
                                        currentPage = (info.page || 0) + 1;
                                        console.log('📊 DataTables API pages:', maxPageNumber, 'current:', currentPage);
                                    }
                                }
                            }
                        } catch (e) {
                            console.log('⚠️  DataTables API unavailable:', e.message);
                        }

                        // 方法 2: 從可見的頁碼按鈕中找最大頁碼（備援）
                        const paginationContainer = document.querySelector('#DataTables_Table_0_paginate') ||
                                                    document.querySelector('.dataTables_paginate') || 
                                                    document.querySelector('.pagination');
                        if (paginationContainer) {
                            const links = paginationContainer.querySelectorAll('.paginate_button:not(.previous):not(.next):not(.disabled)');
                            links.forEach(link => {
                                const pageText = link.textContent.trim();
                                const pageNum = parseInt(pageText);
                                if (!isNaN(pageNum) && pageText !== '…') {
                                    pageLinks.push({
                                        pageNumber: pageNum,
                                        url: window.location.href
                                    });
                                    if (pageNum > maxPageNumber) {
                                        maxPageNumber = pageNum;
                                    }
                                }
                            });

                            // 方法 3: 從 DataTables 信息文本中解析（Showing X to Y of Z entries）
                            const infoText = paginationContainer.parentElement?.querySelector('.dataTables_info') ||
                                             document.querySelector('.dataTables_info');
                            if (infoText && maxPageNumber === 1) {
                                const infoContent = infoText.textContent;
                                const match = infoContent.match(/Showing\s+\d+\s+to\s+(\d+)\s+of\s+(\d+)/i);
                                if (match) {
                                    const recordsPerPage = parseInt(match[1]);
                                    const totalRecords = parseInt(match[2]);
                                    if (recordsPerPage > 0 && totalRecords > 0) {
                                        const calculatedPages = Math.ceil(totalRecords / recordsPerPage);
                                        if (calculatedPages > maxPageNumber) {
                                            maxPageNumber = calculatedPages;
                                        }
                                    }
                                }
                            }
                        }

                        // 如果還是只有 1 頁，檢查是否有 Next 按鈕
                        if (maxPageNumber === 1) {
                            const nextLink = document.querySelector('#DataTables_Table_0_paginate .paginate_button.next:not(.disabled)') ||
                                             document.querySelector('.paginate_button.next:not(.disabled)');
                            if (nextLink) {
                                maxPageNumber = 2;
                            }
                        }

                        return {
                            totalPages: maxPageNumber,
                            currentPage: currentPage,
                            pageLinks: pageLinks,
                            currentUrl: window.location.href,
                            visiblePages: pageLinks.map(p => p.pageNumber).sort((a, b) => a - b)
                        };
                    });
                    
                    // ========== 步驟 3：串行爬取其他頁面（優化版本）==========
                    // 註：RSG 使用 DataTables（AJAX 分頁），串行比並發更快！
                    // 原因：
                    // 1. AJAX 切換頁面只需 1-2 秒，非常快
                    // 2. 並發需要為每頁重複前置操作（Currency/slim/account/date），反而更慢
                    // 3. 串行在已登錄的頁面上操作，避免重複操作
                    console.log('🚀 Step 3: Scraping remaining pages (optimized)...');
                    
                    // 提取單一頁面資料的函數（在 page 對象上操作，模擬點擊分頁按鈕）
                    const extractTableData = async (pageObject, accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                        return await pageObject.evaluate((accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue) => {
                            // 先處理表格資料
                            // 優先查找 DataTables_Table_0
                            let allTables = [];
                            const dataTable0 = document.querySelector('#DataTables_Table_0');
                            if (dataTable0) {
                                allTables = [dataTable0];
                            } else {
                                allTables = Array.from(document.querySelectorAll('table'));
                            }
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
                                    data: dataRows
                                };
                            }
                            
                            const result = {
                                tables: (() => {
                                    const processedTables = allTables.map((table, tableIndex) => processTable(table, tableIndex))
                                        .filter(table => {
                                            // 過濾掉只有表頭沒有資料的表格
                                            return table.rowCount > 0 || !table.isHeaderTable;
                                        });
                                    
                                    if (accountNumberProvided) {
                                        // 如果提供了 account_number，只保留最後一個有資料的表格
                                        if (processedTables.length > 0) {
                                            // 找到最後一個有資料的表格（rowCount > 0）
                                            let lastTable = null;
                                            for (let i = processedTables.length - 1; i >= 0; i--) {
                                                if (processedTables[i].rowCount > 0) {
                                                    lastTable = processedTables[i];
                                                    break;
                                                }
                                            }
                                            
                                            if (lastTable) {
                                                return [lastTable];
                                            }
                                        }
                                        return [];
                                    } else {
                                        // 否則，保留所有有資料的表格
                                        const filtered = processedTables.filter(table => table.rowCount > 0);
                                        return filtered;
                                    }
                                })()
                            };
                            
                            return result;
                        }, accountNumberProvided, accountNumberValue, dateStartValue, dateEndValue);
                    };
                    
                    // 使用 DataTables API 依次翻頁（優先），若失敗再回退 Next 按鈕點擊
                    const goToNextPage = async () => {
                        // 先獲取當前頁碼與總頁數
                        const currentInfo = await page.evaluate(() => {
                            let currentPage = 1;
                            let totalPages = 1;
                            try {
                                if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                    const dt = window.$('#DataTables_Table_0').DataTable();
                                    if (dt && dt.page && dt.page.info) {
                                        const info = dt.page.info();
                                        currentPage = (info.page || 0) + 1;
                                        totalPages = info.pages || 1;
                                    }
                                }
                            } catch (e) {}
                            const hasNextButton = !!(document.querySelector('#DataTables_Table_0_paginate .paginate_button.next:not(.disabled)') ||
                                                     document.querySelector('.paginate_button.next:not(.disabled)'));
                            return { currentPage, totalPages, hasNextButton };
                        });

                        if (!currentInfo.hasNextButton || currentInfo.currentPage >= currentInfo.totalPages) {
                            return { success: false, hasNext: false, currentPage: currentInfo.currentPage };
                        }

                        // 優先使用 DataTables API 翻頁
                        const apiResult = await page.evaluate(() => {
                            try {
                                if (typeof window.$ !== 'undefined' && window.$('#DataTables_Table_0').length) {
                                    const dt = window.$('#DataTables_Table_0').DataTable();
                                    if (dt && dt.page) {
                                        dt.page('next').draw('page');
                                        return { success: true, method: 'api' };
                                    }
                                }
                            } catch (e) {
                                return { success: false, error: e.message };
                            }
                            return { success: false };
                        });

                        if (!apiResult.success) {
                            // 回退：點擊 Next 按鈕
                            const clickResult = await page.evaluate(() => {
                                const nextButton = document.querySelector('#DataTables_Table_0_paginate .paginate_button.next:not(.disabled)') ||
                                                   document.querySelector('.paginate_button.next:not(.disabled)');
                                if (nextButton) {
                                    nextButton.click();
                                    const link = nextButton.querySelector('a');
                                    if (link) {
                                        link.click();
                                    }
                                    return { success: true, method: 'click' };
                                }
                                return { success: false };
                            });
                            if (!clickResult.success) {
                                return { success: false, hasNext: false, currentPage: currentInfo.currentPage };
                            }
                        }

                        // 等待頁碼變化
                        try {
                            await page.waitForFunction(
                                (oldPage) => {
                                    const active = document.querySelector('#DataTables_Table_0_paginate .paginate_button.active') ||
                                                   document.querySelector('.paginate_button.active');
                                    const newPage = active ? parseInt(active.textContent.trim()) : 1;
                                    if (!isNaN(newPage)) return newPage > oldPage;
                                    return false;
                                },
                                { timeout: 5000 },
                                currentInfo.currentPage
                            );
                        } catch (e) {
                            console.log('⚠️  Wait for page change timeout, continue');
                        }

                        // 再次讀取當前頁碼
                        const newPageNum = await page.evaluate(() => {
                            const active = document.querySelector('#DataTables_Table_0_paginate .paginate_button.active') ||
                                           document.querySelector('.paginate_button.active');
                            const newPage = active ? parseInt(active.textContent.trim()) : 1;
                            return isNaN(newPage) ? 1 : newPage;
                        });

                        if (newPageNum > currentInfo.currentPage) {
                            return { success: true, hasNext: true, currentPage: newPageNum };
                        }

                        return { success: false, hasNext: false, currentPage: newPageNum };
                    };
                    
                    // 串行爬取所有其他頁面（使用 Next 按鈕）
                    const otherPagesData = [];
                    let currentPage = 1;
                    let maxPagesToScrape = paginationInfo.totalPages;
                    
                    // 繼續點擊 Next 按鈕直到沒有下一頁
                    let scrapeCount = 0;
                    
                    while (scrapeCount < maxPagesToScrape) {
                        const nextResult = await goToNextPage();
                        
                        if (!nextResult.success || !nextResult.hasNext) {
                            break;
                        }
                        
                        currentPage = nextResult.currentPage || (currentPage + 1);
                        scrapeCount++;
                        
                        // 提取當前頁面的表格資料
                        const tableData = await extractTableData(page, accountNumberProvided, accountNumberParsed, dateStartParsed, dateEndParsed);
                        
                        if (tableData.tables && tableData.tables.length > 0) {
                            otherPagesData.push({
                                pageNumber: currentPage,
                                tables: tableData.tables || []
                            });
                        }
                    }
                    
                    // 合併第一頁和其他頁面的資料
                    const allPagesData = [
                        {
                            pageNumber: 1,
                            tables: firstPageData.tables || []
                        },
                        ...otherPagesData
                    ];
                    
                    // 獲取當前頁面信息
                    const currentPageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });
                    
                    // 合併所有分頁的資料
                    const mergedDomData = {
                        pageInfo: firstPageData.pageInfo || currentPageInfo,
                        queryParams: {
                            accountNumber: accountNumberParsed,
                            dateStart: dateStartParsed,
                            dateEnd: dateEndParsed
                        },
                        totalPages: allPagesData.length,
                        // 合併所有分頁的表格資料
                        tables: (() => {
                            if (allPagesData.length === 0) {
                                return [];
                            }
                            
                            // 如果提供了 account_number，合併所有分頁的最後一個表格
                            if (accountNumberProvided) {
                                const mergedTables = [];
                                
                                // 為每個分頁找到最後一個有資料的表格
                                allPagesData.forEach((pageData) => {
                                    const tables = pageData.tables || [];
                                    
                                    if (tables.length > 0) {
                                        // 找到最後一個有資料的表格
                                        let lastTable = null;
                                        for (let i = tables.length - 1; i >= 0; i--) {
                                            if (tables[i].rowCount > 0) {
                                                lastTable = tables[i];
                                                break;
                                            }
                                        }
                                        
                                        if (lastTable) {
                                            // 為資料添加頁碼標記
                                            const tableWithPageInfo = {
                                                ...lastTable,
                                                data: lastTable.data.map(row => ({
                                                    ...row,
                                                    _pageNumber: pageData.pageNumber
                                                }))
                                            };
                                            mergedTables.push(tableWithPageInfo);
                                        }
                                    }
                                });
                                
                                // 合併所有表格的數據到一個表格中
                                if (mergedTables.length > 0) {
                                    const firstTable = mergedTables[0];
                                    const allData = [];
                                    
                                    mergedTables.forEach((table) => {
                                        allData.push(...table.data);
                                    });
                                    
                                    return [{
                                        ...firstTable,
                                        headers: firstTable.headers,
                                        headerCount: firstTable.headerCount,
                                        rowCount: allData.length,
                                        data: allData,  // 所有頁面的數據都合併到這裡
                                    }];
                                }
                                
                                return [];
                            } else {
                                // 否則，合併所有表格
                                const mergedTables = [];
                                
                                allPagesData.forEach((pageData) => {
                                    const tables = pageData.tables || [];
                                    tables.forEach(table => {
                                        // 只保留有數據的表格
                                        if (table.rowCount > 0 && table.data && table.data.length > 0) {
                                            const tableWithPageInfo = {
                                                ...table,
                                                data: table.data.map(row => ({
                                                    ...row,
                                                    _pageNumber: pageData.pageNumber
                                                }))
                                            };
                                            mergedTables.push(tableWithPageInfo);
                                        }
                                    });
                                });
                                
                                // 如果有多個表格，合併成一個
                                if (mergedTables.length > 1) {
                                    const firstTable = mergedTables[0];
                                    const allData = [];
                                    
                                    mergedTables.forEach((table) => {
                                        allData.push(...table.data);
                                    });
                                    
                                    return [{
                                        ...firstTable,
                                        rowCount: allData.length,
                                        data: allData,  // 所有頁面的數據都合併到這裡
                                    }];
                                } else if (mergedTables.length === 1) {
                                    // 即使只有一個表格，也要確保數據已合併
                                    return mergedTables;
                                }
                                
                                return [];
                            }
                        })()
                    };
                    
                    const domData = mergedDomData;
                    
                    // 截圖：最終頁面（所有數據爬取完成後）
                    await page.screenshot({ 
                        path: 'final_page_screenshot.png',
                        fullPage: false
                    });

                    // 合併所有提取的資料和捕獲的 DOM 資料
                    // accountNumberParsed, dateStartParsed 和 dateEndParsed 已經在上面定義過了
                    const result = {
                        timestamp: new Date().toISOString(),  // 時間戳
                        url: '$url',  // 目標 URL
                        queryParams: {
                            accountNumber: accountNumberParsed,
                            dateStart: dateStartParsed,
                            dateEnd: dateEndParsed
                        },
                        domData: domData,  // 捕獲的 DOM 資料
                        success: true  // 成功標記
                    };

                    // 將結果保存為 JSON 文件
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));

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
                ],
                'table' => [
                    'headers' => $headers,
                    'headerCount' => count($headers),
                    'totalPages' => $domData['totalPages'] ?? 1,
                    'rowCount' => $totalRows,
                    'data' => $allData  // 所有頁面的數據都在這裡
                ]
            ];
            
            // 保存合併後的數據到單一 JSON 文件
            $mergedFileName = "scraped_data/dom_merged_all_pages_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // 將截圖從臨時目錄移動到永久存儲目錄
        // 1. After account screenshot (如果存在)
        $afterAccountSrc = storage_path('app/temp/after_account_screenshot.png');
        $afterAccountDst = storage_path("app/scraped_data/after_account_{$timestamp}.png");
        
        if (file_exists($afterAccountSrc)) {
            rename($afterAccountSrc, $afterAccountDst);
            $this->info("📸 After account screenshot saved to: {$afterAccountDst}");
        }
        
        // 2. After date input screenshot (如果存在)
        $afterDateInputSrc = storage_path('app/temp/after_date_input_screenshot.png');
        $afterDateInputDst = storage_path("app/scraped_data/after_date_input_{$timestamp}.png");
        
        if (file_exists($afterDateInputSrc)) {
            rename($afterDateInputSrc, $afterDateInputDst);
            $this->info("📸 After date input screenshot saved to: {$afterDateInputDst}");
        }
        
        // 3. After Slots (All) tab screenshot (如果存在)
        $afterSlotsAllSrc = storage_path('app/temp/after_slots_all_tab_screenshot.png');
        $afterSlotsAllDst = storage_path("app/scraped_data/after_slots_all_tab_{$timestamp}.png");
        
        if (file_exists($afterSlotsAllSrc)) {
            rename($afterSlotsAllSrc, $afterSlotsAllDst);
            $this->info("📸 After Slots (All) tab screenshot saved to: {$afterSlotsAllDst}");
        }
        
        // 4. Final page screenshot
        $finalScreenshotSrc = storage_path('app/temp/final_page_screenshot.png');
        $finalScreenshotDst = storage_path("app/scraped_data/final_page_{$timestamp}.png");
        
        if (file_exists($finalScreenshotSrc)) {
            rename($finalScreenshotSrc, $finalScreenshotDst);
            $this->info("📸 Final page screenshot saved to: {$finalScreenshotDst}");
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}

