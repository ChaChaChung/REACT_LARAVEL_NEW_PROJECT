<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * FG 瀏覽器 DOM 內容爬蟲命令
 * 使用 Cookie 登入（token, auth, bg_languageKey），登入完成後截圖
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
    protected $description = 'FG: Login with cookies (token, auth, bg_languageKey) and take screenshot after login';

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
        $dateStartJs = $date_start ? json_encode(date('Y-m-d', strtotime($date_start))) : 'null';
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

                    // 若仍為登入頁，再設一次 cookie + localStorage 後重新導向並重新載入
                    const currentUrl = page.url();
                    if (currentUrl.includes('/login') || currentUrl.includes('login') || /login|登入/i.test(await page.evaluate(() => document.body?.innerText || '').catch(() => ''))) {
                        console.log('⚠️  Still on login page, re-applying cookies and localStorage...');
                        $cookiesCode
                        $localStorageCode
                        await new Promise(resolve => setTimeout(resolve, 500));
                        await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 20000 });
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    }

                    // 再次確保 localStorage 已設定後重新載入（讓 SPA 讀取 token）
                    $localStorageCode
                    await page.reload({ waitUntil: 'domcontentloaded', timeout: 20000 });
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 登入後截圖
                    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
                    const screenshotAfterLogin = 'fg_after_login_' + timestamp + '.png';
                    await page.screenshot({ path: screenshotAfterLogin, fullPage: false });
                    console.log('📸 Screenshot (after login):', screenshotAfterLogin);

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

                    let dateStepScreenshots = [];
                    // 若有 date_start 或 date_end，填入日期選擇器（每步截圖）
                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') ||
                        (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            console.log('📅 Setting up date range...');

                            // Step 1: 點擊前截圖
                            const step1 = 'fg_date_step1_before_click_' + timestamp + '.png';
                            await page.screenshot({ path: step1, fullPage: false });
                            dateStepScreenshots.push(step1);
                            console.log('📸 Step 1: Before click date input');

                            await page.waitForSelector('input.el-range-input[placeholder="Start time"], input.el-range-input[placeholder="Start Time"], input.el-range-input', { timeout: 10000 }).catch(() => {});
                            await page.click('input.el-range-input[placeholder="Start time"], input.el-range-input[placeholder="Start Time"], input.el-range-input', { timeout: 5000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 1000));

                            // Step 2: panel 打開後截圖
                            const step2 = 'fg_date_step2_panel_opened_' + timestamp + '.png';
                            await page.screenshot({ path: step2, fullPage: false });
                            dateStepScreenshots.push(step2);
                            console.log('📸 Step 2: Panel opened');

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

                            // Step 3: 選 Start Date 後截圖
                            const step3 = 'fg_date_step3_after_start_date_' + timestamp + '.png';
                            await page.screenshot({ path: step3, fullPage: false });
                            dateStepScreenshots.push(step3);
                            console.log('📸 Step 3: After selecting Start Date');

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

                            // Step 4: 填入 End Date 後截圖
                            const step4 = 'fg_date_step4_after_end_date_' + timestamp + '.png';
                            await page.screenshot({ path: step4, fullPage: false });
                            dateStepScreenshots.push(step4);
                            console.log('📸 Step 4: After filling End Date');

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

                            // Step 5: 點擊 OK 後截圖
                            const step5 = 'fg_date_step5_after_ok_' + timestamp + '.png';
                            await page.screenshot({ path: step5, fullPage: false });
                            dateStepScreenshots.push(step5);
                            console.log('📸 Step 5: After clicking OK');

                        } catch (e) {
                            console.log('⚠️  Date selection error: ' + e.message);
                        }
                    }

                    // 若有 account_number，填入 player account input（不論是否有日期）
                    // 目標 input: <input class="el-input__inner" type="text" autocomplete="off" tabindex="4" id="el-id-xxx">
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
                            // 1. 優先：tabindex="4"（player account 欄位）
                            const byTabindex = document.querySelector('input.el-input__inner[tabindex="4"]');
                            if (byTabindex) return fillInput(byTabindex);
                            // 2. 依 label / placeholder 尋找
                            const inputs = document.querySelectorAll('input.el-input__inner');
                            for (const inp of inputs) {
                                const label = inp.closest('.el-form-item')?.querySelector('label');
                                const labelText = label ? label.textContent.trim().toLowerCase() : '';
                                if (labelText.includes('player account') || inp.placeholder?.toLowerCase().includes('account')) {
                                    return fillInput(inp);
                                }
                            }
                            // 3. 依 type="text" + autocomplete="off" + tabindex="4" 組合
                            const byAttrs = document.querySelector('input.el-input__inner[type="text"][autocomplete="off"][tabindex="4"]');
                            if (byAttrs) return fillInput(byAttrs);
                            return false;
                        }, accountNumber);
                        if (filled) console.log('✅ Filled account_number: ' + accountNumber);
                        await new Promise(resolve => setTimeout(resolve, 300));
                    }

                    // 點擊 query 按鈕（不論是否有日期或 account）
                    const searchClicked = await page.evaluate(() => {
                        const btn = document.querySelector('button.queryBtn.J_Search-bar-query') ||
                            document.querySelector('button.J_Search-bar-query') ||
                            document.querySelector('button.queryBtn');
                        if (btn) { btn.click(); return true; }
                        const btns = Array.from(document.querySelectorAll('button.el-button.el-button--primary'));
                        const fallback = btns.find(b => /query|search|搜尋/i.test((b.textContent || '').trim()));
                        if (fallback) { fallback.click(); return true; }
                        return false;
                    });
                    if (searchClicked) console.log('✅ Query button clicked');
                    await new Promise(resolve => setTimeout(resolve, 2500));

                    if (dateStepScreenshots.length > 0) {
                        const step6 = 'fg_date_step6_after_search_' + timestamp + '.png';
                        await page.screenshot({ path: step6, fullPage: false });
                        dateStepScreenshots.push(step6);
                        console.log('📸 Step 6: After clicking Query');
                    }

                    // 等待 el-table 出現並爬取表格資料
                    await page.waitForSelector('table.el-table__header, table.el-table__body, table.el-table, table[class*="el-table"]', { timeout: 10000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 500));

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

                    let tableData = null;
                    try {
                        tableData = await extractTableData(page);
                        if (tableData && tableData.found) {
                            console.log('📊 Table scraped: ' + tableData.rowCount + ' rows, ' + (tableData.headers?.length || 0) + ' columns');
                        } else {
                            console.log('⚠️  Table not found or empty');
                        }
                    } catch (e) {
                        console.log('⚠️  Table extract error: ' + e.message);
                    }

                    // 轉址後截圖
                    const screenshotAfterRedirect = 'fg_after_redirect_' + timestamp + '.png';
                    await page.screenshot({ path: screenshotAfterRedirect, fullPage: false });
                    console.log('📸 Screenshot (after redirect):', screenshotAfterRedirect);

                    const result = {
                        timestamp: new Date().toISOString(),
                        url: targetUrl,
                        date_start: dateStartParsed,
                        date_end: dateEndParsed,
                        account_number: accountNumber || null,
                        screenshotPath: screenshotAfterRedirect,
                        screenshotAfterLogin: screenshotAfterLogin,
                        screenshotAfterRedirect: screenshotAfterRedirect,
                        dateStepScreenshots: dateStepScreenshots,
                        tableData: tableData,
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
        $result = Process::path($workingDir)->timeout(120)->run('node ' . basename($scriptPath));

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

        $screenshots = [
            $result['screenshotAfterLogin'] ?? null,
            $result['screenshotAfterRedirect'] ?? null,
        ];
        // 相容舊版只回傳 screenshotPath
        if (empty(array_filter($screenshots)) && !empty($result['screenshotPath'])) {
            $screenshots = [$result['screenshotPath']];
        }

        foreach (array_filter($screenshots) as $screenshotPath) {
            $src = $tempDir . '/' . $screenshotPath;
            if (file_exists($src)) {
                $name = str_contains($screenshotPath, 'redirect') ? 'after_redirect' : 'after_login';
                $dst = "{$dstDir}/fg_{$timestamp}_{$name}.png";
                rename($src, $dst);
                $this->info("📸 Screenshot saved: {$dst}");
            }
        }

        // 日期選擇步驟截圖 (step1~6)
        foreach ($result['dateStepScreenshots'] ?? [] as $screenshotPath) {
            $src = $tempDir . '/' . $screenshotPath;
            if (file_exists($src)) {
                $base = basename($screenshotPath, '.png');
                $stepName = preg_replace('/_\d{4}-\d{2}-\d{2}T[\d-]+$/', '', $base);
                $dst = "{$dstDir}/fg_{$timestamp}_date_{$stepName}.png";
                rename($src, $dst);
                $this->info("📸 Date step: {$dst}");
            }
        }

        // 儲存表格資料
        if (!empty($result['tableData']) && ($result['tableData']['found'] ?? false)) {
            $tablePath = "{$dstDir}/fg_{$timestamp}_table.json";
            file_put_contents($tablePath, json_encode($result['tableData'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("📊 Table data saved: {$tablePath} (" . ($result['tableData']['rowCount'] ?? 0) . " rows)");
        }

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info('✅ FG login and screenshot completed!');
    }
}
