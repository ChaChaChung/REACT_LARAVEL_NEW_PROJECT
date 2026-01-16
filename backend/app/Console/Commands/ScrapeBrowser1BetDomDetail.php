<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * 1BET 瀏覽器截圖命令
 */
class ScrapeBrowser1BetDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-1bet-dom-detail {url}
     */
    protected $signature = 'agent:scrape-1bet-dom-detail {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Navigate to 1BET domain from .env and take a screenshot';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 從 .env 獲取 1BET_AGENT_DOMAIN（登錄頁面 URL）
        $domain = env('1BET_AGENT_DOMAIN', '');
        
        if (empty($domain)) {
            $this->error('❌ 1BET_AGENT_DOMAIN is not set in .env file');
            return 1;
        }
        
        // 優先使用命令參數中的 url 作為登錄後要跳轉的目標 URL
        // 如果沒有提供參數，則使用 .env 中的 1BET_AGENT_REDIRECT_URL
        $url = $this->argument('url');
        $redirectUrl = !empty($url) ? $url : env('1BET_AGENT_REDIRECT_URL', '');
        
        // 從 .env 獲取 1BET_AGENT_ACCOUNT
        $account = env('1BET_AGENT_ACCOUNT', '');
        
        // 從 .env 獲取 1BET_AGENT_PASSWORD
        $password = env('1BET_AGENT_PASSWORD', '');
        
        $this->info('=== 1BET Browser Screenshot ===');
        $this->info("Login Domain: {$domain}");
        if (!empty($redirectUrl)) {
            $this->info("Target URL (after login): {$redirectUrl}");
        }
        if (!empty($account)) {
            $this->info("Account: {$account}");
        }
        if (!empty($password)) {
            $this->info("Password: " . str_repeat('*', strlen($password)));
        }
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($domain, $account, $password, $redirectUrl);

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
     * 創建 Puppeteer 自動化腳本
     * @param string $domain 要訪問的域名
     * @param string $account 帳號
     * @param string $password 密碼
     * @param string $redirectUrl 登錄後要跳轉的 URL
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $account = '', $password = '', $redirectUrl = '')
    {
        $this->info('2. Creating browser automation script...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);
        $redirectUrlJs = json_encode($redirectUrl);
        
        // 獲取工作目錄的絕對路徑
        $workingDir = storage_path('app/temp');
        $workingDirJs = json_encode($workingDir);
        
        // 使用 trait 方法生成登入流程代碼
        $loginCode = $this->generate1BetPuppeteerLoginCode('page', $workingDirJs, $redirectUrlJs);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            
            // 工作目錄
            const workingDir = $workingDirJs;

            /**
             * 導航到域名並截圖
             */
            async function navigateAndScreenshot() {
                // 啟動無頭瀏覽器（在背景執行）
                const browser = await puppeteer.launch({
                    headless: 'new', // 無頭模式，在背景執行
                    args: [
                        // 安全性相關參數（用於容器環境）
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        // 性能優化參數
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--no-zygote',
                        '--single-process',
                        '--disable-gpu',
                        '--disable-software-rasterizer',
                        // 背景處理優化
                        '--disable-background-timer-throttling',
                        '--disable-backgrounding-occluded-windows',
                        '--disable-renderer-backgrounding',
                        // 功能禁用（減少資源使用）
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
                        // 額外的性能優化
                        '--disable-javascript-harmony-shipping',
                        '--disable-sync',
                        // 重定向相關參數
                        '--disable-features=IsolateOrigins,site-per-process',
                        '--disable-site-isolation-trials'
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
                    
                    // 隱藏自動化特徵（反檢測）
                    await page.evaluateOnNewDocument(() => {
                        // 隱藏 webdriver 屬性
                        Object.defineProperty(navigator, 'webdriver', {
                            get: () => undefined
                        });
                        
                        // 偽造 plugins
                        Object.defineProperty(navigator, 'plugins', {
                            get: () => [1, 2, 3, 4, 5]
                        });
                        
                        // 偽造 languages
                        Object.defineProperty(navigator, 'languages', {
                            get: () => ['en-US', 'en', 'zh-CN', 'zh']
                        });
                        
                        // 偽造 permissions
                        const originalQuery = window.navigator.permissions.query;
                        window.navigator.permissions.query = (parameters) => (
                            parameters.name === 'notifications' ?
                                Promise.resolve({ state: Notification.permission }) :
                                originalQuery(parameters)
                        );
                        
                        // 偽造 chrome 對象
                        window.chrome = {
                            runtime: {}
                        };
                    });

                    // 移除 JSON 編碼的引號
                    const targetUrl = $domainJs.replace(/^"|"\$/g, '');
                    
                    console.log('🌐 Navigating to target URL: ' + targetUrl);
                    
                    // 導航到目標 URL，使用多種策略處理重定向
                    let navigationSuccess = false;
                    
                    // 策略 1: 嘗試正常導航
                    try {
                        await page.goto(targetUrl, {
                            waitUntil: 'domcontentloaded',
                            timeout: 30000
                        });
                        navigationSuccess = true;
                        console.log('✅ Navigation completed (domcontentloaded)');
                    } catch (e) {
                        console.log('⚠️  Navigation failed: ' + e.message);
                        
                        if (e.message.includes('ERR_TOO_MANY_REDIRECTS')) {
                            console.log('   Redirect loop detected, trying alternative method...');
                            
                            // 策略 2: 使用 evaluate 直接設置 URL
                            try {
                                await page.evaluate((url) => {
                                    window.location.href = url;
                                }, targetUrl);
                                
                                // 等待頁面載入
                                await page.waitForNavigation({
                                    waitUntil: 'domcontentloaded',
                                    timeout: 30000
                                }).catch(() => {
                                    console.log('   Navigation wait timeout, but continuing...');
                                });
                                
                                navigationSuccess = true;
                                console.log('✅ Navigation completed (alternative method)');
                            } catch (e2) {
                                console.log('⚠️  Alternative method failed: ' + e2.message);
                                // 即使失敗也繼續，等待頁面載入
                                await new Promise(resolve => setTimeout(resolve, 5000));
                            }
                        } else {
                            // 其他錯誤，等待一下讓頁面有機會載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        }
                    }
                    
                    // 等待頁面完全載入
                    console.log('⏳ Waiting for page to fully render...');
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 檢查頁面是否真正載入
                    let pageLoaded = false;
                    let currentUrl = '';
                    let retryCount = 0;
                    const maxRetries = 5;
                    
                    while (!pageLoaded && retryCount < maxRetries) {
                        try {
                            currentUrl = page.url();
                            console.log('📍 Current URL (attempt ' + (retryCount + 1) + '): ' + currentUrl);
                            
                            // 檢查頁面是否有內容
                            const pageInfo = await page.evaluate(() => {
                                return {
                                    url: window.location.href,
                                    title: document.title,
                                    hasBody: !!document.body,
                                    bodyTextLength: document.body ? document.body.innerText.length : 0,
                                    inputCount: document.querySelectorAll('input').length,
                                    hasAccountInput: !!document.querySelector('input.el-input__inner[placeholder="请输入账号"]'),
                                    hasPasswordInput: !!document.querySelector('input.el-input__inner[placeholder="请输入密码"]')
                                };
                            });
                            
                            // 如果 URL 不是 about:blank 且有內容，認為頁面已載入
                            if (currentUrl !== 'about:blank' && currentUrl !== '' && pageInfo.hasBody && pageInfo.bodyTextLength > 0) {
                                pageLoaded = true;
                                console.log('✅ Page loaded successfully!');
                                console.log('   Title: ' + pageInfo.title);
                                console.log('   Body text length: ' + pageInfo.bodyTextLength);
                                console.log('   Input count: ' + pageInfo.inputCount);
                                console.log('   Has account input: ' + pageInfo.hasAccountInput);
                                console.log('   Has password input: ' + pageInfo.hasPasswordInput);
                                break;
                            } else {
                                console.log('⚠️  Page not fully loaded yet, waiting...');
                                console.log('   URL: ' + pageInfo.url);
                                console.log('   Has body: ' + pageInfo.hasBody);
                                console.log('   Body text length: ' + pageInfo.bodyTextLength);
                                
                                // 如果還是空白頁面，嘗試重新導航
                                if (currentUrl === 'about:blank' || currentUrl === '') {
                                    console.log('   Attempting to navigate again...');
                                    try {
                                        await page.goto(targetUrl, {
                                            waitUntil: 'domcontentloaded',
                                            timeout: 30000
                                        });
                                    } catch (e) {
                                        console.log('   Navigation retry failed: ' + e.message);
                                    }
                                }
                                
                                retryCount++;
                                await new Promise(resolve => setTimeout(resolve, 3000));
                            }
                        } catch (e) {
                            console.log('⚠️  Error checking page: ' + e.message);
                            retryCount++;
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        }
                    }
                    
                    if (!pageLoaded) {
                        console.log('❌ Page failed to load after ' + maxRetries + ' attempts');
                        console.log('   Final URL: ' + currentUrl);
                        throw new Error('Page failed to load. URL: ' + currentUrl);
                    }
                    
                    // 截圖 1: 頁面載入完成後
                    console.log('📸 Step 0: Taking screenshot after page loaded...');
                    const screenshot0Path = path.join(workingDir, '1bet_step0_page_loaded.png');
                    await page.screenshot({
                        path: screenshot0Path,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshot0Path);
                    
                    // 1BET 登入流程（使用 trait 方法）
                    {$loginCode}
                    
                    // 最終截圖
                    console.log('📸 Final: Taking final screenshot...');
                    const screenshotPath = path.join(workingDir, '1bet_final.png');
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

            navigateAndScreenshot().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_1bet_screenshot.js');
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
     * 處理截圖
     * @param array $result 爬取的結果資料
     */
    private function processScreenshot($result)
    {
        $this->info('');
        $this->info('4. Processing screenshots...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');

        // 處理截圖
        $screenshotsDir = storage_path('app/scraped_data');
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }

        // 處理所有步驟的截圖文件
        $screenshotFiles = [
            '1bet_step0_page_loaded.png' => 'step0_page_loaded',
            '1bet_step1_account_filled.png' => 'step1_account_filled',
            '1bet_step2_password_filled.png' => 'step2_password_filled',
            '1bet_step3_login_clicked.png' => 'step3_login_clicked',
            '1bet_step4_login_completed.png' => 'step4_login_completed',
            '1bet_final.png' => 'final'
        ];

        $savedCount = 0;
        foreach ($screenshotFiles as $screenshotFile => $stepName) {
            $screenshotSrc = $workingDir . '/' . $screenshotFile;
            if (file_exists($screenshotSrc)) {
                $screenshotDst = $screenshotsDir . '/1bet_' . $timestamp . '_' . $stepName . '.png';
                rename($screenshotSrc, $screenshotDst);
                $this->info("📸 {$stepName} screenshot saved: {$screenshotDst}");
                $savedCount++;
            }
        }

        if ($savedCount === 0) {
            $this->warn('⚠️  No screenshot files found');
        } else {
            $this->info("✅ Total {$savedCount} screenshot(s) saved");
        }

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Screenshot process completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('📄 Title: ' . ($result['title'] ?? 'N/A'));
        } else {
            $this->error('❌ Screenshot process failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        $this->info('');
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }
}
