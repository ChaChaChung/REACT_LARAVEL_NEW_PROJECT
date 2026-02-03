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
     * 執行方式：php artisan agent:scrape-fg-dom-detail {url?}
     * {url} - 要爬取的目標網址（可選，未提供則使用 FG_AGENT_DOMAIN）
     */
    protected $signature = 'agent:scrape-fg-dom-detail {url?}';

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
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        if (!$this->checkNodeJs()) {
            return 1;
        }

        $scriptPath = $this->createPuppeteerScript($url);
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
     * 創建 Puppeteer 腳本：設定 Cookie 登入，導航至目標頁，登入完成後截圖
     */
    private function createPuppeteerScript(string $url): string
    {
        $this->info('2. Creating browser automation script...');

        $cookiesCode = $this->generateFgPuppeteerCookiesCode('page');
        $localStorageCode = $this->generateFgPuppeteerLocalStorageCode('page');
        $urlJs = json_encode($url);

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
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    // 登入完成後截圖
                    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
                    const screenshotPath = 'fg_after_login_' + timestamp + '.png';
                    await page.screenshot({ path: screenshotPath, fullPage: false });
                    console.log('📸 Screenshot saved:', screenshotPath);

                    const result = {
                        timestamp: new Date().toISOString(),
                        url: targetUrl,
                        screenshotPath: screenshotPath,
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
        $this->info('4. Processing screenshot...');

        if (!$result || !($result['success'] ?? false)) {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
            return;
        }

        $timestamp = date('Y-m-d_H-i-s');
        $screenshotPath = $result['screenshotPath'] ?? null;

        if ($screenshotPath) {
            $src = dirname(storage_path('app/temp/scraper_fg_dom.js')) . '/' . $screenshotPath;
            if (file_exists($src)) {
                $dst = storage_path("app/scraped_data/fg_{$timestamp}_after_login.png");
                $dstDir = dirname($dst);
                if (!is_dir($dstDir)) {
                    mkdir($dstDir, 0755, true);
                }
                rename($src, $dst);
                $this->info("📸 Screenshot saved: {$dst}");
            }
        }

        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info('✅ FG login and screenshot completed!');
    }
}
