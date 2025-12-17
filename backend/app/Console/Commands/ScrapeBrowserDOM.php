<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserDOM extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-dom {url}
     * {url} - 要爬取的目標網址（必需參數）
     */
    protected $signature = 'agent:scrape-dom {url}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from DOM elements using browser automation';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');

        $this->info('=== Browser DOM Scraper ===');
        $this->info("Target URL: {$url}");

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url);

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
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取認證 cookies 程式碼片段
        $cookiesCode = $this->generatePuppeteerCookiesCode();

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取資料的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容
             */
            async function scrapeDOMContent() {
                console.log('🚀 Starting browser automation for DOM scraping...');

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

                    $cookiesCode

                    // 監聽瀏覽器控制台的錯誤訊息
                    // 這有助於調試頁面載入問題
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            console.log('❌ Browser console error:', msg.text());
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

                    console.log('📄 Extracting DOM content...');

                    // 使用 page.evaluate() 在瀏覽器環境中執行 JavaScript 來提取 DOM 資料
                    const domData = await page.evaluate(() => {
                        // 先處理表格資料
                        const allTables = Array.from(document.querySelectorAll('table'));
                        // 存儲每個表格的表頭
                        const tableHeaders = {};
                        
                        // 第一遍：識別表頭表格（通常包含 th 標籤或 class 包含 header）
                        allTables.forEach((table, index) => {
                            // 獲取表格的所有行
                            const rows = Array.from(table.querySelectorAll('tr'));
                            // 獲取表格的 class 屬性
                            const tableClass = table.className || '';
                            // 檢查表格是否包含表頭
                            const isHeaderTable = tableClass.includes('header') || 
                                                tableClass.includes('Header') ||
                                                rows.some(row => row.querySelectorAll('th').length > 0);
                            // 如果表格包含表頭，則提取表頭
                            if (isHeaderTable && rows.length > 0) {
                                // 提取表頭
                                const headerRow = rows[0];
                                const headerCells = headerRow.querySelectorAll('th, td');
                                if (headerCells.length > 0) {
                                    const headers = Array.from(headerCells).map(cell => cell.textContent.trim());
                                    // 將表頭存儲，供後續表格使用
                                    tableHeaders[index] = headers;
                                }
                            }
                        });
                        
                        // 處理表格的函數
                        function processTable(table, tableIndex) {
                            // 獲取表格的所有行
                            const rows = Array.from(table.querySelectorAll('tr'));
                            // 獲取表格的 class 屬性
                            const tableClass = table.className || '';
                            // 檢查表格是否包含表頭
                            const isHeaderTable = tableClass.includes('header') || 
                                                tableClass.includes('Header') ||
                                                rows.some(row => row.querySelectorAll('th').length > 0);
                            // 初始化表頭行和資料起始索引
                            let headerRow = null;
                            let dataStartIndex = 0;
                            // 如果是表頭表格，只提取表頭，不提取資料
                            if (isHeaderTable) {
                                // 如果表格的第一行存在，則獲取第一行的所有單元格
                                if (rows[0]) {
                                    // 獲取第一行的所有單元格
                                    const headerCells = rows[0].querySelectorAll('th, td');
                                    headerRow = Array.from(headerCells).map((cell, idx) => {
                                        const text = cell.textContent.trim();
                                        return text || 'column_' + idx;
                                    });
                                }
                                // 表頭表格通常沒有資料行
                                dataStartIndex = rows.length;
                            } else {
                                // 資料表格：嘗試找到對應的表頭
                                // 1. 檢查前面的表格是否有表頭
                                let foundHeader = null;
                                for (let i = tableIndex - 1; i >= 0; i--) {
                                    if (tableHeaders[i]) {
                                        foundHeader = tableHeaders[i];
                                        break;
                                    }
                                }
                                
                                // 2. 如果找到表頭，使用它
                                if (foundHeader) {
                                    // 使用找到的表頭
                                    headerRow = foundHeader;
                                    // 所有行都是資料
                                    dataStartIndex = 0;
                                } else {
                                    // 3. 否則檢查第一行是否包含 th（標準表頭）
                                    if (rows[0]) {
                                        // 獲取第一行的所有單元格
                                        const firstRowCells = rows[0].querySelectorAll('th, td');
                                        // 檢查第一行是否包含 th 標籤
                                        const hasTh = rows[0].querySelectorAll('th').length > 0;
                                        // 如果第一行包含 th 標籤，則使用第一行的所有單元格
                                        if (hasTh) {
                                            // 獲取第一行的所有單元格
                                            headerRow = Array.from(firstRowCells).map((cell, idx) => {
                                                const text = cell.textContent.trim();
                                                return text || 'column_' + idx;
                                            });
                                            dataStartIndex = 1;
                                        }
                                    }
                                }
                            }
                            
                            // 將資料行轉換為對象數組
                            const dataRows = rows.slice(dataStartIndex).map((row, rowIndex) => {
                                const cells = Array.from(row.querySelectorAll('td'));
                                const rowData = {};
                                
                                if (headerRow && headerRow.length > 0) {
                                    headerRow.forEach((header, colIndex) => {
                                        // 清理字段名（移除特殊字符，用於 JSON key）
                                        // 保留中文字符和基本字符
                                        let cleanHeader = header
                                            .replace(/[^\w\u4e00-\u9fa5]/g, '_')
                                            .replace(/^_+|_+$/g, '');
                                        
                                        // 如果清理後為空，使用索引
                                        if (!cleanHeader) {
                                            cleanHeader = 'column_' + colIndex;
                                        }
                                        
                                        // 確保字段名唯一（如果重複，添加索引）
                                        let finalHeader = cleanHeader;
                                        let counter = 1;
                                        while (rowData.hasOwnProperty(finalHeader)) {
                                            finalHeader = cleanHeader + '_' + counter;
                                            counter++;
                                        }
                                        
                                        rowData[finalHeader] = cells[colIndex] ? cells[colIndex].textContent.trim() : null;
                                    });
                                }
                                
                                // 添加原始行索引
                                rowData._rowIndex = rowIndex;
                                
                                return rowData;
                            });
                            
                            return {
                                tableIndex: tableIndex,
                                tableId: table.id || null,
                                tableClass: table.className || null,
                                isHeaderTable: isHeaderTable,
                                headers: headerRow || [],
                                headerCount: headerRow ? headerRow.length : 0,
                                rowCount: dataRows.length,
                                // 原始格式（保留以備用）
                                rawRows: rows.slice(dataStartIndex).map(row => 
                                    Array.from(row.querySelectorAll('td')).map(cell => cell.textContent.trim())
                                ),
                                // 結構化資料（推薦使用）
                                data: dataRows
                            };
                        }
                        
                        // 提取表單中的日期輸入值
                        const formDates = {};
                        const dateInputs = document.querySelectorAll('input[type="date"], input[type="text"][placeholder*="date"], input[type="text"][placeholder*="日期"], input[name*="date"], input[name*="日期"]');
                        dateInputs.forEach(input => {
                            const name = input.name || input.id || input.placeholder || 'unknown';
                            const value = input.value || '';
                            if (value) {
                                formDates[name] = value;
                            }
                        });
                        
                        // 也查找日期範圍輸入（通常有 start 和 end）
                        const dateRangeInputs = document.querySelectorAll('input[type="date"], input[type="text"]');
                        dateRangeInputs.forEach(input => {
                            const name = (input.name || input.id || '').toLowerCase();
                            const value = input.value || '';
                            if (value && (name.includes('start') || name.includes('begin') || name.includes('from'))) {
                                formDates['start_date'] = value;
                            }
                            if (value && (name.includes('end') || name.includes('to') || name.includes('until'))) {
                                formDates['end_date'] = value;
                            }
                        });
                        
                        // 專門提取 placeholder 為 "Start time" 和 "End time" 的日期輸入
                        const startTimeInputs = document.querySelectorAll('input[placeholder="Start time"], input[placeholder*="Start time"]');
                        startTimeInputs.forEach(input => {
                            const value = input.value || '';
                            if (value) {
                                formDates['start_time'] = value;
                                formDates['start_time_original'] = value;
                            }
                        });
                        
                        const endTimeInputs = document.querySelectorAll('input[placeholder="End time"], input[placeholder*="End time"]');
                        endTimeInputs.forEach(input => {
                            const value = input.value || '';
                            if (value) {
                                formDates['end_time'] = value;
                                formDates['end_time_original'] = value;
                            }
                        });

                        const result = {
                            // 基本頁面信息
                            pageInfo: {
                                title: document.title,
                                url: window.location.href,
                            },
                            
                            // 提取所有文本內容
                            textContent: document.body.innerText.trim(),
                            
                            // 提取表單日期
                            formDates: formDates,

                            // 提取所有表格資料，並且過濾掉只有表頭沒有資料的表格
                            tables: allTables.map((table, tableIndex) => processTable(table, tableIndex))
                                .filter(table => {
                                    // 保留有資料的表格，或不是純表頭表格的表格
                                    return table.rowCount > 0 || !table.isHeaderTable;
                                })
                        };
                        
                        return result;
                    });

                    // 截圖（用於調試和驗證）
                    // fullPage: true 表示截取整個頁面，而不只是可見區域
                    await page.screenshot({ 
                        path: 'scraped_page_screenshot.png',
                        fullPage: true 
                    });

                    console.log('📸 Screenshot saved: scraped_page_screenshot.png');

                    // 合併所有提取的資料和捕獲的 DOM 資料
                    const result = {
                        timestamp: new Date().toISOString(),  // 時間戳
                        url: '$url',  // 目標 URL
                        domData: domData,  // 捕獲的 DOM 資料
                        success: true  // 成功標記
                    };

                    // 將結果保存為 JSON 文件
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');
                    console.log('📊 DOM elements extracted:');

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
            scrapeDOMContent().then(() => {
                console.log('✅ DOM scraping completed successfully');
                process.exit(0);  // 成功退出
            }).catch((error) => {
                console.error('💥 DOM scraping failed:', error);
                process.exit(1);  // 失敗退出
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
     * 處理和保存爬取的資料
     * @param array $result 爬取的結果資料
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');

        // 檢查爬取是否成功
        if (!$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }

        // 提取 DOM 資料
        $domData = $result['domData'] ?? [];

        // 處理表單日期
        $formDates = $domData['formDates'] ?? [];
        // 判斷是否有表單日期
        if (!empty($formDates)) {
            // 處理 Start time
            if (isset($formDates['start_time'])) {
                // 取得 Start time
                $startTime = $formDates['start_time'];
                // 轉換日期格式
                try {
                    $startDateTime = \Carbon\Carbon::parse($startTime);
                    $formDates['start_time_converted'] = $startDateTime->format('Y-m-d H:i:s');
                    $formDates['start_time_timestamp'] = $startDateTime->timestamp;
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Could not parse start_time: {$e->getMessage()}");
                }
            }
            // 處理 End time
            if (isset($formDates['end_time'])) {
                // 取得 End time
                $endTime = $formDates['end_time'];                
                // 轉換日期格式
                try {
                    $endDateTime = \Carbon\Carbon::parse($endTime);
                    $formDates['end_time_converted'] = $endDateTime->format('Y-m-d H:i:s');
                    $formDates['end_time_timestamp'] = $endDateTime->timestamp;
                } catch (\Exception $e) {
                    $this->warn("   ⚠️  Could not parse end_time: {$e->getMessage()}");
                }
            }
            
            // 更新 domData 中的 formDates
            $domData['formDates'] = $formDates;
        }

        // 生成時間戳，用於文件名
        $timestamp = date('Y-m-d_H-i-s');

        // 保存完整結果為 JSON 文件
        $fullResultPath = storage_path("app/scraped_data/dom_scrape_full_{$timestamp}.json");
        Storage::put("scraped_data/dom_scrape_full_{$timestamp}.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 保存表格資料（包含原始和結構化格式）
        if (!empty($domData['tables'])) {
            // 保存完整表格信息（包含原始和結構化資料）
            Storage::put("scraped_data/dom_tables_{$timestamp}.json", json_encode($domData['tables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // 為每個表格單獨保存結構化資料（推薦使用）
            foreach ($domData['tables'] as $tableIndex => $table) {
                if (!empty($table['data'])) {
                    $tableFileName = "scraped_data/dom_table_{$tableIndex}_structured_{$timestamp}.json";
                    Storage::put($tableFileName, json_encode([
                        'tableIndex' => $table['tableIndex'],
                        'headers' => $table['headers'],
                        'rowCount' => $table['rowCount'],
                        'data' => $table['data']
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->info("   📋 Table {$tableIndex} structured data saved");
                }
            }
            $this->info("📊 Tables saved separately");
        }

        $this->info("💾 Full results saved to: {$fullResultPath}");

        // 將截圖從臨時目錄移動到永久存儲目錄
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/dom_screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }
        
        $this->info("✅ Data processing completed!");
    }
}

