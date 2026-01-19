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

    /**
     * 生成 1BET Puppeteer 登入流程程式碼片段（包含截圖和重定向）
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @param string $workingDirJs 工作目錄的 JSON 編碼（用於截圖路徑）
     * @param string $redirectUrlJs 重定向 URL 的 JSON 編碼
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generate1BetPuppeteerLoginCode(string $pageVar = 'page', string $workingDirJs = 'null', string $redirectUrlJs = 'null'): string
    {
        $account = env('1BET_AGENT_ACCOUNT', '');
        $password = env('1BET_AGENT_PASSWORD', '');
        
        // 轉義 JavaScript 字符串，避免注入問題
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);

        return <<<JS
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
            const accountInputFound = await {$pageVar}.evaluate((accountValue) => {
                // 查找 input 框：placeholder="Please enter account number" 且 class="el-input__inner"
                const input = document.querySelector('input.el-input__inner[placeholder="Please enter account number"]');
                
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
                console.log('⚠️  Account input field not found with placeholder "Please enter account number"');
            }
            
            // 等待一下讓輸入完成
            await new Promise(resolve => setTimeout(resolve, 500));
            
            // 截圖 2: 填入帳號後
            if ($workingDirJs && $workingDirJs !== 'null') {
                console.log('📸 Step 1: Taking screenshot after account filled...');
                const screenshot1Path = path.join($workingDirJs, '1bet_step1_account_filled.png');
                await {$pageVar}.screenshot({
                    path: screenshot1Path,
                    fullPage: true
                });
                console.log('✅ Screenshot saved: ' + screenshot1Path);
            }
            
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
            const passwordInputFound = await {$pageVar}.evaluate((passwordValue) => {
                // 查找密碼 input 框：placeholder="password" 且 class="el-input__inner"
                const input = document.querySelector('input.el-input__inner[placeholder="password"]');
                
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
                console.log('⚠️  Password input field not found with placeholder "password"');
                // 嘗試查找其他可能的選擇器
                const alternativeInput = await {$pageVar}.evaluate(() => {
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
            if ($workingDirJs && $workingDirJs !== 'null') {
                console.log('📸 Step 2: Taking screenshot after password filled and marked...');
                const screenshot2Path = path.join($workingDirJs, '1bet_step2_password_filled.png');
                await {$pageVar}.screenshot({
                    path: screenshot2Path,
                    fullPage: true
                });
                console.log('✅ Screenshot saved: ' + screenshot2Path);
            }
            
            // 步驟 3: 點擊登錄按鈕
            console.log('🔍 Step 3: Looking for login button...');
            
            // 先等待一下讓按鈕完全渲染
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 獲取頁面上所有按鈕的調試信息
            const buttonDebugInfo = await {$pageVar}.evaluate(() => {
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
            
            const loginButtonClicked = await {$pageVar}.evaluate(() => {
                // 方法 1: 優先查找完整選擇器
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
                if ($workingDirJs && $workingDirJs !== 'null') {
                    console.log('📸 Step 3: Taking screenshot after login button clicked...');
                    const screenshot3Path = path.join($workingDirJs, '1bet_step3_login_clicked.png');
                    await {$pageVar}.screenshot({
                        path: screenshot3Path,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshot3Path);
                }
                
                // 記錄點擊前的 URL
                const urlBeforeLogin = {$pageVar}.url();
                console.log('   URL before login: ' + urlBeforeLogin);
                
                // 等待頁面響應（登錄後可能會有頁面跳轉或載入）
                console.log('⏳ Waiting for login response and page navigation...');
                
                try {
                    // 等待頁面導航完成（最多等待 10 秒）
                    await {$pageVar}.waitForNavigation({
                        waitUntil: 'networkidle2',
                        timeout: 10000
                    }).catch(() => {
                        console.log('   Navigation wait timeout, continuing...');
                    });
                    
                    // 檢查 URL 是否變化
                    const urlAfterLogin = {$pageVar}.url();
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
                const pageContent = await {$pageVar}.evaluate(() => {
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
                        
                        const checkStatus = await {$pageVar}.evaluate(() => {
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
                        console.log('🔄 Using JavaScript to navigate (avoiding detection)...');
                        
                        // 使用 JavaScript 直接修改 window.location，避免被檢測為自動化工具
                        await {$pageVar}.evaluate((targetUrl) => {
                            window.location.href = targetUrl;
                        }, redirectUrlParsed);
                        
                        // 等待頁面導航
                        console.log('⏳ Waiting for page navigation...');
                        await {$pageVar}.waitForNavigation({
                            waitUntil: 'networkidle2',
                            timeout: 30000
                        }).catch(() => {
                            console.log('   Navigation wait timeout, but continuing...');
                        });
                        
                        // 等待頁面完全載入
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        
                        // 檢查新頁面狀態
                        const redirectPageContent = await {$pageVar}.evaluate(() => {
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
                        
                        // 檢查是否被重定向到反開發者工具頁面
                        if (redirectPageContent.url.includes('disable-devtool') || 
                            redirectPageContent.url.includes('theajack.github.io') ||
                            redirectPageContent.title.includes('Not allowed')) {
                            console.log('⚠️  Detected anti-devtool page, trying alternative navigation method...');
                            
                            // 嘗試使用 window.location.replace
                            await {$pageVar}.evaluate((targetUrl) => {
                                window.location.replace(targetUrl);
                            }, redirectUrlParsed);
                            
                            await new Promise(resolve => setTimeout(resolve, 3000));
                            
                            // 再次檢查
                            const retryPageContent = await {$pageVar}.evaluate(() => {
                                return {
                                    url: window.location.href,
                                    title: document.title
                                };
                            });
                            
                            console.log('   Retry URL: ' + retryPageContent.url);
                            console.log('   Retry title: ' + retryPageContent.title);
                        }
                    } catch (e) {
                        console.log('⚠️  Redirect navigation failed: ' + e.message);
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    }
                } else if (isBlackScreen) {
                    console.log('⚠️  Black screen detected but no redirect URL configured');
                }
                
                // 截圖 5: 登錄完成後（或重定向後）
                if ($workingDirJs && $workingDirJs !== 'null') {
                    console.log('📸 Step 4: Taking screenshot after login completed (or redirected)...');
                    const screenshot4Path = path.join($workingDirJs, '1bet_step4_login_completed.png');
                    await {$pageVar}.screenshot({
                        path: screenshot4Path,
                        fullPage: true
                    });
                    console.log('✅ Screenshot saved: ' + screenshot4Path);
                }
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
                    const selectors = [
                        'button.el-button.btn-login.el-button--primary.el-button--small',
                        'button.btn-login',
                        'button[class*="btn-login"]',
                        'button.el-button--primary'
                    ];
                    
                    let clicked = false;
                    for (const selector of selectors) {
                        try {
                            const button = await {$pageVar}.$(selector);
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
                    
                    if (clicked) {
                        await new Promise(resolve => setTimeout(resolve, 500));
                        
                        if ($workingDirJs && $workingDirJs !== 'null') {
                            console.log('📸 Step 3: Taking screenshot after login button clicked...');
                            const screenshot3Path = path.join($workingDirJs, '1bet_step3_login_clicked.png');
                            await {$pageVar}.screenshot({
                                path: screenshot3Path,
                                fullPage: true
                            });
                            console.log('✅ Screenshot saved: ' + screenshot3Path);
                        }
                        
                        const urlBeforeLogin = {$pageVar}.url();
                        console.log('   URL before login: ' + urlBeforeLogin);
                        
                        console.log('⏳ Waiting for login response and page navigation...');
                        
                        try {
                            await {$pageVar}.waitForNavigation({
                                waitUntil: 'networkidle2',
                                timeout: 10000
                            }).catch(() => {
                                console.log('   Navigation wait timeout, continuing...');
                            });
                            
                            const urlAfterLogin = {$pageVar}.url();
                            console.log('   URL after login: ' + urlAfterLogin);
                            
                            if (urlAfterLogin !== urlBeforeLogin) {
                                console.log('✅ Page navigated after login');
                            }
                        } catch (e) {
                            console.log('⚠️  Navigation error: ' + e.message);
                        }
                        
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        const pageContent = await {$pageVar}.evaluate(() => {
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
                                
                                const checkStatus = await {$pageVar}.evaluate(() => {
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
                                console.log('🔄 Using JavaScript to navigate (avoiding detection)...');
                                
                                await {$pageVar}.evaluate((targetUrl) => {
                                    window.location.href = targetUrl;
                                }, redirectUrlParsed);
                                
                                console.log('⏳ Waiting for page navigation...');
                                await {$pageVar}.waitForNavigation({
                                    waitUntil: 'networkidle2',
                                    timeout: 30000
                                }).catch(() => {
                                    console.log('   Navigation wait timeout, but continuing...');
                                });
                                
                                await new Promise(resolve => setTimeout(resolve, 3000));
                                
                                const redirectPageContent = await {$pageVar}.evaluate(() => {
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
                                
                                if (redirectPageContent.url.includes('disable-devtool') || 
                                    redirectPageContent.url.includes('theajack.github.io') ||
                                    redirectPageContent.title.includes('Not allowed')) {
                                    console.log('⚠️  Detected anti-devtool page, trying alternative navigation method...');
                                    
                                    await {$pageVar}.evaluate((targetUrl) => {
                                        window.location.replace(targetUrl);
                                    }, redirectUrlParsed);
                                    
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                    
                                    const retryPageContent = await {$pageVar}.evaluate(() => {
                                        return {
                                            url: window.location.href,
                                            title: document.title
                                        };
                                    });
                                    
                                    console.log('   Retry URL: ' + retryPageContent.url);
                                    console.log('   Retry title: ' + retryPageContent.title);
                                }
                            } catch (e) {
                                console.log('⚠️  Redirect navigation failed: ' + e.message);
                                await new Promise(resolve => setTimeout(resolve, 2000));
                            }
                        } else if (isBlackScreen) {
                            console.log('⚠️  Black screen detected but no redirect URL configured');
                        }
                        
                        if ($workingDirJs && $workingDirJs !== 'null') {
                            console.log('📸 Step 4: Taking screenshot after login completed (or redirected)...');
                            const screenshot4Path = path.join($workingDirJs, '1bet_step4_login_completed.png');
                            await {$pageVar}.screenshot({
                                path: screenshot4Path,
                                fullPage: true
                            });
                            console.log('✅ Screenshot saved: ' + screenshot4Path);
                        }
                    } else {
                        console.log('   ⚠️  Could not click button using Puppeteer methods');
                    }
                } catch (e) {
                    console.log('⚠️  Error trying Puppeteer click: ' + e.message);
                }
            }
            
            console.log('✅ 1BET login process completed');
        JS;
    }
}
