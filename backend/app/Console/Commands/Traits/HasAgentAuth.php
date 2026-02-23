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
     * 生成 TAG Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateTagPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $token = env('TAG_AGENT_TOKEN', '');
        $lang = env('TAG_AGENT_LANG', 'en-us');
        $domainRaw = env('TAG_AGENT_DOMAIN', '');
        
        // TAG_AGENT_DOMAIN 可能是完整網址，取 host 作為 cookie domain
        $domain = $domainRaw;
        if ($domainRaw && preg_match('#^https?://#i', $domainRaw)) {
            $parsed = parse_url($domainRaw);
            $domain = $parsed['host'] ?? $domainRaw;
        }

        $tokenJs = json_encode($token);
        $langJs = json_encode($lang);
        $domainJs = json_encode($domain);

        return <<<JS
            console.log('🔐 Setting authentication cookies (session, lang, role, timezone)...');

            const cookies = [];
            const tagDomain = {$domainJs};
            if (tagDomain && tagDomain !== '') {
                const path = '/';
                if ({$tokenJs}) cookies.push({ name: 'session', value: {$tokenJs}, domain: tagDomain, path });
                if ({$langJs}) cookies.push({ name: 'lang', value: {$langJs}, domain: tagDomain, path });
            }

            if (cookies.length > 0) {
                await {$pageVar}.setCookie(...cookies);
                console.log('✅ Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No cookies set (set TAG_AGENT_TOKEN and TAG_AGENT_DOMAIN or use URL with host)');
            }
        JS;
    }

    /**
     * 生成 BNG Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateBngPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $token = env('BNG_AGENT_TOKEN', '');
        $lang = env('BNG_AGENT_LANG', 'zh-TW');
        $domainRaw = env('BNG_AGENT_DOMAIN', '');

        // BNG_AGENT_DOMAIN 可能是完整網址，取 host 作為 cookie domain
        $domain = $domainRaw;
        if ($domainRaw && preg_match('#^https?://#i', $domainRaw)) {
            $parsed = parse_url($domainRaw);
            $domain = $parsed['host'] ?? $domainRaw;
        }

        $tokenJs = json_encode($token);
        $langJs = json_encode($lang);
        $domainJs = json_encode($domain);

        return <<<JS
            console.log('🔐 Setting BNG authentication cookies (session, language)...');

            const cookies = [];
            const bngDomain = {$domainJs};
            if (bngDomain && bngDomain !== '') {
                const path = '/';
                if ({$tokenJs}) cookies.push({ name: 'session', value: {$tokenJs}, domain: bngDomain, path });
                if ({$langJs}) cookies.push({ name: 'language', value: {$langJs}, domain: bngDomain, path });
            }

            if (cookies.length > 0) {
                await {$pageVar}.setCookie(...cookies);
                console.log('✅ BNG Cookies set:', cookies.length);
            } else {
                console.log('⚠️  No BNG cookies set (set BNG_AGENT_TOKEN, BNG_AGENT_LANG and BNG_AGENT_DOMAIN or pass url with same domain)');
            }
        JS;
    }

    /**
     * 生成 FG Puppeteer cookies 設定程式碼片段
     * FG_AGENT_TOKEN → token, FG_AGENT_AUTH → auth, FG_AGENT_LANG → bg_languageKey
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateFgPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $token = env('FG_AGENT_TOKEN', '');
        $auth = env('FG_AGENT_AUTH', '');
        $lang = env('FG_AGENT_LANG', 'en-us');
        $domainRaw = env('FG_AGENT_DOMAIN', '');

        // 從 FG_AGENT_DOMAIN 提取 host 作為 cookie domain
        $domain = $domainRaw;
        if ($domainRaw && preg_match('#^https?://#i', $domainRaw)) {
            $parsed = parse_url($domainRaw);
            $domain = $parsed['host'] ?? $domainRaw;
        } elseif ($domainRaw) {
            // 若無協議，取第一個 / 前的部分作為 host
            $domain = preg_replace('#/.*$#', '', $domainRaw);
        }

        $tokenJs = json_encode($token);
        $authJs = json_encode($auth);
        $langJs = json_encode($lang);
        $domainJs = json_encode($domain);

        return <<<JS
            {
                console.log('🔐 Setting FG authentication cookies (token, auth, bg_languageKey)...');
                const cookies = [];
                const fgDomain = {$domainJs};
                const useSecure = typeof targetUrl !== 'undefined' && targetUrl.startsWith('https');
                if (fgDomain && fgDomain !== '') {
                    const path = '/';
                    if ({$tokenJs}) cookies.push({ name: 'token', value: {$tokenJs}, domain: fgDomain, path, secure: useSecure });
                    if ({$authJs}) cookies.push({ name: 'auth', value: {$authJs}, domain: fgDomain, path, secure: useSecure });
                    if ({$langJs}) cookies.push({ name: 'bg_languageKey', value: {$langJs}, domain: fgDomain, path, secure: useSecure });
                }
                if (cookies.length > 0) {
                    await {$pageVar}.setCookie(...cookies);
                    console.log('✅ FG Cookies set:', cookies.length);
                } else {
                    console.log('⚠️  No FG cookies set (set FG_AGENT_TOKEN, FG_AGENT_AUTH, FG_AGENT_LANG and FG_AGENT_DOMAIN)');
                }
            }
        JS;
    }

    /**
     * 生成 FG localStorage/sessionStorage 設定程式碼（部分 agent 前端從 localStorage 讀取）
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateFgPuppeteerLocalStorageCode(string $pageVar = 'page'): string
    {
        $token = env('FG_AGENT_TOKEN', '');
        $auth = env('FG_AGENT_AUTH', '');
        $lang = env('FG_AGENT_LANG', 'en-us');

        $tokenJs = json_encode($token);
        $authJs = json_encode($auth);
        $langJs = json_encode($lang);

        return <<<JS
            // 部分 agent 前端從 localStorage 讀取 token，一併設定
            await {$pageVar}.evaluate(({ token, auth, lang }) => {
                try {
                    if (token) {
                        localStorage.setItem('token', token);
                        sessionStorage.setItem('token', token);
                    }
                    if (auth) {
                        localStorage.setItem('auth', auth);
                        sessionStorage.setItem('auth', auth);
                    }
                    if (lang) {
                        localStorage.setItem('bg_languageKey', lang);
                        sessionStorage.setItem('bg_languageKey', lang);
                    }
                    console.log('✅ FG localStorage/sessionStorage set');
                } catch (e) {
                    console.warn('⚠️  localStorage set:', e.message);
                }
            }, { token: {$tokenJs}, auth: {$authJs}, lang: {$langJs} });
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
        $lang = env('ATGSLOT_AGENT_LANG', 'zh-TW');
        $langJs = json_encode($lang);

        // 驗證並轉義 loginInfo JSON
        $loginInfo = json_decode($loginInfoJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 如果 JSON 無效，返回錯誤
            return <<<JS
                console.error('❌ Invalid loginInfo JSON format');
                throw new Error('Invalid loginInfo JSON format');
            JS;
        }

        // 將 lang 存到 key=languageFamily 的資料中
        $loginInfo['languageFamily'] = $lang;

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
            
            // 將 languageFamily 寫入 localStorage（key = languageFamily）
            await {$pageVar}.evaluate((langVal) => {
                try {
                    if (langVal != null && langVal !== '') {
                        localStorage.setItem('languageFamily', langVal);
                        console.log('✅ languageFamily set to localStorage:', langVal);
                    }
                } catch (e) {
                    console.error('❌ Error setting languageFamily:', e.message);
                }
            }, $langJs);
            
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

        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);

        return <<<JS
            // ===== Helper Functions =====
            const injectAntiDetection = async () => {
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
            };
            
            const getPageContent = async () => {
                return await {$pageVar}.evaluate(() => {
                    const bodyText = document.body ? document.body.innerText : '';
                    const currentUrl = window.location.href;
                    const pageTitle = document.title || '';
                    return {
                        url: currentUrl,
                        title: pageTitle,
                        bodyTextLength: bodyText.length,
                        hasContent: bodyText.length > 100,
                        hasSecurityCheck: bodyText.includes('What Are You Looking For') || pageTitle.includes('What Are You Looking For'),
                        isDisableDevtoolPage: currentUrl.includes('disable-devtool') || 
                                             currentUrl.includes('theajack.github.io') ||
                                             currentUrl.includes('chrome-error://') ||
                                             pageTitle.includes('Not allowed') ||
                                             bodyText.includes('Not allowed')
                    };
                });
            };
            
            const parseRedirectUrl = () => {
                try {
                    if ($redirectUrlJs && $redirectUrlJs !== 'null' && $redirectUrlJs !== '') {
                        return JSON.parse($redirectUrlJs);
                    }
                } catch (e) {
                    return $redirectUrlJs !== 'null' ? $redirectUrlJs.replace(/^"|"\$/g, '') : null;
                }
                return null;
            };
            
            const navigateToUrl = async (targetUrl, pageContent) => {
                if (!targetUrl) return;
                try {
                    // SPA hash navigation if same origin
                    let hashPart = null;
                    try {
                        const ru = new URL(targetUrl);
                        let curUrl = pageContent?.url || await {$pageVar}.url();
                        if (curUrl) {
                            const cu = new URL(curUrl);
                            if (ru.origin === cu.origin && ru.hash && ru.hash.length > 1) {
                                hashPart = ru.hash;
                            }
                        }
                    } catch (e) {}
                    
                    if (hashPart) {
                        await {$pageVar}.evaluate((h) => { window.location.hash = h; }, hashPart);
                        await new Promise(r => setTimeout(r, 3000));
                    } else {
                        await {$pageVar}.evaluate((url) => { window.location.href = url; }, targetUrl);
                        await {$pageVar}.waitForNavigation({ waitUntil: 'networkidle2', timeout: 20000 }).catch(() => {});
                        await new Promise(r => setTimeout(r, 2000));
                    }
                    await injectAntiDetection();
                } catch (e) {}
            };
            
            const waitForLoginResponse = async () => {
                await Promise.race([
                    {$pageVar}.waitForNavigation({ waitUntil: 'networkidle2', timeout: 8000 }),
                    {$pageVar}.waitForSelector('input[placeholder="Please enter player account"]', { timeout: 10000 }),
                    {$pageVar}.waitForSelector('i.el-icon-switch-button, .btn-logout, .user-info', { timeout: 10000 }),
                    new Promise(r => setTimeout(r, 5000))
                ]).catch(() => {});
            };
            
            const handlePostLogin = async () => {
                await injectAntiDetection();
                await new Promise(r => setTimeout(r, 10000)); // Wait for page to load
                
                let pageContent = await getPageContent();
                
                // Handle disable-devtool redirect
                if (pageContent.isDisableDevtoolPage) {
                    const redirectUrl = parseRedirectUrl();
                    if (redirectUrl) {
                        await {$pageVar}.goto(redirectUrl, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
                        await new Promise(r => setTimeout(r, 3000));
                        await injectAntiDetection();
                        pageContent = await getPageContent();
                    }
                }
                
                // Wait for empty page
                if (!pageContent.hasContent) {
                    await new Promise(r => setTimeout(r, 5000));
                    pageContent = await getPageContent();
                }
                
                // Handle security check
                if (pageContent.hasSecurityCheck || pageContent.isDisableDevtoolPage) {
                    for (let i = 0; i < 10; i++) {
                        await new Promise(r => setTimeout(r, 1000));
                        const status = await getPageContent();
                        if (!status.hasSecurityCheck && !status.isDisableDevtoolPage) break;
                        
                        if (status.isDisableDevtoolPage) {
                            const recoveryUrl = parseRedirectUrl();
                            if (recoveryUrl) {
                                await {$pageVar}.goto(recoveryUrl, { waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => {});
                                await new Promise(r => setTimeout(r, 2000));
                                await injectAntiDetection();
                            }
                        }
                    }
                    pageContent = await getPageContent();
                }
                
                // Navigate to redirect URL if provided
                const redirectUrl = parseRedirectUrl();
                if (redirectUrl) {
                    await navigateToUrl(redirectUrl, pageContent);
                }
            };
            
            // ===== Main Login Flow =====
            console.log('🔐 Starting 1BET login...');
            
            // Parse credentials
            let accountParsed = null;
            try {
                if ($accountJs && $accountJs !== 'null') accountParsed = JSON.parse($accountJs);
            } catch (e) {
                accountParsed = $accountJs !== 'null' ? $accountJs.replace(/^"|"\$/g, '') : null;
            }
            
            let passwordParsed = null;
            try {
                if ($passwordJs && $passwordJs !== 'null') passwordParsed = JSON.parse($passwordJs);
            } catch (e) {
                passwordParsed = $passwordJs !== 'null' ? $passwordJs.replace(/^"|"\$/g, '') : null;
            }
            
            // Find login form with retry
            let loginFormFound = false;
            for (let attempt = 0; attempt < 5 && !loginFormFound; attempt++) {
                if (attempt > 0) {
                    await {$pageVar}.reload({ waitUntil: 'domcontentloaded', timeout: 15000 });
                    await new Promise(r => setTimeout(r, 3000));
                    await injectAntiDetection();
                }
                
                const formCheck = await {$pageVar}.evaluate(() => ({
                    hasAccount: !!document.querySelector('input.el-input__inner[placeholder="Please enter account number"]'),
                    hasPassword: !!document.querySelector('input.el-input__inner[placeholder="password"]')
                }));
                loginFormFound = formCheck.hasAccount && formCheck.hasPassword;
            }
            
            // Fill account
            await {$pageVar}.evaluate((val) => {
                const input = document.querySelector('input.el-input__inner[placeholder="Please enter account number"]');
                if (input && val) {
                    input.value = val;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }, accountParsed);
            await new Promise(r => setTimeout(r, 500));
            
            // Fill password
            await {$pageVar}.evaluate((val) => {
                const input = document.querySelector('input.el-input__inner[placeholder="password"]');
                if (input && val) {
                    input.value = val;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }, passwordParsed);
            await new Promise(r => setTimeout(r, 500));
            
            // Click login button
            const loginSelectors = [
                'button.el-button.btn-login.el-button--primary.el-button--small',
                'button.btn-login',
                'button[class*="btn-login"]',
                'button.el-button--primary',
                'a.btn-login',
                'a[class*="btn-login"]'
            ];
            
            let loginClicked = false;
            for (let attempt = 0; attempt < 3 && !loginClicked; attempt++) {
                if (attempt > 0) await new Promise(r => setTimeout(r, 2000));
                
                // Try evaluate click first
                loginClicked = await {$pageVar}.evaluate((selectors) => {
                    for (const sel of selectors) {
                        const btn = document.querySelector(sel);
                        if (btn) {
                            const style = window.getComputedStyle(btn);
                            if (style.display !== 'none' && style.visibility !== 'hidden' && btn.offsetParent) {
                                btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                btn.click();
                                return true;
                            }
                        }
                    }
                    return false;
                }, loginSelectors);
                
                // Try Puppeteer click if evaluate failed
                if (!loginClicked) {
                    for (const selector of loginSelectors) {
                        try {
                            const btn = await {$pageVar}.$(selector);
                            if (btn && await btn.isIntersectingViewport()) {
                                await btn.click();
                                loginClicked = true;
                                break;
                            }
                        } catch (e) {}
                    }
                }
            }
            
            if (loginClicked) {
                console.log('✅ Login button clicked');
                await new Promise(r => setTimeout(r, 500));
                await waitForLoginResponse();
                await handlePostLogin();
            } else {
                console.log('⚠️ Login button not found');
            }
            
            console.log('✅ 1BET login completed');
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

    /**
     * 生成 Swin Puppeteer cookies 設定程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateSwinPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $lang = env('SWIN_AGENT_LANG');
        $auth = env('SWIN_AGENT_AUTH', '');
        $token = env('SWIN_AGENT_TOKEN', '');
        $domain = env('SWIN_AGENT_DOMAIN');

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
}
