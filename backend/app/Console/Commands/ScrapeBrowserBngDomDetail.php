<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * BNG 瀏覽器登入並跳轉命令
 * 將 BNG_AGENT_TOKEN 存到 cookies 的 session、BNG_AGENT_LANG 存到 cookies 的 language，完成登入後跳轉到指定 url
 */
class ScrapeBrowserBngDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-bng-dom-detail {url} {date_start?} {date_end?}
     */
    protected $signature = 'agent:scrape-bng-dom-detail {url} {date_start?} {date_end?}';

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
        $domain = env('BNG_AGENT_DOMAIN', '');

        $this->info('=== BNG Browser Login & Navigate ===');
        $this->info("Login Domain: " . ($domain ?: '(derive from url)'));
        $this->info("Target URL: {$url}");
        $this->info("Date Start: " . ($dateStart ?: '—'));
        $this->info("Date End: " . ($dateEnd ?: '—'));
        $this->info('Start time: ' . date('Y-m-d H:i:s'));

        if (empty($url)) {
            $this->error('❌ url is required');
            return 1;
        }

        if (!$this->checkNodeJs()) {
            return 1;
        }

        $scriptPath = $this->createPuppeteerScript($url, $domain, $dateStart, $dateEnd);
        $result = $this->runPuppeteerScript($scriptPath);

        if ($result && !empty($result['success'])) {
            $this->info('✅ Login and navigation completed.');
            $this->processScreenshot($result);
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
    private function createPuppeteerScript(string $url, string $domain, ?string $dateStart = null, ?string $dateEnd = null): string
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
        $cookiesCode = $this->generateBngPuppeteerCookiesCode('page', $domainForCookies !== '' ? $domainForCookies : null);

        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');

            const targetUrl = $urlJs;
            const configDomain = $domainJs;
            const dateStart = $dateStartJs;
            const dateEnd = $dateEndJs;

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
                    fs.writeFileSync(resultPath, JSON.stringify({
                        success: true,
                        url: finalUrl,
                        screenshotPath: screenshotFilename,
                        timestamp: new Date().toISOString()
                    }, null, 2));
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
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }
        $this->error("❌ No result file found");
        return null;
    }

    /**
     * 將截圖從 temp 移到 scraped_data 並輸出路徑
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

        $timestamp = date('Ymd_His');
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
}
