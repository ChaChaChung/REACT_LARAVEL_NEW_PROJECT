<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - ATGSLOT
 */
class ScrapeBrowserAtgslotDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-atgslot-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {player_account?} - 玩家帳號（可選參數）
     * {--concurrency=8} - 併發數量（可選，預設為 8）
     */
    protected $signature = 'agent:scrape-atgslot-dom-detail {url} {date_start?} {date_end?} {player_account?} {--concurrency=8}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from ATGSLOT DOM elements using browser automation with two-factor authentication';

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

        $this->info('=== ATGSLOT DOM Data Scraper ===');
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

        // 獲取 ATGSLOT 二階段登入流程程式碼片段（主頁面用）
        $loginCodeForPage = $this->generateAtgslotPuppeteerLoginCode('page');
        // 獲取認證 cookies 程式碼片段（併發頁面用，登入後可以重用 cookies）
        $cookiesCodeForNewPage = $this->generateAtgslotPuppeteerCookiesCode('newPage');

        // 將 date 轉換為 JavaScript 可用的格式
        $dateStartJs = $date_start ? json_encode(date('Y-m-d', strtotime($date_start))) : 'null';
        $dateEndJs = $date_end ? json_encode(date('Y-m-d', strtotime($date_end))) : 'null';
        $playerAccountJs = $player_account ? json_encode($player_account) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 併發控制器：限制同時執行的 Promise 數量
             */
            async function promiseAllWithLimit(items, limit, fn) {
                const results = [];
                const executing = [];
                let completedCount = 0;
                const totalItems = items.length;
                
                for (const [index, item] of items.entries()) {
                    const promise = Promise.resolve().then(() => fn(item, index))
                        .then((result) => {
                            completedCount++;
                            return result;
                        });
                    
                    results.push(promise);
                    
                    if (limit <= items.length) {
                        const executing_promise = promise.then(() => 
                            executing.splice(executing.indexOf(executing_promise), 1)
                        );
                        
                        executing.push(executing_promise);
                        
                        if (executing.length >= limit) {
                            await Promise.race(executing);
                        }
                    }
                }
                
                console.log('⏳ Waiting for all pages to complete...');
                return Promise.all(results);
            }

            /**
             * 從 DOM 提取資料的函數
             */
            async function scrapeDOMContent() {
                const browser = await puppeteer.launch({
                    headless: false,  // 改為 false 以便調試，如果不需要看到瀏覽器可以改回 'new'
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
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

                    // 暫時不攔截資源，確保頁面能正常載入和渲染
                    // 如果需要提升速度，可以在登入完成後再啟用資源攔截
                    // await page.setRequestInterception(true);
                    // page.on('request', (req) => {
                    //     const resourceType = req.resourceType();
                    //     const url = req.url();
                    //     if (['image', 'font', 'media', 'websocket', 'manifest', 'texttrack'].includes(resourceType)) {
                    //         req.abort();
                    //     } else {
                    //         req.continue();
                    //     }
                    // });

                    // 執行二階段登入流程
                    {$loginCodeForPage}

                    // 等待登入完成
                    console.log('⏳ Waiting for login to complete...');
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    // 獲取登入後的 cookies 和認證資訊 - 使用安全的方式
                    let authData = { cookies: '', localStorage: {} };
                    try {
                        // 確保頁面穩定
                        await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        authData = await page.evaluate(() => {
                            const cookies = document.cookie;
                            const localStorage = {};
                            for (let i = 0; i < window.localStorage.length; i++) {
                                const key = window.localStorage.key(i);
                                localStorage[key] = window.localStorage.getItem(key);
                            }
                            return { cookies, localStorage };
                        });
                    } catch (e) {
                        if (e.message.includes('detached') || e.message.includes('Frame')) {
                            console.log('⚠️  Frame detached when getting auth data, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                            try {
                                authData = await page.evaluate(() => {
                                    const cookies = document.cookie;
                                    const localStorage = {};
                                    for (let i = 0; i < window.localStorage.length; i++) {
                                        const key = window.localStorage.key(i);
                                        localStorage[key] = window.localStorage.getItem(key);
                                    }
                                    return { cookies, localStorage };
                                });
                            } catch (e2) {
                                console.log('⚠️  Cannot get auth data: ' + e2.message);
                            }
                        } else {
                            console.log('⚠️  Error getting auth data: ' + e.message);
                        }
                    }

                    const cookies = await page.cookies();
                    console.log('✅ Login completed, obtained ' + cookies.length + ' cookies');

                    // 登入成功後，導航到目標 URL
                    console.log('🌐 Navigating to target URL:', '$url');
                    try {
                        await page.goto('$url', {
                            waitUntil: 'load',
                            timeout: 60000
                        });
                        console.log('✅ Successfully navigated to target URL');
                        
                        // 等待頁面完全載入
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        
                        // 確保頁面有內容
                        const pageHasContent = await page.evaluate(() => {
                            return document.body && document.body.innerHTML.trim().length > 0;
                        }).catch(() => false);
                        
                        if (pageHasContent) {
                            console.log('✅ Target page loaded successfully');
                        } else {
                            console.log('⚠️  Target page body is empty, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        }
                    } catch (e) {
                        console.log('⚠️  Error navigating to target URL: ' + e.message);
                        // 即使導航失敗，也繼續執行
                    }
                    
                    // 截圖目標頁面
                    try {
                        await page.screenshot({ path: '09_target_page_loaded.png', fullPage: true });
                        console.log('📸 Screenshot 9: Target page loaded saved');
                    } catch (e) {
                        console.log('⚠️  Error taking screenshot: ' + e.message);
                    }

                    // 這裡可以根據實際網頁結構提取表格資料
                    // 暫時返回基本結構
                    const result = {
                        success: true,
                        url: '$url',
                        cookies: cookies,
                        authData: authData,
                        message: 'Login completed, ready for data extraction'
                    };

                    // 保存結果
                    fs.writeFileSync('scrape_result.json', JSON.stringify(result, null, 2));

                    await browser.close();
                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    const errorResult = {
                        success: false,
                        error: error.message
                    };
                    fs.writeFileSync('scrape_result.json', JSON.stringify(errorResult, null, 2));
                    await browser.close();
                    throw error;
                }
            }

            scrapeDOMContent().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_atgslot.js');
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        
        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath 腳本文件路徑
     * @return array|null 返回爬取的結果，失敗時返回 null
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation script...');
        
        $workingDir = dirname($scriptPath);
        // 增加超时时间到 5 分钟（300秒），因为可能需要等待手动输入验证码
        $result = Process::path($workingDir)->timeout(300)->run("node " . basename($scriptPath));

        if ($result->failed()) {
            $this->error("❌ Script execution failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        $resultFile = $workingDir . '/scrape_result.json';
        if (file_exists($resultFile)) {
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }

        return null;
    }

    /**
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        // 處理截圖文件
        $this->processScreenshots($workingDir, $timestamp);

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            
            // 保存 cookies 和認證資訊
            $dataFileName = "scraped_data/atgslot_data_{$timestamp}.json";
            $fileData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                ],
                'cookies' => $result['cookies'] ?? [],
                'authData' => $result['authData'] ?? [],
            ];
            
            Storage::put($dataFileName, json_encode($fileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("✅ Data saved: {$dataFileName}");
        } else {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }

    /**
     * 處理截圖文件，將它們從臨時目錄移動到永久儲存目錄
     * @param string $workingDir 工作目錄（臨時目錄）
     * @param string $timestamp 時間戳
     */
    private function processScreenshots($workingDir, $timestamp)
    {
        $this->info('📸 Processing screenshots...');
        
        // 截圖文件名模式
        $screenshotPatterns = [
            '01_initial_login_page.png',
            '02_after_account_filled.png',
            '03_after_password_filled.png',
            '04_after_login_button_clicked.png',
            '05_verification_code_input_found.png',
            '05_verification_code_input_not_found.png',
            '05_waiting_for_verification_*.png',
            '06_after_verification_code_filled_auto.png',
            '06_after_verification_code_filled_manual.png',
            '06_verification_code_timeout.png',
            '06_waiting_for_manual_code_*.png',
            '07_after_confirm_button_clicked.png',
            '08_login_completed_final_page.png',
        ];
        
        $screenshotsDir = storage_path("app/scraped_data/atgslot_screenshots_{$timestamp}");
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }
        
        $screenshotCount = 0;
        
        // 處理所有截圖文件
        foreach ($screenshotPatterns as $pattern) {
            // 處理通配符模式
            if (strpos($pattern, '*') !== false) {
                $globPattern = str_replace('*', '*', $pattern);
                $files = glob($workingDir . '/' . $globPattern);
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        $filename = basename($file);
                        $destPath = $screenshotsDir . '/' . $filename;
                        if (rename($file, $destPath)) {
                            $screenshotCount++;
                            $this->line("   ✅ {$filename}");
                        }
                    }
                }
            } else {
                // 處理固定文件名
                $srcPath = $workingDir . '/' . $pattern;
                if (file_exists($srcPath)) {
                    $destPath = $screenshotsDir . '/' . $pattern;
                    if (rename($srcPath, $destPath)) {
                        $screenshotCount++;
                        $this->line("   ✅ {$pattern}");
                    }
                }
            }
        }
        
        // 也嘗試移動所有以數字開頭的 PNG 文件（備用方案）
        $allScreenshots = glob($workingDir . '/[0-9]*.png');
        foreach ($allScreenshots as $file) {
            $filename = basename($file);
            $destPath = $screenshotsDir . '/' . $filename;
            if (!file_exists($destPath) && rename($file, $destPath)) {
                $screenshotCount++;
                $this->line("   ✅ {$filename}");
            }
        }
        
        if ($screenshotCount > 0) {
            $this->info("✅ {$screenshotCount} screenshot(s) saved to: {$screenshotsDir}");
        } else {
            $this->warn('⚠️  No screenshots found');
        }
    }
}

