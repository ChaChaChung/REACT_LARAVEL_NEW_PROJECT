<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserBlodplayDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-blodplay-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date?} - 要選擇的日期（可選參數）
     * {account_number?} - 要選擇的帳號（可選參數）
     * {--concurrency=4} - 併發數量（可選，預設為 4）
     */
    protected $signature = 'agent:scrape-blodplay-dom-detail {url} {date?} {account_number?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from Blodplay DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $date = $this->argument('date');
        $accountNumber = $this->argument('account_number');

        $this->info('=== Blodplay DOM Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Date: {$date}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 環境
        if (!$this->checkNodeJs()) {
            return 1;
        }
        
        // 獲取併發數量
        $concurrency = (int) $this->option('concurrency');

        // 創建並執行 Puppeteer 腳本（爬取表格數據）
        $scriptPath = $this->createPuppeteerScript($url, $date, $concurrency);
        $result = $this->runPuppeteerScript($scriptPath);

        if ($result) {
            $this->processScrapedData($result);
            $this->info('End of command at: ' . date('Y-m-d H:i:s'));
            $this->info("✅ Data scraping completed!");
            return 0;
        }

        $this->error('❌ Failed to scrape data');
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
     * @param string|null $date 要選擇的日期（可選，單個日期）
     * @param int $concurrency 併發數量
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取 Blodplay 登入流程程式碼片段（主頁面用）
        $cookiesCodeForPage = $this->generateBlodplayPuppeteerLoginCode('page');
        // 獲取認證 cookies 程式碼片段（併發頁面用，登入後可以重用 cookies）
        $cookiesCodeForNewPage = $this->generateBlodplayPuppeteerLoginCode('newPage');

        // 將 date 轉換為 JavaScript 可用的格式（單個日期）
        // 如果 date 是 YYYYMMDD 格式，轉換為 YYYY-MM-DD
        $dateFormatted = null;
        if ($date) {
            if (strlen($date) === 8 && is_numeric($date)) {
                // YYYYMMDD 格式
                $year = substr($date, 0, 4);
                $month = substr($date, 4, 2);
                $day = substr($date, 6, 2);
                $dateFormatted = "{$year}-{$month}-{$day}";
            } else {
                // 嘗試其他格式
                $timestamp = strtotime($date);
                if ($timestamp !== false) {
                    $dateFormatted = date('Y-m-d', $timestamp);
                } else {
                    $dateFormatted = $date;
                }
            }
        }
        $dateStartJs = $dateFormatted ? json_encode($dateFormatted) : 'null';

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

                    $cookiesCodeForPage

                    // 輔助函數：截圖
                    async function takeScreenshot(stepName, pageObj = page) {
                        try {
                            const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
                            const filename = 'step_' + stepName + '_' + timestamp + '.png';
                            await pageObj.screenshot({ 
                                path: filename,
                                fullPage: false
                            });
                            console.log('📸 Screenshot saved: ' + filename);
                        } catch (e) {
                            console.log('⚠️  Failed to take screenshot for step ' + stepName + ': ' + e.message);
                        }
                    }

                    // 導航到目標頁面（確保頁面已經載入，才能訪問 localStorage）
                    console.log('🌐 Navigating to target URL:', '$url');
                    await page.goto('$url', {
                        waitUntil: 'domcontentloaded',
                        timeout: 30000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    /**
                     * 恢復 LocalStorage 到指定頁面
                     * @param {Page} targetPage - 目標頁面對象
                     * @param {Object} storageData - 要恢復的 LocalStorage 數據
                     */
                    async function restoreLocalStorage(targetPage, storageData) {
                        try {
                            await targetPage.evaluate((data) => {
                                // 檢查頁面 URL 是否有效（不是 about:blank 或 data: URL）
                                if (window.location.href === 'about:blank' || window.location.href.startsWith('data:')) {
                                    console.log('⚠️  Cannot restore localStorage: invalid page URL');
                                    return;
                                }
                                
                                try {
                                    // 清空現有的 LocalStorage
                                    window.localStorage.clear();
                                    // 恢復保存的數據
                                    Object.keys(data).forEach(key => {
                                        window.localStorage.setItem(key, data[key]);
                                    });
                                } catch (e) {
                                    console.error('Error restoring localStorage:', e.message);
                                }
                            }, storageData);
                        } catch (e) {
                            console.log('⚠️  Failed to restore localStorage:', e.message);
                        }
                    }
                    
                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            // console.log('❌ Browser console error:', msg.text());
                        }
                    });
                    
                    try {
                        // 優先使用 date 參數（單個日期）
                        const dateJs = $dateStartJs && $dateStartJs !== 'null' ? $dateStartJs : null;
                        if (dateJs) {
                            dateParsed = JSON.parse(dateJs);
                        }
                    } catch (e) {
                        dateParsed = $dateStartJs !== 'null' ? $dateStartJs : null;
                    }
                    
                    // 如果提供了 date，處理 Material-UI 日期選擇器
                    if (dateParsed && dateParsed !== null && dateParsed !== '') {
                        try {
                            console.log('📅 Processing Material-UI date picker for date: ' + dateParsed);
                            
                            // 等待 Material-UI 日期選擇器輸入框出現
                            // 查找包含 MuiInputBase-input 和 Mui-readOnly 類的 input
                            // 或者查找包含日期範圍格式的 input（例如：2026-01-01 00:00:00 ⇢ 2026-01-01 23:59:59）
                            await page.waitForSelector('input.MuiInputBase-input.Mui-readOnly, input[class*="MuiInputBase-input"][readonly], input[readonly][class*="MuiInputBase"]', { timeout: 10000 });
                            
                            // 點擊打開日期選擇器
                            const dateInputFound = await page.evaluate(() => {
                                // 查找所有可能的日期選擇器輸入框
                                const inputs = Array.from(document.querySelectorAll('input[readonly], input.Mui-readOnly'));
                                for (let input of inputs) {
                                    // 檢查是否包含日期範圍格式（包含 ⇢ 符號）
                                    const value = input.value || '';
                                    if (value.includes('⇢') || value.includes('→') || input.className.includes('MuiInputBase')) {
                                        input.click();
                                        return true;
                                    }
                                }
                                return false;
                            });
                            
                            if (!dateInputFound) {
                                // 如果找不到，嘗試使用選擇器點擊
                                const dateInput = await page.$('input.MuiInputBase-input.Mui-readOnly, input[class*="MuiInputBase-input"][readonly]');
                                if (dateInput) {
                                    await dateInput.click();
                                } else {
                                    await page.click('input[readonly][class*="MuiInputBase"]', { timeout: 5000 });
                                }
                            }
                            console.log('✅ Clicked date picker input');
                            
                            // 步驟 4: 點擊日期選擇器後截圖
                            await takeScreenshot('04_after_click_date_picker');
                            
                            // 等待日期選擇面板出現
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 設置日期：將單個日期設置為開始和結束日期（同一天）
                            // 日期格式應該是 YYYY-MM-DD
                            const dateFormatted = dateParsed; // 假設 dateParsed 已經是 YYYY-MM-DD 格式
                            
                            // 等待日期選擇器面板完全載入
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 使用 page.evaluate 來設置日期選擇器中的日期
                            const dateSetResult = await page.evaluate((targetDate) => {
                                // 解析目標日期
                                const [year, month, day] = targetDate.split('-').map(Number);
                                
                                // 方法1: 查找 Material-UI 日曆中的日期按鈕
                                // Material-UI 日期選擇器通常使用 button[role="gridcell"] 或類似的結構
                                const calendarButtons = document.querySelectorAll('button[role="gridcell"], button[class*="MuiPickersDay"], button[class*="day"]');
                                let dateSet = false;
                                
                                for (let btn of calendarButtons) {
                                    const btnText = btn.textContent.trim();
                                    const btnDate = parseInt(btnText);
                                    // 檢查是否匹配目標日期
                                    if (!isNaN(btnDate) && btnDate === day) {
                                        // 檢查按鈕是否可用（不是禁用狀態）
                                        if (!btn.disabled && !btn.classList.contains('Mui-disabled')) {
                                            btn.click();
                                            dateSet = true;
                                            break;
                                        }
                                    }
                                }
                                
                                // 方法2: 如果找不到按鈕，嘗試查找日期輸入框
                                if (!dateSet) {
                                    const dateInputs = document.querySelectorAll('input[type="text"], input[type="date"]');
                                    for (let input of dateInputs) {
                                        const placeholder = input.placeholder || '';
                                        const label = input.getAttribute('aria-label') || '';
                                        const name = input.name || '';
                                        if (placeholder.toLowerCase().includes('date') || 
                                            label.toLowerCase().includes('date') ||
                                            name.toLowerCase().includes('date') ||
                                            input.className.includes('date')) {
                                            input.value = targetDate;
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            dateSet = true;
                                            break;
                                        }
                                    }
                                }
                                
                                return { success: dateSet, date: targetDate, day: day };
                            }, dateFormatted);
                            
                            console.log('📅 Date set result: ' + JSON.stringify(dateSetResult));
                            
                            // 如果日期設置失敗，等待一下再重試
                            if (!dateSetResult.success) {
                                console.log('⚠️  Date setting failed, waiting and retrying...');
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            }
                            
                            // 等待一下確保日期設置完成
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 步驟 5: 設置日期後截圖
                            await takeScreenshot('05_after_set_date');
                            
                            // 設置 End Time 為 23:59:59
                            console.log('⏰ Setting End Time to 23:59:59...');
                            
                            // 步驟 6: 設置結束時間前截圖
                            await takeScreenshot('06_before_set_end_time');
                            
                            const endTimeSet = await page.evaluate(() => {
                                // 查找 End Time 的 input
                                // 根據提供的 HTML 結構：
                                // <div class="flex flex-col MuiBox-root mui-0">
                                //   <span class="MuiTypography-root MuiTypography-caption mb-1 mui-ygjr3i">End Time</span>
                                //   <div class="flex gap-2 MuiBox-root mui-0">
                                //     <input colon=":" class="font-mono" type="text" value="00:00:00">
                                
                                // 方法1: 通過 span 標籤查找
                                const endTimeLabels = Array.from(document.querySelectorAll('span.MuiTypography-caption, span[class*="MuiTypography"]'));
                                let endTimeInput = null;
                                
                                for (let label of endTimeLabels) {
                                    const labelText = label.textContent.trim();
                                    if (labelText === 'End Time' || labelText.includes('End Time')) {
                                        // 在同一個父容器中查找 input
                                        const parent = label.closest('div.flex.flex-col, div[class*="flex"]');
                                        if (parent) {
                                            // 查找 class 包含 font-mono 的 input
                                            endTimeInput = parent.querySelector('input.font-mono, input[class*="font-mono"]');
                                            if (endTimeInput) break;
                                            
                                            // 如果找不到，查找所有 input
                                            const inputs = parent.querySelectorAll('input[type="text"]');
                                            if (inputs.length > 0) {
                                                // 通常 End Time 的 input 在第二個或最後一個
                                                endTimeInput = inputs[inputs.length - 1];
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // 方法2: 如果方法1失敗，直接查找所有 font-mono 的 input，選擇最後一個（通常是 End Time）
                                if (!endTimeInput) {
                                    const allTimeInputs = Array.from(document.querySelectorAll('input.font-mono, input[class*="font-mono"]'));
                                    if (allTimeInputs.length > 0) {
                                        // 選擇最後一個（通常是 End Time）
                                        endTimeInput = allTimeInputs[allTimeInputs.length - 1];
                                    }
                                }
                                
                                if (endTimeInput) {
                                    // 聚焦並選中所有文字
                                    endTimeInput.focus();
                                    endTimeInput.select();
                                    
                                    // 設置時間為 23:59:59
                                    endTimeInput.value = '23:59:59';
                                    
                                    // 觸發多種事件確保值被正確設置
                                    endTimeInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    endTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
                                    endTimeInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                    
                                    // 檢查是否需要點擊 PM 按鈕
                                    // 查找同一個父容器中的 PM 按鈕
                                    const timeContainer = endTimeInput.closest('div.flex.gap-2, div[class*="flex"]');
                                    if (timeContainer) {
                                        const pmButton = timeContainer.querySelector('div[class*="cursor-pointer"]:last-child, div:last-child');
                                        if (pmButton && pmButton.classList.contains('cursor-pointer')) {
                                            // 檢查 PM 按鈕是否已選中（bg-primary 表示已選中）
                                            if (!pmButton.classList.contains('bg-primary')) {
                                                // 如果 PM 按鈕沒有被選中，點擊它
                                                pmButton.click();
                                            }
                                        }
                                    }
                                    
                                    return { success: true, value: endTimeInput.value };
                                }
                                
                                return { success: false, error: 'End Time input not found' };
                            });
                            
                            console.log('⏰ End Time set result: ' + JSON.stringify(endTimeSet));
                            
                            // 步驟 7: 設置結束時間後截圖
                            await takeScreenshot('07_after_set_end_time');
                            
                            // 等待一下確保時間設置完成
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 查找並點擊確認/應用按鈕（Material-UI 日期選擇器通常有 OK 或 Apply 按鈕）
                            const confirmButton = await page.evaluate(() => {
                                // 查找可能的確認按鈕
                                const buttons = Array.from(document.querySelectorAll('button'));
                                let confirmBtn = buttons.find(btn => {
                                    const text = (btn.textContent || '').trim().toLowerCase();
                                    return text === 'ok' || text === 'apply' || text === '確認' || text === '確定';
                                });
                                
                                if (confirmBtn) {
                                    const uniqueId = 'confirm-btn-' + Date.now();
                                    confirmBtn.setAttribute('data-puppeteer-id', uniqueId);
                                    return {
                                        found: true,
                                        selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                        text: confirmBtn.textContent.trim()
                                    };
                                }
                                
                                // 如果找不到，嘗試查找關閉按鈕或點擊外部區域來關閉選擇器
                                return { found: false };
                            });

                            if (confirmButton.found) {
                                await page.click(confirmButton.selector, { timeout: 5000 });
                                console.log('✅ Clicked confirm button: ' + confirmButton.text);
                            } else {
                                // 如果找不到確認按鈕，嘗試點擊外部區域或按 ESC 鍵來關閉選擇器
                                await page.keyboard.press('Escape');
                                console.log('✅ Pressed ESC to close date picker');
                            }
                            
                            // 步驟 8: 點擊確認按鈕後截圖
                            await takeScreenshot('08_after_click_confirm');
                            
                            // 等待日期選擇器關閉
                            await new Promise(resolve => setTimeout(resolve, 1500));
                                
                            // 查找並點擊 Search 按鈕（如果有的話）
                                const searchButton = await page.evaluate(() => {
                                const buttons = Array.from(document.querySelectorAll('button'));
                                let searchBtn = buttons.find(btn => {
                                    const text = (btn.textContent || '').trim().toLowerCase();
                                    return text.includes('search') || text.includes('搜尋') || text.includes('查詢');
                                });
                                
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

                            if (searchButton.found) {
                                await page.click(searchButton.selector, { timeout: 5000 });
                                console.log('✅ Clicked search button: ' + searchButton.text);
                                
                                // 步驟 9: 點擊搜索按鈕後截圖
                                await takeScreenshot('09_after_click_search');
                                
                                // 等待搜索結果載入
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                
                                // 等待表格出現（如果有的話）
                                await page.waitForSelector('table', { timeout: 10000 }).catch(() => {
                                    console.log('⚠️  Table not found, continuing...');
                                });
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                // 步驟 10: 等待表格載入後截圖
                                await takeScreenshot('10_after_table_loaded');
                                    
                                // 提取分頁信息
                                const paginationInfo = await page.evaluate(() => {
                                    const pagination = document.querySelector('div.el-pagination');
                                    if (!pagination) {
                                        return { found: false, error: 'Pagination not found' };
                                    }
                                    
                                    // 獲取總數
                                    const totalSpan = pagination.querySelector('span.el-pagination__total');
                                    const totalText = totalSpan ? totalSpan.textContent.trim() : '';
                                    const totalMatch = totalText.match(/Total\s+(\d+)/);
                                    const total = totalMatch ? parseInt(totalMatch[1]) : 0;
                                    
                                    // 獲取當前頁
                                    const activePage = pagination.querySelector('li.number.active');
                                    const currentPage = activePage ? parseInt(activePage.textContent.trim()) : 1;
                                    
                                    // 獲取所有頁碼
                                    const pageNumbers = [];
                                    const pageItems = pagination.querySelectorAll('li.number');
                                    pageItems.forEach(item => {
                                        if (!item.classList.contains('more')) {
                                            const pageNum = parseInt(item.textContent.trim());
                                            if (!isNaN(pageNum)) {
                                                pageNumbers.push(pageNum);
                                            }
                                        }
                                    });
                                    
                                    // 獲取最後一頁的頁碼（從分頁器中）
                                    let lastPage = currentPage;
                                    if (pageNumbers.length > 0) {
                                        lastPage = Math.max(...pageNumbers);
                                    }
                                    
                                    // 如果有 "more" 按鈕，嘗試獲取最後一頁
                                    const lastPageItem = pagination.querySelector('li.number:last-child');
                                    if (lastPageItem && !lastPageItem.classList.contains('more')) {
                                        const lastPageNum = parseInt(lastPageItem.textContent.trim());
                                        if (!isNaN(lastPageNum)) {
                                            lastPage = lastPageNum;
                                        }
                                    }
                                    
                                    return {
                                        found: true,
                                        total: total,
                                        currentPage: currentPage,
                                        lastPage: lastPage,
                                        pageNumbers: pageNumbers
                                    };
                                });
                                
                                // 步驟 11: 提取分頁信息後截圖
                                await takeScreenshot('11_after_extract_pagination');
                                    
                                    // 提取表格資料的函數（可重用）
                                    const extractTableData = async () => {
                                        return await page.evaluate(() => {
                                            const table = document.querySelector('table');
                                            if (!table) {
                                                return { found: false, error: 'Table not found' };
                                            }
                                            
                                            // 提取表頭
                                            const headers = [];
                                            const thead = table.querySelector('thead');
                                            if (thead) {
                                                const headerRow = thead.querySelector('tr');
                                                if (headerRow) {
                                                    const headerCells = headerRow.querySelectorAll('th');
                                                    headerCells.forEach(cell => {
                                                        const span = cell.querySelector('span span');
                                                        const headerText = span ? span.textContent.trim() : cell.textContent.trim();
                                                        if (headerText) {
                                                            headers.push(headerText);
                                                        }
                                                    });
                                                }
                                            }
                                            
                                            // 提取資料行
                                            const tbody = table.querySelector('tbody#ele-table-body');
                                            if (!tbody) {
                                                return { found: false, error: 'Table body not found' };
                                            }
                                            
                                            const rows = [];
                                            const allRows = Array.from(tbody.querySelectorAll('tr'));
                                            
                                            for (let i = 0; i < allRows.length; i++) {
                                                const row = allRows[i];
                                                
                                                // 跳過 detail-row（詳細信息行）
                                                if (row.classList.contains('detail-row')) {
                                                    continue;
                                                }
                                                
                                                // 跳過 Subtotal 行
                                                const firstCell = row.querySelector('td');
                                                if (firstCell) {
                                                    const firstCellText = firstCell.textContent.trim();
                                                    if (firstCellText === 'Subtotal') {
                                                        continue;
                                                    }
                                                }
                                                
                                                // 提取主行數據
                                                const cells = row.querySelectorAll('td');
                                                const rowData = {};
                                                
                                                cells.forEach((cell, index) => {
                                                    // 獲取實際文本內容
                                                    const div = cell.querySelector('div.inner-row--1');
                                                    let cellText = '';
                                                    
                                                    if (div) {
                                                        // 檢查是否有嵌套的 div
                                                        const innerDiv = div.querySelector('div');
                                                        if (innerDiv) {
                                                            cellText = innerDiv.textContent.trim();
                                                        } else {
                                                            cellText = div.textContent.trim();
                                                        }
                                                    } else {
                                                        cellText = cell.textContent.trim();
                                                    }
                                                    
                                                    // 使用表頭作為 key，如果沒有表頭則使用索引
                                                    if (headers[index]) {
                                                        // 清理字段名
                                                        let cleanHeader = headers[index]
                                                            .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                            .replace(/^_+|_+$/g, '');
                                                        
                                                        if (!cleanHeader) {
                                                            cleanHeader = 'column_' + index;
                                                        }
                                                        
                                                        // 確保字段名唯一
                                                        let finalHeader = cleanHeader;
                                                        let counter = 1;
                                                        while (rowData.hasOwnProperty(finalHeader)) {
                                                            finalHeader = cleanHeader + '_' + counter;
                                                            counter++;
                                                        }
                                                        
                                                        rowData[finalHeader] = cellText;
                                                    } else {
                                                        rowData['column_' + index] = cellText;
                                                    }
                                                });
                                                
                                                // 檢查下一行是否是 detail-row
                                                if (i + 1 < allRows.length && allRows[i + 1].classList.contains('detail-row')) {
                                                    const detailRow = allRows[i + 1];
                                                    const detailGrid = detailRow.querySelector('div.detail-grid');
                                                    
                                                    if (detailGrid) {
                                                        const detailItems = detailGrid.querySelectorAll('div.detail-item');
                                                        const details = {};
                                                        
                                                        detailItems.forEach(item => {
                                                            const label = item.querySelector('div.detail-label');
                                                            const value = item.querySelector('div.detail-value');
                                                            
                                                            if (label && value) {
                                                                const labelText = label.textContent.trim().replace(':', '');
                                                                let cleanLabel = labelText
                                                                    .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                                    .replace(/^_+|_+$/g, '');
                                                                
                                                                // 獲取值（可能包含按鈕，只取文本）
                                                                let valueText = value.textContent.trim();
                                                                
                                                                // 如果值包含按鈕，標記為有按鈕
                                                                const button = value.querySelector('button');
                                                                if (button) {
                                                                    valueText = valueText.replace(button.textContent.trim(), '').trim();
                                                                    details[cleanLabel + '_hasButton'] = true;
                                                                }
                                                                
                                                                details[cleanLabel] = valueText;
                                                            }
                                                        });
                                                        
                                                        rowData.details = details;
                                                    }
                                                    
                                                    // 跳過 detail-row
                                                    i++;
                                                }
                                                
                                                if (Object.keys(rowData).length > 0) {
                                                    rows.push(rowData);
                                                }
                                            }
                                            
                                            return {
                                                found: true,
                                                headers: headers,
                                                rowCount: rows.length,
                                                data: rows
                                            };
                                        });
                                    };
                                    
                                // ========== 步驟 1：爬取第一頁 ==========
                                console.log('📄 Step 1: Extracting first page data...');
                                const firstPageData = await extractTableData();
                                
                                // 步驟 12: 提取第一頁數據後截圖
                                await takeScreenshot('12_after_extract_first_page');
                                    
                                    // ========== 步驟 2：爬取所有其他頁面 ==========
                                    let allPagesData = [];
                                    let allRows = [];
                                    let headers = firstPageData.headers || [];
                                    
                                    if (firstPageData.found) {
                                        allPagesData.push({
                                            pageNumber: 1,
                                            rowCount: firstPageData.rowCount,
                                            data: firstPageData.data
                                        });
                                        allRows = allRows.concat(firstPageData.data);
                                    }
                                    
                                // 如果有分頁，使用併發爬取其他頁面
                                if (paginationInfo.found && paginationInfo.lastPage > 1) {
                                    console.log('📄 Step 2: Starting concurrent scraping for pages 2 to ' + paginationInfo.lastPage + '...');
                                    
                                    // 步驟 13: 開始併發爬取前截圖
                                    await takeScreenshot('13_before_concurrent_scraping');
                                        
                                        // 創建要爬取的頁面列表
                                        const pagesToScrape = [];
                                        for (let i = 2; i <= paginationInfo.lastPage; i++) {
                                            pagesToScrape.push(i);
                                        }
                                        
                                        /**
                                         * 併發爬取單個頁面的函數
                                         * @param {Number} pageNum - 要爬取的頁碼
                                         * @param {Number} index - 索引（用於日誌）
                                         */
                                        async function scrapePageConcurrently(pageNum, index) {
                                            const newPage = await browser.newPage();
                                            
                                            try {
                                                // 設定視窗大小
                                                await newPage.setViewport({ width: 1920, height: 1080 });
                                                
                                                // 設定 User Agent
                                                await newPage.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
                                                
                                                // 攔截並阻止不必要的資源載入
                                                await newPage.setRequestInterception(true);
                                                newPage.on('request', (req) => {
                                                    const resourceType = req.resourceType();
                                                    if (['image', 'font', 'media'].includes(resourceType)) {
                                                        req.abort();
                                                    } else {
                                                        req.continue();
                                                    }
                                                });
                                                
                                                // 導航到目標 URL
                                                await newPage.goto('$url', {
                                                    waitUntil: 'domcontentloaded',
                                                    timeout: 30000
                                                });
                                                
                                                // 等待頁面載入
                                                await new Promise(resolve => setTimeout(resolve, 2000));
                                                
                                                // 恢復 LocalStorage（恢復登入狀態）
                                                await restoreLocalStorage(newPage, savedLocalStorage);
                                                
                                                // 等待一下確保 LocalStorage 已恢復
                                                await new Promise(resolve => setTimeout(resolve, 500));
                                                
                                                // 重新載入頁面以應用 LocalStorage
                                                await newPage.reload({ waitUntil: 'domcontentloaded' });
                                                await new Promise(resolve => setTimeout(resolve, 2000));
                                                
                                            // 設置日期（Material-UI 日期選擇器）
                                            if (dateParsed && dateParsed !== null && dateParsed !== '') {
                                                    try {
                                                    // 等待 Material-UI 日期選擇器輸入框出現
                                                    await newPage.waitForSelector('input.MuiInputBase-input.Mui-readOnly', { timeout: 10000 });
                                                        
                                                        // 點擊打開日期選擇器
                                                    const dateInput = await newPage.$('input.MuiInputBase-input.Mui-readOnly');
                                                    if (dateInput) {
                                                        await dateInput.click();
                                                    } else {
                                                        await newPage.click('input[class*="MuiInputBase-input"][readonly]', { timeout: 5000 });
                                                    }
                                                    
                                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                                    
                                                    // 設置日期
                                                    const dateFormatted = dateParsed;
                                                    await newPage.evaluate((targetDate) => {
                                                        const dateInputs = document.querySelectorAll('input[type="text"], input[type="date"]');
                                                        for (let input of dateInputs) {
                                                            const placeholder = input.placeholder || '';
                                                            const label = input.getAttribute('aria-label') || '';
                                                            if (placeholder.toLowerCase().includes('date') || 
                                                                label.toLowerCase().includes('date') ||
                                                                input.className.includes('date')) {
                                                                input.value = targetDate;
                                                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                                                break;
                                                            }
                                                        }
                                                    }, dateFormatted);
                                                    
                                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                                    
                                                    // 設置 End Time 為 23:59:59
                                                    await newPage.evaluate(() => {
                                                        const endTimeLabels = Array.from(document.querySelectorAll('span.MuiTypography-caption'));
                                                        for (let label of endTimeLabels) {
                                                            if (label.textContent.trim() === 'End Time') {
                                                                const parent = label.closest('div.flex.flex-col, div[class*="flex"]');
                                                                if (parent) {
                                                                    const endTimeInput = parent.querySelector('input[type="text"].font-mono');
                                                                    if (endTimeInput) {
                                                                        endTimeInput.focus();
                                                                        endTimeInput.select();
                                                                        endTimeInput.value = '23:59:59';
                                                                        endTimeInput.dispatchEvent(new Event('input', { bubbles: true }));
                                                                        endTimeInput.dispatchEvent(new Event('change', { bubbles: true }));
                                                                        endTimeInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                                                        
                                                                        const timeContainer = endTimeInput.closest('div.flex.gap-2, div[class*="flex"]');
                                                                        if (timeContainer) {
                                                                            const pmButton = timeContainer.querySelector('div[class*="cursor-pointer"]:last-child, div:last-child');
                                                                            if (pmButton && pmButton.classList.contains('cursor-pointer')) {
                                                                                if (!pmButton.classList.contains('bg-primary')) {
                                                                                    pmButton.click();
                                                                                }
                                                                            }
                                                                        }
                                                                        break;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    });
                                                    
                                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                                    
                                                    // 查找並點擊確認按鈕
                                                    const confirmButton = await newPage.evaluate(() => {
                                                        const buttons = Array.from(document.querySelectorAll('button'));
                                                        let confirmBtn = buttons.find(btn => {
                                                            const text = (btn.textContent || '').trim().toLowerCase();
                                                            return text === 'ok' || text === 'apply' || text === '確認' || text === '確定';
                                                        });
                                                        if (confirmBtn) {
                                                            const uniqueId = 'confirm-btn-' + Date.now();
                                                            confirmBtn.setAttribute('data-puppeteer-id', uniqueId);
                                                                return {
                                                                    found: true,
                                                                    selector: '[data-puppeteer-id="' + uniqueId + '"]'
                                                                };
                                                            }
                                                            return { found: false };
                                                        });
                                                        
                                                    if (confirmButton.found) {
                                                        await newPage.click(confirmButton.selector, { timeout: 5000 });
                                                    } else {
                                                        await newPage.keyboard.press('Escape');
                                                    }
                                                    
                                                            await new Promise(resolve => setTimeout(resolve, 1500));
                                                            
                                                    // 查找並點擊 Search 按鈕
                                                            const searchButton = await newPage.evaluate(() => {
                                                        const buttons = Array.from(document.querySelectorAll('button'));
                                                        let searchBtn = buttons.find(btn => {
                                                            const text = (btn.textContent || '').trim().toLowerCase();
                                                            return text.includes('search') || text.includes('搜尋') || text.includes('查詢');
                                                                });
                                                                if (searchBtn) {
                                                                    const uniqueId = 'search-btn-' + Date.now();
                                                                    searchBtn.setAttribute('data-puppeteer-id', uniqueId);
                                                                    return {
                                                                        found: true,
                                                                        selector: '[data-puppeteer-id="' + uniqueId + '"]'
                                                                    };
                                                                }
                                                                return { found: false };
                                                            });
                                                            
                                                            if (searchButton.found) {
                                                                await newPage.click(searchButton.selector, { timeout: 5000 });
                                                                await new Promise(resolve => setTimeout(resolve, 3000));
                                                        await newPage.waitForSelector('table', { timeout: 10000 }).catch(() => {});
                                                                await new Promise(resolve => setTimeout(resolve, 1000));
                                                        }
                                                    } catch (e) {
                                                    console.log('⚠️  [Page ' + pageNum + '] Error setting date: ' + e.message);
                                                    }
                                                }
                                                
                                                // 跳轉到指定頁碼
                                                const pageJumped = await newPage.evaluate((targetPage) => {
                                                    const pagination = document.querySelector('div.el-pagination');
                                                    if (!pagination) return { success: false, error: 'Pagination not found' };
                                                    
                                                    // 查找指定頁碼的按鈕
                                                    const pageButtons = pagination.querySelectorAll('li.number');
                                                    let targetButton = null;
                                                    
                                                    for (let btn of pageButtons) {
                                                        if (!btn.classList.contains('more')) {
                                                            const pageNum = parseInt(btn.textContent.trim());
                                                            if (pageNum === targetPage) {
                                                                targetButton = btn;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (targetButton) {
                                                        targetButton.click();
                                                        return { success: true };
                                                    }
                                                    
                                                    // 如果找不到按鈕，嘗試使用輸入框跳轉
                                                    const jumpInput = pagination.querySelector('input.el-pagination__editor');
                                                    if (jumpInput) {
                                                        jumpInput.value = targetPage;
                                                        jumpInput.dispatchEvent(new Event('input', { bubbles: true }));
                                                        jumpInput.dispatchEvent(new Event('change', { bubbles: true }));
                                                        jumpInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
                                                        jumpInput.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', bubbles: true }));
                                                        return { success: true, method: 'input' };
                                                    }
                                                    
                                                    return { success: false, error: 'Cannot find page ' + targetPage };
                                                }, pageNum);
                                                
                                                if (pageJumped.success) {
                                                    // 等待頁面載入
                                                    await new Promise(resolve => setTimeout(resolve, 2500));
                                                    await newPage.waitForSelector('table tbody#ele-table-body', { timeout: 10000 }).catch(() => {});
                                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                                } else {
                                                    console.log('⚠️  [Page ' + pageNum + '] Cannot jump to page: ' + (pageJumped.error || 'Unknown error'));
                                                }
                                                
                                                // 提取當前頁的數據
                                                const pageData = await newPage.evaluate(() => {
                                                    const table = document.querySelector('table');
                                                    if (!table) {
                                                        return { found: false, error: 'Table not found' };
                                                    }
                                                    
                                                    const headers = [];
                                                    const thead = table.querySelector('thead');
                                                    if (thead) {
                                                        const headerRow = thead.querySelector('tr');
                                                        if (headerRow) {
                                                            const headerCells = headerRow.querySelectorAll('th');
                                                            headerCells.forEach(cell => {
                                                                const span = cell.querySelector('span span');
                                                                const headerText = span ? span.textContent.trim() : cell.textContent.trim();
                                                                if (headerText) {
                                                                    headers.push(headerText);
                                                                }
                                                            });
                                                        }
                                                    }
                                                    
                                                    const tbody = table.querySelector('tbody#ele-table-body');
                                                    if (!tbody) {
                                                        return { found: false, error: 'Table body not found' };
                                                    }
                                                    
                                                    const rows = [];
                                                    const allRows = Array.from(tbody.querySelectorAll('tr'));
                                                    
                                                    for (let i = 0; i < allRows.length; i++) {
                                                        const row = allRows[i];
                                                        if (row.classList.contains('detail-row')) {
                                                            continue;
                                                        }
                                                        
                                                        const firstCell = row.querySelector('td');
                                                        if (firstCell) {
                                                            const firstCellText = firstCell.textContent.trim();
                                                            if (firstCellText === 'Subtotal') {
                                                                continue;
                                                            }
                                                        }
                                                        
                                                        const cells = row.querySelectorAll('td');
                                                        const rowData = {};
                                                        
                                                        cells.forEach((cell, index) => {
                                                            const div = cell.querySelector('div.inner-row--1');
                                                            let cellText = '';
                                                            
                                                            if (div) {
                                                                const innerDiv = div.querySelector('div');
                                                                if (innerDiv) {
                                                                    cellText = innerDiv.textContent.trim();
                                                                } else {
                                                                    cellText = div.textContent.trim();
                                                                }
                                                            } else {
                                                                cellText = cell.textContent.trim();
                                                            }
                                                            
                                                            if (headers[index]) {
                                                                let cleanHeader = headers[index]
                                                                    .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                                    .replace(/^_+|_+$/g, '');
                                                                
                                                                if (!cleanHeader) {
                                                                    cleanHeader = 'column_' + index;
                                                                }
                                                                
                                                                let finalHeader = cleanHeader;
                                                                let counter = 1;
                                                                while (rowData.hasOwnProperty(finalHeader)) {
                                                                    finalHeader = cleanHeader + '_' + counter;
                                                                    counter++;
                                                                }
                                                                
                                                                rowData[finalHeader] = cellText;
                                                            } else {
                                                                rowData['column_' + index] = cellText;
                                                            }
                                                        });
                                                        
                                                        if (i + 1 < allRows.length && allRows[i + 1].classList.contains('detail-row')) {
                                                            const detailRow = allRows[i + 1];
                                                            const detailGrid = detailRow.querySelector('div.detail-grid');
                                                            
                                                            if (detailGrid) {
                                                                const detailItems = detailGrid.querySelectorAll('div.detail-item');
                                                                const details = {};
                                                                
                                                                detailItems.forEach(item => {
                                                                    const label = item.querySelector('div.detail-label');
                                                                    const value = item.querySelector('div.detail-value');
                                                                    
                                                                    if (label && value) {
                                                                        const labelText = label.textContent.trim().replace(':', '');
                                                                        let cleanLabel = labelText
                                                                            .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                                            .replace(/^_+|_+$/g, '');
                                                                        
                                                                        let valueText = value.textContent.trim();
                                                                        const button = value.querySelector('button');
                                                                        if (button) {
                                                                            valueText = valueText.replace(button.textContent.trim(), '').trim();
                                                                            details[cleanLabel + '_hasButton'] = true;
                                                                        }
                                                                        
                                                                        details[cleanLabel] = valueText;
                                                                    }
                                                                });
                                                                
                                                                rowData.details = details;
                                                            }
                                                            
                                                            i++;
                                                        }
                                                        
                                                        if (Object.keys(rowData).length > 0) {
                                                            rows.push(rowData);
                                                        }
                                                    }
                                                    
                                                    return {
                                                        found: true,
                                                        headers: headers,
                                                        rowCount: rows.length,
                                                        data: rows
                                                    };
                                                });
                                                
                                                return {
                                                    success: true,
                                                    pageNumber: pageNum,
                                                    data: pageData
                                                };
                                                
                                            } catch (error) {
                                                console.log('⚠️  [Page ' + pageNum + '] Error: ' + error.message);
                                                return {
                                                    success: false,
                                                    pageNumber: pageNum,
                                                    error: error.message
                                                };
                                            } finally {
                                                await newPage.close();
                                            }
                                        }
                                        
                                        // 使用併發控制器執行爬取
                                        const concurrencyLimit = {$concurrency};
                                        console.log('🚀 Starting concurrent scraping with limit: ' + concurrencyLimit);
                                        const concurrentResults = await promiseAllWithLimit(
                                            pagesToScrape,
                                            concurrencyLimit,
                                            scrapePageConcurrently
                                        );
                                        
                                        // 處理併發爬取的結果
                                        concurrentResults.forEach(result => {
                                            if (result.success && result.data && result.data.found) {
                                                allPagesData.push({
                                                    pageNumber: result.pageNumber,
                                                    rowCount: result.data.rowCount,
                                                    data: result.data.data
                                                });
                                                allRows = allRows.concat(result.data.data);
                                                console.log('✅ [Page ' + result.pageNumber + '] Scraped ' + result.data.rowCount + ' rows');
                                            } else {
                                                console.log('⚠️  [Page ' + result.pageNumber + '] Failed: ' + (result.error || 'Unknown error'));
                                            }
                                        });
                                        
                                console.log('✅ Concurrent scraping completed! Total pages scraped: ' + allPagesData.length);
                                
                                // 步驟 14: 併發爬取完成後截圖
                                await takeScreenshot('14_after_concurrent_scraping');
                            }
                            
                            // ========== 步驟 3：合併所有數據 ==========
                            const mergedTableData = {
                                found: true,
                                headers: headers,
                                totalPages: allPagesData.length,
                                totalRows: allRows.length,
                                pages: allPagesData,
                                data: allRows
                            };
                            
                            // 步驟 15: 合併數據後截圖
                            await takeScreenshot('15_after_merge_data');
                                    
                                    if (mergedTableData.found) {
                                        // 保存表格資料到文件
                                        const tableDataFile = {
                                            timestamp: new Date().toISOString(),
                                            url: '$url',
                                            date: dateParsed,
                                            tableData: mergedTableData
                                        };
                                        fs.writeFileSync('table_data.json', JSON.stringify(tableDataFile, null, 2));
                                        
                                        // 截圖表格
                                        console.log('📸 Taking screenshot of table...');
                                        await page.screenshot({ 
                                            path: 'table_screenshot.png',
                                            fullPage: false
                                        });
                                        console.log('✅ Screenshot saved: table_screenshot.png');
                                        
                                        // 返回表格資料
                                        return tableDataFile;
                                    } else {
                                        console.log('⚠️  Failed to merge table data');
                                        return { success: false, error: 'Failed to merge table data' };
                                    }
                                } else {
                                    console.log('⚠️  Search button not found');
                                    // 即使沒找到按鈕也截圖
                                    await page.screenshot({ 
                                        path: 'date_search_button_not_found_screenshot.png',
                                    fullPage: false
                                });
                            }
                        } catch (e) {
                            console.log('⚠️  Error processing date range: ' + e.message);
                            console.log('Stack trace: ' + e.stack);
                            // 即使出錯也截圖
                            await page.screenshot({ 
                                path: 'date_error_screenshot.png',
                                fullPage: false
                            });
                        }
                    }

                    // 如果沒有表格資料，返回簡單的成功標誌
                    return {
                        success: true,
                        timestamp: new Date().toISOString(),
                        url: '$url',
                        message: 'Login and navigation completed, but no table data found'
                    };
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

        // 讀取腳本生成的結果文件（優先讀取表格資料，如果沒有則讀取其他結果）
        $tableDataFile = $workingDir . '/table_data.json';
        $loginResultFile = $workingDir . '/login_result.json';
        $scrapedResultFile = $workingDir . '/scraped_result.json';

        if (file_exists($tableDataFile)) {
            // 讀取並解析表格資料 JSON 文件
            $content = file_get_contents($tableDataFile);
            return json_decode($content, true);
        } elseif (file_exists($loginResultFile)) {
            // 讀取並解析登入結果 JSON 文件（向後兼容）
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

        // 檢查是否為表格資料結果（新格式）
        if (isset($result['tableData'])) {
            $this->info('✅ Table data extraction completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            if (isset($result['dateStart']) && isset($result['dateEnd'])) {
                $this->info('📅 Date Range: ' . $result['dateStart'] . ' to ' . $result['dateEnd']);
            }
            
            // 處理表格資料
            $tableData = $result['tableData'];
            
            if (isset($tableData['found']) && $tableData['found']) {
                $totalRows = $tableData['totalRows'] ?? $tableData['rowCount'] ?? 0;
                $totalPages = $tableData['totalPages'] ?? 1;
                
                $this->info('📋 Total pages: ' . $totalPages);
                $this->info('📋 Total rows: ' . $totalRows);
                $this->info('📋 Table headers: ' . (count($tableData['headers'] ?? []) . ' columns'));
                
                // 保存表格資料
                $timestamp = date('Y-m-d_H-i-s');
                $tableFileName = "scraped_data/table_data_{$timestamp}.json";
                $tableFileData = [
                    'metadata' => [
                        'timestamp' => $timestamp,
                        'url' => $result['url'] ?? '',
                        'dateStart' => $result['dateStart'] ?? null,
                        'dateEnd' => $result['dateEnd'] ?? null,
                        'totalPages' => $totalPages,
                        'totalRows' => $totalRows,
                        'headers' => $tableData['headers'] ?? []
                    ],
                    'headers' => $tableData['headers'] ?? [],
                    'totalPages' => $totalPages,
                    'totalRows' => $totalRows,
                    'pages' => $tableData['pages'] ?? [],
                    'data' => $tableData['data'] ?? []
                ];
                
                Storage::put($tableFileName, json_encode($tableFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("✅ Table data saved: {$tableFileName}");
                $this->info("📊 Merged data from {$totalPages} pages with {$totalRows} total rows");
            } else {
                $this->warn('⚠️  Table data extraction failed: ' . ($tableData['error'] ?? 'Unknown error'));
            }
            
            // 處理截圖
            $timestamp = date('Y-m-d_H-i-s');
            $screenshots = [
                'target_page_screenshot.png',
                'date_before_click_screenshot.png',
                'date_picker_opened_screenshot.png',
                'date_start_filled_screenshot.png',
                'date_end_filled_screenshot.png',
                'date_selected_screenshot.png',
                'date_search_clicked_screenshot.png',
                'table_screenshot.png'
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
            $this->info("✅ Data processing completed!");
            return;
        }
        
        // 檢查是否為登入結果（向後兼容，舊格式）
        if (isset($result['loginUrl']) && !isset($result['domData'])) {
            $this->info('✅ Login process completed successfully!');
            $this->info('📍 Login URL: ' . ($result['loginUrl'] ?? 'N/A'));
            if (isset($result['loginPageInfo'])) {
                $this->info('📄 Login Page: ' . ($result['loginPageInfo']['url'] ?? 'N/A'));
            }
            $this->info('🍪 Cookies obtained: ' . ($result['cookiesCount'] ?? 0));
            
            // 處理所有截圖
            $timestamp = date('Y-m-d_H-i-s');
            $screenshots = [
                'login_form_screenshot.png',
                'login_after_screenshot.png',
                'login_error_screenshot.png',
                'target_page_screenshot.png',
                'date_before_click_screenshot.png',
                'date_picker_opened_screenshot.png',
                'date_start_filled_screenshot.png',
                'date_end_filled_screenshot.png',
                'date_selected_screenshot.png',
                'date_ok_button_not_found_screenshot.png',
                'date_search_clicked_screenshot.png',
                'date_search_button_not_found_screenshot.png',
                'table_screenshot.png',
                'date_error_screenshot.png'
            ];
            
            foreach ($screenshots as $screenshot) {
                $screenshotSrc = storage_path('app/temp/' . $screenshot);
                if (file_exists($screenshotSrc)) {
                    $screenshotDst = storage_path("app/scraped_data/{$screenshot}_{$timestamp}.png");
                    rename($screenshotSrc, $screenshotDst);
                    $this->info("📸 Screenshot saved: {$screenshotDst}");
                }
            }
            
            // 處理表格資料（如果有）
            if (isset($result['tableData'])) {
                $this->info('📊 Processing table data...');
                $tableData = $result['tableData'];
                
                if (isset($tableData['found']) && $tableData['found']) {
                    $totalRows = $tableData['totalRows'] ?? $tableData['rowCount'] ?? 0;
                    $totalPages = $tableData['totalPages'] ?? 1;
                    
                    $this->info('📋 Total pages: ' . $totalPages);
                    $this->info('📋 Total rows: ' . $totalRows);
                    $this->info('📋 Table headers: ' . (count($tableData['headers'] ?? []) . ' columns'));
                    
                    // 保存表格資料（包含所有頁面的合併數據）
                    $tableFileName = "scraped_data/table_data_{$timestamp}.json";
                    $tableFileData = [
                        'metadata' => [
                            'timestamp' => $timestamp,
                            'url' => $result['targetUrl'] ?? '',
                            'totalPages' => $totalPages,
                            'totalRows' => $totalRows,
                            'headers' => $tableData['headers'] ?? []
                        ],
                        'headers' => $tableData['headers'] ?? [],
                        'totalPages' => $totalPages,
                        'totalRows' => $totalRows,
                        'pages' => $tableData['pages'] ?? [],
                        'data' => $tableData['data'] ?? []
                    ];
                    
                    Storage::put($tableFileName, json_encode($tableFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->info("✅ Table data saved: {$tableFileName}");
                    $this->info("📊 Merged data from {$totalPages} pages with {$totalRows} total rows");
                } else {
                    $this->warn('⚠️  Table data extraction failed: ' . ($tableData['error'] ?? 'Unknown error'));
                }
            } else {
                $this->warn('⚠️  No table data found in result');
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

