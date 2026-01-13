<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - ZGSLOT (手動輸入模式)
 * 此命令會打開非無頭瀏覽器，讓使用者手動輸入帳號密碼及驗證碼
 */
class ScrapeBrowserZgslotDOMDetail extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-zgslot-dom-detail {url} {start_date?} {end_date?}
     * {url} - 要爬取的目標網址（必需參數）
     * {start_date?} - 開始日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     * {end_date?} - 結束日期（格式：YYYYMMDD 或 YYYY-MM-DD，可選）
     */
    protected $signature = 'agent:scrape-zgslot-dom-detail {url} {start_date?} {end_date?}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from ZGSLOT DOM elements using browser automation with manual login input';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $startDateRaw = $this->argument('start_date');
        $endDateRaw = $this->argument('end_date');
        
        // 轉換日期格式（支持 YYYYMMDD 和 YYYY-MM-DD）
        $startDate = $this->normalizeDate($startDateRaw);
        $endDate = $this->normalizeDate($endDateRaw);

        $this->info('=== ZGSLOT DOM Data Scraper (Manual Input Mode) ===');
        $this->info("Target URL: {$url}");
        if ($startDate) {
            $this->info("Start Date: {$startDate}");
        }
        if ($endDate) {
            $this->info("End Date: {$endDate}");
        }
        $this->info("📋 Dialog scraping: ALL rows");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 從環境變數獲取登入域名
        $domain = env('ZGSLOT_AGENT_DOMAIN', '');
        
        if (empty($domain)) {
            $this->error('❌ ZGSLOT_AGENT_DOMAIN environment variable is not set');
            $this->line('Please set ZGSLOT_AGENT_DOMAIN in your .env file');
            return 1;
        }
        
        // 從環境變數獲取登入帳號和密碼
        $account = env('ZGSLOT_AGENT_ACCOUNT', '');
        $password = env('ZGSLOT_AGENT_PASSWORD', '');
        

        // 創建 Puppeteer 腳本（手動輸入模式）
        $scriptPath = $this->createPuppeteerScript($domain, $url, $startDate, $endDate, $account, $password);

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
     * 標準化日期格式
     * 將 YYYYMMDD 轉換為 YYYY-MM-DD
     * @param string|null $date 日期字符串
     * @return string|null 標準化後的日期字符串
     */
    private function normalizeDate($date)
    {
        if (empty($date)) {
            return null;
        }
        
        // 如果已經是 YYYY-MM-DD 格式，直接返回
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // 如果是 YYYYMMDD 格式，轉換為 YYYY-MM-DD
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        
        // 如果格式不正確，返回原值（讓 JavaScript 端處理錯誤）
        return $date;
    }

    /**
     * 創建 Puppeteer 自動化腳本（手動輸入模式）
     * @param string $domain 登入頁面網址
     * @param string $url 要爬取的目標網址
     * @param string|null $startDate 開始日期（格式：YYYY-MM-DD）
     * @param string|null $endDate 結束日期（格式：YYYY-MM-DD）
     * @param string|null $account 登入帳號
     * @param string|null $password 登入密碼
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($domain, $url, $startDate = null, $endDate = null, $account = null, $password = null)
    {
        $this->info('2. Creating browser automation script...');

        // 轉義 JavaScript 字符串
        $domainJs = json_encode($domain);
        $urlJs = json_encode($url);
        $startDateJs = json_encode($startDate);
        $endDateJs = json_encode($endDate);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);
        
        // 獲取工作目錄的絕對路徑
        $workingDir = storage_path('app/temp');
        $workingDirJs = json_encode($workingDir);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            const readline = require('readline');
            
            // 工作目錄（json_encode 已經生成了正確的 JavaScript 字符串）
            const workingDir = $workingDirJs;

            /**
             * 打開 "All Columns" 開關的輔助函數
             */
            async function openAllColumnsToggle(page) {
                try {
                    await new Promise(resolve => setTimeout(resolve, 1000)); // 等待頁面穩定
                    
                    // 查找開關（通過多種方式：ID、文本內容等，兼容中英文）
                    const toggleOpened = await page.evaluate(() => {
                        // 方法1: 通過 ID 查找（支持多個可能的 ID）
                        let toggleInput = document.querySelector('input#mat-slide-toggle-1-input') ||
                                         document.querySelector('input#mat-slide-toggle-2-input');
                        
                        // 方法2: 如果沒找到，通過文本內容查找
                        if (!toggleInput) {
                            const labels = Array.from(document.querySelectorAll('label.mat-slide-toggle-label'));
                            const label = labels.find(lbl => {
                                const text = (lbl.textContent || lbl.innerText || '').trim();
                                return text.includes('All Columns');
                            });
                            
                            if (label) {
                                toggleInput = label.querySelector('input[type="checkbox"]');
                            }
                        }
                        
                        // 方法3: 查找所有 mat-slide-toggle 的 input
                        if (!toggleInput) {
                            const toggles = Array.from(document.querySelectorAll('mat-slide-toggle input[type="checkbox"]'));
                            for (const toggle of toggles) {
                                const label = toggle.closest('label.mat-slide-toggle-label');
                                if (label) {
                                    const text = (label.textContent || label.innerText || '').trim();
                                    if (text.includes('All Columns')) {
                                        toggleInput = toggle;
                                        break;
                                    }
                                }
                            }
                        }
                        
                        if (toggleInput) {
                            // 檢查是否已打開
                            if (toggleInput.checked) {
                                return { found: true, alreadyOn: true };
                            } else {
                                // 點擊開關（點擊 label 或 input）
                                const label = toggleInput.closest('label.mat-slide-toggle-label');
                                if (label) {
                                    label.click();
                                } else {
                                    toggleInput.click();
                                }
                                
                                // 觸發事件確保 Angular 檢測到變化
                                toggleInput.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
                                toggleInput.dispatchEvent(new Event('click', { bubbles: true, cancelable: true }));
                                
                                return { found: true, alreadyOn: false, toggled: true };
                            }
                        }
                        
                        return { found: false };
                    });
                    
                    if (toggleOpened.found && !toggleOpened.alreadyOn) {
                        // 等待開關狀態更新
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }
                } catch (e) {
                    console.log('⚠️  Error opening "All Columns" toggle: ' + e.message);
                    // 不阻止後續流程，繼續執行
                }
            }

            /**
             * 設置每頁筆數的輔助函數
             */
            async function setPageSize(page, size) {
                try {
                    console.log('📄 Setting page size to ' + size + '...');
                    
                    // 查找分頁器的每頁筆數選擇器
                    const pageSizeSelect = await page.$('mat-select#mat-select-4, mat-select[aria-label*="每页笔数"], mat-select[aria-label*="每頁筆數"]').catch(() => null);
                    
                    if (!pageSizeSelect) {
                        // 嘗試其他選擇器
                        const selectors = [
                            'mat-select.mat-paginator-page-size-select',
                            'mat-select.mat-select',
                            '.mat-paginator-page-size-select mat-select'
                        ];
                        
                        for (const selector of selectors) {
                            const select = await page.$(selector).catch(() => null);
                            if (select) {
                                await select.click();
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                // 等待下拉菜單出現
                                const option = await page.waitForSelector('mat-option .mat-option-text', { timeout: 5000 }).catch(() => null);
                                if (option) {
                                    // 查找包含目標數字的選項
                                    const targetOption = await page.evaluateHandle((targetSize) => {
                                        const options = Array.from(document.querySelectorAll('mat-option'));
                                        return options.find(opt => {
                                            const text = opt.textContent || opt.innerText || '';
                                            return text.includes(targetSize.toString());
                                        });
                                    }, size).catch(() => null);
                                    
                                    if (targetOption && targetOption.asElement()) {
                                        await targetOption.asElement().click();
                                        console.log('✅ Page size set to ' + size);
                                        await new Promise(resolve => setTimeout(resolve, 3000));
                                        // 設置每頁筆數後可能導致頁面閃爍，重新打開 "All Columns" 開關
                                        await openAllColumnsToggle(page);
                                        return;
                                    }
                                }
                            }
                        }
                        
                        console.log('⚠️  Page size selector not found, trying alternative method...');
                    } else {
                        // 點擊選擇器打開下拉菜單
                        await pageSizeSelect.click();
                        console.log('✅ Page size selector clicked');
                        await new Promise(resolve => setTimeout(resolve, 1000));
                        
                        // 等待下拉菜單出現並選擇目標值
                        const targetOption = await page.evaluateHandle((targetSize) => {
                            const options = Array.from(document.querySelectorAll('mat-option'));
                            return options.find(opt => {
                                const text = (opt.textContent || opt.innerText || '').trim();
                                return text === targetSize.toString() || text.includes(targetSize.toString());
                            });
                        }, size).catch(() => null);
                        
                        if (targetOption && targetOption.asElement()) {
                            await targetOption.asElement().click();
                            console.log('✅ Page size set to ' + size);
                            
                            // 等待數據重新載入
                            await new Promise(resolve => setTimeout(resolve, 3000));
                            
                            // 設置每頁筆數後可能導致頁面閃爍，重新打開 "All Columns" 開關
                            await openAllColumnsToggle(page);
                        } else {
                            console.log('⚠️  Option with value ' + size + ' not found');
                            // 按 ESC 關閉下拉菜單
                            await page.keyboard.press('Escape');
                        }
                    }
                } catch (e) {
                    console.log('⚠️  Error setting page size: ' + e.message);
                    console.log('   Error stack: ' + e.stack);
                }
            }

            /**
             * ZGSLOT 手動登入流程
             * 使用 Puppeteer 打開非無頭瀏覽器，讓使用者手動輸入帳號密碼及驗證碼
             */
            async function manualLoginAndNavigate() {
                // 啟動非無頭瀏覽器（headless: false，讓使用者可以看到瀏覽器）
                const browser = await puppeteer.launch({
                    headless: false, // 非無頭模式，讓使用者可以看到瀏覽器
                    args: [
                        // 安全性相關參數（用於容器環境）
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        // 性能優化參數
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--disable-gpu',
                        // 功能禁用（減少資源使用）
                        '--disable-features=TranslateUI',
                        '--disable-crash-reporter',
                        '--disable-breakpad',
                        '--disable-default-apps',
                        '--disable-extensions',
                        '--disable-plugins',
                        '--disable-web-security',
                        '--disable-features=VizDisplayCompositor',
                        '--memory-pressure-off'
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

                    console.log('🔐 Starting ZGSLOT manual login process...');
                    
                    // 導航到登入頁面
                    await page.goto($domainJs, {
                        waitUntil: 'load',
                        timeout: 60000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    
                    // 選擇語言為 English
                    try {
                        await new Promise(resolve => setTimeout(resolve, 1000)); // 等待頁面穩定
                        
                        // 查找語言選擇器（通過多種方式）
                        const languageSelectFound = await page.evaluate(() => {
                            // 方法1: 查找包含"简体中文"或"繁體中文"的 mat-select-trigger
                            const triggers = Array.from(document.querySelectorAll('div.mat-select-trigger'));
                            for (const trigger of triggers) {
                                const text = (trigger.textContent || trigger.innerText || '').trim();
                                if (text.includes('简体中文') || text.includes('English')) {
                                    // 找到 mat-select 元素
                                    const select = trigger.closest('mat-select');
                                    if (select) {
                                        select.setAttribute('data-language-select', 'true');
                                        return true;
                                    }
                                }
                            }
                            
                            // 方法2: 查找所有 mat-select
                            const selects = Array.from(document.querySelectorAll('mat-select'));
                            for (const select of selects) {
                                const trigger = select.querySelector('div.mat-select-trigger');
                                if (trigger) {
                                    const text = (trigger.textContent || trigger.innerText || '').trim();
                                    if (text.includes('简体中文') || text.includes('English')) {
                                        select.setAttribute('data-language-select', 'true');
                                        return true;
                                    }
                                }
                            }
                            
                            return false;
                        });
                        
                        if (languageSelectFound) {
                            // 點擊語言選擇器
                            const languageSelect = await page.$('mat-select[data-language-select="true"]');
                            if (languageSelect) {
                                await languageSelect.click();
                                await new Promise(resolve => setTimeout(resolve, 1000));
                                
                                // 等待下拉菜單出現並選擇 English
                                const englishOption = await page.evaluateHandle(() => {
                                    const options = Array.from(document.querySelectorAll('mat-option'));
                                    return options.find(opt => {
                                        const text = (opt.textContent || opt.innerText || '').trim();
                                        return text === 'English' || 
                                               text.toLowerCase() === 'english' ||
                                               text.includes('English');
                                    });
                                }).catch(() => null);
                                
                                // 點擊 English 選項
                                if (englishOption && englishOption.asElement()) {
                                    await englishOption.asElement().click();
                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                }
                            } else {
                                console.log('   ⚠️  Language selector element not found after marking');
                            }
                        } else {
                            console.log('   ⚠️  Language selector not found (this is OK if it doesn\'t exist on this page)');
                        }
                    } catch (e) {
                        console.log('⚠️  Error selecting language: ' + e.message);
                        // 不阻止後續流程，繼續執行
                    }
                    
                    // 自動填入帳號和密碼
                    const account = $accountJs && $accountJs !== 'null' ? $accountJs.replace(/^"|"$/g, '') : null;
                    const password = $passwordJs && $passwordJs !== 'null' ? $passwordJs.replace(/^"|"$/g, '') : null;
                    
                    if (account && password) {
                        console.log('🔐 Auto-filling account and password...');
                        
                        try {
                            // 查找帳號輸入框（優先使用特定的選擇器）
                            const accountSelectors = [
                                'input#mat-input-0',
                                'input[placeholder="用户名"]',
                                'input[autocomplete="username"]',
                                'input[type="text"][name*="account"]',
                                'input[type="text"][name*="username"]',
                                'input[type="text"][name*="user"]',
                                'input[type="text"][id*="account"]',
                                'input[type="text"][id*="username"]',
                                'input[type="text"][id*="user"]',
                                'input[type="text"][placeholder*="帳號"]',
                                'input[type="text"][placeholder*="账号"]',
                                'input[type="text"][placeholder*="帳戶"]',
                                'input[type="text"][placeholder*="账户"]',
                                'input[type="text"][placeholder*="用戶名"]',
                                'input[type="email"]',
                                'input[type="text"]:first-of-type'
                            ];
                            
                            let accountInput = null;
                            for (const selector of accountSelectors) {
                                accountInput = await page.$(selector).catch(() => null);
                                if (accountInput) {
                                    break;
                                }
                            }
                            
                            if (accountInput) {
                                await accountInput.click();
                                await new Promise(resolve => setTimeout(resolve, 200));
                                await accountInput.click({ clickCount: 3 }); // 選中現有內容
                                await page.keyboard.press('Backspace');
                                await new Promise(resolve => setTimeout(resolve, 100));
                                await accountInput.type(account, { delay: 50 });
                                
                                // 觸發 Angular 變化檢測
                                await page.evaluate(() => {
                                    const input = document.querySelector('input#mat-input-0') || document.querySelector('input[placeholder="用户名"]');
                                    if (input) {
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                });
                            }
                            
                            // 查找密碼輸入框（優先使用特定的選擇器）
                            const passwordSelectors = [
                                'input#mat-input-1',
                                'input[type="password"][placeholder="密码英或数半形"]',
                                'input[type="password"]',
                                'input[name*="password"]',
                                'input[name*="pass"]',
                                'input[id*="password"]',
                                'input[id*="pass"]',
                                'input[placeholder*="密碼"]',
                                'input[placeholder*="密码"]'
                            ];
                            
                            let passwordInput = null;
                            for (const selector of passwordSelectors) {
                                passwordInput = await page.$(selector).catch(() => null);
                                if (passwordInput) {
                                    break;
                                }
                            }
                            
                            if (passwordInput) {
                                await passwordInput.click();
                                await new Promise(resolve => setTimeout(resolve, 200));
                                await passwordInput.click({ clickCount: 3 }); // 選中現有內容
                                await page.keyboard.press('Backspace');
                                await new Promise(resolve => setTimeout(resolve, 100));
                                await passwordInput.type(password, { delay: 50 });
                                
                                // 觸發 Angular 變化檢測
                                await page.evaluate(() => {
                                    const input = document.querySelector('input#mat-input-1') || document.querySelector('input[type="password"][placeholder="密码英或数半形"]');
                                    if (input) {
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                });
                            }
                            
                            console.log('✅ Account and password auto-filled successfully');
                        } catch (e) {
                            console.log('⚠️  Error auto-filling account/password: ' + e.message);
                        }
                    } else {
                        console.log('⚠️  Account or password not provided in environment variables');
                    }
                    
                    // 等待使用者完成登入
                    // 我們會監聽 URL 變化或等待特定元素出現來判斷登入是否完成
                    let loginCompleted = false;
                    let checkAttempts = 0;
                    const maxCheckAttempts = 300; // 最多等待 5 分鐘（300 * 1秒）
                    
                    console.log('⏳ Monitoring login status...');
                    
                    while (!loginCompleted && checkAttempts < maxCheckAttempts) {
                        checkAttempts++;
                        
                        // 檢查是否出現登入成功的標誌（例如：URL 改變、特定元素出現等）
                        const loginSuccess = await page.evaluate(() => {
                            // 檢查 URL 是否不再是登入頁面
                            const url = window.location.href;
                            if (url && !url.includes('/login') && !url.includes('login')) {
                                return true;
                            }
                            
                            // 檢查是否有登入成功的元素（根據實際網站調整）
                            const successIndicators = [
                                document.querySelector('[class*="dashboard"]'),
                                document.querySelector('[class*="home"]'),
                                document.querySelector('[id*="dashboard"]'),
                                document.querySelector('[id*="home"]'),
                                document.querySelector('a[href*="logout"]'),
                                document.querySelector('button[class*="logout"]')
                            ];
                            
                            return successIndicators.some(el => el !== null);
                        });
                        
                        if (loginSuccess) {
                            loginCompleted = true;
                            console.log('✅ Login detected as completed!');
                            break;
                        }
                        
                        // 等待 1 秒後再次檢查
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                    
                    // 等待頁面穩定
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 移除 JSON 編碼的引號
                    const targetUrl = $urlJs.replace(/^"|"$/g, '');
                    
                    // 導航到目標 URL，等待網絡空閒（確保頁面完全載入）
                    await page.goto(targetUrl, {
                        waitUntil: 'networkidle2', // 等待網絡空閒，確保頁面完全載入
                        timeout: 60000
                    });
                    console.log('✅ Successfully navigated to target URL');
                    
                    // 額外等待頁面完全渲染（動態內容可能需要時間）
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    // 打開 "All Columns" 開關（如果存在）
                    await openAllColumnsToggle(page);
                    
                    // 如果提供了開始日期和結束日期，填寫日期並點擊查詢按鈕
                    const startDate = $startDateJs && $startDateJs !== 'null' ? $startDateJs.replace(/^"|"$/g, '') : null;
                    const endDate = $endDateJs && $endDateJs !== 'null' ? $endDateJs.replace(/^"|"$/g, '') : null;
                    
                    if (startDate || endDate) {
                        console.log('📅 Filling date range...');
                        
                        try {
                            // 等待頁面完全載入
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 填寫開始日期
                            if (startDate) {
                                console.log('📝 Filling start date: ' + startDate);
                                // 等待開始日期輸入框出現（優先使用特定的選擇器，兼容中英文）
                                const startDateInput = await page.waitForSelector('input#mat-input-6, input[placeholder="Settle Time Start"], input[placeholder*="Settle Time Start"], input[placeholder="结算时间 开始"], input#mat-input-9, input[placeholder*="结算时间 开始"], input[placeholder*="結算時間 開始"]', { timeout: 10000 }).catch(() => null);
                                
                                if (startDateInput) {
                                    // 點擊輸入框
                                    await startDateInput.click();
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    
                                    // 徹底清空輸入框（使用 Ctrl+A 選中全部，然後刪除）
                                    await page.keyboard.down('Control');
                                    await page.keyboard.press('a');
                                    await page.keyboard.up('Control');
                                    await page.keyboard.press('Backspace');
                                    await new Promise(resolve => setTimeout(resolve, 200));
                                    
                                    // 再次確保清空（雙擊選中全部）
                                    await startDateInput.click({ clickCount: 3 });
                                    await page.keyboard.press('Backspace');
                                    await new Promise(resolve => setTimeout(resolve, 200));
                                    
                                    // 使用 evaluate 直接設置值，然後觸發事件
                                    await page.evaluate((date) => {
                                        const input = document.querySelector('input#mat-input-6') ||
                                                        document.querySelector('input[placeholder="Settle Time Start"]') ||
                                                        document.querySelector('input[placeholder="结算时间 开始"]');
                                        if (input) {
                                            // 直接設置值
                                            input.value = date;
                                            // 觸發多種事件確保 Angular 檢測到變化
                                            input.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true, cancelable: true }));
                                            // 觸發 Angular 特定的變化事件
                                            input.dispatchEvent(new Event('ngModelChange', { bubbles: true }));
                                            // 觸發 focus 和 blur 來確保驗證
                                            input.focus();
                                            input.blur();
                                        }
                                    }, startDate);
                                    
                                    // 也使用 type 方法作為備選
                                    await startDateInput.type(startDate, { delay: 30 });
                                    
                                    // 驗證開始日期是否正確填入
                                    const startDateValue = await page.evaluate(() => {
                                        const input = document.querySelector('input#mat-input-6') ||
                                                        document.querySelector('input[placeholder="Settle Time Start"]') ||
                                                        document.querySelector('input[placeholder="结算时间 开始"]');
                                        return input ? input.value : null;
                                    });
                                    
                                    if (startDateValue && startDateValue.includes(startDate)) {
                                        console.log('✅ Start date filled and verified: ' + startDateValue);
                                    } else {
                                        console.log('⚠️  Start date may not be set correctly. Expected: ' + startDate + ', Got: ' + startDateValue);
                                        // 重試一次
                                        await startDateInput.click();
                                        await new Promise(resolve => setTimeout(resolve, 200));
                                        await page.keyboard.down('Control');
                                        await page.keyboard.press('a');
                                        await page.keyboard.up('Control');
                                        await page.keyboard.press('Backspace');
                                        await new Promise(resolve => setTimeout(resolve, 200));
                                        await startDateInput.type(startDate, { delay: 50 });
                                        await page.evaluate(() => {
                                            const input = document.querySelector('input#mat-input-6') ||
                                                         document.querySelector('input[placeholder="Settle Time Start"]') ||
                                                         document.querySelector('input[placeholder="结算时间 开始"]');
                                            if (input) {
                                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            }
                                        });
                                        console.log('✅ Start date retried');
                                    }
                                    
                                    // 等待頁面可能自動設置結束日期（如果有這個功能）
                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                } else {
                                    console.log('⚠️  Start date input field not found');
                                }
                            }
                            
                            // 填寫結束日期（如果提供了，一定要填寫，覆蓋頁面自動設置的值）
                            if (endDate) {
                                console.log('📝 Filling end date: ' + endDate);
                                // 等待結束日期輸入框出現（優先使用特定的選擇器，兼容中英文）
                                const endDateInput = await page.waitForSelector('input#mat-input-27, input#mat-input-7, input[placeholder="Settle Time End"], input[placeholder*="Settle Time End"], input[placeholder="结算时间 结束"], input[placeholder*="结算时间 结束"], input[placeholder*="結算時間 結束"]', { timeout: 10000 }).catch(() => null);
                                
                                if (endDateInput) {
                                    // 先點擊輸入框使其獲得焦點
                                    await endDateInput.click();
                                    await new Promise(resolve => setTimeout(resolve, 300));

                                    // 強制清空
                                    await page.evaluate(() => {
                                        const input = document.querySelector('input#mat-input-7') ||
                                                    document.querySelector('input[placeholder="Settle Time End"]') ||
                                                    document.querySelector('input[placeholder="结算时间 结束"]');
                                        if (input) {
                                            // 先 focus
                                            input.focus();
                                            // 選中所有內容
                                            input.select();
                                            // 設置為空
                                            input.value = '';
                                            // 觸發事件
                                            input.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true, cancelable: true }));
                                        }
                                    });
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    
                                    // 確保輸入框是空的，然後使用 evaluate 直接設置值
                                    // 先點擊輸入框確保焦點
                                    await endDateInput.click();
                                    await new Promise(resolve => setTimeout(resolve, 300));
                                    
                                    // 使用 evaluate 直接設置值，然後觸發事件
                                    await page.evaluate((date) => {
                                        const input = document.querySelector('input#mat-input-7') ||
                                                     document.querySelector('input[placeholder="Settle Time End"]') ||
                                                     document.querySelector('input[placeholder="结算时间 结束"]');
                                        if (input) {
                                            // 先 focus
                                            input.focus();
                                            // 選中所有內容
                                            input.select();
                                            // 直接設置值
                                            input.value = date;
                                            // 觸發多種事件確保 Angular 檢測到變化
                                            input.dispatchEvent(new Event('input', { bubbles: true, cancelable: true }));
                                            input.dispatchEvent(new Event('change', { bubbles: true, cancelable: true }));
                                            input.dispatchEvent(new Event('blur', { bubbles: true, cancelable: true }));
                                            // 觸發 Angular 特定的變化事件
                                            input.dispatchEvent(new Event('ngModelChange', { bubbles: true }));
                                            // 再次觸發 focus 和 blur 來確保驗證
                                            input.focus();
                                            input.blur();
                                        }
                                    }, endDate);
                                    
                                    // 也使用 type 方法作為備選
                                    await endDateInput.type(endDate, { delay: 30 });
                                    
                                    // 等待一下確保值被設置
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                    
                                    // 驗證值是否正確設置
                                    const checkValue = await page.evaluate(() => {
                                        const input = document.querySelector('input#mat-input-7') ||
                                                     document.querySelector('input[placeholder="Settle Time End"]') ||
                                                     document.querySelector('input[placeholder="结算时间 结束"]');
                                        return input ? input.value : null;
                                    });
                                    
                                    if (!checkValue || !checkValue.includes(endDate)) {
                                        console.log('   ⚠️  Value not set correctly, retrying with type method...');
                                        // 如果 evaluate 方法失敗，使用 type 方法重試
                                        await endDateInput.click();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        // 先清空
                                        await endDateInput.click({ clickCount: 3 });
                                        await page.keyboard.press('Backspace');
                                        await new Promise(resolve => setTimeout(resolve, 200));
                                        // 使用 Ctrl+A 清空
                                        await page.keyboard.down('Control');
                                        await page.keyboard.press('a');
                                        await page.keyboard.up('Control');
                                        await page.keyboard.press('Backspace');
                                        await new Promise(resolve => setTimeout(resolve, 200));
                                        // 再輸入
                                        await endDateInput.type(endDate, { delay: 50 });
                                        // 觸發事件
                                        await page.evaluate(() => {
                                            const input = document.querySelector('input#mat-input-27') ||
                                                         document.querySelector('input#mat-input-7') ||
                                                         document.querySelector('input[placeholder="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder*="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="結算時間 結束"]');
                                            if (input) {
                                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            }
                                        });
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                    }
                                    
                                    // 驗證結束日期是否正確填入
                                    const endDateValue = await page.evaluate(() => {
                                        const input = document.querySelector('input#mat-input-27') ||
                                                     document.querySelector('input#mat-input-7') ||
                                                     document.querySelector('input[placeholder="Settle Time End"]') ||
                                                     document.querySelector('input[placeholder*="Settle Time End"]') ||
                                                     document.querySelector('input[placeholder="结算时间 结束"]') ||
                                                     document.querySelector('input[placeholder*="结算时间 结束"]') ||
                                                     document.querySelector('input[placeholder*="結算時間 結束"]');
                                        return input ? input.value : null;
                                    });
                                    
                                    if (endDateValue && endDateValue.includes(endDate)) {
                                        console.log('✅ End date filled and verified: ' + endDateValue);
                                    } else {
                                        console.log('⚠️  End date may not be set correctly. Expected: ' + endDate + ', Got: ' + endDateValue);
                                        console.log('   🔄 Retrying end date fill...');
                                        // 重試一次，使用更強的方法
                                        await endDateInput.click();
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        // 雙擊選中全部
                                        await endDateInput.click({ clickCount: 3 });
                                        await page.keyboard.press('Backspace');
                                        await new Promise(resolve => setTimeout(resolve, 200));
                                        // 使用 Ctrl+A 清空
                                        await page.keyboard.down('Control');
                                        await page.keyboard.press('a');
                                        await page.keyboard.up('Control');
                                        await page.keyboard.press('Backspace');
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                        // 先使用 evaluate 設置值
                                        await page.evaluate((date) => {
                                            const input = document.querySelector('input#mat-input-27') ||
                                                         document.querySelector('input#mat-input-7') ||
                                                         document.querySelector('input[placeholder="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder*="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="結算時間 結束"]');
                                            if (input) {
                                                input.value = date;
                                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                                                input.dispatchEvent(new Event('ngModelChange', { bubbles: true }));
                                            }
                                        }, endDate);
                                        // 再使用 type 方法
                                        await endDateInput.type(endDate, { delay: 50 });
                                        // 觸發事件
                                        await page.evaluate(() => {
                                            const input = document.querySelector('input#mat-input-27') ||
                                                         document.querySelector('input#mat-input-7') ||
                                                         document.querySelector('input[placeholder="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder*="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="結算時間 結束"]');
                                            if (input) {
                                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                                input.dispatchEvent(new Event('blur', { bubbles: true }));
                                            }
                                        });
                                        await new Promise(resolve => setTimeout(resolve, 500));
                                        
                                        // 再次驗證
                                        const retryValue = await page.evaluate(() => {
                                            const input = document.querySelector('input#mat-input-27') ||
                                                         document.querySelector('input#mat-input-7') ||
                                                         document.querySelector('input[placeholder="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder*="Settle Time End"]') ||
                                                         document.querySelector('input[placeholder="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="结算时间 结束"]') ||
                                                         document.querySelector('input[placeholder*="結算時間 結束"]');
                                            return input ? input.value : null;
                                        });
                                        
                                        if (retryValue && retryValue.includes(endDate)) {
                                            console.log('✅ End date retried and verified: ' + retryValue);
                                        } else {
                                            console.log('⚠️  End date still may not be set correctly after retry. Expected: ' + endDate + ', Got: ' + retryValue);
                                        }
                                    }
                                    
                                    await new Promise(resolve => setTimeout(resolve, 500));
                                } else {
                                    console.log('⚠️  End date input field not found');
                                }
                            }
                            
                            // 點擊查詢按鈕
                            console.log('🔍 Clicking query button...');
                            
                            // 使用 evaluate 查找包含"查询"、"查詢"、"Query"或"Search"文字的按鈕（兼容中英文）
                            const queryButtonFound = await page.evaluate(() => {
                                const buttons = Array.from(document.querySelectorAll('button.mat-flat-button.mat-primary, button.mat-flat-button'));
                                const button = buttons.find(btn => {
                                    const text = (btn.textContent || btn.innerText || '').trim().toLowerCase();
                                    return text.includes('查询') || 
                                           text.includes('查詢') || 
                                           text === '查询' || 
                                           text === '查詢' ||
                                           text.includes('query') ||
                                           text === 'query' ||
                                           text.includes('search') ||
                                           text === 'search';
                                });
                                
                                if (button) {
                                    // 標記按鈕以便後續選擇
                                    button.setAttribute('data-query-button', 'true');
                                    return true;
                                }
                                return false;
                            });
                            
                            if (queryButtonFound) {
                                // 使用標記選擇按鈕
                                const queryButton = await page.$('button[data-query-button="true"]');
                                if (queryButton) {
                                    await queryButton.click();
                                    console.log('✅ Query button clicked');
                                    
                                    // 等待查詢結果載入
                                    console.log('⏳ Waiting for query results...');
                                    await new Promise(resolve => setTimeout(resolve, 5000));
                                    
                                    // 頁面可能已刷新，重新打開 "All Columns" 開關
                                    await openAllColumnsToggle(page);
                                    
                                    // 設置每頁筆數為 10000
                                    await setPageSize(page, 10000);
                                } else {
                                    console.log('⚠️  Query button element not found after marking');
                                }
                            } else {
                                // 嘗試直接使用選擇器
                                const queryButtonDirect = await page.$('button.mat-flat-button.mat-primary').catch(() => null);
                                if (queryButtonDirect) {
                                    await queryButtonDirect.click();
                                    console.log('✅ Query button clicked');
                                    await new Promise(resolve => setTimeout(resolve, 5000));
                                    
                                    // 頁面可能已刷新，重新打開 "All Columns" 開關
                                    await openAllColumnsToggle(page);
                                    
                                    // 設置每頁筆數為 10000
                                    await setPageSize(page, 10000);
                                } else {
                                    console.log('⚠️  Query button not found');
                                }
                            }
                        } catch (e) {
                            console.log('⚠️  Error filling dates or clicking query button: ' + e.message);
                            console.log('   Error stack: ' + e.stack);
                        }
                    } else {
                        // 即使沒有填寫日期，也嘗試設置每頁筆數
                        await setPageSize(page, 10000);
                    }
                            
                    // 獲取頁面標題
                    const pageTitle = await page.title();
                    
                    // 在爬取數據前，確保 "All Columns" 開關是打開的（防止頁面閃爍導致關閉）
                    await openAllColumnsToggle(page);
                    
                    // 爬取 mat-table 表格數據
                    console.log('📊 Scraping mat-table data...');
                    const tableData = await page.evaluate(() => {
                        const table = document.querySelector('mat-table.mat-table');
                        if (!table) {
                            return { error: 'Table not found', rows: [] };
                        }
                        
                        // 獲取表頭
                        const headers = [];
                        const headerRow = table.querySelector('thead tr, mat-header-row');
                        if (headerRow) {
                            const headerCells = headerRow.querySelectorAll('mat-header-cell, th');
                            headerCells.forEach(cell => {
                                const text = (cell.textContent || '').trim();
                                if (text) {
                                    headers.push(text);
                                }
                            });
                        }
                        
                        // 如果沒有找到表頭，嘗試從 mat-column 屬性獲取
                        if (headers.length === 0) {
                            const firstRow = table.querySelector('mat-row, tbody tr');
                            if (firstRow) {
                                const cells = firstRow.querySelectorAll('mat-cell, td');
                                cells.forEach((cell, index) => {
                                    const columnDef = cell.getAttribute('mat-column') || 
                                                     cell.getAttribute('cdk-column') ||
                                                     ('column_' + index);
                                    headers.push(columnDef);
                                });
                            }
                        }
                        
                        // 獲取表格行數據
                        const rows = [];
                        const dataRows = table.querySelectorAll('mat-row, tbody tr');
                        
                        dataRows.forEach((row, rowIndex) => {
                            const rowData = {};
                            const cells = row.querySelectorAll('mat-cell, td');
                            
                            cells.forEach((cell, cellIndex) => {
                                const text = (cell.textContent || '').trim();
                                const header = headers[cellIndex] || ('column_' + cellIndex);
                                
                                // 獲取更多信息（如果有）
                                const columnDef = cell.getAttribute('mat-column') || 
                                                 cell.getAttribute('cdk-column') || 
                                                 header;
                                
                                rowData[columnDef] = text;
                            });
                            
                            if (Object.keys(rowData).length > 0) {
                                rows.push(rowData);
                            }
                        });
                        
                        return {
                            headers: headers,
                            rows: rows,
                            rowCount: rows.length
                        };
                    });
                    
                    console.log('✅ Table data scraped: ' + tableData.rowCount + ' rows found');
                    
                    // 返回結果
                    const result = {
                        success: true,
                        url: page.url(),
                        title: pageTitle,
                        tableData: tableData
                    };
                    
                    // 保存結果
                    const resultPath = path.join(workingDir, 'scrape_result.json');
                    fs.writeFileSync(resultPath, JSON.stringify(result, null, 2));
                    
                    // 保存表格數據為單獨的 JSON 文件（使用與 Splus 類似的結構）
                    // 生成時間戳（格式：YYYY-MM-DD_HH-MM-SS）
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = String(now.getMonth() + 1).padStart(2, '0');
                    const day = String(now.getDate()).padStart(2, '0');
                    const hours = String(now.getHours()).padStart(2, '0');
                    const minutes = String(now.getMinutes()).padStart(2, '0');
                    const seconds = String(now.getSeconds()).padStart(2, '0');
                    const timestamp = year + '-' + month + '-' + day + '_' + hours + '-' + minutes + '-' + seconds;
                    
                    // 提取 URL 的查詢參數
                    const currentUrl = page.url();
                    const queryParams = {};
                    try {
                        const urlObj = new URL(currentUrl);
                        urlObj.searchParams.forEach((value, key) => {
                            queryParams[key] = value;
                        });
                    } catch (e) {
                        // 如果 URL 解析失敗，嘗試手動解析
                        const queryString = currentUrl.split('?')[1];
                        if (queryString) {
                            queryString.split('&').forEach(param => {
                                const [key, value] = param.split('=');
                                if (key) {
                                    queryParams[decodeURIComponent(key)] = value ? decodeURIComponent(value) : '';
                                }
                            });
                        }
                    }
                    
                    // 添加 dateStart 和 dateEnd 到 queryParams
                    if (startDate) {
                        queryParams.dateStart = startDate;
                    }
                    if (endDate) {
                        queryParams.dateEnd = endDate;
                    }
                    
                    const tableDataFile = {
                        metadata: {
                            timestamp: timestamp,
                            url: currentUrl,
                            queryParams: queryParams
                        },
                        headers: tableData.headers || [],
                        totalPages: 1,
                        totalRows: tableData.rowCount || 0,
                        data: tableData.rows || []
                    };
                    
                    const tableDataPath = path.join(workingDir, 'table_data.json');
                    fs.writeFileSync(tableDataPath, JSON.stringify(tableDataFile, null, 2));
                    console.log('💾 Table data saved to: ' + tableDataPath);
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
                    fs.writeFileSync('scrape_result.json', JSON.stringify(errorResult, null, 2));
                    
                    console.log('⏳ Browser will close in 5 seconds...');
                    await new Promise(resolve => setTimeout(resolve, 5000));
                    
                    await browser.close();
                    throw error;
                }
            }

            manualLoginAndNavigate().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/scrape_zgslot_manual.js');
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
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('');
        $this->info('4. Processing scraped data...');

        $timestamp = date('Y-m-d_H-i-s');
        $workingDir = storage_path('app/temp');
        
        // 處理表格數據文件
        $this->processTableData($workingDir, $timestamp);

        if (isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            $this->info('📄 Title: ' . ($result['title'] ?? 'N/A'));
            
            // 顯示表格數據信息
            if (isset($result['tableData'])) {
                $tableData = $result['tableData'];
                $this->info('📊 Table Rows: ' . ($tableData['rowCount'] ?? 0));
                $this->info('📋 Table Headers: ' . (isset($tableData['headers']) ? count($tableData['headers']) : 0));
                if (isset($tableData['error'])) {
                    $this->warn('⚠️  Table Error: ' . $tableData['error']);
                }
            } else {
                $this->warn('⚠️  No table data found');
            }
            
            if (isset($result['error'])) {
                $this->warn('⚠️  Warning: ' . $result['error']);
            }
        } else {
            $this->error('❌ Scraping failed: ' . ($result['error'] ?? 'Unknown error'));
        }
        
        $this->info('');
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
    }

    /**
     * 處理表格數據文件，將它從臨時目錄移動到永久儲存目錄
     * @param string $workingDir 工作目錄（臨時目錄）
     * @param string $timestamp 時間戳
     */
    private function processTableData($workingDir, $timestamp)
    {
        $this->info('📊 Processing table data...');
        
        $tableDataFile = $workingDir . '/table_data.json';
        if (file_exists($tableDataFile)) {
            $dataDir = storage_path('app/scraped_data');
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            
            // 讀取原始數據
            $content = file_get_contents($tableDataFile);
            $rawData = json_decode($content, true);
            
            // 重新組織數據結構（與 Splus 保持一致）
            $processedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $rawData['metadata']['url'] ?? '',
                    'queryParams' => $rawData['metadata']['queryParams'] ?? []
                ],
                'headers' => $rawData['headers'] ?? [],
                'totalPages' => $rawData['totalPages'] ?? 1,
                'totalRows' => $rawData['totalRows'] ?? 0,
                'data' => $rawData['data'] ?? []
            ];
            
            // 保存處理後的數據
            $destFilename = "zgslot_manual_{$timestamp}_table_data.json";
            $destPath = $dataDir . '/' . $destFilename;
            
            file_put_contents($destPath, json_encode($processedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            $this->info("✅ Table data saved to: {$destPath}");
            
            // 顯示表格數據摘要
            if (isset($processedData['totalRows'])) {
                $this->line("   📋 Total Rows: {$processedData['totalRows']}");
                $this->line("   📄 Total Pages: {$processedData['totalPages']}");
                if (isset($processedData['headers']) && count($processedData['headers']) > 0) {
                    $headerPreview = implode(', ', array_slice($processedData['headers'], 0, 5));
                    if (count($processedData['headers']) > 5) {
                        $headerPreview .= '...';
                    }
                    $this->line("   📑 Headers (" . count($processedData['headers']) . "): {$headerPreview}");
                }
            }
        } else {
            $this->warn('⚠️  Table data file not found');
        }
    }
}
