<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * 1BET 瀏覽器截圖命令
 */
class ScrapeBrowser1BetDomDetail extends Command
{

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-1bet-dom-detail
     */
    protected $signature = 'agent:scrape-1bet-dom-detail';

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
        // 從 .env 獲取 1BET_AGENT_DOMAIN
        $domain = env('1BET_AGENT_DOMAIN', '');
        
        if (empty($domain)) {
            $this->error('❌ 1BET_AGENT_DOMAIN is not set in .env file');
            return 1;
        }
        
        // 從 .env 獲取 1BET_AGENT_ACCOUNT
        $account = env('1BET_AGENT_ACCOUNT', '');
        
        // 從 .env 獲取 1BET_AGENT_PASSWORD
        $password = env('1BET_AGENT_PASSWORD', '');
        
        $this->info('=== 1BET Browser Screenshot ===');
        $this->info("Target Domain: {$domain}");
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
        $scriptPath = $this->createPuppeteerScript($domain, $account, $password);

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
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $account = '', $password = '')
    {
        $this->info('2. Creating browser automation script...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);
        
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

                    // 移除 JSON 編碼的引號
                    const targetUrl = $domainJs.replace(/^"|"\$/g, '');
                    
                    console.log('🌐 Navigating to target URL: ' + targetUrl);
                    
                    // 使用請求攔截來處理重定向循環
                    await page.setRequestInterception(true);
                    
                    let redirectCount = 0;
                    const maxRedirects = 15;
                    const redirectHistory = [];
                    
                    page.on('request', async (request) => {
                        const url = request.url();
                        
                        // 檢查重定向循環
                        if (redirectHistory.includes(url) && redirectCount >= maxRedirects) {
                            console.log('⚠️  Redirect loop detected, stopping redirects');
                            console.log('   Final URL: ' + url);
                            // 允許最後一個請求繼續，但停止重定向
                            await request.continue();
                            return;
                        }
                        
                        // 記錄重定向歷史
                        if (redirectHistory.length > 0 && redirectHistory[redirectHistory.length - 1] !== url) {
                            redirectCount++;
                            redirectHistory.push(url);
                            if (redirectCount <= 5) {
                                console.log('   Redirect ' + redirectCount + ': ' + url);
                            }
                        } else if (redirectHistory.length === 0) {
                            redirectHistory.push(url);
                        }
                        
                        await request.continue();
                    });
                    
                    // 導航到目標 URL，即使有重定向錯誤也繼續
                    try {
                        await page.goto(targetUrl, {
                            waitUntil: 'domcontentloaded',
                            timeout: 30000
                        });
                        console.log('✅ Navigation completed');
                    } catch (e) {
                        if (e.message.includes('ERR_TOO_MANY_REDIRECTS')) {
                            console.log('⚠️  Redirect loop detected, but continuing...');
                            // 即使有重定向錯誤，也等待一下讓頁面載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        } else {
                            console.log('⚠️  Navigation error: ' + e.message);
                            // 等待一下讓頁面有機會載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        }
                    }
                    
                    // 等待頁面完全載入
                    console.log('⏳ Waiting for page to fully render...');
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 檢查當前 URL
                    try {
                        const currentUrl = page.url();
                        console.log('📍 Current URL: ' + currentUrl);
                    } catch (e) {
                        console.log('⚠️  Could not get current URL: ' + e.message);
                    }
                    
                    // 解析帳號參數
                    let accountParsed = null;
                    try {
                        if ($accountJs && $accountJs !== 'null' && $accountJs !== '') {
                            accountParsed = JSON.parse($accountJs);
                        }
                    } catch (e) {
                        accountParsed = $accountJs !== 'null' ? $accountJs.replace(/^"|"\$/g, '') : null;
                    }
                    
                    // 步驟 1: 查找並填入帳號 input（不標記）
                    console.log('🔍 Step 1: Looking for account input field...');
                    const accountInputFound = await page.evaluate((accountValue) => {
                        // 查找 input 框：placeholder="请输入账号" 且 class="el-input__inner"
                        const input = document.querySelector('input.el-input__inner[placeholder="请输入账号"]');
                        
                        if (input) {
                            // 如果提供了帳號，填入帳號
                            if (accountValue && accountValue !== null && accountValue !== '') {
                                input.value = accountValue;
                                // 觸發 input 和 change 事件，確保框架能檢測到值變化
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                            }
                            
                            return {
                                found: true,
                                placeholder: input.placeholder,
                                className: input.className,
                                value: input.value
                            };
                        }
                        
                        return { found: false };
                    }, accountParsed);
                    
                    if (accountInputFound.found) {
                        console.log('✅ Account input field found!');
                        console.log('   Placeholder: ' + accountInputFound.placeholder);
                        if (accountParsed && accountParsed !== null && accountParsed !== '') {
                            console.log('   Account filled: ' + accountParsed);
                        }
                    } else {
                        console.log('⚠️  Account input field not found with placeholder "请输入账号"');
                    }
                    
                    // 等待一下讓輸入完成
                    await new Promise(resolve => setTimeout(resolve, 500));
                    
                    // 解析密碼參數
                    let passwordParsed = null;
                    try {
                        if ($passwordJs && $passwordJs !== 'null' && $passwordJs !== '') {
                            passwordParsed = JSON.parse($passwordJs);
                        }
                    } catch (e) {
                        passwordParsed = $passwordJs !== 'null' ? $passwordJs.replace(/^"|"\$/g, '') : null;
                    }
                    
                    // 步驟 2: 查找並標記密碼 input
                    console.log('🔍 Step 2: Looking for password input field...');
                    const passwordInputFound = await page.evaluate((passwordValue) => {
                        // 查找密碼 input 框：placeholder="请输入密码" 且 class="el-input__inner"
                        const input = document.querySelector('input.el-input__inner[placeholder="请输入密码"]');
                        
                        if (input) {
                            // 保存原始樣式
                            input.setAttribute('data-original-style', input.getAttribute('style') || '');
                            
                            // 添加高亮標記（紅色邊框和陰影）
                            input.style.border = '5px solid red';
                            input.style.boxShadow = '0 0 20px red';
                            input.style.zIndex = '9999';
                            input.style.position = 'relative';
                            input.style.backgroundColor = 'rgba(255, 0, 0, 0.1)';
                            
                            // 如果提供了密碼，填入密碼
                            if (passwordValue && passwordValue !== null && passwordValue !== '') {
                                input.value = passwordValue;
                                // 觸發 input 和 change 事件，確保框架能檢測到值變化
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                            }
                            
                            // 滾動到該元素位置
                            input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            return {
                                found: true,
                                placeholder: input.placeholder,
                                className: input.className,
                                type: input.type,
                                value: input.value ? '***' : '' // 不顯示真實密碼，只顯示是否已填入
                            };
                        }
                        
                        return { found: false };
                    }, passwordParsed);
                    
                    if (passwordInputFound.found) {
                        console.log('✅ Password input field found and marked!');
                        console.log('   Placeholder: ' + passwordInputFound.placeholder);
                        console.log('   Type: ' + passwordInputFound.type);
                        console.log('   Class: ' + passwordInputFound.className);
                        if (passwordParsed && passwordParsed !== null && passwordParsed !== '') {
                            console.log('   Password filled: ' + (passwordInputFound.value ? 'Yes' : 'No'));
                        }
                        
                        // 等待一下讓滾動和樣式生效
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    } else {
                        console.log('⚠️  Password input field not found with placeholder "请输入密码"');
                        // 嘗試查找其他可能的選擇器
                        const alternativeInput = await page.evaluate(() => {
                            const passwordInputs = document.querySelectorAll('input[type="password"].el-input__inner');
                            if (passwordInputs.length > 0) {
                                return {
                                    found: true,
                                    count: passwordInputs.length,
                                    placeholders: Array.from(passwordInputs).map(inp => inp.placeholder).filter(p => p)
                                };
                            }
                            return { found: false };
                        });
                        
                        if (alternativeInput.found) {
                            console.log('   Found ' + alternativeInput.count + ' password input(s) with class el-input__inner');
                            if (alternativeInput.placeholders.length > 0) {
                                console.log('   Available placeholders: ' + alternativeInput.placeholders.join(', '));
                            }
                        }
                    }
                    
                    // 截圖
                    console.log('📸 Taking screenshot...');
                    const screenshotPath = path.join(workingDir, '1bet_screenshot.png');
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
        $this->info('4. Processing screenshot...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');

        // 處理截圖
        $screenshotsDir = storage_path('app/scraped_data');
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }

        // 處理截圖文件
        $screenshotFile = '1bet_screenshot.png';
        $screenshotSrc = $workingDir . '/' . $screenshotFile;
        if (file_exists($screenshotSrc)) {
            $screenshotDst = $screenshotsDir . '/1bet_' . $timestamp . '_' . $screenshotFile;
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved: {$screenshotDst}");
        } else {
            $this->warn('⚠️  Screenshot file not found');
        }

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Screenshot completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('📄 Title: ' . ($result['title'] ?? 'N/A'));
        } else {
            $this->error('❌ Screenshot failed: ' . ($result['error'] ?? 'Unknown error'));
        }

        $this->info('');
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }
}
