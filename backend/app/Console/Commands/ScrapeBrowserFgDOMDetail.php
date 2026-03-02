<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserFgDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-fg-dom-detail {url?} {date_start?} {date_end?} {account_number?}
     * {url} - 要爬取的目標網址（可選，未提供則使用 FG_AGENT_DOMAIN）
     * {date_start?} - 開始日期（可選，格式：YYYYMMDD 或 YYYY-MM-DD）
     * {date_end?} - 結束日期（可選）
     * {account_number?} - player account 帳號（可選，第 4 個位置參數）
     */
    protected $signature = 'agent:scrape-fg-dom-detail {url?} {date_start?} {date_end?} {account_number?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from FG DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        $url = $this->argument('url');
        $date_start = $this->argument('date_start');
        $date_end = $this->argument('date_end');
        $account_number = $this->argument('account_number');

        // 若未提供 url，使用 FG_AGENT_DOMAIN 組出完整 URL
        if (empty($url)) {
            $domain = env('FG_AGENT_DOMAIN', '');
            if (empty($domain)) {
                $this->error('❌ Please provide url or set FG_AGENT_DOMAIN in .env');
                return 1;
            }
            $url = $this->buildFgUrl($domain);
        } else {
            $url = $this->buildFgUrl($url);
        }

        $this->info('=== FG Browser DOM Scraper (Cookie Login + Screenshot) ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$date_start}");
        $this->info("Date End: {$date_end}");
        $this->info("Account: " . ($account_number ?: '(none)'));
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        if (!$this->checkNodeJs()) {
            return 1;
        }

        $scriptPath = $this->createPuppeteerScript($url, $date_start, $date_end, $account_number);
        $result = $this->runPuppeteerScript($scriptPath);

        if ($result) {
            $this->processScreenshot($result);
            return 0;
        }

        return 1;
    }

    /**
     * 組出 FG 完整 URL（確保有 https://）
     */
    private function buildFgUrl(string $urlOrDomain): string
    {
        $urlOrDomain = trim($urlOrDomain);
        if (preg_match('#^https?://#i', $urlOrDomain)) {
            return $urlOrDomain;
        }
        return 'https://' . $urlOrDomain;
    }

    private function checkNodeJs(): bool
    {
        $this->info('1. Checking Node.js installation...');

        $result = Process::run('node --version');
        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            $this->line('Please install Node.js from: https://nodejs.org/');
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
     * 創建 Puppeteer 腳本：設定 Cookie 登入，導航至目標頁，填入日期，登入完成後截圖
     */
    private function createPuppeteerScript(string $url, ?string $date_start = null, ?string $date_end = null, ?string $account_number = null): string
    {
        $this->info('2. Creating browser automation script...');

        $cookiesCode = $this->generateFgPuppeteerCookiesCode('page');
        $localStorageCode = $this->generateFgPuppeteerLocalStorageCode('page');
        $urlJs = json_encode($url);
        $dateStartJs = $date_start ? json_encode(date('Y-m-d', strtotime($date_start)) . ' 00:00:00') : 'null';
        $dateEndJs = $date_end ? json_encode(date('Y-m-d', strtotime($date_end)) . ' 23:59:59') : 'null';
        $startY = $date_start ? (int) date('Y', strtotime($date_start)) : 0;
        $startM = $date_start ? (int) date('n', strtotime($date_start)) : 0;
        $startD = $date_start ? (int) date('j', strtotime($date_start)) : 0;
        $endY = $date_end ? (int) date('Y', strtotime($date_end)) : 0;
        $endM = $date_end ? (int) date('n', strtotime($date_end)) : 0;
        $endD = $date_end ? (int) date('j', strtotime($date_end)) : 0;
        $accountNumberJs = $account_number ? json_encode($account_number) : 'null';

        $script = <<<JS
            const puppeteer = require('puppeteer-extra');
            const StealthPlugin = require('puppeteer-extra-plugin-stealth');
            puppeteer.use(StealthPlugin());
            const fs = require('fs');

            async function loginWithCookiesAndScreenshot() {
                const browser = await puppeteer.launch({
                    headless: 'new',
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
                    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

                    const targetUrl = $urlJs;

                    // 先進入 domain 建立 cookie 上下文
                    let originUrl;
                    try {
                        const u = new URL(targetUrl);
                        originUrl = u.origin + '/';
                    } catch (e) {
                        originUrl = targetUrl;
                    }

                    console.log('🌐 First navigating to domain (for cookie context):', originUrl);
                    await page.goto(originUrl, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 1000));

                    // 設定 Cookie：token, auth, bg_languageKey
                    $cookiesCode

                    // 設定 localStorage（部分 agent 前端從 localStorage 讀取）
                    $localStorageCode

                    console.log('🌐 Navigating to:', targetUrl);
                    await page.goto(targetUrl, {
                        waitUntil: 'domcontentloaded',
                        timeout: 20000
                    });
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 無條件再設一次 cookie + localStorage（FG 需存兩次才會登入成功，SPA 可能在首次載入時未正確讀取）
                    console.log('🔐 Re-applying cookies and localStorage (2nd pass for reliable login)...');
                    $cookiesCode
                    $localStorageCode
                    await new Promise(resolve => setTimeout(resolve, 500));
                    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 再次確保 localStorage 已設定後重新載入（讓 SPA 讀取 token）
                    $localStorageCode
                    await page.reload({ waitUntil: 'domcontentloaded', timeout: 20000 });
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);

                    // 登入完成後跳轉到目標 URL
                    console.log('🔗 Navigating to target URL:', targetUrl);
                    await page.goto(targetUrl, {
                        waitUntil: 'domcontentloaded',
                        timeout: 20000
                    });
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    // 解析 date_start、date_end
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

                    // 若有 date_start 或 date_end，填入日期選擇器
                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') ||
                        (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            console.log('📅 Setting up date range...');

                            await page.waitForSelector('input.el-range-input[placeholder="Start time"], input.el-range-input[placeholder="Start Time"], input.el-range-input', { timeout: 10000 }).catch(() => {});
                            await page.click('input.el-range-input[placeholder="Start time"], input.el-range-input[placeholder="Start Time"], input.el-range-input', { timeout: 5000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 1000));

                            // 用日曆點選，不填入 input（input 會自動加一個月）
                            const startParts = ($startY && $startM && $startD) ? { y: $startY, m: $startM, d: $startD } : null;
                            const endParts = ($endY && $endM && $endD) ? { y: $endY, m: $endM, d: $endD } : null;

                            const navigateAndClickDate = async (panelSide, targetY, targetM, targetD) => {
                                const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                                const panelIdx = panelSide === 'left' ? 0 : 1;
                                for (let i = 0; i < 24; i++) {
                                    const headerText = await page.evaluate((idx) => {
                                        const contents = document.querySelectorAll('.el-date-range-picker__content');
                                        const panel = contents[idx];
                                        if (!panel) return '';
                                        const divs = panel.querySelectorAll('.el-date-range-picker__header > div');
                                        const d = divs[divs.length - 1];
                                        return d ? d.textContent.trim() : '';
                                    }, panelIdx);
                                    const parts = headerText.split(/\s+/).filter(Boolean);
                                    const curY = parseInt(parts[0], 10) || 0;
                                    const curM = monthNames.indexOf(parts[1]) + 1 || 0;
                                    if (curY === targetY && curM === targetM) break;
                                    const needNext = (targetY > curY) || (targetY === curY && targetM > curM);
                                    const navOk = await page.evaluate(({ idx, goNext }) => {
                                        const contents = document.querySelectorAll('.el-date-range-picker__content');
                                        const panel = contents[idx];
                                        if (!panel) return false;
                                        const arrowClass = goNext ? 'arrow-right' : 'arrow-left';
                                        const excludeClass = goNext ? 'd-arrow-right' : 'd-arrow-left';
                                        const btn = panel.querySelector('.el-picker-panel__icon-btn.' + arrowClass + ':not(.' + excludeClass + ')');
                                        if (btn) { btn.click(); return true; }
                                        return false;
                                    }, { idx: panelIdx, goNext: needNext });
                                    if (!navOk) break;
                                    await new Promise(r => setTimeout(r, 300));
                                }
                                const clicked = await page.evaluate(({ idx, day }) => {
                                    const contents = document.querySelectorAll('.el-date-range-picker__content');
                                    const panel = contents[idx];
                                    if (!panel) return false;
                                    const cells = panel.querySelectorAll('td.available:not(.prev-month):not(.next-month)');
                                    for (const td of cells) {
                                        const span = td.querySelector('.el-date-table-cell__text');
                                        if (span && span.textContent.trim() === String(day)) {
                                            td.click();
                                            return true;
                                        }
                                    }
                                    return false;
                                }, { idx: panelIdx, day: targetD });
                                return clicked;
                            };

                            const sameMonth = startParts && endParts && startParts.y === endParts.y && startParts.m === endParts.m;
                            if (startParts && startParts.y && startParts.m && startParts.d) {
                                console.log('📅 Selecting Start Date: ' + startParts.y + '-' + startParts.m + '-' + startParts.d);
                                await navigateAndClickDate('left', startParts.y, startParts.m, startParts.d);
                                await new Promise(resolve => setTimeout(resolve, 500));
                            }

                            if (endParts && endParts.y && endParts.m && endParts.d) {
                                console.log('📅 Selecting End Date: ' + endParts.y + '-' + endParts.m + '-' + endParts.d);
                                await new Promise(resolve => setTimeout(resolve, 300));
                                if (sameMonth) {
                                    await navigateAndClickDate('left', endParts.y, endParts.m, endParts.d);
                                } else {
                                    await navigateAndClickDate('right', endParts.y, endParts.m, endParts.d);
                                }
                                await new Promise(resolve => setTimeout(resolve, 500));
                            }

                            const okButtonClicked = await page.evaluate(() => {
                                const okButtons = Array.from(document.querySelectorAll('button.el-button.el-picker-panel__link-btn.el-button--default.el-button--mini.is-plain, button.el-button'));
                                for (let btn of okButtons) {
                                    if (btn.textContent.trim() === 'OK') {
                                        btn.click();
                                        return true;
                                    }
                                }
                                return false;
                            });
                            if (okButtonClicked) console.log('✅ OK button clicked');
                            await new Promise(resolve => setTimeout(resolve, 1000));

                        } catch (e) {
                            console.log('⚠️  Date selection error: ' + e.message);
                        }
                    }

                    // 若有 account_number，填入 player account input（依 label 尋找，tabindex 會因頁面不同而變）
                    const accountNumber = $accountNumberJs && $accountNumberJs !== 'null' ? $accountNumberJs : null;
                    if (accountNumber && accountNumber !== '') {
                        const filled = await page.evaluate((val) => {
                            const fillInput = (inp) => {
                                if (!inp) return false;
                                inp.value = val;
                                inp.dispatchEvent(new Event('input', { bubbles: true }));
                                inp.dispatchEvent(new Event('change', { bubbles: true }));
                                return true;
                            };
                            // 依 label "player account" 尋找（el-form-item > label + input）
                            const formItems = document.querySelectorAll('.el-form-item');
                            for (const item of formItems) {
                                const label = item.querySelector('.el-form-item__label, label');
                                const labelText = label ? label.textContent.trim().toLowerCase() : '';
                                if (labelText.includes('player account')) {
                                    const inp = item.querySelector('input.el-input__inner');
                                    if (inp) return fillInput(inp);
                                }
                            }
                            return false;
                        }, accountNumber);
                        if (filled) console.log('✅ Filled account_number: ' + accountNumber);
                        await new Promise(resolve => setTimeout(resolve, 300));
                    }

                    // 點擊 query 按鈕（不論是否有日期或 account）
                    const searchClicked = await page.evaluate(() => {
                        const btn = document.querySelector('button.queryBtn.J_Search-bar-query');
                        if (btn) { btn.click(); return true; }
                        const btns = Array.from(document.querySelectorAll('button.el-button.el-button--primary'));
                        const fallback = btns.find(b => /query/i.test((b.textContent || '').trim()));
                        if (fallback) { fallback.click(); return true; }
                        return false;
                    });
                    if (searchClicked) console.log('✅ Query button clicked');
                    await new Promise(resolve => setTimeout(resolve, 2500));

                    // 等待 el-table 出現並爬取表格資料
                    await page.waitForSelector('table.el-table__header, table.el-table__body, table.el-table, table[class*="el-table"]', { timeout: 10000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));

                    // 滾動到分頁組件確保可見
                    await page.evaluate(() => {
                        const pagination = document.querySelector('.el-pagination');
                        if (pagination) pagination.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                    await new Promise(resolve => setTimeout(resolve, 300));

                    const extractTableData = async (pageObject) => {
                        return await pageObject.evaluate(() => {
                            let headerTable = document.querySelector('table.el-table__header');
                            let bodyTable = document.querySelector('table.el-table__body');
                            let table = document.querySelector('table.el-table');
                            if (!table && !headerTable) {
                                document.querySelectorAll('table').forEach(t => {
                                    if (t.className && t.className.includes('el-table')) {
                                        if (t.className.includes('el-table__header')) headerTable = t;
                                        else if (t.className.includes('el-table__body')) bodyTable = t;
                                        else if (!table) table = t;
                                    }
                                });
                            }
                            if (!table && !headerTable && !bodyTable) {
                                return { found: false, error: 'Table el-table not found' };
                            }
                            let headers = [];
                            const thead = (headerTable || table)?.querySelector('thead');
                            if (thead) {
                                const headerCells = thead.querySelectorAll('tr:first-child th, tr:first-child td');
                                headers = Array.from(headerCells).map(cell => {
                                    const div = cell.querySelector('div.cell');
                                    return div ? div.textContent.trim() : cell.textContent.trim();
                                });
                            }
                            let rows = [];
                            const tbody = (bodyTable || table)?.querySelector('tbody');
                            if (tbody) rows = Array.from(tbody.querySelectorAll('tr'));
                            else if (bodyTable) rows = Array.from(bodyTable.querySelectorAll('tr'));
                            const dataRows = rows.map((row, rowIndex) => {
                                const cells = row.querySelectorAll('td');
                                const rowData = {};
                                headers.forEach((h, i) => {
                                    let key = (h || 'column_' + i).replace(/[^\w\u4e00-\u9fa5]/g, '_').replace(/^_+|_+$/g, '') || 'column_' + i;
                                    let val = null;
                                    if (cells[i]) {
                                        const div = cells[i].querySelector('div.cell');
                                        val = div ? div.textContent.trim() : cells[i].textContent.trim();
                                    }
                                    rowData[key] = val;
                                });
                                if (headers.length === 0) {
                                    Array.from(cells).forEach((c, i) => {
                                        const div = c.querySelector('div.cell');
                                        rowData['column_' + i] = div ? div.textContent.trim() : c.textContent.trim();
                                    });
                                }
                                rowData._rowIndex = rowIndex;
                                return rowData;
                            }).filter(r => {
                                const v = Object.values(r)[0];
                                return v !== '小計' && v !== '總計';
                            });
                            return {
                                found: true,
                                headers: headers,
                                rowCount: dataRows.length,
                                data: dataRows
                            };
                        });
                    };

                    // 第一頁資料
                    let firstPageData = null;
                    try {
                        firstPageData = await extractTableData(page);
                    } catch (e) {
                        console.log('⚠️  Table extract error: ' + e.message);
                    }

                    // 取得分頁資訊（el-pagination: Total 2698, input max=135, el-pager li.number）
                    let totalPages = 1;
                    const paginationInfo = await page.evaluate(() => {
                        let total = 1;
                        const elPagination = document.querySelector('.el-pagination');
                        if (elPagination) {
                            const pageInput = elPagination.querySelector('input[type="number"][max]');
                            if (pageInput && pageInput.hasAttribute('max')) {
                                total = parseInt(pageInput.getAttribute('max'));
                            }
                            if (total <= 1) {
                                const text = elPagination.textContent || '';
                                const totalMatch = text.match(/total\s+(\d+)/i);
                                if (totalMatch) {
                                    const totalRecords = parseInt(totalMatch[1]);
                                    total = Math.ceil(totalRecords / 20);
                                }
                            }
                            if (total <= 1) {
                                const pagerItems = elPagination.querySelectorAll('.el-pager li.number');
                                let maxNum = 1;
                                pagerItems.forEach(li => {
                                    const n = parseInt(li.textContent.trim());
                                    if (!isNaN(n) && n > maxNum) maxNum = n;
                                });
                                if (maxNum > 1) total = maxNum;
                            }
                        }
                        return { totalPages: Math.max(1, total) };
                    });
                    totalPages = Math.min(paginationInfo.totalPages, 500);
                    if (paginationInfo.totalPages > 500) {
                        console.log('⚠️  Capping at 500 pages (detected ' + paginationInfo.totalPages + ')');
                    }
                    console.log('📄 Total pages: ' + totalPages);

                    const allPagesData = firstPageData && firstPageData.found ? [firstPageData] : [];
                    const headers = firstPageData?.headers || [];

                    // 爬取第 2 頁到最後一頁（點擊下一頁按鈕逐頁爬取）
                    for (let p = 2; p <= totalPages; p++) {
                        try {
                            const nextClicked = await page.evaluate(() => {
                                const btn = document.querySelector('.el-pagination .btn-next:not([disabled]):not(.is-disabled)');
                                if (btn && btn.getAttribute('aria-disabled') !== 'true') {
                                    btn.click();
                                    return true;
                                }
                                return false;
                            });
                            if (!nextClicked) {
                                console.log('⚠️  No next page button, stopping at page ' + (p - 1));
                                break;
                            }
                            await new Promise(resolve => setTimeout(resolve, 1200));
                            const pageData = await extractTableData(page);
                            if (pageData && pageData.found && pageData.data && pageData.data.length > 0) {
                                allPagesData.push(pageData);
                            }
                        } catch (err) {
                            console.log('⚠️  Page ' + p + ' error: ' + err.message);
                        }
                    }

                    // 合併所有頁面資料
                    let tableData = null;
                    if (allPagesData.length > 0) {
                        const allRows = [];
                        allPagesData.forEach(pd => {
                            if (pd.data) allRows.push(...pd.data);
                        });
                        tableData = {
                            found: true,
                            headers: headers,
                            rowCount: allRows.length,
                            data: allRows
                        };
                        console.log('📊 Total scraped: ' + allRows.length + ' rows from ' + allPagesData.length + ' pages');
                    } else if (firstPageData) {
                        tableData = firstPageData;
                    }

                    // 最後一張截圖（所有操作完成後）
                    const screenshotPath = 'fg_final_' + timestamp + '.png';
                    await page.screenshot({ path: screenshotPath, fullPage: false });
                    console.log('📸 Screenshot (final):', screenshotPath);

                    const result = {
                        timestamp: new Date().toISOString(),
                        url: targetUrl,
                        date_start: dateStartParsed,
                        date_end: dateEndParsed,
                        account_number: accountNumber || null,
                        screenshotPath: screenshotPath,
                        tableData: tableData,
                        totalPages: allPagesData.length,
                        success: true
                    };

                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');

                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    fs.writeFileSync('scraped_result.json', JSON.stringify({
                        error: error.message,
                        success: false,
                        timestamp: new Date().toISOString()
                    }, null, 2));
                    throw error;
                } finally {
                    await browser.close();
                    console.log('🏁 Browser closed');
                }
            }

            loginWithCookiesAndScreenshot().then(() => {
                console.log('✅ FG login and screenshot completed');
                process.exit(0);
            }).catch((err) => {
                console.error('💥 Failed:', err);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scraper_fg_dom.js');
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        $this->info("✅ Script created: {$scriptPath}");

        return $scriptPath;
    }

    private function runPuppeteerScript(string $scriptPath): ?array
    {
        $this->info('3. Running browser automation...');

        $workingDir = dirname($scriptPath);
        $result = Process::path($workingDir)->timeout(600)->run('node ' . basename($scriptPath));

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
            return json_decode(file_get_contents($resultFile), true);
        }

        $this->error('❌ No result file found');
        return null;
    }

    private function processScreenshot(?array $result): void
    {
        $this->info('4. Processing screenshots...');

        if (!$result || !($result['success'] ?? false)) {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
            return;
        }

        $timestamp = date('Y-m-d_H-i-s');
        $tempDir = dirname(storage_path('app/temp/scraper_fg_dom.js'));
        $dstDir = storage_path('app/scraped_data');
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }

        // 只保留最後一張截圖
        $screenshotPath = $result['screenshotPath'] ?? null;
        if ($screenshotPath) {
            $src = $tempDir . '/' . $screenshotPath;
            if (file_exists($src)) {
                $dst = "{$dstDir}/fg_{$timestamp}_final.png";
                rename($src, $dst);
                $this->info("📸 Screenshot saved: {$dst}");
            }
        }

        // 儲存表格資料（模仿 Splus 的 mergedData 格式）
        $mergedFileName = null;
        if (!empty($result['tableData']) && ($result['tableData']['found'] ?? false)) {
            $tableData = $result['tableData'];
            $allData = $tableData['data'] ?? [];
            $headers = $tableData['headers'] ?? [];
            $totalRows = count($allData);

            // 移除 _rowIndex 等內部欄位
            $allData = array_map(function ($row) {
                unset($row['_rowIndex']);
                return $row;
            }, $allData);

            $queryParams = array_filter([
                'date_start' => $result['date_start'] ?? null,
                'date_end' => $result['date_end'] ?? null,
                'account_number' => $result['account_number'] ?? null,
            ]);

            $mergedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                    'queryParams' => $queryParams,
                    'totalPages' => $result['totalPages'] ?? 1,
                    'totalRows' => $totalRows,
                ],
                'headers' => $headers,
                'headerCount' => count($headers),
                'rowCount' => $totalRows,
                'data' => $allData,
            ];

            $mergedFileName = "scraped_data/fg_scraped_data_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("📊 Merged data saved: storage/app/{$mergedFileName} ({$totalRows} rows)");
        }

        if (!$mergedFileName && !empty($result['tableData']) && !($result['tableData']['found'] ?? false)) {
            $this->warn('⚠️ Table not found or empty.');
        }

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info('✅ FG login and screenshot completed!');
    }
}
