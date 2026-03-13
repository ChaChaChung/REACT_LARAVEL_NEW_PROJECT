<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令（MT 平台）
 */
class ScrapeBrowserMtDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-mt-dom-detail {url}
     * {url}             - 要爬取的目標網址（必需參數）
     * {date_start?}     - 要選擇的開始日期（可選參數，格式 YYYYMMDD）
     * {date_end?}       - 要選擇的結束日期（可選參數，格式 YYYYMMDD）
     * {account_number?} - 要搜尋的帳號（可選參數）
     */
    protected $signature = 'agent:scrape-mt-dom-detail {url} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from MT DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        ini_set('memory_limit', '1G');

        $url           = $this->argument('url');
        $dateStart     = $this->argument('date_start');
        $dateEnd       = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');
        $domain        = env('MT_AGENT_DOMAIN', '');

        $this->info('=== MT Browser DOM Scraper ===');
        $this->info("Login Domain: " . ($domain ?: '(derive from url)'));
        $this->info("Target URL: {$url}");
        $this->info("Date Start: " . ($dateStart ?: '—'));
        $this->info("Date End: "   . ($dateEnd   ?: '—'));
        $this->info("Account Number: " . ($accountNumber ?: '-'));
        $this->info('Start time: ' . date('Y-m-d H:i:s'));

        if (empty($url)) {
            $this->error('❌ url is required');
            return 1;
        }

        if (!$this->checkNodeJs()) {
            return 1;
        }

        $scriptPath = $this->createPuppeteerScript($url, $domain, $dateStart, $dateEnd, $accountNumber);
        $result     = $this->runPuppeteerScript($scriptPath);

        if ($result && !empty($result['success'])) {
            $this->info('✅ Login and navigation completed.');
            $this->processScreenshot($result);
            $this->processTableData($result);
            return 0;
        }

        $this->error('❌ Browser automation failed or no result.');
        return 1;
    }

    // ─────────────────────────────────────────────
    // Node.js / Puppeteer 環境檢查
    // ─────────────────────────────────────────────

    /**
     * 檢查 Node.js 和 Puppeteer 環境
     */
    private function checkNodeJs(): bool
    {
        $this->info('1. Checking Node.js installation...');
        $result = Process::run('node --version');
        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            return false;
        }
        $this->info('✅ Node.js found: ' . trim($result->output()));

        $puppeteerCheck = Process::run('npm list puppeteer-real-browser --depth=0');
        if ($puppeteerCheck->failed()) {
            $this->warn('⚠️  puppeteer-real-browser not found. Installing...');
            $install = Process::run('npm install puppeteer-real-browser');
            if ($install->failed()) {
                $this->error('❌ Failed to install puppeteer-real-browser');
                return false;
            }
            $this->info('✅ puppeteer-real-browser installed successfully');
        } else {
            $this->info('✅ puppeteer-real-browser found');
        }
        return true;
    }

    // ─────────────────────────────────────────────
    // 建立 Puppeteer 腳本
    // ─────────────────────────────────────────────

    /**
     * 建立 Puppeteer 腳本：先登入 MT，再跳轉到 url，根據參數填入日期/帳號後爬取 DOM 表格
     */
    private function createPuppeteerScript(
        string  $url,
        string  $domain,
        ?string $dateStart     = null,
        ?string $dateEnd       = null,
        ?string $accountNumber = null
    ): string {
        $this->info('2. Creating browser automation script...');

        $urlJs           = json_encode($url);
        $domainJs        = json_encode($domain);
        $dateStartJs     = $dateStart     ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs       = $dateEnd       ? json_encode(date('Y-m-d', strtotime($dateEnd)))   : 'null';
        $accountNumberJs = $accountNumber ? json_encode($accountNumber)                        : 'null';

        // 登入流程程式碼（MT 帳密登入）
        $loginCode = $this->generateMtPuppeteerLoginCode('page');

        $script = <<<JS
            const { connect } = require('puppeteer-real-browser');
            const fs   = require('fs');
            const path = require('path');

            const targetUrl     = $urlJs;
            const configDomain  = $domainJs;
            const dateStart     = $dateStartJs;
            const dateEnd       = $dateEndJs;
            const accountNumber = $accountNumberJs;

            async function run() {
                console.log('🚀 MT: Starting login and navigate...');

                // puppeteer-real-browser 使用真實 Chrome，自動繞過反爬蟲偵測
                const { browser, page } = await connect({
                    headless: false,
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                    ],
                    turnstile: true,
                    connectOption: {},
                    disableXvfb: false,
                    ignoreAllFlags: false,
                });

                try {
                    await page.setViewport({ width: 1920, height: 1080 });

                    // ── 決定登入入口 URL ──
                    // 登入域（uag533）與目標域（uagm33）是不同的子域名，需要在登入後
                    // 把 cookie domain 設為父域 .ofalive99.net，讓兩邊都能用同一 session
                    let loginUrl;
                    if (configDomain && configDomain !== '') {
                        let d = configDomain;
                        if (!d.startsWith('http://') && !d.startsWith('https://')) {
                            d = 'https://' + d;
                        }
                        loginUrl = d.replace(/\/$/, '');
                    } else {
                        try {
                            const u = new URL(targetUrl);
                            loginUrl = u.origin;
                        } catch (e) {
                            loginUrl = targetUrl;
                        }
                    }

                    console.log('🌐 Navigating to login page:', loginUrl);
                    await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // ── MT 帳密登入流程 ──
                    $loginCode

                    // ── 登入後：擷取 localStorage（JWT token 可能在這裡）──
                    let loginLocalStorage = {};
                    try {
                        loginLocalStorage = await page.evaluate(() => {
                            const data = {};
                            for (let i = 0; i < localStorage.length; i++) {
                                const key = localStorage.key(i);
                                data[key] = localStorage.getItem(key);
                            }
                            return data;
                        });
                        const lsKeys = Object.keys(loginLocalStorage);
                        console.log('💾 localStorage after login (' + lsKeys.length + ' keys):', lsKeys.join(', '));
                    } catch (lsErr) {
                        console.log('⚠️  Failed to capture localStorage:', lsErr.message);
                    }

                    // ── 跳轉到目標 URL ──
                    console.log('🔄 Navigating to target URL:', targetUrl);
                    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 })
                        .catch(() => console.log('⚠️  Navigation timeout, continuing anyway...'));

                    // ── 注入 localStorage 到目標域，讓 SPA 用正確 token 初始化 ──
                    if (Object.keys(loginLocalStorage).length > 0) {
                        try {
                            await page.evaluate((lsData) => {
                                Object.entries(lsData).forEach(([key, value]) => {
                                    localStorage.setItem(key, value);
                                });
                            }, loginLocalStorage);
                            console.log('💉 Injected localStorage into target domain, reloading...');
                            // reload 讓 SPA 用注入的 token 重新初始化
                            await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 })
                                .catch(() => {});
                            await new Promise(r => setTimeout(r, 2000));
                        } catch (injectErr) {
                            console.log('⚠️  localStorage injection failed:', injectErr.message);
                        }
                    }

                    // SPA hash routing：等待頁面主要元素出現（最多 30 秒）
                    console.log('⏳ Waiting for SPA initial render...');
                    await page.waitForFunction(
                        () => document.querySelectorAll('input, table, [class*="el-"], [class*="ant-"], nav, aside').length > 0,
                        { timeout: 30000, polling: 500 }
                    ).catch(() => console.log('⚠️  SPA initial render timeout'));

                    // 等待 loading spinner 消失（最多 30 秒）— spinner 消失代表資料載入完成
                    console.log('⏳ Waiting for loading spinner to disappear...');
                    await page.waitForFunction(
                        () => {
                            // 偵測常見的 loading spinner selector
                            const spinners = document.querySelectorAll(
                                '.loading, .spinner, [class*="loading"], [class*="spinner"], ' +
                                '.el-loading-mask, .ant-spin, .v-spinner, ' +
                                '[class*="load-mask"], [class*="loadmask"]'
                            );
                            // 如果沒有 spinner，或所有 spinner 都是 hidden，視為載入完成
                            if (spinners.length === 0) return true;
                            return Array.from(spinners).every(el => {
                                const style = window.getComputedStyle(el);
                                return style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0';
                            });
                        },
                        { timeout: 30000, polling: 500 }
                    ).then(() => console.log('✅ Loading complete'))
                     .catch(() => console.log('⚠️  Loading spinner still visible after 30s, continuing anyway...'));

                    // 再給 SPA 額外 1 秒完成 DOM 更新
                    await new Promise(r => setTimeout(r, 1000));

                    // ── 偵錯：印出頁面所有 input 屬性，確認實際 selector ──
                    const dumpInputs = async (label) => {
                        const inputs = await page.evaluate(() => {
                            return Array.from(document.querySelectorAll('input')).map(el => ({
                                placeholder: el.getAttribute('placeholder') || '',
                                name:        el.getAttribute('name')        || '',
                                id:          el.getAttribute('id')          || '',
                                type:        el.getAttribute('type')        || '',
                                visible:     el.offsetParent !== null,
                            }));
                        });
                        console.log('🔍 ' + label + ' inputs (' + inputs.length + '):');
                        inputs.forEach((inp, i) => {
                            console.log('  [' + i + '] placeholder="' + inp.placeholder + '" name="' + inp.name + '" id="' + inp.id + '" type="' + inp.type + '" visible=' + inp.visible);
                        });
                        return inputs;
                    };

                    const workingDirEarly = require('path').dirname(process.argv[1]);

                    // 初始截圖
                    await page.screenshot({ path: require('path').join(workingDirEarly, 'mt_early_screenshot.png'), fullPage: true }).catch(() => {});
                    console.log('📸 Early screenshot saved');
                    await dumpInputs('INITIAL');

                    // ── 嘗試點擊側邊欄「注單查詢」連結 ──
                    console.log('🖱️  Trying to click sidebar "注單查詢" link...');
                    const clickedMenu = await page.evaluate(() => {
                        // 尋找包含「注單查詢」文字的可點擊元素
                        const allLinks = Array.from(document.querySelectorAll('a, li, span, div'));
                        const target = allLinks.find(el => {
                            const t = (el.textContent || '').trim();
                            return t === '注單查詢' || t === '注单查询';
                        });
                        if (target) {
                            target.click();
                            return target.tagName + ' | ' + target.textContent.trim();
                        }
                        return null;
                    });

                    if (clickedMenu) {
                        console.log('✅ Clicked menu item:', clickedMenu);
                        // 等待頁面更新（最多 10 秒）
                        await new Promise(r => setTimeout(r, 3000));
                        await page.screenshot({ path: require('path').join(workingDirEarly, 'mt_after_menu_click.png'), fullPage: true }).catch(() => {});
                        console.log('📸 After menu click screenshot saved');
                        await dumpInputs('AFTER_MENU_CLICK');
                    } else {
                        console.log('⚠️  Could not find 注單查詢 menu item');
                    }

                    // ── 印出頁面主要的 HTML 結構，幫助診斷 ──
                    const bodyStructure = await page.evaluate(() => {
                        const main = document.querySelector('main, .main, #main, .content, #content, [class*="content"], [class*="main"]');
                        if (!main) return 'NO MAIN ELEMENT';
                        return main.innerHTML.substring(0, 2000);
                    });
                    console.log('🔍 Main content HTML (first 2000 chars):', bodyStructure.substring(0, 500));

                    // 用 waitForFunction 輪詢日期欄位出現（最多 30 秒）
                    console.log('⏳ Polling for date inputs...');
                    const routeMounted = await page.waitForFunction(
                        () => !!(document.querySelector('input[placeholder="Start date"]') ||
                                 document.querySelector('input[placeholder="End date"]')),
                        { timeout: 30000, polling: 500 }
                    ).then(() => true).catch(() => false);

                    if (routeMounted) {
                        console.log('✅ Date inputs found');
                    } else {
                        console.log('⚠️  Date inputs not found after 30s');
                        await dumpInputs('FINAL');
                    }

                    const finalUrl = page.url();
                    console.log('✅ Reached URL:', finalUrl);

                    // ── 選填帳號搜尋欄位 ──
                    if (accountNumber && accountNumber !== 'null') {
                        console.log('🔍 Filling account number:', accountNumber);
                        try {
                            // 嘗試多種帳號輸入欄位選擇器
                            const accountSelectors = [
                                'input[name="username"]',
                                'input[name="account"]',
                                'input[name="loginName"]',
                                'input[name="login_name"]',
                                'input[placeholder*="帳號"]',
                                'input[placeholder*="account"]',
                                'input[placeholder*="Account"]',
                                'input[placeholder*="username"]',
                                'input[placeholder*="Username"]',
                            ];
                            let accountFilled = false;
                            for (const sel of accountSelectors) {
                                const input = await page.$(sel);
                                if (input) {
                                    await page.evaluate((el, val) => {
                                        el.value = '';
                                        el.value = val;
                                        el.dispatchEvent(new Event('input',  { bubbles: true }));
                                        el.dispatchEvent(new Event('change', { bubbles: true }));
                                        el.dispatchEvent(new Event('blur',   { bubbles: true }));
                                    }, input, accountNumber);
                                    console.log('✅ Account number filled via:', sel);
                                    accountFilled = true;
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    break;
                                }
                            }
                            if (!accountFilled) {
                                console.log('⚠️  Account number input field not found, skipping.');
                            }
                        } catch (e) {
                            console.log('⚠️  Error filling account number:', e.message);
                        }
                    }

                    // ── 填入日期範圍（Ant Design RangePicker 互動式操作）──
                    const hasDates = (dateStart && dateStart !== 'null') || (dateEnd && dateEnd !== 'null');
                    if (hasDates) {
                        console.log('📅 Setting date range:', dateStart, '~', dateEnd);
                        try {
                            // Ant Design RangePicker 需要：
                            // 1. 點擊 Start date input 開啟日曆
                            // 2. 導航到正確月份（若需要）
                            // 3. 點擊 start date 格子 (title="YYYY-MM-DD")
                            // 4. 點擊 end date 格子
                            // 5. 點擊 Ok 按鈕確認

                                // 日期值
                                const startVal = dateStart && dateStart !== 'null' ? dateStart : null;
                                const endVal   = dateEnd   && dateEnd   !== 'null' ? dateEnd   : null;

                                // ── 格式轉換：dateStart/dateEnd → "YYYY/MM/DD HH:mm:ss" ──
                                // e.g. "2026-03-01" → "2026/03/01 00:00:00"
                                const formatForPicker = (dateStr, isEnd) => {
                                    if (!dateStr || dateStr === 'null') return null;
                                    const d = dateStr.replace(/-/g, '/');
                                    return d + (isEnd ? ' 23:59:59' : ' 00:00:00');
                                };
                                const startFormatted = formatForPicker(startVal, false);
                                const endFormatted   = formatForPicker(endVal,   true);
                                console.log('📅 Formatted dates:', startFormatted, '~', endFormatted);

                                if (!routeMounted) {
                                    console.log('⚠️  Date input fields not found, skipping date filter.');
                                } else {
                                    // ── Step 1：清除並 type 開始日期 ──
                                    const startInput = await page.$('input[placeholder="Start date"]');
                                    if (startInput && startFormatted) {
                                        await startInput.click({ clickCount: 3 });
                                        await new Promise(r => setTimeout(r, 200));
                                        await page.keyboard.down('Control');
                                        await page.keyboard.press('a');
                                        await page.keyboard.up('Control');
                                        await page.keyboard.press('Delete');
                                        await new Promise(r => setTimeout(r, 200));
                                        await startInput.type(startFormatted, { delay: 40 });
                                        console.log('📅 Typed start date:', startFormatted);
                                        await new Promise(r => setTimeout(r, 400));
                                    }

                                    // ── Step 2：清除並 type 結束日期 ──
                                    const endInput = await page.$('input[placeholder="End date"]');
                                    if (endInput && endFormatted) {
                                        await endInput.click({ clickCount: 3 });
                                        await new Promise(r => setTimeout(r, 200));
                                        await page.keyboard.down('Control');
                                        await page.keyboard.press('a');
                                        await page.keyboard.up('Control');
                                        await page.keyboard.press('Delete');
                                        await new Promise(r => setTimeout(r, 200));
                                        await endInput.type(endFormatted, { delay: 40 });
                                        console.log('📅 Typed end date:', endFormatted);
                                        await new Promise(r => setTimeout(r, 400));
                                    }

                                    // ── Step 3：點擊 Ok 按鈕確認 ──
                                    const okClicked = await page.evaluate(() => {
                                        const okBtn = document.querySelector('.ant-picker-ok button, .ant-picker-footer .ant-btn-primary');
                                        if (okBtn) { okBtn.click(); return true; }
                                        return false;
                                    });
                                    if (okClicked) {
                                        console.log('✅ Clicked Ok to confirm date range');
                                    } else {
                                        console.log('⚠️  Ok button not found, pressing Escape');
                                        await page.keyboard.press('Escape');
                                    }
                                    await new Promise(r => setTimeout(r, 500));


                                // ── Step 5：點擊查詢按鈕 ──
                                await new Promise(r => setTimeout(r, 500));
                                const searchUid = await page.evaluate(() => {
                                    const candidates = Array.from(document.querySelectorAll('button, input[type="submit"]'));
                                    const btn = candidates.find(b => {
                                        const classes = b.className || '';
                                        const t = (b.textContent || b.value || '').trim().toLowerCase();
                                        return t.includes('查詢') || t.includes('search') || t.includes('搜尋') || t.includes('query') || classes.includes('action-botton');
                                    });
                                    if (btn) {
                                        const uid = 'mt-search-' + Date.now();
                                        btn.setAttribute('data-mt-uid', uid);
                                        return uid;
                                    }
                                    return null;
                                });

                                if (searchUid) {
                                    await page.click('[data-mt-uid="' + searchUid + '"]');
                                    console.log('🔍 Clicked search button');
                                } else {
                                    console.log('⚠️  Search button not found');
                                }

                                // 等待表格資料載入穩定
                                await new Promise(r => setTimeout(r, 3000));
                            }
                        } catch (dateErr) {
                            console.log('⚠️  Date setting step:', dateErr.message);
                        }
                    }

                    // ── 等待表格出現並穩定 ──
                    console.log('⏳ Waiting for table to appear...');
                    // 優先等待 table-layout: auto 的表格，否則回退到普通 table
                    const tableSelector = 'table[style*="table-layout: auto"], table';
                    const tableFound = await page.waitForSelector(tableSelector + ' tbody tr', { timeout: 20000 })
                        .then(() => true).catch(() => false);

                    if (tableFound) {
                        let prevRowCount = 0;
                        let stableCount   = 0;
                        const maxWaitMs   = 15000;
                        const startAt    = Date.now();
                        while (Date.now() - startAt < maxWaitMs) {
                            const rowCount = await page.evaluate((sel) => {
                                const table = document.querySelector(sel);
                                const tbody = table ? table.querySelector('tbody') : null;
                                return tbody ? tbody.querySelectorAll('tr').length : 0;
                            }, tableSelector);
                            if (rowCount === prevRowCount) {
                                stableCount++;
                                if (stableCount >= 3) break;
                            } else {
                                stableCount  = 0;
                                prevRowCount = rowCount;
                            }
                            await new Promise(r => setTimeout(r, 500));
                        }
                        console.log('✅ Table data stabilized (' + prevRowCount + ' rows detected)');
                    } else {
                        console.log('⚠️  Table not found after 20s, continuing anyway...');
                    }

                    // ── 翻頁爬取：取得總頁數並逐頁爬取 ──
                    const scrapeCurrentPage = async () => {
                        return await page.evaluate(() => {
                            // 優先尋找 table-layout: auto 的表格，否則尋找欄位最多的表格
                            const autoTables = Array.from(document.querySelectorAll('table[style*="table-layout: auto"]'));
                            let table = null;
                            if (autoTables.length > 0) {
                                table = autoTables[0];
                            } else {
                                const allTables = Array.from(document.querySelectorAll('table'));
                                if (allTables.length === 0) return { found: false, error: 'No table found' };
                                table = allTables.reduce((best, t) => {
                                    const colCount = (t.querySelector('thead tr') || t.querySelector('tr'))?.querySelectorAll('th, td').length || 0;
                                    const bestCount = (best.querySelector('thead tr') || best.querySelector('tr'))?.querySelectorAll('th, td').length || 0;
                                    return colCount > bestCount ? t : best;
                                }, allTables[0]);
                            }

                            // 取欄位標題
                            let headers = [];
                            const thead = table.querySelector('thead');
                            if (thead) {
                                const headerRow = thead.querySelector('tr');
                                if (headerRow) {
                                    headers = Array.from(headerRow.querySelectorAll('th, td'))
                                        .map(c => (c.textContent || '').trim().replace(/\s+/g, ' '))
                                        .filter(h => h !== '');
                                }
                            }
                            // 若 thead 沒標題，嘗試第一行
                            if (headers.length === 0) {
                                const firstRow = table.querySelector('tr');
                                if (firstRow) {
                                    headers = Array.from(firstRow.querySelectorAll('th, td'))
                                        .map(c => (c.textContent || '').trim());
                                }
                            }

                            const makeKey = (h, idx) => {
                                const s = (h || '').trim();
                                return s ? s.replace(/\s+/g, '_') : ('column_' + idx);
                            };
                            const keyList = [];
                            const seen    = {};
                            headers.forEach((h, idx) => {
                                let k = makeKey(h, idx);
                                if (seen[k]) { seen[k]++; k = k + '_' + seen[k]; } else { seen[k] = 1; }
                                keyList.push(k);
                            });

                            // 爬取 tbody 所有行
                            const tbody = table.querySelector('tbody');
                            const trs   = tbody ? tbody.querySelectorAll('tr') : table.querySelectorAll('tr');
                            const allRows = [];
                            for (const tr of trs) {
                                const cells = tr.querySelectorAll('td, th');
                                if (cells.length === 0) continue;
                                const row = Array.from(cells).map(c =>
                                    (c.textContent || '').trim().replace(/\s+/g, ' ')
                                );
                                const obj = {};
                                keyList.forEach((k, idx) => {
                                    obj[k] = row[idx] !== undefined ? row[idx] : '';
                                });
                                allRows.push(obj);
                            }

                            if (allRows.length === 0) {
                                return { found: false, error: 'No data rows found' };
                            }
                            return { found: true, headers: headers, keyList: keyList, data: allRows };
                        });
                    };

                    // 取得總頁數
                    const getTotalPages = async () => {
                        return await page.evaluate(() => {
                            // 嘗試多種分頁器格式
                            // 格式 1: pageNow() onclick
                            const onclickLinks = Array.from(document.querySelectorAll('ul.pagination li a[onclick]'))
                                .map(a => {
                                    const m = (a.getAttribute('onclick') || '').match(/pageNow\\('(\\d+)'\\)/);
                                    return m ? parseInt(m[1], 10) : 0;
                                })
                                .filter(n => n > 0);
                            if (onclickLinks.length > 0) return Math.max(...onclickLinks);

                            // 格式 2: 分頁按鈕的文字頁碼
                            const pageButtons = Array.from(document.querySelectorAll(
                                'ul.pagination li a, .pagination a, .page-link'
                            ));
                            const pageNums = pageButtons
                                .map(a => parseInt((a.textContent || '').trim(), 10))
                                .filter(n => !isNaN(n) && n > 0);
                            if (pageNums.length > 0) return Math.max(...pageNums);

                            // 格式 3: 「共 N 頁」文字
                            const paginationText = document.querySelector('.pagination-info, .page-info, .total-pages');
                            if (paginationText) {
                                const m = paginationText.textContent.match(/(\\d+)/g);
                                if (m && m.length > 0) return parseInt(m[m.length - 1], 10);
                            }

                            return 1; // 預設 1 頁
                        });
                    };

                    // 跳到指定頁
                    const goToPage = async (pageNum) => {
                        try {
                            await Promise.all([
                                page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
                                page.evaluate((n) => {
                                    if (typeof pageNow === 'function') {
                                        pageNow(String(n));
                                        return;
                                    }
                                    // 嘗試點擊頁碼按鈕
                                    const links = document.querySelectorAll('ul.pagination li a, .pagination a, .page-link');
                                    for (const a of links) {
                                        if (parseInt((a.textContent || '').trim(), 10) === n) {
                                            a.click();
                                            return;
                                        }
                                        const m = (a.getAttribute('onclick') || '').match(/pageNow\\('(\\d+)'\\)/);
                                        if (m && parseInt(m[1], 10) === n) { a.click(); return; }
                                    }
                                }, pageNum)
                            ]);
                            await page.waitForSelector('table tbody tr', { timeout: 10000 }).catch(() => {});
                            console.log('✅ Navigated to page ' + pageNum);
                            return true;
                        } catch (e) {
                            console.log('⚠️  goToPage(' + pageNum + '):', e.message);
                            return false;
                        }
                    };

                    // ── 主翻頁迴圈 ──
                    let allHeaders = [];
                    let allKeyList = [];
                    let allData    = [];
                    let totalPages = 0;

                    const lastPage = await getTotalPages();
                    console.log('📑 Total pages detected: ' + lastPage);

                    for (let currentPage = 1; currentPage <= lastPage; currentPage++) {
                        totalPages++;
                        console.log('📄 Scraping page ' + currentPage + ' / ' + lastPage + '...');

                        await page.waitForSelector('table tbody tr', { timeout: 10000 }).catch(() => {});

                        const pageResult = await scrapeCurrentPage();

                        if (!pageResult || !pageResult.found) {
                            console.log('⚠️  Table not found on page ' + currentPage + ': ' + (pageResult && pageResult.error || ''));
                            break;
                        }

                        if (allHeaders.length === 0) {
                            allHeaders = pageResult.headers;
                            allKeyList = pageResult.keyList;
                        }
                        allData = allData.concat(pageResult.data);
                        console.log('📋 Page ' + currentPage + ' rows: ' + pageResult.data.length + ' (total: ' + allData.length + ')');

                        if (currentPage < lastPage) {
                            const moved = await goToPage(currentPage + 1);
                            if (!moved) {
                                console.log('⚠️  Could not navigate to page ' + (currentPage + 1) + '. Stopping.');
                                break;
                            }
                            await new Promise(r => setTimeout(r, 800));
                        }
                    }

                    console.log('✅ All pages scraped. Total pages: ' + totalPages + ', total rows: ' + allData.length);

                    const tableData = allHeaders.length > 0
                        ? { found: true, headers: allHeaders, keys: allKeyList, rowCount: allData.length, data: allData }
                        : { found: false, error: 'Table not found' };

                    if (tableData.found && tableData.data.length > 0) {
                        console.log('📋 Total table rows scraped: ' + tableData.rowCount + ' (across ' + totalPages + ' pages)');
                    } else if (!tableData.found) {
                        console.log('⚠️  Table not found');
                    } else {
                        console.log('⚠️  Table found but no data rows');
                    }

                    // ── 截圖 ──
                    const workingDir       = require('path').dirname(process.argv[1]);
                    const resultPath       = path.join(workingDir, 'scraped_result.json');
                    const screenshotFile   = 'mt_scraped_page.png';
                    const screenshotPath   = path.join(workingDir, screenshotFile);
                    try {
                        await page.screenshot({ path: screenshotPath, fullPage: false });
                        console.log('📸 Screenshot saved: ' + screenshotFile);
                    } catch (screenshotErr) {
                        console.log('⚠️  Screenshot failed:', screenshotErr.message);
                    }

                    const pageTitle  = await page.title().catch(() => '');
                    const queryParams = {
                        date_start:     dateStart     || null,
                        date_end:       dateEnd       || null,
                        account_number: accountNumber || null,
                    };

                    const result = {
                        success:        true,
                        url:            finalUrl,
                        screenshotPath: screenshotFile,
                        date_start:     dateStart     || null,
                        date_end:       dateEnd       || null,
                        account_number: accountNumber || null,
                        queryParams:    queryParams,
                        domData: {
                            pageInfo:    { title: pageTitle.trim(), url: finalUrl },
                            queryParams: queryParams,
                            totalPages:  totalPages,
                            pages:       [],
                            tables:      [tableData]
                        },
                        tableData:  tableData,
                        timestamp:  new Date().toISOString()
                    };

                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    console.log('💾 Result written to scraped_result.json');

                } catch (error) {
                    console.error('❌ Error:', error.message);
                    const workingDir = require('path').dirname(process.argv[1]);
                    const resultPath = path.join(workingDir, 'scraped_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify({
                        success: false,
                        error:   error.message,
                        timestamp: new Date().toISOString()
                    }, null, 2));
                } finally {
                    await browser.close();
                    console.log('🏁 Browser closed');
                }
            }

            run().then(() => process.exit(0)).catch((err) => {
                console.error(err);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scraper_mt_dom.js');
        $dir = dirname($scriptPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        $this->info("✅ Script created: {$scriptPath}");
        return $scriptPath;
    }

    // ─────────────────────────────────────────────
    // 執行 Puppeteer 腳本並讀取結果
    // ─────────────────────────────────────────────

    /**
     * 執行 Puppeteer 腳本並讀取結果
     */
    private function runPuppeteerScript(string $scriptPath): ?array
    {
        $this->info('3. Running browser automation...');
        $workingDir = dirname($scriptPath);
        $result = Process::path($workingDir)->timeout(0)->run('node ' . basename($scriptPath));

        $this->line('');
        $this->line('📋 Browser Output:');
        $this->line($result->output());

        if ($result->failed()) {
            $this->error('❌ Browser automation failed');
            $this->line('Error: ' . $result->errorOutput());
            return null;
        }

        $resultFile = $workingDir . '/scraped_result.json';
        if (file_exists($resultFile)) {
            $this->info('📄 Result file: ' . realpath($resultFile));
            $content    = file_get_contents($resultFile);
            $data       = json_decode($content, true);
            $copyPath   = storage_path('app/scraped_data/scraped_result_mt_' . date('Y-m-d_H-i-s') . '.json');
            $copyDir    = dirname($copyPath);
            if (!is_dir($copyDir)) {
                mkdir($copyDir, 0755, true);
            }
            copy($resultFile, $copyPath);
            $this->info("📋 Copy saved to: {$copyPath}");
            return $data;
        }

        $this->error('❌ No result file found');
        return null;
    }

    // ─────────────────────────────────────────────
    // 後處理：截圖 & 表格資料
    // ─────────────────────────────────────────────

    /**
     * 將截圖從 temp 移到 scraped_data
     */
    private function processScreenshot(array $result): void
    {
        $screenshotPath = $result['screenshotPath'] ?? null;
        if (!$screenshotPath) {
            return;
        }
        $tempDir = dirname(storage_path('app/temp/scraper_mt_dom.js'));
        $src = $tempDir . '/' . $screenshotPath;
        if (!file_exists($src)) {
            $this->warn("⚠️  Screenshot not found: {$src}");
            return;
        }
        $dstDir = storage_path('app/scraped_data');
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }
        $timestamp = date('Y-m-d_H-i-s');
        $dst = "{$dstDir}/mt_screenshot_{$timestamp}.png";
        if (rename($src, $dst)) {
            $this->info("📸 Screenshot saved to: {$dst}");
        } else {
            $this->warn("⚠️  Could not move screenshot to {$dst}");
        }
    }

    /**
     * 處理並儲存表格資料
     */
    private function processTableData(array $result): void
    {
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $dstDir    = storage_path('app/scraped_data');
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }

        // 表格資料來源：優先 domData.tables，否則 tableData
        $tableData = null;
        $domData   = $result['domData'] ?? [];
        if (!empty($domData['tables']) && is_array($domData['tables'])) {
            $firstTable = $domData['tables'][0] ?? null;
            if ($firstTable && is_array($firstTable)) {
                $tableData = $firstTable;
            }
        }
        if ($tableData === null) {
            $tableData = $result['tableData'] ?? null;
        }

        if ($tableData === null || !is_array($tableData)) {
            $this->warn('⚠️  No table data in result.');
            return;
        }

        if (!($tableData['found'] ?? false) || !isset($tableData['data'])) {
            $this->warn('⚠️  Table not found or empty: ' . ($tableData['error'] ?? 'no data'));
            return;
        }

        $headers = $tableData['headers'] ?? [];
        $keys    = $tableData['keys']    ?? $headers;
        $allData = $tableData['data']    ?? [];

        // 清除前後空白及括弧內容
        $allData = array_map(function ($row) {
            if (!is_array($row)) {
                return $row;
            }
            return array_map(function ($v) {
                if (!is_string($v)) {
                    return $v;
                }
                return preg_replace('/\s+/u', '', trim(preg_replace('/[（(][^）)]*[）)]/u', '', $v)));
            }, $row);
        }, $allData);

        $totalRows    = count($allData);
        $totalPagesRaw = $domData['totalPages'] ?? 1;

        $this->info('📋 Total rows: ' . $totalRows);
        $this->info('📋 Total pages: ' . $totalPagesRaw);
        $this->info('📋 Table headers: ' . count($headers) . ' columns');

        // 統計彙總（數字欄位加總）
        $summary = [];
        foreach ($headers as $i => $header) {
            if (!$header) {
                continue;
            }
            $key      = $keys[$i] ?? $header;
            $numTotal = 0;
            $isNum    = false;
            foreach ($allData as $row) {
                $val = $row[$key] ?? '';
                $clean = preg_replace('/[^\d\-\.]/u', '', $val);
                if (is_numeric($clean) && $clean !== '') {
                    $numTotal += (float) $clean;
                    $isNum = true;
                }
            }
            if ($isNum) {
                $summary[$key] = $numTotal;
            }
        }

        // 建立彙總列（如果有數字欄位）
        $summaryRow = [];
        if (!empty($summary)) {
            foreach ($keys as $k) {
                $summaryRow[$k] = $summary[$k] ?? '';
            }
            // 第一欄標示為 Summary
            if (!empty($keys)) {
                $summaryRow[$keys[0]] = 'Summary';
            }
            // 第二欄標示資料筆數
            if (count($keys) > 1) {
                $summaryRow[$keys[1]] = "Count: {$totalRows}";
            }
            $this->info('📊 Summary row generated.');
        }

        // 儲存主要 JSON 檔案
        $fileName = "scraped_data/mt_table_data_{$timestamp}.json";
        $fileData = [
            'metadata' => [
                'timestamp'     => $timestamp,
                'url'           => $result['url']           ?? '',
                'date_start'    => $result['date_start']    ?? null,
                'date_end'      => $result['date_end']      ?? null,
                'account_number'=> $result['account_number']?? null,
                'totalPages'    => $totalPagesRaw,
                'totalRows'     => $totalRows,
                'headers'       => $headers,
            ],
            'headers'    => $headers,
            'keys'       => $keys,
            'totalPages' => $totalPagesRaw,
            'totalRows'  => $totalRows,
            'data'       => $allData,
            'summary'    => $summaryRow ?: null,
        ];

        Storage::put($fileName, json_encode($fileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("✅ Table data saved: {$fileName}");
        $this->info("📊 Total rows: {$totalRows}");

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info('✅ MT DOM scraping completed!');
    }
}
