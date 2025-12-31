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
        $auth = env('FOQQ_AGENT_AUTH', '');
        $token = env('FOQQ_AGENT_TOKEN', '');
        $domain = env('FOQQ_AGENT_DOMAIN');

        return <<<JS
            // console.log('🔐 Setting authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
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
}
