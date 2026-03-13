<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Live22 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserLive22DOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-live22-dom-detail {url}
     */
    protected $signature = 'agent:scrape-live22-dom-detail {url} {date_start?} {date_end?} {account_number?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from Live22 DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');
        $concurrency = $this->option('concurrency');

        $this->info('=== Live22 DOM Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$dateStart}");
        $this->info("Date End: {$dateEnd}");
        $this->info("Account Number: {$accountNumber}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $dateStart, $dateEnd, $concurrency);
        
        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        if ($result) {
            $this->info('End of command at: ' . date('Y-m-d H:i:s'));
            $this->info("✅ Data scraping completed!");
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

        $result = Process::run('node --version');

        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            return false;
        }

        $this->info('✅ Node.js found: ' . trim($result->output()));
        return true;
    }

    /**
     * 創建 Puppeteer 自動化腳本
     * @param string $url 要爬取的目標網址
     * @param string|null $date_start
     * @param string|null $date_end
     * @param int $concurrency
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date_start = null, $date_end = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取 Live22 登入流程程式碼片段 (localStorage)
        $authCode = $this->generateLive22PuppeteerLoginCode('page');

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            async function scrapeDOMContent() {
                const browser = await puppeteer.launch({
                    headless: 'new',
                    args: ['--no-sandbox', '--disable-setuid-sandbox']
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });

                    // 1. 設置 localStorage 認證
                    $authCode

                    // 2. 導航到目標網址
                    console.log('🌐 Navigating to target URL: $url');
                    await page.goto('$url', {
                        waitUntil: 'networkidle2',
                        timeout: 60000
                    });

                    // 等待頁面載入內容
                    await new Promise(resolve => setTimeout(resolve, 5000));

                    // 截圖存證
                    const timestamp = new Date().getTime();
                    const screenshotPath = `storage/app/public/live22_screenshot_\${timestamp}.png`;
                    await page.screenshot({ path: screenshotPath, fullPage: true });
                    console.log(`📸 Screenshot saved: \${screenshotPath}`);

                    // 這裡可以根據實際頁面結構提取資料
                    const content = await page.content();
                    fs.writeFileSync('scraped_result.json', JSON.stringify({
                        success: true,
                        timestamp: new Date().toISOString(),
                        url: '$url',
                        screenshot: screenshotPath
                    }, null, 2));

                } catch (error) {
                    console.error('❌ Error during scraping:', error.message);
                    fs.writeFileSync('scraped_result.json', JSON.stringify({
                        success: false,
                        error: error.message
                    }, null, 2));
                } finally {
                    await browser.close();
                }
            }

            scrapeDOMContent().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Fatal Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/live22_scrape.js');
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        
        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath
     * @return bool
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation...');
        
        $workingDir = dirname($scriptPath);
        $result = Process::path($workingDir)->timeout(120)->run("node " . basename($scriptPath));

        if ($result->failed()) {
            $this->error("❌ Scraping failed");
            $this->line("Error: " . $result->errorOutput());
            return false;
        }

        $this->line($result->output());
        return true;
    }
}
