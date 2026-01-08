<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - ATGSLOT
 */
class ScrapeBrowserAtgslotDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-atgslot-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {player_account?} - 玩家帳號（可選參數）
     */
    protected $signature = 'agent:scrape-atgslot-dom-detail {url} {date_start?} {date_end?} {player_account?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from ATGSLOT DOM elements using browser automation with two-factor authentication';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $date_start = $this->argument('date_start');
        $date_end = $this->argument('date_end');
        $player_account = $this->argument('player_account');

        $this->info('=== ATGSLOT DOM Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$date_start}");
        $this->info("Date End: {$date_end}");
        $this->info("Player Account: {$player_account}");
        
        // 檢查是否設定了 loginInfo（可以跳過登入流程）
        $loginInfo = env('ATGSLOT_AGENT_LOGIN_INFO', '');
        if (!empty($loginInfo)) {
            $this->info('✅ 已設定 loginInfo，將使用現有登入資訊（跳過登入流程）');
        } else {
            // 檢查是否設定了驗證碼
            $verificationCode = env('ATGSLOT_AGENT_VERIFICATION_CODE', '');
            if (empty($verificationCode)) {
                $this->warn('');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->warn('⚠️  注意：未設定二階段驗證碼');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->warn('如果網站需要二階段驗證，系統會暫停並等待您輸入');
                $this->warn('您可以在 .env 文件中設定：');
                $this->warn('  - ATGSLOT_AGENT_VERIFICATION_CODE=your_code（僅驗證碼）');
                $this->warn('  - ATGSLOT_AGENT_LOGIN_INFO={"token":"...","key":"..."}（完整登入資訊，可跳過登入）');
                $this->warn('═══════════════════════════════════════════════════════════');
                $this->warn('');
            } else {
                $this->info('✅ 已設定驗證碼，將自動填入');
            }
        }

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $date_start, $date_end, $player_account);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理爬取的資料
        if ($result) {
            $this->processScrapedData($result);
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
     * 創建 Puppeteer 自動化腳本（從 DOM 提取資料）
     * @param string $url 要爬取的目標網址
     * @param string|null $date_start 要選擇的開始日期（可選）
     * @param string|null $date_end 要選擇的結束日期（可選）
     * @param string|null $player_account 玩家帳號（可選）
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date_start = null, $date_end = null, $player_account = null)
    {
        $this->info('2. Creating browser automation script...');

        // 檢查是否使用 loginInfo 跳過登入
        $loginInfo = env('ATGSLOT_AGENT_LOGIN_INFO', '');
        if (!empty($loginInfo)) {
            // 使用 loginInfo 直接設置登入狀態
            $loginCodeForPage = $this->generateAtgslotPuppeteerLoginInfoCode('page', $loginInfo);
        } else {
            // 使用正常的二階段登入流程
            $loginCodeForPage = $this->generateAtgslotPuppeteerLoginCode('page');
        }

        // 格式化日期為 YYYY-MM-DD 的輔助函數
        $formatDate = function($date) {
            if (!$date) {
                return null;
            }
            // 如果已經是 YYYY-MM-DD 格式，直接使用
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $date;
            }
            // YYYYMMDD 格式轉換為 YYYY-MM-DD
            if (preg_match('/^\d{8}$/', $date)) {
                return substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            }
            // 其他格式嘗試轉換
            return date('Y-m-d', strtotime($date));
        };
        
        $dateStartFormatted = $formatDate($date_start);
        $dateEndFormatted = $formatDate($date_end);
        
        $dateStartJs = $dateStartFormatted ? json_encode($dateStartFormatted) : 'null';
        $dateEndJs = $dateEndFormatted ? json_encode($dateEndFormatted) : 'null';
        $playerAccountJs = $player_account ? json_encode($player_account) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             */
            async function scrapeDOMContent() {
                const browser = await puppeteer.launch({
                    headless: 'new',  // 使用 headless 模式在背景運行
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--no-zygote',
                        '--single-process',
                        '--disable-gpu',
                        '--disable-software-rasterizer',
                        '--disable-background-timer-throttling',
                        '--disable-backgrounding-occluded-windows',
                        '--disable-renderer-backgrounding',
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
                        '--disable-javascript-harmony-shipping',
                        '--disable-sync'
                    ],
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');


                    // 執行二階段登入流程
                    {$loginCodeForPage}

                    // 等待登入完成
                    console.log('⏳ Waiting for login to complete...');
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    // 獲取登入後的 cookies 和認證資訊 - 使用安全的方式
                    let authData = { cookies: '', localStorage: {} };
                    try {
                        // 確保頁面穩定
                        await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        authData = await page.evaluate(() => {
                            const cookies = document.cookie;
                            const localStorage = {};
                            for (let i = 0; i < window.localStorage.length; i++) {
                                const key = window.localStorage.key(i);
                                localStorage[key] = window.localStorage.getItem(key);
                            }
                            return { cookies, localStorage };
                        });
                    } catch (e) {
                        if (e.message.includes('detached') || e.message.includes('Frame')) {
                            console.log('⚠️  Frame detached when getting auth data, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                            try {
                                authData = await page.evaluate(() => {
                                    const cookies = document.cookie;
                                    const localStorage = {};
                                    for (let i = 0; i < window.localStorage.length; i++) {
                                        const key = window.localStorage.key(i);
                                        localStorage[key] = window.localStorage.getItem(key);
                                    }
                                    return { cookies, localStorage };
                                });
                            } catch (e2) {
                                console.log('⚠️  Cannot get auth data: ' + e2.message);
                            }
                        } else {
                            console.log('⚠️  Error getting auth data: ' + e.message);
                        }
                    }

                    const cookies = await page.cookies();
                    console.log('✅ Login completed, obtained ' + cookies.length + ' cookies');

                    // 登入成功後，導航到目標 URL
                    console.log('🌐 Navigating to target URL:', '$url');
                    try {
                        await page.goto('$url', {
                            waitUntil: 'load',
                            timeout: 60000
                        });
                        console.log('✅ Successfully navigated to target URL');
                        
                        // 等待頁面完全載入
                        await new Promise(resolve => setTimeout(resolve, 3000));
                        
                        // 確保頁面有內容
                        const pageHasContent = await page.evaluate(() => {
                            return document.body && document.body.innerHTML.trim().length > 0;
                        }).catch(() => false);
                        
                        if (pageHasContent) {
                            console.log('✅ Target page loaded successfully');
                        } else {
                            console.log('⚠️  Target page body is empty, waiting...');
                            await new Promise(resolve => setTimeout(resolve, 2000));
                        }
                    } catch (e) {
                        console.log('⚠️  Error navigating to target URL: ' + e.message);
                        // 即使導航失敗，也繼續執行
                    }
                    
                    // 填入日期範圍（如果提供了 date_start 或 date_end）
                    let dateStartValue = null;
                    let dateEndValue = null;
                    let playerAccountValue = null;
                    let searchButtonClicked = false; // 聲明在外層以便後面使用
                    
                    // 安全地解析玩家帳號值
                    if ($playerAccountJs && $playerAccountJs !== 'null' && $playerAccountJs.trim() !== '') {
                        try {
                            const parsed = JSON.parse($playerAccountJs);
                            if (parsed !== null && parsed !== '') {
                                playerAccountValue = parsed;
                            }
                        } catch (e) {
                            console.log('⚠️  Error parsing player_account as JSON, using as string: ' + e.message);
                            playerAccountValue = $playerAccountJs.replace(/^["']|["']$/g, ''); // 移除引號
                        }
                    }
                    
                    // 安全地解析日期值
                    if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs.trim() !== '') {
                        try {
                            const parsed = JSON.parse($dateStartJs);
                            if (parsed !== null && parsed !== '') {
                                dateStartValue = parsed;
                            }
                        } catch (e) {
                            // 如果 JSON 解析失敗，嘗試直接使用字符串值
                            console.log('⚠️  Error parsing date_start as JSON, using as string: ' + e.message);
                            dateStartValue = $dateStartJs.replace(/^["']|["']$/g, ''); // 移除引號
                        }
                    }
                    
                    if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs.trim() !== '') {
                        try {
                            const parsed = JSON.parse($dateEndJs);
                            if (parsed !== null && parsed !== '') {
                                dateEndValue = parsed;
                            }
                        } catch (e) {
                            // 如果 JSON 解析失敗，嘗試直接使用字符串值
                            console.log('⚠️  Error parsing date_end as JSON, using as string: ' + e.message);
                            dateEndValue = $dateEndJs.replace(/^["']|["']$/g, ''); // 移除引號
                        }
                    }
                    
                    if (dateStartValue || dateEndValue) {
                        console.log('📅 Filling date range...');
                        console.log('   Date Start: ' + (dateStartValue || 'N/A'));
                        console.log('   Date End: ' + (dateEndValue || 'N/A'));
                        
                        try {
                            // 等待頁面穩定
                            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 查找日期輸入框
                            const dateInputs = await page.$$('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                            console.log('📋 Found ' + dateInputs.length + ' date input(s)');
                            
                            if (dateInputs.length >= 2) {
                                // 第一個輸入框填入開始日期
                                if (dateStartValue) {
                                    console.log('📝 Filling start date: ' + dateStartValue);
                                    await page.evaluate((dateValue, index) => {
                                        const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (inputs[index]) {
                                            const input = inputs[index];
                                            input.value = '';
                                            input.value = dateValue;
                                            // 觸發各種事件確保應用檢測到變化
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            // 也觸發 keyup 和 keydown（某些框架需要）
                                            input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                                            input.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true }));
                                        }
                                    }, dateStartValue, 0);
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                                
                                // 第二個輸入框填入結束日期
                                if (dateEndValue) {
                                    console.log('📝 Filling end date: ' + dateEndValue);
                                    await page.evaluate((dateValue, index) => {
                                        const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (inputs[index]) {
                                            const input = inputs[index];
                                            input.value = '';
                                            input.value = dateValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                                            input.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true }));
                                        }
                                    }, dateEndValue, 1);
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                }
                                
                                console.log('✅ Date range filled successfully');
                            } else if (dateInputs.length === 1) {
                                // 如果只有一個輸入框，可能是日期範圍選擇器
                                console.log('📝 Found single date input, trying to fill as range...');
                                if (dateStartValue && dateEndValue) {
                                    // 嘗試填入範圍格式（根據實際需求調整格式）
                                    const dateRange = dateStartValue + ' ~ ' + dateEndValue;
                                    await page.evaluate((rangeValue) => {
                                        const input = document.querySelector('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (input) {
                                            input.value = '';
                                            input.value = rangeValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    }, dateRange);
                                    console.log('✅ Date range filled: ' + dateRange);
                                } else if (dateStartValue) {
                                    await page.evaluate((dateValue) => {
                                        const input = document.querySelector('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="選擇日期"]');
                                        if (input) {
                                            input.value = '';
                                            input.value = dateValue;
                                            input.dispatchEvent(new Event('input', { bubbles: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        }
                                    }, dateStartValue);
                                    console.log('✅ Start date filled: ' + dateStartValue);
                                }
                            } else {
                                console.log('⚠️  Date inputs not found, trying alternative selectors...');
                                // 嘗試其他選擇器
                                const altInputs = await page.$$('input[placeholder*="日期"], input[placeholder*="date"], input.ivu-input');
                                console.log('📋 Found ' + altInputs.length + ' alternative input(s)');
                            }
                            
                            // 等待一下讓日期輸入生效
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                        } catch (e) {
                            console.log('⚠️  Error filling date range: ' + e.message);
                            console.error(e);
                        }
                    }
                    
                    // 填入玩家帳號（如果提供了 player_account）
                    if (playerAccountValue) {
                        console.log('👤 Filling player account: ' + playerAccountValue);
                        try {
                            // 等待頁面穩定
                            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                            await new Promise(resolve => setTimeout(resolve, 1000));
                            
                            // 查找玩家帳號輸入框（使用用戶指定的選擇器）
                            const playerAccountInput = await page.$('input[placeholder="搜尋用戶/注單ID"]');
                            
                            if (playerAccountInput) {
                                console.log('✅ Found player account input field');
                                
                                // 填入玩家帳號
                                await page.evaluate((accountValue) => {
                                    const input = document.querySelector('input[placeholder="搜尋用戶/注單ID"]');
                                    if (input) {
                                        // 清空輸入框
                                        input.value = '';
                                        // 設置值
                                        input.value = accountValue;
                                        // 觸發各種事件確保應用檢測到變化
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                        // 也觸發 keyup 和 keydown（某些框架需要）
                                        input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                                        input.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true }));
                                        
                                        // 觸發 focus 和 focusout 事件
                                        input.focus();
                                        input.dispatchEvent(new Event('focus', { bubbles: true }));
                                        setTimeout(() => {
                                            input.blur();
                                            input.dispatchEvent(new Event('focusout', { bubbles: true }));
                                        }, 100);
                                    }
                                }, playerAccountValue);
                                
                                await new Promise(resolve => setTimeout(resolve, 500));
                                console.log('✅ Player account filled successfully');
                            } else {
                                console.log('⚠️  Player account input field not found');
                            }
                        } catch (e) {
                            console.log('⚠️  Error filling player account: ' + e.message);
                            console.error(e);
                        }
                        
                        // 等待一下讓帳號輸入生效
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }
                    
                    // 如果填寫了日期範圍或玩家帳號，需要點擊搜尋按鈕
                    if (dateStartValue || dateEndValue || playerAccountValue) {
                        // 點擊搜尋按鈕
                        console.log('🔍 Looking for search button...');
                        searchButtonClicked = false; // 重置為 false
                        
                        try {
                            // 等待一下確保按鈕已渲染
                            await new Promise(resolve => setTimeout(resolve, 500));
                            
                            // 使用精確的選擇器查找按鈕
                            const buttonFound = await page.evaluate(() => {
                                    // 優先查找包含 "搜尋" 文字的按鈕
                                    const allButtons = Array.from(document.querySelectorAll('button.ivu-btn.ivu-btn-primary, button.ivu-btn-primary, .ivu-btn-primary'));
                                    
                                    console.log('Found ' + allButtons.length + ' primary button(s)');
                                    
                                    // 查找包含 "搜尋" 文字的按鈕
                                    for (const btn of allButtons) {
                                        // 獲取按鈕文字（包括 span 內的文字）
                                        const text = (btn.textContent || btn.innerText || '').trim();
                                        const textLower = text.toLowerCase();
                                        
                                        console.log('Checking button with text: "' + text + '"');
                                        
                                        // 檢查是否包含搜尋相關文字
                                        if (textLower.includes('搜尋') || 
                                            textLower.includes('搜索') || 
                                            textLower.includes('查詢') || 
                                            textLower.includes('search')) {
                                            
                                            console.log('Found search button with text: "' + text + '"');
                                            
                                            // 確保按鈕可見
                                            if (btn.offsetParent === null) {
                                                console.log('Button is not visible, skipping');
                                                continue;
                                            }
                                            
                                            // 嘗試點擊
                                            try {
                                                btn.focus();
                                                btn.click();
                                                return { found: true, method: 'click', text: text };
                                            } catch (e) {
                                                console.log('Click failed, trying dispatchEvent: ' + e.message);
                                                // 如果點擊失敗，嘗試觸發事件
                                                const clickEvent = new MouseEvent('click', { 
                                                    bubbles: true, 
                                                    cancelable: true,
                                                    view: window
                                                });
                                                btn.dispatchEvent(clickEvent);
                                                
                                                // 也嘗試 mousedown 和 mouseup
                                                btn.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
                                                btn.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true }));
                                                
                                                return { found: true, method: 'dispatchEvent', text: text };
                                            }
                                        }
                                    }
                                    
                                    // 如果找不到包含文字的按鈕，嘗試點擊第一個主要按鈕
                                    if (allButtons.length > 0) {
                                        const firstButton = allButtons[0];
                                        if (firstButton.offsetParent !== null) {
                                            console.log('No text match found, clicking first primary button');
                                            try {
                                                firstButton.focus();
                                                firstButton.click();
                                                return { found: true, method: 'click', text: 'first primary button' };
                                            } catch (e) {
                                                const clickEvent = new MouseEvent('click', { 
                                                    bubbles: true, 
                                                    cancelable: true,
                                                    view: window
                                                });
                                                firstButton.dispatchEvent(clickEvent);
                                                return { found: true, method: 'dispatchEvent', text: 'first primary button' };
                                            }
                                        }
                                    }
                                    
                                return { found: false, message: 'No search button found' };
                            });
                            
                            if (buttonFound.found) {
                                searchButtonClicked = true;
                                console.log('✅ Search button clicked using method: ' + buttonFound.method);
                                console.log('   Button text: ' + buttonFound.text);
                                
                                // 等待搜尋結果載入
                                await new Promise(resolve => setTimeout(resolve, 2000));
                                
                                // 等待頁面穩定
                                await page.waitForFunction(() => document.readyState === 'complete', { timeout: 10000 }).catch(() => {});
                                await new Promise(resolve => setTimeout(resolve, 1000));
                            } else {
                                console.log('⚠️  Search button not found: ' + (buttonFound.message || 'Unknown error'));
                                
                                // 嘗試使用 Puppeteer 的 waitForSelector 和 click
                                try {
                                    console.log('🔍 Trying Puppeteer selector method...');
                                    await page.waitForSelector('button.ivu-btn.ivu-btn-primary', { timeout: 5000 });
                                    const button = await page.$('button.ivu-btn.ivu-btn-primary');
                                    
                                    if (button) {
                                        // 檢查按鈕文字
                                        const buttonText = await page.evaluate(btn => btn.textContent || btn.innerText, button);
                                        console.log('Found button with text: "' + buttonText + '"');
                                        
                                        if (buttonText.includes('搜尋') || buttonText.includes('搜索') || buttonText.includes('查詢')) {
                                            await button.click();
                                            searchButtonClicked = true;
                                            console.log('✅ Search button clicked using Puppeteer selector');
                                            await new Promise(resolve => setTimeout(resolve, 2000));
                                        }
                                    }
                                } catch (e) {
                                    console.log('⚠️  Puppeteer selector method failed: ' + e.message);
                                }
                            }
                            
                            if (!searchButtonClicked) {
                                console.log('⚠️  Could not find or click search button, you may need to click it manually');
                            }
                            
                        } catch (e) {
                            console.log('⚠️  Error clicking search button: ' + e.message);
                            console.error(e);
                        }
                    }
                    
                    // 如果搜索按鈕被點擊，等待數據載入
                    if (searchButtonClicked) {
                        console.log('⏳ Waiting for search results to load...');
                        
                        // 監聽網絡請求完成（特別是數據 API 請求）
                        const dataLoadedPromise = page.waitForResponse((response) => {
                            const url = response.url();
                            // 檢查是否是數據 API 請求（通常包含 betrecord, record, data, list 等關鍵字）
                            return response.status() === 200 && 
                                   (url.includes('/betrecord') || 
                                    url.includes('/record') || 
                                    url.includes('/data') || 
                                    url.includes('/list') ||
                                    url.includes('/api') ||
                                    response.headers()['content-type']?.includes('application/json'));
                        }, { timeout: 30000 }).catch(() => {
                            console.log('⚠️  No data API response detected, continuing anyway...');
                            return null;
                        });
                        
                        // 同時等待表格出現並有數據行
                        const tableReadyPromise = page.waitForFunction(() => {
                            // 查找 iView UI 表格
                            const ivuTable = document.querySelector('.ivu-table, table.ivu-table, .ivu-table-body');
                            if (ivuTable) {
                                const tbody = ivuTable.querySelector('tbody') || ivuTable.querySelector('.ivu-table-body tbody');
                                if (tbody) {
                                    const rows = tbody.querySelectorAll('tr');
                                    // 確保有數據行（不只是空表格）
                                    if (rows.length > 0) {
                                        // 檢查第一行是否有實際內容
                                        const firstRow = rows[0];
                                        const cells = firstRow.querySelectorAll('td');
                                        if (cells.length > 0) {
                                            const firstCell = cells[0];
                                            const text = firstCell.textContent.trim();
                                            // 如果第一個單元格有內容且不是"無數據"或"加載中"
                                            return text && 
                                                   !text.includes('無數據') && 
                                                   !text.includes('無資料') && 
                                                   !text.includes('Loading') &&
                                                   !text.includes('載入中');
                                        }
                                    }
                                }
                            }
                            
                            // 查找一般表格
                            const table = document.querySelector('table');
                            if (table) {
                                const tbody = table.querySelector('tbody');
                                if (tbody) {
                                    const rows = tbody.querySelectorAll('tr');
                                    if (rows.length > 0) {
                                        const firstRow = rows[0];
                                        const cells = firstRow.querySelectorAll('td');
                                        if (cells.length > 0) {
                                            const firstCell = cells[0];
                                            const text = firstCell.textContent.trim();
                                            return text && 
                                                   !text.includes('無數據') && 
                                                   !text.includes('無資料') && 
                                                   !text.includes('Loading') &&
                                                   !text.includes('載入中');
                                        }
                                    }
                                }
                            }
                            
                            return false;
                        }, { timeout: 30000 }).catch(() => {
                            console.log('⚠️  Table ready check timeout, continuing anyway...');
                            return null;
                        });
                        
                        // 等待加載指示器消失（如果有的話）
                        try {
                            await page.waitForFunction(() => {
                                // 查找常見的加載指示器
                                const loadingIndicators = [
                                    '.ivu-spin',
                                    '.loading',
                                    '.ivu-loading',
                                    '[class*="loading"]',
                                    '[class*="spinner"]'
                                ];
                                
                                for (const selector of loadingIndicators) {
                                    const element = document.querySelector(selector);
                                    if (element) {
                                        // 檢查元素是否可見
                                        const style = window.getComputedStyle(element);
                                        if (style.display !== 'none' && style.visibility !== 'hidden') {
                                            return false; // 還在加載
                                        }
                                    }
                                }
                                return true; // 沒有可見的加載指示器
                            }, { timeout: 10000 }).catch(() => {
                                console.log('⚠️  Loading indicator check timeout, continuing anyway...');
                            });
                        } catch (e) {
                            // 忽略錯誤，繼續執行
                        }
                        
                        // 等待網絡請求或表格就緒（哪個先完成就用哪個）
                        await Promise.race([
                            dataLoadedPromise,
                            tableReadyPromise,
                            new Promise(resolve => setTimeout(resolve, 5000)) // 至少等待 5 秒
                        ]);
                        
                        // 額外等待一下確保數據渲染完成
                        await new Promise(resolve => setTimeout(resolve, 2000));
                    }
                    
                    // 截圖目標頁面（填入日期和點擊搜尋後）
                    try {
                        await page.screenshot({ path: '10_after_search_clicked.png', fullPage: true });
                        console.log('📸 Screenshot 10: After search clicked saved');
                    } catch (e) {
                        console.log('⚠️  Error taking screenshot: ' + e.message);
                    }

                    // 等待表格數據載入（使用更智能的方式）
                    console.log('📄 Waiting for table data to load...');
                    let tableFound = false;
                    
                    // 嘗試使用 waitForSelector 等待表格出現
                    try {
                        await page.waitForSelector('.ivu-table table, table.ivu-table, .ivu-table-body table, table tbody', { 
                            timeout: 15000,
                            visible: true 
                        }).catch(() => {
                            console.log('⚠️  Table selector not found, trying alternative method...');
                        });
                    } catch (e) {
                        console.log('⚠️  WaitForSelector failed: ' + e.message);
                    }
                    
                    // 等待表格有數據行
                    for (let retry = 0; retry < 15; retry++) {
                        try {
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 檢查表格是否存在且有數據
                            const tableCheck = await page.evaluate(() => {
                                // 查找 iView UI 表格（可能使用 ivu-table 類）
                                const ivuTable = document.querySelector('.ivu-table, table.ivu-table, .ivu-table-body');
                                if (ivuTable) {
                                    const tbody = ivuTable.querySelector('tbody') || ivuTable.querySelector('.ivu-table-body tbody');
                                    if (tbody) {
                                        const rows = tbody.querySelectorAll('tr');
                                        if (rows.length > 0) {
                                            // 檢查第一行是否有實際數據
                                            const firstRow = rows[0];
                                            const cells = firstRow.querySelectorAll('td');
                                            if (cells.length > 0) {
                                                const firstCellText = cells[0].textContent.trim();
                                                // 確保不是空行或無數據提示
                                                if (firstCellText && 
                                                    !firstCellText.includes('無數據') && 
                                                    !firstCellText.includes('無資料') &&
                                                    !firstCellText.includes('Loading') &&
                                                    !firstCellText.includes('載入中')) {
                                                    return { found: true, rowCount: rows.length, type: 'ivu-table' };
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                // 查找一般表格
                                const table = document.querySelector('table');
                                if (table) {
                                    const tbody = table.querySelector('tbody');
                                    if (tbody) {
                                        const rows = tbody.querySelectorAll('tr');
                                        if (rows.length > 0) {
                                            // 檢查第一行是否有實際數據
                                            const firstRow = rows[0];
                                            const cells = firstRow.querySelectorAll('td');
                                            if (cells.length > 0) {
                                                const firstCellText = cells[0].textContent.trim();
                                                if (firstCellText && 
                                                    !firstCellText.includes('無數據') && 
                                                    !firstCellText.includes('無資料') &&
                                                    !firstCellText.includes('Loading') &&
                                                    !firstCellText.includes('載入中')) {
                                                    return { found: true, rowCount: rows.length, type: 'standard-table' };
                                                }
                                            }
                                        }
                                    }
                                }
                                return { found: false };
                            });
                            
                            if (tableCheck.found) {
                                tableFound = true;
                                console.log('✅ Table found after ' + (retry + 1) + ' retry(ies), type: ' + tableCheck.type + ', rows: ' + tableCheck.rowCount);
                                break;
                            } else {
                                console.log('⚠️  Retry ' + (retry + 1) + '/15: Table not found or no data yet, waiting...');
                            }
                        } catch (e) {
                            console.log('⚠️  Retry ' + (retry + 1) + '/15: Error checking table: ' + e.message);
                        }
                    }

                    // 如果表格已找到，實現無限滾動加載所有數據
                    if (tableFound) {
                        console.log('🔄 Starting infinite scroll to load all data...');
                        
                        // 獲取當前表格行數的輔助函數
                        const getTableRowCount = async () => {
                            return await page.evaluate(() => {
                                // 查找 iView UI 表格
                                const ivuTable = document.querySelector('.ivu-table, table.ivu-table, .ivu-table-body');
                                if (ivuTable) {
                                    const tbody = ivuTable.querySelector('tbody') || ivuTable.querySelector('.ivu-table-body tbody') || ivuTable;
                                    if (tbody) {
                                        const rows = tbody.querySelectorAll('tbody tr, .ivu-table-tbody tr, tr');
                                        // 過濾掉表頭行和空行
                                        return Array.from(rows).filter(row => {
                                            const cells = row.querySelectorAll('td');
                                            return cells.length > 0 && !row.querySelector('th');
                                        }).length;
                                    }
                                }
                                
                                // 查找一般表格
                                const table = document.querySelector('table');
                                if (table) {
                                    const tbody = table.querySelector('tbody');
                                    if (tbody) {
                                        const rows = tbody.querySelectorAll('tr');
                                        // 過濾掉表頭行
                                        return Array.from(rows).filter(row => {
                                            return !row.querySelector('th') && row.querySelectorAll('td').length > 0;
                                        }).length;
                                    }
                                }
                                return 0;
                            });
                        };
                        
                        // 獲取初始行數
                        let previousRowCount = await getTableRowCount();
                        console.log('📊 Initial row count: ' + previousRowCount);
                        
                        let scrollAttempts = 0;
                        const maxScrollAttempts = 200; // 最多滾動200次，防止無限循環
                        let noNewDataCount = 0;
                        const maxNoNewDataCount = 5; // 連續5次沒有新數據就停止
                        
                        while (scrollAttempts < maxScrollAttempts) {
                            scrollAttempts++;
                            console.log('⬇️  Scroll attempt ' + scrollAttempts + ' (current rows: ' + previousRowCount + ')...');
                            
                            // 滾動到頁面底部
                            await page.evaluate(() => {
                                const scrollHeight = document.body.scrollHeight || document.documentElement.scrollHeight;
                                window.scrollTo(0, scrollHeight);
                            });
                            
                            // 等待滾動完成（減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 800));
                            
                            // 監聽網絡請求（等待新的 API 請求完成）
                            const scrollDataLoadedPromise = page.waitForResponse((response) => {
                                const url = response.url();
                                // 檢查是否是數據 API 請求
                                const isDataRequest = response.status() === 200 && 
                                       (url.includes('/betrecord') || 
                                        url.includes('/record') || 
                                        url.includes('/data') || 
                                        url.includes('/list') ||
                                        url.includes('/api') ||
                                        (response.headers()['content-type'] && response.headers()['content-type'].includes('application/json')));
                                
                                if (isDataRequest) {
                                    console.log('📡 API request detected: ' + url);
                                }
                                return isDataRequest;
                            }, { timeout: 20000 }).catch(() => {
                                // 如果沒有新的 API 請求，可能已經到底了
                                return null;
                            });
                            
                            // 等待 API 請求完成或超時（減少超時時間）
                            await Promise.race([
                                scrollDataLoadedPromise,
                                new Promise(resolve => setTimeout(resolve, 3000)) // 如果沒有 API 請求，只等待 3 秒
                            ]);
                            
                            // 等待新數據渲染到表格（減少等待時間）
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 再次檢查行數
                            const newRowCount = await getTableRowCount();
                            
                            // 檢查是否有新數據
                            if (newRowCount > previousRowCount) {
                                const addedRows = newRowCount - previousRowCount;
                                console.log('✅ New data loaded! Row count increased from ' + previousRowCount + ' to ' + newRowCount + ' (+' + addedRows + ' rows)');
                                noNewDataCount = 0; // 重置計數器
                                previousRowCount = newRowCount;
                            } else {
                                noNewDataCount++;
                                console.log('⚠️  No new data loaded (' + noNewDataCount + '/' + maxNoNewDataCount + '). Current rows: ' + newRowCount);
                                
                                // 如果連續多次沒有新數據，停止滾動
                                if (noNewDataCount >= maxNoNewDataCount) {
                                    console.log('✅ No more data to load. Stopping scroll.');
                                    console.log('📊 Final row count: ' + newRowCount);
                                    break;
                                }
                            }
                            
                            // 每20次滾動顯示一次進度
                            if (scrollAttempts % 20 === 0) {
                                console.log('📊 Progress: ' + scrollAttempts + ' scrolls completed, ' + newRowCount + ' total rows loaded');
                            }
                        }
                        
                        if (scrollAttempts >= maxScrollAttempts) {
                            console.log('⚠️  Reached maximum scroll attempts (' + maxScrollAttempts + '). Stopping.');
                        }
                        
                        const finalRowCount = await getTableRowCount();
                        console.log('✅ Infinite scroll completed. Final row count: ' + finalRowCount);
                        
                        // 等待最後一次加載完成（減少等待時間）
                        await new Promise(resolve => setTimeout(resolve, 2000));
                        
                        // 滾動回頂部，方便查看數據
                        await page.evaluate(() => {
                            window.scrollTo(0, 0);
                        });
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }

                    // 提取表格數據
                    console.log('📊 Extracting table data...');
                    let tableData = { found: false, data: [], headers: [], error: null };
                    
                    if (tableFound) {
                        try {
                            tableData = await page.evaluate(() => {
                                // 嘗試查找 iView UI 表格
                                let table = document.querySelector('.ivu-table table, table.ivu-table, .ivu-table-body table');
                                if (!table) {
                                    // 如果找不到，查找任何表格
                                    const allTables = document.querySelectorAll('table');
                                    for (let t of allTables) {
                                        // 優先選擇包含 tbody 且有數據行的表格
                                        const tbody = t.querySelector('tbody');
                                        if (tbody && tbody.querySelectorAll('tr').length > 0) {
                                            table = t;
                                            break;
                                        }
                                    }
                                    // 如果還是找不到，使用第一個表格
                                    if (!table && allTables.length > 0) {
                                        table = allTables[0];
                                    }
                                }

                                if (!table) {
                                    return {
                                        found: false,
                                        error: 'No table found',
                                        debug: {
                                            tableCount: document.querySelectorAll('table').length,
                                            ivuTableExists: !!document.querySelector('.ivu-table'),
                                            bodyText: document.body ? document.body.innerText.substring(0, 200) : 'No body'
                                        }
                                    };
                                }

                                // 提取表頭
                                let headers = [];
                                const thead = table.querySelector('thead');
                                if (thead) {
                                    const headerRows = Array.from(thead.querySelectorAll('tr'));
                                    if (headerRows.length > 0) {
                                        const headerCells = headerRows[0].querySelectorAll('th, td');
                                        headers = Array.from(headerCells).map(cell => {
                                            // iView UI 表格的表頭可能在 span 或其他元素中
                                            const span = cell.querySelector('span');
                                            if (span) {
                                                return span.textContent.trim();
                                            }
                                            return cell.textContent.trim();
                                        });
                                    }
                                } else {
                                    // 如果沒有 thead，嘗試從第一個 tr 提取（可能是表頭行）
                                    const firstRow = table.querySelector('tr');
                                    if (firstRow) {
                                        const firstRowCells = firstRow.querySelectorAll('th, td');
                                        // 檢查是否是表頭行（包含 th 或樣式類似表頭）
                                        if (firstRowCells.length > 0 && (firstRowCells[0].tagName === 'TH' || firstRow.querySelector('th'))) {
                                            headers = Array.from(firstRowCells).map(cell => {
                                                const span = cell.querySelector('span');
                                                if (span) {
                                                    return span.textContent.trim();
                                                }
                                                return cell.textContent.trim();
                                            });
                                        }
                                    }
                                }

                                // 提取數據行 - iView UI 表格可能使用不同的結構
                                let rows = [];
                                let dataStartIndex = 0;
                                
                                // 優先查找 iView UI 表格的 tbody（可能是 .ivu-table-body 或 .ivu-table-tbody）
                                const ivuTableBody = document.querySelector('.ivu-table-body, .ivu-table-tbody');
                                const tbody = table.querySelector('tbody') || 
                                             (ivuTableBody ? ivuTableBody.querySelector('tbody') : null) ||
                                             ivuTableBody;
                                
                                if (tbody) {
                                    rows = Array.from(tbody.querySelectorAll('tr'));
                                    // 如果還是沒有行，嘗試在整個 tbody 中查找（可能是虛擬滾動）
                                    if (rows.length === 0) {
                                        rows = Array.from(tbody.querySelectorAll('tbody tr, .ivu-table-tbody tr, tr'));
                                    }
                                } else {
                                    // 嘗試查找 .ivu-table-body 中的行
                                    const ivuBody = document.querySelector('.ivu-table-body');
                                    if (ivuBody) {
                                        rows = Array.from(ivuBody.querySelectorAll('tr'));
                                    }
                                    
                                    // 如果還是沒有，從整個表格查找
                                    if (rows.length === 0) {
                                        rows = Array.from(table.querySelectorAll('tr'));
                                        // 如果有表頭，跳過第一行
                                        if (thead || (rows.length > 0 && rows[0].querySelectorAll('th').length > 0)) {
                                            dataStartIndex = 1;
                                        }
                                    }
                                }
                                
                                // 調試信息：記錄找到的行數
                                console.log('Found ' + rows.length + ' rows in tbody, dataStartIndex: ' + dataStartIndex);

                                // 將數據行轉換為對象數組
                                const dataRows = rows.slice(dataStartIndex)
                                    .map((row, rowIndex) => {
                                        const cells = Array.from(row.querySelectorAll('td'));
                                        
                                        // 跳過表頭行（如果包含 th）
                                        if (row.querySelector('th')) {
                                            return null;
                                        }
                                        
                                        // 如果沒有 td，跳過這一行
                                        if (cells.length === 0) {
                                            return null;
                                        }
                                        
                                        const rowData = {};
                                        
                                        if (headers && headers.length > 0) {
                                            headers.forEach((header, colIndex) => {
                                                // 清理字段名
                                                let cleanHeader = header
                                                    .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                    .replace(/^_+|_+$/g, '');
                                                
                                                if (!cleanHeader || cleanHeader === '') {
                                                    cleanHeader = 'column_' + colIndex;
                                                }
                                                
                                                // 確保字段名唯一
                                                let finalHeader = cleanHeader;
                                                let counter = 1;
                                                while (rowData.hasOwnProperty(finalHeader)) {
                                                    finalHeader = cleanHeader + '_' + counter;
                                                    counter++;
                                                }
                                                
                                                // 提取單元格內容（iView UI 可能使用 span 或其他元素）
                                                let cellValue = null;
                                                if (cells[colIndex]) {
                                                    // 嘗試多種方式提取內容
                                                    const cell = cells[colIndex];
                                                    
                                                    // 優先查找 .ivu-table-cell 或特定類名
                                                    const cellContent = cell.querySelector('.ivu-table-cell, .ivu-table-cell-main, span, div');
                                                    
                                                    if (cellContent) {
                                                        cellValue = cellContent.textContent.trim();
                                                    } else {
                                                        // 直接獲取文本內容
                                                        cellValue = cell.textContent.trim();
                                                    }
                                                    
                                                    // 如果還是空的，嘗試 innerText
                                                    if (!cellValue) {
                                                        cellValue = cell.innerText.trim();
                                                    }
                                                }
                                                rowData[finalHeader] = cellValue;
                                            });
                                        } else {
                                            // 如果沒有表頭，使用索引作為 key
                                            cells.forEach((cell, colIndex) => {
                                                const cellContent = cell.querySelector('.ivu-table-cell, .ivu-table-cell-main, span, div');
                                                let cellValue = null;
                                                if (cellContent) {
                                                    cellValue = cellContent.textContent.trim();
                                                } else {
                                                    cellValue = cell ? cell.textContent.trim() : null;
                                                }
                                                if (!cellValue) {
                                                    cellValue = cell ? cell.innerText.trim() : null;
                                                }
                                                rowData['column_' + colIndex] = cellValue;
                                            });
                                        }
                                        
                                        // 添加原始行索引
                                        rowData._rowIndex = rowIndex;
                                        
                                        return rowData;
                                    })
                                    .filter(rowData => {
                                        // 過濾掉 null（表頭行或空行）
                                        if (!rowData) return false;
                                        
                                        // 過濾掉完全空的行
                                        const values = Object.values(rowData).filter(v => {
                                            if (v === null || v === undefined) return false;
                                            if (typeof v === 'string' && v.trim() === '') return false;
                                            if (typeof v === 'number' && v === '_rowIndex') return false; // 保留 _rowIndex
                                            return true;
                                        });
                                        
                                        // 如果只有 _rowIndex，則認為是空行
                                        if (values.length <= 1 && rowData._rowIndex !== undefined) return false;
                                        
                                        // 檢查是否包含"小計"或"總計"
                                        const firstValue = values[0];
                                        if (typeof firstValue === 'string' && (firstValue.includes('小計') || firstValue.includes('總計'))) {
                                            return false;
                                        }
                                        
                                        return true;
                                    });
                                
                                // 調試信息：記錄提取的行數
                                console.log('Extracted ' + dataRows.length + ' data rows');

                                // 如果沒有找到數據行，提供調試信息
                                if (dataRows.length === 0) {
                                    const debugInfo = {
                                        tableFound: !!table,
                                        tableTag: table ? table.tagName : null,
                                        tableClass: table ? table.className : null,
                                        theadFound: !!thead,
                                        tbodyFound: !!tbody,
                                        tbodyTag: tbody ? tbody.tagName : null,
                                        tbodyClass: tbody ? tbody.className : null,
                                        ivuTableBodyFound: !!ivuTableBody,
                                        totalRowsFound: rows.length,
                                        dataStartIndex: dataStartIndex,
                                        firstRowHTML: rows.length > 0 ? rows[0].outerHTML.substring(0, 500) : null,
                                        allTableBodies: Array.from(document.querySelectorAll('tbody, .ivu-table-body, .ivu-table-tbody')).map(el => ({
                                            tag: el.tagName,
                                            class: el.className,
                                            rowCount: el.querySelectorAll('tr').length
                                        }))
                                    };
                                    
                                    return {
                                        found: true,
                                        headers: headers,
                                        data: dataRows,
                                        rowCount: dataRows.length,
                                        debug: debugInfo
                                    };
                                }
                                
                                return {
                                    found: true,
                                    headers: headers,
                                    data: dataRows,
                                    rowCount: dataRows.length
                                };
                            });
                            
                            if (tableData.found) {
                                if (tableData.rowCount > 0) {
                                    console.log('✅ Successfully extracted ' + tableData.rowCount + ' rows from table');
                                    console.log('📋 Table headers: ' + tableData.headers.join(', '));
                                } else {
                                    console.log('⚠️  Table found but no data rows extracted (rowCount: 0)');
                                    console.log('📋 Table headers found: ' + tableData.headers.join(', '));
                                    if (tableData.debug) {
                                        console.log('📋 Debug info:', JSON.stringify(tableData.debug, null, 2));
                                    }
                                }
                            } else {
                                console.log('⚠️  Table data extraction failed: ' + (tableData.error || 'Unknown error'));
                                if (tableData.debug) {
                                    console.log('📋 Debug info:', JSON.stringify(tableData.debug, null, 2));
                                }
                            }
                        } catch (e) {
                            console.log('⚠️  Error extracting table data: ' + e.message);
                            tableData.error = e.message;
                        }
                    } else {
                        console.log('⚠️  Table not found after waiting, attempting extraction anyway...');
                        // 即使沒找到，也嘗試提取
                        try {
                            tableData = await page.evaluate(() => {
                                const allTables = document.querySelectorAll('table');
                                return {
                                    found: allTables.length > 0,
                                    tableCount: allTables.length,
                                    tableClasses: Array.from(allTables).map(t => t.className),
                                    bodyText: document.body ? document.body.innerText.substring(0, 300) : 'No body'
                                };
                            });
                        } catch (e) {
                            console.log('⚠️  Error during fallback extraction: ' + e.message);
                        }
                    }

                    // 返回結果
                    const result = {
                        success: true,
                        url: '$url',
                        cookies: cookies,
                        authData: authData,
                        tableData: tableData,
                        message: tableData.found ? 'Data extraction completed' : 'Login completed, but table data extraction failed'
                    };

                    // 保存結果
                    fs.writeFileSync('scrape_result.json', JSON.stringify(result, null, 2));

                    await browser.close();
                    return result;
                } catch (error) {
                    console.error('❌ Error:', error);
                    const errorResult = {
                        success: false,
                        error: error.message
                    };
                    fs.writeFileSync('scrape_result.json', JSON.stringify(errorResult, null, 2));
                    await browser.close();
                    throw error;
                }
            }

            scrapeDOMContent().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_atgslot.js');
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
        // 增加超时时间到 60 分钟（3600秒），因为无限滚动加载可能需要很长时间
        $this->info('⏳ Script timeout set to 60 minutes (infinite scroll may take a while)...');
        $result = Process::path($workingDir)->timeout(3600)->run("node " . basename($scriptPath));

        if ($result->failed()) {
            $this->error("❌ Script execution failed");
            $this->line("Error: " . $result->errorOutput());
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
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        // 處理截圖文件
        $this->processScreenshots($workingDir, $timestamp);

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            
            // 如果有表格數據，保存表格數據到單獨的文件
            if (isset($result['tableData']) && is_array($result['tableData'])) {
                $tableData = $result['tableData'];
                
                if (isset($tableData['found']) && $tableData['found'] && isset($tableData['data'])) {
                    $tableFileName = "scraped_data/atgslot_table_data_{$timestamp}.json";
                    $rowCount = count($tableData['data'] ?? []);
                    $tableFileData = [
                        'metadata' => [
                            'timestamp' => $timestamp,
                            'url' => $result['url'] ?? '',
                            'rowCount' => $rowCount,
                            'headers' => $tableData['headers'] ?? [],
                        ],
                        'headers' => $tableData['headers'] ?? [],
                        'data' => $tableData['data'] ?? [],
                    ];
                    
                    // 如果沒有數據行但有調試信息，也保存調試信息
                    if ($rowCount === 0 && isset($tableData['debug'])) {
                        $tableFileData['debug'] = $tableData['debug'];
                        $this->warn('⚠️  No data rows extracted (rowCount: 0)');
                        $this->line('   Debug info saved to file');
                    }
                    
                    Storage::put($tableFileName, json_encode($tableFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->info("✅ Table data saved: {$tableFileName}");
                    $this->info("   Rows extracted: {$rowCount}");
                } else {
                    $this->warn('⚠️  Table data extraction failed: ' . ($tableData['error'] ?? 'Unknown error'));
                    if (isset($tableData['debug'])) {
                        $this->line('   Debug info: ' . json_encode($tableData['debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }
            } else {
                $this->warn('⚠️  No table data found in result');
            }
        } else {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }

    /**
     * 處理截圖文件，將它們從臨時目錄移動到永久儲存目錄（與數據文件同資料夾）
     * @param string $workingDir 工作目錄（臨時目錄）
     * @param string $timestamp 時間戳
     */
    private function processScreenshots($workingDir, $timestamp)
    {
        $this->info('📸 Processing screenshots...');
        
        // 截圖文件名模式
        $screenshotPatterns = [
            '01_initial_login_page.png',
            '02_after_account_filled.png',
            '03_after_password_filled.png',
            '04_after_login_button_clicked.png',
            '05_verification_code_input_found.png',
            '05_verification_code_input_not_found.png',
            '05_waiting_for_verification_*.png',
            '06_after_verification_code_filled_auto.png',
            '06_after_verification_code_filled_manual.png',
            '06_verification_code_timeout.png',
            '06_waiting_for_manual_code_*.png',
            '07_after_confirm_button_clicked.png',
            '08_login_completed_final_page.png',
            '10_after_search_clicked.png',
        ];
        
        // 截圖直接保存在 scraped_data 資料夾，與數據文件同級（不使用子資料夾）
        $screenshotsDir = storage_path('app/scraped_data');
        if (!is_dir($screenshotsDir)) {
            mkdir($screenshotsDir, 0755, true);
        }
        
        $screenshotCount = 0;
        
        // 處理所有截圖文件
        foreach ($screenshotPatterns as $pattern) {
            // 處理通配符模式
            if (strpos($pattern, '*') !== false) {
                $globPattern = str_replace('*', '*', $pattern);
                $files = glob($workingDir . '/' . $globPattern);
                foreach ($files as $file) {
                    if (file_exists($file)) {
                        $filename = basename($file);
                        // 添加時間戳前綴以避免文件名衝突
                        $destFilename = "atgslot_{$timestamp}_{$filename}";
                        $destPath = $screenshotsDir . '/' . $destFilename;
                        if (rename($file, $destPath)) {
                            $screenshotCount++;
                            $this->line("   ✅ {$destFilename}");
                        }
                    }
                }
            } else {
                // 處理固定文件名
                $srcPath = $workingDir . '/' . $pattern;
                if (file_exists($srcPath)) {
                    // 添加時間戳前綴以避免文件名衝突
                    $destFilename = "atgslot_{$timestamp}_{$pattern}";
                    $destPath = $screenshotsDir . '/' . $destFilename;
                    if (rename($srcPath, $destPath)) {
                        $screenshotCount++;
                        $this->line("   ✅ {$destFilename}");
                    }
                }
            }
        }
        
        // 也嘗試移動所有以數字開頭的 PNG 文件（備用方案）
        $allScreenshots = glob($workingDir . '/[0-9]*.png');
        foreach ($allScreenshots as $file) {
            $filename = basename($file);
            // 添加時間戳前綴以避免文件名衝突
            $destFilename = "atgslot_{$timestamp}_{$filename}";
            $destPath = $screenshotsDir . '/' . $destFilename;
            if (!file_exists($destPath) && rename($file, $destPath)) {
                $screenshotCount++;
                $this->line("   ✅ {$destFilename}");
            }
        }
        
        if ($screenshotCount > 0) {
            $this->info("✅ {$screenshotCount} screenshot(s) saved to: {$screenshotsDir}");
        } else {
            $this->warn('⚠️  No screenshots found');
        }
    }
}

