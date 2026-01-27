<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 1BET 瀏覽器截圖與爬蟲命令 V2
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
                // 檢查新格式（dataFile）或舊格式（tableData）
                $hasData = !empty($result['dataFile']) || 
                          (!empty($result['tableData']['found']) && !empty($result['tableData']['data']));
                
                if ($hasData) {
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
                console.log('🚀 Starting 1BET scraper...');
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

                    // 🛡️ Disable Debugger via CDP (silent)
                    try {
                        const client = await page.target().createCDPSession();
                        await client.send('Debugger.enable');
                        await client.send('Debugger.setBreakpointsActive', { active: false });
                        await client.send('Debugger.setSkipAllPauses', { skip: true });
                    } catch (e) {
                        // Silent fail
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
                                request.respond({ status: 204, body: '' });
                            } else {
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
                        window.close = function() { };
                        window.open = function() { return null; };
                        
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
                    
                    await page.goto($domainJs, { waitUntil: 'domcontentloaded', timeout: 60000 });

                    // Perform login
                    try {
                        $loginCode
                    } catch (loginError) {
                        // Silent - might be okay if navigated
                    }

                    const targetUrl = $redirectUrlJs;
                    const currentUrl = page.url();
                    if (!currentUrl.includes('#/gameOrder') && currentUrl !== targetUrl) {
                        console.log('🌐 Navigating to target URL: ' + targetUrl);
                        await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(e => {});
                    }
                    
                    await new Promise(r => setTimeout(r, 5000));

                    // Initialize screenshots list
                    const screenshots = {};
                    const registerScreenshot = async (name, filename) => {
                        const scPath = path.join(workingDir, filename);
                        try {
                            await page.screenshot({ path: scPath, fullPage: true });
                            screenshots[name] = scPath;
                        } catch (e) {
                            // Silent fail
                        }
                    };

                    // Form Filling logic with retry for "Execution context was destroyed"
                    let formFilled = false;
                    let fillAttempts = 0;
                    while (!formFilled && fillAttempts < 3) {
                        fillAttempts++;
                        
                        try {
                            if (page.url() === 'about:blank') {
                                await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
                                await new Promise(r => setTimeout(r, 3000));
                            }

                            // Account Number
                            const accountNumber = $accountNumberJs;
                            if (accountNumber && accountNumber !== 'null') {
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
                                await page.evaluate(() => {
                                    const okBtn = Array.from(document.querySelectorAll('button.el-button')).find(b => b.textContent.trim() === 'OK');
                                    if (okBtn) okBtn.click();
                                });
                                await new Promise(r => setTimeout(r, 1000));
                            }

                            formFilled = true;
                        } catch (e) {
                            if (e.message.includes('destroyed') || e.message.includes('navigation')) {
                                await new Promise(r => setTimeout(r, 2000));
                            } else {
                                await new Promise(r => setTimeout(r, 1000));
                            }
                        }
                    }

                    // Query
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
                        // Silent fail
                    }

                    // Take final screenshot
                    await registerScreenshot('final', '1bet_final.png');
                    
                    // 取得表頭（只需要一次）
                    const headers = await page.evaluate(() => {
                        const headerTable = document.querySelector('.el-table__header');
                        if (!headerTable) return [];
                        return Array.from(headerTable.querySelectorAll('th')).map(th => th.textContent.trim());
                    });
                    
                    // 提取單頁資料的函數
                    const extractPageData = async () => {
                        return await page.evaluate(() => {
                            const bodyTable = document.querySelector('.el-table__body');
                            if (!bodyTable) return [];
                            
                            const headerTable = document.querySelector('.el-table__header');
                            const headers = Array.from(headerTable?.querySelectorAll('th') || []).map(th => th.textContent.trim());
                            
                            return Array.from(bodyTable.querySelectorAll('tr')).map(tr => {
                                const cells = Array.from(tr.querySelectorAll('td'));
                                const rowData = {};
                                cells.forEach((td, i) => {
                                    const key = headers[i] || 'col_' + i;
                                    rowData[key] = td.textContent.trim();
                                });
                                return rowData;
                            }).filter(row => Object.keys(row).length > 0); // 過濾空行
                        });
                    };
                    
                    // 取得分頁資訊（包含偵錯）
                    const getPaginationInfo = async () => {
                        return await page.evaluate(() => {
                            // 嘗試多種選擇器找分頁元件
                            const paginationBox = document.querySelector('.pagination-box') || 
                                                document.querySelector('.el-pagination') ||
                                                document.querySelector('[class*="pagination"]');
                            
                            if (!paginationBox) {
                                return { found: false, currentPage: 1, totalPages: 1, totalRecords: 0, perPage: 10 };
                            }
                            
                            // 偵錯：列出分頁元件的 HTML 結構
                            const paginationHTML = paginationBox.innerHTML.substring(0, 500);
                            
                            // 嘗試找到總記錄數
                            let totalRecords = 0;
                            const totalEl = paginationBox.querySelector('.el-pagination__total, [class*="total"]');
                            if (totalEl) {
                                const match = totalEl.textContent.match(/\\d+/);
                                if (match) totalRecords = parseInt(match[0]);
                            }
                            
                            // 嘗試找到每頁筆數
                            let perPage = 10;
                            const sizeEl = paginationBox.querySelector('.el-pagination__sizes, [class*="sizes"]');
                            if (sizeEl) {
                                const match = sizeEl.textContent.match(/\\d+/);
                                if (match) perPage = parseInt(match[0]);
                            }
                            
                            // 計算總頁數
                            const totalPages = totalRecords > 0 ? Math.ceil(totalRecords / perPage) : 1;
                            
                            // 找當前頁
                            let currentPage = 1;
                            const activeEl = paginationBox.querySelector('.el-pager li.active, .el-pager li.is-active, .number.active, [class*="active"]');
                            if (activeEl) {
                                const num = parseInt(activeEl.textContent);
                                if (!isNaN(num)) currentPage = num;
                            }
                            
                            // 找下一頁按鈕
                            const nextBtn = paginationBox.querySelector('.btn-next') || 
                                        paginationBox.querySelector('.el-pagination__next') ||
                                        paginationBox.querySelector('button.btn-next') ||
                                        paginationBox.querySelector('[class*="next"]');
                            const hasNextBtn = !!nextBtn;
                            const nextBtnDisabled = nextBtn ? (nextBtn.disabled || nextBtn.classList.contains('disabled') || nextBtn.classList.contains('is-disabled')) : true;
                            
                            return { 
                                found: true, 
                                currentPage, 
                                totalPages, 
                                totalRecords, 
                                perPage,
                                hasNextBtn,
                                nextBtnDisabled,
                                paginationHTML
                            };
                        });
                    };
                    
                    // 檢查是否有下一頁
                    const hasNextPage = async () => {
                        return await page.evaluate(() => {
                            const paginationBox = document.querySelector('.pagination-box') || 
                                                document.querySelector('.el-pagination') ||
                                                document.querySelector('[class*="pagination"]');
                            if (!paginationBox) return false;
                            
                            // 嘗試多種選擇器
                            const nextBtn = paginationBox.querySelector('.btn-next') || 
                                        paginationBox.querySelector('.el-pagination__next') ||
                                        paginationBox.querySelector('button.btn-next') ||
                                        paginationBox.querySelector('[class*="next"]:not([class*="prev"])');
                            
                            if (!nextBtn) return false;
                            
                            // 檢查是否被禁用
                            const isDisabled = nextBtn.disabled || 
                                            nextBtn.classList.contains('disabled') || 
                                            nextBtn.classList.contains('is-disabled') ||
                                            nextBtn.getAttribute('disabled') !== null;
                            
                            return !isDisabled;
                        });
                    };
                    
                    // 點擊下一頁
                    const clickNextPage = async () => {
                        const clicked = await page.evaluate(() => {
                            const paginationBox = document.querySelector('.pagination-box') || 
                                                document.querySelector('.el-pagination') ||
                                                document.querySelector('[class*="pagination"]');
                            if (!paginationBox) return false;
                            
                            const nextBtn = paginationBox.querySelector('.btn-next') || 
                                        paginationBox.querySelector('.el-pagination__next') ||
                                        paginationBox.querySelector('button.btn-next') ||
                                        paginationBox.querySelector('[class*="next"]:not([class*="prev"])');
                            
                            if (nextBtn && !nextBtn.disabled) {
                                nextBtn.click();
                                return true;
                            }
                            return false;
                        });
                        
                        if (clicked) {
                            // 等待資料載入
                            await new Promise(r => setTimeout(r, 1000));
                        }
                        return clicked;
                    };
                    
                    // 開始分頁爬取
                    let allData = [];
                    let pageNum = 1;
                    const maxPages = 600; // 安全限制，最多爬 600 頁（足夠 4813 筆，每頁 10 筆 = 482 頁）
                    
                    // 先取得分頁資訊
                    const paginationInfo = await getPaginationInfo();
                    
                    // 如果找到總記錄數，計算預期頁數
                    const expectedPages = paginationInfo.totalRecords > 0 
                        ? Math.ceil(paginationInfo.totalRecords / paginationInfo.perPage) 
                        : maxPages;
                    
                    // 類似 OMG 的輸出格式
                    console.log('📄 Scraping ' + expectedPages + ' pages (' + paginationInfo.totalRecords + ' records)...');
                    
                    let consecutiveEmptyPages = 0;
                    const maxConsecutiveEmpty = 3;
                    const maxRetries = 3;
                    
                    while (pageNum <= Math.min(maxPages, expectedPages + 5)) {
                        // 提取當前頁資料（含重試機制）
                        let pageData = [];
                        let retryCount = 0;
                        
                        while (retryCount < maxRetries) {
                            pageData = await extractPageData();
                            
                            if (pageData.length > 0) {
                                break;
                            }
                            
                            retryCount++;
                            if (retryCount < maxRetries) {
                                await new Promise(r => setTimeout(r, 1000));
                            }
                        }
                        
                        if (pageData.length === 0) {
                            consecutiveEmptyPages++;
                            
                            if (consecutiveEmptyPages >= maxConsecutiveEmpty) {
                                console.log('⚠️ Too many failed pages, stopping pagination');
                                break;
                            }
                            
                            // 重試機制（類似 OMG）
                            if (pageNum <= 3) {
                                console.log('⏳ Retrying page ' + pageNum + '...');
                                await new Promise(r => setTimeout(r, 1000));
                                pageData = await extractPageData();
                                if (pageData.length > 0) {
                                    consecutiveEmptyPages = 0;
                                    allData = allData.concat(pageData);
                                }
                            }
                        } else {
                            consecutiveEmptyPages = 0;
                            allData = allData.concat(pageData);
                        }
                        
                        // 檢查是否有下一頁
                        const canGoNext = await hasNextPage();
                        if (!canGoNext) {
                            break;
                        }
                        
                        // 點擊下一頁
                        const clicked = await clickNextPage();
                        if (!clicked) {
                            break;
                        }
                        
                        pageNum++;
                        
                        // 每 200 頁休息一下，避免被檢測
                        if (pageNum % 200 === 0) {
                            await new Promise(r => setTimeout(r, 1000));
                        }
                    }
                    
                    // 類似 OMG 的總結輸出
                    console.log('\\n✅ Total scraped: ' + allData.length + ' rows from ' + pageNum + ' pages');
                    
                    // 構建類似 OMG 的資料結構
                    const timestamp = new Date().toISOString();
                    const mergedData = {
                        metadata: {
                            timestamp: timestamp,
                            url: targetUrl,
                            queryParams: {
                                date_start: $dateStartJs || null,
                                date_end: $dateEndJs || null,
                                account_number: $accountNumberJs || null
                            },
                            totalPages: pageNum,
                            totalRows: allData.length
                        },
                        headers: headers,
                        data: allData
                    };

                    // 直接將資料寫入檔案，避免記憶體問題
                    const fileTimestamp = timestamp.replace(/[:.]/g, '-').slice(0, 19);
                    const dataFilePath = path.join(workingDir, '1bet_data_' + fileTimestamp + '.json');
                    fs.writeFileSync(dataFilePath, JSON.stringify(mergedData, null, 2));
                    
                    // 類似 OMG 的資訊輸出
                    console.log('✅ Using scraped data: ' + allData.length + ' rows, ' + headers.length + ' headers');
                    console.log('📸 Screenshot saved to: ' + (screenshots['final'] || 'N/A'));

                    // 輸出精簡的 JSON 結果供 PHP 解析（不包含完整資料）
                    console.log(JSON.stringify({
                        status: 'success',
                        screenshots: screenshots,
                        dataFile: dataFilePath,
                        url: targetUrl,
                        queryParams: {
                            date_start: $dateStartJs || null,
                            date_end: $dateEndJs || null,
                            account_number: $accountNumberJs || null
                        },
                        summary: {
                            totalRows: allData.length,
                            totalPages: pageNum,
                            headers: headers
                        }
                    }));

                } catch (error) {
                    console.error('❌ Error:', error.message);
                    try {
                        const errorScreenshot = path.join(workingDir, '1bet_error.png');
                        await page.screenshot({ path: errorScreenshot, fullPage: true });
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
        
        // 增加超時時間至 1800 秒（30 分鐘），因為需要爬取多頁資料
        $process = Process::timeout(1800)->path(dirname($scriptPath));
        
        // 即時輸出，讓使用者看到進度（只保留最後幾行找 JSON）
        $lastLines = [];
        $maxLastLines = 10;
        
        $result = $process->run(['node', basename($scriptPath)], function ($type, $output) use (&$lastLines, $maxLastLines) {
            // 即時顯示輸出
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if (!empty($trimmedLine)) {
                    // 不顯示 JSON 結果行
                    if (!str_starts_with($trimmedLine, '{"status"')) {
                        $this->line($trimmedLine);
                    }
                    // 只保留最後幾行
                    $lastLines[] = $trimmedLine;
                    if (count($lastLines) > $maxLastLines) {
                        array_shift($lastLines);
                    }
                }
            }
        });

        if ($result->failed()) {
            $this->error('❌ Puppeteer execution failed: ' . $result->errorOutput());
            return null;
        }

        // 從最後幾行中找到 JSON 結果
        $jsonResult = null;
        for ($i = count($lastLines) - 1; $i >= 0; $i--) {
            if (str_starts_with($lastLines[$i], '{"status"')) {
                $jsonResult = json_decode($lastLines[$i], true);
                break;
            }
        }

        if (!$jsonResult || !isset($jsonResult['status']) || $jsonResult['status'] !== 'success') {
            $this->error('❌ Failed to parse result from script');
            // 清理暫存腳本檔案
            @unlink($scriptPath);
            return null;
        }

        // 清理暫存腳本檔案
        @unlink($scriptPath);
        
        return $jsonResult;
    }

    private function processScrapedData($result)
    {
        // 顯示查詢參數（類似 OMG）
        $queryParams = $result['queryParams'] ?? [];
        if (!empty($queryParams)) {
            $this->info('📋 Query Parameters:');
            if (!empty($queryParams['date_start'])) {
                $this->info("   - Date Start: {$queryParams['date_start']}");
            }
            if (!empty($queryParams['date_end'])) {
                $this->info("   - Date End: {$queryParams['date_end']}");
            }
            if (!empty($queryParams['account_number'])) {
                $this->info("   - Account Number: {$queryParams['account_number']}");
            }
        }
        
        // 顯示 URL
        if (!empty($result['url'])) {
            $this->info("🔗 URL: {$result['url']}");
        }
        
        // 新格式：資料已經由 Node.js 直接寫入檔案
        if (isset($result['dataFile'])) {
            $dataFile = $result['dataFile'];
            $summary = $result['summary'] ?? [];
            $rowCount = $summary['totalRows'] ?? 0;
            $totalPages = $summary['totalPages'] ?? 1;
            $headers = $summary['headers'] ?? [];
            
            $this->info("✅ Scraped {$rowCount} rows from {$totalPages} page(s)");
            $this->info("📊 Headers: " . count($headers));
            $this->info("💾 Data file: {$dataFile}");
        } 
        // 舊格式：資料在 tableData 中
        elseif (isset($result['tableData'])) {
            $tableData = $result['tableData'];
            $rowCount = count($tableData['data'] ?? []);
            $totalPages = $tableData['totalPages'] ?? 1;
            
            $this->info("✅ Scraped {$rowCount} rows from {$totalPages} page(s)");
            
            // 儲存資料到 JSON 檔案
            $timestamp = date('Y-m-d_H-i-s');
            $fileName = "scraped_data/1bet_data_{$timestamp}.json";
            Storage::put($fileName, json_encode($tableData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("💾 Data saved to: storage/app/{$fileName}");
        }
        
        // 清理中間步驟的截圖，只保留最後一張
        $this->cleanupIntermediateScreenshots();
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
    
    /**
     * 清理中間步驟的截圖，只保留 final
     */
    private function cleanupIntermediateScreenshots()
    {
        $screenshotDir = storage_path('app/scraped_data');
        $patterns = [
            '1bet_step1_*.png',
            '1bet_step2_*.png', 
            '1bet_step3_*.png',
            '1bet_step4_*.png',
            '1bet_pre_query.png',
        ];
        
        $deletedCount = 0;
        foreach ($patterns as $pattern) {
            $files = glob($screenshotDir . '/' . $pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                    $deletedCount++;
                }
            }
        }
        
        if ($deletedCount > 0) {
            $this->info("🧹 Cleaned up {$deletedCount} intermediate screenshot(s)");
        }
    }
}
