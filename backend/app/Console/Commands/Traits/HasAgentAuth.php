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
     * 生成 1BET Puppeteer 登入流程程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generate1BetPuppeteerLoginCode(string $pageVar = 'page'): string
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
            const passwordInputFound = await {$pageVar}.evaluate((passwordValue) => {
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
            
            // 步驟 3: 點擊登錄按鈕
            console.log('🔍 Step 3: Looking for login button...');
            
            // 先檢查頁面狀態
            const pageStatus = await {$pageVar}.evaluate(() => {
                return {
                    url: window.location.href,
                    readyState: document.readyState,
                    hasBody: !!document.body,
                    bodyTextLength: document.body ? document.body.innerText.length : 0,
                    buttonCount: document.querySelectorAll('button').length,
                    allElementsCount: document.querySelectorAll('*').length
                };
            });
            console.log('   Page status:');
            console.log('     URL: ' + pageStatus.url);
            console.log('     Ready state: ' + pageStatus.readyState);
            console.log('     Has body: ' + pageStatus.hasBody);
            console.log('     Body text length: ' + pageStatus.bodyTextLength);
            console.log('     Button count: ' + pageStatus.buttonCount);
            console.log('     Total elements: ' + pageStatus.allElementsCount);
            
            // 如果頁面沒有按鈕，等待更長時間讓頁面完全渲染
            if (pageStatus.buttonCount === 0) {
                console.log('   No buttons found, waiting for page to fully render...');
                await new Promise(resolve => setTimeout(resolve, 3000));
                
                // 再次檢查
                const retryStatus = await {$pageVar}.evaluate(() => {
                    return {
                        buttonCount: document.querySelectorAll('button').length,
                        readyState: document.readyState
                    };
                });
                console.log('   After waiting - Button count: ' + retryStatus.buttonCount + ', Ready state: ' + retryStatus.readyState);
            }
            
            // 先等待按鈕出現（多種選擇器）
            const selectors = [
                'button.btn-login',
                'button.el-button.btn-login',
                'button.el-button.btn-login.el-button--primary',
                'button[class*="btn-login"]'
            ];
            
            let buttonFoundBySelector = false;
            for (const selector of selectors) {
                try {
                    await {$pageVar}.waitForSelector(selector, { 
                        timeout: 5000,
                        visible: true 
                    });
                    console.log('   ✅ Button found by selector: ' + selector);
                    buttonFoundBySelector = true;
                    break;
                } catch (e) {
                    // 繼續嘗試下一個選擇器
                }
            }
            
            if (!buttonFoundBySelector) {
                console.log('   ⚠️  Button not found by any selector, trying alternative methods...');
            }
            
            // 等待一下讓按鈕完全渲染
            await new Promise(resolve => setTimeout(resolve, 2000));
            
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
                
                // 方法 4: 通過文本查找 "登录"
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
                        return text.includes('登录') || text.includes('登入') || text.includes('Login');
                    });
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
                        return { clicked: false, reason: 'not_visible' };
                    }
                    
                    // 滾動到按鈕位置
                    button.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    
                    // 點擊按鈕
                    button.click();
                    
                    return {
                        clicked: true,
                        text: button.textContent.trim(),
                        className: button.className,
                        type: button.type
                    };
                }
                
                // 調試信息：列出所有按鈕和相關元素
                const allButtons = Array.from(document.querySelectorAll('button'));
                const allClickable = Array.from(document.querySelectorAll('button, a, [role="button"], input[type="submit"], input[type="button"]'));
                const allElementsWithLogin = Array.from(document.querySelectorAll('*')).filter(el => {
                    const text = el.textContent.trim().toLowerCase();
                    return text.includes('登录') || text.includes('登入') || text.includes('login');
                });
                
                const buttonInfo = allButtons.map(btn => ({
                    text: btn.textContent.trim(),
                    className: btn.className,
                    type: btn.type,
                    id: btn.id,
                    visible: window.getComputedStyle(btn).display !== 'none'
                }));
                
                const clickableInfo = allClickable.slice(0, 10).map(el => ({
                    tag: el.tagName,
                    text: el.textContent.trim().substring(0, 50),
                    className: el.className,
                    id: el.id
                }));
                
                const loginElementsInfo = allElementsWithLogin.slice(0, 5).map(el => ({
                    tag: el.tagName,
                    text: el.textContent.trim().substring(0, 50),
                    className: el.className
                }));
                
                return { 
                    clicked: false, 
                    reason: 'not_found',
                    availableButtons: buttonInfo,
                    clickableElements: clickableInfo,
                    loginRelatedElements: loginElementsInfo,
                    totalButtons: allButtons.length,
                    totalClickable: allClickable.length
                };
            });
            
            if (loginButtonClicked.clicked) {
                console.log('✅ Login button clicked!');
                console.log('   Button text: ' + loginButtonClicked.text);
                console.log('   Button class: ' + loginButtonClicked.className);
                console.log('   Button type: ' + loginButtonClicked.type);
                
                // 等待登錄處理
                console.log('⏳ Waiting for login to process...');
                await new Promise(resolve => setTimeout(resolve, 2000));
                
                // 嘗試等待頁面導航或變化
                try {
                    await {$pageVar}.waitForNavigation({
                        waitUntil: 'networkidle2',
                        timeout: 10000
                    }).catch(() => {
                        console.log('   No navigation detected, continuing...');
                    });
                } catch (e) {
                    console.log('   Navigation wait timeout, continuing...');
                }
                
                // 再等待一下讓頁面完全載入
                await new Promise(resolve => setTimeout(resolve, 2000));
            } else {
                console.log('⚠️  Login button not found');
                if (loginButtonClicked.reason) {
                    console.log('   Reason: ' + loginButtonClicked.reason);
                }
                
                console.log('   Total buttons on page: ' + (loginButtonClicked.totalButtons || 0));
                console.log('   Total clickable elements: ' + (loginButtonClicked.totalClickable || 0));
                
                // 顯示可用的按鈕信息
                if (loginButtonClicked.availableButtons && loginButtonClicked.availableButtons.length > 0) {
                    console.log('   Available buttons on page:');
                    loginButtonClicked.availableButtons.forEach((btn, index) => {
                        console.log('     ' + (index + 1) + '. Text: "' + btn.text + '", Class: "' + btn.className + '", Type: "' + btn.type + '", Visible: ' + btn.visible);
                    });
                }
                
                // 顯示可點擊的元素
                if (loginButtonClicked.clickableElements && loginButtonClicked.clickableElements.length > 0) {
                    console.log('   Clickable elements on page:');
                    loginButtonClicked.clickableElements.forEach((el, index) => {
                        console.log('     ' + (index + 1) + '. Tag: ' + el.tag + ', Text: "' + el.text + '", Class: "' + el.className + '"');
                    });
                }
                
                // 顯示包含 "登录" 的元素
                if (loginButtonClicked.loginRelatedElements && loginButtonClicked.loginRelatedElements.length > 0) {
                    console.log('   Elements containing "登录" text:');
                    loginButtonClicked.loginRelatedElements.forEach((el, index) => {
                        console.log('     ' + (index + 1) + '. Tag: ' + el.tag + ', Text: "' + el.text + '", Class: "' + el.className + '"');
                    });
                }
                
                if (loginButtonClicked.totalButtons === 0) {
                    console.log('   ⚠️  No buttons found at all - page may not be fully loaded');
                    console.log('   💡 Suggestion: Check if page needs more time to render or if there are iframes');
                }
            }
            
            console.log('✅ 1BET login process completed');
        JS;
    }
}
