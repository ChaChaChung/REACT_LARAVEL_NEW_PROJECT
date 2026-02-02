<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserTagDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-tag-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {player_account?} - 玩家帳號（可選參數）
     * {--concurrency=4} - 併發數量（可選，預設為 4）
     */
    protected $signature = 'agent:scrape-tag-dom-detail {url} {date_start?} {date_end?} {player_account?} {--concurrency=8}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from TAG DOM elements using browser automation with detailed information';

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

        $this->info('=== Browser DOM Scraper (TAG) ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$date_start}");
        $this->info("Date End: {$date_end}");
        $this->info("Player Account: {$player_account}");
        $this->info("Concurrency: {$concurrency}");

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

        // 獲取認證 cookies 程式碼片段（主頁面用）
        $cookiesCodeForPage = $this->generateTagPuppeteerCookiesCode('page');

        // 將 date 轉換為 JavaScript 可用的格式
        $dateStartJs = $date_start ? json_encode(date('Y-m-d', strtotime($date_start))) : 'null';
        $dateEndJs = $date_end ? json_encode(date('Y-m-d', strtotime($date_end))) : 'null';
        $playerAccountJs = $player_account ? json_encode($player_account) : 'null';

        // 有日期時組出帶查詢參數的目標 URL，直接跳轉（不靠日期選擇器）
        $parsed = parse_url($url);
        $basePath = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '/');
        $finalUrl = $url;
        if ($date_start || $date_end) {
            $q = [];
            if ($date_start) {
                $q['startTime'] = date('Y-m-d', strtotime($date_start)) . ' 00:00:00';
            }
            if ($date_end) {
                $q['endTime'] = date('Y-m-d', strtotime($date_end)) . ' 23:59:59';
            }
            if ($player_account) {
                $q['platformPlayerID'] = $player_account;
            }
            $q['lang'] = env('TAG_AGENT_LANG', 'en-us');
            $q['timezone'] = env('TAG_AGENT_TIMEZONE', '-4');
            $q['currentPage'] = '1';
            $q['pageSize'] = '100';
            $q['brandID'] = 'all';
            $queryString = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
            $queryString = str_replace('%20', '+', $queryString);
            $finalUrl = $basePath . '?' . $queryString;
        }
        $finalUrlJs = json_encode($finalUrl);
        $useUrlParamsJs = ($date_start || $date_end) ? 'true' : 'false';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer-extra');
            const StealthPlugin = require('puppeteer-extra-plugin-stealth');
            puppeteer.use(StealthPlugin());
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容（併發版本）
             */
            async function scrapeDOMContent() {
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

                    // stealth 外掛已處理多數反偵測；可選：自訂 User-Agent
                    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

                    // TAG 後台為 SPA，不攔截請求，讓所有資源（JS/CSS/XHR）正常載入以利登入後渲染
                    // await page.setRequestInterception(true);
                    // page.on('request', (req) => { req.continue(); });

                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            // console.log('❌ Browser console error:', msg.text());
                        }
                    });

                    const finalTargetUrl = $finalUrlJs;
                    const useUrlParams = $useUrlParamsJs;
                    // 若要用 cookie 代登入：必須「先」進入目標 domain 任一頁，再 setCookie，再 goto 目標 URL（第二次請求才會帶上 cookie）
                    const tagCookieLoginUrl = (() => {
                        try {
                            const u = new URL(finalTargetUrl);
                            return u.origin + '/';
                        } catch (e) { return null; }
                    })();
                    if (tagCookieLoginUrl) {
                        console.log('🌐 First navigating to domain (for cookie context):', tagCookieLoginUrl);
                        await page.goto(tagCookieLoginUrl, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                    // 在第一次進入該 domain 後立刻設定 cookie，之後再 goto 目標頁時請求會帶上這些 cookie
                    $cookiesCodeForPage
                    console.log('🌐 Navigating to:', finalTargetUrl);
                    await page.goto(finalTargetUrl, {
                        waitUntil: 'domcontentloaded',
                        timeout: 20000
                    });
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 若仍為登入頁：再設一次 cookie 後重新導向（有時可補上 lang/role/timezone 或 session）
                    if (page.url().includes('/login')) {
                        $cookiesCodeForPage
                    }
                    // 若目前在登入頁，再試一次導向目標頁
                    if (page.url().includes('/login')) {
                        await page.goto(finalTargetUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
                    }

                    // 偵測 session expired：若被導回登入頁或頁面出現 "Session expired, please log in again (1002)" 等，重設 cookie 並重新導向
                    const checkSessionExpired = async () => {
                        const isLogin = page.url().includes('/login');
                        const hasExpired = await page.evaluate(() => {
                            const text = document.body ? document.body.innerText : '';
                            return /session\s*expired|Session expired,\s*please\s*log\s*in\s*again|\(1002\)|登入已過期|會話已過期/i.test(text);
                        }).catch(() => false);
                        if (isLogin || hasExpired) {
                            console.log('⚠️  Session expired (1002) or redirected to login. Re-applying cookies and retrying...');
                            $cookiesCodeForPage
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            console.log('🔄 Navigating to target URL again...');
                            await page.goto(finalTargetUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
                            const searchBtnAfter = await page.evaluateHandle(() => {
                                const btns = Array.from(document.querySelectorAll('button.el-button.el-button--primary'));
                                return btns.find(b => (b.querySelector('span') && b.querySelector('span').textContent.trim() === 'Search') || b.textContent.trim().includes('Search')) || null;
                            });
                            const searchElAfter = searchBtnAfter.asElement();
                            if (searchElAfter) {
                                await searchElAfter.click();
                                console.log('✅ Search button clicked (after retry)');
                                await new Promise(resolve => setTimeout(resolve, 1500));
                            }
                            if (searchBtnAfter) await searchBtnAfter.dispose();
                        }
                    };
                    await checkSessionExpired();

                    
                    // 選日期前再確認一次 session 未過期
                    await checkSessionExpired();
                    
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    try {
                        if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs !== '') {
                            dateStartParsed = JSON.parse($dateStartJs);
                        }
                        if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs !== '') {
                            dateEndParsed = JSON.parse($dateEndJs);
                        }
                    } catch (e) {
                        dateStartParsed = $dateStartJs !== 'null' ? $dateStartJs : null;
                        dateEndParsed = $dateEndJs !== 'null' ? $dateEndJs : null;
                    }
                    const doDateSelection = async () => {
                        if (!(dateStartParsed || dateEndParsed)) return;
                        try {
                            console.log('📅 Clicking Start Time to select date range...');
                            await page.waitForSelector('input.el-range-input[placeholder="Start Time"], input.el-range-input', { timeout: 15000 }).catch(() => {});
                            const startTimeInput = await page.$('input.el-range-input[placeholder="Start Time"], input.el-range-input');
                            if (startTimeInput) {
                                await startTimeInput.click();
                                await new Promise(resolve => setTimeout(resolve, 500));
                                if (dateStartParsed) {
                                    await page.waitForSelector('input.el-input__inner[placeholder="Start Date"], input[placeholder*="Start Date"]', { timeout: 5000 }).catch(() => {});
                                    await page.evaluate((v) => {
                                        const el = document.querySelector('input.el-input__inner[placeholder="Start Date"], input[placeholder*="Start Date"]');
                                        if (el) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
                                    }, dateStartParsed);
                                    await new Promise(resolve => setTimeout(resolve, 200));
                                }
                                if (dateEndParsed) {
                                    await page.waitForSelector('input.el-input__inner[placeholder="End Date"], input[placeholder*="End Date"]', { timeout: 5000 }).catch(() => {});
                                    await page.evaluate((v) => {
                                        const el = document.querySelector('input.el-input__inner[placeholder="End Date"], input[placeholder*="End Date"]');
                                        if (el) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
                                    }, dateEndParsed);
                                    await new Promise(resolve => setTimeout(resolve, 200));
                                }
                                const okBtn = await page.evaluateHandle(() => {
                                    const btns = Array.from(document.querySelectorAll('button.el-button'));
                                    return btns.find(b => b.textContent.trim() === 'OK') || null;
                                });
                                const okEl = okBtn.asElement();
                                if (okEl) { await okEl.click(); console.log('✅ Date range confirmed'); }
                                await okBtn.dispose();
                            }
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        } catch (e) {
                            console.log('⚠️  Date selection error: ' + e.message);
                        }
                    };
                    if ((dateStartParsed || dateEndParsed) && !useUrlParams) {
                        await doDateSelection();
                    } else if (useUrlParams) {
                        console.log('📅 Date in URL, skip date picker');
                    }
                    
                    // 到達目標 URL 後一律點 Search 按鈕
                    try {
                        const searchBtn = await page.evaluateHandle(() => {
                            const btns = Array.from(document.querySelectorAll('button.el-button.el-button--primary'));
                            return btns.find(b => (b.querySelector('span') && b.querySelector('span').textContent.trim() === 'Search') || b.textContent.trim().includes('Search')) || null;
                        });
                        const searchEl = searchBtn.asElement();
                        if (searchEl) {
                            await searchEl.click();
                            console.log('✅ Search button clicked');
                            await new Promise(resolve => setTimeout(resolve, 2500));
                        } else {
                            console.log('⚠️  Search button not found');
                        }
                        if (searchBtn) await searchBtn.dispose();
                    } catch (e) {
                        console.log('⚠️  Search button click: ' + e.message);
                    }
                    console.log('🔍 Checking if page redirected to login after Search click...');
                    await checkSessionExpired();

                    // 簡化滾動操作（只滾動一次，減少等待時間）
                    await page.evaluate(() => {
                        window.scrollTo(0, document.body.scrollHeight);
                    });
                    await new Promise(resolve => setTimeout(resolve, 500));
                    await page.evaluate(() => {
                        window.scrollTo(0, 0);
                    });
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    
                    const isSessionExpiredPage = async () => {
                        if (page.url().includes('/login')) return true;
                        return await page.evaluate(() => {
                            const text = document.body ? document.body.innerText : '';
                            return /Session expired,\s*please\s*log\s*in\s*again|\(1002\)|session\s*expired/i.test(text);
                        }).catch(() => false);
                    };
                    let tableFound = false;
                    for (let retry = 0; retry < 15; retry++) {
                        if (await isSessionExpiredPage()) {
                            console.log('⚠️  Session expired (1002) or on login page during table wait, recovering...');
                            await checkSessionExpired();
                            if (!useUrlParams) await doDateSelection();
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                        const tableCheck = await page.evaluate(() => {
                            // 檢查外層容器
                            const elTableDiv = document.querySelector('div.el-table, .el-table');
                            // 檢查表頭表格
                            const headerTable = document.querySelector('table.el-table__header');
                            // 檢查表體表格
                            const bodyTable = document.querySelector('table.el-table__body');
                            // 檢查是否有任何包含 el-table 的元素
                            const anyElTable = document.querySelector('[class*="el-table"]');
                            
                            // 檢查表格是否有數據行
                            let hasDataRows = false;
                            if (bodyTable) {
                                const rows = bodyTable.querySelectorAll('tbody tr, tr');
                                hasDataRows = rows.length > 0;
                            } else if (elTableDiv) {
                                const innerBodyTable = elTableDiv.querySelector('table.el-table__body');
                                if (innerBodyTable) {
                                    const rows = innerBodyTable.querySelectorAll('tbody tr, tr');
                                    hasDataRows = rows.length > 0;
                                }
                            }
                            
                            return {
                                hasElTableDiv: !!elTableDiv,
                                hasHeaderTable: !!headerTable,
                                hasBodyTable: !!bodyTable,
                                hasAnyElTable: !!anyElTable,
                                hasDataRows: hasDataRows,
                                found: !!(elTableDiv || headerTable || bodyTable || anyElTable)
                            };
                        });
                        
                        if (tableCheck.found) {
                            console.log('✅ Table found!', JSON.stringify(tableCheck));
                            tableFound = true;
                            // 如果找到表格但沒有數據行，再等待一下
                            if (!tableCheck.hasDataRows) {
                                console.log('⚠️  Table found but no data rows yet, waiting...');
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            } else {
                                break;
                            }
                        }
                        
                        if (retry < 14) {
                            console.log('⏳ Retry ' + (retry + 1) + '/15: Waiting for table...');
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                    }
                    
                    if (!tableFound) {
                        // 獲取調試信息
                        const debugInfo = await page.evaluate(() => {
                            const allElTableElements = document.querySelectorAll('[class*="el-table"]');
                            const allTables = document.querySelectorAll('table');
                            return {
                                url: window.location.href,
                                title: document.title,
                                hasElTableDiv: !!document.querySelector('div.el-table, .el-table'),
                                hasHeaderTable: !!document.querySelector('table.el-table__header'),
                                hasBodyTable: !!document.querySelector('table.el-table__body'),
                                allTables: allTables.length,
                                allElTableElements: allElTableElements.length,
                                tableClasses: Array.from(allTables).map(t => t.className).slice(0, 5),
                                elTableClasses: Array.from(allElTableElements).map(el => el.className).slice(0, 5),
                                bodyHTML: document.body ? document.body.innerHTML.substring(0, 2000) : 'No body'
                            };
                        });
                        console.error('⚠️  Table not found after retries. Debug info:', JSON.stringify(debugInfo, null, 2));
                        console.log('⚠️  Continuing anyway, will try again later...');
                        if (await isSessionExpiredPage()) {
                            console.log('⚠️  Session expired (1002) or on login page after table wait, recovering...');
                            await checkSessionExpired();
                            if (!useUrlParams) await doDateSelection();
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        }
                    }
                    
                    // 額外等待確保表格完全渲染
                    await new Promise(resolve => setTimeout(resolve, 1000));


                    // 解析 player_account（date 已在進入 URL 後先選好）
                    let playerAccountParsed = null;
                    try {
                        if ($playerAccountJs && $playerAccountJs !== 'null' && $playerAccountJs !== '') {
                            playerAccountParsed = JSON.parse($playerAccountJs);
                        }
                    } catch (e) {
                        playerAccountParsed = $playerAccountJs !== 'null' ? $playerAccountJs : null;
                    }

                    // 如果提供了 player_account，填入玩家帳號
                    if (playerAccountParsed && playerAccountParsed !== null && playerAccountParsed !== '') {
                        try {
                            console.log('👤 Filling Player Account: ' + playerAccountParsed);
                            
                            // 等待日期選擇器關閉（如果之前有填入日期）（優化：減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 200)); // 從500ms減少到200ms
                            
                            // 等待 Player Account input 出現（優化：減少超時時間）
                            await page.waitForSelector('input.el-input__inner[placeholder="InputPlayer Account"]', { timeout: 5000 }).catch(() => { // 從10000ms減少到5000ms
                                console.log('⚠️  Player Account input not found');
                            });
                            
                            // 填入玩家帳號
                            await page.evaluate((accountValue) => {
                                const accountInput = document.querySelector('input.el-input__inner[placeholder="InputPlayer Account"]');
                                
                                if (accountInput) {
                                    accountInput.value = '';
                                    accountInput.value = accountValue;
                                    accountInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    accountInput.dispatchEvent(new Event('change', { bubbles: true }));
                                    accountInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                            }, playerAccountParsed);
                            
                            await new Promise(resolve => setTimeout(resolve, 200)); // 從500ms減少到200ms
                            
                            console.log('✅ Player Account filled successfully');
                        } catch (e) {
                            console.log('⚠️  Error filling player account: ' + e.message);
                            console.error(e);
                        }
                    }


                    // 如果至少填入了其中一個日期或玩家帳號，嘗試點擊搜尋按鈕
                    // 若 URL 已帶查詢參數（useUrlParams），後台會依參數直接載入表格，跳過 Search 可避免觸發查詢 API 導致的 session expired (1002)
                        if (!useUrlParams && ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') || 
                        (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '') ||
                        (playerAccountParsed && playerAccountParsed !== null && playerAccountParsed !== ''))) {
                        try {
                            // 等待一下讓日期和帳號輸入完成
                            await new Promise(resolve => setTimeout(resolve, 300));

                            // 查找並點擊搜尋按鈕
                            console.log('🔍 Looking for Search button...');
                            
                            // 先獲取點擊前的數據行數（用於驗證）
                            const rowCountBefore = await page.evaluate(() => {
                                // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                let bodyTable = null;
                                const elTableDiv = document.querySelector('div.el-table, .el-table');
                                if (elTableDiv) {
                                    bodyTable = elTableDiv.querySelector('table.el-table__body');
                                }
                                if (!bodyTable) {
                                    bodyTable = document.querySelector('table.el-table__body');
                                }
                                const finalTable = bodyTable;
                                if (finalTable) {
                                    const rows = finalTable.querySelectorAll('tbody tr, tr');
                                    return rows.length;
                                }
                                return 0;
                            });
                            console.log('📊 Rows before search: ' + rowCountBefore);
                            
                            // 使用多種方式查找並點擊按鈕
                            let buttonClicked = false;
                            
                            // 方式1：使用 Puppeteer 的原生方法點擊
                            try {
                                // 等待按鈕出現並可點擊
                                await page.waitForSelector('button.el-button.el-button--primary', { timeout: 5000, visible: true }).catch(() => {});
                                
                                // 查找按鈕並獲取其選擇器
                                const buttonInfo = await page.evaluate(() => {
                                    const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--primary'));
                                    for (let btn of buttons) {
                                        const text = btn.textContent.trim();
                                        if (text === 'Query') {
                                            // 添加唯一標識
                                            const uniqueId = 'search-btn-' + Date.now();
                                            btn.setAttribute('data-puppeteer-search-id', uniqueId);
                                            return {
                                                found: true,
                                                selector: 'button[data-puppeteer-search-id="' + uniqueId + '"]',
                                                text: text
                                            };
                                        }
                                    }
                                    return { found: false };
                                });
                                
                                if (buttonInfo.found) {
                                    // 滾動到按鈕位置
                                    await page.evaluate((selector) => {
                                        const btn = document.querySelector(selector);
                                        if (btn) {
                                            btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        }
                                    }, buttonInfo.selector);
                                    
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    
                                    // 高亮按鈕（添加紅色邊框）
                                    await page.evaluate((selector) => {
                                        const btn = document.querySelector(selector);
                                        if (btn) {
                                            // 保存原始樣式
                                            btn.setAttribute('data-original-style', btn.getAttribute('style') || '');
                                            // 添加紅色邊框高亮
                                            btn.style.border = '5px solid red';
                                            btn.style.boxShadow = '0 0 20px red';
                                            btn.style.zIndex = '9999';
                                            btn.style.position = 'relative';
                                        }
                                    }, buttonInfo.selector);
                                    
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    
                                    // 恢復原始樣式
                                    await page.evaluate((selector) => {
                                        const btn = document.querySelector(selector);
                                        if (btn) {
                                            const originalStyle = btn.getAttribute('data-original-style');
                                            if (originalStyle) {
                                                btn.setAttribute('style', originalStyle);
                                            } else {
                                                btn.removeAttribute('style');
                                            }
                                        }
                                    }, buttonInfo.selector);
                                    
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    
                                    // 使用 Puppeteer 的 click 方法
                                    await page.click(buttonInfo.selector, { timeout: 5000 });
                                    
                                    buttonClicked = true;
                                }
                            } catch (e) {
                                console.log('⚠️  Method 1 failed: ' + e.message);
                            }
                            
                            // 方式2：如果方式1失敗，使用 evaluateHandle
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--default'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text === 'Search') {
                                                return btn;
                                            }
                                        }
                                        return null;
                                    });
                                    
                                    if (buttonHandle && buttonHandle.asElement()) {
                                        // 滾動到按鈕
                                        await buttonHandle.asElement().scrollIntoView();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        
                                        // 高亮按鈕（通過選擇器）
                                        await page.evaluate(() => {
                                            const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--default'));
                                            for (let btn of buttons) {
                                                const text = btn.textContent.trim();
                                                if (text === 'Search') {
                                                    btn.setAttribute('data-original-style', btn.getAttribute('style') || '');
                                                    btn.style.border = '5px solid red';
                                                    btn.style.boxShadow = '0 0 20px red';
                                                    btn.style.zIndex = '9999';
                                                    btn.style.position = 'relative';
                                                    break;
                                                }
                                            }
                                        });
                                        
                                        // 恢復樣式
                                        await page.evaluate(() => {
                                            const buttons = Array.from(document.querySelectorAll('button.el-button.el-button--default'));
                                            for (let btn of buttons) {
                                                const text = btn.textContent.trim();
                                                if (text === 'Search') {
                                                    const originalStyle = btn.getAttribute('data-original-style');
                                                    if (originalStyle) {
                                                        btn.setAttribute('style', originalStyle);
                                                    } else {
                                                        btn.removeAttribute('style');
                                                    }
                                                    break;
                                                }
                                            }
                                        });
                                        
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        
                                        await buttonHandle.asElement().click();
                                        await buttonHandle.dispose();
                                        
                                        buttonClicked = true;
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 2 failed: ' + e.message);
                                }
                            }
                            
                            // 方式3：如果還是失敗，嘗試查找任何包含 "Search" 的按鈕
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text === 'Search') {
                                                return btn;
                                            }
                                        }
                                        return null;
                                    });
                                    
                                    if (buttonHandle && buttonHandle.asElement()) {
                                        await buttonHandle.asElement().scrollIntoView();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        await buttonHandle.asElement().click();
                                        await buttonHandle.dispose();
                                        
                                        buttonClicked = true;
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 3 failed: ' + e.message);
                                }
                            }
                            
                            // 方式4：向後兼容，查找 "搜尋" 按鈕
                            if (!buttonClicked) {
                                try {
                                    const buttonHandle = await page.evaluateHandle(() => {
                                        const buttons = Array.from(document.querySelectorAll('button'));
                                        for (let btn of buttons) {
                                            const text = btn.textContent.trim();
                                            if (text === '搜尋') {
                                                return btn;
                                            }
                                        }
                                        return null;
                                    });
                                    
                                    if (buttonHandle && buttonHandle.asElement()) {
                                        await buttonHandle.asElement().scrollIntoView();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        await buttonHandle.asElement().click();
                                        await buttonHandle.dispose();
                                        
                                        buttonClicked = true;
                                    } else {
                                        if (buttonHandle) await buttonHandle.dispose();
                                    }
                                } catch (e) {
                                    console.log('⚠️  Method 4 failed: ' + e.message);
                                }
                            }
                            
                            if (buttonClicked) {
                                console.log('✅ Search button clicked (form path)');
                                // 智能等待：等待表格數據真正更新
                                // 監聽表格內容變化，或者等待足夠時間
                                let rowCountAfter = 0;
                                let waitAttempts = 0;
                                const maxWaitAttempts = 15; // 最多等待15次，每次1秒（總共15秒）
                                let dataChanged = false;
                                
                                // 先等待初始加載
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                while (waitAttempts < maxWaitAttempts) {
                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                    
                                    rowCountAfter = await page.evaluate(() => {
                                        // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                        let bodyTable = null;
                                        const elTableDiv = document.querySelector('div.el-table, .el-table');
                                        if (elTableDiv) {
                                            bodyTable = elTableDiv.querySelector('table.el-table__body');
                                        }
                                        if (!bodyTable) {
                                            bodyTable = document.querySelector('table.el-table__body');
                                        }
                                        const finalTable = bodyTable;
                                        if (finalTable) {
                                            const rows = finalTable.querySelectorAll('tbody tr, tr');
                                            return rows.length;
                                        }
                                        return 0;
                                    });
                                    
                                    // 檢查行數是否變化
                                    if (rowCountAfter !== rowCountBefore) {
                                        dataChanged = true;
                                        // 再等待1秒確保數據完全加載
                                        await new Promise(resolve => setTimeout(resolve, 1000));
                                        break;
                                    }
                                    
                                    // 如果已經等待足夠時間（至少5秒），繼續執行
                                    // 可能是日期範圍內沒有新數據，或者數據已經正確加載
                                    if (waitAttempts >= 4) {
                                        // 再等待1秒後繼續
                                        await new Promise(resolve => setTimeout(resolve, 1000));
                                        break;
                                    }
                                    
                                    waitAttempts++;
                                }
                                
                                // 確保表格出現
                                await page.waitForSelector('table.el-table, table.el-table__header, table.el-table__body, table[class*="el-table"], .el-table, .el-table__header, .el-table__body', { timeout: 10000 }).catch(() => {});
                                
                                // 等待表格數據行出現
                                await page.waitForSelector('table.el-table tbody tr, table.el-table__body tbody tr, table[class*="el-table"] tbody tr, .el-table tbody tr', { timeout: 10000 }).catch(() => {});
                                
                                // 最後一次檢查行數
                                rowCountAfter = await page.evaluate(() => {
                                    // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                    let bodyTable = null;
                                    const elTableDiv = document.querySelector('div.el-table, .el-table');
                                    if (elTableDiv) {
                                        bodyTable = elTableDiv.querySelector('table.el-table__body');
                                    }
                                    if (!bodyTable) {
                                        bodyTable = document.querySelector('table.el-table__body');
                                    }
                                    const finalTable = bodyTable;
                                    if (finalTable) {
                                        const rows = finalTable.querySelectorAll('tbody tr, tr');
                                        return rows.length;
                                    }
                                    return 0;
                                });
                                
                                // 滾動到分頁組件位置，確保頁數和筆數可見
                                await page.evaluate(() => {
                                    const pagination = document.querySelector('.el-pagination');
                                    if (pagination) {
                                        pagination.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    }
                                });
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                // 步驟截圖 04：點擊查詢按鈕後（包含頁數和筆數）
                            }
                        } catch (e) {
                            console.log('⚠️  Error clicking search button: ' + e.message);
                            console.error(e);
                        }
                    } else if (useUrlParams) {
                        console.log('📌 URL has query params, skipping form Search button (data loaded from URL, avoids session expired)...');
                    }

                    // 提取表格資料的函數（可重用）
                    // @param {Page} pageObject - Puppeteer 頁面對象（可以是 page 或 newPage）
                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            // Element UI 表格結構：
                            // 外層：div.el-table
                            // 內層：table.el-table__header (表頭) 和 table.el-table__body (表體)
                            
                            let headerTable = null;
                            let bodyTable = null;
                            let table = null;
                            
                            // 方式1：優先查找外層 div.el-table，然後在裡面找表格
                            const elTableDiv = document.querySelector('div.el-table, .el-table');
                            if (elTableDiv) {
                                // 在 div.el-table 內部查找表頭和表體
                                headerTable = elTableDiv.querySelector('table.el-table__header');
                                bodyTable = elTableDiv.querySelector('table.el-table__body');
                                
                                // 如果找不到，嘗試查找任何 table 元素
                                if (!headerTable && !bodyTable) {
                                    const tables = elTableDiv.querySelectorAll('table');
                                    for (let t of tables) {
                                        if (t.className && t.className.includes('el-table__header')) {
                                            headerTable = t;
                                        } else if (t.className && t.className.includes('el-table__body')) {
                                            bodyTable = t;
                                        }
                                    }
                                }
                            }
                            
                            // 方式2：如果方式1失敗，直接查找 table 元素
                            if (!headerTable && !bodyTable && !table) {
                                headerTable = document.querySelector('table.el-table__header');
                                bodyTable = document.querySelector('table.el-table__body');
                                table = document.querySelector('table.el-table');
                            }
                            
                            // 方式3：如果還是找不到，遍歷所有表格
                            if (!table && !headerTable && !bodyTable) {
                                const allTables = document.querySelectorAll('table');
                                for (let t of allTables) {
                                    if (t.className) {
                                        if (t.className.includes('el-table__header') && !headerTable) {
                                            headerTable = t;
                                        } else if (t.className.includes('el-table__body') && !bodyTable) {
                                            bodyTable = t;
                                        } else if (t.className.includes('el-table') && !table) {
                                            table = t;
                                        }
                                    }
                                }
                            }
                            
                            // 方式4：查找任何包含 el-table 的元素，然後向上或向下查找 table
                            if (!table && !headerTable && !bodyTable) {
                                const elTableElement = document.querySelector('[class*="el-table"]');
                                if (elTableElement) {
                                    // 如果是 table 元素
                                    if (elTableElement.tagName === 'TABLE') {
                                        if (elTableElement.className.includes('el-table__header')) {
                                            headerTable = elTableElement;
                                        } else if (elTableElement.className.includes('el-table__body')) {
                                            bodyTable = elTableElement;
                                        } else {
                                            table = elTableElement;
                                        }
                                    } else {
                                        // 如果不是 table，向下查找 table 元素
                                        const childTables = elTableElement.querySelectorAll('table');
                                        for (let t of childTables) {
                                            if (t.className && t.className.includes('el-table__header') && !headerTable) {
                                                headerTable = t;
                                            } else if (t.className && t.className.includes('el-table__body') && !bodyTable) {
                                                bodyTable = t;
                                            }
                                        }
                                        
                                        // 如果還是找不到，向上查找 table 元素
                                        if (!headerTable && !bodyTable) {
                                            let parent = elTableElement.parentElement;
                                            while (parent && parent.tagName !== 'TABLE' && parent !== document.body) {
                                                parent = parent.parentElement;
                                            }
                                            if (parent && parent.tagName === 'TABLE') {
                                                if (parent.className && parent.className.includes('el-table__header')) {
                                                    headerTable = parent;
                                                } else if (parent.className && parent.className.includes('el-table__body')) {
                                                    bodyTable = parent;
                                                } else {
                                                    table = parent;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // 如果完全找不到表格，返回錯誤
                            if (!table && !headerTable && !bodyTable) {
                                // 獲取調試信息
                                const debugInfo = {
                                    allTables: document.querySelectorAll('table').length,
                                    elTableElements: document.querySelectorAll('.el-table, [class*="el-table"]').length,
                                    tableClasses: Array.from(document.querySelectorAll('table')).map(t => t.className)
                                };
                                return {
                                    found: false,
                                    error: 'Table el-table not found',
                                    debug: debugInfo
                                };
                            }

                            // 提取表頭（優先從 headerTable，其次從 table）
                            let headers = [];
                            let thead = null;
                            
                            if (headerTable) {
                                thead = headerTable.querySelector('thead');
                            } else if (table) {
                                thead = table.querySelector('thead');
                            }
                            
                            if (thead) {
                                const headerRows = Array.from(thead.querySelectorAll('tr'));
                                if (headerRows.length > 0) {
                                    const headerCells = headerRows[0].querySelectorAll('th, td');
                                    headers = Array.from(headerCells).map(cell => {
                                        // Element UI 表格的表頭內容在 div.cell 中
                                        const cellDiv = cell.querySelector('div.cell');
                                        if (cellDiv) {
                                            return cellDiv.textContent.trim();
                                        }
                                        return cell.textContent.trim();
                                    });
                                }
                            } else {
                                // 如果沒有 thead，嘗試從第一個表格的第一行提取表頭
                                const sourceTable = headerTable || table;
                                if (sourceTable) {
                                    const firstRow = sourceTable.querySelector('tr');
                                    if (firstRow) {
                                        const headerCells = firstRow.querySelectorAll('th, td');
                                        headers = Array.from(headerCells).map(cell => {
                                            const cellDiv = cell.querySelector('div.cell');
                                            if (cellDiv) {
                                                return cellDiv.textContent.trim();
                                            }
                                            return cell.textContent.trim();
                                        });
                                    }
                                }
                            }

                            // 提取資料行（優先從 bodyTable，其次從 table）
                            let rows = [];
                            let dataStartIndex = 0;
                            let tbody = null;
                            
                            if (bodyTable) {
                                tbody = bodyTable.querySelector('tbody');
                                if (tbody) {
                                    rows = Array.from(tbody.querySelectorAll('tr'));
                                } else {
                                    rows = Array.from(bodyTable.querySelectorAll('tr'));
                                }
                            } else if (table) {
                                tbody = table.querySelector('tbody');
                                if (tbody) {
                                    rows = Array.from(tbody.querySelectorAll('tr'));
                                } else {
                                    rows = Array.from(table.querySelectorAll('tr'));
                                    // 如果有表頭，跳過第一行
                                    if (thead || (rows.length > 0 && rows[0].querySelectorAll('th').length > 0)) {
                                        dataStartIndex = 1;
                                    }
                                }
                            }

                            // 將資料行轉換為對象數組
                            const dataRows = rows.slice(dataStartIndex)
                                .map((row, rowIndex) => {
                                    const cells = Array.from(row.querySelectorAll('td'));
                                    const rowData = {};
                                    
                                    if (headers && headers.length > 0) {
                                        headers.forEach((header, colIndex) => {
                                            // 清理字段名
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
                                            
                                            // Element UI 表格的數據內容也在 div.cell 中
                                            let cellValue = null;
                                            if (cells[colIndex]) {
                                                const cellDiv = cells[colIndex].querySelector('div.cell');
                                                if (cellDiv) {
                                                    cellValue = cellDiv.textContent.trim();
                                                } else {
                                                    cellValue = cells[colIndex].textContent.trim();
                                                }
                                            }
                                            rowData[finalHeader] = cellValue;
                                        });
                                    } else {
                                        // 如果沒有表頭，使用索引作為 key
                                        cells.forEach((cell, colIndex) => {
                                            const cellDiv = cell.querySelector('div.cell');
                                            let cellValue = null;
                                            if (cellDiv) {
                                                cellValue = cellDiv.textContent.trim();
                                            } else {
                                                cellValue = cell ? cell.textContent.trim() : null;
                                            }
                                            rowData['column_' + colIndex] = cellValue;
                                        });
                                    }
                                    
                                    // 添加原始行索引
                                    rowData._rowIndex = rowIndex;
                                    
                                    return rowData;
                                })
                                .filter(rowData => {
                                    // 過濾掉小計和總計行
                                    // 檢查第一個欄位（通常是日期欄位）是否包含"小計"或"總計"
                                    const firstValue = Object.values(rowData)[0];
                                    return firstValue !== '小計' && firstValue !== '總計';
                                });

                            // 確定使用的表格對象（用於返回信息）
                            const finalTable = bodyTable || table || headerTable;
                            
                            return {
                                found: true,
                                tableId: finalTable ? finalTable.id || null : null,
                                tableClass: finalTable ? finalTable.className || null : null,
                                hasHeaderTable: !!headerTable,
                                hasBodyTable: !!bodyTable,
                                headers: headers,
                                headerCount: headers.length,
                                rowCount: dataRows.length,
                                rawRows: rows.slice(dataStartIndex)
                                    .map(row => {
                                        return Array.from(row.querySelectorAll('td')).map(cell => {
                                            const cellDiv = cell.querySelector('div.cell');
                                            if (cellDiv) {
                                                return cellDiv.textContent.trim();
                                            }
                                            return cell.textContent.trim();
                                        });
                                    })
                                    .filter(rowArray => {
                                        // 過濾掉小計和總計行
                                        return rowArray.length > 0 && rowArray[0] !== '小計' && rowArray[0] !== '總計';
                                    }),
                                data: dataRows
                            };
                        });
                    };

                    // ========== 步驟 1：爬取第一頁，獲取分頁資訊 ==========
                    if (await isSessionExpiredPage()) {
                        console.log('⚠️  Session expired (1002) or on login page before Step 1, recovering...');
                        await checkSessionExpired();
                        if (!useUrlParams) await doDateSelection();
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    }
                    console.log('📄 Step 1: Extracting first page and pagination info...');
                    
                    // 確保表格已載入（增加等待時間和重試機制）
                    // Element UI 表格 (el-table)
                    console.log('🔍 Step 1: Waiting for table to load...');
                    
                    // 額外等待並滾動頁面（優化：減少等待時間）
                    await new Promise(resolve => setTimeout(resolve, 500)); // 從1000ms減少到500ms
                    await page.evaluate(() => {
                        window.scrollTo(0, document.body.scrollHeight);
                    });
                    await new Promise(resolve => setTimeout(resolve, 300)); // 從1000ms減少到300ms
                    await page.evaluate(() => {
                        window.scrollTo(0, 0);
                    });
                    await new Promise(resolve => setTimeout(resolve, 300)); // 從1000ms減少到300ms
                    
                    tableFound = false;
                    for (let retry = 0; retry < 5; retry++) { // 從10減少到5
                        try {
                            // 檢查表格是否存在且有內容
                            const tableCheck = await page.evaluate(() => {
                                // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                let bodyTable = null;
                                let table = null;
                                
                                // 方式1：優先查找外層 div.el-table，然後在裡面找表格
                                const elTableDiv = document.querySelector('div.el-table, .el-table');
                                if (elTableDiv) {
                                    bodyTable = elTableDiv.querySelector('table.el-table__body');
                                    if (!bodyTable) {
                                        const tables = elTableDiv.querySelectorAll('table');
                                        for (let t of tables) {
                                            if (t.className && t.className.includes('el-table__body')) {
                                                bodyTable = t;
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                // 方式2：如果方式1失敗，直接查找 table 元素
                                if (!bodyTable) {
                                    bodyTable = document.querySelector('table.el-table__body');
                                    table = document.querySelector('table.el-table');
                                }
                                
                                // 方式3：如果還是找不到，遍歷所有表格
                                if (!bodyTable && !table) {
                                    const allTables = document.querySelectorAll('table');
                                    for (let t of allTables) {
                                        if (t.className) {
                                            if (t.className.includes('el-table__body') && !bodyTable) {
                                                bodyTable = t;
                                            } else if (t.className.includes('el-table') && !table) {
                                                table = t;
                                            }
                                        }
                                    }
                                }
                                
                                const finalTable = bodyTable || table;
                                if (!finalTable) return { found: false, hasTable: false };
                                const rows = finalTable.querySelectorAll('tbody tr, tr');
                                return {
                                    found: rows.length > 0,
                                    hasTable: true,
                                    rowCount: rows.length
                                };
                            });
                            
                            if (tableCheck.found) {
                                console.log('✅ Table found with ' + tableCheck.rowCount + ' rows!');
                                tableFound = true;
                                break;
                            } else if (tableCheck.hasTable) {
                                console.log('⚠️  Table found but no data rows yet (rowCount: ' + tableCheck.rowCount + '), waiting...');
                            }
                            
                            if (retry < 4) {
                                console.log('⏳ Retry ' + (retry + 1) + '/5: Waiting for table...');
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            }
                        } catch (e) {
                            console.log('⚠️  Retry ' + (retry + 1) + '/5: Error checking table - ' + e.message);
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                    }
                    
                    // 如果還是找不到表格，獲取頁面信息以便調試
                    if (!tableFound) {
                        // 獲取頁面信息以便調試
                        const pageInfo = await page.evaluate(() => {
                            const allTables = Array.from(document.querySelectorAll('table'));
                            const elTableElements = Array.from(document.querySelectorAll('.el-table, [class*="el-table"]'));
                            return {
                                url: window.location.href,
                                title: document.title,
                                hasElTable: !!document.querySelector('table.el-table'),
                                hasElTableClass: elTableElements.length > 0,
                                tableCount: allTables.length,
                                tableClasses: allTables.map(t => t.className),
                                elTableClasses: elTableElements.map(el => el.className),
                                bodyText: document.body ? document.body.innerText.substring(0, 500) : 'No body'
                            };
                        });
                    }
                    
                    // 提取第一頁的表格資料
                    const firstPageData = await extractTableData(page);
                    
                    if (!firstPageData.found) {
                        const errorMsg = firstPageData.error || 'No table found on first page';
                        console.error('❌ ' + errorMsg);
                        if (firstPageData.debug) {
                            console.error('📋 Debug info:', JSON.stringify(firstPageData.debug, null, 2));
                        }
                        
                        // 再等待一下並重試一次（優化：減少等待時間）
                        console.log('⏳ Waiting and retrying...');
                        await new Promise(resolve => setTimeout(resolve, 1000)); // 從2000ms減少到1000ms
                        await page.evaluate(() => {
                            window.scrollTo(0, document.body.scrollHeight);
                        });
                        await new Promise(resolve => setTimeout(resolve, 300)); // 從1000ms減少到300ms
                        await page.evaluate(() => {
                            window.scrollTo(0, 0);
                        });
                        await new Promise(resolve => setTimeout(resolve, 300)); // 從1000ms減少到300ms
                        
                        const retryData = await extractTableData(page);
                        if (!retryData.found) {
                            throw new Error(errorMsg + ' (retry also failed)');
                        } else {
                            console.log('✅ Table found on retry!');
                            // 使用重試成功的數據
                            Object.assign(firstPageData, retryData);
                        }
                    }
                    
                    // 步驟截圖 05：第一頁表格資料提取後（或重試後）
                    await page.screenshot({ path: 'step_08_after_first_page.png', fullPage: false });
                    console.log('📸 Screenshot: step_08_after_first_page.png');
                    
                    // 滾動到分頁組件位置，確保頁數和筆數可見（優化：減少等待時間）
                    await page.evaluate(() => {
                        const pagination = document.querySelector('.el-pagination');
                        if (pagination) {
                            pagination.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    });
                    await new Promise(resolve => setTimeout(resolve, 300)); // 從500ms減少到300ms
                    
                    // 等待分頁組件載入（Element UI 的分頁組件）（優化：減少等待時間）
                    await new Promise(resolve => setTimeout(resolve, 300)); // 從1000ms減少到300ms
                    
                    // 獲取所有分頁連結（支持多種分頁組件）
                    const paginationInfo = await page.evaluate(() => {
                        const pageLinks = [];
                        let totalPages = 1;
                        
                        // 優先方式：查找總頁數顯示（最準確）
                        // Element UI 分頁組件通常會顯示 "1 / 2" 或 "共 2 頁" 等格式
                        const elPagination = document.querySelector('.el-pagination');
                        if (elPagination) {
                            const paginationText = elPagination.textContent || elPagination.innerText || '';
                            console.log('📄 Pagination text:', paginationText);
                            
                            // 方式1（最優先）：查找分頁按鈕中的最大數字（最可靠，因為按鈕直接顯示實際頁數）
                            // 優先檢查 .el-pager 中的按鈕，因為這是最直接的分頁按鈕容器
                            // 例如：如果只顯示按鈕 1，那麼總頁數就是 1；如果顯示 1,2,3，那麼總頁數就是 3
                            let buttonDetectedPages = null;
                            {
                                // 首先檢查 .el-pager 中的按鈕（Element UI 的標準結構）
                                const elPager = elPagination.querySelector('.el-pager');
                                let numberButtons = [];
                                if (elPager) {
                                    numberButtons = Array.from(elPager.querySelectorAll('li.number'));
                                    console.log('🔍 Found el-pager, buttons:', numberButtons.length);
                                }
                                // 如果找不到，再查找其他位置
                                if (numberButtons.length === 0) {
                                    numberButtons = Array.from(elPagination.querySelectorAll('.number, button.number'));
                                    console.log('🔍 No el-pager, trying other selectors, found:', numberButtons.length, 'buttons');
                                }
                                
                                let maxPageNum = 1;
                                let buttonCount = 0;
                                numberButtons.forEach(btn => {
                                    const text = btn.textContent.trim();
                                    const pageNum = parseInt(text);
                                    console.log('🔍 Button text:', text, ', parsed:', pageNum);
                                    if (!isNaN(pageNum) && pageNum > 0 && pageNum <= 10000) {
                                        if (pageNum > maxPageNum) {
                                            maxPageNum = pageNum;
                                        }
                                        buttonCount++;
                                    }
                                });
                                
                                console.log('🔍 Button detection result: buttonCount=', buttonCount, ', maxPageNum=', maxPageNum);
                                
                                // 如果找到按鈕，使用最大頁碼作為總頁數
                                // 特別是當按鈕數量較少（<=5）時，這些按鈕很可能就是所有的頁面
                                // 例如：如果只顯示 1, 2, 3，那麼總頁數就是 3
                                // 如果只顯示 1，那麼總頁數就是 1
                                if (buttonCount > 0) {
                                    // 如果按鈕數量 <= 5，很可能這些就是所有頁面，直接使用最大頁碼
                                    // 這包括只有1個按鈕的情況（總頁數=1）
                                    if (buttonCount <= 5) {
                                        buttonDetectedPages = maxPageNum;
                                        totalPages = maxPageNum;
                                        console.log('✅ Found total pages from buttons (most reliable, few buttons):', totalPages, '(button count:', buttonCount + ')');
                                    } else {
                                        // 如果按鈕數量 > 5，可能是分頁器顯示的當前頁附近的按鈕，需要謹慎
                                        // 只有在沒有其他方法檢測到時才使用
                                        buttonDetectedPages = maxPageNum;
                                        totalPages = maxPageNum;
                                        console.log('⚠️  Found total pages from buttons (many buttons, may be inaccurate):', totalPages, '(button count:', buttonCount + ')');
                                    }
                                } else {
                                    console.log('⚠️  No pagination buttons found');
                                }
                            }
                            
                            // 方式1.5：查找 "1 / 1892" 或 "1/1892" 格式（備用方法，如果按鈕檢測失敗）
                            // 注意：如果按鈕檢測已經找到結果，不要使用這個方法（因為按鈕更可靠）
                            if (buttonDetectedPages === null && totalPages === 1) {
                                const slashMatch = paginationText.match(/(\d+)\s*\/\s*(\d+)/);
                                if (slashMatch && slashMatch[1] && slashMatch[2]) {
                                    const totalPagesFromText = parseInt(slashMatch[2]);
                                    if (!isNaN(totalPagesFromText) && totalPagesFromText > 0 && totalPagesFromText <= 10000) {
                                        totalPages = totalPagesFromText;
                                        console.log('✅ Found total pages from slash format:', totalPages);
                                    }
                                }
                            } else if (buttonDetectedPages !== null) {
                                console.log('ℹ️  Skipping slash format detection, using button detection result:', buttonDetectedPages);
                            }
                            
                            // 方式2：從 "Total 1227" 文本中提取總記錄數，然後計算總頁數
                            if (totalPages === 1) {
                                const totalMatch = paginationText.match(/total\s+(\d+)/i);
                                if (totalMatch && totalMatch[1]) {
                                    const totalRecords = parseInt(totalMatch[1]);
                                    // 查找每頁數量（從 select 或當前設置）
                                    let perPage = 10; // 默認值
                                    const perPageSelect = elPagination.querySelector('.el-select-dropdown__item.selected');
                                    if (perPageSelect) {
                                        const perPageText = perPageSelect.textContent || '';
                                        const perPageMatch = perPageText.match(/(\d+)\/page/i);
                                        if (perPageMatch && perPageMatch[1]) {
                                            perPage = parseInt(perPageMatch[1]);
                                        }
                                    }
                                    // 如果找不到，嘗試從 URL 參數中獲取
                                    if (perPage === 10) {
                                        const urlParams = new URLSearchParams(window.location.search);
                                        const limitParam = urlParams.get('limit');
                                        if (limitParam) {
                                            perPage = parseInt(limitParam);
                                        }
                                    }
                                    if (totalRecords > 0 && perPage > 0) {
                                        totalPages = Math.ceil(totalRecords / perPage);
                                        console.log('✅ Calculated total pages from Total records:', totalPages, '(records:', totalRecords, ', perPage:', perPage + ')');
                                    }
                                }
                            }
                            
                            // 方式3：查找 "共 1892 頁" 或 "total 1892 pages" 格式
                            if (totalPages === 1) {
                                const totalMatch = paginationText.match(/(?:共|總|total|of)\s*(\d+)\s*(?:頁|page|pages)/i);
                                if (totalMatch && totalMatch[1]) {
                                    totalPages = parseInt(totalMatch[1]);
                                    console.log('✅ Found total pages from text format:', totalPages);
                                }
                            }
                            
                            // 方式4：查找 input[type="number"] 的 max 屬性（最後備用方法，可能不準確）
                            // 注意：這個屬性可能會顯示最大可能的頁數，而不是實際的總頁數
                            // 只在沒有其他方法檢測到時才使用
                            if (totalPages === 1) {
                                const pageInput = elPagination.querySelector('input[type="number"]');
                                if (pageInput && pageInput.hasAttribute('max')) {
                                    const maxValue = parseInt(pageInput.getAttribute('max'));
                                    if (!isNaN(maxValue) && maxValue > 0 && maxValue <= 10000) {
                                        totalPages = maxValue;
                                        console.log('⚠️  Found total pages from input max attribute (may be inaccurate, using as last resort):', totalPages);
                                    }
                                }
                            }
                        }
                        
                        // 方式2：查找傳統分頁組件
                        if (totalPages === 1) {
                            const paginationContainer = document.querySelector('.pagination') || 
                                                       document.querySelector('ul.pagination');
                            
                            if (paginationContainer) {
                                // 優先查找總頁數顯示
                                const containerText = paginationContainer.textContent || paginationContainer.innerText || '';
                                const slashMatch = containerText.match(/(\d+)\s*\/\s*(\d+)/);
                                if (slashMatch && slashMatch[2]) {
                                    totalPages = parseInt(slashMatch[2]);
                                } else {
                                    // 查找所有分頁連結中的最大頁碼
                                    const links = paginationContainer.querySelectorAll('a[data-ci-pagination-page], a[data-page]');
                                    let maxPageNum = 1;
                                    links.forEach(link => {
                                        const pageNum = parseInt(link.getAttribute('data-ci-pagination-page') || link.getAttribute('data-page') || '0');
                                        if (!isNaN(pageNum) && pageNum > 0 && pageNum < 100) {
                                            if (pageNum > maxPageNum) {
                                                maxPageNum = pageNum;
                                            }
                                        }
                                    });
                                    if (maxPageNum > 1) {
                                        totalPages = maxPageNum;
                                    }
                                }
                            }
                        }
                        
                        // 方式3：檢查是否有「下一頁」按鈕（表示至少有2頁）
                        // 必須確保按鈕存在、可見、且未被禁用
                        if (totalPages === 1) {
                            const nextLink = document.querySelector('a[rel="next"], .btn-next:not(.disabled), button.el-pagination__next:not(.disabled)');
                            if (nextLink) {
                                // 檢查是否可見
                                const isVisible = nextLink.offsetParent !== null;
                                // 檢查是否被禁用（檢查 disabled 屬性、class 中包含 disabled、或 aria-disabled="true"）
                                const isDisabled = nextLink.hasAttribute('disabled') || 
                                                  nextLink.classList.contains('disabled') ||
                                                  nextLink.getAttribute('aria-disabled') === 'true' ||
                                                  nextLink.classList.contains('is-disabled');
                                
                                if (isVisible && !isDisabled) {
                                    totalPages = 2;
                                }
                            }
                        }
                        
                        // 確保至少有 1 頁
                        if (totalPages < 1) {
                            totalPages = 1;
                        }
                        
                        // 生成頁面連結（僅用於構建 URL，不影響總頁數判斷）
                        for (let i = 1; i <= totalPages; i++) {
                            pageLinks.push({
                                pageNumber: i,
                                url: window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'page=' + i
                            });
                        }
                        
                        return {
                            totalPages: totalPages,
                            pageLinks: pageLinks,
                            currentUrl: window.location.href,
                            paginationFound: totalPages > 1
                        };
                    });
                    
                    // 驗證總頁數：如果第一頁的數據行數很少（<10），且檢測到的總頁數很大（>5），
                    // 很可能是檢測錯誤，應該檢查實際的按鈕數量
                    console.log('📄 Detected total pages: ' + paginationInfo.totalPages + ', first page rows: ' + firstPageData.rowCount);
                    
                    // 如果檢測到的總頁數 > 5，但第一頁只有少量數據（<=10），可能是檢測錯誤
                    // 強制使用按鈕檢測的結果，如果按鈕只顯示1個，應該只有1頁
                    if (paginationInfo.totalPages > 5 && firstPageData.rowCount <= 10) {
                        console.log('⚠️  Suspicious pagination detection: ' + paginationInfo.totalPages + ' pages but only ' + firstPageData.rowCount + ' rows on first page');
                        
                        const buttonRecheck = await page.evaluate(() => {
                            const elPagination = document.querySelector('.el-pagination');
                            if (elPagination) {
                                const elPager = elPagination.querySelector('.el-pager');
                                let numberButtons = [];
                                if (elPager) {
                                    numberButtons = Array.from(elPager.querySelectorAll('li.number'));
                                }
                                if (numberButtons.length === 0) {
                                    numberButtons = Array.from(elPagination.querySelectorAll('.number, button.number'));
                                }
                                
                                let maxPageNum = 1;
                                let buttonCount = 0;
                                numberButtons.forEach(btn => {
                                    const text = btn.textContent.trim();
                                    const pageNum = parseInt(text);
                                    if (!isNaN(pageNum) && pageNum > 0 && pageNum <= 10000) {
                                        if (pageNum > maxPageNum) {
                                            maxPageNum = pageNum;
                                        }
                                        buttonCount++;
                                    }
                                });
                                
                                return { buttonCount: buttonCount, maxPageNum: maxPageNum };
                            }
                            return null;
                        });
                        
                        if (buttonRecheck && buttonRecheck.buttonCount > 0 && buttonRecheck.buttonCount <= 5) {
                            console.log('✅ Recheck: Found ' + buttonRecheck.buttonCount + ' buttons, max page: ' + buttonRecheck.maxPageNum);
                            console.log('⚠️  Correcting total pages from ' + paginationInfo.totalPages + ' to ' + buttonRecheck.maxPageNum);
                            paginationInfo.totalPages = buttonRecheck.maxPageNum;
                        }
                    }
                    
                    console.log('📄 Final total pages: ' + paginationInfo.totalPages);
                    
                    // 定義併發數量
                    const CONCURRENCY_LIMIT = $concurrency;
                    
                    // 準備要爬取的頁面列表（從第 2 頁開始，因為第 1 頁已經爬了）
                    const pagesToScrape = [];
                    for (let i = 2; i <= paginationInfo.totalPages; i++) {
                        pagesToScrape.push({ pageNumber: i });
                    }
                    
                    console.log('📋 Pages to scrape: ' + pagesToScrape.length + ' pages (from page 2 to ' + paginationInfo.totalPages + ')');
                    
                    // 順序爬取函數（在第一頁點擊分頁按鈕切換，維持日期條件）
                    const scrapePage = async (pageInfo, index) => {
                        const startTime = Date.now();
                        
                        try {
                            // 查找並點擊對應頁碼的分頁按鈕
                            const buttonClicked = await page.evaluate((targetPageNumber) => {
                                // 查找 Element UI 的分頁按鈕
                                const elPagination = document.querySelector('.el-pagination');
                                if (elPagination) {
                                    // 查找所有分頁按鈕（.number 類）
                                    const numberButtons = elPagination.querySelectorAll('.number');
                                    for (let btn of numberButtons) {
                                        const text = btn.textContent.trim();
                                        const pageNum = parseInt(text);
                                        if (pageNum === targetPageNumber) {
                                            btn.click();
                                            return true;
                                        }
                                    }
                                }
                                
                                // 如果找不到，嘗試查找傳統分頁
                                const paginationContainer = document.querySelector('.pagination') || 
                                                           document.querySelector('ul.pagination');
                                if (paginationContainer) {
                                    const links = paginationContainer.querySelectorAll('a[data-ci-pagination-page], a[data-page]');
                                    for (let link of links) {
                                        const pageNum = parseInt(link.getAttribute('data-ci-pagination-page') || link.getAttribute('data-page') || '0');
                                        if (pageNum === targetPageNumber) {
                                            link.click();
                                            return true;
                                        }
                                    }
                                }
                                
                                return false;
                            }, pageInfo.pageNumber);

                            // 等待頁面切換和數據加載（優化：減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 300));
                            
                            // 等待表格更新（優化：減少超時時間）
                            await page.waitForSelector('table.el-table__body, .el-table__body', { timeout: 5000 }).catch(() => { });
                            
                            // 等待數據完全加載（智能等待，優化：減少等待間隔和重試次數）
                            let rowCount = 0;
                            let waitAttempts = 0;
                            const maxWaitAttempts = 5; // 從10減少到5
                            
                            while (waitAttempts < maxWaitAttempts) {
                                await new Promise(resolve => setTimeout(resolve, 200)); // 從500ms減少到200ms
                                
                                rowCount = await page.evaluate(() => {
                                    // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                    let bodyTable = null;
                                    const elTableDiv = document.querySelector('div.el-table, .el-table');
                                    if (elTableDiv) {
                                        bodyTable = elTableDiv.querySelector('table.el-table__body');
                                    }
                                    if (!bodyTable) {
                                        bodyTable = document.querySelector('table.el-table__body');
                                    }
                                    const finalTable = bodyTable;
                                    if (finalTable) {
                                        const rows = finalTable.querySelectorAll('tbody tr, tr');
                                        return rows.length;
                                    }
                                    return 0;
                                });
                                
                                // 如果行數穩定（連續兩次檢查相同），認為數據已加載完成
                                if (waitAttempts > 0 && rowCount > 0) {
                                    await new Promise(resolve => setTimeout(resolve, 200)); // 從500ms減少到200ms
                                    const rowCount2 = await page.evaluate(() => {
                                        // Element UI 表格結構：外層 div.el-table，內層 table.el-table__body
                                        let bodyTable = null;
                                        const elTableDiv = document.querySelector('div.el-table, .el-table');
                                        if (elTableDiv) {
                                            bodyTable = elTableDiv.querySelector('table.el-table__body');
                                        }
                                        if (!bodyTable) {
                                            bodyTable = document.querySelector('table.el-table__body');
                                        }
                                        const finalTable = bodyTable;
                                        if (finalTable) {
                                            const rows = finalTable.querySelectorAll('tbody tr, tr');
                                            return rows.length;
                                        }
                                        return 0;
                                    });
                                    
                                    if (rowCount === rowCount2) {
                                        break;
                                    }
                                }
                                
                                waitAttempts++;
                            }
                            
                            // 再等待一下確保數據完全渲染（優化：減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 300)); // 從1000ms減少到300ms
                            
                            // 提取表格資料
                            const tableData = await extractTableData(page);
                            
                            const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
                            
                            return {
                                pageNumber: pageInfo.pageNumber,
                                tables: [tableData]
                            };
                            
                        } catch (error) {
                            const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
                            console.error('❌ [Page ' + pageInfo.pageNumber + '] Error after ' + elapsed + 's: ' + error.message);
                            return {
                                pageNumber: pageInfo.pageNumber,
                                tables: [],
                                error: error.message
                            };
                        }
                    };
                    
                    // 順序爬取所有頁面（在第一頁點擊分頁按鈕，維持日期條件）
                    const startTime = Date.now();
                    
                    const otherPagesData = [];
                    let shouldStop = false;
                    
                    for (let i = 0; i < pagesToScrape.length && !shouldStop; i++) {
                        // 爬取前的檢查：已經有了要爬取的頁面列表，直接進行爬取
                        // 不需要過度檢查，因為頁面列表已經確定
                        
                        const pageData = await scrapePage(pagesToScrape[i], i);
                        otherPagesData.push(pageData);
                        
                        // 檢查該頁是否有數據
                        const pageRowCount = pageData.tables && pageData.tables.length > 0 
                            ? pageData.tables.reduce((sum, table) => sum + (table.rowCount || 0), 0)
                            : 0;
                        
                        console.log('📊 Page ' + pageData.pageNumber + ' has ' + pageRowCount + ' rows');
                        
                        // 如果該頁沒有數據，停止爬取
                        if (pageRowCount === 0) {
                            console.log('⚠️  Page ' + pageData.pageNumber + ' has no data, stopping pagination');
                            shouldStop = true;
                            break;
                        }
                        
                        // 檢查是否還有下一頁（在點擊後檢查，主要用於檢測是否意外到達最後一頁）
                        // 只有在當前頁沒有數據時才停止，不應該因為找不到下一頁按鈕就停止
                        // 因為可能還有更多頁面在 pagesToScrape 列表中
                    }
                    
                    const totalElapsed = ((Date.now() - startTime) / 1000).toFixed(2);
                    
                    // 合併第一頁和其他頁面的資料
                    const allPagesData = [
                        {
                            pageNumber: 1,
                            tables: [firstPageData]
                        },
                        ...otherPagesData
                    ];
                    
                    // 按頁碼排序
                    allPagesData.sort((a, b) => a.pageNumber - b.pageNumber);
                    
                    const totalRowsFromPages = allPagesData.reduce((sum, pageData) => {
                        if (pageData.tables && pageData.tables.length > 0) {
                            return sum + pageData.tables.reduce((s, table) => s + (table.rowCount || 0), 0);
                        }
                        return sum;
                    }, 0);
                    console.log('📊 Total rows from all pages: ' + totalRowsFromPages);
                    
                    console.log('✅ All pages scraped successfully!');

                    // 獲取當前頁面信息
                    const pageInfo = await page.evaluate(() => {
                        return {
                            title: document.title,
                            url: window.location.href
                        };
                    });

                    // 合併所有頁面的表格資料
                    const allTables = [];
                    allPagesData.forEach(pageData => {
                        if (pageData.tables && pageData.tables.length > 0) {
                            pageData.tables.forEach(table => {
                                allTables.push(table);
                            });
                        }
                    });

                    // 構建結果數據結構
                    const domData = {
                        pageInfo: pageInfo,
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed,
                            player_account: playerAccountParsed
                        },
                        totalPages: allPagesData.length,
                        pages: allPagesData,
                        tables: allTables
                    };

                    // 合併所有提取的資料
                    const result = {
                        timestamp: new Date().toISOString(),
                        url: finalTargetUrl,
                        queryParams: {
                            date_start: dateStartParsed,
                            date_end: dateEndParsed,
                            player_account: playerAccountParsed
                        },
                        domData: domData,
                        success: true
                    };

                    // 將結果保存為 JSON 文件
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');

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
                process.exit(0);
            }).catch((error) => {
                console.error('💥 DOM scraping failed:', error);
                process.exit(1);
            });
        JS;

        // 設定腳本保存路徑
        $scriptPath = storage_path('app/temp/scraper_tag_dom.js');

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
        
        // 獲取查詢參數
        $queryParams = $result['queryParams'] ?? [];

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存合併後的表格資料到單一 JSON 文件（主要輸出文件）
        $allData = [];
        $totalRows = 0;
        $headers = [];
        
        // 優先從 pages 中提取數據（因為數據是按頁面組織的）
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
        
        $this->info('📊 Total rows extracted: ' . $totalRows);

        // 初始化合併後的檔案名稱
        $mergedFileName = null;
        
        // 如果有資料，保存合併後的資料
        if (!empty($allData)) {
            unset($row);
            
            // 創建合併後的數據結構
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
            
            // 保存合併後的資料到單一 JSON 檔案
            $mergedFileName = "scraped_data/scraped_data_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $this->info("✅ Data saved successfully!");
        }

        if (!$mergedFileName) {
            $this->warn("⚠️ No data to save.");
        }

        // 將截圖從臨時目錄移動到永久儲存目錄
        $timestamp = date('Y-m-d_H-i-s');
        $screenshotFiles = [
            'step_08_after_first_page.png',
        ];
        
        foreach ($screenshotFiles as $screenshotFile) {
            $screenshotSrc = storage_path('app/temp/' . $screenshotFile);
            if (file_exists($screenshotSrc)) {
                $screenshotDst = storage_path("app/scraped_data/{$timestamp}_{$screenshotFile}");
                rename($screenshotSrc, $screenshotDst);
                $this->info("📸 Screenshot saved: {$screenshotDst}");
            }
        }

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}

