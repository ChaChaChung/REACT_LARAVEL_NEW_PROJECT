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
     * 執行方式：php artisan agent:scrape-bng-dom-detail {url}
     */
    protected $signature = 'agent:scrape-bng-dom-detail {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'BNG: Set session/language cookies (login) then navigate to url';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        $url = $this->argument('url');
        $domain = env('BNG_AGENT_DOMAIN', '');

        $this->info('=== BNG Browser Login & Navigate ===');
        $this->info("Login Domain: " . ($domain ?: '(derive from url)'));
        $this->info("Target URL: {$url}");
        $this->info('Start time: ' . date('Y-m-d H:i:s'));

        if (empty($url)) {
            $this->error('❌ url is required');
            return 1;
        }

        if (!$this->checkNodeJs()) {
            return 1;
        }

        $scriptPath = $this->createPuppeteerScript($url, $domain);
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
     * 創建 Puppeteer 腳本：先進入 domain 設定 cookie（登入），再跳轉到 url
     */
    private function createPuppeteerScript(string $url, string $domain): string
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
        $cookiesCode = $this->generateBngPuppeteerCookiesCode('page', $domainForCookies !== '' ? $domainForCookies : null);

        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');

            const targetUrl = $urlJs;
            const configDomain = $domainJs;

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
