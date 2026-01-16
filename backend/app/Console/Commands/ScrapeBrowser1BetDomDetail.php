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
                    
                    // 1BET 登入流程
                    console.log('🔐 Starting 1BET login process...');
                    
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
                    
                    // 截圖 2: 填入帳號後
                    console.log('📸 Step 1: Taking screenshot after account filled...');
                    const screenshot1Path = path.join(workingDir, '1bet_step1_account_filled.png');
                    await page.screenshot({
                        path: screenshot1Path,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshot1Path);
                    
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
                    
                    // 截圖 3: 標記並填入密碼後
                    console.log('📸 Step 2: Taking screenshot after password filled and marked...');
                    const screenshot2Path = path.join(workingDir, '1bet_step2_password_filled.png');
                    await page.screenshot({
                        path: screenshot2Path,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshot2Path);
                    
                    // 步驟 3: 點擊登錄按鈕
                    console.log('🔍 Step 3: Looking for login button...');
                    
                    // 先等待一下讓按鈕完全渲染
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 獲取頁面上所有按鈕的調試信息
                    const buttonDebugInfo = await page.evaluate(() => {
                        const allButtons = Array.from(document.querySelectorAll('button'));
                        return allButtons.map(btn => ({
                            text: btn.textContent.trim(),
                            className: btn.className,
                            type: btn.type,
                            id: btn.id,
                            visible: window.getComputedStyle(btn).display !== 'none' && btn.offsetParent !== null,
                            hasSpan: !!btn.querySelector('span'),
                            spanText: btn.querySelector('span') ? btn.querySelector('span').textContent.trim() : ''
                        }));
                    });
                    
                    console.log('   Found ' + buttonDebugInfo.length + ' button(s) on page:');
                    buttonDebugInfo.forEach((btn, index) => {
                        console.log('     ' + (index + 1) + '. Text: "' + btn.text + '", Class: "' + btn.className + '", Visible: ' + btn.visible);
                        if (btn.hasSpan) {
                            console.log('        Span text: "' + btn.spanText + '"');
                        }
                    });
                    
                    const loginButtonClicked = await page.evaluate(() => {
                        // 方法 1: 查找完整選擇器
                        let button = document.querySelector('button.el-button.btn-login.el-button--primary.el-button--small');
                        
                        // 方法 2: 查找 btn-login 類
                        if (!button) {
                            button = document.querySelector('button.btn-login');
                        }
                        
                        // 方法 3: 查找包含 btn-login 的按鈕
                        if (!button) {
                            const buttons = Array.from(document.querySelectorAll('button[class*="btn-login"]'));
                            if (buttons.length > 0) {
                                button = buttons[0];
                            }
                        }
                        
                        // 方法 4: 通過文本查找 "登录"（檢查按鈕文本和 span 文本）
                        if (!button) {
                            const allButtons = Array.from(document.querySelectorAll('button.el-button, button[type="button"]'));
                            button = allButtons.find(btn => {
                                const text = btn.textContent.trim();
                                const spanText = btn.querySelector('span') ? btn.querySelector('span').textContent.trim() : '';
                                return text === '登录' || text === '登入' || text === 'Login' ||
                                       spanText === '登录' || spanText === '登入' || spanText === 'Login';
                            });
                        }
                        
                        // 方法 5: 查找任何包含 "登录" 文本的按鈕
                        if (!button) {
                            const allButtons = Array.from(document.querySelectorAll('button'));
                            button = allButtons.find(btn => {
                                const text = btn.textContent.trim();
                                const spanText = btn.querySelector('span') ? btn.querySelector('span').textContent.trim() : '';
                                return text.includes('登录') || text.includes('登入') || text.includes('Login') ||
                                       spanText.includes('登录') || spanText.includes('登入') || spanText.includes('Login');
                            });
                        }
                        
                        // 方法 6: 查找 primary 類型的按鈕（通常是登錄按鈕）
                        if (!button) {
                            const primaryButtons = Array.from(document.querySelectorAll('button.el-button--primary'));
                            if (primaryButtons.length > 0) {
                                button = primaryButtons[0];
                            }
                        }
                        
                        if (button) {
                            // 檢查按鈕是否可見
                            const style = window.getComputedStyle(button);
                            const isVisible = style.display !== 'none' && 
                                            style.visibility !== 'hidden' && 
                                            style.opacity !== '0' &&
                                            button.offsetParent !== null;
                            
                            if (!isVisible) {
                                console.log('   Button found but not visible');
                                return { clicked: false, reason: 'not_visible', buttonText: button.textContent.trim() };
                            }
                            
                            // 滾動到按鈕位置
                            button.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            // 點擊按鈕
                            button.click();
                            
                            return {
                                clicked: true,
                                text: button.textContent.trim(),
                                className: button.className,
                                spanText: button.querySelector('span') ? button.querySelector('span').textContent.trim() : ''
                            };
                        }
                        
                        return { clicked: false, reason: 'not_found' };
                    });
                    
                    if (loginButtonClicked.clicked) {
                        console.log('✅ Login button clicked!');
                        console.log('   Button text: ' + loginButtonClicked.text);
                        console.log('   Button class: ' + loginButtonClicked.className);
                        
                        // 等待一下讓點擊生效
                        await new Promise(resolve => setTimeout(resolve, 500));
                        
                        // 截圖 4: 點擊登錄按鈕後（登錄處理前）
                        console.log('📸 Step 3: Taking screenshot after login button clicked...');
                        const screenshot3Path = path.join(workingDir, '1bet_step3_login_clicked.png');
                        await page.screenshot({
                            path: screenshot3Path,
                            fullPage: true
                        });
                        console.log('✅ Screenshot saved: ' + screenshot3Path);
                        
                        // 記錄點擊前的 URL
                        const urlBeforeLogin = page.url();
                        console.log('   URL before login: ' + urlBeforeLogin);
                        
                        // 等待頁面響應（登錄後可能會有頁面跳轉或載入）
                        console.log('⏳ Waiting for login response and page navigation...');
                        
                        try {
                            // 等待頁面導航完成（最多等待 10 秒）
                            await page.waitForNavigation({
                                waitUntil: 'networkidle2',
                                timeout: 10000
                            }).catch(() => {
                                console.log('   Navigation wait timeout, continuing...');
                            });
                            
                            // 檢查 URL 是否變化
                            const urlAfterLogin = page.url();
                            console.log('   URL after login: ' + urlAfterLogin);
                            
                            if (urlAfterLogin !== urlBeforeLogin) {
                                console.log('✅ Page navigated after login');
                            }
                        } catch (e) {
                            console.log('⚠️  Navigation error: ' + e.message);
                        }
                        
                        // 等待頁面完全載入（即使沒有導航，也等待內容更新）
                        console.log('⏳ Waiting for page content to load...');
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        
                        // 再次等待確保頁面完全渲染
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        // 檢查頁面是否有內容
                        const pageContent = await page.evaluate(() => {
                            const bodyText = document.body ? document.body.innerText : '';
                            return {
                                url: window.location.href,
                                title: document.title,
                                bodyText: bodyText,
                                bodyTextLength: bodyText.length,
                                hasContent: document.body && bodyText.length > 0,
                                hasSecurityCheck: bodyText.includes('What Are You Looking For') || 
                                                bodyText.includes('security check') ||
                                                document.title.includes('What Are You Looking For')
                            };
                        });
                        
                        console.log('   Page title: ' + pageContent.title);
                        console.log('   Body text length: ' + pageContent.bodyTextLength);
                        console.log('   Has content: ' + pageContent.hasContent);
                        console.log('   Has security check: ' + pageContent.hasSecurityCheck);
                        
                        // 檢查是否為安全驗證頁面
                        if (pageContent.hasSecurityCheck) {
                            console.log('⚠️  Detected security check page: "What Are You Looking For"');
                            console.log('   Waiting for security check to complete...');
                            
                            // 等待安全驗證完成（最多等待 10 秒）
                            let securityCheckPassed = false;
                            for (let i = 0; i < 10; i++) {
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                const checkStatus = await page.evaluate(() => {
                                    const bodyText = document.body ? document.body.innerText : '';
                                    return {
                                        hasSecurityCheck: bodyText.includes('What Are You Looking For') || 
                                                         document.title.includes('What Are You Looking For'),
                                        url: window.location.href
                                    };
                                });
                                
                                if (!checkStatus.hasSecurityCheck) {
                                    securityCheckPassed = true;
                                    console.log('✅ Security check passed, URL: ' + checkStatus.url);
                                    break;
                                }
                                
                                console.log('   Still on security check page, waiting... (' + (i + 1) + '/10)');
                            }
                            
                            if (!securityCheckPassed) {
                                console.log('⚠️  Security check did not complete automatically');
                            }
                        }
                        
                        // 檢查是否為黑畫面（內容很少或沒有內容）
                        const isBlackScreen = !pageContent.hasContent || pageContent.bodyTextLength < 100;
                        
                        if (isBlackScreen) {
                            console.log('⚠️  Detected black screen or empty page after login');
                        }
                        
                        // 如果提供了重定向 URL，則跳轉
                        let redirectUrlParsed = null;
                        try {
                            if ($redirectUrlJs && $redirectUrlJs !== 'null' && $redirectUrlJs !== '') {
                                redirectUrlParsed = JSON.parse($redirectUrlJs);
                            }
                        } catch (e) {
                            redirectUrlParsed = $redirectUrlJs !== 'null' ? $redirectUrlJs.replace(/^"|"\$/g, '') : null;
                        }
                        
                        // 如果檢測到安全驗證頁面或黑畫面，且有重定向 URL，直接跳轉
                        if ((pageContent.hasSecurityCheck || isBlackScreen) && redirectUrlParsed && redirectUrlParsed !== null && redirectUrlParsed !== '') {
                            console.log('🔄 Security check or black screen detected, redirecting to specified URL: ' + redirectUrlParsed);
                        } else if (redirectUrlParsed && redirectUrlParsed !== null && redirectUrlParsed !== '') {
                            console.log('🔄 Redirecting to specified URL: ' + redirectUrlParsed);
                        }
                        
                        if (redirectUrlParsed && redirectUrlParsed !== null && redirectUrlParsed !== '') {
                            
                            try {
                                // 導航到指定的 URL
                                await page.goto(redirectUrlParsed, {
                                    waitUntil: 'networkidle2',
                                    timeout: 30000
                                });
                                console.log('✅ Navigation to redirect URL completed');
                                
                                // 等待頁面完全載入
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                
                                // 檢查新頁面狀態
                                const redirectPageContent = await page.evaluate(() => {
                                    return {
                                        url: window.location.href,
                                        title: document.title,
                                        bodyText: document.body ? document.body.innerText.length : 0,
                                        hasContent: document.body && document.body.innerText.length > 0
                                    };
                                });
                                
                                console.log('   Redirected URL: ' + redirectPageContent.url);
                                console.log('   Redirected page title: ' + redirectPageContent.title);
                                console.log('   Redirected page body text length: ' + redirectPageContent.bodyTextLength);
                                console.log('   Redirected page has content: ' + redirectPageContent.hasContent);
                            } catch (e) {
                                console.log('⚠️  Redirect navigation failed: ' + e.message);
                                // 即使失敗也繼續，可能頁面已經載入
                                await new Promise(resolve => setTimeout(resolve, 2000));
                            }
                        } else if (isBlackScreen) {
                            console.log('⚠️  Black screen detected but no redirect URL configured');
                        }
                        
                        // 截圖 5: 登錄完成後（或重定向後）
                        console.log('📸 Step 4: Taking screenshot after login completed (or redirected)...');
                        const screenshot4Path = path.join(workingDir, '1bet_step4_login_completed.png');
                        await page.screenshot({
                            path: screenshot4Path,
                            fullPage: true
                        });
                        console.log('✅ Screenshot saved: ' + screenshot4Path);
                    } else {
                        console.log('⚠️  Login button not found');
                        if (loginButtonClicked.reason) {
                            console.log('   Reason: ' + loginButtonClicked.reason);
                            if (loginButtonClicked.buttonText) {
                                console.log('   Button text found: ' + loginButtonClicked.buttonText);
                            }
                        }
                        
                        // 嘗試使用 Puppeteer 的 click 方法
                        console.log('   Trying Puppeteer click method...');
                        try {
                            // 嘗試多種選擇器
                            const selectors = [
                                'button.el-button.btn-login.el-button--primary.el-button--small',
                                'button.btn-login',
                                'button[class*="btn-login"]',
                                'button.el-button--primary'
                            ];
                            
                            let clicked = false;
                            for (const selector of selectors) {
                                try {
                                    const button = await page.$(selector);
                                    if (button) {
                                        const isVisible = await button.isIntersectingViewport();
                                        if (isVisible) {
                                            await button.scrollIntoView();
                                            await new Promise(resolve => setTimeout(resolve, 300));
                                            await button.click();
                                            console.log('   ✅ Button clicked using selector: ' + selector);
                                            clicked = true;
                                            break;
                                        }
                                    }
                                } catch (e) {
                                    // 繼續嘗試下一個選擇器
                                }
                            }
                            
                            if (!clicked) {
                                console.log('   ⚠️  Could not click button using Puppeteer methods');
                            } else {
                                // 如果成功點擊，繼續登錄流程
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                // 截圖 4: 點擊登錄按鈕後
                                console.log('📸 Step 3: Taking screenshot after login button clicked...');
                                const screenshot3Path = path.join(workingDir, '1bet_step3_login_clicked.png');
                                await page.screenshot({
                                    path: screenshot3Path,
                                    fullPage: true
                                });
                                console.log('✅ Screenshot saved: ' + screenshot3Path);
                                
                                // 繼續登錄流程
                                const urlBeforeLogin = page.url();
                                console.log('   URL before login: ' + urlBeforeLogin);
                                
                                console.log('⏳ Waiting for login response and page navigation...');
                                
                                try {
                                    await page.waitForNavigation({
                                        waitUntil: 'networkidle2',
                                        timeout: 10000
                                    }).catch(() => {
                                        console.log('   Navigation wait timeout, continuing...');
                                    });
                                    
                                    const urlAfterLogin = page.url();
                                    console.log('   URL after login: ' + urlAfterLogin);
                                    
                                    if (urlAfterLogin !== urlBeforeLogin) {
                                        console.log('✅ Page navigated after login');
                                    }
                                } catch (e) {
                                    console.log('⚠️  Navigation error: ' + e.message);
                                }
                                
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                await new Promise(resolve => setTimeout(resolve, 2000));
                                
                                const pageContent = await page.evaluate(() => {
                                    const bodyText = document.body ? document.body.innerText : '';
                                    return {
                                        url: window.location.href,
                                        title: document.title,
                                        bodyText: bodyText,
                                        bodyTextLength: bodyText.length,
                                        hasContent: document.body && bodyText.length > 0,
                                        hasSecurityCheck: bodyText.includes('What Are You Looking For') || 
                                                        bodyText.includes('security check') ||
                                                        document.title.includes('What Are You Looking For')
                                    };
                                });
                                
                                console.log('   Page title: ' + pageContent.title);
                                console.log('   Body text length: ' + pageContent.bodyTextLength);
                                console.log('   Has content: ' + pageContent.hasContent);
                                console.log('   Has security check: ' + pageContent.hasSecurityCheck);
                                
                                if (pageContent.hasSecurityCheck) {
                                    console.log('⚠️  Detected security check page: "What Are You Looking For"');
                                    console.log('   Waiting for security check to complete...');
                                    
                                    let securityCheckPassed = false;
                                    for (let i = 0; i < 10; i++) {
                                        await new Promise(resolve => setTimeout(resolve, 1000));
                                        
                                        const checkStatus = await page.evaluate(() => {
                                            const bodyText = document.body ? document.body.innerText : '';
                                            return {
                                                hasSecurityCheck: bodyText.includes('What Are You Looking For') || 
                                                                 document.title.includes('What Are You Looking For'),
                                                url: window.location.href
                                            };
                                        });
                                        
                                        if (!checkStatus.hasSecurityCheck) {
                                            securityCheckPassed = true;
                                            console.log('✅ Security check passed, URL: ' + checkStatus.url);
                                            break;
                                        }
                                        
                                        console.log('   Still on security check page, waiting... (' + (i + 1) + '/10)');
                                    }
                                    
                                    if (!securityCheckPassed) {
                                        console.log('⚠️  Security check did not complete automatically');
                                    }
                                }
                                
                                const isBlackScreen = !pageContent.hasContent || pageContent.bodyTextLength < 100;
                                
                                if (isBlackScreen) {
                                    console.log('⚠️  Detected black screen or empty page after login');
                                }
                                
                                let redirectUrlParsed = null;
                                try {
                                    if ($redirectUrlJs && $redirectUrlJs !== 'null' && $redirectUrlJs !== '') {
                                        redirectUrlParsed = JSON.parse($redirectUrlJs);
                                    }
                                } catch (e) {
                                    redirectUrlParsed = $redirectUrlJs !== 'null' ? $redirectUrlJs.replace(/^"|"\$/g, '') : null;
                                }
                                
                                if ((pageContent.hasSecurityCheck || isBlackScreen) && redirectUrlParsed && redirectUrlParsed !== null && redirectUrlParsed !== '') {
                                    console.log('🔄 Security check or black screen detected, redirecting to specified URL: ' + redirectUrlParsed);
                                } else if (redirectUrlParsed && redirectUrlParsed !== null && redirectUrlParsed !== '') {
                                    console.log('🔄 Redirecting to specified URL: ' + redirectUrlParsed);
                                }
                                
                                if (redirectUrlParsed && redirectUrlParsed !== null && redirectUrlParsed !== '') {
                                    try {
                                        await page.goto(redirectUrlParsed, {
                                            waitUntil: 'networkidle2',
                                            timeout: 30000
                                        });
                                        console.log('✅ Navigation to redirect URL completed');
                                        
                                        await new Promise(resolve => setTimeout(resolve, 3000));
                                        
                                        const redirectPageContent = await page.evaluate(() => {
                                            return {
                                                url: window.location.href,
                                                title: document.title,
                                                bodyText: document.body ? document.body.innerText.length : 0,
                                                hasContent: document.body && document.body.innerText.length > 0
                                            };
                                        });
                                        
                                        console.log('   Redirected URL: ' + redirectPageContent.url);
                                        console.log('   Redirected page title: ' + redirectPageContent.title);
                                        console.log('   Redirected page body text length: ' + redirectPageContent.bodyTextLength);
                                        console.log('   Redirected page has content: ' + redirectPageContent.hasContent);
                                    } catch (e) {
                                        console.log('⚠️  Redirect navigation failed: ' + e.message);
                                        await new Promise(resolve => setTimeout(resolve, 2000));
                                    }
                                } else if (isBlackScreen) {
                                    console.log('⚠️  Black screen detected but no redirect URL configured');
                                }
                                
                                console.log('📸 Step 4: Taking screenshot after login completed (or redirected)...');
                                const screenshot4Path = path.join(workingDir, '1bet_step4_login_completed.png');
                                await page.screenshot({
                                    path: screenshot4Path,
                                    fullPage: true
                                });
                                console.log('✅ Screenshot saved: ' + screenshot4Path);
                            }
                        } catch (e) {
                            console.log('⚠️  Error trying Puppeteer click: ' + e.message);
                        }
                    }
                    
                    console.log('✅ 1BET login process completed');
                    
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
