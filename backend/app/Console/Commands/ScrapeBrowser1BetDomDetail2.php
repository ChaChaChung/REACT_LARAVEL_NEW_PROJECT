<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 1BET 瀏覽器截圖與爬蟲命令 V2
 */
class ScrapeBrowser1BetDomDetail2 extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-1bet-dom-detail2 {url} {date_start?} {date_end?} {account_number?}
     */
    protected $signature = 'agent:scrape-1bet-dom-detail2 {url} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Navigate to 1BET domain, login, and scrape data with anti-detection bypass';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 從 .env 獲取配置
        $domain = env('1BET_AGENT_DOMAIN', '');
        $account = env('1BET_AGENT_ACCOUNT', '');
        $password = env('1BET_AGENT_PASSWORD', '');

        if (empty($domain)) {
            $this->error('❌ 1BET_AGENT_DOMAIN is not set in .env file');
            return 1;
        }

        $url = $this->argument('url');
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');

        $this->info('=== 1BET Browser Scraper V2 ===');
        $this->info("Login Domain: {$domain}");
        $this->info("Target URL: {$url}");
        $this->info("Account: {$account}");
        $this->info("Date Start: " . ($dateStart ?: 'None'));
        $this->info("Date End: " . ($dateEnd ?: 'None'));
        $this->info("Account Number: " . ($accountNumber ?: 'None'));
        $this->info('Start time: ' . date('Y-m-d H:i:s'));

        // 檢查環境
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        try {
            $scriptPath = $this->createPuppeteerScript($domain, $url, $dateStart, $dateEnd, $accountNumber);

            // 執行腳本
            $result = $this->runPuppeteerScript($scriptPath);

            if ($result) {
                $this->processScreenshot($result);
                if (!empty($result['tableData']['found']) && !empty($result['tableData']['data'])) {
                    $this->processScrapedData($result);
                }
                return 0;
            }
        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
        }

        return 1;
    }

    /**
     * 檢查 Node.js 和 Puppeteer 環境
     */
    private function checkNodeJs()
    {
        $result = Process::run('node --version');
        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            return false;
        }
        return true;
    }

    /**
     * 創建 Puppeteer 自動化腳本
     */
    private function createPuppeteerScript($domain, $redirectUrl, $dateStart, $dateEnd, $accountNumber)
    {
        $domainJs = json_encode($domain);
        $redirectUrlJs = json_encode($redirectUrl);
        $workingDir = storage_path('app/scraped_data');
        $workingDirJs = json_encode($workingDir);
        $lang = env('1BET_AGENT_LANG', 'zh-TW');
        $langJs = json_encode($lang);

        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';

        $loginCode = $this->generate1BetPuppeteerLoginCode('page', $workingDirJs, $redirectUrlJs);

        $script = <<<JS
const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
puppeteer.use(StealthPlugin());
const fs = require('fs');
const path = require('path');

const workingDir = $workingDirJs;
if (!fs.existsSync(workingDir)) fs.mkdirSync(workingDir, { recursive: true });

async function run() {
    console.log('🚀 Starting Puppeteer...');
    const browser = await puppeteer.launch({
        headless: 'new',
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-blink-features=AutomationControlled',
            '--disable-web-security',
        ],
        ignoreDefaultArgs: ['--enable-automation'],
        executablePath: process.env.CHROME_BIN || undefined
    });

    try {
        const page = await browser.newPage();
        
        // Listen to console from page
        page.on('console', msg => console.log('PAGE LOG:', msg.text()));

        // 🛡️ Disable Debugger via CDP
        try {
            const client = await page.target().createCDPSession();
            await client.send('Debugger.enable');
            await client.send('Debugger.setBreakpointsActive', { active: false });
            await client.send('Debugger.setSkipAllPauses', { skip: true });
            console.log('🛡️ CDP Debugger disabled (with setSkipAllPauses)');
        } catch (e) {
            console.log('⚠️ Failed to disable debugger via CDP:', e.message);
        }

        await page.setViewport({ width: 1920, height: 1080 });
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        // 🛡️ Anti-detection and disable-devtool bypass (enhanced)
        await page.setRequestInterception(true);
        page.on('request', (request) => {
            const url = request.url().toLowerCase();
            const isDisableDevtool = 
                url.includes('disable-devtool') || 
                url.includes('theajack.github.io') ||
                url.includes('cdn.jsdelivr.net/npm/disable-devtool') ||
                url.includes('unpkg.com/disable-devtool') ||
                url.includes('devtools-detector') ||
                url.includes('console-ban');
            
            if (isDisableDevtool) {
                if (request.isNavigationRequest()) {
                    console.log('🛡️ Blocked navigation to disable-devtool');
                    request.respond({ status: 204, body: '' });
                } else {
                    console.log('🛡️ Blocked script request to disable-devtool: ' + url);
                    request.respond({
                        status: 200,
                        contentType: 'text/javascript',
                        body: 'window.DisableDevtool = { isSuspend: true, init: () => {}, suspend: () => {}, resume: () => {}, md5: (s) => s, version: "0.3.7" }; window.devtoolsDetector = { launch: () => {}, stop: () => {}, isLaunch: () => false };'
                    });
                }
            } else {
                request.continue();
            }
        });

        await page.evaluateOnNewDocument((l) => {
            // Bypass webdriver detection
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            window.chrome = { runtime: {} };
            Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
            Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });

            // 🛡️ Prevent window closure and blank redirects
            window.close = function() { console.log('🛡️ Prevented window.close()'); };
            window.open = function() { console.log('🛡️ Prevented window.open()'); return null; };
            
            // Language injection
            try {
                const v = l || 'zh-TW';
                ['lang', 'locale', 'language'].forEach(k => {
                    sessionStorage.setItem(k, v);
                    localStorage.setItem(k, v);
                });
            } catch (e) {}

            // 🛡️ DisableDevtool Mock
            const mock = {
                isSuspend: true,
                init: () => { },
                suspend: () => { },
                resume: () => { },
                md5: (s) => s,
                version: '0.3.7'
            };
            Object.defineProperty(window, 'DisableDevtool', {
                get: () => mock,
                set: () => {},
                configurable: false
            });

            // 🛡️ Function constructor bypass for debugger
            const oConstructor = Function.prototype.constructor;
            Function.prototype.constructor = function(str) {
                if (str && (str.includes('debugger') || str.includes('debug'))) {
                    return function() {};
                }
                return oConstructor.apply(this, arguments);
            };

            // 🛡️ RegExp bypass for probes
            const oRegExpToString = RegExp.prototype.toString;
            RegExp.prototype.toString = function() {
                if (this.source === '(?=a)b') return 'function RegExp() { [native code] }';
                return oRegExpToString.call(this);
            };
            
            // 🛡️ 【關鍵】Performance API 時間隨機化 - 防止 type=6 檢測
            try {
                const originalNow = performance.now.bind(performance);
                performance.now = function() {
                    return originalNow() + Math.random() * 0.1;
                };
            } catch (e) {}
            
            // 🛡️ 【關鍵】覆寫 console 方法 - 防止 type=6 時間測量檢測
            try {
                const originalConsole = {
                    log: console.log.bind(console),
                    table: console.table.bind(console),
                    warn: console.warn.bind(console),
                    error: console.error.bind(console),
                    clear: console.clear.bind(console)
                };
                
                // 快速返回，不讓 console.log 觸發時間差異
                console.log = function() {
                    // 使用 setTimeout 延遲執行，避免時間測量
                    const args = Array.from(arguments);
                    setTimeout(() => originalConsole.log.apply(console, args), 0);
                };
                console.table = function() {
                    const args = Array.from(arguments);
                    setTimeout(() => originalConsole.table.apply(console, args), 0);
                };
                console.clear = function() {
                    setTimeout(() => originalConsole.clear(), 0);
                };
            } catch (e) {}
            
            // 🛡️ 防止頁面被清空（innerHTML = ''）
            try {
                const originalInnerHTMLDescriptor = Object.getOwnPropertyDescriptor(Element.prototype, 'innerHTML');
                Object.defineProperty(Element.prototype, 'innerHTML', {
                    set: function(value) {
                        if ((this === document.body || this === document.documentElement) && 
                            (value === '' || value === ' ' || value.length < 10)) {
                            console.log('🛡️ Blocked attempt to clear page content');
                            return;
                        }
                        return originalInnerHTMLDescriptor.set.call(this, value);
                    },
                    get: function() {
                        return originalInnerHTMLDescriptor.get.call(this);
                    },
                    configurable: true
                });
            } catch (e) {}
            
            // 🛡️ 防止 document.write 清空頁面
            try {
                const originalWrite = document.write.bind(document);
                document.write = function(content) {
                    if (!content || content.length < 10) {
                        return;
                    }
                    return originalWrite(content);
                };
            } catch (e) {}
            
            // 🛡️ 偽裝 devtoolsDetector
            try {
                window.devtoolsDetector = {
                    launch: () => {},
                    stop: () => {},
                    isLaunch: () => false,
                    addListener: () => {},
                    removeListener: () => {}
                };
            } catch (e) {}
            
            // 🛡️ 攔截 setInterval（阻止檢測循環）
            try {
                const originalSetInterval = window.setInterval;
                window.setInterval = function(callback, delay) {
                    if (typeof callback === 'function' && typeof delay === 'number' && delay <= 500) {
                        const str = callback.toString();
                        if (str.includes('devtool') || str.includes('debugger') || str.includes('outerWidth') || str.includes('outerHeight') || str.includes('console')) {
                            return originalSetInterval(function() {}, delay);
                        }
                    }
                    return originalSetInterval.apply(this, arguments);
                };
            } catch (e) {}
        }, $langJs);

        console.log('🌐 Navigating to login page: ' + $domainJs);
        await page.goto($domainJs, { waitUntil: 'domcontentloaded', timeout: 60000 });

        // Perform login
        console.log('🔐 Starting login process...');
        try {
            $loginCode
        } catch (loginError) {
            console.log('⚠️ Login process error (might be okay if navigated):', loginError.message);
        }

        const targetUrl = $redirectUrlJs;
        const currentUrl = page.url();
        console.log('🔍 Current URL: ' + currentUrl);
        if (!currentUrl.includes('#/gameOrder') && currentUrl !== targetUrl) {
            console.log('🌐 Navigating to target URL: ' + targetUrl);
            await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(e => console.log('⚠️ Navigation to target URL warning:', e.message));
        } else {
            console.log('✅ Already at target URL or similar');
        }
        
        console.log('⏳ Waiting for page stability...');
        await new Promise(r => setTimeout(r, 5000));

        // Initialize screenshots list
        const screenshots = {};
        const registerScreenshot = async (name, filename) => {
            const scPath = path.join(workingDir, filename);
            try {
                await page.screenshot({ path: scPath, fullPage: true });
                screenshots[name] = scPath;
                console.log('✅ Screenshot saved: ' + scPath);
            } catch (e) {
                console.log('⚠️ Failed to take screenshot ' + name + ':', e.message);
            }
        };

        // Form Filling logic with retry for "Execution context was destroyed"
        let formFilled = false;
        let fillAttempts = 0;
        while (!formFilled && fillAttempts < 3) {
            fillAttempts++;
            console.log(`📝 Filling search form (attempt \${fillAttempts})...`);
            
            try {
                if (page.url() === 'about:blank') {
                    console.log('❌ Page is about:blank, reloading...');
                    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
                    await new Promise(r => setTimeout(r, 3000));
                }

                // Account Number
                const accountNumber = $accountNumberJs;
                if (accountNumber && accountNumber !== 'null') {
                    console.log('👤 Filling account number: ' + accountNumber);
                    const accInput = await page.waitForSelector('input[placeholder="Please enter player account"]', { timeout: 10000 });
                    await accInput.focus();
                    await page.keyboard.down('Control');
                    await page.keyboard.press('A');
                    await page.keyboard.up('Control');
                    await page.keyboard.press('Backspace');
                    await page.keyboard.type(accountNumber, { delay: 50 });
                }

                // Date Range
                const dateStart = $dateStartJs;
                const dateEnd = $dateEndJs;
                if ((dateStart && dateStart !== 'null') || (dateEnd && dateEnd !== 'null')) {
                    console.log('📅 Filling date range: ' + dateStart + ' to ' + dateEnd);
                    const datePicker = await page.waitForSelector('input.el-range-input[placeholder="Start date time"], .el-date-editor--datetimerange', { timeout: 10000 });
                    await datePicker.click();
                    await new Promise(r => setTimeout(r, 2000));
                    
                    if (dateStart && dateStart !== 'null') {
                        const startInput = await page.waitForSelector('input[placeholder="Start Date"]', { timeout: 5000 });
                        await startInput.focus();
                        await startInput.evaluate(el => el.select());
                        await page.keyboard.press('Backspace');
                        await page.keyboard.type(dateStart, { delay: 50 });
                        await startInput.evaluate(el => {
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    }
                    if (dateEnd && dateEnd !== 'null') {
                        const endInput = await page.waitForSelector('input[placeholder="End Date"]', { timeout: 5000 });
                        await endInput.focus();
                        await endInput.evaluate(el => el.select());
                        await page.keyboard.press('Backspace');
                        await page.keyboard.type(dateEnd, { delay: 50 });
                        await endInput.evaluate(el => {
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                            el.dispatchEvent(new Event('change', { bubbles: true }));
                        });
                    }

                    // Click OK
                    console.log('🖱️ Clicking OK on date picker...');
                    await page.evaluate(() => {
                        const okBtn = Array.from(document.querySelectorAll('button.el-button')).find(b => b.textContent.trim() === 'OK');
                        if (okBtn) okBtn.click();
                    });
                    await new Promise(r => setTimeout(r, 1000));
                }

                formFilled = true;
                console.log('✅ Form filled successfully');
            } catch (e) {
                console.log(`⚠️ Form filling error (attempt \${fillAttempts}):`, e.message);
                if (e.message.includes('destroyed') || e.message.includes('navigation')) {
                    await new Promise(r => setTimeout(r, 2000));
                } else {
                    // If it's not a navigation error, taking a break anyway
                    await new Promise(r => setTimeout(r, 1000));
                }
            }
        }

        // Take pre-query screenshot
        await registerScreenshot('pre_query', '1bet_pre_query.png');

        // Query
        console.log('🔍 Clicking Query button...');
        try {
            await page.evaluate(() => {
                const queryBtn = Array.from(document.querySelectorAll('button.el-button--primary')).find(b => {
                    const text = b.textContent.trim().toLowerCase();
                    return text === 'query' || text === '查询' || text === '查詢';
                });
                if (queryBtn) queryBtn.click();
            });
            await new Promise(r => setTimeout(r, 5000));
        } catch (e) {
            console.log('⚠️ Query button click error:', e.message);
        }

        // Take final screenshot
        await registerScreenshot('final', '1bet_final.png');

        // Extract Data
        console.log('📊 Extracting table data...');
        const tableData = await page.evaluate(() => {
            const bodyTable = document.querySelector('.el-table__body');
            const headerTable = document.querySelector('.el-table__header');
            if (!bodyTable) return { found: false };

            const headers = Array.from(headerTable?.querySelectorAll('th') || []).map(th => th.textContent.trim());
            const rows = Array.from(bodyTable.querySelectorAll('tr')).map(tr => {
                const cells = Array.from(tr.querySelectorAll('td'));
                const rowData = {};
                cells.forEach((td, i) => {
                    const key = headers[i] || `col_\${i}`;
                    rowData[key] = td.textContent.trim();
                });
                return rowData;
            });
            return { found: true, data: rows };
        }).catch(e => {
            console.log('⚠️ Data extraction error:', e.message);
            return { found: false, error: e.message };
        });

        console.log(JSON.stringify({
            status: 'success',
            screenshots: screenshots,
            tableData: tableData
        }));

    } catch (error) {
        console.error('❌ Script Error:', error.message);
        // Take error screenshot
        try {
            const errorScreenshot = path.join(workingDir, '1bet_error.png');
            await page.screenshot({ path: errorScreenshot, fullPage: true });
            console.log('📸 Error screenshot saved: ' + errorScreenshot);
        } catch (e) {}
        process.exit(1);
    } finally {
        await browser.close();
    }
}

run();
JS;

        $scriptPath = tempnam($workingDir, 'puppeteer_1bet_');
        rename($scriptPath, $scriptPath .= '.js');
        file_put_contents($scriptPath, $script);
        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Executing Puppeteer script...');
        // 增加超時時間至 300 秒，因為 Puppeteer 登入與導航較耗時
        $process = Process::timeout(300)->run(['node', $scriptPath]);

        $output = $process->output();
        if (!empty($output)) {
            $this->line($output);
        }

        if ($process->failed()) {
            $this->error('❌ Puppeteer execution failed: ' . $process->errorOutput());
            return null;
        }

        // 解析最後一行的 JSON
        $lines = explode("\n", trim($output));
        $lastLine = end($lines);
        $result = json_decode($lastLine, true);

        if (!$result || !isset($result['status']) || $result['status'] !== 'success') {
            $this->error('❌ Failed to parse result from script');
            return null;
        }

        return $result;
    }

    /**
     * 處理截圖結果
     */
    private function processScreenshot($result)
    {
        if (!empty($result['screenshots'])) {
            foreach ($result['screenshots'] as $name => $path) {
                $this->info("✅ Screenshot taken [\$name]: \$path");
            }
        } elseif (!empty($result['screenshot'])) {
            $this->info('✅ Screenshot taken: ' . $result['screenshot']);
        }
    }

    private function processScrapedData($result)
    {
        $this->info('✅ Scraped ' . count($result['tableData']['data']) . ' rows of data');
        // 這裡可以根據需求存入資料庫
    }
}
