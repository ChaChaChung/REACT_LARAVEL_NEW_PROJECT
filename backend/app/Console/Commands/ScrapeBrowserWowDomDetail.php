<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - WOW (使用 sessionStorage 登入)
 * 此命令會使用 sessionStorage 中的 dashboardToken 進行登入，然後截圖
 */
class ScrapeBrowserWowDomDetail extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-wow-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     */
    protected $signature = 'agent:scrape-wow-dom-detail {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from WOW DOM elements using browser automation with sessionStorage login';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');

        $this->info('=== WOW DOM Data Scraper (SessionStorage Login) ===');
        $this->info("Target URL: {$url}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 從環境變數獲取登入域名
        $domain = env('WOW_AGENT_DOMAIN', '');
        
        if (empty($domain)) {
            $this->error('❌ WOW_AGENT_DOMAIN environment variable is not set');
            $this->line('Please set WOW_AGENT_DOMAIN in your .env file');
            return 1;
        }

        // 從環境變數獲取登入 Token
        $token = env('WOW_AGENT_TOKEN', '');
        
        if (empty($token)) {
            $this->error('❌ WOW_AGENT_TOKEN environment variable is not set');
            $this->line('Please set WOW_AGENT_TOKEN in your .env file');
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($domain, $url, $token);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理截圖
        if ($result) {
            $this->processScreenshot($result);
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
     * 創建 Puppeteer 自動化腳本（使用 sessionStorage 登入）
     * @param string $domain 登入頁面網址
     * @param string $url 要爬取的目標網址
     * @param string $token 登入 Token
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $url, $token)
    {
        $this->info('2. Creating browser automation script...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $urlJs = json_encode($url);
        $tokenJs = json_encode($token);
        
        // 獲取工作目錄的絕對路徑
        $workingDir = storage_path('app/temp');
        $workingDirJs = json_encode($workingDir);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            
            // 工作目錄
            const workingDir = $workingDirJs;

            /**
             * WOW 使用 sessionStorage 登入流程
             */
            async function loginWithSessionStorageAndScreenshot() {
                // 啟動非無頭瀏覽器（headless: false，讓使用者可以看到瀏覽器）
                const browser = await puppeteer.launch({
                    headless: false, // 非無頭模式，讓使用者可以看到瀏覽器
                    args: [
                        // 安全性相關參數（用於容器環境）
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        // 性能優化參數
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--disable-gpu',
                        // 功能禁用（減少資源使用）
                        '--disable-features=TranslateUI',
                        '--disable-crash-reporter',
                        '--disable-breakpad',
                        '--disable-default-apps',
                        '--disable-extensions',
                        '--disable-plugins',
                        '--disable-web-security',
                        '--disable-features=VizDisplayCompositor',
                        '--memory-pressure-off'
                    ],
                    // 如果環境變數中指定了 Chrome 路徑，則使用該路徑
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    // 創建新的瀏覽器頁面
                    const page = await browser.newPage();

                    // 設定視窗大小為 1920x1080（模擬桌面瀏覽器）
                    await page.setViewport({ width: 1920, height: 1080 });

                    // 設定 User Agent，模擬真實的瀏覽器請求
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

                    console.log('🔐 Starting WOW sessionStorage login process...');
                    
                    // 移除 JSON 編碼的引號
                    const loginDomain = $domainJs.replace(/^"|"\$/g, '');
                    const targetUrl = $urlJs.replace(/^"|"\$/g, '');
                    const dashboardToken = $tokenJs.replace(/^"|"\$/g, '');
                    
                    // 先導航到登入頁面
                    console.log('🌐 Navigating to login domain: ' + loginDomain);
                    await page.goto(loginDomain, {
                        waitUntil: 'load',
                        timeout: 60000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 設置 sessionStorage 中的 dashboardToken
                    console.log('💾 Setting sessionStorage[dashboardToken]...');
                    await page.evaluate((token) => {
                        try {
                            // 設置 dashboardToken 到 sessionStorage
                            sessionStorage.setItem('dashboardToken', token);
                            console.log('✅ Set sessionStorage[dashboardToken]');
                            
                            // 觸發 storage 事件，讓應用知道 sessionStorage 已更新
                            window.dispatchEvent(new StorageEvent('storage', {
                                key: 'dashboardToken',
                                newValue: token,
                                oldValue: null,
                                storageArea: sessionStorage
                            }));
                            
                            // 如果頁面有監聽器，可能需要觸發自定義事件
                            window.dispatchEvent(new Event('sessionStorageUpdated'));
                            
                            return true;
                        } catch (e) {
                            console.error('❌ Error setting sessionStorage:', e.message);
                            return false;
                        }
                    }, dashboardToken);
                    
                    // 等待一下讓頁面處理 sessionStorage 更新
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 刷新頁面讓應用讀取新的 sessionStorage
                    console.log('🔄 Reloading page to apply sessionStorage...');
                    await page.reload({
                        waitUntil: 'networkidle2',
                        timeout: 60000
                    });
                    
                    // 等待頁面完全載入
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 驗證 sessionStorage 是否已設置
                    const sessionStorageSet = await page.evaluate(() => {
                        const token = sessionStorage.getItem('dashboardToken');
                        return token !== null && token !== '';
                    });
                    
                    if (sessionStorageSet) {
                        console.log('✅ sessionStorage[dashboardToken] successfully set and page reloaded');
                    } else {
                        console.log('⚠️  sessionStorage[dashboardToken] may not be set correctly');
                    }
                    
                    // 等待頁面穩定
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 導航到目標 URL
                    console.log('🌐 Navigating to target URL: ' + targetUrl);
                    await page.goto(targetUrl, {
                        waitUntil: 'networkidle2',
                        timeout: 60000
                    });
                    
                    // 等待頁面完全載入
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 截圖
                    console.log('📸 Taking screenshot...');
                    const screenshotPath = path.join(workingDir, 'wow_screenshot.png');
                    await page.screenshot({
                        path: screenshotPath,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshotPath);
                    
                    // 返回結果
                    const result = {
                        success: true,
                        url: page.url(),
                        title: await page.title(),
                        screenshot: screenshotPath
                    };
                    
                    // 保存結果
                    const resultPath = path.join(workingDir, 'scrape_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    
                    // 等待 5 秒讓使用者查看結果
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    const errorResult = {
                        success: false,
                        error: error.message,
                        stack: error.stack
                    };
                    fs.writeFileSync(path.join(workingDir, 'scrape_result.json'), JSON.stringify(errorResult, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    throw error;
                }
            }

            loginWithSessionStorageAndScreenshot().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_wow_sessionstorage.js');
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
        
        // 使用 Process 執行腳本，並實時輸出日誌
        $process = Process::path($workingDir)
            ->timeout(600)
            ->tty(false); // 不使用 TTY，以便捕獲所有輸出
        
        // 執行腳本並實時輸出
        $result = $process->run("node " . basename($scriptPath), function ($type, $output) {
            // 實時輸出腳本的控制台日誌
            echo $output;
        });

        if ($result->failed()) {
            $this->error("❌ Script execution failed");
            $errorOutput = $result->errorOutput();
            $stdOutput = $result->output();
            
            // 顯示完整的錯誤信息
            if (!empty($errorOutput)) {
                $this->line("Error output: " . $errorOutput);
            }
            if (!empty($stdOutput)) {
                $this->line("Standard output: " . $stdOutput);
            }
            
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
     * 處理和保存截圖
     * @param array $result 爬取的結果資料
     */
    private function processScreenshot($result)
    {
        $this->info('');
        $this->info('4. Processing screenshot...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('📄 Title: ' . ($result['title'] ?? 'N/A'));
            
            // 處理截圖
            if (isset($result['screenshot'])) {
                $screenshotSrc = $result['screenshot'];
                $screenshotsDir = storage_path('app/scraped_data');
                if (!is_dir($screenshotsDir)) {
                    mkdir($screenshotsDir, 0755, true);
                }
                
                $screenshotDst = $screenshotsDir . '/wow_screenshot_' . $timestamp . '.png';
                
                if (file_exists($screenshotSrc)) {
                    rename($screenshotSrc, $screenshotDst);
                    $this->info("📸 Screenshot saved to: {$screenshotDst}");
                } else {
                    $this->warn('⚠️  Screenshot file not found: ' . $screenshotSrc);
                }
            } else {
                $this->warn('⚠️  No screenshot found in result');
            }
            
            if (isset($result['error'])) {
                $this->warn('⚠️  Warning: ' . $result['error']);
            }
        } else {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('');
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }
}
