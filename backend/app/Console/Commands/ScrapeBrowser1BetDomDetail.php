<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 1BET 瀏覽器截圖命令
 */
class ScrapeBrowser1BetDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-1bet-dom-detail {url} {date_start?} {date_end?} {account_number?}
     */
    protected $signature = 'agent:scrape-1bet-dom-detail {url} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Navigate to 1BET domain from .env and take a screenshot';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 從 .env 獲取 1BET_AGENT_DOMAIN（登錄頁面 URL）
        $domain = env('1BET_AGENT_DOMAIN', '');

        if (empty($domain)) {
            $this->error('❌ 1BET_AGENT_DOMAIN is not set in .env file');
            return 1;
        }

        // 優先使用命令參數中的 url 作為登錄後要跳轉的目標 URL
        // 如果沒有提供參數，則使用 .env 中的 1BET_AGENT_REDIRECT_URL
        $url = $this->argument('url');
        $redirectUrl = !empty($url) ? $url : env('1BET_AGENT_REDIRECT_URL', '');

        // 從 .env 獲取 1BET_AGENT_ACCOUNT
        $account = env('1BET_AGENT_ACCOUNT', '');

        // 從 .env 獲取 1BET_AGENT_PASSWORD
        $password = env('1BET_AGENT_PASSWORD', '');

        $this->info('=== 1BET Browser Screenshot ===');
        $this->info("Login Domain: {$domain}");
        if (!empty($redirectUrl)) {
            $this->info("Target URL (after login): {$redirectUrl}");
        }
        if (!empty($account)) {
            $this->info("Account: {$account}");
        }
        if (!empty($password)) {
            $this->info("Password: " . str_repeat('*', strlen($password)));
        }
        if ($dateStart = $this->argument('date_start')) {
            $this->info("Date Start: {$dateStart}");
        }
        if ($dateEnd = $this->argument('date_end')) {
            $this->info("Date End: {$dateEnd}");
        }
        if ($accountNumber = $this->argument('account_number')) {
            $this->info("Account number: {$accountNumber}");
        }
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 從命令參數獲取 date_start、date_end、account_number（可選）
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($domain, $redirectUrl, $dateStart, $dateEnd, $accountNumber);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理截圖與表格資料
        if ($result) {
            $this->processScreenshot($result);
            if (!empty($result['tableData']['found']) && !empty($result['tableData']['data'])) {
                $this->processScrapedData($result);
            }
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
     * 創建 Puppeteer 自動化腳本
     * @param string $domain 要訪問的域名
     * @param string $redirectUrl 登錄後要跳轉的 URL
     * @param string|null $dateStart 開始日期，填入 placeholder="Start Date" 的 input
     * @param string|null $dateEnd 結束日期，填入 placeholder="End Date" 的 input
     * @param string|null $accountNumber 玩家帳號，填入 placeholder="Please enter player account" 的 input
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $redirectUrl = '', $dateStart = null, $dateEnd = null, $accountNumber = null)
    {
        $this->info('2. Creating browser automation script...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $redirectUrlJs = json_encode($redirectUrl);

        // 獲取工作目錄的絕對路徑
        $workingDir = storage_path('app/temp');
        $workingDirJs = json_encode($workingDir);

        // 1BET_AGENT_LANG：在每個新文件載入「前」注入到 sessionStorage/localStorage（key: lang）
        $lang = env('1BET_AGENT_LANG', 'zh-TW');
        $langJs = json_encode($lang);

        // date_start / date_end：填入 Start Date、End Date 的 input
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';

        // account_number：填入 placeholder="Please enter player account" 的 input
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';

        // 使用 trait 方法生成登入流程代碼
        $loginCode = $this->generate1BetPuppeteerLoginCode('page', $workingDirJs, $redirectUrlJs);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            
            // 工作目錄
            const workingDir = $workingDirJs;

            /**
             * 導航到域名並截圖
             */
            async function navigateAndScreenshot() {
                // 啟動無頭瀏覽器（在背景執行）
                const browser = await puppeteer.launch({
                    headless: 'new', // 無頭模式，在背景執行
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
                        '--disable-sync',
                        // 重定向相關參數
                        '--disable-features=IsolateOrigins,site-per-process',
                        '--disable-site-isolation-trials',
                        '--disable-blink-features=AutomationControlled', // 核心：禁用自動化控制特徵
                        // 防止 DevTools Protocol 檢測
                        '--disable-blink-features=AutomationControlled',
                        '--disable-features=ChromeWhatsNewUI,HttpsUpgrades',
                        '--use-fake-ui-for-media-stream',
                    ],
                    // 排除自動化開關
                    ignoreDefaultArgs: ['--enable-automation'],
                    // 如果環境變數中指定了 Chrome 路徑，則使用該路徑
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    // 創建新的瀏覽器頁面
                    const page = await browser.newPage();
                    
                    // 核心：透過 CDP 徹底禁用 debugger 語句
                    try {
                        const client = await page.target().createCDPSession();
                        await client.send('Debugger.enable');
                        await client.send('Debugger.setBreakpointsActive', { active: false });
                        console.log('🛡️ CDP Debugger disabled');
                    } catch (e) {
                        console.log('⚠️ Failed to disable debugger via CDP:', e.message);
                    }

                    // 設定視窗大小為 1920x1080（模擬桌面瀏覽器）
                    await page.setViewport({ width: 1920, height: 1080 });

                    // 設定 User Agent，模擬真實的瀏覽器請求
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
                    
                    // 攔截並阻止跳轉到 disable-devtool 頁面
                    await page.setRequestInterception(true);
                    page.on('request', (request) => {
                        const url = request.url();
                        const isNav = request.isNavigationRequest() && request.frame() === page.mainFrame();
                        
                        if (url.includes('disable-devtool') || url.includes('theajack.github.io')) {
                            if (isNav) {
                                console.log('🛡️ Blocked navigation request to error page, staying on app');
                                request.respond({
                                    status: 204,
                                    body: ''
                                });
                            } else {
                                console.log('🛡️ Blocked script/resource request to:', url);
                                request.respond({
                                    status: 200,
                                    contentType: 'text/javascript',
                                    body: 'window.DisableDevtool = { isSuspend: true, init: () => {}, suspend: () => {}, resume: () => {}, md5: (s) => s, version: "0.3.7" }; console.log("🛡️ DisableDevtool blocked via request interception");'
                                });
                            }
                        } else {
                            request.continue();
                        }
                    });
                    
                    // 監聽頁面跳轉，防止被重定向
                    page.on('framenavigated', async (frame) => {
                        const url = frame.url();
                        if (url.includes('disable-devtool') || url.includes('theajack.github.io') || url.includes('chrome-error://')) {
                            console.log('🛡️ Detected navigation to disable-devtool, blocking...');
                            
                            // 立即重新注入防護代碼
                            try {
                                await page.evaluate(() => {
                                    try {
                                        const mock = { isSuspend: true, init: () => {}, suspend: () => {}, resume: () => {}, md5: (s) => s, version: '0.3.7' };
                                        Object.defineProperty(window, 'DisableDevtool', { get: () => mock, set: () => {}, configurable: false });
                                        Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                                        Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                                        
                                        // 阻止導航
                                        const blockNav = (url) => (typeof url === 'string' && (url.includes('disable-devtool') || url.includes('theajack.github.io')));
                                        const oAssign = window.location.assign;
                                        window.location.assign = function(u) { if (!blockNav(u)) oAssign.call(window.location, u); };
                                        const oReplace = window.location.replace;
                                        window.location.replace = function(u) { if (!blockNav(u)) oReplace.call(window.location, u); };
                                        console.log('🛡️ Emergency protection injected');
                                    } catch (e) {}
                                });
                            } catch (e) {
                                console.log('⚠️ Failed to inject emergency protection:', e.message);
                            }
                        }
                    });
                    
                    // 隱藏自動化特徵 + 繞過 disable-devtool + 語言注入
                    await page.evaluateOnNewDocument((l) => {
                        // 1. 語言注入
                        try {
                            var v = (l && String(l) !== 'null' && String(l) !== '') ? l : 'zh-TW';
                            var keys = ['lang', 'locale', 'language', 'i18n', 'user-lang', 'site_lang'];
                            keys.forEach(function(k) {
                                try { sessionStorage.setItem(k, v); } catch (e) {}
                                try { localStorage.setItem(k, v); } catch (e) {}
                            });
                        } catch (e) {}

                        // 2. 隱藏 webdriver 屬性
                        try {
                            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
                        } catch (e) {}
                        
                        // 3. 偽造 chrome 對象
                        try {
                            if (!window.chrome) {
                                window.chrome = { runtime: {}, loadTimes: function() {}, csi: function() {}, app: {} };
                            }
                        } catch (e) {}
                        
                        // 4. 繞過與偽裝 disable-devtool 物件
                        // 1. 完全禁用 DisableDevtool
                        const mock = {
                            isSuspend: true,
                            init: () => { console.log('🛡️ DisableDevtool.init called (mocked)'); },
                            suspend: () => { console.log('🛡️ DisableDevtool.suspend called (mocked)'); },
                            resume: () => { console.log('🛡️ DisableDevtool.resume called (mocked)'); },
                            md5: (s) => s,
                            version: '0.3.7'
                        };
                        try {
                            Object.defineProperty(window, 'DisableDevtool', {
                                get: () => mock,
                                set: () => {},
                                configurable: false
                            });
                        } catch (e) {}

                        // 2. 移除一些常見的自動化特徵
                        try {
                            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
                        } catch (e) {}

                        // 3. 偽裝 Chrome 相關屬性
                        window.chrome = { runtime: {} };

                        // 4. 偽造視窗尺寸，防止被檢測出正在開發者模式
                        try {
                            Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                            Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                        } catch (e) {}

                        // 5. 語言注入
                        try {
                            Object.defineProperty(navigator, 'languages', { get: () => [lang || 'zh-CN', 'zh', 'en'] });
                        } catch (e) {}

                        // 6. 覆寫 Function 建構函式以阻斷 debugger 語句
                        try {
                            const originalConstructor = Function.prototype.constructor;
                            Function.prototype.constructor = function(str) {
                                if (str && (str.includes('debugger') || str.includes('debug'))) {
                                    return function() {};
                                }
                                return originalConstructor.apply(this, arguments);
                            };
                        } catch (e) {}

                        // 7. 攔截 RegExp 以防止探針
                        try {
                            const originalRegExpToString = RegExp.prototype.toString;
                            RegExp.prototype.toString = function() {
                                if (this.source === '(?=a)b') return 'function RegExp() { [native code] }';
                                return originalRegExpToString.call(this);
                            };
                        } catch (e) {}

                        // 8. 攔截 console 以防止除錯工具偵測
                        try {
                            const methods = ['log', 'debug', 'info', 'warn', 'error', 'table', 'clear'];
                            methods.forEach(m => {
                                const original = console[m];
                                if (original) {
                                    console[m] = function() {
                                        if (arguments.length > 0 && typeof arguments[0] === 'string' && (arguments[0].includes('devtool') || arguments[0].includes('detect'))) return;
                                        return original.apply(console, arguments);
                                    };
                                }
                            });
                        } catch (e) {}

                        // 9. 阻止跳轉到 disable-devtool
                        try {
                            const blockUrl = (url) => {
                                if (typeof url === 'string' && (url.includes('disable-devtool') || url.includes('theajack.github.io'))) {
                                    console.log('🛡️ Blocked navigation in evaluateOnNewDocument');
                                    return true;
                                }
                                return false;
                            };
                            const originalAssign = window.location.assign;
                            window.location.assign = function(url) { if (!blockUrl(url)) originalAssign.call(window.location, url); };
                            const originalReplace = window.location.replace;
                            window.location.replace = function(url) { if (!blockUrl(url)) originalReplace.call(window.location, url); };
                        } catch (e) {}
                        
                        console.log('🛡️ Advanced anti-detection activated');
                    }, $langJs);

                    // 移除 JSON 編碼的引號
                    const targetUrl = $domainJs.replace(/^"|"\$/g, '');
                    
                    console.log('🌐 Navigating to target URL: ' + targetUrl);
                    
                    // 導航到目標 URL，使用多種策略處理重定向
                    let navigationSuccess = false;
                    
                    // 策略 1: 嘗試正常導航
                    try {
                        await page.goto(targetUrl, {
                            waitUntil: 'domcontentloaded',
                            timeout: 30000
                        });
                        navigationSuccess = true;
                        console.log('✅ Navigation completed (domcontentloaded)');
                    } catch (e) {
                        console.log('⚠️  Navigation failed: ' + e.message);
                        
                        if (e.message.includes('ERR_TOO_MANY_REDIRECTS')) {
                            console.log('   Redirect loop detected, trying alternative method...');
                            
                            // 策略 2: 使用 evaluate 直接設置 URL
                            try {
                                await page.evaluate((url) => {
                                    window.location.href = url;
                                }, targetUrl);
                                
                                // 等待頁面載入
                                await page.waitForNavigation({
                                    waitUntil: 'domcontentloaded',
                                    timeout: 30000
                                }).catch(() => {
                                    console.log('   Navigation wait timeout, but continuing...');
                                });
                                
                                navigationSuccess = true;
                                console.log('✅ Navigation completed (alternative method)');
                            } catch (e2) {
                                console.log('⚠️  Alternative method failed: ' + e2.message);
                                // 即使失敗也繼續，等待頁面載入
                                await new Promise(resolve => setTimeout(resolve, 5000));
                            }
                        } else {
                            // 其他錯誤，等待一下讓頁面有機會載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        }
                    }
                    
                    // 等待頁面完全載入
                    console.log('⏳ Waiting for page to fully render...');
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 檢查頁面是否真正載入
                    let pageLoaded = false;
                    let currentUrl = '';
                    let retryCount = 0;
                    const maxRetries = 5;
                    
                    while (!pageLoaded && retryCount < maxRetries) {
                        try {
                            currentUrl = page.url();
                            console.log('📍 Current URL (attempt ' + (retryCount + 1) + '): ' + currentUrl);
                            
                            // 檢查頁面是否有內容
                            const pageInfo = await page.evaluate(() => {
                                return {
                                    url: window.location.href,
                                    title: document.title,
                                    hasBody: !!document.body,
                                    bodyTextLength: document.body ? document.body.innerText.length : 0,
                                    inputCount: document.querySelectorAll('input').length,
                                    hasAccountInput: !!document.querySelector('input.el-input__inner[placeholder="请输入账号"]'),
                                    hasPasswordInput: !!document.querySelector('input.el-input__inner[placeholder="请输入密码"]')
                                };
                            });
                            
                            // 如果 URL 不是 about:blank 且有內容，認為頁面已載入
                            if (currentUrl !== 'about:blank' && currentUrl !== '' && pageInfo.hasBody && pageInfo.bodyTextLength > 0) {
                                pageLoaded = true;
                                console.log('✅ Page loaded successfully!');
                                console.log('   Title: ' + pageInfo.title);
                                console.log('   Body text length: ' + pageInfo.bodyTextLength);
                                console.log('   Input count: ' + pageInfo.inputCount);
                                console.log('   Has account input: ' + pageInfo.hasAccountInput);
                                console.log('   Has password input: ' + pageInfo.hasPasswordInput);
                                break;
                            } else {
                                console.log('⚠️  Page not fully loaded yet, waiting...');
                                console.log('   URL: ' + pageInfo.url);
                                console.log('   Has body: ' + pageInfo.hasBody);
                                console.log('   Body text length: ' + pageInfo.bodyTextLength);
                                
                                // 如果還是空白頁面，嘗試重新導航
                                if (currentUrl === 'about:blank' || currentUrl === '') {
                                    console.log('   Attempting to navigate again...');
                                    try {
                                        await page.goto(targetUrl, {
                                            waitUntil: 'domcontentloaded',
                                            timeout: 30000
                                        });
                                    } catch (e) {
                                        console.log('   Navigation retry failed: ' + e.message);
                                    }
                                }
                                
                                retryCount++;
                                await new Promise(resolve => setTimeout(resolve, 3000));
                            }
                        } catch (e) {
                            console.log('⚠️  Error checking page: ' + e.message);
                            retryCount++;
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        }
                    }
                    
                    if (!pageLoaded) {
                        console.log('❌ Page failed to load after ' + maxRetries + ' attempts');
                        console.log('   Final URL: ' + currentUrl);
                        throw new Error('Page failed to load. URL: ' + currentUrl);
                    }
                    
                    // 截圖 1: 頁面載入完成後
                    console.log('📸 Step 0: Taking screenshot after page loaded...');
                    const screenshot0Path = path.join(workingDir, '1bet_step0_page_loaded.png');
                    await page.screenshot({
                        path: screenshot0Path,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshot0Path);
                    
                    // 設置 site_lang cookie（evaluateOnNewDocument 已注入 sessionStorage.lang，這裡用同一值）
                    try {
                        const langForCookie = await page.evaluate(() => (sessionStorage.getItem('lang') || localStorage.getItem('lang') || 'zh-TW'));
                        const curl = await page.url();
                        const u = new URL(curl);
                        if (u.hostname) {
                            await page.setCookie({ name: 'site_lang', value: langForCookie, domain: u.hostname, path: '/' });
                        }
                    } catch (e) { console.log('⚠️  site_lang cookie: ' + (e.message || e)); }
                    
                    // 1BET 登入流程（使用 trait 方法）
                    {$loginCode}

                    // 提取表格資料的函數（模仿 GLC：table.el-table__header / el-table__body）
                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            let headerTable = document.querySelector('table.el-table__header');
                            let bodyTable = document.querySelector('table.el-table__body');
                            let table = document.querySelector('table.el-table');
                            if (!table && !headerTable) {
                                const allTables = document.querySelectorAll('table');
                                for (let t of allTables) {
                                    if (t.className && (t.className.includes('el-table') || t.className.includes('el-table__header') || t.className.includes('el-table__body'))) {
                                        if (t.className.includes('el-table__header')) headerTable = t;
                                        else if (t.className.includes('el-table__body')) bodyTable = t;
                                        else if (!table) table = t;
                                    }
                                }
                            }
                            if (!table && !headerTable && !bodyTable) {
                                const el = document.querySelector('.el-table, .el-table__header, .el-table__body, [class*="el-table"]');
                                if (el) {
                                    let p = el.parentElement;
                                    while (p && p.tagName !== 'TABLE') p = p.parentElement;
                                    if (p && p.tagName === 'TABLE') {
                                        if (p.className.includes('el-table__header')) headerTable = p;
                                        else if (p.className.includes('el-table__body')) bodyTable = p;
                                        else table = p;
                                    } else if (el.tagName === 'TABLE') {
                                        if (el.className.includes('el-table__header')) headerTable = el;
                                        else if (el.className.includes('el-table__body')) bodyTable = el;
                                        else table = el;
                                    }
                                }
                            }
                            if (!table && !headerTable && !bodyTable) {
                                return { found: false, error: 'Table el-table not found' };
                            }
                            let headers = [];
                            let thead = (headerTable && headerTable.querySelector('thead')) || (table && table.querySelector('thead'));
                            if (thead) {
                                const hrs = thead.querySelectorAll('tr');
                                if (hrs.length > 0) {
                                    const hcs = hrs[0].querySelectorAll('th, td');
                                    headers = Array.from(hcs).map(cell => {
                                        const d = cell.querySelector('div.cell');
                                        return d ? d.textContent.trim() : cell.textContent.trim();
                                    });
                                }
                            } else {
                                const src = headerTable || table;
                                if (src) {
                                    const fr = src.querySelector('tr');
                                    if (fr) {
                                        const hcs = fr.querySelectorAll('th, td');
                                        headers = Array.from(hcs).map(cell => {
                                            const d = cell.querySelector('div.cell');
                                            return d ? d.textContent.trim() : cell.textContent.trim();
                                        });
                                    }
                                }
                            }
                            let rows = [], dataStartIndex = 0, tbody = null;
                            if (bodyTable) {
                                tbody = bodyTable.querySelector('tbody');
                                rows = tbody ? Array.from(tbody.querySelectorAll('tr')) : Array.from(bodyTable.querySelectorAll('tr'));
                            } else if (table) {
                                tbody = table.querySelector('tbody');
                                rows = tbody ? Array.from(tbody.querySelectorAll('tr')) : Array.from(table.querySelectorAll('tr'));
                                if (thead || (rows.length > 0 && rows[0].querySelectorAll('th').length > 0)) dataStartIndex = 1;
                            }
                            const dataRows = rows.slice(dataStartIndex).map((row, ri) => {
                                const cells = Array.from(row.querySelectorAll('td'));
                                const rowData = {};
                                if (headers && headers.length > 0) {
                                    headers.forEach((h, ci) => {
                                        let ch = (h || '').replace(/[^\\w\\u4e00-\\u9fa5]/g, '_').replace(/^_+|_+$/g, '') || ('column_' + ci);
                                        let fh = ch; let c = 1;
                                        while (rowData.hasOwnProperty(fh)) { fh = ch + '_' + c; c++; }
                                        let cv = null;
                                        if (cells[ci]) { const d = cells[ci].querySelector('div.cell'); cv = d ? d.textContent.trim() : cells[ci].textContent.trim(); }
                                        rowData[fh] = cv;
                                    });
                                } else {
                                    cells.forEach((c, ci) => { const d = c.querySelector('div.cell'); rowData['column_' + ci] = (d || c) ? (d ? d.textContent.trim() : c.textContent.trim()) : null; });
                                }
                                rowData._rowIndex = ri;
                                return rowData;
                            }).filter(rd => { const v = Object.values(rd)[0]; return v !== '小計' && v !== '總計'; });
                            const ft = bodyTable || table || headerTable;
                            return { found: true, tableClass: ft ? ft.className : null, headers, headerCount: headers.length, rowCount: dataRows.length, data: dataRows };
                        });
                    };

                    // 先尋找並框起 Date time 欄位（.el-date-editor--datetimerange 內 input[placeholder="Start date time"]），再將 date_start 填入該 input
                    const dateTimeFieldInfo = await page.evaluate(() => {
                        const info = { found: false, by: null, tagName: '', className: '', placeholder: '', id: '', name: '', value: '' };
                        // 1) 找文字包含 "Date time:" 或 "Date time" 的節點，再找其後的 input / .el-date-editor
                        const walk = (el) => {
                            if (!el || el.nodeType !== 1) return null;
                            const t = (el.textContent || '').trim();
                            if (/Date\s*time\s*:?/i.test(t) && (el.tagName === 'LABEL' || el.tagName === 'SPAN' || el.tagName === 'DIV' || el.tagName === 'TD' || el.tagName === 'TH')) {
                                let n = el.nextElementSibling;
                                while (n) {
                                    if (n.tagName === 'INPUT' || n.tagName === 'TEXTAREA' || n.classList.contains('el-date-editor') || n.querySelector?.('input, .el-date-editor')) {
                                        const inp = n.tagName === 'INPUT' ? n : n.querySelector('input.el-input__inner, input');
                                        return inp || n;
                                    }
                                    n = n.nextElementSibling;
                                }
                                n = el.parentElement?.nextElementSibling;
                                if (n) {
                                    const inp = n.querySelector?.('input, .el-date-editor') || (n.tagName === 'INPUT' ? n : null);
                                    if (inp) return inp.tagName === 'INPUT' ? inp : inp.querySelector?.('input') || inp;
                                }
                                const inParent = el.closest('tr, .el-form-item, div[class*="form"], li')?.querySelector?.('input, .el-date-editor input, .el-date-editor');
                                if (inParent) return inParent;
                            }
                            for (let c = el.firstChild; c; c = c.nextSibling) { const r = walk(c); if (r) return r; }
                            return null;
                        };
                        const fromLabel = walk(document.body);
                        if (fromLabel) {
                            info.found = true; info.by = 'Date time label';
                            const el = fromLabel.tagName === 'INPUT' ? fromLabel : fromLabel.querySelector?.('input');
                            if (el) { info.tagName = el.tagName; info.className = el.className || ''; info.placeholder = el.placeholder || ''; info.id = el.id || ''; info.name = el.name || ''; info.value = (el.value || '').slice(0, 80); }
                            else { info.tagName = fromLabel.tagName; info.className = fromLabel.className || ''; }
                            var toFrame = (fromLabel.tagName === 'INPUT' ? fromLabel : (fromLabel.querySelector && fromLabel.querySelector('input.el-input__inner, input'))) || fromLabel;
                            try {
                                toFrame.style.border = '3px solid red';
                                toFrame.style.boxShadow = '0 0 10px red';
                                toFrame.style.zIndex = '9999';
                                toFrame.style.position = 'relative';
                            } catch (e) {}
                            return info;
                        }
                        // 2) 依 placeholder / 元件型別找
                        const sel = 'input.el-input__inner[placeholder*="Date time"], input[placeholder*="date time"], input[placeholder*="datetime"], .el-date-editor.el-input__inner, .el-date-editor--datetimerange, .el-date-editor';
                        const el2 = document.querySelector(sel) || document.querySelector('.el-date-editor input, .el-date-editor--datetimerange input');
                        if (el2) {
                            const inp = el2.tagName === 'INPUT' ? el2 : el2.querySelector?.('input');
                            if (inp) {
                                info.found = true; info.by = 'placeholder or el-date-editor';
                                info.tagName = inp.tagName; info.className = inp.className || ''; info.placeholder = inp.placeholder || ''; info.id = inp.id || ''; info.name = inp.name || ''; info.value = (inp.value || '').slice(0, 80);
                                try {
                                    inp.style.border = '3px solid red';
                                    inp.style.boxShadow = '0 0 10px red';
                                    inp.style.zIndex = '9999';
                                    inp.style.position = 'relative';
                                } catch (e) {}
                            }
                        }
                        return info;
                    });
                    if (dateTimeFieldInfo.found) {
                        console.log('✅ Date time field found and framed (by: ' + dateTimeFieldInfo.by + ') tagName=' + dateTimeFieldInfo.tagName + ' placeholder=' + dateTimeFieldInfo.placeholder);
                        await new Promise(resolve => setTimeout(resolve, 600));
                    } else {
                        console.log('⚠️  Date time field not found (searched: "Date time:" label, placeholder*="Date time|datetime", .el-date-editor)');
                    }
                    // account_number 填入 placeholder="Please enter player account" 的欄位
                    const accountNumberVal = $accountNumberJs;
                    if (accountNumberVal) {
                        try {
                            const accEl = await page.$('input[placeholder="Please enter player account"]');
                            if (accEl) {
                                // await accEl.evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, accountNumberVal);
                                await accEl.focus(); // 必須先 focus
                                await page.keyboard.type(accountNumberVal, { delay: 100 });
                                console.log('✅ Filled account_number (Please enter player account): ' + accountNumberVal);
                            } else { console.log('⚠️  input[placeholder="Please enter player account"] not found'); }
                        } catch (e) { console.log('⚠️  Fill account_number: ' + (e.message || e)); }
                        await new Promise(r => setTimeout(r, 300));
                    }
                    const dateStartVal = $dateStartJs;
                    const dateEndVal = $dateEndJs;
                    if (dateStartVal || dateEndVal) {
                        try {
                            // 1) 點擊主輸入打開彈窗（Start Date / End Date 在彈窗內）
                            const toOpen = await page.$('input.el-range-input[placeholder="Start date time"]') || await page.$('div.el-date-editor--datetimerange input') || await page.$('div.el-date-editor--datetimerange');
                            if (toOpen) {
                                await toOpen.click();
                                await new Promise(resolve => setTimeout(resolve, 800));
                            }
                            // 2) 等待彈窗內的 input[placeholder="Start Date"]、input[placeholder="End Date"] 出現
                            await page.waitForSelector('input.el-input__inner[placeholder="Start Date"], input.el-input__inner[placeholder="End Date"], input[placeholder="Start Date"], input[placeholder="End Date"]', { timeout: 8000 }).catch(() => {});
                            // 3) date_start 填入 <input placeholder="Start Date" class="el-input__inner">
                            if (dateStartVal) {
                                const el = await page.$('input.el-input__inner[placeholder="Start Date"]') || await page.$('input[placeholder="Start Date"]');
                                if (el) {
                                    await el.evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, dateStartVal);
                                    console.log('✅ Filled Start Date (date_start): ' + dateStartVal);
                                } else { console.log('⚠️  input[placeholder="Start Date"].el-input__inner not found'); }
                                await new Promise(r => setTimeout(r, 300));
                            }
                            // 4) date_end 填入 <input placeholder="End Date" class="el-input__inner">
                            if (dateEndVal) {
                                const el = await page.$('input.el-input__inner[placeholder="End Date"]') || await page.$('input[placeholder="End Date"]');
                                if (el) {
                                    await el.evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, dateEndVal);
                                    console.log('✅ Filled End Date (date_end): ' + dateEndVal);
                                } else { console.log('⚠️  input[placeholder="End Date"].el-input__inner not found'); }
                                await new Promise(r => setTimeout(r, 300));
                            }
                            // 5) 點擊 OK 確認
                            const ok = await page.evaluate(() => {
                                const btns = Array.from(document.querySelectorAll('button.el-picker-panel__link-btn, button.el-button'));
                                for (const b of btns) { if ((b.textContent || '').trim() === 'OK') { b.click(); return true; } }
                                return false;
                            });
                            if (ok) console.log('✅ OK clicked');
                            await new Promise(r => setTimeout(r, 600));
                        } catch (e) { console.log('⚠️  Date range fill: ' + (e.message || e)); }
                    }
                    // 點擊 query 按鈕（el-button el-button--primary el-button--small，span=query）；填完 account_number 或日期後皆執行
                    const queryClicked = await page.evaluate(() => {
                        const btns = Array.from(document.querySelectorAll('button.el-button.el-button--primary.el-button--small, button.el-button--primary'));
                        for (const b of btns) {
                            const t = (b.textContent || '').trim();
                            const s = (b.querySelector('span') ? (b.querySelector('span').textContent || '') : '').trim();
                            if (t === 'query' || s === 'query') { b.click(); return true; }
                        }
                        return false;
                    });
                    if (queryClicked) { console.log('✅ Query button clicked'); } else { console.log('⚠️  Query button not found'); }
                    await new Promise(r => setTimeout(r, 1200));
                    
                    // 檢查是否被跳轉到 disable-devtool 頁面
                    const currentPageStatus = await page.evaluate(() => {
                        const url = window.location.href;
                        const title = document.title || '';
                        return {
                            url: url,
                            title: title,
                            isDisableDevtool: url.includes('disable-devtool') || 
                                             url.includes('theajack.github.io') ||
                                             title.includes('theajack.github.io') ||
                                             title === 'Blocked'
                        };
                    });
                    
                    if (currentPageStatus.isDisableDevtool) {
                        console.log('⚠️  Detected redirect to disable-devtool after query!');
                        console.log('   Current URL: ' + currentPageStatus.url);
                        console.log('   Current Title: ' + currentPageStatus.title);
                        console.log('   Navigating back to target page...');
                        
                        // 導航回目標頁面
                        const targetUrl = $redirectUrlJs.replace(/^"|"\$/g, '');
                        if (targetUrl && targetUrl !== 'null' && targetUrl !== '') {
                            try {
                                await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
                                await new Promise(r => setTimeout(r, 3000));
                                
                                // 重新注入防護
                                await page.evaluate(() => {
                                    try {
                                        Object.defineProperty(window, 'DisableDevtool', {
                                            get: () => ({
                                                isSuspend: true,
                                                init: () => {},
                                                suspend: () => {},
                                                resume: () => {},
                                                md5: (s) => s,
                                                version: '0.3.7'
                                            }),
                                            set: () => {},
                                            configurable: false
                                        });
                                        Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                                        Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                                        const originalConstructor = Function.prototype.constructor;
                                        Function.prototype.constructor = function(str) {
                                            if (str && (str.includes('debugger') || str.includes('debug'))) {
                                                return function() {};
                                            }
                                            return originalConstructor.apply(this, arguments);
                                        };
                                        console.log('🛡️ Protection re-injected after recovery');
                                    } catch (e) {}
                                });
                                
                                console.log('✅ Successfully navigated back to: ' + page.url());
                                
                                // 等待頁面重新載入
                                await new Promise(r => setTimeout(r, 2000));
                                
                                // 重新填寫表單（因為頁面重新載入了）
                                console.log('🔄 Re-filling form after recovery...');
                                
                                // 重新填寫 account_number
                                if (accountNumberVal) {
                                    try {
                                        const accEl = await page.$('input[placeholder="Please enter player account"]');
                                        if (accEl) {
                                            await accEl.focus();
                                            await page.keyboard.type(accountNumberVal, { delay: 100 });
                                            console.log('✅ Re-filled account_number: ' + accountNumberVal);
                                        }
                                    } catch (e) { console.log('⚠️  Re-fill account_number failed:', e.message); }
                                    await new Promise(r => setTimeout(r, 300));
                                }
                                
                                // 重新填寫日期
                                if (dateStartVal || dateEndVal) {
                                    try {
                                        const toOpen = await page.$('input.el-range-input[placeholder="Start date time"]') || await page.$('div.el-date-editor--datetimerange input') || await page.$('div.el-date-editor--datetimerange');
                                        if (toOpen) {
                                            await toOpen.click();
                                            await new Promise(resolve => setTimeout(resolve, 800));
                                        }
                                        
                                        if (dateStartVal) {
                                            const el = await page.$('input.el-input__inner[placeholder="Start Date"]') || await page.$('input[placeholder="Start Date"]');
                                            if (el) {
                                                await el.evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, dateStartVal);
                                                console.log('✅ Re-filled Start Date: ' + dateStartVal);
                                            }
                                        }
                                        
                                        if (dateEndVal) {
                                            const el = await page.$('input.el-input__inner[placeholder="End Date"]') || await page.$('input[placeholder="End Date"]');
                                            if (el) {
                                                await el.evaluate((e, v) => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); }, dateEndVal);
                                                console.log('✅ Re-filled End Date: ' + dateEndVal);
                                            }
                                        }
                                        
                                        const ok = await page.evaluate(() => {
                                            const btns = Array.from(document.querySelectorAll('button.el-picker-panel__link-btn, button.el-button'));
                                            for (const b of btns) { if ((b.textContent || '').trim() === 'OK') { b.click(); return true; } }
                                            return false;
                                        });
                                        if (ok) console.log('✅ OK clicked (retry)');
                                        await new Promise(r => setTimeout(r, 600));
                                    } catch (e) { console.log('⚠️  Re-fill dates failed:', e.message); }
                                }
                                
                                // 重新點擊 query
                                const queryRetry = await page.evaluate(() => {
                                    const btns = Array.from(document.querySelectorAll('button.el-button.el-button--primary.el-button--small, button.el-button--primary'));
                                    for (const b of btns) {
                                        const t = (b.textContent || '').trim();
                                        const s = (b.querySelector('span') ? (b.querySelector('span').textContent || '') : '').trim();
                                        if (t === 'query' || s === 'query') { b.click(); return true; }
                                    }
                                    return false;
                                });
                                if (queryRetry) { console.log('✅ Query button re-clicked'); }
                                await new Promise(r => setTimeout(r, 1200));
                                
                            } catch (e) {
                                console.log('⚠️  Failed to navigate back:', e.message);
                            }
                        }
                    }
                    
                    // 爬取 table.el-table__header / el-table__body 的資料（模仿 GLC）
                    await page.waitForSelector('table.el-table__header, table.el-table__body, table.el-table, table[class*="el-table"]', { timeout: 8000 }).catch(() => {});
                    let tableData = { found: false };
                    try {
                        tableData = await extractTableData(page);
                        if (tableData.found) console.log('✅ Table extracted: ' + (tableData.rowCount || 0) + ' rows, ' + (tableData.headerCount || 0) + ' columns');
                        else console.log('⚠️  Table not found: ' + (tableData.error || ''));
                    } catch (e) { console.log('⚠️  extractTableData: ' + (e.message || e)); }
                    console.log('📸 Taking screenshot after query...');
                    await page.screenshot({ path: path.join(workingDir, '1bet_step4b_dates_filled.png'), fullPage: true });
                    
                    // 最終檢查：確保不在 disable-devtool 頁面
                    const finalPageStatus = await page.evaluate(() => {
                        const url = window.location.href;
                        const title = document.title || '';
                        return {
                            url: url,
                            title: title,
                            isDisableDevtool: url.includes('disable-devtool') || 
                                             url.includes('theajack.github.io') ||
                                             title.includes('theajack.github.io') ||
                                             title === 'Blocked'
                        };
                    });
                    
                    if (finalPageStatus.isDisableDevtool) {
                        console.log('⚠️  Still on disable-devtool page before final screenshot!');
                        console.log('   URL: ' + finalPageStatus.url);
                        console.log('   Title: ' + finalPageStatus.title);
                        console.log('   Attempting final recovery...');
                        
                        const targetUrl = $redirectUrlJs.replace(/^"|"\$/g, '');
                        if (targetUrl && targetUrl !== 'null' && targetUrl !== '') {
                            try {
                                await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
                                await new Promise(r => setTimeout(r, 3000));
                                console.log('✅ Final recovery successful: ' + page.url());
                                
                                // 重新注入防護
                                await page.evaluate(() => {
                                    try {
                                        Object.defineProperty(window, 'DisableDevtool', {
                                            get: () => ({
                                                isSuspend: true,
                                                init: () => {},
                                                suspend: () => {},
                                                resume: () => {},
                                                md5: (s) => s,
                                                version: '0.3.7'
                                            }),
                                            set: () => {},
                                            configurable: false
                                        });
                                        Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                                        Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                                        const originalConstructor = Function.prototype.constructor;
                                        Function.prototype.constructor = function(str) {
                                            if (str && (str.includes('debugger') || str.includes('debug'))) {
                                                return function() {};
                                            }
                                            return originalConstructor.apply(this, arguments);
                                        };
                                    } catch (e) {}
                                });
                                
                                // 重新填寫完整表單
                                console.log('🔄 Re-filling complete form after final recovery...');
                                
                                // 1. 重新填寫 account_number
                                if (accountNumberVal) {
                                    try {
                                        const accEl = await page.$('input[placeholder="Please enter player account"]');
                                        if (accEl) {
                                            await accEl.click({ clickCount: 3 }); // 選中所有文字
                                            await page.keyboard.press('Backspace'); // 清除
                                            await accEl.type(accountNumberVal, { delay: 100 });
                                            console.log('✅ Final: Re-filled account_number: ' + accountNumberVal);
                                        }
                                    } catch (e) { console.log('⚠️  Final: Re-fill account failed:', e.message); }
                                    await new Promise(r => setTimeout(r, 500));
                                }
                                
                                // 2. 重新填寫日期
                                if (dateStartVal || dateEndVal) {
                                    try {
                                        // 點擊打開日期選擇器
                                        const toOpen = await page.$('input.el-range-input[placeholder="Start date time"]') || 
                                                      await page.$('div.el-date-editor--datetimerange input') || 
                                                      await page.$('div.el-date-editor--datetimerange');
                                        if (toOpen) {
                                            await toOpen.click();
                                            await new Promise(r => setTimeout(r, 1000));
                                            console.log('✅ Final: Date picker opened');
                                        }
                                        
                                        // 等待日期輸入框出現
                                        await page.waitForSelector('input.el-input__inner[placeholder="Start Date"], input[placeholder="Start Date"]', { timeout: 5000 }).catch(() => {});
                                        
                                        // 填寫開始日期
                                        if (dateStartVal) {
                                            const startEl = await page.$('input.el-input__inner[placeholder="Start Date"]') || await page.$('input[placeholder="Start Date"]');
                                            if (startEl) {
                                                await startEl.evaluate((e, v) => { 
                                                    e.value = v; 
                                                    e.dispatchEvent(new Event('input', { bubbles: true })); 
                                                    e.dispatchEvent(new Event('change', { bubbles: true })); 
                                                }, dateStartVal);
                                                console.log('✅ Final: Re-filled Start Date: ' + dateStartVal);
                                            }
                                            await new Promise(r => setTimeout(r, 300));
                                        }
                                        
                                        // 填寫結束日期
                                        if (dateEndVal) {
                                            const endEl = await page.$('input.el-input__inner[placeholder="End Date"]') || await page.$('input[placeholder="End Date"]');
                                            if (endEl) {
                                                await endEl.evaluate((e, v) => { 
                                                    e.value = v; 
                                                    e.dispatchEvent(new Event('input', { bubbles: true })); 
                                                    e.dispatchEvent(new Event('change', { bubbles: true })); 
                                                }, dateEndVal);
                                                console.log('✅ Final: Re-filled End Date: ' + dateEndVal);
                                            }
                                            await new Promise(r => setTimeout(r, 300));
                                        }
                                        
                                        // 點擊 OK
                                        const okClicked = await page.evaluate(() => {
                                            const btns = Array.from(document.querySelectorAll('button.el-picker-panel__link-btn, button.el-button'));
                                            for (const b of btns) { 
                                                if ((b.textContent || '').trim() === 'OK') { 
                                                    b.click(); 
                                                    return true; 
                                                } 
                                            }
                                            return false;
                                        });
                                        if (okClicked) console.log('✅ Final: OK clicked');
                                        await new Promise(r => setTimeout(r, 800));
                                        
                                    } catch (e) { 
                                        console.log('⚠️  Final: Re-fill dates failed:', e.message); 
                                    }
                                }
                                
                                // 3. 重新點擊 query 按鈕
                                const queryClicked = await page.evaluate(() => {
                                    const btns = Array.from(document.querySelectorAll('button.el-button.el-button--primary.el-button--small, button.el-button--primary'));
                                    for (const b of btns) {
                                        const t = (b.textContent || '').trim();
                                        const s = (b.querySelector('span') ? (b.querySelector('span').textContent || '') : '').trim();
                                        if (t === 'query' || s === 'query') { 
                                            b.click(); 
                                            return true; 
                                        }
                                    }
                                    return false;
                                });
                                if (queryClicked) { 
                                    console.log('✅ Final: Query button clicked'); 
                                } else { 
                                    console.log('⚠️  Final: Query button not found'); 
                                }
                                
                                // 等待查詢完成
                                await new Promise(r => setTimeout(r, 2000));
                                
                                // 重新抓取表格
                                console.log('🔍 Final: Extracting table data...');
                                await page.waitForSelector('table.el-table__header, table.el-table__body, table.el-table, table[class*="el-table"]', { timeout: 8000 }).catch(() => {});
                                try {
                                    tableData = await extractTableData(page);
                                    if (tableData.found) {
                                        console.log('✅ Final: Table extracted: ' + (tableData.rowCount || 0) + ' rows, ' + (tableData.headerCount || 0) + ' columns');
                                    } else {
                                        console.log('⚠️  Final: Table not found');
                                    }
                                } catch (e) {
                                    console.log('⚠️  Final: Table extraction failed:', e.message);
                                }
                                
                            } catch (e) {
                                console.log('⚠️  Final recovery failed:', e.message);
                            }
                        }
                    }
                    
                    // 最終截圖（若有找到 Date time 欄位，已加上紅框；若已點擊 Start date time，日期面板應已開啟）
                    console.log('📸 Final: Taking final screenshot...');
                    const screenshotPath = path.join(workingDir, '1bet_final.png');
                    await page.screenshot({
                        path: screenshotPath,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshotPath);
                    
                    // 返回結果（含 tableData 供 processScrapedData 儲存）
                    const result = {
                        success: true,
                        url: page.url(),
                        title: await page.title(),
                        screenshot: screenshotPath,
                        tableData: tableData
                    };
                    
                    // 保存結果
                    const resultPath = path.join(workingDir, 'scrape_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    
                    // 等待 5 秒讓使用者查看結果
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    
                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    const errorResult = {
                        success: false,
                        error: error.message,
                        stack: error.stack
                    };
                    fs.writeFileSync(path.join(workingDir, 'scrape_result.json'), JSON.stringify(errorResult, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    throw error;
                }
            }

            navigateAndScreenshot().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_1bet_screenshot.js');
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

        // 使用 Process 執行腳本，並實時輸出日誌
        $process = Process::path($workingDir)
            ->timeout(600) // 10 分鐘超時
            ->tty(false); // 不使用 TTY，以便捕獲所有輸出

        // 執行腳本並實時輸出
        $result = $process->run("node " . basename($scriptPath), function ($type, $output) {
            // 實時輸出腳本的控制台日誌
            echo $output;
        });

        if ($result->failed()) {
            $this->error("❌ Script execution failed");
            $errorOutput = $result->errorOutput();
            $stdOutput = $result->output();

            // 顯示完整的錯誤信息
            if (!empty($errorOutput)) {
                $this->line("Error output: " . $errorOutput);
            }
            if (!empty($stdOutput)) {
                $this->line("Standard output: " . $stdOutput);
            }

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
     * 處理截圖
     * @param array $result 爬取的結果資料
     */
    private function processScreenshot($result)
    {
        $this->info('');
        $this->info('4. Processing screenshots...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');

        // 處理截圖
        $screenshotsDir = storage_path('app/scraped_data');
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }

        // 處理所有步驟的截圖文件
        $screenshotFiles = [
            '1bet_step0_page_loaded.png' => 'step0_page_loaded',
            '1bet_step1_account_filled.png' => 'step1_account_filled',
            '1bet_step2_password_filled.png' => 'step2_password_filled',
            '1bet_step3_login_clicked.png' => 'step3_login_clicked',
            '1bet_step4_login_completed.png' => 'step4_login_completed',
            '1bet_step4b_dates_filled.png' => 'step4b_dates_filled',
            '1bet_final.png' => 'final'
        ];

        $savedCount = 0;
        foreach ($screenshotFiles as $screenshotFile => $stepName) {
            $screenshotSrc = $workingDir . '/' . $screenshotFile;
            if (file_exists($screenshotSrc)) {
                $screenshotDst = $screenshotsDir . '/1bet_' . $timestamp . '_' . $stepName . '.png';
                rename($screenshotSrc, $screenshotDst);
                $this->info("📸 {$stepName} screenshot saved: {$screenshotDst}");
                $savedCount++;
            }
        }

        if ($savedCount === 0) {
            $this->warn('⚠️  No screenshot files found');
        } else {
            $this->info("✅ Total {$savedCount} screenshot(s) saved");
        }

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Screenshot process completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('📄 Title: ' . ($result['title'] ?? 'N/A'));
        } else {
            $this->error('❌ Screenshot process failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        $this->info('');
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }

    /**
     * 處理並儲存爬取的表格資料（模仿 GLC：table.el-table__header / el-table__body）
     * @param array $result runPuppeteerScript 的結果，需含 tableData
     */
    private function processScrapedData($result)
    {
        $tableData = $result['tableData'] ?? null;
        if (!$tableData || empty($tableData['found']) || empty($tableData['data'])) {
            $this->warn('⚠️  No table data to save.');
            return;
        }

        $timestamp = date('Y-m-d_H-i-s');
        $allData = $tableData['data'];
        $headers = $tableData['headers'] ?? [];
        $totalRows = count($allData);

        $mergedData = [
            'metadata' => [
                'timestamp' => $timestamp,
                'url' => $result['url'] ?? '',
                'totalRows' => $totalRows,
                'source' => '1bet_el_table',
            ],
            'headers' => $headers,
            'data' => $allData,
        ];

        $fileName = "scraped_data/scraped_data_1bet_{$timestamp}.json";
        Storage::put($fileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("✅ Table data saved: {$fileName} ({$totalRows} rows)");
    }
}
