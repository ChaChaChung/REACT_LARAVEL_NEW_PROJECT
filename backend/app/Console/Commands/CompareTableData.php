<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\FGController;
use Illuminate\Http\Request;

/**
 * 比對網頁表格資料與 API 資料
 * 逐一比對會員的有效投注和輸贏
 */
class CompareTableData extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:compare-table {url} {--gt=chess}
     * {url} - 要爬取的目標網址（必需參數）
     * {--gt=} - 遊戲類型（chess/hunter/slot/arcade），預設為 chess
     */
    protected $signature = 'agent:compare-table {url} {--gt=chess}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Compare table data from webpage with API data (logByPageTotalBets)';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        $url = $this->argument('url');
        $gt = $this->option('gt');

        $this->info('=== Table Data Comparison ===');
        $this->info("Target URL: {$url}");
        $this->info("Game Type: {$gt}");

        // 1. 爬取網頁資料
        $this->info('1. Scraping webpage data...');
        $scrapeCommand = new ScrapeBrowserDOM();
        $scrapeCommand->setOutput($this->output);
        
        // 執行爬取
        $scrapeResult = $this->scrapeWebPage($url);
        
        if (!$scrapeResult || !$scrapeResult['success']) {
            $this->error('❌ Failed to scrape webpage');
            return 1;
        }

        $domData = $scrapeResult['domData'] ?? [];
        $tables = $domData['tables'] ?? [];

        if (empty($tables)) {
            $this->error('❌ No tables found in webpage');
            return 1;
        }

        // 2. 提取日期資訊
        $this->info('2. Extracting date information...');
        $dateRange = $this->extractDateRange($domData);
        
        if (!$dateRange) {
            $this->error('❌ Could not extract date range from webpage');
            $this->warn('Please ensure the webpage contains date information');
            return 1;
        }

        // 3. 調用 API 獲取資料
        $this->info('3. Fetching data from API...');
        $apiData = $this->fetchApiData($gt, $dateRange);

        if (!$apiData) {
            $this->error('❌ Failed to fetch API data');
            return 1;
        }

        // 4. 比對資料
        $this->info('4. Comparing data...');
        $comparisonResult = $this->compareData($tables, $apiData);

        // 5. 顯示結果
        $this->displayComparisonResult($comparisonResult);

        // 6. 保存結果
        $this->saveComparisonResult($comparisonResult, $dateRange);

        return 0;
    }

    /**
     * 爬取網頁資料
     * @param string $url 目標網址
     * @return array|null
     */
    private function scrapeWebPage($url)
    {
        // 使用 Artisan 調用 ScrapeBrowserDOM 命令
        $exitCode = Artisan::call('agent:scrape-dom', [
            'url' => $url
        ]);
        
        if ($exitCode !== 0) {
            return null;
        }

        // 讀取最新的爬取結果
        $files = glob(storage_path('app/scraped_data/dom_scrape_full_*.json'));
        if (empty($files)) {
            return null;
        }

        // 獲取最新的文件
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $latestFile = $files[0];
        $content = file_get_contents($latestFile);
        return json_decode($content, true);
    }

    /**
     * 從網頁資料中提取日期範圍
     * @param array $domData DOM 資料
     * @return array|null 返回 ['start' => timestamp, 'end' => timestamp] 或 null
     */
    private function extractDateRange($domData)
    {
        // 方法1: 從表單中提取日期（優先）
        $formDates = $domData['formDates'] ?? [];
        
        // 查找開始和結束日期
        $startDate = null;
        $endDate = null;
        
        // 優先查找 start_time 和 end_time（來自 placeholder 為 "Start time" 和 "End time" 的 input）
        if (isset($formDates['start_time']) && !empty($formDates['start_time'])) {
            $startDate = $formDates['start_time'];
        }
        if (isset($formDates['end_time']) && !empty($formDates['end_time'])) {
            $endDate = $formDates['end_time'];
        }
        
        // 如果沒有找到 start_time/end_time，則查找其他包含 start/end 的鍵
        if (!$startDate || !$endDate) {
            foreach ($formDates as $key => $value) {
                $keyLower = strtolower($key);
                if (!$startDate && $keyLower === 'start_date' && !empty($value)) {
                    $startDate = $value;
                }
                if (!$endDate && $keyLower === 'end_date' && !empty($value)) {
                    $endDate = $value;
                }
            }
        }
        // 如果找到日期範圍
        if ($startDate || $endDate) {
            // 如果只有一個日期，使用同一天
            if (!$startDate && $endDate) {
                $startDate = $endDate;
            }
            if (!$endDate && $startDate) {
                $endDate = $startDate;
            }
            
            if ($startDate && $endDate) {
                // 假設表單中的日期是美東時間，轉換為台北時間再轉 UTC
                return $this->convertDateRangeEasternToUTC($startDate, $endDate);
            }
        }

        return null;
    }

    /**
     * 將美東時間的日期範圍轉換為 UTC 時間戳
     * @param string $startDate 開始日期 (格式: YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS)
     * @param string $endDate 結束日期 (格式: YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS)
     * @return array|null 返回 ['start' => timestamp, 'end' => timestamp] 或 null
     */
    private function convertDateRangeEasternToUTC($startDate, $endDate)
    {
        try {
            $easternTz = new \DateTimeZone('America/New_York');
            $taipeiTz = new \DateTimeZone('Asia/Taipei');
            $utcTz = new \DateTimeZone('UTC');
            
            // 檢查日期字符串是否已經包含時間部分
            // 如果已經包含時間（格式：YYYY-MM-DD HH:MM:SS），直接使用
            // 如果只有日期（格式：YYYY-MM-DD），添加默認時間
            $startDateTimeStr = $startDate;
            if (!preg_match('/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $startDate)) {
                // 只有日期，添加默認開始時間 00:00:00
                $startDateTimeStr = $startDate . ' 00:00:00';
            }
            
            $endDateTimeStr = $endDate;
            if (!preg_match('/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $endDate)) {
                // 只有日期，添加默認結束時間 23:59:59
                $endDateTimeStr = $endDate . ' 23:59:59';
            }
            
            // 開始日期（美東時間 -> 台北時間 -> UTC）
            $startEastern = new \DateTime($startDateTimeStr, $easternTz);
            $startEastern->setTimezone($taipeiTz);
            $startTaipei = $startEastern->format('Y-m-d H:i:s');
            $startEastern->setTimezone($utcTz);
            
            // 結束日期（美東時間 -> 台北時間 -> UTC）
            $endEastern = new \DateTime($endDateTimeStr, $easternTz);
            $endEastern->setTimezone($taipeiTz);
            $endTaipei = $endEastern->format('Y-m-d H:i:s');
            $endEastern->setTimezone($utcTz);

            return [
                'start' => $startEastern->getTimestamp(),
                'end' => $endEastern->getTimestamp(),
                'start_taipei' => $startTaipei,
                'end_taipei' => $endTaipei,
            ];
        } catch (\Exception $e) {
            $this->warn('Failed to convert date range: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 調用 API 獲取資料
     * @param string $gt 遊戲類型
     * @param array $dateRange 日期範圍
     * @return array|null
     */
    private function fetchApiData($gt, $dateRange)
    {
        try {            
            // 創建請求物件
            $request = Request::create('/api/fg/logByPageTotalBets', 'POST', [
                'gt' => $gt,
                'start_time' => $dateRange['start'],
                'end_time' => $dateRange['end'],
            ]);

            // 處理分頁資料
            $allData = [];
            $pageKey = null;
            
            do {
                // 如果有 page_key，添加到請求中
                if ($pageKey) {
                    $request = Request::create('/api/fg/logByPageTotalBets', 'POST', [
                        'gt' => $gt,
                        'page_key' => $pageKey,
                        'start_time' => $dateRange['start'],
                        'end_time' => $dateRange['end'],
                    ]);
                }
                
                // 調用控制器方法
                $response = app(FGController::class)->logByPageTotalBets($request);
                $result = json_decode($response->getContent(), true);

                // 解析 code，將可轉換的數值視為整數比較
                $code = null;
                if (isset($result['code'])) {
                    // 支援字串或數值，去除空白後轉 int
                    $code = (int)trim((string)$result['code']);
                }

                // code == 0 才算成功；若 data 缺失則提示，但不當作 code 錯誤
                if ($code === 0) {
                    $currentData = $result['data'] ?? [];

                    // 提取列表資料
                    if (isset($currentData['data']) && is_array($currentData['data'])) {
                        $allData = array_merge($allData, $currentData['data']);
                    }

                    // 檢查是否有下一頁
                    $pageKey = $currentData['page_key'] ?? null;
                }
            } while (!empty($pageKey) && $pageKey !== 'none');
            
            if (empty($allData)) {
                $this->warn('   No data returned from API');
                return null;
            }
            
            return $allData;
        } catch (\Exception $e) {
            $this->error('API request exception: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 比對網頁資料和 API 資料
     * @param array $tables 網頁表格資料
     * @param array $apiData API 資料
     * @return array 比對結果
     */
    private function compareData($tables, $apiData)
    {
        $results = [
            'matched' => [],
            'mismatched' => [],
            'webpage_only' => [],
            'api_only' => [],
            'summary' => [
                'total_webpage' => 0,
                'total_api' => 0,
                'matched_count' => 0,
                'mismatched_count' => 0,
            ]
        ];

        // 從網頁表格中提取資料
        $webpageRecords = [];
        foreach ($tables as $table) {
            // 總下注金額
            $totalBets = 0;
            // 有效投注金額
            $allBets = 0;
            // 輸贏金額
            $allAwards = 0;
            foreach ($table['data'] as $row) {
                // 提取關鍵欄位：總下注金額、有效投注、輸贏
                $playerAccount = $row['player_account']?? null;
                if ($playerAccount) {
                    $totalBets += $row['place_a_bet']?? 0;
                    $allBets += $row['Valid_coding']?? 0;
                    $allAwards += $row['bonus']?? 0;
                    $webpageRecords[$playerAccount] = [
                        'player_account' => $playerAccount,
                        'total_bets' => $totalBets,
                        'all_bets' => $allBets,
                        'all_awards' => $allAwards
                    ];
                }
            }
        }
        $this->line('   webpageRecords: ' . json_encode($webpageRecords, JSON_UNESCAPED_UNICODE));

        // 建立 API 資料索引（以 player_id 為 key）
        $apiRecords = [];
        // 總下注金額
        $totalBets = 0;
        // 有效投注金額
        $allBets = 0;
        // 輸贏金額
        $allAwards = 0;
        foreach ($apiData as $record) {
            // 提取關鍵欄位：總下注金額、有效投注、輸贏
            $player_name = $record['player_name']?? null;
            if ($player_name) {
                $totalBets += $record['total_bets']?? 0;
                $allBets += $record['all_bets']?? 0;
                $allAwards += $record['all_awards']?? 0;
                $apiRecords[$player_name] = [
                    'player_account' => $player_name,
                    'total_bets' => $totalBets,
                    'all_bets' => $allBets,
                    'all_awards' => $allAwards
                ];
            }
        }
        $this->line('   apiRecords: ' . json_encode($apiRecords, JSON_UNESCAPED_UNICODE));

        $results['summary']['total_webpage'] = count($webpageRecords);
        $results['summary']['total_api'] = count($apiRecords);

        // 比對資料
        foreach ($webpageRecords as $playerAccount => $webpageRecord) {
            if (isset($apiRecords[$playerAccount])) {
                $apiRecord = $apiRecords[$playerAccount];
                
                // 比對有效投注和輸贏（允許小數點誤差）
                $validBetMatch = abs($webpageRecord['all_bets'] - $apiRecord['all_bets']) < 0.01;
                $allAwardsMatch = abs($webpageRecord['all_awards'] - $apiRecord['all_awards']) < 0.01;
                
                if ($validBetMatch && $allAwardsMatch) {
                    $results['matched'][] = [
                        'player_account' => $playerAccount,
                        'webpage' => $webpageRecord,
                        'api' => $apiRecord,
                    ];
                    $results['summary']['matched_count']++;
                } else {
                    $results['mismatched'][] = [
                        'player_id' => $playerAccount,
                        'webpage' => $webpageRecord,
                        'api' => $apiRecord,
                        'differences' => [
                            'total_bets' => [
                                'webpage' => $webpageRecord['total_bets'],
                                'api' => $apiRecord['total_bets'],
                                'diff' => $webpageRecord['total_bets'] - $apiRecord['total_bets'],
                            ],
                            'all_bets' => [
                                'webpage' => $webpageRecord['all_bets'],
                                'api' => $apiRecord['all_bets'],
                                'diff' => $webpageRecord['all_bets'] - $apiRecord['all_bets'],
                            ],
                            'all_awards' => [
                                'webpage' => $webpageRecord['all_awards'],
                                'api' => $apiRecord['all_awards'],
                                'diff' => $webpageRecord['all_awards'] - $apiRecord['all_awards'],
                            ],
                        ],
                    ];
                    $results['summary']['mismatched_count']++;
                }
                
                // 從 API 記錄中移除已比對的
                unset($apiRecords[$playerAccount]);
            } else {
                $results['webpage_only'][] = $webpageRecord;
            }
        }

        // 剩餘的 API 記錄
        $results['api_only'] = array_values($apiRecords);

        return $results;
    }

    /**
     * 顯示比對結果
     * @param array $result 比對結果
     */
    private function displayComparisonResult($result)
    {
        $this->line("");
        $this->info("📊 Comparison Results:");
        $this->info("   Webpage Records: " . $result['summary']['total_webpage']);
        $this->info("   API Records: " . $result['summary']['total_api']);
        $this->info("   ✅ Matched: " . $result['summary']['matched_count']);
        $this->info("   ❌ Mismatched: " . $result['summary']['mismatched_count']);
    }

    /**
     * 保存比對結果
     * @param array $result 比對結果
     * @param array $dateRange 日期範圍
     */
    private function saveComparisonResult($result, $dateRange)
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "comparison_result_{$timestamp}.json";
        
        $saveData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'date_range' => [
                'start_timestamp' => $dateRange['start'],
                'end_timestamp' => $dateRange['end'],
                'start_utc' => isset($dateRange['start_utc']) ? $dateRange['start_utc'] : date('Y-m-d H:i:s', $dateRange['start']),
                'end_utc' => isset($dateRange['end_utc']) ? $dateRange['end_utc'] : date('Y-m-d H:i:s', $dateRange['end']),
            ],
        ];
        
        // 如果有原始美東時間，也保存
        if (isset($dateRange['original_eastern'])) {
            $saveData['date_range']['original_eastern'] = $dateRange['original_eastern'];
        }
        
        $saveData = array_merge($saveData, [
            'summary' => $result['summary'],
            'matched' => $result['matched'],
            'mismatched' => $result['mismatched'],
            'webpage_only' => $result['webpage_only'],
            'api_only' => $result['api_only'],
        ]);

        Storage::put("scraped_data/{$filename}", json_encode($saveData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("💾 Comparison result saved to: scraped_data/{$filename}");
    }
}

