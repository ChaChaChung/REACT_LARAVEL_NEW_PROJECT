<?php

namespace App\Console\Commands\Traits;

/**
 * Agent 認證配置 Trait
 * 提供統一的認證資訊管理
 */
trait HasAgentAuth
{
    /**
     * 生成 Puppeteer cookies 設定程式碼片段
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generatePuppeteerCookiesCode(): string
    {
        $auth = env('AGENT_AUTH', '');
        $token = env('AGENT_TOKEN', '');
        $bgLang = env('AGENT_BG_LANGUAGE_KEY', 'zh-cn');
        $domain = env('AGENT_DOMAIN');

        return <<<JS
            console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            if ('$auth') cookies.push({ name: 'auth', value: '$auth', domain: '$domain' });
            if ('$token') cookies.push({ name: 'token', value: '$token', domain: '$domain' });
            if ('$bgLang') cookies.push({ name: 'bg_languageKey', value: '$bgLang', domain: '$domain' });

            // 如果有設定 cookies，則應用到頁面
            if (cookies.length > 0) {
                await page.setCookie(...cookies);
                console.log('✅ Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No cookies found in environment variables');
            }
        JS;
    }

    /**
     * 生成 RSG Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateRsgPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $token = env('RSG_AGENT_TOKEN', '');
        $lang = env('RSG_AGENT_LANGUAGE', 'zh-TW');
        $domain = env('RSG_AGENT_DOMAIN');

        return <<<JS
            console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            if ('$token') cookies.push({ name: '.RoyalGameSGTCtrl.Session', value: '$token', domain: '$domain' });
            if ('$lang') cookies.push({ name: 'Lang', value: '$lang', domain: '$domain' });

            // 如果有設定 cookies，則應用到頁面
            if (cookies.length > 0) {
                await {$pageVar}.setCookie(...cookies);
                console.log('✅ Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No cookies found in environment variables');
            }
        JS;
    }

    /**
     * 生成 FoqQ Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateFoqqPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $lang = env('FOQQ_AGENT_LANG');
        $auth = env('FOQQ_AGENT_AUTH', '');
        $token = env('FOQQ_AGENT_TOKEN', '');
        $domain = env('FOQQ_AGENT_DOMAIN');

        return <<<JS
            // console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            if ('$lang') cookies.push({ name: 'lang', value: '$lang', domain: '$domain' });
            if ('$auth') cookies.push({ name: 'ci_session', value: '$auth', domain: '$domain' });
            if ('$token') cookies.push({ name: 'login_root', value: '$token', domain: '$domain' });

            // 如果有設定 cookies，則應用到頁面
            if (cookies.length > 0) {
                await {$pageVar}.setCookie(...cookies);
                // console.log('✅ Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No cookies found in environment variables');
            }
        JS;
    }

    /**
     * 生成 168 Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generate168PuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $auth = env('168_AGENT_AUTH', '');
        $domain = env('168_AGENT_DOMAIN');

        return <<<JS
            // console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            if ('$auth') cookies.push({ name: 'laravel_session', value: '$auth', domain: '$domain' });

            // 如果有設定 cookies，則應用到頁面
            if (cookies.length > 0) {
                await {$pageVar}.setCookie(...cookies);
                // console.log('✅ Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No cookies found in environment variables');
            }
        JS;
    }

    /**
     * 生成 Splus Puppeteer 登入流程程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateSplusPuppeteerLoginCode(string $pageVar = 'page'): string
    {
        $domain = env('SPLUS_AGENT_DOMAIN', '');
        $account = env('SPLUS_AGENT_ACCOUNT', '');
        $password = env('SPLUS_AGENT_PASSWORD', '');
        
        // 轉義 JavaScript 字符串，避免注入問題
        $domainJs = json_encode($domain);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);

        return <<<JS
            console.log('🔐 Starting Splus login process...');
            
            // 導航到登入頁面
            await {$pageVar}.goto($domainJs, {
                waitUntil: 'domcontentloaded',
                timeout: 30000
            });
            
            // 等待頁面載入
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 查找並填入帳號欄位
            const accountInput = await {$pageVar}.waitForSelector('input[id="input-10"]', { timeout: 10000 }).catch(() => null);
            
            if (accountInput) {
                await {$pageVar}.evaluate((account) => {
                    const input = document.querySelector('input[id="input-10"]');
                    if (input) {
                        input.value = '';
                        input.value = account;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                    }
                }, $accountJs);
            }
            
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // 查找並填入密碼欄位
            const passwordInput = await {$pageVar}.waitForSelector('input[id="input-13"]', { timeout: 10000 }).catch(() => null);
            
            if (passwordInput) {
                await {$pageVar}.evaluate((password) => {
                    const input = document.querySelector('input[id="input-13"]');
                    if (input) {
                        input.value = '';
                        input.value = password;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                    }
                }, $passwordJs);
            }
            
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // 使用 evaluate 查找並點擊登入按鈕
            const buttonFound = await {$pageVar}.evaluate(() => {
                // 查找所有可能的按鈕
                const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], a[class*="btn"]'));
                const loginBtn = buttons.find(btn => {
                    const text = (btn.textContent || btn.value || btn.innerText || '').trim().toLowerCase();
                    const className = (btn.className || '').toLowerCase();
                    const id = (btn.id || '').toLowerCase();
                    
                    // 檢查是否包含登入相關的文字
                    return text.includes('登入') || text.includes('login');
                });
                
                if (loginBtn) {
                    // 先嘗試點擊
                    try {
                        loginBtn.click();
                        return { found: true, method: 'click' };
                    } catch (e) {
                        // 如果點擊失敗，嘗試觸發事件
                        const event = new MouseEvent('click', { bubbles: true, cancelable: true });
                        loginBtn.dispatchEvent(event);
                        return { found: true, method: 'dispatchEvent' };
                    }
                }
                
                // 如果找不到按鈕，嘗試提交表單
                const forms = document.querySelectorAll('form');
                if (forms.length > 0) {
                    forms[0].submit();
                    return { found: true, method: 'formSubmit' };
                }
                
                return { found: false };
            });

            // 等待登入完成（等待頁面導航或 URL 變化）
            await {$pageVar}.waitForNavigation({ 
                waitUntil: 'domcontentloaded',
                timeout: 30000 
            }).catch(() => {
                console.log('⚠️  Navigation timeout, waiting 3 seconds...');
            });
            
            // 額外等待確保頁面完全載入
            await new Promise(resolve => setTimeout(resolve, 2000));
        JS;
    }

    /**
     * 生成 1BET Puppeteer 登入流程程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generate1BetPuppeteerLoginCode(string $pageVar = 'page'): string
    {
        $domain = env('1BET_AGENT_DOMAIN', '');
        $account = env('1BET_AGENT_ACCOUNT', '');
        $password = env('1BET_AGENT_PASSWORD', '');
        
        // 轉義 JavaScript 字符串，避免注入問題
        $domainJs = json_encode($domain);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);

        return <<<JS
            console.log('🔐 Starting 1BET login process...');
            console.log('📍 Step 1: Navigating to login page...');
            console.log('   URL: ' + $domainJs);
            
            // 設置頁面錯誤監聽
            {$pageVar}.on('pageerror', (error) => {
                console.log('   ⚠️  Page error: ' + error.message);
            });
            
            {$pageVar}.on('requestfailed', (request) => {
                console.log('   ⚠️  Request failed: ' + request.url() + ' - ' + request.failure().errorText);
            });
            
            // 監聽響應
            {$pageVar}.on('response', (response) => {
                const status = response.status();
                if (status >= 400) {
                    console.log('   ⚠️  Response error: ' + response.url() + ' - Status: ' + status);
                }
            });
            
            // 導航到登入頁面，使用 networkidle2 確保頁面完全加載
            console.log('   Attempting to navigate...');
            let navigationSuccess = false;
            try {
                const response = await {$pageVar}.goto($domainJs, {
                    waitUntil: 'networkidle2',
                    timeout: 60000
                });
                console.log('   Navigation completed (networkidle2)');
                console.log('   Response status: ' + (response ? response.status() : 'N/A'));
                navigationSuccess = true;
            } catch (e) {
                console.log('   ⚠️  Networkidle2 timeout: ' + e.message);
                console.log('   Trying with load event...');
                try {
                    const response = await {$pageVar}.goto($domainJs, {
                        waitUntil: 'load',
                        timeout: 60000
                    });
                    console.log('   Navigation completed (load event)');
                    console.log('   Response status: ' + (response ? response.status() : 'N/A'));
                    navigationSuccess = true;
                } catch (e2) {
                    console.log('   ⚠️  Load event timeout: ' + e2.message);
                    console.log('   Trying with domcontentloaded...');
                    try {
                        const response = await {$pageVar}.goto($domainJs, {
                            waitUntil: 'domcontentloaded',
                            timeout: 60000
                        });
                        console.log('   Navigation completed (domcontentloaded)');
                        console.log('   Response status: ' + (response ? response.status() : 'N/A'));
                        navigationSuccess = true;
                    } catch (e3) {
                        console.log('   ❌ All navigation attempts failed');
                        console.log('   Last error: ' + e3.message);
                        // 不抛出错误，继续尝试
                    }
                }
            }
            
            // 如果导航失败，等待一下再检查
            if (!navigationSuccess) {
                console.log('   ⚠️  Navigation may have failed, waiting 5 seconds...');
                await new Promise(resolve => setTimeout(resolve, 5000));
            }
            
            // 等待一下讓頁面完全渲染
            console.log('   Waiting for page to render (3 seconds)...');
            await new Promise(resolve => setTimeout(resolve, 3000));
            
            // 檢查頁面是否正確加載
            let initialUrl = {$pageVar}.url();
            let initialTitle = await {$pageVar}.title();
            console.log('   Initial URL (from page.url()): ' + initialUrl);
            console.log('   Initial Title: ' + initialTitle);
            
            // 如果 URL 是空白，嘗試等待或重新導航
            if (initialUrl === 'about:blank' || initialUrl === '') {
                console.log('   ⚠️  URL is blank, waiting for navigation (10 seconds)...');
                try {
                    await {$pageVar}.waitForNavigation({ 
                        waitUntil: 'networkidle2', 
                        timeout: 10000 
                    });
                    initialUrl = {$pageVar}.url();
                    console.log('   URL after wait: ' + initialUrl);
                } catch (e) {
                    console.log('   ⚠️  Wait navigation timeout: ' + e.message);
                    // 嘗試重新導航
                    console.log('   Attempting to navigate again...');
                    try {
                        await {$pageVar}.goto($domainJs, {
                            waitUntil: 'networkidle2',
                            timeout: 30000
                        });
                        initialUrl = {$pageVar}.url();
                        console.log('   URL after retry: ' + initialUrl);
                        await new Promise(resolve => setTimeout(resolve, 3000));
                    } catch (e2) {
                        console.log('   ⚠️  Retry navigation failed: ' + e2.message);
                    }
                }
            }
            
            // 檢查頁面內容
            const pageInfo = await {$pageVar}.evaluate(() => {
                return {
                    url: window.location.href,
                    title: document.title,
                    readyState: document.readyState,
                    hasBody: !!document.body,
                    bodyTextLength: document.body ? document.body.innerText.length : 0,
                    inputCount: document.querySelectorAll('input').length,
                    scriptCount: document.querySelectorAll('script').length,
                    hasContent: document.body && document.body.innerText.length > 0
                };
            });
            console.log('   Page info:');
            console.log('     - URL (from window.location): ' + pageInfo.url);
            console.log('     - Title: ' + pageInfo.title);
            console.log('     - Ready state: ' + pageInfo.readyState);
            console.log('     - Has body: ' + pageInfo.hasBody);
            console.log('     - Body text length: ' + pageInfo.bodyTextLength);
            console.log('     - Input fields: ' + pageInfo.inputCount);
            console.log('     - Script tags: ' + pageInfo.scriptCount);
            console.log('     - Has content: ' + pageInfo.hasContent);
            
            // 如果頁面仍然是空白，等待更長時間
            if ((initialUrl === 'about:blank' || pageInfo.url === 'about:blank') && !pageInfo.hasContent) {
                console.log('⚠️  Page still appears blank, waiting longer (10 seconds)...');
                await new Promise(resolve => setTimeout(resolve, 10000));
                
                // 再次檢查
                const finalCheck = await {$pageVar}.evaluate(() => {
                    return {
                        url: window.location.href,
                        bodyTextLength: document.body ? document.body.innerText.length : 0,
                        inputCount: document.querySelectorAll('input').length
                    };
                });
                console.log('   Final check - URL: ' + finalCheck.url);
                console.log('   Final check - Body text length: ' + finalCheck.bodyTextLength);
                console.log('   Final check - Input fields: ' + finalCheck.inputCount);
            }
            
            const currentUrl = {$pageVar}.url();
            const currentTitle = await {$pageVar}.title();
            console.log('   Current URL: ' + currentUrl);
            console.log('   Current Title: ' + currentTitle);
            console.log('✅ Step 1 completed: Page loaded');
            
            // 等待頁面載入
            console.log('⏳ Step 2: Waiting for page to fully load (3 seconds)...');
            await new Promise(resolve => setTimeout(resolve, 3000));
            
            // 檢查頁面是否有內容
            const pageHasContent = await {$pageVar}.evaluate(() => {
                return {
                    hasBody: !!document.body,
                    bodyText: document.body ? document.body.innerText.length : 0,
                    hasInputs: document.querySelectorAll('input').length > 0
                };
            });
            console.log('   Page has body: ' + pageHasContent.hasBody);
            console.log('   Body text length: ' + pageHasContent.bodyText);
            console.log('   Has input fields: ' + pageHasContent.hasInputs);
            console.log('✅ Step 2 completed: Page ready');
            
            // 查找並填入帳號欄位（優先使用精確的選擇器）
            console.log('🔍 Step 3: Looking for account input field...');
            const accountSelectors = [
                'input[type="text"][placeholder="Please enter account number"]',
                'input.el-input__inner[type="text"]',
                'input[type="text"][name*="account"]',
                'input[type="text"][name*="username"]',
                'input[type="text"][name*="user"]',
                'input[type="text"][id*="account"]',
                'input[type="text"][id*="username"]',
                'input[type="text"][id*="user"]',
                'input[type="text"][placeholder*="帳號"]',
                'input[type="text"][placeholder*="帳戶"]',
                'input[type="text"][placeholder*="Account"]',
                'input[type="text"][placeholder*="Username"]',
                'input[type="text"]:first-of-type'
            ];
            
            let accountInput = null;
            let accountSelector = null;
            for (let i = 0; i < accountSelectors.length; i++) {
                const selector = accountSelectors[i];
                console.log('   Trying selector ' + (i + 1) + '/' + accountSelectors.length + ': ' + selector);
                try {
                    accountInput = await {$pageVar}.$(selector);
                    if (accountInput) {
                        accountSelector = selector;
                        console.log('✅ Step 3 completed: Found account input with selector: ' + selector);
                        break;
                    }
                } catch (e) {
                    console.log('   ⚠️  Selector not found, trying next...');
                    continue;
                }
            }
            
            if (accountInput && accountSelector) {
                console.log('✍️  Step 4: Filling account field...');
                // 使用 Puppeteer 的 type 方法，更可靠
                // $accountJs 已经是 JSON 编码的字符串，在 JavaScript 中可以直接使用
                const accountValue = $accountJs;
                console.log('   Account value: ' + accountValue);
                console.log('   Clicking input field (triple click to select all)...');
                await accountInput.click({ clickCount: 3 }); // 三击选中所有文本
                console.log('   Clearing input field...');
                await accountInput.type('', { delay: 0 }); // 清空
                console.log('   Typing account (50ms delay per character)...');
                await accountInput.type(accountValue, { delay: 50 }); // 输入账号，每个字符间隔50ms
                console.log('✅ Step 4 completed: Account filled: ' + accountValue);
            } else {
                console.log('⚠️  Step 3 failed: Account input not found, trying fallback...');
                // 如果找不到，嘗試填入第一個文字輸入框
                try {
                    console.log('   Trying first text input as fallback...');
                    const firstTextInput = await {$pageVar}.$('input[type="text"]:first-of-type');
                    if (firstTextInput) {
                        const accountValue = $accountJs;
                        console.log('   Account value: ' + accountValue);
                        await firstTextInput.click({ clickCount: 3 });
                        await firstTextInput.type('', { delay: 0 });
                        await firstTextInput.type(accountValue, { delay: 50 });
                        console.log('✅ Step 4 completed: Account filled using first text input: ' + accountValue);
                    } else {
                        console.log('❌ Step 4 failed: No text input found');
                    }
                } catch (e) {
                    console.log('❌ Step 4 failed: ' + e.message);
                }
            }
            
            console.log('⏳ Waiting 500ms before next step...');
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // 查找並填入密碼欄位（優先使用精確的選擇器）
            console.log('🔍 Step 5: Looking for password input field...');
            console.log('   Waiting for password input to appear (timeout: 5 seconds)...');
            
            // 先等待密码输入框出现
            let passwordInput = null;
            let passwordSelector = null;
            
            // 优先尝试等待精确的选择器
            const prioritySelectors = [
                'input[type="password"][placeholder="password"]',
                'input.el-input__inner[type="password"]'
            ];
            
            for (const selector of prioritySelectors) {
                try {
                    console.log('   Waiting for: ' + selector);
                    await {$pageVar}.waitForSelector(selector, { timeout: 5000 });
                    passwordInput = await {$pageVar}.$(selector);
                    if (passwordInput) {
                        passwordSelector = selector;
                        console.log('✅ Step 5 completed: Found password input with selector: ' + selector);
                        break;
                    }
                } catch (e) {
                    console.log('   ⚠️  Selector not found: ' + selector);
                    continue;
                }
            }
            
            // 如果优先选择器都没找到，尝试其他选择器
            if (!passwordInput) {
                const passwordSelectors = [
                    'input[type="password"]',
                    'input[type="password"][name*="password"]',
                    'input[type="password"][name*="pass"]',
                    'input[type="password"][id*="password"]',
                    'input[type="password"][id*="pass"]',
                    'input[type="password"][placeholder*="密碼"]',
                    'input[type="password"][placeholder*="Password"]'
                ];
                
                for (let i = 0; i < passwordSelectors.length; i++) {
                    const selector = passwordSelectors[i];
                    console.log('   Trying selector ' + (i + 1) + '/' + passwordSelectors.length + ': ' + selector);
                    try {
                        passwordInput = await {$pageVar}.$(selector);
                        if (passwordInput) {
                            passwordSelector = selector;
                            console.log('✅ Step 5 completed: Found password input with selector: ' + selector);
                            break;
                        }
                    } catch (e) {
                        console.log('   ⚠️  Selector not found, trying next...');
                        continue;
                    }
                }
            }
            
            if (passwordInput && passwordSelector) {
                console.log('✍️  Step 6: Filling password field...');
                // 使用 Puppeteer 的 type 方法，更可靠
                // $passwordJs 已经是 JSON 编码的字符串，在 JavaScript 中可以直接使用
                const passwordValue = $passwordJs;
                console.log('   Password length: ' + passwordValue.length + ' characters');
                console.log('   Clicking input field (triple click to select all)...');
                await passwordInput.click({ clickCount: 3 }); // 三击选中所有文本
                console.log('   Clearing input field...');
                await passwordInput.type('', { delay: 0 }); // 清空
                console.log('   Typing password (50ms delay per character)...');
                await passwordInput.type(passwordValue, { delay: 50 }); // 输入密码，每个字符间隔50ms
                console.log('✅ Step 6 completed: Password filled');
            } else {
                console.log('⚠️  Step 5 failed: Password input not found, trying fallback...');
                // 如果找不到，嘗試填入第一個密碼輸入框
                try {
                    console.log('   Trying first password input as fallback...');
                    const firstPasswordInput = await {$pageVar}.$('input[type="password"]');
                    if (firstPasswordInput) {
                        const passwordValue = $passwordJs;
                        console.log('   Password length: ' + passwordValue.length + ' characters');
                        await firstPasswordInput.click({ clickCount: 3 });
                        await firstPasswordInput.type('', { delay: 0 });
                        await firstPasswordInput.type(passwordValue, { delay: 50 });
                        console.log('✅ Step 6 completed: Password filled using first password input');
                    } else {
                        console.log('❌ Step 6 failed: No password input found');
                    }
                } catch (e) {
                    console.log('❌ Step 6 failed: ' + e.message);
                }
            }
            
            console.log('⏳ Waiting 500ms before next step...');
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // 先查找登入按鈕，然後在等待導航的同時點擊
            console.log('🔍 Step 7: Looking for login button...');
            console.log('   Waiting for buttons to appear (timeout: 5 seconds)...');
            
            // 先等待按钮出现
            try {
                await {$pageVar}.waitForSelector('button, input[type="submit"], a[class*="btn"]', { timeout: 5000 });
                console.log('   Buttons found, searching for login button...');
            } catch (e) {
                console.log('   ⚠️  No buttons found yet, continuing search...');
            }
            
            // 等待一下让页面完全加载
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            const loginButtonInfo = await {$pageVar}.evaluate(() => {
                // 查找所有可能的按鈕
                const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], a[class*="btn"]'));
                const loginBtn = buttons.find(btn => {
                    const text = (btn.textContent || btn.value || btn.innerText || '').trim().toLowerCase();
                    const className = (btn.className || '').toLowerCase();
                    const id = (btn.id || '').toLowerCase();
                    const type = (btn.type || '').toLowerCase();
                    
                    // 檢查是否包含登入相關的文字
                    return text.includes('登入') || 
                           text.includes('login') || 
                           text.includes('登錄') ||
                           text.includes('sign in') ||
                           type === 'submit';
                });
                
                if (loginBtn) {
                    return { 
                        found: true, 
                        tagName: loginBtn.tagName,
                        className: loginBtn.className || '',
                        id: loginBtn.id || '',
                        text: (loginBtn.textContent || loginBtn.value || '').trim()
                    };
                }
                
                // 如果找不到按鈕，檢查是否有表單
                const forms = document.querySelectorAll('form');
                if (forms.length > 0) {
                    return { found: true, hasForm: true };
                }
                
                return { found: false };
            });

            if (loginButtonInfo.found) {
                console.log('✅ Step 7 completed: Login button found');
                console.log('   Button tag: ' + (loginButtonInfo.tagName || 'N/A'));
                console.log('   Button text: ' + (loginButtonInfo.text || 'N/A'));
            } else {
                console.log('⚠️  Step 7 failed: Login button not found');
            }

            // 在等待導航的同時點擊登入按鈕
            if (loginButtonInfo.found) {
                console.log('🖱️  Step 8: Clicking login button and waiting for navigation...');
                const navigationPromise = {$pageVar}.waitForNavigation({ 
                    waitUntil: 'domcontentloaded',
                    timeout: 30000 
                }).catch(() => {
                    console.log('⚠️  Navigation timeout, waiting 3 seconds...');
                });
                
                // 點擊登入按鈕
                if (loginButtonInfo.hasForm) {
                    console.log('   Submitting form...');
                    // 提交表單
                    await {$pageVar}.evaluate(() => {
                        const forms = document.querySelectorAll('form');
                        if (forms.length > 0) {
                            forms[0].submit();
                        }
                    });
                } else {
                    console.log('   Clicking button...');
                    // 點擊按鈕
                    await {$pageVar}.evaluate(() => {
                        const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], a[class*="btn"]'));
                        const loginBtn = buttons.find(btn => {
                            const text = (btn.textContent || btn.value || btn.innerText || '').trim().toLowerCase();
                            const type = (btn.type || '').toLowerCase();
                            return text.includes('登入') || 
                                   text.includes('login') || 
                                   text.includes('登錄') ||
                                   text.includes('sign in') ||
                                   type === 'submit';
                        });
                        
                        if (loginBtn) {
                            loginBtn.click();
                        }
                    });
                }
                
                console.log('   Waiting for navigation (timeout: 30 seconds)...');
                // 等待導航完成
                await navigationPromise;
                console.log('✅ Step 8 completed: Navigation completed');
            } else {
                console.log('⚠️  Step 8 skipped: Login button not found, waiting 3 seconds...');
                await new Promise(resolve => setTimeout(resolve, 3000));
            }
            
            // 額外等待確保頁面完全載入
            console.log('⏳ Step 9: Waiting for page to fully load (2 seconds)...');
            await new Promise(resolve => setTimeout(resolve, 2000));
            console.log('✅ Step 9 completed: Page fully loaded');
            
            console.log('✅ 1BET login process completed');
        JS;
    }

    /**
     * 生成 GLC Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateGlcPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $token = env('GLC_AGENT_TOKEN', '');
        $lang = env('GLC_AGENT_LANG', 'zh-TW');
        $domain = env('GLC_AGENT_DOMAIN');

        return <<<JS
            console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            if ('$token') cookies.push({ name: 'app_admin_session', value: '$token', domain: '$domain' });
            if ('$lang') cookies.push({ name: 'lang', value: '$lang', domain: '$domain' });

            // 如果有設定 cookies，則應用到頁面
            if (cookies.length > 0) {
                await {$pageVar}.setCookie(...cookies);
                console.log('✅ Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No cookies found in environment variables');
            }
        JS;
    }

    /**
     * 生成 PGONE Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generatePgonePuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $token = env('PGONE_AGENT_TOKEN', '');
        $lang = env('PGONE_AGENT_LANG', 'zh-TW');
        $domain = env('PGONE_AGENT_DOMAIN');

        return <<<JS
            console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            if ('$token') cookies.push({ name: 'vue_admin_template_token', value: '$token', domain: '$domain' });
            if ('$lang') cookies.push({ name: 'lang', value: '$lang', domain: '$domain' });

            // 如果有設定 cookies，則應用到頁面
            if (cookies.length > 0) {
                await {$pageVar}.setCookie(...cookies);
                console.log('✅ Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No cookies found in environment variables');
            }
        JS;
    }

    /**
     * 生成 Blodplay Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateBlodplayPuppeteerLoginCode(string $pageVar = 'page'): string
    {
        $token = env('BLODPLAY_AGENT_TOKEN', '');
        $lang = env('BLODPLAY_AGENT_LANG', 'zh-TW');
        $domain = env('BLODPLAY_AGENT_DOMAIN', '');

        return <<<JS
            console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            const domain = '$domain';
            
            // 確保 domain 不為空
            if (domain && domain !== '') {
                // 清理 domain（移除協議）
                let cleanDomain = domain.replace(/^https?:\/\//, '').replace(/\/$/, '');
                
                // 構建完整的 cookie 對象（包含所有必需字段）
                if ('$token' && '$token' !== '') {
                    cookies.push({ 
                        name: '__Secure-next-auth.session-token', 
                        value: '$token', 
                        domain: cleanDomain,
                        path: '/',
                        httpOnly: true,
                        secure: true,
                        sameSite: 'Lax'
                    });
                }
                if ('$lang' && '$lang' !== '') {
                    cookies.push({ 
                        name: 'NEXT_LOCALE', 
                        value: '$lang', 
                        domain: cleanDomain,
                        path: '/',
                        httpOnly: false,
                        secure: true,
                        sameSite: 'Lax'
                    });
                }

                // 如果有設定 cookies，則應用到頁面
                if (cookies.length > 0) {
                    try {
                        await {$pageVar}.setCookie(...cookies);
                        console.log('✅ Cookies set:', cookies.length);
                    } catch (cookieError) {
                        console.error('❌ Error setting cookies:', cookieError.message);
                    }
                }
            } else {
                console.log('⚠️  Domain not configured, skipping cookie setup');
            }
        JS;
    }

    /**
     * 生成 ATGSLOT Puppeteer 使用 loginInfo 直接登入的程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @param string $loginInfoJson loginInfo 的 JSON 字符串
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateAtgslotPuppeteerLoginInfoCode(string $pageVar = 'page', string $loginInfoJson = ''): string
    {
        $domain = env('ATGSLOT_AGENT_DOMAIN', '');
        $domainJs = json_encode($domain);
        
        // 驗證並轉義 loginInfo JSON
        $loginInfo = json_decode($loginInfoJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 如果 JSON 無效，返回錯誤
            return <<<JS
                console.error('❌ Invalid loginInfo JSON format');
                throw new Error('Invalid loginInfo JSON format');
            JS;
        }
        
        $loginInfoJs = json_encode($loginInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JS
            console.log('🔐 Using loginInfo to skip login process...');
            
            // 導航到登入頁面（或直接導航到目標頁面）
            await {$pageVar}.goto($domainJs, {
                waitUntil: 'load',
                timeout: 60000
            });
            
            // 等待頁面載入
            await new Promise(resolve => setTimeout(resolve, 2000));
            
            // 將 loginInfo 設置到 localStorage
            console.log('💾 Setting loginInfo to localStorage...');
            await {$pageVar}.evaluate((loginInfo) => {
                try {
                    // 設置 loginInfo 到 localStorage
                    localStorage.setItem('loginInfo', JSON.stringify(loginInfo));
                    console.log('✅ loginInfo set to localStorage');
                    
                    // 如果有 token，也可以設置到其他地方（根據實際需求）
                    if (loginInfo.token) {
                        // 可以設置到 sessionStorage 或其他地方
                        sessionStorage.setItem('token', loginInfo.token);
                    }
                    
                    // 觸發 storage 事件，讓應用知道 localStorage 已更新
                    window.dispatchEvent(new StorageEvent('storage', {
                        key: 'loginInfo',
                        newValue: JSON.stringify(loginInfo),
                        storageArea: localStorage
                    }));
                    
                    // 如果頁面有監聽器，可能需要觸發自定義事件
                    window.dispatchEvent(new Event('loginInfoUpdated'));
                    
                    return true;
                } catch (e) {
                    console.error('❌ Error setting loginInfo:', e.message);
                    return false;
                }
            }, $loginInfoJs);
            
            // 等待一下讓頁面處理 localStorage 更新
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 刷新頁面或導航到目標頁面，讓應用讀取新的 loginInfo
            console.log('🔄 Reloading page to apply loginInfo...');
            await {$pageVar}.reload({
                waitUntil: 'load',
                timeout: 60000
            });
            
            // 等待頁面完全載入
            await new Promise(resolve => setTimeout(resolve, 3000));
            
            // 驗證 loginInfo 是否已設置
            const loginInfoSet = await {$pageVar}.evaluate(() => {
                const stored = localStorage.getItem('loginInfo');
                return stored !== null && stored !== '';
            });
            
            if (loginInfoSet) {
                console.log('✅ loginInfo successfully set and page reloaded');
            } else {
                console.log('⚠️  loginInfo may not be set correctly');
            }
            
            console.log('✅ Login process skipped using loginInfo');
        JS;
    }

    /**
     * 生成 WOW Puppeteer 使用 sessionStorage 直接登入的程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @param string $token Token 值
     * @param string|null $lang 語言設定
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateWowPuppeteerLoginInfoCode(string $pageVar = 'page'): string
    {
        $domain = env('WOW_AGENT_DOMAIN', '');
        $token = env('WOW_AGENT_TOKEN', '');
        $lang = env('WOW_AGENT_LANG', 'zh-TW');
        
        // 轉義 JavaScript 字符串，避免注入問題
        $domainJs = json_encode($domain);
        $tokenJs = json_encode($token);
        $langJs = json_encode($lang);
        
        return <<<JS
            // 導航到登入頁面
            await {$pageVar}.goto($domainJs, {
                waitUntil: 'load',
                timeout: 60000
            });
            
            // 等待頁面載入
            await new Promise(resolve => setTimeout(resolve, 2000));
            
            // 設置 sessionStorage 中的 dashboardToken
            await {$pageVar}.evaluate((token) => {
                console.log('💾 Setting dashboardToken to sessionStorage...');
                try {
                    sessionStorage.setItem('dashboardToken', token);
                    
                    window.dispatchEvent(new StorageEvent('storage', {
                        key: 'dashboardToken',
                        newValue: token,
                        oldValue: null,
                        storageArea: sessionStorage
                    }));
                    
                    window.dispatchEvent(new Event('sessionStorageUpdated'));
                    
                    return true;
                } catch (e) {
                    console.error('❌ Error setting sessionStorage:', e.message);
                    return false;
                }
            }, $tokenJs);
            
            // 設置 cookie 中的 site_lang（如果提供了語言設定）
            const siteLang = $langJs && $langJs !== 'null' ? $langJs.replace(/^"|"\$/g, '') : null;
            if (siteLang && siteLang !== '') {
                try {
                    const currentUrl = {$pageVar}.url();
                    const urlObj = new URL(currentUrl);
                    const domain = urlObj.hostname;
                    
                    await {$pageVar}.setCookie({
                        name: 'site_lang',
                        value: siteLang,
                        domain: domain,
                        path: '/'
                    });
                } catch (e) {
                    console.log('⚠️  Error setting cookie: ' + e.message);
                }
            }
            
            // 等待一下讓頁面處理 sessionStorage 更新
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 刷新頁面讓應用讀取新的 sessionStorage 和 cookie
            await {$pageVar}.reload({
                waitUntil: 'networkidle2',
                timeout: 60000
            });
            
            // 等待頁面完全載入
            await new Promise(resolve => setTimeout(resolve, 3000));
            
            // 驗證 sessionStorage 是否已設置
            const sessionStorageSet = await {$pageVar}.evaluate(() => {
                const token = sessionStorage.getItem('dashboardToken');
                return token !== null && token !== '';
            });
            
            console.log('✅ WOW login process completed using sessionStorage and cookies');
        JS;
    }
}
