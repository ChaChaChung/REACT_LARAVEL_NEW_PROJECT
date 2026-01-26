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
            
            // 重試機制：如果找不到登入表單，重新整理頁面
            let loginFormFound = false;
            let refreshAttempts = 0;
            const maxRefreshAttempts = 5;
            
            while (!loginFormFound && refreshAttempts < maxRefreshAttempts) {
                if (refreshAttempts > 0) {
                    await {$pageVar}.reload({ waitUntil: 'domcontentloaded', timeout: 15000 });
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 重新注入防護代碼
                    await {$pageVar}.evaluate(() => {
                        try {
                            const mock = { isSuspend: true, init: () => {}, suspend: () => {}, resume: () => {}, md5: (s) => s, version: '0.3.7' };
                            Object.defineProperty(window, 'DisableDevtool', { get: () => mock, set: () => {}, configurable: false });
                            Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                            Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                            const oConstructor = Function.prototype.constructor;
                            Function.prototype.constructor = function(str) {
                                if (str && (str.includes('debugger') || str.includes('debug'))) return function() {};
                                return oConstructor.apply(this, arguments);
                            };
                            const oRegExpToString = RegExp.prototype.toString;
                            RegExp.prototype.toString = function() {
                                if (this.source === '(?=a)b') return 'function RegExp() { [native code] }';
                                return oRegExpToString.call(this);
                            };
                        } catch (e) {}
                    });
                }
                
                // 檢查登入表單是否存在
                const formCheck = await {$pageVar}.evaluate(() => {
                    const accountInput = document.querySelector('input.el-input__inner[placeholder="Please enter account number"]');
                    const passwordInput = document.querySelector('input.el-input__inner[placeholder="password"]') || 
                                         document.querySelector('input[type="password"].el-input__inner');
                    return {
                        hasAccountInput: !!accountInput,
                        hasPasswordInput: !!passwordInput,
                        hasLoginButton: !!document.querySelector('button.btn-login, button[class*="btn-login"], a.btn-login')
                    };
                });
                
                if (formCheck.hasAccountInput && formCheck.hasPasswordInput) {
                    loginFormFound = true;
                } else {
                    refreshAttempts++;
                }
            }
            
            // 步驟 1: 查找並填入帳號 input（不標記）
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
            
            // 步驟 2: 查找並填入密碼
            const passwordInputFound = await {$pageVar}.evaluate((passwordValue) => {
                const input = document.querySelector('input.el-input__inner[placeholder="password"]');
                if (input) {
                    if (passwordValue && passwordValue !== null && passwordValue !== '') {
                        input.value = passwordValue;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return { found: true, placeholder: input.placeholder, className: input.className, type: input.type, value: input.value ? '***' : '' };
                }
                return { found: false };
            }, passwordParsed);
            
            // 步驟 3: 點擊登錄按鈕
            let loginButtonClicked = { clicked: false };
            for (let attempt = 0; attempt < 3; attempt++) {
                if (attempt === 1) {
                    await new Promise(resolve => setTimeout(resolve, 400));
                    await {$pageVar}.waitForSelector('button, a.btn-login, a[class*="btn-login"], a.el-button--primary, input[type="submit"]', { timeout: 6000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 300));
                    const buttonDebugInfo = await {$pageVar}.evaluate(() => {
                        const all = Array.from(document.querySelectorAll('button, a.btn-login, a[class*="btn-login"], a.el-button--primary'));
                        return all.map(btn => ({ text: btn.textContent.trim(), className: btn.className, visible: window.getComputedStyle(btn).display !== 'none' && btn.offsetParent !== null }));
                    });
                }
                loginButtonClicked = await {$pageVar}.evaluate(() => {
                // 查找完整選擇器
                let button = document.querySelector('button.el-button.btn-login.el-button--primary.el-button--small');
                
                if (button) {
                    // 檢查按鈕是否可見
                    const style = window.getComputedStyle(button);
                    const isVisible = style.display !== 'none' && 
                                    style.visibility !== 'hidden' && 
                                    style.opacity !== '0' &&
                                    button.offsetParent !== null;
                    
                    if (!isVisible) {
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
                if (loginButtonClicked.clicked) break;
                if (attempt < 2) { console.log('   Login button not found, retrying in 2s (body may have been empty)...'); await new Promise(r => setTimeout(r, 2000)); }
            }
            if (loginButtonClicked.clicked) {
                console.log('✅ Login button clicked!');
                
                // 等待一下讓點擊生效
                await new Promise(resolve => setTimeout(resolve, 500));
                
                // 記錄點擊前的 URL
                const urlBeforeLogin = {$pageVar}.url();
                console.log('   URL before login: ' + urlBeforeLogin);
                
                // 等待頁面響應（登錄後可能會有頁面跳轉或載入）
                console.log('⏳ Waiting for login response and page navigation...');
                
                try {
                    // 等待登入響應：導航、SPA hash 變化或特定元素出現
                    await Promise.race([
                        {$pageVar}.waitForNavigation({ waitUntil: 'networkidle2', timeout: 8000 }),
                        {$pageVar}.waitForSelector('input[placeholder="Please enter player account"]', { timeout: 10000 }),
                        {$pageVar}.waitForSelector('i.el-icon-switch-button, .btn-logout, .user-info', { timeout: 10000 }),
                        new Promise(resolve => setTimeout(resolve, 5000))
                    ]).catch(() => console.log('   Wait for login response timed out, checking status...'));

                    const urlAfterLogin = {$pageVar}.url();
                    console.log('   URL after login: ' + urlAfterLogin);
                } catch (e) {
                    console.log('⚠️  Navigation error: ' + e.message);
                }
                // 登入後立即重新注入防護代碼（防止新頁面載入 disable-devtool）
                console.log('🛡️ Re-injecting anti-detection code after login...');
                await {$pageVar}.evaluate(() => {
                    // 重新禁用 disable-devtool
                    try {
                        // 1. 完全禁用 DisableDevtool
                        Object.defineProperty(window, 'DisableDevtool', {
                            get: () => undefined,
                            set: () => {},
                            configurable: false
                        });
                        
                        // 2. 偽造視窗尺寸
                        Object.defineProperty(window, 'outerWidth', {
                            get: () => window.innerWidth
                        });
                        Object.defineProperty(window, 'outerHeight', {
                            get: () => window.innerHeight
                        });
                        
                        console.log('🛡️ Anti-detection re-injection complete');
                    } catch (e) {
                        console.log('⚠️ Error re-injecting protection:', e.message);
                    }
                });
                
                // 登入後 #/player 常為非同步載入，需較長時間才能渲染，避免誤判黑屏
                console.log('⏳ Waiting for page content to load (#/player may load slowly)...');
                await new Promise(resolve => setTimeout(resolve, 4000));
                await new Promise(resolve => setTimeout(resolve, 12000));
                let pageContent = await {$pageVar}.evaluate(() => {
                    const bodyText = document.body ? document.body.innerText : '';
                    const currentUrl = window.location.href;
                    const pageTitle = document.title || '';
                    return {
                        url: currentUrl,
                        title: pageTitle,
                        bodyText: bodyText,
                        bodyTextLength: bodyText.length,
                        hasContent: document.body && bodyText.length > 0,
                        hasSecurityCheck: bodyText.includes('What Are You Looking For') || 
                                        bodyText.includes('security check') ||
                                        pageTitle.includes('What Are You Looking For'),
                        isDisableDevtoolPage: currentUrl.includes('disable-devtool') || 
                                             currentUrl.includes('theajack.github.io') ||
                                             currentUrl.includes('chrome-error://') ||
                                             pageTitle.includes('theajack.github.io') ||
                                             pageTitle.includes('Not allowed') ||
                                             bodyText.includes('Not allowed') ||
                                             bodyText.includes('What Are You Looking For')
                    };
                });
                
                // 檢測到 disable-devtool 頁面，立即處理
                if (pageContent.isDisableDevtoolPage) {
                    console.log('⚠️ Detected disable-devtool redirect page!');
                    console.log('   Current URL: ' + pageContent.url);
                    console.log('   Page title: ' + pageContent.title);
                    
                    // 解析重定向 URL（如果有的話）
                    let targetRedirectUrl = null;
                    try {
                        if ($redirectUrlJs && $redirectUrlJs !== 'null' && $redirectUrlJs !== '') {
                            targetRedirectUrl = JSON.parse($redirectUrlJs);
                        }
                    } catch (e) {
                        targetRedirectUrl = $redirectUrlJs !== 'null' ? $redirectUrlJs.replace(/^"|"\$/g, '') : null;
                    }
                    
                    // 如果有重定向 URL，直接導航到那裡，否則嘗試返回
                    if (targetRedirectUrl && targetRedirectUrl !== null && targetRedirectUrl !== '') {
                        console.log('   Navigating directly to target URL: ' + targetRedirectUrl);
                        try {
                            await {$pageVar}.goto(targetRedirectUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        } catch (e) {
                            console.log('⚠️ Failed to navigate to target URL:', e.message);
                        }
                    } else {
                        console.log('   Attempting to navigate back...');
                        try {
                            // 嘗試返回前一頁
                            await {$pageVar}.goBack({ waitUntil: 'domcontentloaded', timeout: 5000 });
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        } catch (e) {
                            console.log('⚠️ Failed to navigate back:', e.message);
                        }
                    }
                    
                    // 重新注入防護並檢查頁面
                    await {$pageVar}.evaluate(() => {
                        try {
                            const mock = { isSuspend: true, init: () => {}, suspend: () => {}, resume: () => {}, md5: (s) => s, version: '0.3.7' };
                            Object.defineProperty(window, 'DisableDevtool', { get: () => mock, set: () => {}, configurable: false });
                            Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                            Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                            const oConstructor = Function.prototype.constructor;
                            Function.prototype.constructor = function(str) {
                                if (str && (str.includes('debugger') || str.includes('debug'))) return function() {};
                                return oConstructor.apply(this, arguments);
                            };
                            const oRegExpToString = RegExp.prototype.toString;
                            RegExp.prototype.toString = function() {
                                if (this.source === '(?=a)b') return 'function RegExp() { [native code] }';
                                return oRegExpToString.call(this);
                            };
                            console.log('🛡️ Re-injected protection after recovery');
                        } catch (e) {}
                    });
                    
                    // 重新檢查頁面
                    pageContent = await {$pageVar}.evaluate(() => {
                        const bodyText = document.body ? document.body.innerText : '';
                        const currentUrl = window.location.href;
                        const pageTitle = document.title || '';
                        return {
                            url: currentUrl,
                            title: pageTitle,
                            bodyText: bodyText,
                            bodyTextLength: bodyText.length,
                            hasContent: document.body && bodyText.length > 0,
                            hasSecurityCheck: bodyText.includes('What Are You Looking For') || pageTitle.includes('What Are You Looking For'),
                            isDisableDevtoolPage: currentUrl.includes('disable-devtool') || 
                                                 currentUrl.includes('theajack.github.io') ||
                                                 currentUrl.includes('chrome-error://') ||
                                                 pageTitle.includes('theajack.github.io')
                        };
                    });
                    
                    console.log('✅ Recovery complete, new URL: ' + pageContent.url);
                    console.log('   New title: ' + pageContent.title);
                    console.log('   Still on disable-devtool: ' + pageContent.isDisableDevtoolPage);
                }
                if (!pageContent.hasContent || pageContent.bodyTextLength < 100) {
                    console.log('   Page still empty, waiting 5s more for #/player to render...');
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    pageContent = await {$pageVar}.evaluate(() => {
                        const bodyText = document.body ? document.body.innerText : '';
                        const currentUrl = window.location.href;
                        return {
                            url: currentUrl,
                            title: document.title,
                            bodyText: bodyText,
                            bodyTextLength: bodyText.length,
                            hasContent: document.body && bodyText.length > 0,
                            hasSecurityCheck: bodyText.includes('What Are You Looking For') || 
                                            bodyText.includes('security check') || 
                                            document.title.includes('What Are You Looking For'),
                            isDisableDevtoolPage: currentUrl.includes('disable-devtool') || 
                                                 currentUrl.includes('theajack.github.io') ||
                                                 bodyText.includes('Not allowed')
                        };
                    });
                }
                if (pageContent.hasSecurityCheck || pageContent.isDisableDevtoolPage) {
                    // 等待安全驗證完成（最多等待 10 秒）
                    let securityCheckPassed = false;
                    for (let i = 0; i < 10; i++) {
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        const checkStatus = await {$pageVar}.evaluate(() => {
                            const bodyText = document.body ? document.body.innerText : '';
                            const currentUrl = window.location.href;
                            const pageTitle = document.title || '';
                            return {
                                hasSecurityCheck: bodyText.includes('What Are You Looking For') || 
                                                 pageTitle.includes('What Are You Looking For'),
                                isDisableDevtoolPage: currentUrl.includes('disable-devtool') || 
                                                     currentUrl.includes('theajack.github.io') ||
                                                     currentUrl.includes('chrome-error://') ||
                                                     pageTitle.includes('theajack.github.io'),
                                url: currentUrl,
                                title: pageTitle
                            };
                        });
                        
                        // 如果檢測到 disable-devtool 頁面，立即嘗試恢復
                        if (checkStatus.isDisableDevtoolPage) {
                            // 嘗試導航到重定向 URL
                            let recoveryUrl = null;
                            try {
                                if ($redirectUrlJs && $redirectUrlJs !== 'null' && $redirectUrlJs !== '') {
                                    recoveryUrl = JSON.parse($redirectUrlJs);
                                }
                            } catch (e) {
                                recoveryUrl = $redirectUrlJs !== 'null' ? $redirectUrlJs.replace(/^"|"\$/g, '') : null;
                            }
                            
                            if (recoveryUrl && recoveryUrl !== null && recoveryUrl !== '') {
                                try {
                                    await {$pageVar}.goto(recoveryUrl, { waitUntil: 'domcontentloaded', timeout: 10000 });
                                    await new Promise(resolve => setTimeout(resolve, 2000));
                                    
                                    // 重新注入防護
                                    await {$pageVar}.evaluate(() => {
                                        try {
                                            const mock = { isSuspend: true, init: () => {}, suspend: () => {}, resume: () => {}, md5: (s) => s, version: '0.3.7' };
                                            Object.defineProperty(window, 'DisableDevtool', { get: () => mock, set: () => {}, configurable: false });
                                            Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                                            Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                                            const oConstructor = Function.prototype.constructor;
                                            Function.prototype.constructor = function(str) {
                                                if (str && (str.includes('debugger') || str.includes('debug'))) return function() {};
                                                return oConstructor.apply(this, arguments);
                                            };
                                            const oRegExpToString = RegExp.prototype.toString;
                                            RegExp.prototype.toString = function() {
                                                if (this.source === '(?=a)b') return 'function RegExp() { [native code] }';
                                                return oRegExpToString.call(this);
                                            };
                                        } catch (e) {}
                                    });
                                    
                                    continue; // 重新檢查
                                } catch (e) {
                                    console.log('   Recovery navigation failed:', e.message);
                                }
                            }
                        }
                        
                        if (!checkStatus.hasSecurityCheck && !checkStatus.isDisableDevtoolPage) {
                            securityCheckPassed = true;
                            console.log('✅ Security check passed, URL: ' + checkStatus.url);
                            break;
                        }
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
                
                if (redirectUrlParsed && redirectUrlParsed !== null && redirectUrlParsed !== '') {
                    try {
                        // 在 Node 端判斷：同 origin 且目標有 hash 時，只改 location.hash，避免整頁重載、減少觸發 anti-devtool
                        // 黑屏時 pageContent.url 常為空，改優先用 page.url() 取得目前 URL，避免 new URL('') 拋錯導致誤走 full nav
                        let hashPart = null;
                        try {
                            var ru = new URL(redirectUrlParsed);
                            var curUrl = (pageContent && pageContent.url) || '';
                            if (!curUrl) { try { curUrl = await {$pageVar}.url(); } catch (e2) {} }
                            if (curUrl) {
                                var cu = new URL(curUrl);
                                if (ru.origin === cu.origin) {
                                    if (ru.hash && ru.hash.length > 1) hashPart = ru.hash;
                                    else if (redirectUrlParsed.indexOf('#') >= 0) { var h = redirectUrlParsed.substring(redirectUrlParsed.indexOf('#')); if (h && h.length > 1) hashPart = h; }
                                }
                            }
                        } catch (e) {}
                        if (hashPart) {
                            console.log('   Using hash-only navigation (SPA) to avoid full reload...');
                            await {$pageVar}.evaluate((h) => { window.location.hash = h; }, hashPart);
                            await new Promise(resolve => setTimeout(resolve, 4000));
                        } else {
                            await {$pageVar}.evaluate((targetUrl) => { window.location.href = targetUrl; }, redirectUrlParsed);
                            console.log('⏳ Waiting for page navigation...');
                            await {$pageVar}.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => { console.log('   Navigation wait timeout, but continuing...'); });
                            await new Promise(resolve => setTimeout(resolve, 3000));
                        }
                        
                        // 重定向後重新注入防護代碼
                        console.log('🛡️ Re-injecting protection after redirect...');
                        await {$pageVar}.evaluate(() => {
                            try {
                                const mock = { isSuspend: true, init: () => {}, suspend: () => {}, resume: () => {}, md5: (s) => s, version: '0.3.7' };
                                Object.defineProperty(window, 'DisableDevtool', { get: () => mock, set: () => {}, configurable: false });
                                Object.defineProperty(window, 'outerWidth', { get: () => window.innerWidth });
                                Object.defineProperty(window, 'outerHeight', { get: () => window.innerHeight });
                                const oConstructor = Function.prototype.constructor;
                                Function.prototype.constructor = function(str) {
                                    if (str && (str.includes('debugger') || str.includes('debug'))) return function() {};
                                    return oConstructor.apply(this, arguments);
                                };
                                const oRegExpToString = RegExp.prototype.toString;
                                RegExp.prototype.toString = function() {
                                    if (this.source === '(?=a)b') return 'function RegExp() { [native code] }';
                                    return oRegExpToString.call(this);
                                };
                            } catch (e) {}
                        });
                        const redirectPageContent = await {$pageVar}.evaluate(() => {
                            return {
                                url: window.location.href,
                                title: document.title,
                                bodyText: document.body ? document.body.innerText.length : 0,
                                hasContent: document.body && document.body.innerText.length > 0
                            };
                        });
                        
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
            } else {
                console.log('⚠️  Login button not found');
                if (loginButtonClicked.reason) {
                    console.log('   Reason: ' + loginButtonClicked.reason);
                    if (loginButtonClicked.buttonText) {
                        console.log('   Button text found: ' + loginButtonClicked.buttonText);
                    }
                }
                const loginDebug = await {$pageVar}.evaluate(() => {
                    const q = (s) => document.querySelectorAll(s).length;
                    return {
                        button: q('button'),
                        aBtn: q('a.btn-login') + q('a[class*="btn-login"]') + q('a.el-button--primary'),
                        divSpanBtn: q('div.el-button') + q('span.el-button') + q('div[class*="el-button"]'),
                        iframes: document.querySelectorAll('iframe').length,
                        bodyLen: document.body ? document.body.innerText.length : 0
                    };
                });
                console.log('   Debug: <button>=' + loginDebug.button + ' a(btn-login/el-button--primary)=' + loginDebug.aBtn + ' div/span.el-button=' + loginDebug.divSpanBtn + ' iframes=' + loginDebug.iframes + ' bodyLen=' + loginDebug.bodyLen);
                if (loginDebug.iframes > 0) {
                    console.log('   ⚠️  Page has iframes – 登入按鈕若在 iframe 內，目前只搜主頁，需改為切到該 frame 再找');
                }
                if (loginDebug.button === 0 && loginDebug.aBtn === 0 && loginDebug.divSpanBtn === 0) {
                    console.log('   ⚠️  主頁沒有 button/a/div 登入控制項，可能：尚未渲染（可加長 wait）、在 iframe、或為其他標籤');
                }
                // 嘗試使用 Puppeteer 的 click 方法
                console.log('   Trying Puppeteer click method...');
                try {
                    const selectors = [
                        'button.el-button.btn-login.el-button--primary.el-button--small',
                        'button.btn-login',
                        'button[class*="btn-login"]',
                        'button.el-button--primary',
                        'a.btn-login',
                        'a[class*="btn-login"]',
                        'a.el-button--primary',
                        'input[type="submit"]',
                        'div.btn-login',
                        'div[class*="btn-login"]',
                        'div.el-button--primary',
                        'span.el-button--primary'
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
                         
                        const urlBeforeLogin = {$pageVar}.url();
                        console.log('   URL before login: ' + urlBeforeLogin);
                        
                        console.log('⏳ Waiting for login response and page navigation...');
                        
                        try {
                            await Promise.race([
                                {$pageVar}.waitForNavigation({ waitUntil: 'networkidle2', timeout: 8000 }),
                                {$pageVar}.waitForSelector('input[placeholder="Please enter player account"]', { timeout: 10000 }),
                                {$pageVar}.waitForSelector('i.el-icon-switch-button, .btn-logout, .user-info', { timeout: 10000 }),
                                new Promise(resolve => setTimeout(resolve, 5000))
                            ]).catch(() => console.log('   Wait for login response timed out, checking status...'));
                            
                            const urlAfterLogin = {$pageVar}.url();
                            console.log('   URL after login: ' + urlAfterLogin);
                            if (urlAfterLogin !== urlBeforeLogin) console.log('✅ Page navigated after login');
                        } catch (e) {
                            console.log('⚠️  Navigation error: ' + e.message);
                        }
                        await new Promise(resolve => setTimeout(resolve, 4000));
                        await new Promise(resolve => setTimeout(resolve, 12000));
                        let pageContent = await {$pageVar}.evaluate(() => {
                            const bodyText = document.body ? document.body.innerText : '';
                            return {
                                url: window.location.href,
                                title: document.title,
                                bodyText: bodyText,
                                bodyTextLength: bodyText.length,
                                hasContent: document.body && bodyText.length > 0,
                                hasSecurityCheck: bodyText.includes('What Are You Looking For') || bodyText.includes('security check') || document.title.includes('What Are You Looking For')
                            };
                        });
                        if (!pageContent.hasContent || pageContent.bodyTextLength < 100) {
                            console.log('   Page still empty, waiting 5s more for #/player to render...');
                            await new Promise(resolve => setTimeout(resolve, 5000));
                            pageContent = await {$pageVar}.evaluate(() => {
                                const bodyText = document.body ? document.body.innerText : '';
                                return {
                                    url: window.location.href,
                                    title: document.title,
                                    bodyText: bodyText,
                                    bodyTextLength: bodyText.length,
                                    hasContent: document.body && bodyText.length > 0,
                                    hasSecurityCheck: bodyText.includes('What Are You Looking For') || bodyText.includes('security check') || document.title.includes('What Are You Looking For')
                                };
                            });
                            console.log('   After retry: hasContent=' + pageContent.hasContent + ' bodyTextLength=' + pageContent.bodyTextLength);
                        }
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
                                let hashPart = null;
                                try {
                                    var ru = new URL(redirectUrlParsed);
                                    var curUrl = (pageContent && pageContent.url) || '';
                                    if (!curUrl) { try { curUrl = await {$pageVar}.url(); } catch (e2) {} }
                                    if (curUrl) {
                                        var cu = new URL(curUrl);
                                        if (ru.origin === cu.origin) {
                                            if (ru.hash && ru.hash.length > 1) hashPart = ru.hash;
                                            else if (redirectUrlParsed.indexOf('#') >= 0) { var h = redirectUrlParsed.substring(redirectUrlParsed.indexOf('#')); if (h && h.length > 1) hashPart = h; }
                                        }
                                    }
                                } catch (e) {}
                                if (hashPart) {
                                    console.log('   Using hash-only navigation (SPA) to avoid full reload...');
                                    await {$pageVar}.evaluate((h) => { window.location.hash = h; }, hashPart);
                                    await new Promise(resolve => setTimeout(resolve, 4000));
                                } else {
                                    await {$pageVar}.evaluate((targetUrl) => { window.location.href = targetUrl; }, redirectUrlParsed);
                                    console.log('⏳ Waiting for page navigation...');
                                    await {$pageVar}.waitForNavigation({ waitUntil: 'networkidle2', timeout: 30000 }).catch(() => { console.log('   Navigation wait timeout, but continuing...'); });
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                }
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
                                    await {$pageVar}.evaluate((targetUrl) => { window.location.replace(targetUrl); }, redirectUrlParsed);
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                    const retryPageContent = await {$pageVar}.evaluate(() => { return { url: window.location.href, title: document.title }; });
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

    /**
     * 生成 OMG Puppeteer 使用 localStorage 直接登入的程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateOmgPuppeteerLoginInfoCode(string $pageVar = 'page'): string
    {
        $domain = env('OMG_AGENT_DOMAIN', '');
        $loginInfo = env('OMG_AGENT_LOGIN_INFO', '');
        $lang = env('OMG_AGENT_LANG', '{"value":"en-US"}');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $loginInfoJs = json_encode($loginInfo); // 注意：這裡是 JSON string 的 string，所以 encode 一次變 "string"
        $langJs = json_encode($lang);

        return <<<JS
            // 導航到登入頁面
            let omgTargetUrl = $domainJs;
            if (!omgTargetUrl.startsWith('http')) {
                omgTargetUrl = 'https://' + omgTargetUrl;
            }
            
            console.log('🌐 Navigating to login page: ' + omgTargetUrl);
            await {$pageVar}.goto(omgTargetUrl, {
                waitUntil: 'networkidle2',
                timeout: 60000
            });
            
            // 等待頁面載入
            await new Promise(resolve => setTimeout(resolve, 2000));
            
            // 設置 localStorage
            await {$pageVar}.evaluate((loginInfoStr, langStr) => {
                console.log('💾 Setting OMG login info to localStorage...');
                try {
                    // OMG_AGENT_LOGIN_INFO 應該是一個 JSON 字符串，我們需要確保它被正確設置
                    // 根據需求，直接將環境變數的內容當作字串存入 key
                    if (loginInfoStr) {
                        localStorage.setItem('vben-web-antd-5.5.0-prod-core-access', loginInfoStr);
                    }
                    
                    if (langStr) {
                        localStorage.setItem('vben-web-antd-5.5.0-prod-preferences-locale', langStr);
                    }
                    
                    // 觸發 storage 事件 (Authentication)
                    window.dispatchEvent(new StorageEvent('storage', {
                        key: 'vben-web-antd-5.5.0-prod-core-access',
                        storageArea: localStorage,
                        newValue: loginInfoStr
                    }));

                    // 觸發 storage 事件 (Language)
                    if (langStr) {
                        window.dispatchEvent(new StorageEvent('storage', {
                            key: 'vben-web-antd-5.5.0-prod-preferences-locale',
                            storageArea: localStorage,
                            newValue: langStr
                        }));
                    }
                    
                    return true;
                } catch (e) {
                    console.error('❌ Error setting localStorage:', e.message);
                    return false;
                }
            }, $loginInfoJs, $langJs);
            
            // 等待一下讓頁面處理 localStorage 更新
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 刷新頁面
            console.log('🔄 Reloading page to apply login info...');
            await {$pageVar}.reload({
                waitUntil: 'networkidle2',
                timeout: 60000
            });
            
            // 等待頁面完全載入
            await new Promise(resolve => setTimeout(resolve, 3000));
            
            // 驗證
            const localStorageSet = await {$pageVar}.evaluate(() => {
                const info = localStorage.getItem('vben-web-antd-5.5.0-prod-core-access');
                return info !== null && info !== '';
            });
            
            console.log('✅ OMG login process completed. Success: ' + localStorageSet);
        JS;
    }
}
