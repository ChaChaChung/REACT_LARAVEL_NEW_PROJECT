<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器內容爬蟲命令
 */
class ScrapeBrowserDgDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-dg-dom-detail {url} {date_start?} {date_end?} {account_number?}
     */
    protected $signature = 'agent:scrape-dg-dom-detail {url} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from DG DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        ini_set('memory_limit', '1G');
        $url = $this->argument('url');
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');
        $domain = env('DG_AGENT_DOMAIN', '');

        $this->info('=== DG Browser Login & Navigate ===');
        $this->info("Login Domain: " . ($domain ?: '(derive from url)'));
        $this->info("Target URL: {$url}");
        $this->info("Date Start: " . ($dateStart ?: '—'));
        $this->info("Date End: " . ($dateEnd ?: '—'));
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
        $result = $this->runPuppeteerScript($scriptPath);

        if ($result && !empty($result['success'])) {
            $this->info('✅ Login and navigation completed.');
            $this->processScreenshot($result);
            $this->processTableData($result);
            return 0;
        }

        $this->error('❌ Browser automation failed or no result.');
        return 1;
    }

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

    /**
     * 創建 Puppeteer 腳本：先進入 domain 設定 cookie（登入），再跳轉到 url，若有 date_start/date_end 則點開 date picker 後截圖
     */
    private function createPuppeteerScript(string $url, string $domain, ?string $dateStart = null, ?string $dateEnd = null, ?string $accountNumber = null): string
    {
        $this->info('2. Creating browser automation script...');

        // 若 DG_AGENT_DOMAIN 為空，從目標 url 推導 host，否則 cookie 不會被設定導致登入失敗
        $domainForCookies = $domain;
        if ($domainForCookies === '') {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host) {
                $domainForCookies = $host;
            }
        }

        $urlJs = json_encode($url);
        $domainJs = json_encode($domain);
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';
        $cookiesCode = $this->generateDgPuppeteerCookiesCode('page', $domainForCookies !== '' ? $domainForCookies : null);

        $script = <<<JS
            const { connect } = require('puppeteer-real-browser');
            const fs = require('fs');
            const path = require('path');

            const targetUrl = $urlJs;
            const configDomain = $domainJs;
            const dateStart = $dateStartJs;
            const dateEnd = $dateEndJs;
            const accountNumber = $accountNumberJs;

            async function run() {
                console.log('🚀 DG: Starting login and navigate...');

                const stepScreenshots = [];
                const workingDirRef = { value: null };

                const takeStepScreenshot = async (page, stepName) => {
                    if (!workingDirRef.value) return;
                    const filename = 'step_' + String(stepScreenshots.length + 1).padStart(2, '0') + '_' + stepName.replace(/[^a-zA-Z0-9_]/g, '_') + '.png';
                    const filePath = path.join(workingDirRef.value, filename);
                    try {
                        await page.screenshot({ path: filePath, fullPage: false });
                        stepScreenshots.push(filename);
                        console.log('📸 Step screenshot: ' + filename);
                    } catch (e) {
                        console.log('⚠️  Step screenshot failed (' + stepName + '): ' + e.message);
                    }
                };

                // puppeteer-real-browser 使用真實 Chrome，自動繞過 Cloudflare bot 驗證
                const { browser, page } = await connect({
                    headless: false,
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                    ],
                    turnstile: true,    // 自動處理 Cloudflare Turnstile
                    connectOption: {},
                    disableXvfb: false,
                    ignoreAllFlags: false,
                });

                try {
                    await page.setViewport({ width: 1920, height: 1080 });

                    workingDirRef.value = require('path').dirname(process.argv[1]);

                    // 先進入目標 domain 任一頁，才能對該 domain 設定 cookie
                    let loginUrl;
                    if (configDomain && configDomain !== '') {
                        let d = configDomain;
                        if (!d.startsWith('http://') && !d.startsWith('https://')) {
                            d = 'https://' + d;
                        }
                        loginUrl = d.replace(/\/\$/, '') + (d.endsWith('/') ? '' : '/');
                    } else {
                        try {
                            const u = new URL(targetUrl);
                            loginUrl = u.origin + '/';
                        } catch (e) {
                            loginUrl = targetUrl;
                        }
                    }
                    console.log('🌐 Navigating to domain (for cookie context):', loginUrl);
                    await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    await takeStepScreenshot(page, '01_domain_loaded');

                    // 登入：設定 cookies（session = DG_AGENT_TOKEN, language = DG_AGENT_LANG）
                    $cookiesCode

                    await new Promise(resolve => setTimeout(resolve, 500));

                    // Cookie 設定完成後，直接跳轉到目標 URL
                    console.log('🔄 Navigating to target URL with cookies:', targetUrl);
                    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    await takeStepScreenshot(page, '02_target_url_loaded');

                    // 若目標 url 有 hash，用 client 端導向到對應路由
                    try {
                        const u = new URL(targetUrl);
                        if (u.hash && u.hash.length > 1) {
                            console.log('📍 Setting hash:', u.hash);
                            await page.evaluate((hash) => { window.location.hash = hash; }, u.hash);
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            await takeStepScreenshot(page, '03_hash_navigated');
                        }
                    } catch (e) {}

                    const finalUrl = page.url();
                    console.log('✅ Reached URL:', finalUrl);

                    // ── 選擇 project_uid select 的第一個可用選項 ──
                    try {
                        const projectSelectSelector = 'select.form-control.input-sm[name="project_uid"]';
                        await page.waitForSelector(projectSelectSelector, { timeout: 8000 }).catch(() => null);
                        const projectSelect = await page.$(projectSelectSelector);
                        if (projectSelect) {
                            // 取得第一個非 disabled 的 option value
                            const firstOptionValue = await page.evaluate((sel) => {
                                const select = document.querySelector(sel);
                                if (!select) return null;
                                const option = Array.from(select.options).find(o => !o.disabled);
                                return option ? option.value : null;
                            }, projectSelectSelector);

                            if (firstOptionValue !== null) {
                                await page.select(projectSelectSelector, firstOptionValue);
                                console.log('✅ Selected project_uid option: ' + firstOptionValue);
                                await new Promise(resolve => setTimeout(resolve, 800));
                                await takeStepScreenshot(page, '04_project_uid_selected');
                            } else {
                                console.log('⚠️  No selectable option found in project_uid select');
                            }
                        } else {
                            console.log('⚠️  select[name="project_uid"] not found on page');
                        }
                    } catch (selectErr) {
                        console.log('⚠️  project_uid select step: ' + selectErr.message);
                    }

                    if (accountNumber && accountNumber !== 'null') {
                        const playerInputSelector = 'input.form-control.input-sm[name="player"]';
                        try {
                            console.log('🔍 Checking for player input field...');
                            const playerInput = await page.$(playerInputSelector);
                            if (playerInput) {
                                console.log('📋 Player input field found. Filling in account number...');
                                await page.type(playerInputSelector, accountNumber);
                                console.log(`✅ Filled player input with account number: ${accountNumber}`);
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                await takeStepScreenshot(page, '05_player_input_filled');
                            } else {
                                console.log('⚠️ Player input field not found. Skipping input.');
                            }
                        } catch (playerInputError) {
                            console.log('⚠️ Error during player input field handling:', playerInputError.message);
                        }
                    }

                    // 若有帶 date_start 或 date_end：點擊 laydate input 並輸入日期
                    const hasDates = (dateStart && dateStart !== 'null') || (dateEnd && dateEnd !== 'null');
                    if (hasDates) {
                        try {
                            // 等待 laydate input 出現
                            await page.waitForSelector('input[name="beginTimeStr"], input[name="endTimeStr"]', { timeout: 10000 }).catch(() => null);

                            // 將 YYYY-MM-DD 轉換成 laydate 格式 YYYY/MM/DD hh:mm:ss
                            const toLaydateFormat = (dateStr, isEnd) => {
                                if (!dateStr || dateStr === 'null') return null;
                                const d = dateStr.replace(/-/g, '/');
                                return isEnd ? d + ' 23:59:59' : d + ' 00:00:00';
                            };

                            const beginValue = toLaydateFormat(dateStart, false);
                            const endValue = toLaydateFormat(dateEnd, true);

                            // 輔助函式：點擊 input → 全選清除 → 輸入新值 → Tab 確認 → JS 保底
                            const fillDateInput = async (name, value, stepPrefix) => {
                                if (!value) return;
                                const input = await page.$('input[name="' + name + '"]');
                                if (!input) {
                                    console.log('⚠️  input[name="' + name + '"] not found');
                                    return;
                                }
                                // 點擊開啟 date picker
                                await input.click();
                                await new Promise(resolve => setTimeout(resolve, 800));
                                await takeStepScreenshot(page, stepPrefix + '_picker_open');

                                // 全選舊值後刪除
                                await input.click({ clickCount: 3 });
                                await new Promise(resolve => setTimeout(resolve, 200));
                                await page.keyboard.press('Delete');
                                await new Promise(resolve => setTimeout(resolve, 200));

                                // 逐字輸入新日期值
                                await input.type(value, { delay: 50 });
                                console.log('📅 Typed ' + name + ':', value);
                                await new Promise(resolve => setTimeout(resolve, 300));
                                await takeStepScreenshot(page, stepPrefix + '_typed');

                                // Tab 確認並關閉 picker
                                await page.keyboard.press('Tab');
                                await new Promise(resolve => setTimeout(resolve, 500));
                                await takeStepScreenshot(page, stepPrefix + '_confirmed');

                                // JS 保底：確保 input value 與 change event 被正確設定
                                await page.evaluate((inputName, inputValue) => {
                                    const el = document.querySelector('input[name="' + inputName + '"]');
                                    if (!el) return;
                                    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
                                    setter.call(el, inputValue);
                                    el.dispatchEvent(new Event('input', { bubbles: true }));
                                    el.dispatchEvent(new Event('change', { bubbles: true }));
                                }, name, value);
                            };

                            if (beginValue) await fillDateInput('beginTimeStr', beginValue, '06a_begin_date');
                            if (endValue) await fillDateInput('endTimeStr', endValue, '06b_end_date');

                            await new Promise(resolve => setTimeout(resolve, 300));
                            await takeStepScreenshot(page, '06_date_range_set');

                            // 設定每頁筆數為 1000（最大值），減少總頁數加快爬取
                            try {
                                const pageSizeSelect = await page.$('select#pageSize, select[name="pageSize"]');
                                if (pageSizeSelect) {
                                    await page.select('select#pageSize, select[name="pageSize"]', '1000');
                                    console.log('📊 Set pageSize to 1000');
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                } else {
                                    console.log('⚠️  pageSize select not found');
                                }
                            } catch (e) {
                                console.log('⚠️  pageSize step: ' + e.message);
                            }

                            // 點擊 Search 按鈕
                            const searchBtn = await page.$('button[type="submit"].btn.btn-default');
                            if (searchBtn) {
                                await searchBtn.evaluate(el => el.scrollIntoView({ block: 'center' }));
                                await takeStepScreenshot(page, '07_before_search_click');
                                await searchBtn.click();
                                console.log('🔍 Clicked Search button');
                                // 等待表格第一筆資料出現（最多 20 秒）
                                console.log('⏳ Waiting for memberBetdetailTable to load...');
                                await page.waitForSelector('table#memberBetdetailTable tbody tr', { timeout: 20000 })
                                    .then(() => console.log('✅ Table loaded'))
                                    .catch(() => console.log('⚠️  Table did not load within 20s, continuing anyway'));
                                await new Promise(resolve => setTimeout(resolve, 500));
                                await takeStepScreenshot(page, '08_table_loaded');
                            } else {
                                console.log('⚠️  Search button not found');
                            }
                        } catch (pickerErr) {
                            console.log('⚠️  Date picker step: ' + pickerErr.message);
                        }
                    }

                    // ── 多輪展開：每輪點完所有 fa-plus 後等待新增的子行出現，直到沒有 fa-plus ──
                    const expandAllToggleRows = async () => {
                        let totalClicked = 0;
                        let round = 0;
                        while (true) {
                            round++;
                            const count = await page.evaluate(() => {
                                return document.querySelectorAll('div.btn.btn-xs.app-toggle-btn i.fa-plus').length;
                            });
                            if (count === 0) {
                                console.log('🔽 No more toggles to expand (total clicked: ' + totalClicked + ', rounds: ' + (round - 1) + ')');
                                break;
                            }
                            console.log('🔽 Round ' + round + ': found ' + count + ' toggle(s), clicking one by one...');
                            // 逐一點擊，每次等待 DOM 更新
                            for (let i = 0; i < count; i++) {
                                await page.evaluate((idx) => {
                                    const btns = Array.from(document.querySelectorAll('div.btn.btn-xs.app-toggle-btn'))
                                        .filter(btn => btn.querySelector('i.fa-plus'));
                                    if (btns[idx]) btns[idx].click();
                                }, i);
                                await new Promise(r => setTimeout(r, 100));
                            }
                            totalClicked += count;
                            // 等待子行 DOM 渲染完成，再進行下一輪
                            await new Promise(r => setTimeout(r, 800));
                            console.log('🔽 Round ' + round + ' done, checking for more...');
                        }
                        return totalClicked;
                    };

                    // ── debug：印出表格 tr 結構，顯示所有 cell 內容，定位 BSCD 欄位 ──
                    const debugTableRows = async () => {
                        const info = await page.evaluate(() => {
                            const selector = 'table.table.table-condensed.table-hover.table-striped';
                            const table = document.querySelector(selector);
                            if (!table) return { found: false };
                            const tbody = table.querySelector('tbody');
                            const trs = tbody ? tbody.querySelectorAll('tr') : table.querySelectorAll('tr');
                            // 找出含有 BSCD 文字的 tr（不限欄位）
                            let bscdTrIdx = -1;
                            const rows = Array.from(trs).slice(0, 50).map((tr, i) => {
                                const cells = tr.querySelectorAll('td, th');
                                const cellTexts = Array.from(cells).map(c => (c.textContent || '').trim().replace(/\s+/g, ' ').substring(0, 40));
                                const hasBscd = cellTexts.some(t => t === 'BSCD' || t.includes('BSCD'));
                                if (hasBscd && bscdTrIdx === -1) bscdTrIdx = i;
                                return {
                                    class: tr.className,
                                    display: tr.style.display,
                                    cellCount: cells.length,
                                    hasBscd: hasBscd,
                                    cells: cellTexts
                                };
                            });
                            return { found: true, totalRows: trs.length, bscdTrIdx, rows };
                        });
                        if (!info.found) {
                            console.log('🔍 DEBUG: table not found');
                        } else {
                            console.log('🔍 DEBUG: table has ' + info.totalRows + ' tr(s), BSCD found at tr[' + info.bscdTrIdx + ']');
                            // 印出前 6 行，以及含 BSCD 的行
                            info.rows.forEach((r, i) => {
                                if (i < 6 || r.hasBscd) {
                                    const marker = r.hasBscd ? ' ⭐ BSCD ROW' : '';
                                    console.log('  tr[' + i + '] class="' + r.class + '" display="' + r.display + '"' + marker);
                                    console.log('    cells: ' + JSON.stringify(r.cells));
                                }
                            });
                        }
                        return info;
                    };

                    // ── 翻頁爬取：抓表格所有 tr（含隱藏行）──
                    const scrapeCurrentPage = async () => {
                        return await page.evaluate(() => {
                            const selector = 'table#memberBetdetailTable';
                            const table = document.querySelector(selector);
                            if (!table) return { found: false, error: 'Table not found' };

                            // 從 thead 取欄位標題
                            const thead = table.querySelector('thead');
                            let headers = [];
                            if (thead) {
                                const headerCells = thead.querySelectorAll('tr th, tr td');
                                headers = Array.from(headerCells).map(c => (c.textContent || '').trim());
                            }

                            const makeKey = (h, idx) => {
                                const s = (h || '').trim();
                                return s ? s.replace(/\s+/g, '_') : ('column_' + idx);
                            };
                            const keyList = [];
                            const seen = {};
                            headers.forEach((h, idx) => {
                                let k = makeKey(h, idx);
                                if (seen[k]) { seen[k]++; k = k + '_' + seen[k]; } else { seen[k] = 1; }
                                keyList.push(k);
                            });

                            // 抓所有 tbody tr，包含隱藏的（display:none 也要），全部爬出
                            const tbody = table.querySelector('tbody');
                            const trs = tbody ? tbody.querySelectorAll('tr') : table.querySelectorAll('tr');
                            const allRows = [];
                            for (const tr of trs) {
                                const cells = tr.querySelectorAll('td, th');
                                if (cells.length === 0) continue;

                                // 取每個 cell 的文字，對應到 keyList
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
                                return { found: false, error: 'No rows found in table' };
                            }

                            return { found: true, headers: headers, keyList: keyList, data: allRows };
                        });
                    };

                    // 取得目前 active 頁碼
                    const getActivePage = async () => {
                        return await page.evaluate(() => {
                            const el = document.querySelector('ul.pagination li.active a');
                            return el ? el.textContent.trim() : null;
                        });
                    };

                    // 取得總頁數（從 >> 的 onclick="return pageNow('N')" 抓最後一頁）
                    const getTotalPages = async () => {
                        return await page.evaluate(() => {
                            const links = document.querySelectorAll('ul.pagination li.next a');
                            for (const a of links) {
                                const match = (a.getAttribute('onclick') || '').match(/pageNow\('(\d+)'\)/);
                                if (match) {
                                    const text = a.textContent.replace(/\s/g, '');
                                    if (text === '>>') return parseInt(match[1], 10);
                                }
                            }
                            const pageNums = Array.from(document.querySelectorAll('ul.pagination li a[onclick]'))
                                .map(a => {
                                    const m = (a.getAttribute('onclick') || '').match(/pageNow\('(\d+)'\)/);
                                    return m ? parseInt(m[1], 10) : 0;
                                })
                                .filter(n => n > 0);
                            return pageNums.length > 0 ? Math.max(...pageNums) : 1;
                        });
                    };

                    // 跳到指定頁並等待 active 頁碼變化（最多 12 秒）
                    // 跳到指定頁：pageNow() 觸發整頁 navigation
                    // 必須用 Promise.all 同時啟動 waitForNavigation + pageNow，否則 context 被銷毀
                    const goToPage = async (pageNum) => {
                        try {
                            await Promise.all([
                                page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20000 }),
                                page.evaluate((n) => {
                                    if (typeof pageNow === 'function') {
                                        pageNow(String(n));
                                    } else {
                                        const links = document.querySelectorAll('ul.pagination li a');
                                        for (const a of links) {
                                            const match = (a.getAttribute('onclick') || '').match(/pageNow\('(\d+)'\)/);
                                            if (match && match[1] === String(n)) { a.click(); return; }
                                        }
                                    }
                                }, pageNum)
                            ]);
                            await page.waitForSelector('table#memberBetdetailTable tbody tr', { timeout: 10000 }).catch(() => {});
                            console.log('✅ Page ' + pageNum + ' loaded');
                            return true;
                        } catch (e) {
                            console.log('⚠️  goToPage(' + pageNum + ') error: ' + e.message);
                            return false;
                        }
                    };

                    // ── 主翻頁迴圈 ──
                    let allHeaders = [];
                    let allKeyList = [];
                    let allData = [];
                    let totalPages = 0;

                    const lastPage = await getTotalPages();
                    console.log('📑 Total pages detected: ' + lastPage);

                    for (let currentPage = 1; currentPage <= lastPage; currentPage++) {
                        totalPages++;
                        console.log('📄 Scraping page ' + currentPage + ' / ' + lastPage + '...');

                        await page.waitForSelector('table#memberBetdetailTable tbody tr', { timeout: 12000 }).catch(() => {});

                        await expandAllToggleRows();
                        await takeStepScreenshot(page, 'page_' + String(currentPage).padStart(3, '0') + '_expanded');
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
                        console.log('📋 Page ' + currentPage + ' rows: ' + pageResult.data.length + ' (total so far: ' + allData.length + ')');

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
                        console.log('⚠️  Table (table#memberBetdetailTable) not found');
                    } else {
                        console.log('⚠️  Table found but no data rows');
                    }

                    const workingDir = workingDirRef.value || require('path').dirname(process.argv[1]);
                    const screenshotFilename = 'dg_scraped_page.png';
                    const screenshotPath = path.join(workingDir, screenshotFilename);
                    try {
                        await page.screenshot({ path: screenshotPath, fullPage: false });
                        console.log('📸 Final screenshot saved: ' + screenshotFilename);
                        stepScreenshots.push(screenshotFilename);
                    } catch (screenshotError) {
                        console.log('⚠️  Screenshot failed: ' + screenshotError.message);
                    }

                    console.log('📸 Total step screenshots: ' + stepScreenshots.length);

                    const resultPath = path.join(workingDir, 'scraped_result.json');
                    const pageTitle = await page.title().catch(() => ''); const pageInfo = { title: pageTitle.trim(), url: finalUrl };
                    const queryParams = { date_start: dateStart || null, date_end: dateEnd || null };
                    const result = {
                        success: true,
                        url: finalUrl,
                        screenshotPath: screenshotFilename,
                        stepScreenshots: stepScreenshots,
                        date_start: dateStart || null,
                        date_end: dateEnd || null,
                        queryParams: queryParams,
                        domData: {
                            pageInfo: pageInfo,
                            queryParams: queryParams,
                            totalPages: totalPages,
                            pages: [],
                            tables: [tableData]
                        },
                        tableData: tableData,
                        timestamp: new Date().toISOString()
                    };
                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    console.log('💾 Result written to scraped_result.json');
                } catch (error) {
                    console.error('❌ Error:', error.message);
                    const workingDir = require('path').dirname(process.argv[1]);
                    const resultPath = path.join(workingDir, 'scraped_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify({
                        success: false,
                        error: error.message,
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

        $scriptPath = storage_path('app/temp/scraper_dg_dom.js');
        $dir = dirname($scriptPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        $this->info("✅ Script created: {$scriptPath}");
        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本並讀取結果
     */
    private function runPuppeteerScript(string $scriptPath): ?array
    {
        $this->info('3. Running browser automation...');
        $workingDir = dirname($scriptPath);
        $result = Process::path($workingDir)->timeout(0)->run('node ' . basename($scriptPath));

        $this->line("");
        $this->line("📋 Browser Output:");
        $this->line($result->output());

        if ($result->failed()) {
            $this->error("❌ Browser automation failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        $resultFile = $workingDir . '/scraped_result.json';
        if (file_exists($resultFile)) {
            $this->info("📄 Result file: " . realpath($resultFile));
            $content = file_get_contents($resultFile);
            $data = json_decode($content, true);
            $copyPath = storage_path('app/scraped_data/scraped_result_' . date('Y-m-d_H-i-s') . '.json');
            $copyDir = dirname($copyPath);
            if (!is_dir($copyDir)) {
                mkdir($copyDir, 0755, true);
            }
            copy($resultFile, $copyPath);
            $this->info("📋 Copy saved to: {$copyPath}");
            return $data;
        }
        $this->error("❌ No result file found");
        return null;
    }

    /**
     * 將所有步驟截圖從 temp 移到 scraped_data（含 stepScreenshots 陣列與最終截圖）
     */
    private function processScreenshot(array $result): void
    {
        $tempDir = dirname(storage_path('app/temp/scraper_dg_dom.js'));
        $timestamp = date('Y-m-d_H-i-s');
        $dstDir = storage_path('app/scraped_data');
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }

        // 處理所有步驟截圖（stepScreenshots 陣列已包含最終截圖）
        $stepScreenshots = $result['stepScreenshots'] ?? [];
        if (!empty($stepScreenshots)) {
            $this->info('📸 Moving ' . count($stepScreenshots) . ' step screenshot(s)...');
            foreach ($stepScreenshots as $filename) {
                $src = $tempDir . '/' . $filename;
                if (!file_exists($src)) {
                    $this->warn("⚠️  Screenshot not found: {$src}");
                    continue;
                }
                $dst = "{$dstDir}/dg_{$timestamp}_{$filename}";
                if (rename($src, $dst)) {
                    $this->info("  📸 {$filename} → {$dst}");
                } else {
                    $this->warn("  ⚠️  Could not move {$filename}");
                }
            }
            return;
        }

        // 向下相容：若無 stepScreenshots，只處理最終截圖
        $screenshotPath = $result['screenshotPath'] ?? null;
        if (!$screenshotPath) {
            return;
        }
        $src = $tempDir . '/' . $screenshotPath;
        if (!file_exists($src)) {
            $this->warn("⚠️  Screenshot not found: {$src}");
            return;
        }
        $dst = "{$dstDir}/dg_screenshot_{$timestamp}.png";
        if (rename($src, $dst)) {
            $this->info("📸 Screenshot saved to: {$dst}");
        } else {
            $this->warn("⚠️  Could not move screenshot to {$dst}");
        }
    }

    /**
     * 處理並儲存表格資料（與 SwinDOMDetail 一致：從 domData.tables 或 tableData 讀取，metadata + headers + data）
     */
    private function processTableData(array $result): void
    {
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $dstDir = storage_path('app/scraped_data');
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }

        // 表格資料來源：與 Swin 一致，優先 domData.tables，否則 tableData
        $tableData = null;
        $domData = $result['domData'] ?? [];
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
        $keys = $tableData['keys'] ?? $headers;
        $allData = $tableData['data'] ?? [];
        // 去除每筆資料每個值的前後空白，以及值內所有空白（含千分位空格）
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

        $totalRows = count($allData);
        $this->info('📋 Table rows: ' . $totalRows);

        // Console 預覽：data 為 key-value 陣列，依 keys 順序轉成表格列
        $headerRow = $headers;
        $dataPreview = array_slice($allData, 0, 10);
        $previewAsRows = array_map(function ($row) use ($keys) {
            if (is_array($row) && !array_is_list($row)) {
                return array_map(fn ($k) => $row[$k] ?? '', $keys);
            }
            return is_array($row) ? $row : [];
        }, $dataPreview);
        if (!empty($headerRow) || !empty($previewAsRows)) {
            $this->table($headerRow ?: $headers, $previewAsRows);
        }
        if ($totalRows > 10) {
            $this->line('... and ' . ($totalRows - 10) . ' more rows.');
        }

        $queryParams = $result['queryParams'] ?? array_filter([
            'date_start' => $result['date_start'] ?? $this->argument('date_start'),
            'date_end' => $result['date_end'] ?? $this->argument('date_end'),
        ]);
        $mergedData = [
            'metadata' => [
                'timestamp' => $timestamp,
                'url' => $result['url'] ?? '',
                'queryParams' => $queryParams,
                'totalPages' => $domData['totalPages'] ?? 1,
                'totalRows' => $totalRows,
            ],
            'headers' => $headers,
            'headerCount' => count($headers),
            'rowCount' => $totalRows,
            'data' => $allData,
        ];

        $mergedFileName = "scraped_data/dg_scraped_data_{$timestamp}.json";
        Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("📊 Table data saved: storage/app/{$mergedFileName} ({$totalRows} rows)");

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info('✅ DG scraping completed!');
    }
}