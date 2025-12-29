<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserSplusDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-splus-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date_start?} - 要選擇的開始日期（可選參數）
     * {date_end?} - 要選擇的結束日期（可選參數）
     * {--concurrency=4} - 併發數量（可選，預設為 4）
     */
    protected $signature = 'agent:scrape-splus-dom-detail {url} {date_start?} {date_end?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from Splus DOM elements using browser automation with detailed information';

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

        $this->info('=== Splus API Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$date_start}");
        $this->info("Date End: {$date_end}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 獲取登入 Token 和 Cookies
        $auth = $this->getLoginToken();
        if (!$auth || !isset($auth['token'])) {
            $this->error('❌ Failed to get login token');
            return 1;
        }
        
        $token = $auth['token'];
        $cookies = $auth['cookies'] ?? [];

        // 轉換日期為時間戳
        $startTimestamp = $this->convertDateToTimestamp($date_start);
        $endTimestamp = $this->convertDateToTimestamp($date_end, true); // end of day

        if (!$startTimestamp || !$endTimestamp) {
            $this->error('❌ Invalid date format. Please use YYYYMMDD format (e.g., 20251226)');
            return 1;
        }

        // 調用 API 獲取所有數據
        $allData = $this->fetchAllDataFromApi($token, $cookies, $startTimestamp, $endTimestamp);

        if ($allData) {
            $this->saveApiData($allData, $date_start, $date_end);
            $this->info('End of command at: ' . date('Y-m-d H:i:s'));
            $this->info("✅ Data fetching completed!");
            return 0;
        }

        return 1;
    }

    /**
     * 獲取登入 Token 和 Cookies（從瀏覽器）
     * @return array|null 返回 ['token' => string, 'cookies' => array]
     */
    private function getLoginToken()
    {
        // 優先從環境變數獲取（如果已設置）
        $token = env('SPLUS_AGENT_TOKEN');
        if ($token) {
            $this->info('✅ Using token from environment variable');
            return ['token' => $token, 'cookies' => []];
        }

        // 否則通過瀏覽器登入獲取
        $this->info('1. Getting login token and cookies from browser...');
        
        if (!$this->checkNodeJs()) {
            return null;
        }

        $scriptPath = $this->createTokenFetchScript();
        $result = $this->runTokenFetchScript($scriptPath);

        if ($result && isset($result['loginToken'])) {
            $this->info('✅ Login token and cookies obtained');
            return [
                'token' => $result['loginToken'],
                'cookies' => $result['cookies'] ?? []
            ];
        }

        return null;
    }

    /**
     * 將日期轉換為時間戳（毫秒）
     * @param string|null $date 日期格式：YYYYMMDD (e.g., 20251226)
     * @param bool $endOfDay 是否為當天結束時間（23:59:59）
     * @return int|null
     */
    private function convertDateToTimestamp($date, $endOfDay = false)
    {
        if (!$date) {
            return null;
        }

        // 解析日期格式 YYYYMMDD
        if (strlen($date) === 8 && is_numeric($date)) {
            $year = substr($date, 0, 4);
            $month = substr($date, 4, 2);
            $day = substr($date, 6, 2);
            
            $dateTime = sprintf('%s-%s-%s', $year, $month, $day);
            if ($endOfDay) {
                $dateTime .= ' 23:59:59';
            } else {
                $dateTime .= ' 00:00:00';
            }
            
            $timestamp = strtotime($dateTime);
            if ($timestamp === false) {
                return null;
            }
            
            // 轉換為毫秒
            return $timestamp * 1000;
        }

        // 嘗試其他格式
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }

        if ($endOfDay) {
            $timestamp = strtotime(date('Y-m-d 23:59:59', $timestamp));
        }

        return $timestamp * 1000;
    }

    /**
     * 從 API 獲取所有頁面的數據
     * @param string $token 登入 Token
     * @param array $cookies Cookies 陣列
     * @param int $startTimestamp 開始時間戳（毫秒）
     * @param int $endTimestamp 結束時間戳（毫秒）
     * @return array|null
     */
    private function fetchAllDataFromApi($token, $cookies, $startTimestamp, $endTimestamp)
    {
        $this->info('2. Fetching data from API...');
        
        $baseUrl = 'https://game-hall-backend.yz168.com.tw/api/v2/channel/stats/wager_details/';
        $allData = [];
        $currentPage = 1;
        $totalPages = null;

        do {
            $this->info("   Fetching page {$currentPage}...");
            
            // 構建 HTTP 客戶端
            $client = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
            
            // 如果有 cookies，添加 cookies
            if (!empty($cookies)) {
                $cookieString = collect($cookies)->map(function ($cookie) {
                    return $cookie['name'] . '=' . $cookie['value'];
                })->implode('; ');
                $client = $client->withHeaders(['Cookie' => $cookieString]);
            }
            
            // 如果 token 存在，嘗試多種認證方式
            if ($token) {
                // 嘗試 Bearer token
                $client = $client->withToken($token);
                // 也嘗試作為自定義 header
                $client = $client->withHeaders(['X-Auth-Token' => $token]);
            }
            
            $response = $client->get($baseUrl, [
                'startTimestamp' => $startTimestamp,
                'endTimestamp' => $endTimestamp,
                'currency' => 'ALL',
                'page' => $currentPage,
                'perPage' => 20,
            ]);

            if (!$response->successful()) {
                $this->error("❌ API request failed: " . $response->status());
                $this->error("Response: " . $response->body());
                return null;
            }

            $data = $response->json();
            
            // 調試：顯示實際的響應結構（僅第一頁）
            if ($currentPage === 1) {
                $this->line("   🔍 Debug - Response keys: " . implode(', ', array_keys($data ?? [])));

                // 顯示所有頂層字段（排除 data 數組）
                $metaFields = [];
                foreach ($data as $key => $value) {
                    if ($key !== 'data' && !is_array($value) || (is_array($value) && !isset($value[0]))) {
                        $metaFields[$key] = $value;
                    }
                }
                
                // 顯示具體的分頁字段
                if (isset($data['meta']['totalPages'])) {
                    $this->line("   🔍 totalPages: " . $data['meta']['totalPages']);
                }
                if (isset($data['meta']['totalCounts'])) {
                    $this->line("   🔍 totalCounts: " . $data['meta']['totalCounts']);
                }
            }
            
            // 檢查響應格式（可能有多種格式）
            $records = null;
            if (isset($data['data']) && is_array($data['data'])) {
                $records = $data['data'];
            } elseif (isset($data['results']) && is_array($data['results'])) {
                $records = $data['results'];
            } elseif (isset($data['items']) && is_array($data['items'])) {
                $records = $data['items'];
            } elseif (is_array($data) && isset($data[0]) && !isset($data['totalPages'])) {
                // 如果直接是數組且沒有分頁信息
                $records = $data;
            }
            
            if ($records === null) {
                $this->error("❌ Invalid API response format - no data found");
                $this->error("Response structure: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return null;
            }

            // 獲取分頁信息（支持多種格式）
            if ($totalPages === null) {
                $totalCounts = $data['meta']['totalCounts'] ?? null;
                $perPage = $data['meta']['perPage'] ?? 20;
                $apiTotalPages = $data['meta']['totalPages'] ?? null;
                
                // 如果 API 返回了 totalCounts，根據它計算總頁數（更可靠）
                if ($totalCounts !== null && $perPage > 0) {
                    $calculatedTotalPages = (int)ceil($totalCounts / $perPage);
                    
                    // 如果計算出的頁數大於 API 返回的頁數，使用計算出的頁數
                    if ($apiTotalPages === null || $calculatedTotalPages > $apiTotalPages) {
                        $totalPages = $calculatedTotalPages;
                        $this->info("   Calculated total pages: {$totalPages} (from totalCounts: {$totalCounts}, perPage: {$perPage})");
                    } else {
                        $totalPages = $apiTotalPages;
                        $this->info("   Using API total pages: {$totalPages}");
                    }
                } elseif ($apiTotalPages !== null) {
                    $totalPages = $apiTotalPages;
                    $this->info("   Using API total pages: {$totalPages}");
                } else {
                    // 如果都沒有，設為一個大數，讓循環繼續直到沒有數據
                    $totalPages = 999; // 設置一個很大的數，讓循環繼續
                    $this->warn("   ⚠️  No pagination info found, will continue fetching until no more data");
                }
                
                $this->info("   Total pages: {$totalPages}, Total records: " . ($totalCounts ?? 'unknown'));
            }

            // 合併數據
            $allData = array_merge($allData, $records);
            $this->info("   ✅ Page {$currentPage}: " . count($records) . " records");

            // 如果當前頁沒有數據，停止循環
            if (empty($records)) {
                $this->info("   No more data, stopping...");
                break;
            }

            // 如果當前頁的數據少於每頁數量，說明已經到最後一頁
            $perPage = $data['perPage'] ?? $data['per_page'] ?? 20;
            if (count($records) < $perPage) {
                $this->info("   Last page reached (records: " . count($records) . " < perPage: {$perPage})");
                break;
            }

            $currentPage++;
            
            // 如果已經獲取所有頁面，停止（只有在有明確分頁信息時才檢查，999 表示沒有分頁信息）
            if ($totalPages < 999 && $currentPage > $totalPages) {
                $this->info("   Reached total pages limit: {$totalPages}");
                break;
            }
        } while (true); // 改為無限循環，通過 break 控制

        $this->info("✅ Fetched all {$totalPages} pages, total records: " . count($allData));
        
        return [
            'totalPages' => $totalPages,
            'totalCounts' => count($allData),
            'data' => $allData,
            'pagination' => [
                'totalPages' => $totalPages,
                'totalCounts' => count($allData),
                'perPage' => 20,
            ]
        ];
    }

    /**
     * 保存 API 數據
     * @param array $data
     * @param string|null $date_start
     * @param string|null $date_end
     */
    private function saveApiData($data, $date_start, $date_end)
    {
        $this->info('3. Saving data...');
        
        $timestamp = date('Y-m-d_H-i-s');
        $fileName = "scraped_data/api_data_{$timestamp}.json";
        
        $fileData = [
            'metadata' => [
                'timestamp' => $timestamp,
                'dateStart' => $date_start,
                'dateEnd' => $date_end,
                'totalPages' => $data['totalPages'],
                'totalCounts' => $data['totalCounts'],
                'source' => 'API',
            ],
            'pagination' => $data['pagination'],
            'data' => $data['data']
        ];
        
        Storage::put($fileName, json_encode($fileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("✅ Data saved: {$fileName}");
        $this->info("📊 Total records: {$data['totalCounts']}");
    }

    /**
     * 創建獲取 Token 的腳本
     * @return string
     */
    private function createTokenFetchScript()
    {
        $domain = env('SPLUS_AGENT_DOMAIN', '');
        $account = env('SPLUS_AGENT_ACCOUNT', '');
        $password = env('SPLUS_AGENT_PASSWORD', '');
        
        $domainJs = json_encode($domain);
        $accountJs = json_encode($account);
        $passwordJs = json_encode($password);

        $loginCode = $this->generateSplusPuppeteerLoginCode('page');

        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            async function getLoginToken() {
                const browser = await puppeteer.launch({
                    headless: 'new',
                    args: ['--no-sandbox', '--disable-setuid-sandbox']
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });
                    
                    {$loginCode}
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 獲取 LocalStorage 中的 loginToken 和 Cookies
                    const authData = await page.evaluate(() => {
                        const token = window.localStorage.getItem('loginToken');
                        return { token: token };
                    });
                    
                    // 獲取所有 cookies
                    const cookies = await page.cookies();
                    
                    if (authData.token) {
                        fs.writeFileSync('token_result.json', JSON.stringify({
                            success: true,
                            loginToken: authData.token,
                            cookies: cookies
                        }, null, 2));
                        return { token: authData.token, cookies: cookies };
                    } else {
                        fs.writeFileSync('token_result.json', JSON.stringify({
                            success: false,
                            error: 'Token not found in LocalStorage'
                        }, null, 2));
                        return null;
                    }
                } finally {
                    await browser.close();
                }
            }

            getLoginToken().then(() => {
                process.exit(0);
            }).catch((error) => {
                console.error('Error:', error);
                fs.writeFileSync('token_result.json', JSON.stringify({
                    success: false,
                    error: error.message
                }, null, 2));
                process.exit(1);
            });
        JS;

        $scriptPath = storage_path('app/temp/fetch_token.js');
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        
        return $scriptPath;
    }

    /**
     * 執行獲取 Token 的腳本
     * @param string $scriptPath
     * @return array|null
     */
    private function runTokenFetchScript($scriptPath)
    {
        $workingDir = dirname($scriptPath);
        $result = Process::path($workingDir)->timeout(60)->run("node " . basename($scriptPath));

        if ($result->failed()) {
            $this->error("❌ Token fetch failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        $tokenFile = $workingDir . '/token_result.json';
        if (file_exists($tokenFile)) {
            $content = file_get_contents($tokenFile);
            return json_decode($content, true);
        }

        return null;
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
     * @param int $concurrency 併發數量
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date_start = null, $date_end = null, $concurrency = 4)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取 Splus 登入流程程式碼片段（主頁面用）
        $cookiesCodeForPage = $this->generateSplusPuppeteerLoginCode('page');
        // 獲取認證 cookies 程式碼片段（併發頁面用，登入後可以重用 cookies）
        $cookiesCodeForNewPage = $this->generateSplusPuppeteerLoginCode('newPage');

        // 將 date 轉換為 JavaScript 可用的格式
        $dateStartJs = $date_start ? json_encode(date('Y-m-d', strtotime($date_start))) : 'null';
        $dateEndJs = $date_end ? json_encode(date('Y-m-d', strtotime($date_end))) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 併發控制器：限制同時執行的 Promise 數量
             * @param {Array} items - 準備要處理的項目列表（例如要爬取的頁面資訊）
             * @param {Number} limit - 併發數量上限（同時最多執行幾個任務）
             * @param {Function} fn - 要執行的函數，接收 (item, index) 兩個參數
             * @return {Promise<Array>} 返回所有執行結果的陣列
             */
            async function promiseAllWithLimit(items, limit, fn) {
                // 創建兩個陣列來追蹤任務狀態
                const results = [];   // 儲存所有任務的 Promise（包含已完成和未完成的）
                const executing = []; // 儲存「正在執行中」的任務 Promise
                
                // 所有要處理的項目執行迴圈
                for (const [index, item] of items.entries()) {
                    // 為每個項目創建一個 Promise
                    // Promise.resolve().then() 確保函數是異步執行的
                    const promise = Promise.resolve().then(() => fn(item, index));
                    
                    // 將這個 Promise 加入結果陣列
                    // 注意：這裡只是「記錄」這個 Promise，任務可能還沒開始執行
                    results.push(promise);
                    
                    // 併發控制邏輯（核心部分）
                    if (limit <= items.length) {
                        // 創建一個「可追蹤」的 Promise
                        // 當原始 Promise 完成時，自動從 executing 陣列中移除自己
                        const executing_promise = promise.then(() => 
                            executing.splice(executing.indexOf(executing_promise), 1)
                        );
                        
                        // 將這個任務加入「執行中」的任務池
                        executing.push(executing_promise);
                        
                        // 如果執行中的任務數量達到上限
                        if (executing.length >= limit) {
                            // 使用 Promise.race 等待「任何一個」任務完成
                            // Promise.race 的特性：只要陣列中有一個 Promise 完成，就會 resolve
                            // 這樣可以確保：當一個任務完成後，立即可以開始下一個任務
                            await Promise.race(executing);
                            
                            // 執行到這裡時，表示至少有一個任務完成了
                            // 該任務已經自動從 executing 陣列中移除（見上面的 splice）
                            // 現在 executing.length < limit，可以繼續添加新任務
                        }
                    }
                }
                
                // 等待所有任務完成
                // Promise.all 會等待 results 陣列中的所有 Promise 都完成
                // 返回一個包含所有結果的陣列
                return Promise.all(results);
            }

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容（併發版本）
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

                    // 攔截並阻止不必要的資源載入（大幅提升速度）
                    await page.setRequestInterception(true);
                    page.on('request', (req) => {
                        const resourceType = req.resourceType();
                        // 只阻止圖片、字體、媒體檔案，保留 CSS 和 JS 以確保分頁功能正常
                        if (['image', 'font', 'media'].includes(resourceType)) {
                            req.abort();
                        } else {
                            req.continue();
                        }
                    });

                    $cookiesCodeForPage

                    // 保存登入資訊到 LocalStorage（用於併發爬取時恢復登入狀態）
                    console.log('💾 Saving login information from LocalStorage...');
                    const savedLocalStorage = await page.evaluate(() => {
                        const storage = {};
                        for (let i = 0; i < window.localStorage.length; i++) {
                            const key = window.localStorage.key(i);
                            storage[key] = window.localStorage.getItem(key);
                        }
                        return storage;
                    });
                    console.log('✅ Login information saved (' + Object.keys(savedLocalStorage).length + ' items)');
                    
                    /**
                     * 恢復 LocalStorage 到指定頁面
                     * @param {Page} targetPage - 目標頁面對象
                     * @param {Object} storageData - 要恢復的 LocalStorage 數據
                     */
                    async function restoreLocalStorage(targetPage, storageData) {
                        await targetPage.evaluate((data) => {
                            // 清空現有的 LocalStorage
                            window.localStorage.clear();
                            // 恢復保存的數據
                            Object.keys(data).forEach(key => {
                                window.localStorage.setItem(key, data[key]);
                            });
                        }, storageData);
                    }
                    
                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            // console.log('❌ Browser console error:', msg.text());
                        }
                    });

                    console.log('🌐 Navigating to:', '$url');
                    
                    // 導航到目標頁面
                    // 使用 'domcontentloaded' 替代 'networkidle2' 加快載入速度
                    // timeout: 30000 設定 30 秒超時
                    console.log('🌐 Navigating to target URL:', '$url');
                    await page.goto('$url', {
                        waitUntil: 'domcontentloaded',
                        timeout: 30000
                    });
                    
                    // 等待頁面載入
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 解析 date 參數
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    
                    try {
                        if ($dateStartJs && $dateStartJs !== 'null' && $dateStartJs !== '') {
                            dateStartParsed = JSON.parse($dateStartJs);
                        }
                        if ($dateEndJs && $dateEndJs !== 'null' && $dateEndJs !== '') {
                            dateEndParsed = JSON.parse($dateEndJs);
                        }
                    } catch (e) {
                        dateStartParsed = $dateStartJs !== 'null' ? $dateStartJs : null;
                        dateEndParsed = $dateEndJs !== 'null' ? $dateEndJs : null;
                    }
                    
                    // 如果提供了 date_start 和 date_end，處理 Element UI 日期選擇器
                    if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') && (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                        try {
                            // 等待日期選擇器輸入框出現
                            await page.waitForSelector('input.el-range-input[placeholder="Start Time"]', { timeout: 10000 });
                            
                            // 點擊打開日期選擇器
                            await page.click('input.el-range-input[placeholder="Start Time"]');
                            
                            // 等待日期選擇面板出現
                            await new Promise(resolve => setTimeout(resolve, 1500));
                            
                            // 填入開始日期
                            await page.waitForSelector('input.el-input__inner[placeholder="Start Date"]', { timeout: 10000 });
                            
                            // 使用更可靠的方法填入日期：先聚焦，清空，然後輸入
                            const startDateInput = await page.$('input.el-input__inner[placeholder="Start Date"]');
                            if (startDateInput) {
                                await startDateInput.click({ clickCount: 3 }); // 三擊選中所有文字
                                await startDateInput.type(dateStartParsed, { delay: 100 }); // 使用 type 方法，更可靠
                                
                                // 觸發事件確保 Element UI 識別輸入
                                await page.evaluate((dateStartValue) => {
                                    const input = document.querySelector('input.el-input__inner[placeholder="Start Date"]');
                                    if (input) {
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                }, dateStartParsed);
                            }
                            
                            // 等待一下確保輸入完成
                            await new Promise(resolve => setTimeout(resolve, 800));
                            
                            // 填入結束日期
                            await page.waitForSelector('input.el-input__inner[placeholder="End Date"]', { timeout: 10000 });
                            
                            // 使用更可靠的方法填入日期
                            const endDateInput = await page.$('input.el-input__inner[placeholder="End Date"]');
                            if (endDateInput) {
                                await endDateInput.click({ clickCount: 3 }); // 三擊選中所有文字
                                await endDateInput.type(dateEndParsed, { delay: 100 }); // 使用 type 方法，更可靠
                                
                                // 觸發事件確保 Element UI 識別輸入
                                await page.evaluate((dateEndValue) => {
                                    const input = document.querySelector('input.el-input__inner[placeholder="End Date"]');
                                    if (input) {
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                        input.dispatchEvent(new Event('blur', { bubbles: true }));
                                    }
                                }, dateEndParsed);
                            }
                            
                            // 等待一下確保輸入完成
                            await new Promise(resolve => setTimeout(resolve, 800));
                            
                            // 使用 page.evaluate 同時設置兩個日期值，確保兩個值都正確（防止開始日期被清空）
                            const dateValues = await page.evaluate((dateStartValue, dateEndValue) => {
                                const startInput = document.querySelector('input.el-input__inner[placeholder="Start Date"]');
                                const endInput = document.querySelector('input.el-input__inner[placeholder="End Date"]');
                                
                                const startValueBefore = startInput ? startInput.value : '';
                                const endValueBefore = endInput ? endInput.value : '';
                                
                                // 同時設置兩個值，確保都正確
                                if (startInput) {
                                    startInput.value = dateStartValue;
                                    startInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    startInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                                
                                if (endInput) {
                                    endInput.value = dateEndValue;
                                    endInput.dispatchEvent(new Event('input', { bubbles: true }));
                                    endInput.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                                
                                // 再次觸發事件確保 Element UI 識別
                                if (startInput) {
                                    startInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                                if (endInput) {
                                    endInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                }
                                
                                return {
                                    startValue: startInput ? startInput.value : '',
                                    endValue: endInput ? endInput.value : '',
                                    startWasFixed: startValueBefore !== dateStartValue,
                                    endWasFixed: endValueBefore !== dateEndValue
                                };
                            }, dateStartParsed, dateEndParsed);
                            
                            // 等待一下確保修正完成
                            await new Promise(resolve => setTimeout(resolve, 500));
                            
                            // 查找並點擊 OK 按鈕
                            const okButton = await page.evaluate(() => {
                                // 查找包含 "OK" 文本的按鈕
                                const allButtons = Array.from(document.querySelectorAll('button.el-picker-panel__link-btn'));
                                let okBtn = allButtons.find(btn => {
                                    const span = btn.querySelector('span');
                                    return span && span.textContent.trim() === 'OK';
                                });

                                // 判斷 okBtn 是否存在
                                if (okBtn) {
                                    const uniqueId = 'ok-btn-' + Date.now();
                                    okBtn.setAttribute('data-puppeteer-id', uniqueId);
                                    return {
                                        found: true,
                                        selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                        text: okBtn.querySelector('span').textContent.trim()
                                    };
                                }
                                
                                return { found: false };
                            });

                            // 判斷是否有找到 OK 按鈕
                            if (okButton.found) {
                                await page.click(okButton.selector, { timeout: 5000 });
                                
                                // 等待日期選擇器關閉
                                await new Promise(resolve => setTimeout(resolve, 1500));
                                
                                // 查找並點擊 Search 按鈕
                                const searchButton = await page.evaluate(() => {
                                    // 查找包含 "Search" 文本的按鈕
                                    const allButtons = Array.from(document.querySelectorAll('button.default-btn, button.base_button'));
                                    let searchBtn = allButtons.find(btn => {
                                        const span = btn.querySelector('span.v-btn__content');
                                        return span && span.textContent.trim() === 'Search';
                                    });

                                    // 判斷 searchBtn 是否存在
                                    if (searchBtn) {
                                        const uniqueId = 'search-btn-' + Date.now();
                                        searchBtn.setAttribute('data-puppeteer-id', uniqueId);
                                        return {
                                            found: true,
                                            selector: '[data-puppeteer-id="' + uniqueId + '"]',
                                            text: searchBtn.querySelector('span.v-btn__content').textContent.trim()
                                        };
                                    }
                                    
                                    return { found: false };
                                });

                                // 判斷是否有找到 Search 按鈕
                                if (searchButton.found) {
                                    await page.click(searchButton.selector, { timeout: 5000 });
                                    
                                    // 等待搜索結果載入
                                    await new Promise(resolve => setTimeout(resolve, 3000));
                                    
                                    // 等待表格出現
                                    await page.waitForSelector('table tbody#ele-table-body', { timeout: 10000 }).catch(() => {
                                        console.log('⚠️  Table not found, waiting 2 more seconds...');
                                    });
                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                    
                                    // 提取分頁信息
                                    const paginationInfo = await page.evaluate(() => {
                                        const pagination = document.querySelector('div.el-pagination');
                                        if (!pagination) {
                                            return { found: false, error: 'Pagination not found' };
                                        }
                                        
                                        // 獲取總數
                                        const totalSpan = pagination.querySelector('span.el-pagination__total');
                                        const totalText = totalSpan ? totalSpan.textContent.trim() : '';
                                        const totalMatch = totalText.match(/Total\s+(\d+)/);
                                        const total = totalMatch ? parseInt(totalMatch[1]) : 0;
                                        
                                        // 獲取當前頁
                                        const activePage = pagination.querySelector('li.number.active');
                                        const currentPage = activePage ? parseInt(activePage.textContent.trim()) : 1;
                                        
                                        // 獲取所有頁碼
                                        const pageNumbers = [];
                                        const pageItems = pagination.querySelectorAll('li.number');
                                        pageItems.forEach(item => {
                                            if (!item.classList.contains('more')) {
                                                const pageNum = parseInt(item.textContent.trim());
                                                if (!isNaN(pageNum)) {
                                                    pageNumbers.push(pageNum);
                                                }
                                            }
                                        });
                                        
                                        // 獲取最後一頁的頁碼（從分頁器中）
                                        let lastPage = currentPage;
                                        if (pageNumbers.length > 0) {
                                            lastPage = Math.max(...pageNumbers);
                                        }
                                        
                                        // 如果有 "more" 按鈕，嘗試獲取最後一頁
                                        const lastPageItem = pagination.querySelector('li.number:last-child');
                                        if (lastPageItem && !lastPageItem.classList.contains('more')) {
                                            const lastPageNum = parseInt(lastPageItem.textContent.trim());
                                            if (!isNaN(lastPageNum)) {
                                                lastPage = lastPageNum;
                                            }
                                        }
                                        
                                        return {
                                            found: true,
                                            total: total,
                                            currentPage: currentPage,
                                            lastPage: lastPage,
                                            pageNumbers: pageNumbers
                                        };
                                    });
                                    
                                    // 提取表格資料的函數（可重用）
                                    const extractTableData = async () => {
                                        return await page.evaluate(() => {
                                            const table = document.querySelector('table');
                                            if (!table) {
                                                return { found: false, error: 'Table not found' };
                                            }
                                            
                                            // 提取表頭
                                            const headers = [];
                                            const thead = table.querySelector('thead');
                                            if (thead) {
                                                const headerRow = thead.querySelector('tr');
                                                if (headerRow) {
                                                    const headerCells = headerRow.querySelectorAll('th');
                                                    headerCells.forEach(cell => {
                                                        const span = cell.querySelector('span span');
                                                        const headerText = span ? span.textContent.trim() : cell.textContent.trim();
                                                        if (headerText) {
                                                            headers.push(headerText);
                                                        }
                                                    });
                                                }
                                            }
                                            
                                            // 提取資料行
                                            const tbody = table.querySelector('tbody#ele-table-body');
                                            if (!tbody) {
                                                return { found: false, error: 'Table body not found' };
                                            }
                                            
                                            const rows = [];
                                            const allRows = Array.from(tbody.querySelectorAll('tr'));
                                            
                                            for (let i = 0; i < allRows.length; i++) {
                                                const row = allRows[i];
                                                
                                                // 跳過 detail-row（詳細信息行）
                                                if (row.classList.contains('detail-row')) {
                                                    continue;
                                                }
                                                
                                                // 跳過 Subtotal 行
                                                const firstCell = row.querySelector('td');
                                                if (firstCell) {
                                                    const firstCellText = firstCell.textContent.trim();
                                                    if (firstCellText === 'Subtotal') {
                                                        continue;
                                                    }
                                                }
                                                
                                                // 提取主行數據
                                                const cells = row.querySelectorAll('td');
                                                const rowData = {};
                                                
                                                cells.forEach((cell, index) => {
                                                    // 獲取實際文本內容
                                                    const div = cell.querySelector('div.inner-row--1');
                                                    let cellText = '';
                                                    
                                                    if (div) {
                                                        // 檢查是否有嵌套的 div
                                                        const innerDiv = div.querySelector('div');
                                                        if (innerDiv) {
                                                            cellText = innerDiv.textContent.trim();
                                                        } else {
                                                            cellText = div.textContent.trim();
                                                        }
                                                    } else {
                                                        cellText = cell.textContent.trim();
                                                    }
                                                    
                                                    // 使用表頭作為 key，如果沒有表頭則使用索引
                                                    if (headers[index]) {
                                                        // 清理字段名
                                                        let cleanHeader = headers[index]
                                                            .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                            .replace(/^_+|_+$/g, '');
                                                        
                                                        if (!cleanHeader) {
                                                            cleanHeader = 'column_' + index;
                                                        }
                                                        
                                                        // 確保字段名唯一
                                                        let finalHeader = cleanHeader;
                                                        let counter = 1;
                                                        while (rowData.hasOwnProperty(finalHeader)) {
                                                            finalHeader = cleanHeader + '_' + counter;
                                                            counter++;
                                                        }
                                                        
                                                        rowData[finalHeader] = cellText;
                                                    } else {
                                                        rowData['column_' + index] = cellText;
                                                    }
                                                });
                                                
                                                // 檢查下一行是否是 detail-row
                                                if (i + 1 < allRows.length && allRows[i + 1].classList.contains('detail-row')) {
                                                    const detailRow = allRows[i + 1];
                                                    const detailGrid = detailRow.querySelector('div.detail-grid');
                                                    
                                                    if (detailGrid) {
                                                        const detailItems = detailGrid.querySelectorAll('div.detail-item');
                                                        const details = {};
                                                        
                                                        detailItems.forEach(item => {
                                                            const label = item.querySelector('div.detail-label');
                                                            const value = item.querySelector('div.detail-value');
                                                            
                                                            if (label && value) {
                                                                const labelText = label.textContent.trim().replace(':', '');
                                                                let cleanLabel = labelText
                                                                    .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                                    .replace(/^_+|_+$/g, '');
                                                                
                                                                // 獲取值（可能包含按鈕，只取文本）
                                                                let valueText = value.textContent.trim();
                                                                
                                                                // 如果值包含按鈕，標記為有按鈕
                                                                const button = value.querySelector('button');
                                                                if (button) {
                                                                    valueText = valueText.replace(button.textContent.trim(), '').trim();
                                                                    details[cleanLabel + '_hasButton'] = true;
                                                                }
                                                                
                                                                details[cleanLabel] = valueText;
                                                            }
                                                        });
                                                        
                                                        rowData.details = details;
                                                    }
                                                    
                                                    // 跳過 detail-row
                                                    i++;
                                                }
                                                
                                                if (Object.keys(rowData).length > 0) {
                                                    rows.push(rowData);
                                                }
                                            }
                                            
                                            return {
                                                found: true,
                                                headers: headers,
                                                rowCount: rows.length,
                                                data: rows
                                            };
                                        });
                                    };
                                    
                                    // ========== 步驟 1：爬取第一頁 ==========
                                    console.log('📄 Step 1: Extracting first page data...');
                                    const firstPageData = await extractTableData();
                                    
                                    // ========== 步驟 2：爬取所有其他頁面 ==========
                                    let allPagesData = [];
                                    let allRows = [];
                                    let headers = firstPageData.headers || [];
                                    
                                    if (firstPageData.found) {
                                        allPagesData.push({
                                            pageNumber: 1,
                                            rowCount: firstPageData.rowCount,
                                            data: firstPageData.data
                                        });
                                        allRows = allRows.concat(firstPageData.data);
                                    }
                                    
                                    // 如果有分頁，使用併發爬取其他頁面
                                    if (paginationInfo.found && paginationInfo.lastPage > 1) {
                                        console.log('📄 Step 2: Starting concurrent scraping for pages 2 to ' + paginationInfo.lastPage + '...');
                                        
                                        // 創建要爬取的頁面列表
                                        const pagesToScrape = [];
                                        for (let i = 2; i <= paginationInfo.lastPage; i++) {
                                            pagesToScrape.push(i);
                                        }
                                        
                                        /**
                                         * 併發爬取單個頁面的函數
                                         * @param {Number} pageNum - 要爬取的頁碼
                                         * @param {Number} index - 索引（用於日誌）
                                         */
                                        async function scrapePageConcurrently(pageNum, index) {
                                            const newPage = await browser.newPage();
                                            
                                            try {
                                                // 設定視窗大小
                                                await newPage.setViewport({ width: 1920, height: 1080 });
                                                
                                                // 設定 User Agent
                                                await newPage.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
                                                
                                                // 攔截並阻止不必要的資源載入
                                                await newPage.setRequestInterception(true);
                                                newPage.on('request', (req) => {
                                                    const resourceType = req.resourceType();
                                                    if (['image', 'font', 'media'].includes(resourceType)) {
                                                        req.abort();
                                                    } else {
                                                        req.continue();
                                                    }
                                                });
                                                
                                                // 導航到目標 URL
                                                await newPage.goto('$url', {
                                                    waitUntil: 'domcontentloaded',
                                                    timeout: 30000
                                                });
                                                
                                                // 等待頁面載入
                                                await new Promise(resolve => setTimeout(resolve, 2000));
                                                
                                                // 恢復 LocalStorage（恢復登入狀態）
                                                await restoreLocalStorage(newPage, savedLocalStorage);
                                                
                                                // 等待一下確保 LocalStorage 已恢復
                                                await new Promise(resolve => setTimeout(resolve, 500));
                                                
                                                // 重新載入頁面以應用 LocalStorage
                                                await newPage.reload({ waitUntil: 'domcontentloaded' });
                                                await new Promise(resolve => setTimeout(resolve, 2000));
                                                
                                                // 設置日期範圍
                                                if ((dateStartParsed && dateStartParsed !== null && dateStartParsed !== '') && (dateEndParsed && dateEndParsed !== null && dateEndParsed !== '')) {
                                                    try {
                                                        // 等待日期選擇器輸入框出現
                                                        await newPage.waitForSelector('input.el-range-input[placeholder="Start Time"]', { timeout: 10000 });
                                                        
                                                        // 點擊打開日期選擇器
                                                        await newPage.click('input.el-range-input[placeholder="Start Time"]');
                                                        await new Promise(resolve => setTimeout(resolve, 1500));
                                                        
                                                        // 填入開始日期
                                                        await newPage.waitForSelector('input.el-input__inner[placeholder="Start Date"]', { timeout: 10000 });
                                                        const startDateInput = await newPage.$('input.el-input__inner[placeholder="Start Date"]');
                                                        if (startDateInput) {
                                                            await startDateInput.click({ clickCount: 3 });
                                                            await startDateInput.type(dateStartParsed, { delay: 100 });
                                                            await newPage.evaluate((dateStartValue) => {
                                                                const input = document.querySelector('input.el-input__inner[placeholder="Start Date"]');
                                                                if (input) {
                                                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                                                }
                                                            }, dateStartParsed);
                                                        }
                                                        await new Promise(resolve => setTimeout(resolve, 800));
                                                        
                                                        // 填入結束日期
                                                        await newPage.waitForSelector('input.el-input__inner[placeholder="End Date"]', { timeout: 10000 });
                                                        const endDateInput = await newPage.$('input.el-input__inner[placeholder="End Date"]');
                                                        if (endDateInput) {
                                                            await endDateInput.click({ clickCount: 3 });
                                                            await endDateInput.type(dateEndParsed, { delay: 100 });
                                                            await newPage.evaluate((dateEndValue) => {
                                                                const input = document.querySelector('input.el-input__inner[placeholder="End Date"]');
                                                                if (input) {
                                                                    input.dispatchEvent(new Event('input', { bubbles: true }));
                                                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                                                    input.dispatchEvent(new Event('blur', { bubbles: true }));
                                                                }
                                                            }, dateEndParsed);
                                                        }
                                                        await new Promise(resolve => setTimeout(resolve, 800));
                                                        
                                                        // 同時設置兩個日期值
                                                        await newPage.evaluate((dateStartValue, dateEndValue) => {
                                                            const startInput = document.querySelector('input.el-input__inner[placeholder="Start Date"]');
                                                            const endInput = document.querySelector('input.el-input__inner[placeholder="End Date"]');
                                                            if (startInput) {
                                                                startInput.value = dateStartValue;
                                                                startInput.dispatchEvent(new Event('input', { bubbles: true }));
                                                                startInput.dispatchEvent(new Event('change', { bubbles: true }));
                                                                startInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                                            }
                                                            if (endInput) {
                                                                endInput.value = dateEndValue;
                                                                endInput.dispatchEvent(new Event('input', { bubbles: true }));
                                                                endInput.dispatchEvent(new Event('change', { bubbles: true }));
                                                                endInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                                            }
                                                        }, dateStartParsed, dateEndParsed);
                                                        await new Promise(resolve => setTimeout(resolve, 500));
                                                        
                                                        // 點擊 OK 按鈕
                                                        const okButton = await newPage.evaluate(() => {
                                                            const allButtons = Array.from(document.querySelectorAll('button.el-picker-panel__link-btn'));
                                                            let okBtn = allButtons.find(btn => {
                                                                const span = btn.querySelector('span');
                                                                return span && span.textContent.trim() === 'OK';
                                                            });
                                                            if (okBtn) {
                                                                const uniqueId = 'ok-btn-' + Date.now();
                                                                okBtn.setAttribute('data-puppeteer-id', uniqueId);
                                                                return {
                                                                    found: true,
                                                                    selector: '[data-puppeteer-id="' + uniqueId + '"]'
                                                                };
                                                            }
                                                            return { found: false };
                                                        });
                                                        
                                                        if (okButton.found) {
                                                            await newPage.click(okButton.selector, { timeout: 5000 });
                                                            await new Promise(resolve => setTimeout(resolve, 1500));
                                                            
                                                            // 點擊 Search 按鈕
                                                            const searchButton = await newPage.evaluate(() => {
                                                                const allButtons = Array.from(document.querySelectorAll('button.default-btn, button.base_button'));
                                                                let searchBtn = allButtons.find(btn => {
                                                                    const span = btn.querySelector('span.v-btn__content');
                                                                    return span && span.textContent.trim() === 'Search';
                                                                });
                                                                if (searchBtn) {
                                                                    const uniqueId = 'search-btn-' + Date.now();
                                                                    searchBtn.setAttribute('data-puppeteer-id', uniqueId);
                                                                    return {
                                                                        found: true,
                                                                        selector: '[data-puppeteer-id="' + uniqueId + '"]'
                                                                    };
                                                                }
                                                                return { found: false };
                                                            });
                                                            
                                                            if (searchButton.found) {
                                                                await newPage.click(searchButton.selector, { timeout: 5000 });
                                                                await new Promise(resolve => setTimeout(resolve, 3000));
                                                                await newPage.waitForSelector('table tbody#ele-table-body', { timeout: 10000 }).catch(() => {});
                                                                await new Promise(resolve => setTimeout(resolve, 1000));
                                                            }
                                                        }
                                                    } catch (e) {
                                                        console.log('⚠️  [Page ' + pageNum + '] Error setting date range: ' + e.message);
                                                    }
                                                }
                                                
                                                // 跳轉到指定頁碼
                                                const pageJumped = await newPage.evaluate((targetPage) => {
                                                    const pagination = document.querySelector('div.el-pagination');
                                                    if (!pagination) return { success: false, error: 'Pagination not found' };
                                                    
                                                    // 查找指定頁碼的按鈕
                                                    const pageButtons = pagination.querySelectorAll('li.number');
                                                    let targetButton = null;
                                                    
                                                    for (let btn of pageButtons) {
                                                        if (!btn.classList.contains('more')) {
                                                            const pageNum = parseInt(btn.textContent.trim());
                                                            if (pageNum === targetPage) {
                                                                targetButton = btn;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                    
                                                    if (targetButton) {
                                                        targetButton.click();
                                                        return { success: true };
                                                    }
                                                    
                                                    // 如果找不到按鈕，嘗試使用輸入框跳轉
                                                    const jumpInput = pagination.querySelector('input.el-pagination__editor');
                                                    if (jumpInput) {
                                                        jumpInput.value = targetPage;
                                                        jumpInput.dispatchEvent(new Event('input', { bubbles: true }));
                                                        jumpInput.dispatchEvent(new Event('change', { bubbles: true }));
                                                        jumpInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
                                                        jumpInput.dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', bubbles: true }));
                                                        return { success: true, method: 'input' };
                                                    }
                                                    
                                                    return { success: false, error: 'Cannot find page ' + targetPage };
                                                }, pageNum);
                                                
                                                if (pageJumped.success) {
                                                    // 等待頁面載入
                                                    await new Promise(resolve => setTimeout(resolve, 2500));
                                                    await newPage.waitForSelector('table tbody#ele-table-body', { timeout: 10000 }).catch(() => {});
                                                    await new Promise(resolve => setTimeout(resolve, 1000));
                                                } else {
                                                    console.log('⚠️  [Page ' + pageNum + '] Cannot jump to page: ' + (pageJumped.error || 'Unknown error'));
                                                }
                                                
                                                // 提取當前頁的數據
                                                const pageData = await newPage.evaluate(() => {
                                                    const table = document.querySelector('table');
                                                    if (!table) {
                                                        return { found: false, error: 'Table not found' };
                                                    }
                                                    
                                                    const headers = [];
                                                    const thead = table.querySelector('thead');
                                                    if (thead) {
                                                        const headerRow = thead.querySelector('tr');
                                                        if (headerRow) {
                                                            const headerCells = headerRow.querySelectorAll('th');
                                                            headerCells.forEach(cell => {
                                                                const span = cell.querySelector('span span');
                                                                const headerText = span ? span.textContent.trim() : cell.textContent.trim();
                                                                if (headerText) {
                                                                    headers.push(headerText);
                                                                }
                                                            });
                                                        }
                                                    }
                                                    
                                                    const tbody = table.querySelector('tbody#ele-table-body');
                                                    if (!tbody) {
                                                        return { found: false, error: 'Table body not found' };
                                                    }
                                                    
                                                    const rows = [];
                                                    const allRows = Array.from(tbody.querySelectorAll('tr'));
                                                    
                                                    for (let i = 0; i < allRows.length; i++) {
                                                        const row = allRows[i];
                                                        if (row.classList.contains('detail-row')) {
                                                            continue;
                                                        }
                                                        
                                                        const firstCell = row.querySelector('td');
                                                        if (firstCell) {
                                                            const firstCellText = firstCell.textContent.trim();
                                                            if (firstCellText === 'Subtotal') {
                                                                continue;
                                                            }
                                                        }
                                                        
                                                        const cells = row.querySelectorAll('td');
                                                        const rowData = {};
                                                        
                                                        cells.forEach((cell, index) => {
                                                            const div = cell.querySelector('div.inner-row--1');
                                                            let cellText = '';
                                                            
                                                            if (div) {
                                                                const innerDiv = div.querySelector('div');
                                                                if (innerDiv) {
                                                                    cellText = innerDiv.textContent.trim();
                                                                } else {
                                                                    cellText = div.textContent.trim();
                                                                }
                                                            } else {
                                                                cellText = cell.textContent.trim();
                                                            }
                                                            
                                                            if (headers[index]) {
                                                                let cleanHeader = headers[index]
                                                                    .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                                    .replace(/^_+|_+$/g, '');
                                                                
                                                                if (!cleanHeader) {
                                                                    cleanHeader = 'column_' + index;
                                                                }
                                                                
                                                                let finalHeader = cleanHeader;
                                                                let counter = 1;
                                                                while (rowData.hasOwnProperty(finalHeader)) {
                                                                    finalHeader = cleanHeader + '_' + counter;
                                                                    counter++;
                                                                }
                                                                
                                                                rowData[finalHeader] = cellText;
                                                            } else {
                                                                rowData['column_' + index] = cellText;
                                                            }
                                                        });
                                                        
                                                        if (i + 1 < allRows.length && allRows[i + 1].classList.contains('detail-row')) {
                                                            const detailRow = allRows[i + 1];
                                                            const detailGrid = detailRow.querySelector('div.detail-grid');
                                                            
                                                            if (detailGrid) {
                                                                const detailItems = detailGrid.querySelectorAll('div.detail-item');
                                                                const details = {};
                                                                
                                                                detailItems.forEach(item => {
                                                                    const label = item.querySelector('div.detail-label');
                                                                    const value = item.querySelector('div.detail-value');
                                                                    
                                                                    if (label && value) {
                                                                        const labelText = label.textContent.trim().replace(':', '');
                                                                        let cleanLabel = labelText
                                                                            .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                                                            .replace(/^_+|_+$/g, '');
                                                                        
                                                                        let valueText = value.textContent.trim();
                                                                        const button = value.querySelector('button');
                                                                        if (button) {
                                                                            valueText = valueText.replace(button.textContent.trim(), '').trim();
                                                                            details[cleanLabel + '_hasButton'] = true;
                                                                        }
                                                                        
                                                                        details[cleanLabel] = valueText;
                                                                    }
                                                                });
                                                                
                                                                rowData.details = details;
                                                            }
                                                            
                                                            i++;
                                                        }
                                                        
                                                        if (Object.keys(rowData).length > 0) {
                                                            rows.push(rowData);
                                                        }
                                                    }
                                                    
                                                    return {
                                                        found: true,
                                                        headers: headers,
                                                        rowCount: rows.length,
                                                        data: rows
                                                    };
                                                });
                                                
                                                return {
                                                    success: true,
                                                    pageNumber: pageNum,
                                                    data: pageData
                                                };
                                                
                                            } catch (error) {
                                                console.log('⚠️  [Page ' + pageNum + '] Error: ' + error.message);
                                                return {
                                                    success: false,
                                                    pageNumber: pageNum,
                                                    error: error.message
                                                };
                                            } finally {
                                                await newPage.close();
                                            }
                                        }
                                        
                                        // 使用併發控制器執行爬取
                                        const concurrencyLimit = {$concurrency};
                                        console.log('🚀 Starting concurrent scraping with limit: ' + concurrencyLimit);
                                        const concurrentResults = await promiseAllWithLimit(
                                            pagesToScrape,
                                            concurrencyLimit,
                                            scrapePageConcurrently
                                        );
                                        
                                        // 處理併發爬取的結果
                                        concurrentResults.forEach(result => {
                                            if (result.success && result.data && result.data.found) {
                                                allPagesData.push({
                                                    pageNumber: result.pageNumber,
                                                    rowCount: result.data.rowCount,
                                                    data: result.data.data
                                                });
                                                allRows = allRows.concat(result.data.data);
                                                console.log('✅ [Page ' + result.pageNumber + '] Scraped ' + result.data.rowCount + ' rows');
                                            } else {
                                                console.log('⚠️  [Page ' + result.pageNumber + '] Failed: ' + (result.error || 'Unknown error'));
                                            }
                                        });
                                        
                                        console.log('✅ Concurrent scraping completed! Total pages scraped: ' + allPagesData.length);
                                    }
                                    
                                    // ========== 步驟 3：合併所有數據 ==========
                                    const mergedTableData = {
                                        found: true,
                                        headers: headers,
                                        totalPages: allPagesData.length,
                                        totalRows: allRows.length,
                                        pages: allPagesData,
                                        data: allRows
                                    };
                                    
                                    if (mergedTableData.found) {
                                        // 保存表格資料到文件
                                        const tableDataFile = {
                                            timestamp: new Date().toISOString(),
                                            url: '$url',
                                            dateStart: dateStartParsed,
                                            dateEnd: dateEndParsed,
                                            tableData: mergedTableData
                                        };
                                        fs.writeFileSync('table_data.json', JSON.stringify(tableDataFile, null, 2));
                                        
                                        // 截圖表格
                                        console.log('📸 Taking screenshot of table...');
                                        await page.screenshot({ 
                                            path: 'table_screenshot.png',
                                            fullPage: false
                                        });
                                        console.log('✅ Screenshot saved: table_screenshot.png');
                                        
                                        // 返回表格資料
                                        return tableDataFile;
                                    } else {
                                        console.log('⚠️  Failed to merge table data');
                                        return { success: false, error: 'Failed to merge table data' };
                                    }
                                } else {
                                    console.log('⚠️  Search button not found');
                                    // 即使沒找到按鈕也截圖
                                    await page.screenshot({ 
                                        path: 'date_search_button_not_found_screenshot.png',
                                        fullPage: false
                                    });
                                }
                            } else {
                                console.log('⚠️  OK button not found');
                                // 即使沒找到按鈕也截圖
                                await page.screenshot({ 
                                    path: 'date_ok_button_not_found_screenshot.png',
                                    fullPage: false
                                });
                            }
                        } catch (e) {
                            console.log('⚠️  Error processing date range: ' + e.message);
                            console.log('Stack trace: ' + e.stack);
                            // 即使出錯也截圖
                            await page.screenshot({ 
                                path: 'date_error_screenshot.png',
                                fullPage: false
                            });
                        }
                    }

                    // 如果沒有表格資料，返回簡單的成功標誌
                    return {
                        success: true,
                        timestamp: new Date().toISOString(),
                        url: '$url',
                        message: 'Login and navigation completed, but no table data found'
                    };
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
            scrapeDOMContent().then(() => {
                console.log('✅ DOM scraping completed successfully');
                process.exit(0);
            }).catch((error) => {
                console.error('💥 DOM scraping failed:', error);
                process.exit(1);
            });
        JS;

        // 設定腳本保存路徑
        $scriptPath = storage_path('app/temp/scraper_dom.js');

        // 確保臨時目錄存在
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
     * @return array|null 返回解析後的結果資料，失敗時返回 null
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation...');

        // 獲取腳本所在目錄，並將工作目錄切換到該目錄
        // 這樣可以確保腳本生成的臨時文件（如截圖、結果文件）在同一目錄
        $workingDir = dirname($scriptPath);

        // 在指定目錄執行 Node.js 腳本
        // 增加超時時間到 60 分鐘（6000秒），因為需要爬取多頁數據
        $result = Process::path($workingDir)->timeout(6000)->run("node " . basename($scriptPath));

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

        // 讀取腳本生成的結果文件（優先讀取表格資料，如果沒有則讀取其他結果）
        $tableDataFile = $workingDir . '/table_data.json';
        $loginResultFile = $workingDir . '/login_result.json';
        $scrapedResultFile = $workingDir . '/scraped_result.json';

        if (file_exists($tableDataFile)) {
            // 讀取並解析表格資料 JSON 文件
            $content = file_get_contents($tableDataFile);
            return json_decode($content, true);
        } elseif (file_exists($loginResultFile)) {
            // 讀取並解析登入結果 JSON 文件（向後兼容）
            $content = file_get_contents($loginResultFile);
            return json_decode($content, true);
        } elseif (file_exists($scrapedResultFile)) {
            // 讀取並解析爬取結果 JSON 文件
            $content = file_get_contents($scrapedResultFile);
            return json_decode($content, true);
        }

        $this->error("❌ No result file found");
        return null;
    }
    
    /**
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        // 檢查是否為表格資料結果（新格式）
        if (isset($result['tableData'])) {
            $this->info('✅ Table data extraction completed successfully!');
            $this->info('📍 URL: ' . ($result['url'] ?? 'N/A'));
            if (isset($result['dateStart']) && isset($result['dateEnd'])) {
                $this->info('📅 Date Range: ' . $result['dateStart'] . ' to ' . $result['dateEnd']);
            }
            
            // 處理表格資料
            $tableData = $result['tableData'];
            
            if (isset($tableData['found']) && $tableData['found']) {
                $totalRows = $tableData['totalRows'] ?? $tableData['rowCount'] ?? 0;
                $totalPages = $tableData['totalPages'] ?? 1;
                
                $this->info('📋 Total pages: ' . $totalPages);
                $this->info('📋 Total rows: ' . $totalRows);
                $this->info('📋 Table headers: ' . (count($tableData['headers'] ?? []) . ' columns'));
                
                // 保存表格資料
                $timestamp = date('Y-m-d_H-i-s');
                $tableFileName = "scraped_data/table_data_{$timestamp}.json";
                $tableFileData = [
                    'metadata' => [
                        'timestamp' => $timestamp,
                        'url' => $result['url'] ?? '',
                        'dateStart' => $result['dateStart'] ?? null,
                        'dateEnd' => $result['dateEnd'] ?? null,
                        'totalPages' => $totalPages,
                        'totalRows' => $totalRows,
                        'headers' => $tableData['headers'] ?? []
                    ],
                    'headers' => $tableData['headers'] ?? [],
                    'totalPages' => $totalPages,
                    'totalRows' => $totalRows,
                    'pages' => $tableData['pages'] ?? [],
                    'data' => $tableData['data'] ?? []
                ];
                
                Storage::put($tableFileName, json_encode($tableFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $this->info("✅ Table data saved: {$tableFileName}");
                $this->info("📊 Merged data from {$totalPages} pages with {$totalRows} total rows");
            } else {
                $this->warn('⚠️  Table data extraction failed: ' . ($tableData['error'] ?? 'Unknown error'));
            }
            
            // 處理截圖
            $timestamp = date('Y-m-d_H-i-s');
            $screenshots = [
                'target_page_screenshot.png',
                'date_before_click_screenshot.png',
                'date_picker_opened_screenshot.png',
                'date_start_filled_screenshot.png',
                'date_end_filled_screenshot.png',
                'date_selected_screenshot.png',
                'date_search_clicked_screenshot.png',
                'table_screenshot.png'
            ];
            
            foreach ($screenshots as $screenshot) {
                $screenshotSrc = storage_path('app/temp/' . $screenshot);
                if (file_exists($screenshotSrc)) {
                    $screenshotDst = storage_path("app/scraped_data/{$screenshot}_{$timestamp}.png");
                    rename($screenshotSrc, $screenshotDst);
                    $this->info("📸 Screenshot saved: {$screenshotDst}");
                }
            }
            
            $this->info('End of command at: ' . date('Y-m-d H:i:s'));
            $this->info("✅ Data processing completed!");
            return;
        }
        
        // 檢查是否為登入結果（向後兼容，舊格式）
        if (isset($result['loginUrl']) && !isset($result['domData'])) {
            $this->info('✅ Login process completed successfully!');
            $this->info('📍 Login URL: ' . ($result['loginUrl'] ?? 'N/A'));
            if (isset($result['loginPageInfo'])) {
                $this->info('📄 Login Page: ' . ($result['loginPageInfo']['url'] ?? 'N/A'));
            }
            $this->info('🍪 Cookies obtained: ' . ($result['cookiesCount'] ?? 0));
            
            // 處理所有截圖
            $timestamp = date('Y-m-d_H-i-s');
            $screenshots = [
                'login_form_screenshot.png',
                'login_after_screenshot.png',
                'login_error_screenshot.png',
                'target_page_screenshot.png',
                'date_before_click_screenshot.png',
                'date_picker_opened_screenshot.png',
                'date_start_filled_screenshot.png',
                'date_end_filled_screenshot.png',
                'date_selected_screenshot.png',
                'date_ok_button_not_found_screenshot.png',
                'date_search_clicked_screenshot.png',
                'date_search_button_not_found_screenshot.png',
                'table_screenshot.png',
                'date_error_screenshot.png'
            ];
            
            foreach ($screenshots as $screenshot) {
                $screenshotSrc = storage_path('app/temp/' . $screenshot);
                if (file_exists($screenshotSrc)) {
                    $screenshotDst = storage_path("app/scraped_data/{$screenshot}_{$timestamp}.png");
                    rename($screenshotSrc, $screenshotDst);
                    $this->info("📸 Screenshot saved: {$screenshotDst}");
                }
            }
            
            // 處理表格資料（如果有）
            if (isset($result['tableData'])) {
                $this->info('📊 Processing table data...');
                $tableData = $result['tableData'];
                
                if (isset($tableData['found']) && $tableData['found']) {
                    $totalRows = $tableData['totalRows'] ?? $tableData['rowCount'] ?? 0;
                    $totalPages = $tableData['totalPages'] ?? 1;
                    
                    $this->info('📋 Total pages: ' . $totalPages);
                    $this->info('📋 Total rows: ' . $totalRows);
                    $this->info('📋 Table headers: ' . (count($tableData['headers'] ?? []) . ' columns'));
                    
                    // 保存表格資料（包含所有頁面的合併數據）
                    $tableFileName = "scraped_data/table_data_{$timestamp}.json";
                    $tableFileData = [
                        'metadata' => [
                            'timestamp' => $timestamp,
                            'url' => $result['targetUrl'] ?? '',
                            'totalPages' => $totalPages,
                            'totalRows' => $totalRows,
                            'headers' => $tableData['headers'] ?? []
                        ],
                        'headers' => $tableData['headers'] ?? [],
                        'totalPages' => $totalPages,
                        'totalRows' => $totalRows,
                        'pages' => $tableData['pages'] ?? [],
                        'data' => $tableData['data'] ?? []
                    ];
                    
                    Storage::put($tableFileName, json_encode($tableFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->info("✅ Table data saved: {$tableFileName}");
                    $this->info("📊 Merged data from {$totalPages} pages with {$totalRows} total rows");
                } else {
                    $this->warn('⚠️  Table data extraction failed: ' . ($tableData['error'] ?? 'Unknown error'));
                }
            } else {
                $this->warn('⚠️  No table data found in result');
            }
            
            $this->info('End of command at: ' . date('Y-m-d H:i:s'));
            $this->info("✅ Login and navigation process completed!");
            return;
        }

        // 檢查爬取是否成功
        if (!$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }

        // 提取 DOM 資料
        $domData = $result['domData'] ?? [];
        
        // 獲取查詢參數
        $queryParams = $result['queryParams'] ?? [];

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存合併後的表格資料到單一 JSON 文件（主要輸出文件）
        $allData = [];
        $totalRows = 0;
        $headers = [];
        
        // 首先嘗試從 tables 中提取數據
        if (!empty($domData['tables'])) {
            foreach ($domData['tables'] as $tableIndex => $table) {
                if (!empty($table['data'])) {
                    // 將當前表格的所有數據添加到總數組中
                    $allData = array_merge($allData, $table['data']);
                    $totalRows += count($table['data']);
                    
                    // 保存表頭（使用第一個表格的表頭）
                    if (empty($headers) && !empty($table['headers'])) {
                        $headers = $table['headers'];
                    }
                }
            }
        }
        
        // 如果 tables 為空或沒有數據，嘗試從 pages 中提取數據
        if (empty($allData) && !empty($domData['pages'])) {
            foreach ($domData['pages'] as $page) {
                if (!empty($page['tables'])) {
                    foreach ($page['tables'] as $table) {
                        if (!empty($table['data'])) {
                            $allData = array_merge($allData, $table['data']);
                            $totalRows += count($table['data']);
                            
                            // 保存表頭（使用第一個表格的表頭）
                            if (empty($headers) && !empty($table['headers'])) {
                                $headers = $table['headers'];
                            }
                        }
                    }
                }
            }
        }

        // 初始化合併後的檔案名稱
        $mergedFileName = null;
        
        // 如果有資料，保存合併後的資料
        if (!empty($allData)) {
            // 清理"代理"欄位：移除"公司主站代理線"字樣
            foreach ($allData as &$row) {
                if (isset($row['代理'])) {
                    // 移除"公司主站代理線"，只保留前面的部分
                    $row['代理'] = str_replace('公司主站代理線', '', $row['代理']);
                    // 去除多餘的空白
                    $row['代理'] = trim($row['代理']);
                }
            }
            unset($row); // 解除引用
            
            // 按照平台分類數資料
            $platformData = [];
            // 平台欄位名稱
            $platformField = '平台';
            
            // 所有資料執行迴圈
            foreach ($allData as $row) {
                // 取出平台名稱
                $platform = $row[$platformField] ?? 'Unknown';
                
                // 如果平台資料不存在，創建新的平台資料
                if (!isset($platformData[$platform])) {
                    // 創建新的平台資料
                    $platformData[$platform] = [
                        'rowCount' => 0,
                        'data' => []
                    ];
                }
                
                // 將資料加入平台資料
                $platformData[$platform]['data'][] = $row;
                // 增加平台資料的行數
                $platformData[$platform]['rowCount']++;
            }
            
            // 按照平台名稱排序
            ksort($platformData);
            
            // 創建合併後的數據結構（按平台分類）
            $mergedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                    'queryParams' => $queryParams,
                    'totalPages' => $domData['totalPages'] ?? 1,
                    'totalRows' => $totalRows,
                    'platformCount' => count($platformData),
                    'platforms' => array_keys($platformData)
                ],
                'headers' => $headers,
                'headerCount' => count($headers),
                'rowCount' => $totalRows,
                'platforms' => $platformData
            ];
            
            // 保存合併後的資料到單一 JSON 檔案
            $mergedFileName = "scraped_data/scraped_data_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // 為每個平台單獨保存檔案
            foreach ($platformData as $platform => $data) {
                // 取出平台名稱
                $safePlatformName = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '_', $platform);
                
                // 創建平台專屬的資料結構
                $platformFileData = [
                    'metadata' => [
                        'timestamp' => $timestamp,
                        'platform' => $platform,
                        'url' => $result['url'] ?? '',
                        'queryParams' => $queryParams,
                        'totalPages' => $domData['totalPages'] ?? 1,
                        'totalRows' => $data['rowCount']
                    ],
                    'headers' => $headers,
                    'headerCount' => count($headers),
                    'rowCount' => $data['rowCount'],
                    'data' => $data['data']
                ];
                
                // 保存平台專屬檔案
                $platformFileName = "scraped_data/platform_{$safePlatformName}_{$timestamp}.json";
                Storage::put($platformFileName, json_encode($platformFileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            
            $this->info("✅ All platform-specific files saved!");
        }

        if (!$mergedFileName) {
            $this->warn("⚠️ No data to save.");
        }

        // 將截圖從臨時目錄移動到永久儲存目錄
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/dom_screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }
        
        // 將登入表單截圖從臨時目錄移動到永久儲存目錄
        $loginScreenshotSrc = storage_path('app/temp/login_form_screenshot.png');
        $loginScreenshotDst = storage_path("app/scraped_data/login_form_screenshot_{$timestamp}.png");
        
        if (file_exists($loginScreenshotSrc)) {
            rename($loginScreenshotSrc, $loginScreenshotDst);
            $this->info("📸 Login form screenshot saved to: {$loginScreenshotDst}");
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }
}

