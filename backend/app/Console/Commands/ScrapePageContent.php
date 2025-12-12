<?php

namespace App\Console\Commands;

use App\Services\AgentLoginService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use DOMDocument;
use DOMXPath;

class ScrapePageContent extends Command
{
    protected $signature = 'agent:scrape-content {endpoint?} {--selector=} {--output=json} {--wait-js}';
    protected $description = 'Scrape content directly from HTML (works best with server-rendered content)';

    public function handle(AgentLoginService $agentService)
    {
        $endpoint = $this->argument('endpoint') ?? '/';
        $selector = $this->option('selector');
        $output = $this->option('output');
        $waitJs = $this->option('wait-js');
        
        $this->info('=== HTML Content Scraper ===');
        $this->info("Target endpoint: {$endpoint}");
        
        // 1. 檢查登入狀態
        if (!$agentService->checkLoginStatus()) {
            $this->error('❌ Authentication failed. Please check your cookies.');
            return 1;
        }
        
        $this->info('✅ Authentication successful');
        
        // 2. 如果需要等待 JavaScript，建議使用瀏覽器自動化
        if ($waitJs) {
            $this->warn('⚠️  JavaScript rendering requested. Consider using:');
            $this->line('   php artisan agent:scrape-browser');
            $this->line('   This method works best with server-rendered content.');
            $this->line('');
            
            if (!$this->confirm('Continue with HTML-only scraping?')) {
                return 0;
            }
        }
        
        // 3. 獲取頁面內容
        $this->info('2. Fetching page content...');
        
        try {
            $response = $agentService->getPageContent($endpoint);
            
            if (!$response['success']) {
                $this->error("❌ Failed to fetch page: HTTP {$response['status_code']}");
                return 1;
            }
            
            $html = $response['body'];
            $this->info("✅ Page loaded (size: " . $this->formatBytes(strlen($html)) . ")");
            
        } catch (\Exception $e) {
            $this->error("❌ Error fetching page: " . $e->getMessage());
            return 1;
        }
        
        // 4. 分析和提取內容
        $this->info('3. Analyzing HTML content...');
        
        $extractedData = $this->extractDataFromHtml($html, $selector);
        
        if (empty($extractedData['tables']) && empty($extractedData['lists']) && empty($extractedData['forms'])) {
            $this->warn('⚠️  No structured data found in HTML');
            $this->line('This might indicate:');
            $this->line('  • Content is loaded by JavaScript (use --wait-js or browser automation)');
            $this->line('  • Wrong endpoint (try different URLs)');
            $this->line('  • Authentication issues');
            
            $this->analyzePageType($html);
        }
        
        // 5. 保存結果
        $this->saveExtractedData($extractedData, $endpoint, $output);
        
        return 0;
    }
    
    private function extractDataFromHtml($html, $selector = null)
    {
        $this->info('   Parsing HTML structure...');
        
        // 創建 DOM 解析器
        $dom = new DOMDocument();
        
        // 禁用錯誤報告（HTML5 標籤可能會產生警告）
        libxml_use_internal_errors(true);
        
        // 載入 HTML
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $xpath = new DOMXPath($dom);
        
        $extractedData = [
            'tables' => [],
            'lists' => [],
            'forms' => [],
            'divs_with_data' => [],
            'custom_selector' => null
        ];
        
        // 如果指定了選擇器，嘗試提取該元素
        if ($selector) {
            $extractedData['custom_selector'] = $this->extractBySelector($xpath, $selector);
        }
        
        // 提取表格數據
        $extractedData['tables'] = $this->extractTables($xpath);
        $this->info("   📋 Tables found: " . count($extractedData['tables']));
        
        // 提取列表數據
        $extractedData['lists'] = $this->extractLists($xpath);
        $this->info("   📝 Lists found: " . count($extractedData['lists']));
        
        // 提取表單數據
        $extractedData['forms'] = $this->extractForms($xpath);
        $this->info("   📄 Forms found: " . count($extractedData['forms']));
        
        // 提取可能包含數據的 div 元素
        $extractedData['divs_with_data'] = $this->extractDataDivs($xpath);
        $this->info("   📦 Data divs found: " . count($extractedData['divs_with_data']));
        
        return $extractedData;
    }
    
    private function extractBySelector($xpath, $selector)
    {
        try {
            // 轉換 CSS 選擇器為 XPath（簡單版本）
            $xpathSelector = $this->cssToXpath($selector);
            $nodes = $xpath->query($xpathSelector);
            
            $results = [];
            foreach ($nodes as $node) {
                $results[] = [
                    'tag' => $node->nodeName,
                    'text' => trim($node->textContent),
                    'html' => $node->ownerDocument->saveHTML($node)
                ];
            }
            
            return $results;
        } catch (\Exception $e) {
            $this->warn("   ⚠️  Selector extraction failed: " . $e->getMessage());
            return null;
        }
    }
    
    private function extractTables($xpath)
    {
        $tables = [];
        $tableNodes = $xpath->query('//table');
        
        foreach ($tableNodes as $table) {
            $headers = [];
            $rows = [];
            
            // 提取表頭
            $headerNodes = $xpath->query('.//thead//th | .//tr[1]//td | .//tr[1]//th', $table);
            foreach ($headerNodes as $header) {
                $headers[] = trim($header->textContent);
            }
            
            // 提取數據行
            $rowNodes = $xpath->query('.//tbody//tr | .//tr[position()>1]', $table);
            foreach ($rowNodes as $row) {
                $cellNodes = $xpath->query('.//td | .//th', $row);
                $rowData = [];
                foreach ($cellNodes as $cell) {
                    $rowData[] = trim($cell->textContent);
                }
                if (!empty($rowData)) {
                    $rows[] = $rowData;
                }
            }
            
            if (!empty($headers) || !empty($rows)) {
                $tables[] = [
                    'headers' => $headers,
                    'rows' => $rows,
                    'total_rows' => count($rows)
                ];
            }
        }
        
        return $tables;
    }
    
    private function extractLists($xpath)
    {
        $lists = [];
        $listNodes = $xpath->query('//ul | //ol');
        
        foreach ($listNodes as $list) {
            $items = [];
            $itemNodes = $xpath->query('.//li', $list);
            
            foreach ($itemNodes as $item) {
                $text = trim($item->textContent);
                if (!empty($text) && strlen($text) > 2) {
                    $items[] = $text;
                }
            }
            
            if (count($items) >= 2) { // 至少要有 2 個項目才算有效列表
                $lists[] = [
                    'type' => $list->nodeName,
                    'items' => $items,
                    'count' => count($items)
                ];
            }
        }
        
        return $lists;
    }
    
    private function extractForms($xpath)
    {
        $forms = [];
        $formNodes = $xpath->query('//form');
        
        foreach ($formNodes as $form) {
            $fields = [];
            $inputNodes = $xpath->query('.//input | .//select | .//textarea', $form);
            
            foreach ($inputNodes as $input) {
                $name = $input->getAttribute('name');
                $type = $input->getAttribute('type') ?: $input->nodeName;
                $value = $input->getAttribute('value');
                
                if (!empty($name)) {
                    $fields[] = [
                        'name' => $name,
                        'type' => $type,
                        'value' => $value
                    ];
                }
            }
            
            if (!empty($fields)) {
                $forms[] = [
                    'action' => $form->getAttribute('action'),
                    'method' => $form->getAttribute('method') ?: 'GET',
                    'fields' => $fields
                ];
            }
        }
        
        return $forms;
    }
    
    private function extractDataDivs($xpath)
    {
        $dataDivs = [];
        
        // 尋找可能包含數據的 div（基於 class 名稱和內容）
        $divNodes = $xpath->query('//div[contains(@class, "record") or contains(@class, "data") or contains(@class, "table") or contains(@class, "list") or contains(@class, "item")]');
        
        foreach ($divNodes as $div) {
            $text = trim($div->textContent);
            $class = $div->getAttribute('class');
            $id = $div->getAttribute('id');
            
            // 過濾掉太短或太長的內容
            if (strlen($text) > 10 && strlen($text) < 2000) {
                $dataDivs[] = [
                    'class' => $class,
                    'id' => $id,
                    'text' => substr($text, 0, 300) . (strlen($text) > 300 ? '...' : ''),
                    'length' => strlen($text)
                ];
            }
        }
        
        // 限制數量以避免過多數據
        return array_slice($dataDivs, 0, 20);
    }
    
    private function cssToXpath($cssSelector)
    {
        // 非常簡單的 CSS 到 XPath 轉換
        $selector = trim($cssSelector);
        
        // 處理 ID 選擇器
        if (strpos($selector, '#') === 0) {
            return "//*[@id='" . substr($selector, 1) . "']";
        }
        
        // 處理 class 選擇器
        if (strpos($selector, '.') === 0) {
            return "//*[contains(@class, '" . substr($selector, 1) . "')]";
        }
        
        // 處理標籤選擇器
        if (preg_match('/^[a-zA-Z]+$/', $selector)) {
            return "//" . $selector;
        }
        
        // 默認返回原始選擇器作為 XPath
        return "//" . $selector;
    }
    
    private function analyzePageType($html)
    {
        $this->info('   📊 Page Analysis:');
        
        // 檢查是否為 SPA 應用
        $isSpa = strpos($html, 'app') !== false || 
                 strpos($html, 'vue') !== false || 
                 strpos($html, 'react') !== false || 
                 strpos($html, 'angular') !== false;
                 
        if ($isSpa) {
            $this->warn('   ⚠️  Detected Single Page Application (SPA)');
            $this->line('   💡 Recommendation: Use browser automation instead');
            $this->line('       php artisan agent:scrape-browser');
        }
        
        // 檢查 JavaScript 文件
        $jsCount = substr_count($html, '<script');
        $this->info("   📜 JavaScript files found: {$jsCount}");
        
        // 檢查是否有 loading 或 placeholder 元素
        $hasLoading = strpos($html, 'loading') !== false || 
                      strpos($html, 'Loading') !== false ||
                      strpos($html, 'loader') !== false;
                      
        if ($hasLoading) {
            $this->warn('   ⚠️  Found loading indicators - content might be dynamic');
        }
        
        // 檢查頁面大小
        $size = strlen($html);
        if ($size < 1000) {
            $this->warn("   ⚠️  Page very small ({$this->formatBytes($size)}) - might be a shell page");
        }
    }
    
    private function saveExtractedData($data, $endpoint, $format)
    {
        $this->info('4. Saving extracted data...');
        
        $timestamp = date('Y-m-d_H-i-s');
        $safeEndpoint = str_replace(['/', '\\', '?', '*'], '_', $endpoint);
        
        // 保存完整數據為 JSON
        $jsonPath = storage_path("app/scraped_data/html_scrape_{$safeEndpoint}_{$timestamp}.json");
        
        // 確保目錄存在
        $directory = dirname($jsonPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("💾 Full data saved: {$jsonPath}");
        
        // 如果有表格數據且要求 CSV 格式
        if ($format === 'csv' && !empty($data['tables'])) {
            foreach ($data['tables'] as $index => $table) {
                $csvPath = storage_path("app/scraped_data/table_{$index}_{$safeEndpoint}_{$timestamp}.csv");
                $this->saveTableAsCsv($table, $csvPath);
            }
        }
        
        // 顯示摘要
        $this->info("📊 Extraction Summary:");
        $this->info("   📋 Tables: " . count($data['tables']));
        $this->info("   📝 Lists: " . count($data['lists']));
        $this->info("   📄 Forms: " . count($data['forms']));
        $this->info("   📦 Data divs: " . count($data['divs_with_data']));
        
        if ($data['custom_selector']) {
            $this->info("   🎯 Custom selector matches: " . count($data['custom_selector']));
        }
    }
    
    private function saveTableAsCsv($table, $filepath)
    {
        $fp = fopen($filepath, 'w');
        
        // 寫入 UTF-8 BOM
        fwrite($fp, "\xEF\xBB\xBF");
        
        // 寫入表頭
        if (!empty($table['headers'])) {
            fputcsv($fp, $table['headers']);
        }
        
        // 寫入數據行
        foreach ($table['rows'] as $row) {
            fputcsv($fp, $row);
        }
        
        fclose($fp);
        
        $this->info("📊 Table CSV saved: " . basename($filepath));
    }
    
    private function formatBytes($size)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
}