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
     * 生成 ATGSLOT Puppeteer 二階段登入流程程式碼片段
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateAtgslotPuppeteerLoginCode(string $pageVar = 'page'): string
    {
        $domain = env('ATGSLOT_AGENT_DOMAIN', '');
        $account = env('ATGSLOT_AGENT_ACCOUNT', '');
        $password = env('ATGSLOT_AGENT_PASSWORD', '');
        $verificationCode = env('ATGSLOT_AGENT_VERIFICATION_CODE', '');
        
        // 轉義 JavaScript 字符串，避免注入問題
        $domainJs = json_encode($domain);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);
        $verificationCodeJs = json_encode($verificationCode);

        return <<<JS
            console.log('🔐 Starting ATGSLOT two-factor login process...');
            
            // 第一步：導航到登入頁面
            console.log('🌐 Navigating to login page...');
            console.log('⏳ This may take up to 60 seconds...');
            await {$pageVar}.goto($domainJs, {
                waitUntil: 'load',  // 使用 'load' 確保頁面完全載入
                timeout: 60000  // 增加超時時間到 60 秒
            });
            console.log('✅ Page loaded');
            
            console.log('⏳ Waiting for page to render...');
            // 等待頁面完全載入和渲染
            await new Promise(resolve => setTimeout(resolve, 5000));
            
            // 確保頁面有內容（檢查 body 是否有內容）- 使用安全的方式
            let pageHasContent = false;
            try {
                pageHasContent = await {$pageVar}.evaluate(() => {
                    return document.body && document.body.innerHTML.trim().length > 0;
                });
            } catch (e) {
                if (e.message.includes('detached') || e.message.includes('Frame')) {
                    console.log('⚠️  Frame detached, waiting for page to stabilize...');
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                    try {
                        pageHasContent = await {$pageVar}.evaluate(() => {
                            return document.body && document.body.innerHTML.trim().length > 0;
                        });
                    } catch (e2) {
                        console.log('⚠️  Still cannot evaluate page content: ' + e2.message);
                    }
                }
            }
            
            if (!pageHasContent) {
                console.log('⚠️  Page body is empty, waiting for content...');
                // 等待頁面內容出現（最多等待 10 秒）
                for (let i = 0; i < 20; i++) {
                    await new Promise(resolve => setTimeout(resolve, 500));
                    try {
                        const hasContent = await {$pageVar}.evaluate(() => {
                            return document.body && document.body.innerHTML.trim().length > 0;
                        });
                        if (hasContent) {
                            console.log('✅ Page content loaded');
                            pageHasContent = true;
                            break;
                        }
                    } catch (e) {
                        if (e.message.includes('detached') || e.message.includes('Frame')) {
                            console.log('⚠️  Frame detached during content check, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                        }
                    }
                }
            }
            
            // 檢查頁面標題和 URL - 使用安全的方式
            let pageInfo = { title: 'Unknown', url: 'Unknown', bodyLength: 0, hasInputs: 0 };
            try {
                pageInfo = await {$pageVar}.evaluate(() => {
                    return {
                        title: document.title,
                        url: window.location.href,
                        bodyLength: document.body ? document.body.innerHTML.length : 0,
                        hasInputs: document.querySelectorAll('input').length
                    };
                });
            } catch (e) {
                if (e.message.includes('detached') || e.message.includes('Frame')) {
                    console.log('⚠️  Frame detached when getting page info, waiting...');
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                    try {
                        pageInfo = await {$pageVar}.evaluate(() => {
                            return {
                                title: document.title,
                                url: window.location.href,
                                bodyLength: document.body ? document.body.innerHTML.length : 0,
                                hasInputs: document.querySelectorAll('input').length
                            };
                        });
                    } catch (e2) {
                        console.log('⚠️  Cannot get page info: ' + e2.message);
                    }
                } else {
                    console.log('⚠️  Error getting page info: ' + e.message);
                }
            }
            console.log('📄 Page info:', JSON.stringify(pageInfo));
            
            // 額外等待確保渲染完成
            await new Promise(resolve => setTimeout(resolve, 2000));
            
            // 截圖 1: 初始登入頁面
            await {$pageVar}.screenshot({ path: '01_initial_login_page.png', fullPage: true });
            console.log('📸 Screenshot 1: Initial login page saved');
            
            // 第一步：查找並填入帳號欄位
            console.log('📝 Step 1: Filling account...');
            const accountSelectors = [
                'input[name="account"]',
                'input[name="username"]',
                'input[type="text"]',
                'input[id*="account"]',
                'input[id*="username"]',
                'input[placeholder*="帳號"]',
                'input[placeholder*="帳戶"]',
                'input[placeholder*="account"]',
                'input[placeholder*="username"]'
            ];
            
            let accountFilled = false;
            for (const selector of accountSelectors) {
                try {
                    // 使用 waitForSelector 避免 detached frame 错误
                    const accountInput = await {$pageVar}.waitForSelector(selector, { timeout: 2000 }).catch(() => null);
                    if (accountInput) {
                        // 使用 evaluateHandle 确保元素仍然有效
                        await {$pageVar}.evaluate((account, sel) => {
                            const input = document.querySelector(sel);
                            if (input && input.offsetParent !== null) {  // 检查元素是否可见
                                input.value = '';
                                input.value = account;
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                            }
                        }, $accountJs, selector);
                        accountFilled = true;
                        console.log('✅ Account filled using selector: ' + selector);
                        break;
                    }
                } catch (e) {
                    if (e.message.includes('detached') || e.message.includes('Frame')) {
                        console.log('⚠️  Frame detached, retrying...');
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        // 重新等待页面稳定
                        await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                    }
                    continue;
                }
            }
            
            if (!accountFilled) {
                console.log('⚠️  Account input not found, trying manual fill...');
                try {
                    // 確保頁面穩定
                    await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    // 嘗試手動查找所有輸入框 - 使用安全的方式
                    try {
                        await {$pageVar}.evaluate((account) => {
                            const inputs = document.querySelectorAll('input[type="text"], input[type="email"]');
                            if (inputs.length > 0 && inputs[0].offsetParent !== null) {
                                inputs[0].value = account;
                                inputs[0].dispatchEvent(new Event('input', { bubbles: true }));
                                inputs[0].dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }, $accountJs);
                    } catch (e) {
                        if (e.message.includes('detached') || e.message.includes('Frame')) {
                            console.log('⚠️  Frame detached, retrying account fill...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                            // 重试一次
                            try {
                                await {$pageVar}.evaluate((account) => {
                                    const inputs = document.querySelectorAll('input[type="text"], input[type="email"]');
                                    if (inputs.length > 0 && inputs[0].offsetParent !== null) {
                                        inputs[0].value = account;
                                        inputs[0].dispatchEvent(new Event('input', { bubbles: true }));
                                        inputs[0].dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                }, $accountJs);
                            } catch (e2) {
                                console.log('⚠️  Error filling account manually (retry failed): ' + e2.message);
                            }
                        } else {
                            console.log('⚠️  Error filling account manually: ' + e.message);
                        }
                    }
                } catch (e) {
                    console.log('⚠️  Error in account fill process: ' + e.message);
                }
            }
            
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 截圖 2: 填入帳號後（等待渲染）
            await new Promise(resolve => setTimeout(resolve, 500));
            await {$pageVar}.screenshot({ path: '02_after_account_filled.png', fullPage: true });
            console.log('📸 Screenshot 2: After account filled saved');
            
            // 第一步：查找並填入密碼欄位
            console.log('📝 Step 1: Filling password...');
            const passwordSelectors = [
                'input[name="password"]',
                'input[type="password"]',
                'input[id*="password"]',
                'input[placeholder*="密碼"]',
                'input[placeholder*="password"]'
            ];
            
            let passwordFilled = false;
            for (const selector of passwordSelectors) {
                try {
                    // 使用 waitForSelector 避免 detached frame 错误
                    const passwordInput = await {$pageVar}.waitForSelector(selector, { timeout: 2000 }).catch(() => null);
                    if (passwordInput) {
                        await {$pageVar}.evaluate((password, sel) => {
                            const input = document.querySelector(sel);
                            if (input && input.offsetParent !== null) {
                                input.value = '';
                                input.value = password;
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                            }
                        }, $passwordJs, selector);
                        passwordFilled = true;
                        console.log('✅ Password filled using selector: ' + selector);
                        break;
                    }
                } catch (e) {
                    if (e.message.includes('detached') || e.message.includes('Frame')) {
                        console.log('⚠️  Frame detached, retrying...');
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                    }
                    continue;
                }
            }
            
            await new Promise(resolve => setTimeout(resolve, 1000));
            
            // 截圖 3: 填入密碼後（等待渲染）
            await new Promise(resolve => setTimeout(resolve, 500));
            await {$pageVar}.screenshot({ path: '03_after_password_filled.png', fullPage: true });
            console.log('📸 Screenshot 3: After password filled saved');
            
            // 第一步：點擊登入按鈕（提交第一階段）
            console.log('📝 Step 1: Clicking login button...');
            let loginButtonClicked = { found: false };
            let retryCount = 0;
            const maxRetries = 3;
            
            while (retryCount < maxRetries && !loginButtonClicked.found) {
                try {
                    // 確保頁面穩定
                    await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    
                    loginButtonClicked = await {$pageVar}.evaluate(() => {
                        const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], a[class*="btn"]'));
                        const loginBtn = buttons.find(btn => {
                            const text = (btn.textContent || btn.value || btn.innerText || '').trim().toLowerCase();
                            return text.includes('登入') || text.includes('login') || text.includes('提交') || text.includes('submit');
                        });
                        
                        if (loginBtn && loginBtn.offsetParent !== null) {
                            try {
                                loginBtn.click();
                                return { found: true, method: 'click' };
                            } catch (e) {
                                const event = new MouseEvent('click', { bubbles: true, cancelable: true });
                                loginBtn.dispatchEvent(event);
                                return { found: true, method: 'dispatchEvent' };
                            }
                        }
                        
                        // 嘗試提交表單
                        const forms = document.querySelectorAll('form');
                        if (forms.length > 0) {
                            forms[0].submit();
                            return { found: true, method: 'formSubmit' };
                        }
                        
                        return { found: false };
                    });
                    
                    if (loginButtonClicked.found) {
                        break;
                    }
                } catch (e) {
                    retryCount++;
                    if (e.message.includes('detached') || e.message.includes('Frame')) {
                        console.log('⚠️  Frame detached when clicking login button, retrying... (' + retryCount + '/' + maxRetries + ')');
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                    } else {
                        console.log('⚠️  Error clicking login button: ' + e.message);
                        break;
                    }
                }
            }
            
            if (loginButtonClicked.found) {
                console.log('✅ Login button clicked using method: ' + loginButtonClicked.method);
            } else {
                console.log('⚠️  Login button not found');
            }
            
            // 截圖 4: 點擊登入按鈕後（等待頁面反應和渲染）
            await new Promise(resolve => setTimeout(resolve, 2000));
            await {$pageVar}.screenshot({ path: '04_after_login_button_clicked.png', fullPage: true });
            console.log('📸 Screenshot 4: After login button clicked saved');
            
            // 等待第一階段登入完成，頁面可能會跳轉或顯示驗證碼輸入框
            console.log('⏳ Waiting for second stage (verification code)...');
            console.log('⏳ This may take a few seconds...');
            await new Promise(resolve => setTimeout(resolve, 3000));
            
            // 檢查是否出現驗證碼輸入框
            const verificationCodeSelectors = [
                'input[name="verification"]',
                'input[name="verification_code"]',
                'input[name="code"]',
                'input[name="otp"]',
                'input[type="text"][placeholder*="驗證"]',
                'input[type="text"][placeholder*="驗證碼"]',
                'input[type="text"][placeholder*="verification"]',
                'input[type="text"][placeholder*="code"]',
                'input[id*="verification"]',
                'input[id*="code"]',
                'input[id*="otp"]'
            ];
            
            let verificationInputFound = false;
            let verificationSelector = null;
            
            // 等待驗證碼輸入框出現（最多等待 10 秒）
            for (let i = 0; i < 20; i++) {
                // 每 2 秒截圖一次，記錄等待過程
                if (i % 4 === 0 && i > 0) {
                    try {
                        await {$pageVar}.screenshot({ path: '05_waiting_for_verification_' + i + '.png', fullPage: true });
                        console.log('📸 Screenshot: Waiting for verification code input (attempt ' + i + ')');
                    } catch (e) {
                        // 忽略截图错误
                    }
                }
                
                for (const selector of verificationCodeSelectors) {
                    try {
                        // 使用 waitForSelector 避免 detached frame 错误
                        const verificationInput = await {$pageVar}.waitForSelector(selector, { timeout: 500 }).catch(() => null);
                        if (verificationInput) {
                            verificationInputFound = true;
                            verificationSelector = selector;
                            console.log('✅ Verification code input found: ' + selector);
                            break;
                        }
                    } catch (e) {
                        if (e.message.includes('detached') || e.message.includes('Frame')) {
                            // 等待页面稳定
                            await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 2000 }).catch(() => {});
                        }
                        continue;
                    }
                }
                
                if (verificationInputFound) {
                    break;
                }
                
                await new Promise(resolve => setTimeout(resolve, 500));
            }
            
            // 截圖 5: 驗證碼輸入框出現時（等待渲染）
            await new Promise(resolve => setTimeout(resolve, 1000));
            if (verificationInputFound) {
                await {$pageVar}.screenshot({ path: '05_verification_code_input_found.png', fullPage: true });
                console.log('📸 Screenshot 5: Verification code input found saved');
            } else {
                await {$pageVar}.screenshot({ path: '05_verification_code_input_not_found.png', fullPage: true });
                console.log('📸 Screenshot 5: Verification code input not found saved');
            }
            
            if (verificationInputFound && verificationSelector) {
                console.log('📝 Step 2: Filling verification code...');
                
                // 如果有設定驗證碼，自動填入
                if ($verificationCodeJs && $verificationCodeJs !== '' && $verificationCodeJs !== 'null') {
                    await {$pageVar}.evaluate((code, sel) => {
                        const input = document.querySelector(sel);
                        if (input) {
                            input.value = '';
                            input.value = code;
                            input.dispatchEvent(new Event('input', { bubbles: true }));
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                        }
                    }, $verificationCodeJs, verificationSelector);
                    console.log('✅ Verification code filled automatically');
                    
                    // 截圖 6: 自動填入驗證碼後（等待渲染）
                    await new Promise(resolve => setTimeout(resolve, 1000));
                    await {$pageVar}.screenshot({ path: '06_after_verification_code_filled_auto.png', fullPage: true });
                    console.log('📸 Screenshot 6: After verification code filled (auto) saved');
                } else {
                    // 如果沒有設定驗證碼，等待手動輸入（最多等待 120 秒）
                    console.log('⚠️  Verification code not set in environment variable');
                    console.log('⏳ Waiting for manual verification code input (120 seconds timeout)...');
                    console.log('💡 Please enter the verification code in the browser');
                    console.log('💡 You can also set ATGSLOT_AGENT_VERIFICATION_CODE in .env to skip manual input');
                    
                    // 等待驗證碼輸入框有值（最多 120 秒）
                    let codeEntered = false;
                    const maxWaitTime = 120; // 秒
                    const checkInterval = 1; // 每秒檢查一次
                    const totalChecks = maxWaitTime / checkInterval;
                    
                    for (let i = 0; i < totalChecks; i++) {
                        // 每 10 秒顯示進度
                        if (i % 10 === 0 && i > 0) {
                            const remaining = maxWaitTime - (i * checkInterval);
                            console.log('⏳ Still waiting... ' + remaining + ' seconds remaining');
                        }
                        
                        // 每 20 秒截圖一次，記錄等待過程
                        if (i % 20 === 0 && i > 0) {
                            try {
                                await {$pageVar}.screenshot({ path: '06_waiting_for_manual_code_' + i + '.png', fullPage: true });
                                console.log('📸 Screenshot: Waiting for manual verification code input (attempt ' + i + ')');
                            } catch (e) {
                                // 忽略截圖錯誤
                            }
                        }
                        
                        try {
                            const codeValue = await {$pageVar}.evaluate((sel) => {
                                const input = document.querySelector(sel);
                                return input ? input.value : '';
                            }, verificationSelector);
                            
                            if (codeValue && codeValue.length > 0) {
                                codeEntered = true;
                                console.log('✅ Verification code entered: ' + codeValue);
                                
                                // 截圖 6: 手動填入驗證碼後（等待渲染）
                                await new Promise(resolve => setTimeout(resolve, 500));
                                try {
                                    await {$pageVar}.screenshot({ path: '06_after_verification_code_filled_manual.png', fullPage: true });
                                    console.log('📸 Screenshot 6: After verification code filled (manual) saved');
                                } catch (e) {
                                    // 忽略截圖錯誤
                                }
                                break;
                            }
                        } catch (e) {
                            if (e.message.includes('detached') || e.message.includes('Frame')) {
                                console.log('⚠️  Frame detached during code check, waiting...');
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 5000 }).catch(() => {});
                            }
                        }
                        
                        await new Promise(resolve => setTimeout(resolve, checkInterval * 1000));
                    }
                    
                    if (!codeEntered) {
                        console.log('⚠️  Verification code not entered within ' + maxWaitTime + ' seconds timeout');
                        try {
                            await {$pageVar}.screenshot({ path: '06_verification_code_timeout.png', fullPage: true });
                            console.log('📸 Screenshot: Verification code timeout saved');
                        } catch (e) {
                            // 忽略截圖錯誤
                        }
                    }
                }
                
                await new Promise(resolve => setTimeout(resolve, 1000));
                
                // 第二步：點擊確認按鈕（完成二階段登入）
                console.log('📝 Step 2: Clicking confirm button...');
                const confirmButtonClicked = await {$pageVar}.evaluate(() => {
                    const buttons = Array.from(document.querySelectorAll('button, input[type="submit"], a[class*="btn"]'));
                    const confirmBtn = buttons.find(btn => {
                        const text = (btn.textContent || btn.value || btn.innerText || '').trim().toLowerCase();
                        return text.includes('確認') || text.includes('confirm') || 
                               text.includes('提交') || text.includes('submit') ||
                               text.includes('驗證') || text.includes('verify');
                    });
                    
                    if (confirmBtn) {
                        try {
                            confirmBtn.click();
                            return { found: true, method: 'click' };
                        } catch (e) {
                            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
                            confirmBtn.dispatchEvent(event);
                            return { found: true, method: 'dispatchEvent' };
                        }
                    }
                    
                    return { found: false };
                });
                
                if (confirmButtonClicked.found) {
                    console.log('✅ Confirm button clicked');
                } else {
                    console.log('⚠️  Confirm button not found, trying form submit...');
                    // 嘗試提交表單
                    await {$pageVar}.evaluate(() => {
                        const forms = document.querySelectorAll('form');
                        if (forms.length > 0) {
                            forms[0].submit();
                        }
                    });
                }
                
                // 截圖 7: 點擊確認按鈕後（等待登入完成和渲染）
                await new Promise(resolve => setTimeout(resolve, 2000));
                await {$pageVar}.screenshot({ path: '07_after_confirm_button_clicked.png', fullPage: true });
                console.log('📸 Screenshot 7: After confirm button clicked saved');
                
                // 等待登入完成（使用 Promise.race 避免 detached frame 错误）
                try {
                    await Promise.race([
                        {$pageVar}.waitForNavigation({ 
                            waitUntil: 'domcontentloaded',
                            timeout: 30000 
                        }),
                        new Promise((resolve) => setTimeout(resolve, 30000))
                    ]);
                } catch (e) {
                    if (e.message.includes('detached') || e.message.includes('Frame')) {
                        console.log('⚠️  Frame detached during navigation, waiting for page to stabilize...');
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                    } else {
                        console.log('⚠️  Navigation timeout, waiting 3 seconds...');
                    }
                }
            } else {
                console.log('⚠️  Verification code input not found, assuming single-stage login');
                // 如果沒有找到驗證碼輸入框，可能是單階段登入，直接等待導航
                try {
                    await Promise.race([
                        {$pageVar}.waitForNavigation({ 
                            waitUntil: 'domcontentloaded',
                            timeout: 30000 
                        }),
                        new Promise((resolve) => setTimeout(resolve, 30000))
                    ]);
                } catch (e) {
                    if (e.message.includes('detached') || e.message.includes('Frame')) {
                        console.log('⚠️  Frame detached during navigation, waiting for page to stabilize...');
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                    } else {
                        console.log('⚠️  Navigation timeout, waiting 3 seconds...');
                    }
                }
            }
            
            // 額外等待確保頁面完全載入和渲染
            await new Promise(resolve => setTimeout(resolve, 3000));
            
            // 確保最終頁面有內容
            try {
                await {$pageVar}.evaluate(() => {
                    return new Promise((resolve) => {
                        if (document.body && document.body.innerHTML.trim().length > 0) {
                            resolve();
                        } else {
                            const checkInterval = setInterval(() => {
                                if (document.body && document.body.innerHTML.trim().length > 0) {
                                    clearInterval(checkInterval);
                                    resolve();
                                }
                            }, 100);
                            setTimeout(() => {
                                clearInterval(checkInterval);
                                resolve();
                            }, 5000);
                        }
                    });
                });
            } catch (e) {
                if (e.message.includes('detached') || e.message.includes('Frame')) {
                    console.log('⚠️  Frame detached, waiting for page to stabilize...');
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    await {$pageVar}.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                }
            }
            
            // 截圖 8: 登入完成後的最終頁面
            try {
                await {$pageVar}.screenshot({ path: '08_login_completed_final_page.png', fullPage: true });
                console.log('📸 Screenshot 8: Login completed - final page saved');
            } catch (e) {
                console.log('⚠️  Error taking screenshot: ' + e.message);
            }
            
            console.log('✅ Login process completed');
            console.log('📍 Current URL: ' + (await {$pageVar}.url()));
        JS;
    }

    /**
     * 生成 ATGSLOT Puppeteer cookies 設定程式碼片段（登入後使用）
     * @param string $pageVar 頁面變數名稱（預設為 'page'）
     * @return string 返回 JavaScript 程式碼片段
     */
    protected function generateAtgslotPuppeteerCookiesCode(string $pageVar = 'page'): string
    {
        $token = env('ATGSLOT_AGENT_TOKEN', '');
        $domain = env('ATGSLOT_AGENT_DOMAIN', '');

        return <<<JS
            console.log('🔐 Setting ATGSLOT authentication cookies...');

            // 根據環境變數設定認證 cookies
            // 這些 cookies 用於通過需要登入的頁面驗證
            const cookies = [];
            const domain = '$domain';
            
            if (domain && domain !== '') {
                let cleanDomain = domain.replace(/^https?:\/\//, '').replace(/\/$/, '');
                
                if ('$token' && '$token' !== '') {
                    cookies.push({ 
                        name: 'atgslot_token', 
                        value: '$token', 
                        domain: cleanDomain,
                        path: '/',
                        httpOnly: true,
                        secure: true,
                        sameSite: 'Lax'
                    });
                }

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
}
