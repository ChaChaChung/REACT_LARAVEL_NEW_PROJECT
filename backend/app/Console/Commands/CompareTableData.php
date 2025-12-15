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

        $this->info("   Date Range: {$dateRange['start']} to {$dateRange['end']}");

        // 3. 調用 API 獲取資料
        $this->info('3. Fetching data from API...');
        $apiData = $this->fetchApiData($gt, $dateRange);

        if (!$apiData) {
            $this->error('❌ Failed to fetch API data');
            return 1;
        }

        $this->info("   API Records: " . count($apiData));

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
        // 方法1: 從 URL 中提取日期
        $url = $domData['pageInfo']['url'] ?? '';
        if (preg_match('/date[=:]([0-9\-]+)/i', $url, $matches)) {
            $dateStr = $matches[1];
            $timestamp = strtotime($dateStr);
            if ($timestamp) {
                return [
                    'start' => $timestamp,
                    'end' => $timestamp + 86400 - 1 // 當天結束時間
                ];
            }
        }

        // 方法2: 從文本內容中提取日期
        $textContent = $domData['textContent'] ?? '';
        
        // 查找日期模式：YYYY-MM-DD 或 YYYY/MM/DD（使用 # 作為分隔符避免與 / 衝突）
        if (preg_match('#(\d{4}[-/]\d{2}[-/]\d{2})#i', $textContent, $matches)) {
            $dateStr = str_replace('/', '-', $matches[1]);
            $timestamp = strtotime($dateStr);
            if ($timestamp) {
                return [
                    'start' => $timestamp,
                    'end' => $timestamp + 86400 - 1
                ];
            }
        }

        // 方法3: 從表格資料中提取日期（查找時間欄位）
        $tables = $domData['tables'] ?? [];
        foreach ($tables as $table) {
            $data = $table['data'] ?? [];
            foreach ($data as $row) {
                // 查找包含時間的欄位
                foreach ($row as $key => $value) {
                    if (preg_match('/time|時間|date|日期/i', $key) && !empty($value)) {
                        // 嘗試解析日期時間
                        $timestamp = strtotime($value);
                        if ($timestamp) {
                            // 使用當天的開始和結束時間
                            $startOfDay = strtotime(date('Y-m-d 00:00:00', $timestamp));
                            $endOfDay = strtotime(date('Y-m-d 23:59:59', $timestamp));
                            return [
                                'start' => $startOfDay,
                                'end' => $endOfDay
                            ];
                        }
                    }
                }
            }
        }

        return null;
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
            $controller = new FGController();
            
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
                $response = $controller->logByPageTotalBets($request);
                $result = json_decode($response->getContent(), true);
                
                // 調試：顯示完整響應（僅第一次）
                // if ($pageKey === null) {
                //     $this->line('   API Response: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
                // }
                
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
                        $this->line("   Fetched page: " . count($currentData['data']) . " records (Total: " . count($allData) . ")");
                    } else {
                        $this->warn('   Warning: No data in API response data');
                        $this->line('   Response data structure: ' . json_encode(array_keys($currentData), JSON_UNESCAPED_UNICODE));
                    }

                    // 檢查是否有下一頁
                    $pageKey = $currentData['page_key'] ?? null;
                } else {
                    // 顯示詳細錯誤信息（僅在 code 非 0 且確實存在時）
                    if ($code !== null && $code !== 0) {
                        $this->error('   API returned code: ' . $result['code']);
                    }
                    if (isset($result['message'])) {
                        $this->error('   API message: ' . $result['message']);
                    }
                    if (isset($result['error'])) {
                        $this->error('   API error: ' . $result['error']);
                    }
                    if (!isset($result['data'])) {
                        $this->error('   No data in API response');
                    }
                    $this->line('   Full response: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    break;
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
            $data = $table['data'] ?? [];
            $totalBets = 0;
            foreach ($data as $row) {
                // 提取關鍵欄位：玩家ID、有效投注、輸贏
                $playerAccount = $row['player_account']?? null;
                $totalBets += $row['place_a_bet']?? 0;
                $validBet = $this->extractValidBet($row);
                $winLoss = $this->extractWinLoss($row);
                
                if ($playerAccount) {
                    $webpageRecords[$playerAccount] = [
                        'player_account' => $playerAccount,
                        'total_bets' => $totalBets,
                        'valid_bet' => $validBet,
                        'win_loss' => $winLoss,
                        'raw_data' => $row,
                    ];
                }
            }
        }
        $this->line('   webpageRecords: ' . json_encode($webpageRecords, JSON_UNESCAPED_UNICODE));

        // 建立 API 資料索引（以 player_id 為 key）
        $apiRecords = [];
        $totalBets = 0;
        foreach ($apiData as $record) {
            $player_name = $record['player_name']?? null;
            if ($player_name) {
                $totalBets += $record['total_bets']?? 0;
                $apiRecords[$player_name] = [
                    'player_account' => $player_name,
                    'total_bets' => $totalBets,
                    'valid_bet' => $record['valid_bet'] ?? $record['validBet'] ?? $record['total_bets'] ?? 0,
                    'win_loss' => $record['win_loss'] ?? $record['winLoss'] ?? $record['payout'] ?? 0,
                    'raw_data' => $record,
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
                $validBetMatch = abs($webpageRecord['valid_bet'] - $apiRecord['valid_bet']) < 0.01;
                $winLossMatch = abs($webpageRecord['win_loss'] - $apiRecord['win_loss']) < 0.01;
                
                if ($validBetMatch && $winLossMatch) {
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
                            'valid_bet' => [
                                'webpage' => $webpageRecord['valid_bet'],
                                'api' => $apiRecord['valid_bet'],
                                'diff' => $webpageRecord['valid_bet'] - $apiRecord['valid_bet'],
                            ],
                            'win_loss' => [
                                'webpage' => $webpageRecord['win_loss'],
                                'api' => $apiRecord['win_loss'],
                                'diff' => $webpageRecord['win_loss'] - $apiRecord['win_loss'],
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
     * 從表格行中提取玩家ID
     * @param array $row 表格行資料
     * @return string|null
     */
    private function extractPlayerId($row)
    {
        // 嘗試多種可能的欄位名稱
        $possibleKeys = ['playerID', 'player_id', '玩家ID', '玩家账号', 'player account', 'playerAccount'];
        
        foreach ($possibleKeys as $key) {
            if (isset($row[$key]) && !empty($row[$key])) {
                return (string)$row[$key];
            }
        }
        
        return null;
    }

    /**
     * 從表格行中提取有效投注
     * @param array $row 表格行資料
     * @return float
     */
    private function extractValidBet($row)
    {
        $possibleKeys = ['有效打码', 'Valid coding', 'valid_bet', 'validBet', 'total_bets', 'totalBets'];
        
        foreach ($possibleKeys as $key) {
            if (isset($row[$key]) && !empty($row[$key])) {
                return (float)str_replace(',', '', $row[$key]);
            }
        }
        
        return 0.0;
    }

    /**
     * 從表格行中提取輸贏
     * @param array $row 表格行資料
     * @return float
     */
    private function extractWinLoss($row)
    {
        $possibleKeys = ['收支', 'income and expenditure', 'win_loss', 'winLoss', 'payout', 'profit'];
        
        foreach ($possibleKeys as $key) {
            if (isset($row[$key]) && !empty($row[$key])) {
                return (float)str_replace(',', '', $row[$key]);
            }
        }
        
        return 0.0;
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
        $this->info("   📄 Webpage Only: " . count($result['webpage_only']));
        $this->info("   🔌 API Only: " . count($result['api_only']));

        if (!empty($result['mismatched'])) {
            $this->line("");
            $this->warn("⚠️  Mismatched Records:");
            foreach (array_slice($result['mismatched'], 0, 10) as $mismatch) {
                $this->line("   Player ID: " . $mismatch['player_id']);
                $this->line("      Valid Bet - Webpage: {$mismatch['differences']['valid_bet']['webpage']}, API: {$mismatch['differences']['valid_bet']['api']}, Diff: {$mismatch['differences']['valid_bet']['diff']}");
                $this->line("      Win/Loss - Webpage: {$mismatch['differences']['win_loss']['webpage']}, API: {$mismatch['differences']['win_loss']['api']}, Diff: {$mismatch['differences']['win_loss']['diff']}");
            }
            if (count($result['mismatched']) > 10) {
                $this->line("   ... and " . (count($result['mismatched']) - 10) . " more");
            }
        }
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
                'start' => date('Y-m-d H:i:s', $dateRange['start']),
                'end' => date('Y-m-d H:i:s', $dateRange['end']),
            ],
            'summary' => $result['summary'],
            'matched' => $result['matched'],
            'mismatched' => $result['mismatched'],
            'webpage_only' => $result['webpage_only'],
            'api_only' => $result['api_only'],
        ];

        Storage::put("scraped_data/{$filename}", json_encode($saveData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("💾 Comparison result saved to: scraped_data/{$filename}");
    }
}

