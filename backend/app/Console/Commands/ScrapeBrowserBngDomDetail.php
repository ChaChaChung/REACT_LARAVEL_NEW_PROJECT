<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserBngDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-bng-dom-detail {url} {date_start?} {date_end?} {account_number?}
     */
    protected $signature = 'agent:scrape-bng-dom-detail {url} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'BNG: Login, navigate, open date picker (optional date_start/date_end), screenshot';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        $url = $this->argument('url');
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');
        $domain = env('BNG_AGENT_DOMAIN', '');

        $this->info('=== BNG Browser Login & Navigate ===');
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

        $puppeteerCheck = Process::run('npm list puppeteer --depth=0');
        if ($puppeteerCheck->failed()) {
            $this->warn('⚠️  Puppeteer not found. Installing...');
            $install = Process::run('npm install puppeteer');
            if ($install->failed()) {
                $this->error('❌ Failed to install Puppeteer');
                return false;
            }
            $this->info('✅ Puppeteer installed successfully');
        } else {
            $this->info('✅ Puppeteer found');
        }
        return true;
    }

    /**
     * 創建 Puppeteer 腳本：先進入 domain 設定 cookie（登入），再跳轉到 url，若有 date_start/date_end 則點開 date picker 後截圖
     */
    private function createPuppeteerScript(string $url, string $domain, ?string $dateStart = null, ?string $dateEnd = null, ?string $accountNumber = null): string
    {
        $this->info('2. Creating browser automation script...');

        // 若 BNG_AGENT_DOMAIN 為空，從目標 url 推導 host，否則 cookie 不會被設定導致登入失敗
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
        $cookiesCode = $this->generateBngPuppeteerCookiesCode('page', $domainForCookies !== '' ? $domainForCookies : null);

        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');

            const targetUrl = $urlJs;
            const configDomain = $domainJs;
            const dateStart = $dateStartJs;
            const dateEnd = $dateEndJs;
            const accountNumber = $accountNumberJs;

            async function run() {
                console.log('🚀 BNG: Starting login and navigate...');
                const browser = await puppeteer.launch({
                    headless: 'new',
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        '--disable-web-security',
                    ],
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

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

                    // 登入：設定 cookies（session = BNG_AGENT_TOKEN, language = BNG_AGENT_LANG）
                    $cookiesCode

                    await new Promise(resolve => setTimeout(resolve, 500));

                    // 強制重新載入頁面，讓下次請求帶上 cookie（SPA 若只改 hash 不會向 server 重新要頁面，登入會失敗）
                    console.log('🔄 Reloading page so request is sent with cookies...');
                    await page.reload({ waitUntil: 'load', timeout: 30000 });
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 若目標 url 有 hash，用 client 端導向到對應路由（不再次 reload）
                    try {
                        const u = new URL(targetUrl);
                        if (u.hash && u.hash.length > 1) {
                            console.log('📍 Setting hash:', u.hash);
                            await page.evaluate((hash) => { window.location.hash = hash; }, u.hash);
                            await new Promise(resolve => setTimeout(resolve, 2000));
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
                            } else {
                                console.log('⚠️ Player input field not found. Skipping input.');
                            }
                        } catch (playerInputError) {
                            console.log('⚠️ Error during player input field handling:', playerInputError.message);
                        }
                    }

                    // 若有帶 date_start 或 date_end：點開 date picker → 在日曆上點選日期 → Apply
                    const openPickerSelector = 'input.form-control.input-sm.app-date-picker, input.app-date-picker';
                    const startInputSelector = 'div.range.start .datepicker input, div.range.start input[name="_date"]';
                    const endInputSelector = 'div.range.end .datepicker input, div.range.end input[name="_date"]';
                    const hasDates = (dateStart && dateStart !== 'null') || (dateEnd && dateEnd !== 'null');
                    if (hasDates) {
                        try {
                            await page.waitForSelector(openPickerSelector, { timeout: 10000 }).catch(() => null);
                            const openPickerInput = await page.$(openPickerSelector);
                            if (openPickerInput) {
                                await openPickerInput.click();
                                console.log('📅 Opened date picker');
                                await new Promise(resolve => setTimeout(resolve, 800));
                            }

                            const parseDate = (dateStr) => {
                                const [y, m, d] = dateStr.split('-').map(Number);
                                return { year: y, month: m, day: d };
                            };

                            // 依 td 的 debug 屬性點選日期（debug="2026-02-01T00:00:00Z"），排除 .off
                            const clickDateByDebug = async (dateStr) => {
                                const debugPrefix = dateStr + 'T';
                                const clicked = await page.evaluate((prefix) => {
                                    const calendars = document.querySelectorAll('div.calendar');
                                    for (const cal of calendars) {
                                        const tds = cal.querySelectorAll('tbody td:not(.off)');
                                        for (const td of tds) {
                                            const debug = td.getAttribute('debug');
                                            if (debug && debug.indexOf(prefix) === 0) {
                                                td.click();
                                                return true;
                                            }
                                        }
                                    }
                                    return false;
                                }, debugPrefix);
                                return clicked;
                            };

                            if (dateStart && dateStart !== 'null') {
                                const startEl = await page.$(startInputSelector);
                                if (startEl) {
                                    await startEl.click();
                                    await new Promise(resolve => setTimeout(resolve, 400));
                                }
                                const ok = await clickDateByDebug(dateStart);
                                if (ok) console.log('📅 Clicked start date:', dateStart);
                                else console.log('⚠️  Could not click start date (debug)');
                                await new Promise(resolve => setTimeout(resolve, 400));
                            }

                            if (dateEnd && dateEnd !== 'null') {
                                const endEl = await page.$(endInputSelector);
                                if (endEl) {
                                    await endEl.click();
                                    await new Promise(resolve => setTimeout(resolve, 400));
                                }
                                const ok = await clickDateByDebug(dateEnd);
                                if (ok) console.log('📅 Clicked end date:', dateEnd);
                                else console.log('⚠️  Could not click end date (debug)');
                                await new Promise(resolve => setTimeout(resolve, 400));
                            }

                            const applyBtn = await page.$('div.apply-btn');
                            if (applyBtn) {
                                await applyBtn.evaluate(el => el.scrollIntoView({ block: 'center' }));
                                await new Promise(resolve => setTimeout(resolve, 200));
                                await applyBtn.click();
                                console.log('📅 Clicked Apply');
                                await new Promise(resolve => setTimeout(resolve, 1200));
                            } else {
                                console.log('⚠️  Apply button (div.apply-btn) not found');
                            }

                            const submitBtn = await page.$('div.btn.btn-default.submit-btn');
                            if (submitBtn) {
                                await submitBtn.evaluate(el => el.scrollIntoView({ block: 'center' }));
                                await new Promise(resolve => setTimeout(resolve, 200));
                                await submitBtn.click();
                                console.log('📅 Clicked 搜尋 (submit)');
                                await new Promise(resolve => setTimeout(resolve, 1500));
                            } else {
                                console.log('⚠️  Submit button (div.submit-btn) not found');
                            }

                            await new Promise(resolve => setTimeout(resolve, 500));
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
                            const selector = 'table.table.table-condensed.table-hover.table-striped';
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

                    // 取得目前 active 頁碼（用於等待換頁完成）
                    const getActivePage = async () => {
                        return await page.evaluate(() => {
                            const el = document.querySelector('ul.pagination li.active a');
                            return el ? el.textContent.trim() : null;
                        });
                    };

                    // 判斷是否還有「>>」可點（li.mg-available 內含 >> 的 <a>）
                    const hasNextPage = async () => {
                        return await page.evaluate(() => {
                            const items = document.querySelectorAll('ul.pagination li.mg-available a');
                            for (const a of items) {
                                if (a.textContent.trim() === '>>') return true;
                            }
                            return false;
                        });
                    };

                    // 點擊下一頁並等待 active 頁碼改變
                    const clickNextPage = async (currentActivePage) => {
                        const clicked = await page.evaluate(() => {
                            const items = document.querySelectorAll('ul.pagination li.mg-available a');
                            for (const a of items) {
                                if (a.textContent.trim() === '>>') {
                                    a.click();
                                    return true;
                                }
                            }
                            return false;
                        });
                        if (!clicked) return false;
                        // 等待 active 頁碼變化（最多 10 秒）
                        const deadline = Date.now() + 10000;
                        while (Date.now() < deadline) {
                            await new Promise(r => setTimeout(r, 500));
                            const newPage = await getActivePage();
                            if (newPage !== null && newPage !== currentActivePage) {
                                return true;
                            }
                        }
                        console.log('⚠️  Page did not change after clicking >>');
                        return false;
                    };

                    // ── 主翻頁迴圈 ──
                    let allHeaders = [];
                    let allKeyList = [];
                    let allData = [];
                    let totalPages = 0;

                    while (true) {
                        totalPages++;
                        console.log('📄 Scraping page ' + totalPages + '...');
                        // 先展開所有 toggle 按鈕，讓 BSCD 子行可見
                        await expandAllToggleRows();
                        const pageResult = await scrapeCurrentPage();

                        if (!pageResult || !pageResult.found) {
                            console.log('⚠️  Table not found on page ' + totalPages + ': ' + (pageResult && pageResult.error || ''));
                            break;
                        }

                        if (allHeaders.length === 0) {
                            allHeaders = pageResult.headers;
                            allKeyList = pageResult.keyList;
                        }
                        allData = allData.concat(pageResult.data);
                        console.log('📋 Page ' + totalPages + ' rows: ' + pageResult.data.length + ' (total so far: ' + allData.length + ')');

                        const hasNext = await hasNextPage();
                        if (!hasNext) {
                            console.log('✅ No more pages (>> not found). Done at page ' + totalPages);
                            break;
                        }

                        const currentActivePage = await getActivePage();
                        const moved = await clickNextPage(currentActivePage);
                        if (!moved) {
                            console.log('⚠️  Could not navigate to next page. Stopping.');
                            break;
                        }
                        // 等待表格重新渲染
                        await new Promise(r => setTimeout(r, 800));
                    }

                    const tableData = allHeaders.length > 0
                        ? { found: true, headers: allHeaders, keys: allKeyList, rowCount: allData.length, data: allData }
                        : { found: false, error: 'Table not found' };

                    if (tableData.found && tableData.data.length > 0) {
                        console.log('📋 Total table rows scraped: ' + tableData.rowCount + ' (across ' + totalPages + ' pages)');
                    } else if (!tableData.found) {
                        console.log('⚠️  Table (table.table-condensed.table-hover.table-striped) not found');
                    } else {
                        console.log('⚠️  Table found but no data rows');
                    }

                    const workingDir = require('path').dirname(process.argv[1]);
                    const screenshotFilename = 'bng_scraped_page.png';
                    const screenshotPath = path.join(workingDir, screenshotFilename);
                    try {
                        await page.screenshot({ path: screenshotPath, fullPage: false });
                        console.log('📸 Screenshot saved: ' + screenshotFilename);
                    } catch (screenshotError) {
                        console.log('⚠️  Screenshot failed: ' + screenshotError.message);
                    }

                    const resultPath = path.join(workingDir, 'scraped_result.json');
                    const pageInfo = { title: (await page.title()).trim(), url: finalUrl };
                    const queryParams = { date_start: dateStart || null, date_end: dateEnd || null };
                    const result = {
                        success: true,
                        url: finalUrl,
                        screenshotPath: screenshotFilename,
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

        $scriptPath = storage_path('app/temp/scraper_bng_dom.js');
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
        $result = Process::path($workingDir)->timeout(120)->run('node ' . basename($scriptPath));

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
     * 將截圖從 temp 移到 scraped_data 並輸出路徑（與 SwinDOMDetail 一致：時間戳 Y-m-d_H-i-s）
     */
    private function processScreenshot(array $result): void
    {
        $screenshotPath = $result['screenshotPath'] ?? null;
        if (!$screenshotPath) {
            return;
        }

        $tempDir = dirname(storage_path('app/temp/scraper_bng_dom.js'));
        $src = $tempDir . '/' . $screenshotPath;
        if (!file_exists($src)) {
            $this->warn("⚠️  Screenshot not found: {$src}");
            return;
        }

        $timestamp = date('Y-m-d_H-i-s');
        $dstDir = storage_path('app/scraped_data');
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }
        $dst = "{$dstDir}/bng_screenshot_{$timestamp}.png";
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

        $mergedFileName = "scraped_data/bng_scraped_data_{$timestamp}.json";
        Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("📊 Table data saved: storage/app/{$mergedFileName} ({$totalRows} rows)");

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info('✅ BNG scraping completed!');
    }
}