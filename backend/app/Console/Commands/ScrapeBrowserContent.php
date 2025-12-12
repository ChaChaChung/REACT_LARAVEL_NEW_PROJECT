<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器內容爬蟲命令
 */
class ScrapeBrowserContent extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-browser {url}
     * {url} - 要爬取的目標網址（必需參數）
     */
    protected $signature = 'agent:scrape-browser {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content directly from rendered page using browser automation';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');

        $this->info('=== Browser Content Scraper ===');
        $this->info("Target URL: {$url}");
        
        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理爬取的數據
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
     * 創建 Puppeteer 自動化腳本
     * @param string $url 要爬取的目標網址
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url)
    {
        $this->info('2. Creating browser automation script...');

        // 從 .env 環境變數獲取認證相關的 cookie 值
        $auth = env('AGENT_AUTH', '');
        $token = env('AGENT_TOKEN', '');
        $bgLang = env('AGENT_BG_LANGUAGE_KEY', 'zh-cn');

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 主要的爬取函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁內容
             */
            async function scrapeContent() {
                console.log('🚀 Starting browser automation...');

                // 啟動無頭瀏覽器（headless mode）
                // 使用多個 Chrome 參數來優化性能和穩定性
                const browser = await puppeteer.launch({
                    headless: 'new', // 使用新的 headless 模式
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

                    console.log('🔐 Setting authentication cookies...');

                    // 根據環境變數設定認證 cookies
                    // 這些 cookies 用於通過需要登入的頁面驗證
                    const cookies = [];
                    if ('$auth') cookies.push({ name: 'auth', value: '$auth', domain: 'agent2.chichengwld.com' });
                    if ('$token') cookies.push({ name: 'token', value: '$token', domain: 'agent2.chichengwld.com' });
                    if ('$bgLang') cookies.push({ name: 'bg_languageKey', value: '$bgLang', domain: 'agent2.chichengwld.com' });

                    // 如果有設定 cookies，則應用到頁面
                    if (cookies.length > 0) {
                        await page.setCookie(...cookies);
                        console.log('✅ Cookies set:', cookies.length);
                    } else {
                        console.log('⚠️  No cookies found in environment variables');
                    }

                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            console.log('❌ Browser console error:', msg.text());
                        }
                    });

                    // 監聽網路請求，捕獲 API 調用
                    // 這可以獲取頁面載入時發送的 AJAX/Fetch 請求數據
                    const networkRequests = [];
                    page.on('response', async (response) => {
                        const url = response.url();
                        // 只捕獲包含 '/api/' 且狀態為 200 的請求
                        if (url.includes('/api/') && response.status() === 200) {
                            try {
                                const contentType = response.headers()['content-type'];
                                // 只處理 JSON 格式的回應
                                if (contentType && contentType.includes('application/json')) {
                                    const json = await response.json();
                                    // 保存 API 請求的 URL、數據和時間戳
                                    networkRequests.push({
                                        url: url,
                                        data: json,
                                        timestamp: new Date().toISOString()
                                    });
                                    console.log('📡 Captured API call:', url);
                                }
                            } catch (e) {
                                // 忽略無法解析為 JSON 的回應
                            }
                        }
                    });

                    console.log('🌐 Navigating to:', '$url');

                    // 導航到目標頁面
                    // waitUntil: 'networkidle2' 表示等待網路空閒（沒有超過 2 個網路連接）時才繼續
                    // timeout: 30000 設定 30 秒超時
                    await page.goto('$url', {
                        waitUntil: 'networkidle2',
                        timeout: 30000
                    });

                    // 等待額外 5 秒，確保動態內容完全載入
                    await new Promise(resolve => setTimeout(resolve, 5000));

                    // 滾動頁面以觸發懶載入（lazy loading）
                    // 許多現代網站使用懶載入技術，只有當元素進入視窗時才載入內容
                    console.log('📜 Scrolling to trigger lazy loading...');
                    await page.evaluate(() => {
                        return new Promise((resolve) => {
                            let totalHeight = 0;
                            const distance = 100;  // 每次滾動 100 像素
                            const timer = setInterval(() => {
                                const scrollHeight = document.body.scrollHeight;
                                window.scrollBy(0, distance);
                                totalHeight += distance;

                                // 如果已滾動到底部，停止滾動
                                if(totalHeight >= scrollHeight){
                                    clearInterval(timer);
                                    resolve();
                                }
                            }, 100);  // 每 100 毫秒滾動一次
                        });
                    });

                    // 滾動完成後再等待 2 秒，讓懶載入的內容有時間載入
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    console.log('📊 Extracting data from page...');

                    // 在瀏覽器頁面中執行 JavaScript 來提取數據
                    // page.evaluate() 在瀏覽器上下文中執行，可以訪問 DOM
                    const extractedData = await page.evaluate(() => {
                        // 提取表格數據
                        // 尋找所有 <table> 元素，提取表頭和行數據
                        const tables = Array.from(document.querySelectorAll('table')).map(table => {
                            // 提取表頭（從 thead th 或第一行的 td）
                            const headers = Array.from(table.querySelectorAll('thead th, tr:first-child td')).map(th => th.innerText.trim());
                            // 提取數據行（從 tbody tr 或除第一行外的所有 tr）
                            const rows = Array.from(table.querySelectorAll('tbody tr, tr:not(:first-child)')).map(tr => {
                                return Array.from(tr.querySelectorAll('td')).map(td => td.innerText.trim());
                            });

                            return { headers, rows };
                        }).filter(table => table.headers.length > 0);  // 過濾掉沒有表頭的表格

                        // 提取列表數據
                        // 尋找所有 <ul> 和 <ol> 元素，提取列表項內容
                        const lists = Array.from(document.querySelectorAll('ul, ol')).map(list => {
                            return Array.from(list.querySelectorAll('li')).map(li => li.innerText.trim());
                        }).filter(list => list.length > 0);  // 過濾掉空列表

                        // 尋找包含特定關鍵字的數據元素
                        // 這用於找到可能包含重要數據的元素（如 "record", "chess", "data" 等）
                        const dataElements = Array.from(document.querySelectorAll('*')).filter(el => {
                            const text = el.innerText || '';
                            const className = el.className || '';
                            const id = el.id || '';

                            // 過濾條件：文本長度適中，且包含特定關鍵字
                            return (text.length > 10 && text.length < 10000) && 
                                (text.includes('record') || text.includes('chess') || 
                                    className.includes('record') || className.includes('data') ||
                                    id.includes('record') || id.includes('data'));
                        }).map(el => ({
                            tag: el.tagName,
                            class: el.className,
                            id: el.id,
                            text: el.innerText.substring(0, 200) + (el.innerText.length > 200 ? '...' : '')  // 限制文本長度
                        }));

                        // 返回提取的所有數據
                        return {
                            type: 'page_analysis',
                            url: window.location.href,
                            title: document.title,
                            tables: tables,
                            lists: lists,
                            dataElements: dataElements.slice(0, 10),  // 限制數據元素數量為 10 個
                            pageText: document.body.innerText.substring(0, 1000) + '...'  // 頁面文本的前 1000 字符
                        };
                    });

                    // 截圖（用於調試和驗證）
                    // fullPage: true 表示截取整個頁面，而不只是可見區域
                    await page.screenshot({ 
                        path: 'scraped_page_screenshot.png',
                        fullPage: true 
                    });

                    console.log('📸 Screenshot saved: scraped_page_screenshot.png');

                    // 合併所有提取的數據和捕獲的 API 請求
                    const result = {
                        timestamp: new Date().toISOString(),  // 時間戳
                        url: '$url',  // 目標 URL
                        extractedData: extractedData,  // 從頁面提取的數據
                        networkRequests: networkRequests,  // 捕獲的 API 請求
                        success: true  // 成功標記
                    };

                    // 將結果保存為 JSON 文件
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');
                    console.log('📊 Extracted tables:', extractedData.tables?.length || 0);
                    console.log('📋 Extracted lists:', extractedData.lists?.length || 0);
                    console.log('📡 API calls captured:', networkRequests.length);

                    return result;
                } catch (error) {
                    // 如果發生錯誤，記錄錯誤並保存錯誤信息
                    console.error('❌ Error during scraping:', error);
                    fs.writeFileSync('scraped_result.json', JSON.stringify({
                        error: error.message,
                        success: false,
                        timestamp: new Date().toISOString()
                    }, null, 2));
                    throw error;
                } finally {
                    // 無論成功或失敗，都要關閉瀏覽器
                    await browser.close();
                    console.log('🏁 Browser closed');
                }
            }

            // 執行爬取函數並處理結果
            scrapeContent().then(() => {
                console.log('✅ Scraping completed successfully');
                process.exit(0);  // 成功退出
            }).catch((error) => {
                console.error('💥 Scraping failed:', error);
                process.exit(1);  // 失敗退出
            });
        JS;

        // 設定腳本保存路徑
        $scriptPath = storage_path('app/temp/scraper.js');

        // 確保臨時目錄存在，如果不存在則創建
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // 將生成的腳本寫入文件
        file_put_contents($scriptPath, $script);

        $this->info("✅ Script created: {$scriptPath}");

        return $scriptPath;
    }
    
    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath Puppeteer 腳本文件路徑
     * @return array|null 返回解析後的結果數據，失敗時返回 null
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation...');

        // 獲取腳本所在目錄，並將工作目錄切換到該目錄
        // 這樣可以確保腳本生成的臨時文件（如截圖、結果文件）在同一目錄
        $workingDir = dirname($scriptPath);

        // 在指定目錄執行 Node.js 腳本
        $result = Process::path($workingDir)->run("node " . basename($scriptPath));

        // 顯示瀏覽器執行的輸出信息
        $this->line(""); // 空行
        $this->line("📋 Browser Output:");
        $this->line($result->output());

        // 檢查執行是否失敗
        if ($result->failed()) {
            $this->error("❌ Browser automation failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        // 讀取腳本生成的結果文件
        $resultFile = $workingDir . '/scraped_result.json';

        if (file_exists($resultFile)) {
            // 讀取並解析 JSON 文件
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }

        $this->error("❌ No result file found");
        return null;
    }
    
    /**
     * 處理和保存爬取的數據
     * @param array $result 爬取的結果數據
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        // 檢查爬取是否成功
        if (!$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }

        // 提取數據和 API 請求
        $extractedData = $result['extractedData'];
        $networkRequests = $result['networkRequests'] ?? [];

        // 顯示數據分析結果
        $this->info("📊 Analysis Results:");
        $this->info("   Tables found: " . count($extractedData['tables'] ?? []));
        $this->info("   Lists found: " . count($extractedData['lists'] ?? []));
        $this->info("   Data elements found: " . count($extractedData['dataElements'] ?? []));
        $this->info("   API calls captured: " . count($networkRequests));

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存完整結果為 JSON 文件
        $fullResultPath = storage_path("app/scraped_data/browser_scrape_full_{$timestamp}.json");
        Storage::put("scraped_data/browser_scrape_full_{$timestamp}.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 如果捕獲到 API 請求數據，單獨保存為 JSON 文件
        if (!empty($networkRequests)) {
            Storage::put("scraped_data/api_calls_{$timestamp}.json", json_encode($networkRequests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("📡 API calls saved separately");
        }

        $this->info("💾 Full results saved to: {$fullResultPath}");

        // 將截圖從臨時目錄移動到永久存儲目錄
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }
        
        $this->info("✅ Data processing completed!");
    }
}