<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
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
            $this->processScrapedData($result, $date_start, $date_end);
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

        // 如果設定了 loginInfo，則使用 loginInfo 直接設置登入狀態
        if (!empty($loginInfo)) {
            $loginCodeForPage = $this->generateAtgslotPuppeteerLoginInfoCode('page', $loginInfo);
        } else {
            $this->info('❌ 未設定 ATGSLOT_AGENT_LOGIN_INFO，請先在 .env 文件中設定');
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

        // 若同時有開始與結束日期，產生逐日列表（一天一天查）
        $dateList = [];
        if ($date_start && $date_end && $dateStartFormatted && $dateEndFormatted) {
            $startTs = strtotime($dateStartFormatted);
            $endTs = strtotime($dateEndFormatted);
            for ($t = $startTs; $t <= $endTs; $t += 86400) {
                $dateList[] = date('Y-m-d', $t);
            }
        }
        $dateListJs = json_encode($dateList);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容
             */
            async function scrapeDOMContent() {
                // 啟動無頭瀏覽器（headless mode）
                const browser = await puppeteer.launch({
                    headless: 'new',
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
                        '--disable-sync'
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
                    
                    const dateList = $dateListJs;
                    let result;
                    if (dateList && Array.isArray(dateList) && dateList.length > 0) {
                        // 逐日模式：每天 Start = End = 當日，搜尋後擷取摘要並收集
                        console.log('📅 Daily mode: scraping ' + dateList.length + ' day(s) from ' + dateList[0] + ' to ' + dateList[dateList.length - 1]);
                        const dailyData = [];
                        const extractTagsInPage = async () => {
                            return await page.evaluate(() => {
                                const keywords = ['Profit', 'Unique player', 'Win/Bet amount', 'Win/Bet count'];
                                let tagEls = document.querySelectorAll('.ivu-col.ivu-col-span-24 .ivu-tag .ivu-tag-text, .ivu-col.ivu-col-span-24 .ivu-tag-size-large .ivu-tag-text');
                                if (tagEls.length === 0) tagEls = document.querySelectorAll('.ivu-tag .ivu-tag-text, .ivu-tag-size-large .ivu-tag-text, .ivu-tag-text');
                                const all = Array.from(tagEls).map(el => (el.textContent || el.innerText || '').trim()).filter(t => t.length > 0);
                                const summary = all.filter(t => keywords.some(kw => t.indexOf(kw) >= 0));
                                return summary.length > 0 ? summary : all;
                            });
                        };
                        const clickSearchButton = async () => {
                            const buttonFound = await page.evaluate(() => {
                                const allButtons = Array.from(document.querySelectorAll('button.ivu-btn.ivu-btn-primary, button.ivu-btn-primary, .ivu-btn-primary'));
                                for (const btn of allButtons) {
                                    const text = (btn.textContent || btn.innerText || '').trim().toLowerCase();
                                    if (text.includes('搜尋') || text.includes('搜索') || text.includes('查詢') || text.includes('search')) {
                                        if (btn.offsetParent !== null) { btn.click(); return true; }
                                    }
                                }
                                if (allButtons.length > 0 && allButtons[0].offsetParent !== null) { allButtons[0].click(); return true; }
                                return false;
                            });
                            return !!buttonFound;
                        };
                        for (let i = 0; i < dateList.length; i++) {
                            const dateStr = dateList[i];
                            console.log('📅 Day ' + (i + 1) + '/' + dateList.length + ': ' + dateStr);
                            await page.evaluate((dateValue, index) => {
                                const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="Select date"]');
                                if (inputs[index] != null) {
                                    const input = inputs[index];
                                    input.value = '';
                                    input.value = dateValue;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                            }, dateStr, 0);
                            await new Promise(resolve => setTimeout(resolve, 300));
                            await page.evaluate((dateValue, index) => {
                                const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="Select date"]');
                                if (inputs[index] != null) {
                                    const input = inputs[index];
                                    input.value = '';
                                    input.value = dateValue;
                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                            }, dateStr, 1);
                            await new Promise(resolve => setTimeout(resolve, 800));
                            await clickSearchButton();
                            await new Promise(resolve => setTimeout(resolve, 4000));
                            const tags = await extractTagsInPage();
                            dailyData.push({ Date: dateStr, data: tags || [] });
                            if (tags && tags.length > 0) {
                                console.log('   ✅ ' + tags.length + ' tag(s)');
                            } else {
                                console.log('   ⚠️  No tags');
                            }
                            try {
                                await page.screenshot({ path: 'daily_' + dateStr + '.png', fullPage: true });
                                console.log('   📸 Screenshot saved: daily_' + dateStr + '.png');
                            } catch (e) {
                                console.log('   ⚠️  Screenshot failed: ' + e.message);
                            }
                            await new Promise(resolve => setTimeout(resolve, 1000));
                        }
                        result = {
                            success: true,
                            url: '$url',
                            cookies: cookies,
                            authData: authData,
                            dailyData: dailyData,
                            message: 'Daily data extracted: ' + dailyData.length + ' day(s)'
                        };
                    } else {
                    // 單次查詢模式：填入日期範圍（如果提供了 date_start 或 date_end）
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
                            const dateInputs = await page.$$('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="Select date"]');
                            console.log('📋 Found ' + dateInputs.length + ' date input(s)');
                            
                            if (dateInputs.length >= 2) {
                                // 第一個輸入框填入開始日期
                                if (dateStartValue) {
                                    console.log('📝 Filling start date: ' + dateStartValue);
                                    await page.evaluate((dateValue, index) => {
                                        const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="Select date"]');
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
                                        const inputs = document.querySelectorAll('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="Select date"]');
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
                                        const input = document.querySelector('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="Select date"]');
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
                                        const input = document.querySelector('input.ivu-input.ivu-input-default.ivu-input-with-suffix[placeholder="Select date"]');
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
                        
                        // 等待紅框內的摘要 tag 出現（Profit、Unique player、Win/Bet amount、Win/Bet count）
                        const tagsReadyPromise = page.waitForFunction(() => {
                            const tagTexts = document.querySelectorAll('.ivu-tag .ivu-tag-text, .ivu-tag-size-large .ivu-tag-text');
                            return tagTexts.length >= 1;
                        }, { timeout: 30000 }).catch(() => {
                            console.log('⚠️  Summary tags check timeout, continuing anyway...');
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
                        
                        // 等待網絡請求或摘要 tag 就緒（哪個先完成就用哪個）
                        await Promise.race([
                            dataLoadedPromise,
                            tagsReadyPromise,
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

                    // 等待紅框內的摘要 tag 載入（不爬取 table），多等一會讓 API 回傳後再擷取
                    console.log('📄 Waiting for summary tags (Profit, Unique player, Win/Bet amount, Win/Bet count)...');
                    const tagSelectors = ['.ivu-tag .ivu-tag-text', '.ivu-tag-size-large .ivu-tag-text', '.ivu-tag-text', '[class*="ivu-tag"] span'];
                    let tagSelectorFound = false;
                    for (const sel of tagSelectors) {
                        try {
                            await page.waitForSelector(sel, { timeout: 8000, visible: true });
                            tagSelectorFound = true;
                            console.log('✅ Summary tag selector found: ' + sel);
                            break;
                        } catch (e) {
                            console.log('⚠️  Selector timeout: ' + sel);
                        }
                    }
                    if (!tagSelectorFound) {
                        console.log('⚠️  No summary tag selector matched, will try extraction anyway');
                    }
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    // 提取紅框內的摘要 tag 資料（可重試幾次，等資料渲染）
                    console.log('📊 Extracting summary tag data...');
                    let tagData = { found: false, tags: [], error: null };
                    const extractTags = async () => {
                        return await page.evaluate(() => {
                            const keywords = ['Profit', 'Unique player', 'Win/Bet amount', 'Win/Bet count'];
                            // 先試 .ivu-col-span-24 內
                            let tagEls = document.querySelectorAll('.ivu-col.ivu-col-span-24 .ivu-tag .ivu-tag-text, .ivu-col.ivu-col-span-24 .ivu-tag-size-large .ivu-tag-text');
                            if (tagEls.length === 0) {
                                tagEls = document.querySelectorAll('.ivu-tag .ivu-tag-text, .ivu-tag-size-large .ivu-tag-text, .ivu-tag-text');
                            }
                            const all = Array.from(tagEls).map(el => (el.textContent || el.innerText || '').trim()).filter(t => t.length > 0);
                            const summary = all.filter(t => keywords.some(kw => t.indexOf(kw) >= 0));
                            return summary.length > 0 ? summary : all;
                        });
                    };
                    for (let retry = 0; retry < 5; retry++) {
                        try {
                            const tags = await extractTags();
                            if (tags && tags.length > 0) {
                                tagData = { found: true, tags: tags, error: null };
                                console.log('✅ Extracted ' + tagData.tags.length + ' summary tag(s): ' + tagData.tags.join(' | '));
                                break;
                            }
                            if (retry < 4) {
                                console.log('⚠️  No summary tags yet, retry ' + (retry + 1) + '/5 in 2s...');
                                await new Promise(resolve => setTimeout(resolve, 2000));
                            }
                        } catch (e) {
                            console.log('⚠️  Error extracting tag data: ' + e.message);
                            tagData.error = e.message;
                            break;
                        }
                    }
                    if (!tagData.found && !tagData.error) {
                        console.log('⚠️  No summary tags found after retries');
                    }

                    // 單次模式結果
                    result = {
                        success: true,
                        url: '$url',
                        cookies: cookies,
                        authData: authData,
                        tagData: tagData,
                        message: tagData.found ? 'Summary tag data extracted' : 'Login completed, but summary tag extraction failed'
                    };
                    }

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
     * @param string|null $date_start 查詢開始日期（可選）
     * @param string|null $date_end 查詢結束日期（可選）
     */
    private function processScrapedData($result, $date_start = null, $date_end = null)
    {
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        // 處理截圖文件
        $this->processScreenshots($workingDir, $timestamp);

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));

            // 組日期字串（Date）：若有 date_start/date_end 則為區間，否則為當日
            $formatDate = function ($d) {
                if (!$d) {
                    return '';
                }
                return preg_match('/^\d{8}$/', $d) ? substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) : $d;
            };
            $start = $formatDate($date_start);
            $end = $formatDate($date_end);
            $dateStr = ($start && $end) ? ($start . ' ~ ' . $end) : ($start ?: $end ?: date('Y-m-d'));

            // 移除千分位並格式化成小數點兩位
            $formatValue = function ($v) {
                $v = str_replace(',', '', trim($v));
                if (preg_match('/^(.+)%$/u', $v, $m) && is_numeric(trim($m[1]))) {
                    return number_format((float)trim($m[1]), 2, '.', '') . '%';
                }
                if (is_numeric($v)) {
                    return number_format((float)$v, 2, '.', '');
                }
                return $v;
            };
            // 將 tag 陣列轉成物件：key 為欄位名、value 為數值（千分位已移除、小數點兩位）
            // Profit 拆成 Profit、Profit rate；Win/Bet amount 拆成 Win amount、Bet amount；Win/Bet count 拆成 Win count、Bet count
            $expandSummaryTags = function (array $tags) use ($formatValue) {
                $out = [];
                foreach ($tags as $t) {
                    if (preg_match('/^Profit:\s*(.+?)\(([^)]+%)\)\s*$/u', $t, $m)) {
                        $out['Total_Win_Loss'] = $formatValue(trim($m[1]));
                    } elseif (preg_match('/^Profit:\s*(.+)$/u', $t, $m)) {
                        $out['Total_Win_Loss'] = $formatValue(trim($m[1]));
                    } elseif (preg_match('/^Win\/Bet amount:\s*(.+?)\s*\/\s*(.+)$/u', $t, $m)) {
                        $out['Total_Bet_Amount'] = $formatValue(trim($m[2]));
                    } elseif (preg_match('/^Win\/Bet count:\s*(.+?)\s*\/\s*(.+)$/u', $t, $m)) {
                        $out['Total_Bet_Times'] = $formatValue(trim($m[2]));
                    } elseif (preg_match('/^([^:]+):\s*(.+)$/u', $t, $m)) {
                        $out[trim($m[1])] = $formatValue(trim($m[2]));
                    }
                }
                return $out;
            };

            // 逐日模式：保存 dailyData 為一份 JSON（每天一筆 { Date, data }，data 為物件）
            if (isset($result['dailyData']) && is_array($result['dailyData'])) {
                $tagFileName = "scraped_data/atgslot_tag_data_{$timestamp}.json";
                $dailyDataExpanded = array_map(function ($day) use ($expandSummaryTags) {
                    $fields = $expandSummaryTags($day['data'] ?? []);
                    return array_merge(['Date' => $day['Date'] ?? ''], $fields);
                }, $result['dailyData']);
                $tagFileData = [
                    'Date' => $dateStr,
                    'metadata' => [
                        'timestamp' => $timestamp,
                        'url' => $result['url'] ?? '',
                        'dayCount' => count($result['dailyData']),
                    ],
                    'data' => $dailyDataExpanded,
                ];
                Storage::put($tagFileName, json_encode($tagFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("✅ Daily summary data saved: {$tagFileName} (" . count($result['dailyData']) . " days)");
            }
            // 單次查詢模式：保存紅框內的摘要資料（data 為物件）
            elseif (isset($result['tagData']) && is_array($result['tagData']) && !empty($result['tagData']['tags'])) {
                $tagData = $result['tagData'];
                $dataObj = $expandSummaryTags($tagData['tags'] ?? []);
                $tagFileName = "scraped_data/atgslot_tag_data_{$timestamp}.json";
                $tagFileData = [
                    'Date' => $dateStr,
                    'metadata' => [
                        'timestamp' => $timestamp,
                        'url' => $result['url'] ?? '',
                    ],
                    'data' => $dataObj,
                ];
                Storage::put($tagFileName, json_encode($tagFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("✅ Summary tag data saved: {$tagFileName}");
                foreach ($dataObj as $key => $value) {
                    $this->line("   {$key}: {$value}");
                }
            } elseif (isset($result['tagData']['error'])) {
                $this->warn('⚠️  Summary tag extraction failed: ' . $result['tagData']['error']);
            } else {
                $this->warn('⚠️  No summary tag data in result');
            }
            
            // 若有表格數據也可保存（此腳本已改為只爬 tag，不爬 table）
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
            'daily_*.png',  // 逐日模式：每天的查詢結果截圖
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

