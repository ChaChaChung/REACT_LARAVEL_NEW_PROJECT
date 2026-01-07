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
class ScrapeBrowserBlodplayDOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-blodplay-dom-detail {url}
     * {url} - 要爬取的目標網址（必需參數）
     * {date?} - 要選擇的日期（可選參數）
     * {account_number?} - 要選擇的帳號（可選參數）
     * {--concurrency=10} - 併發數量（可選，預設為 10）
     */
    protected $signature = 'agent:scrape-blodplay-dom-detail {url} {date?} {account_number?} {--concurrency=10}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from Blodplay DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $date = $this->argument('date');
        $accountNumber = $this->argument('account_number');

        $this->info('=== Blodplay DOM Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Date: {$date}");
        $this->info("Account Number: {$accountNumber}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));
        
        // 使用 API 方式爬取
        $this->info('📡 Using API mode for faster scraping...');
        $result = $this->scrapeViaApi($url, $date, $accountNumber);
        
        if ($result) {
            $this->processScrapedData($result);
            return 0;
        }
        
        $this->error('❌ Failed to scrape data via API');
        return 1;
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
                    $timestamp = date('Y-m-d_H-i-s');
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
            return;
        }

        // 初始化變量（用於 API 和瀏覽器兩種方式）
        $allData = [];
        $totalRows = 0;
        $headers = [];
        $totalPages = 1;
        $mergedFileName = null;
        $queryParams = [];
        $timestamp = date('Y-m-d_H-i-s'); // 生成時間戳，用於文件名
        
        // 檢查是否為 API 返回的數據格式
        if (isset($result['domData']) && isset($result['domData']['data'])) {
            // API 返回的格式：直接處理 domData.data
            $this->info('✅ Processing API data...');
            $domData = $result['domData'];
            $queryParams = $result['queryParams'] ?? [];
            
            // 直接使用 domData.data 作為 allData
            $allData = $domData['data'] ?? [];
            $totalRows = count($allData);
            $headers = $domData['headers'] ?? [];
            $totalPages = $domData['totalPages'] ?? 1;
            
            $this->info("📊 Total pages: {$totalPages}");
            $this->info("📊 Total rows: {$totalRows}");
            $this->info("📊 Headers: " . count($headers) . " columns");
            
            // 跳過後續的表格提取邏輯，直接處理數據
            goto processData;
        }
        
        // 檢查爬取是否成功（瀏覽器方式）
        if (isset($result['success']) && !$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }

        // 提取 DOM 資料
        $domData = $result['domData'] ?? [];
        
        // 獲取查詢參數
        $queryParams = $result['queryParams'] ?? [];

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 首先嘗試從 tables 中提取數據（瀏覽器方式）
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
        
        // 標籤：處理數據（用於 API 數據直接跳轉到這裡）
        processData:
        
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
            
            // 創建合併後的數據結構
            $mergedData = [
                'metadata' => [
                    'timestamp' => $timestamp,
                    'url' => $result['url'] ?? '',
                    'queryParams' => $queryParams,
                    'totalPages' => $domData['totalPages'] ?? 1,
                    'totalRows' => $totalRows
                ],
                'headers' => $headers,
                'headerCount' => count($headers),
                'rowCount' => $totalRows,
                'data' => $allData
            ];
            
            // 保存合併後的資料到單一 JSON 檔案
            $mergedFileName = "scraped_data/scraped_data_{$timestamp}.json";
            Storage::put($mergedFileName, json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if (!$mergedFileName) {
            $this->warn("⚠️ No data to save.");
        }
        
        $this->info('End of command at: ' . date('Y-m-d H:i:s'));
        $this->info("✅ Data processing completed!");
    }

    /**
     * 構建 API 查詢參數
     * @param int $page 頁碼
     * @param int|null $commitFrom 開始時間戳
     * @param int|null $commitTo 結束時間戳
     * @param string|null $accountNumber 帳號（將作為 player 參數）
     * @return array 查詢參數陣列
     */
    private function buildApiQueryParams($page, $commitFrom = null, $commitTo = null, $accountNumber = null)
    {
        $queryParams = [
            'page' => $page,
            'page_size' => 10,
            'abnormal' => false,
        ];
        
        if ($commitFrom !== null && $commitTo !== null) {
            $queryParams['commit_from'] = $commitFrom;
            $queryParams['commit_to'] = $commitTo;
        }
        
        if ($accountNumber) {
            $queryParams['player'] = $accountNumber;
        }
        
        return $queryParams;
    }

    /**
     * 提取 API 響應中的結果數據
     * @param array $responseData API 響應數據
     * @return array 包含 results 和元數據的陣列
     */
    private function extractApiResults($responseData)
    {
        $apiData = $responseData['data'] ?? $responseData;
        return [
            'results' => $apiData['results'] ?? [],
            'totalPages' => $apiData['total_pages'] ?? 1,
            'totalCount' => $apiData['count'] ?? 0,
        ];
    }

    /**
     * 使用 API 方式爬取數據
     * @param string $url 目標 URL（用於提取域名）
     * @param string|null $date 日期（YYYYMMDD 格式）
     * @param string|null $accountNumber 帳號（可選，將作為 player 參數傳遞）
     * @return array|null 返回爬取的數據，格式與瀏覽器方式一致
     */
    private function scrapeViaApi($url, $date = null, $accountNumber = null)
    {
        $this->info('📡 Starting API scraping...');
        
        $this->info("📅 Date: {$date}");
        $this->info("👤 Account Number (player): {$accountNumber}");
        
        // 解析 URL 獲取域名
        $parsedUrl = parse_url($url);
        $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        $apiUrl = $baseUrl . '/api/round/list';

        // 轉換日期為時間戳
        $commitFrom = null;
        $commitTo = null;
        
        if ($date) {
            // 如果 date 是 YYYYMMDD 格式，轉換為 YYYY-MM-DD
            if (strlen($date) === 8 && is_numeric($date)) {
                $year = substr($date, 0, 4);
                $month = substr($date, 4, 2);
                $day = substr($date, 6, 2);
                $dateFormatted = "{$year}-{$month}-{$day}";
            } else {
                $dateFormatted = $date;
            }
            
            // 轉換為時間戳（開始時間：當天 00:00:00 台北時間，結束時間：次日 00:00:00 台北時間）
            // 使用台北時區（GMT+8）確保時間正確
            $taipeiTimezone = new \DateTimeZone('Asia/Taipei');
            $startDateTime = new \DateTime($dateFormatted . ' 00:00:00', $taipeiTimezone);
            $commitFrom = $startDateTime->getTimestamp();
            
            $endDateTime = new \DateTime($dateFormatted . ' 00:00:00', $taipeiTimezone);
            $endDateTime->modify('+1 day');
            $commitTo = $endDateTime->getTimestamp();
            
            // 創建 UTC 時區對象用於顯示
            $utcTimezone = new \DateTimeZone('UTC');
            $startDateTimeUTC = clone $startDateTime;
            $startDateTimeUTC->setTimezone($utcTimezone);
            $endDateTimeUTC = clone $endDateTime;
            $endDateTimeUTC->setTimezone($utcTimezone);
        }

        // 獲取認證 cookies（使用 Blodplay 專用的環境變數）
        $token = env('BLODPLAY_AGENT_TOKEN', '');
        $lang = env('BLODPLAY_AGENT_LANG', 'zh-TW');
        $domain = env('BLODPLAY_AGENT_DOMAIN', $parsedUrl['host']);

        // 構建 cookies 字符串（使用 Blodplay 的 cookie 名稱）
        $cookies = [];
        if ($token) {
            // Blodplay 使用 __Secure-next-auth.session-token
            $cookies[] = "__Secure-next-auth.session-token={$token}";
        }
        if ($lang) {
            // Blodplay 使用 NEXT_LOCALE
            $cookies[] = "NEXT_LOCALE={$lang}";
        }
        $cookieString = implode('; ', $cookies);
        
        if (empty($cookieString)) {
            $this->warn('⚠️  No authentication cookies found. Trying without cookies...');
            $this->warn('   Please set BLODPLAY_AGENT_TOKEN and BLODPLAY_AGENT_LANG in .env');
        } else {
            $this->info('🔐 Using authentication cookies: ' . (count($cookies)) . ' cookie(s)');
        }

        // 先獲取第一頁以獲取總頁數
        $this->info('📄 Fetching first page to get total pages...');
        $this->info("🔗 API URL: {$apiUrl}");
        
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json',
            'Accept-Language' => 'zh-TW,zh;q=0.9,en;q=0.8',
            'Referer' => $url,
            'Origin' => $baseUrl,
        ];
        
        if (!empty($cookieString)) {
            $headers['Cookie'] = $cookieString;
        }
        
        $queryParams = $this->buildApiQueryParams(1, $commitFrom, $commitTo, $accountNumber);
        
        $firstPageResponse = Http::withHeaders($headers)->get($apiUrl, $queryParams);

        if (!$firstPageResponse->successful()) {
            $this->error('❌ Failed to fetch first page: ' . $firstPageResponse->status());
            $this->error('Response body: ' . $firstPageResponse->body());
            $this->error('Request URL: ' . $apiUrl . '?' . http_build_query($queryParams));
            return null;
        }

        $firstPageData = $firstPageResponse->json();
        $firstPageExtracted = $this->extractApiResults($firstPageData);
        $totalPages = $firstPageExtracted['totalPages'];
        $totalCount = $firstPageExtracted['totalCount'];
        $results = $firstPageExtracted['results'];
        
        // 如果沒有數據，顯示可能的問題
        if ($totalCount == 0 && empty($results)) {
            $this->warn('⚠️  No data returned.');
        }

        // 獲取併發數量
        $concurrency = (int) $this->option('concurrency');
        
        // 收集所有數據
        $allResults = [];
        $allResults[] = $results; // 使用已經提取的 results

        // 併發獲取其他頁面
        if ($totalPages > 1) {
            // 降低併發數以避免超時（最多 5 個）
            $actualConcurrency = min($concurrency, 5);
            
            $pagesToFetch = range(2, $totalPages);
            $chunks = array_chunk($pagesToFetch, $actualConcurrency);
            $failedPages = []; // 記錄失敗的頁面，稍後重試
            
            foreach ($chunks as $chunkIndex => $chunk) {
                // 使用 Http::pool() 的正確方式：傳入回調函數，增加超時時間
                $responses = Http::timeout(60)->pool(function ($pool) use ($chunk, $apiUrl, $headers, $commitFrom, $commitTo, $accountNumber) {
                    $requests = [];
                    foreach ($chunk as $page) {
                        // 在閉包中直接構建查詢參數（避免使用 $this）
                        $pageQueryParams = [
                            'page' => $page,
                            'page_size' => 10,
                            'abnormal' => false,
                        ];
                        
                        if ($commitFrom !== null && $commitTo !== null) {
                            $pageQueryParams['commit_from'] = $commitFrom;
                            $pageQueryParams['commit_to'] = $commitTo;
                        }
                        
                        if ($accountNumber) {
                            $pageQueryParams['player'] = $accountNumber;
                        }
                        
                        $requests[$page] = $pool->as($page)->withHeaders($headers)->timeout(60)->get($apiUrl, $pageQueryParams);
                    }
                    return $requests;
                });
                
                foreach ($responses as $page => $response) {
                    // 檢查是否為異常
                    if ($response instanceof \Exception) {
                        $this->warn("⚠️  Page {$page} failed: " . $response->getMessage());
                        $failedPages[] = $page; // 記錄失敗的頁面
                        continue;
                    }
                    
                    // 檢查是否為 Response 對象且成功
                    if (method_exists($response, 'successful') && $response->successful()) {
                        $pageData = $response->json();
                        $pageExtracted = $this->extractApiResults($pageData);
                        $allResults[] = $pageExtracted['results'];
                    } else {
                        $status = method_exists($response, 'status') ? $response->status() : 'Unknown';
                        $this->warn("⚠️  Page {$page} failed: " . $status);
                        $failedPages[] = $page; // 記錄失敗的頁面
                    }
                }
                
                // 每批之間稍作延遲，避免過於頻繁的請求
                if ($chunkIndex < count($chunks) - 1) {
                    usleep(500000); // 0.5 秒延遲
                }
            }
            
            // 重試失敗的頁面（單個請求，避免超時）
            if (!empty($failedPages)) {
                $this->info("🔄 Retrying " . count($failedPages) . " failed pages...");
                foreach ($failedPages as $page) {
                    $this->info("🔄 Retrying page {$page}...");
                    try {
                        $pageQueryParams = $this->buildApiQueryParams($page, $commitFrom, $commitTo, $accountNumber);
                        $response = Http::withHeaders($headers)->timeout(60)->get($apiUrl, $pageQueryParams);
                        
                        if ($response->successful()) {
                            $pageData = $response->json();
                            $pageExtracted = $this->extractApiResults($pageData);
                            $allResults[] = $pageExtracted['results'];
                            $this->info("✅ Page {$page} retried successfully: " . count($pageExtracted['results']) . " records");
                        } else {
                            $this->warn("⚠️  Page {$page} retry failed: " . $response->status());
                        }
                    } catch (\Exception $e) {
                        $this->warn("⚠️  Page {$page} retry exception: " . $e->getMessage());
                    }
                    
                    // 每次重試之間稍作延遲
                    usleep(300000); // 0.3 秒延遲
                }
            }
        }

        // 合併所有結果
        $allData = array_merge(...$allResults);
        $this->info("📊 Total records fetched: " . count($allData));

        // 轉換為與瀏覽器方式一致的格式
        $convertedData = [];
        foreach ($allData as $item) {
            $row = [];
            foreach ($item as $key => $value) {
                // 轉換鍵名為與 DOM 提取一致的格式
                $cleanKey = str_replace('_', '_', $key);
                $row[$cleanKey] = $value;
            }
            $convertedData[] = $row;
        }

        // 構建返回數據結構（與瀏覽器方式一致）
        return [
            'url' => $url,
            'queryParams' => $queryParams,
            'domData' => [
                'totalPages' => $totalPages,
                'totalRows' => count($allData),
                'headers' => array_keys($allData[0] ?? []),
                'data' => $convertedData,
            ],
        ];
    }
}

