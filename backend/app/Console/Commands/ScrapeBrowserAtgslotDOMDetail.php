<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - ATGSLOT
 */
class ScrapeBrowserAtgslotDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-atgslot-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {player_account?} - 玩家帳號（可選參數）
     * {--concurrency=8} - 併發數量（可選，預設為 8）
     */
    protected $signature = 'agent:scrape-atgslot-dom-detail {url} {date_start?} {date_end?} {player_account?} {--concurrency=8}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from ATGSLOT DOM elements using browser automation with two-factor authentication';

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
        $player_account = $this->argument('player_account');
        $concurrency = $this->option('concurrency');

        $this->info('=== ATGSLOT DOM Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$date_start}");
        $this->info("Date End: {$date_end}");
        $this->info("Player Account: {$player_account}");
        $this->info("Concurrency: {$concurrency}");
        
        // 檢查是否設定了 loginInfo（可以跳過登入流程）
        $loginInfo = env('ATGSLOT_AGENT_LOGIN_INFO', '');
        if (!empty($loginInfo)) {
            $this->info('✅ 已設定 loginInfo，將使用現有登入資訊（跳過登入流程）');
        } else {
            // 檢查是否設定了驗證碼
            $verificationCode = env('ATGSLOT_AGENT_VERIFICATION_CODE', '');
            if (empty($verificationCode)) {
                $this->warn('');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->warn('⚠️  注意：未設定二階段驗證碼');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->warn('如果網站需要二階段驗證，系統會暫停並等待您輸入');
                $this->warn('您可以在 .env 文件中設定：');
                $this->warn('  - ATGSLOT_AGENT_VERIFICATION_CODE=your_code（僅驗證碼）');
                $this->warn('  - ATGSLOT_AGENT_LOGIN_INFO={"token":"...","key":"..."}（完整登入資訊，可跳過登入）');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->warn('');
            } else {
                $this->info('✅ 已設定驗證碼，將自動填入');
            }
        }

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $date_start, $date_end, $player_account, $concurrency);

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
     * @param string|null $player_account 玩家帳號（可選）
     * @param int $concurrency 併發數量
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date_start = null, $date_end = null, $player_account = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 檢查是否使用 loginInfo 跳過登入
        $loginInfo = env('ATGSLOT_AGENT_LOGIN_INFO', '');
        if (!empty($loginInfo)) {
            // 使用 loginInfo 直接設置登入狀態
            $loginCodeForPage = $this->generateAtgslotPuppeteerLoginInfoCode('page', $loginInfo);
            $cookiesCodeForNewPage = $this->generateAtgslotPuppeteerCookiesCode('newPage');
        } else {
            // 使用正常的二階段登入流程
            $loginCodeForPage = $this->generateAtgslotPuppeteerLoginCode('page');
            $cookiesCodeForNewPage = $this->generateAtgslotPuppeteerCookiesCode('newPage');
        }

        // 將 date 轉換為 JavaScript 可用的格式
        // 格式化日期為 YYYY-MM-DD
        $dateStartFormatted = null;
        $dateEndFormatted = null;
        
        if ($date_start) {
            // 如果已經是 YYYY-MM-DD 格式，直接使用；否則轉換
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) {
                $dateStartFormatted = $date_start;
            } elseif (preg_match('/^\d{8}$/', $date_start)) {
                // YYYYMMDD 格式轉換為 YYYY-MM-DD
                $dateStartFormatted = substr($date_start, 0, 4) . '-' . substr($date_start, 4, 2) . '-' . substr($date_start, 6, 2);
            } else {
                $dateStartFormatted = date('Y-m-d', strtotime($date_start));
            }
        }
        
        if ($date_end) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) {
                $dateEndFormatted = $date_end;
            } elseif (preg_match('/^\d{8}$/', $date_end)) {
                $dateEndFormatted = substr($date_end, 0, 4) . '-' . substr($date_end, 4, 2) . '-' . substr($date_end, 6, 2);
            } else {
                $dateEndFormatted = date('Y-m-d', strtotime($date_end));
            }
        }
        
        $dateStartJs = $dateStartFormatted ? json_encode($dateStartFormatted) : 'null';
        $dateEndJs = $dateEndFormatted ? json_encode($dateEndFormatted) : 'null';
        $playerAccountJs = $player_account ? json_encode($player_account) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 併發控制器：限制同時執行的 Promise 數量
             */
            async function promiseAllWithLimit(items, limit, fn) {
                const results = [];
                const executing = [];
                let completedCount = 0;
                const totalItems = items.length;
                
                for (const [index, item] of items.entries()) {
                    const promise = Promise.resolve().then(() => fn(item, index))
                        .then((result) => {
                            completedCount++;
                            return result;
                        });
                    
                    results.push(promise);
                    
                    if (limit <= items.length) {
                        const executing_promise = promise.then(() => 
                            executing.splice(executing.indexOf(executing_promise), 1)
                        );
                        
                        executing.push(executing_promise);
                        
                        if (executing.length >= limit) {
                            await Promise.race(executing);
                        }
                    }
                }
                
                console.log('⏳ Waiting for all pages to complete...');
                return Promise.all(results);
            }

            /**
             * 從 DOM 提取資料的函數
             */
            async function scrapeDOMContent() {
                const browser = await puppeteer.launch({
                    headless: 'new',  // 使用 headless 模式在背景運行
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--no-zygote',
                        '--single-process',
                        '--disable-gpu',
                        '--disable-software-rasterizer',
                        '--disable-background-timer-throttling',
                        '--disable-backgrounding-occluded-windows',
                        '--disable-renderer-backgrounding',
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
                        '--disable-javascript-harmony-shipping',
                        '--disable-sync'
                    ],
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

                    // 暫時不攔截資源，確保頁面能正常載入和渲染
                    // 如果需要提升速度，可以在登入完成後再啟用資源攔截
                    // await page.setRequestInterception(true);
                    // page.on('request', (req) => {
                    //     const resourceType = req.resourceType();
                    //     const url = req.url();
                    //     if (['image', 'font', 'media', 'websocket', 'manifest', 'texttrack'].includes(resourceType)) {
                    //         req.abort();
                    //     } else {
                    //         req.continue();
                    //     }
                    // });

                    // 執行二階段登入流程
                    {$loginCodeForPage}

                    // 等待登入完成
                    console.log('⏳ Waiting for login to complete...');
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    // 獲取登入後的 cookies 和認證資訊 - 使用安全的方式
                    let authData = { cookies: '', localStorage: {} };
                    try {
                        // 確保頁面穩定
                        await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        authData = await page.evaluate(() => {
                            const cookies = document.cookie;
                            const localStorage = {};
                            for (let i = 0; i < window.localStorage.length; i++) {
                                const key = window.localStorage.key(i);
                                localStorage[key] = window.localStorage.getItem(key);
                            }
                            return { cookies, localStorage };
                        });
                    } catch (e) {
                        if (e.message.includes('detached') || e.message.includes('Frame')) {
                            console.log('⚠️  Frame detached when getting auth data, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                            try {
                                authData = await page.evaluate(() => {
                                    const cookies = document.cookie;
                                    const localStorage = {};
                                    for (let i = 0; i < window.localStorage.length; i++) {
                                        const key = window.localStorage.key(i);
                                        localStorage[key] = window.localStorage.getItem(key);
                                    }
                                    return { cookies, localStorage };
                                });
                            } catch (e2) {
                                console.log('⚠️  Cannot get auth data: ' + e2.message);
                            }
                        } else {
                            console.log('⚠️  Error getting auth data: ' + e.message);
                        }
                    }

                    const cookies = await page.cookies();
                    console.log('✅ Login completed, obtained ' + cookies.length + ' cookies');

                    // 登入成功後，導航到目標 URL
                    console.log('🌐 Navigating to target URL:', '$url');
                    try {
                        await page.goto('$url', {
                            waitUntil: 'load',
                            timeout: 60000
                        });
                        console.log('✅ Successfully navigated to target URL');
                        
                        // 等待頁面完全載入
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        
                        // 確保頁面有內容
                        const pageHasContent = await page.evaluate(() => {
                            return document.body && document.body.innerHTML.trim().length > 0;
                        }).catch(() => false);
                        
                        if (pageHasContent) {
                            console.log('✅ Target page loaded successfully');
                        } else {
                            console.log('⚠️  Target page body is empty, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        }
                    } catch (e) {
                        console.log('⚠️  Error navigating to target URL: ' + e.message);
                        // 即使導航失敗，也繼續執行
                    }
                    
                    // 填入日期範圍（如果提供了 date_start 或 date_end）
                    let dateStartValue = null;
                    let dateEndValue = null;
                    
                    // 安全地解析日期值
                    if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs.trim() !== '') {
                        try {
                            const parsed = JSON.parse($dateStartJs);
                            if (parsed !== null && parsed !== '') {
                                dateStartValue = parsed;
                            }
                        } catch (e) {
                            // 如果 JSON 解析失敗，嘗試直接使用字符串值
                            console.log('⚠️  Error parsing date_start as JSON, using as string: ' + e.message);
                            dateStartValue = $dateStartJs.replace(/^["']|["']$/g, ''); // 移除引號
                        }
                    }
                    
                    if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs.trim() !== '') {
                        try {
                            const parsed = JSON.parse($dateEndJs);
                            if (parsed !== null && parsed !== '') {
                                dateEndValue = parsed;
                            }
                        } catch (e) {
                            // 如果 JSON 解析失敗，嘗試直接使用字符串值
                            console.log('⚠️  Error parsing date_end as JSON, using as string: ' + e.message);
                            dateEndValue = $dateEndJs.replace(/^["']|["']$/g, ''); // 移除引號
                        }
                    }
                    
                    if (dateStartValue || dateEndValue) {
                        console.log('📅 Filling date range...');
                        console.log('   Date Start: ' + (dateStartValue || 'N/A'));
                        console.log('   Date End: ' + (dateEndValue || 'N/A'));
                        
                        try {
                            // 等待頁面穩定
                            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 查找日期輸入框
                            const dateInputs = await page.$$('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                            console.log('📋 Found ' + dateInputs.length + ' date input(s)');
                            
                            if (dateInputs.length >= 2) {
                                // 第一個輸入框填入開始日期
                                if (dateStartValue) {
                                    console.log('📝 Filling start date: ' + dateStartValue);
                                    await page.evaluate((dateValue, index) => {
                                        const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (inputs[index]) {
                                            const input = inputs[index];
                                            input.value = '';
                                            input.value = dateValue;
                                            // 觸發各種事件確保應用檢測到變化
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            // 也觸發 keyup 和 keydown（某些框架需要）
                                            input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                                            input.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true }));
                                        }
                                    }, dateStartValue, 0);
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                                
                                // 第二個輸入框填入結束日期
                                if (dateEndValue) {
                                    console.log('📝 Filling end date: ' + dateEndValue);
                                    await page.evaluate((dateValue, index) => {
                                        const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (inputs[index]) {
                                            const input = inputs[index];
                                            input.value = '';
                                            input.value = dateValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                                            input.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true }));
                                        }
                                    }, dateEndValue, 1);
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                                
                                console.log('✅ Date range filled successfully');
                            } else if (dateInputs.length === 1) {
                                // 如果只有一個輸入框，可能是日期範圍選擇器
                                console.log('📝 Found single date input, trying to fill as range...');
                                if (dateStartValue && dateEndValue) {
                                    // 嘗試填入範圍格式（根據實際需求調整格式）
                                    const dateRange = dateStartValue + ' ~ ' + dateEndValue;
                                    await page.evaluate((rangeValue) => {
                                        const input = document.querySelector('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (input) {
                                            input.value = '';
                                            input.value = rangeValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    }, dateRange);
                                    console.log('✅ Date range filled: ' + dateRange);
                                } else if (dateStartValue) {
                                    await page.evaluate((dateValue) => {
                                        const input = document.querySelector('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (input) {
                                            input.value = '';
                                            input.value = dateValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    }, dateStartValue);
                                    console.log('✅ Start date filled: ' + dateStartValue);
                                }
                            } else {
                                console.log('⚠️  Date inputs not found, trying alternative selectors...');
                                // 嘗試其他選擇器
                                const altInputs = await page.$$('input[placeholder*="日期"], input[placeholder*="date"], input.ivu-input');
                                console.log('📋 Found ' + altInputs.length + ' alternative input(s)');
                            }
                            
                            // 等待一下讓日期輸入生效
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 點擊搜尋按鈕
                            console.log('🔍 Looking for search button...');
                            let searchButtonClicked = false;
                            
                            try {
                                // 等待一下確保按鈕已渲染
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                // 使用精確的選擇器查找按鈕
                                const buttonFound = await page.evaluate(() => {
                                    // 優先查找包含 "搜尋" 文字的按鈕
                                    const allButtons = Array.from(document.querySelectorAll('button.ivu-btn.ivu-btn-primary, button.ivu-btn-primary, .ivu-btn-primary'));
                                    
                                    console.log('Found ' + allButtons.length + ' primary button(s)');
                                    
                                    // 查找包含 "搜尋" 文字的按鈕
                                    for (const btn of allButtons) {
                                        // 獲取按鈕文字（包括 span 內的文字）
                                        const text = (btn.textContent || btn.innerText || '').trim();
                                        const textLower = text.toLowerCase();
                                        
                                        console.log('Checking button with text: "' + text + '"');
                                        
                                        // 檢查是否包含搜尋相關文字
                                        if (textLower.includes('搜尋') || 
                                            textLower.includes('搜索') || 
                                            textLower.includes('查詢') || 
                                            textLower.includes('search')) {
                                            
                                            console.log('Found search button with text: "' + text + '"');
                                            
                                            // 確保按鈕可見
                                            if (btn.offsetParent === null) {
                                                console.log('Button is not visible, skipping');
                                                continue;
                                            }
                                            
                                            // 嘗試點擊
                                            try {
                                                btn.focus();
                                                btn.click();
                                                return { found: true, method: 'click', text: text };
                                            } catch (e) {
                                                console.log('Click failed, trying dispatchEvent: ' + e.message);
                                                // 如果點擊失敗，嘗試觸發事件
                                                const clickEvent = new MouseEvent('click', { 
                                                    bubbles: true, 
                                                    cancelable: true,
                                                    view: window
                                                });
                                                btn.dispatchEvent(clickEvent);
                                                
                                                // 也嘗試 mousedown 和 mouseup
                                                btn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
                                                btn.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true }));
                                                
                                                return { found: true, method: 'dispatchEvent', text: text };
                                            }
                                        }
                                    }
                                    
                                    // 如果找不到包含文字的按鈕，嘗試點擊第一個主要按鈕
                                    if (allButtons.length > 0) {
                                        const firstButton = allButtons[0];
                                        if (firstButton.offsetParent !== null) {
                                            console.log('No text match found, clicking first primary button');
                                            try {
                                                firstButton.focus();
                                                firstButton.click();
                                                return { found: true, method: 'click', text: 'first primary button' };
                                            } catch (e) {
                                                const clickEvent = new MouseEvent('click', { 
                                                    bubbles: true, 
                                                    cancelable: true,
                                                    view: window
                                                });
                                                firstButton.dispatchEvent(clickEvent);
                                                return { found: true, method: 'dispatchEvent', text: 'first primary button' };
                                            }
                                        }
                                    }
                                    
                                    return { found: false, message: 'No search button found' };
                                });
                                
                                if (buttonFound.found) {
                                    searchButtonClicked = true;
                                    console.log('✅ Search button clicked using method: ' + buttonFound.method);
                                    console.log('   Button text: ' + buttonFound.text);
                                    
                                    // 等待搜尋結果載入
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                    
                                    // 等待頁面穩定
                                    await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                } else {
                                    console.log('⚠️  Search button not found: ' + (buttonFound.message || 'Unknown error'));
                                    
                                    // 嘗試使用 Puppeteer 的 waitForSelector 和 click
                                    try {
                                        console.log('🔍 Trying Puppeteer selector method...');
                                        await page.waitForSelector('button.ivu-btn.ivu-btn-primary', { timeout: 5000 });
                                        const button = await page.$('button.ivu-btn.ivu-btn-primary');
                                        
                                        if (button) {
                                            // 檢查按鈕文字
                                            const buttonText = await page.evaluate(btn => btn.textContent || btn.innerText, button);
                                            console.log('Found button with text: "' + buttonText + '"');
                                            
                                            if (buttonText.includes('搜尋') || buttonText.includes('搜索') || buttonText.includes('查詢')) {
                                                await button.click();
                                                searchButtonClicked = true;
                                                console.log('✅ Search button clicked using Puppeteer selector');
                                                await new Promise(resolve => setTimeout(resolve, 2000));
                                            }
                                        }
                                    } catch (e) {
                                        console.log('⚠️  Puppeteer selector method failed: ' + e.message);
                                    }
                                }
                                
                                if (!searchButtonClicked) {
                                    console.log('⚠️  Could not find or click search button, you may need to click it manually');
                                }
                                
                            } catch (e) {
                                console.log('⚠️  Error clicking search button: ' + e.message);
                                console.error(e);
                            }
                            
                        } catch (e) {
                            console.log('⚠️  Error filling date range: ' + e.message);
                            console.error(e);
                        }
                    }
                    
                    // 截圖目標頁面（填入日期和點擊搜尋後）
                    try {
                        await page.screenshot({ path: '10_after_search_clicked.png', fullPage: true });
                        console.log('📸 Screenshot 10: After search clicked saved');
                    } catch (e) {
                        console.log('⚠️  Error taking screenshot: ' + e.message);
                    }

                    // 這裡可以根據實際網頁結構提取表格資料
                    // 暫時返回基本結構
                    const result = {
                        success: true,
                        url: '$url',
                        cookies: cookies,
                        authData: authData,
                        message: 'Login completed, ready for data extraction'
                    };

                    // 保存結果
                    fs.writeFileSync('scrape_result.json', JSON.stringify(result, null, 2));

                    await browser.close();
                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    const errorResult = {
                        success: false,
                        error: error.message
                    };
                    fs.writeFileSync('scrape_result.json', JSON.stringify(errorResult, null, 2));
                    await browser.close();
                    throw error;
                }
            }

            scrapeDOMContent().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_atgslot.js');
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        
        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath 腳本文件路徑
     * @return array|null 返回爬取的結果，失敗時返回 null
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation script...');
        
        $workingDir = dirname($scriptPath);
        // 增加超时时间到 5 分钟（300秒），因为可能需要等待手动输入验证码
        $result = Process::path($workingDir)->timeout(300)->run("node " . basename($scriptPath));

        if ($result->failed()) {
            $this->error("❌ Script execution failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        $resultFile = $workingDir . '/scrape_result.json';
        if (file_exists($resultFile)) {
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }

        return null;
    }

    /**
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        // 處理截圖文件
        $this->processScreenshots($workingDir, $timestamp);

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            
            // 保存 cookies 和認證資訊
            $dataFileName = "scraped_data/atgslot_data_{$timestamp}.json";
            $fileData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                ],
                'cookies' => $result['cookies'] ?? [],
                'authData' => $result['authData'] ?? [],
            ];
            
            Storage::put($dataFileName, json_encode($fileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("✅ Data saved: {$dataFileName}");
        } else {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }

    /**
     * 處理截圖文件，將它們從臨時目錄移動到永久儲存目錄
     * @param string $workingDir 工作目錄（臨時目錄）
     * @param string $timestamp 時間戳
     */
    private function processScreenshots($workingDir, $timestamp)
    {
        $this->info('📸 Processing screenshots...');
        
        // 截圖文件名模式
        $screenshotPatterns = [
            '01_initial_login_page.png',
            '02_after_account_filled.png',
            '03_after_password_filled.png',
            '04_after_login_button_clicked.png',
            '05_verification_code_input_found.png',
            '05_verification_code_input_not_found.png',
            '05_waiting_for_verification_*.png',
            '06_after_verification_code_filled_auto.png',
            '06_after_verification_code_filled_manual.png',
            '06_verification_code_timeout.png',
            '06_waiting_for_manual_code_*.png',
            '07_after_confirm_button_clicked.png',
            '08_login_completed_final_page.png',
        ];
        
        $screenshotsDir = storage_path("app/scraped_data/atgslot_screenshots_{$timestamp}");
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }
        
        $screenshotCount = 0;
        
        // 處理所有截圖文件
        foreach ($screenshotPatterns as $pattern) {
            // 處理通配符模式
            if (strpos($pattern, '*') !== false) {
                $globPattern = str_replace('*', '*', $pattern);
                $files = glob($workingDir . '/' . $globPattern);
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        $filename = basename($file);
                        $destPath = $screenshotsDir . '/' . $filename;
                        if (rename($file, $destPath)) {
                            $screenshotCount++;
                            $this->line("   ✅ {$filename}");
                        }
                    }
                }
            } else {
                // 處理固定文件名
                $srcPath = $workingDir . '/' . $pattern;
                if (file_exists($srcPath)) {
                    $destPath = $screenshotsDir . '/' . $pattern;
                    if (rename($srcPath, $destPath)) {
                        $screenshotCount++;
                        $this->line("   ✅ {$pattern}");
                    }
                }
            }
        }
        
        // 也嘗試移動所有以數字開頭的 PNG 文件（備用方案）
        $allScreenshots = glob($workingDir . '/[0-9]*.png');
        foreach ($allScreenshots as $file) {
            $filename = basename($file);
            $destPath = $screenshotsDir . '/' . $filename;
            if (!file_exists($destPath) && rename($file, $destPath)) {
                $screenshotCount++;
                $this->line("   ✅ {$filename}");
            }
        }
        
        if ($screenshotCount > 0) {
            $this->info("✅ {$screenshotCount} screenshot(s) saved to: {$screenshotsDir}");
        } else {
            $this->warn('⚠️  No screenshots found');
        }
    }
}

