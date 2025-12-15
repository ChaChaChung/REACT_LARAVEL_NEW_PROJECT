<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserDOM extends Command
{
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

        // 從 .env 環境變數獲取認證相關的 cookie 值
        $auth = env('AGENT_AUTH', '');
        $token = env('AGENT_TOKEN', '');
        $bgLang = env('AGENT_BG_LANGUAGE_KEY', 'zh-cn');

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
                        
                        const result = {
                            // 基本頁面信息
                            pageInfo: {
                                title: document.title,
                                url: window.location.href,
                                description: document.querySelector('meta[name="description"]')?.content || null,
                                keywords: document.querySelector('meta[name="keywords"]')?.content || null,
                            },
                            
                            // 提取所有文本內容
                            textContent: document.body.innerText.trim(),

                            // 提取所有表格資料（轉換為對象數組，字段名對應表頭）
                            tables: allTables.map((table, tableIndex) => processTable(table, tableIndex)),
                            
                            // 提取所有表單資料
                            forms: Array.from(document.querySelectorAll('form')).map((form, index) => ({
                                index: index,
                                action: form.action || null,
                                method: form.method || 'get',
                                inputs: Array.from(form.querySelectorAll('input, select, textarea')).map(input => ({
                                    type: input.type || input.tagName.toLowerCase(),
                                    name: input.name || null,
                                    id: input.id || null,
                                    value: input.value || null,
                                    placeholder: input.placeholder || null,
                                    required: input.required || false
                                }))
                            })),
                            
                            
                            // 提取所有具有 data-* 屬性的元素
                            dataAttributes: Array.from(document.querySelectorAll('*')).filter(el => {
                                // 檢查元素是否有任何 data-* 屬性
                                return Array.from(el.attributes).some(attr => attr.name.startsWith('data-'));
                            }).slice(0, 50).map(el => {
                                const dataAttrs = {};
                                Array.from(el.attributes).forEach(attr => {
                                    if (attr.name.startsWith('data-')) {
                                        dataAttrs[attr.name] = attr.value;
                                    }
                                });
                                return {
                                    tagName: el.tagName.toLowerCase(),
                                    textContent: el.textContent.trim().substring(0, 100),
                                    dataAttributes: dataAttrs
                                };
                            }),
                            
                            // 提取所有 class 和 id
                            classes: Array.from(document.querySelectorAll('[class]')).slice(0, 100).map(el => ({
                                tagName: el.tagName.toLowerCase(),
                                className: el.className,
                                id: el.id || null
                            })),
                            
                            // 提取 JSON-LD 結構化資料（如果存在）
                            jsonLd: Array.from(document.querySelectorAll('script[type="application/ld+json"]')).map(script => {
                                try {
                                    return JSON.parse(script.textContent);
                                } catch (e) {
                                    return null;
                                }
                            }).filter(data => data !== null),
                            
                            // 提取所有 meta 標籤
                            metaTags: Array.from(document.querySelectorAll('meta')).map(meta => ({
                                name: meta.name || meta.property || null,
                                content: meta.content || null,
                                httpEquiv: meta.httpEquiv || null
                            })),
                            
                            // 統計信息
                            statistics: {
                                totalTables: document.querySelectorAll('table').length,
                                totalForms: document.querySelectorAll('form').length,
                                totalScripts: document.querySelectorAll('script').length,
                                totalStyles: document.querySelectorAll('style, link[rel="stylesheet"]').length
                            }
                        };
                        
                        return result;
                    });

                    // 截圖
                    await page.screenshot({ 
                        path: 'scraped_page_screenshot.png',
                        fullPage: true 
                    });
                    console.log('📸 Screenshot saved: scraped_page_screenshot.png');

                    // 合併結果
                    const result = {
                        timestamp: new Date().toISOString(),  // 時間戳
                        url: '$url',  // 目標 URL
                        domData: domData,  // 捕獲的 DOM 資料
                        success: true  // 成功標記
                    };

                    // 保存結果
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');
                    console.log('📊 DOM elements extracted:');
                    console.log('   - Tables:', domData.statistics.totalTables);
                    console.log('   - Forms:', domData.statistics.totalForms);

                    return result;
                } catch (error) {
                    console.error('❌ Error during scraping:', error);
                    fs.writeFileSync('scraped_result.json', JSON.stringify({
                        error: error.message,
                        success: false,
                        timestamp: new Date().toISOString()
                    }, null, 2));
                    throw error;
                } finally {
                    await browser.close();
                    console.log('🏁 Browser closed');
                }
            }

            // 執行爬取函數
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

        $workingDir = dirname($scriptPath);
        $result = Process::path($workingDir)->run("node " . basename($scriptPath));

        $this->line("");
        $this->line("📋 Browser Output:");
        $this->line($result->output());

        if ($result->failed()) {
            $this->error("❌ Browser automation failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }

        $resultFile = $workingDir . '/scraped_result.json';

        if (file_exists($resultFile)) {
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

        if (!$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }

        $domData = $result['domData'] ?? [];
        $statistics = $domData['statistics'] ?? [];

        // 顯示資料分析結果
        $this->info("📊 DOM Extraction Results:");
        $this->info("   Page Title: " . ($domData['pageInfo']['title'] ?? 'N/A'));
        $this->info("   Tables: " . ($statistics['totalTables'] ?? 0));
        $this->info("   Forms: " . ($statistics['totalForms'] ?? 0));
        
        // 顯示表格詳細信息
        if (!empty($domData['tables'])) {
            $this->line("");
            $this->info("📋 Tables Details:");
            foreach ($domData['tables'] as $tableIndex => $table) {
                $this->info("   Table #{$tableIndex}:");
                $this->info("      - Headers: " . implode(', ', array_slice($table['headers'] ?? [], 0, 5)) . (count($table['headers'] ?? []) > 5 ? '...' : ''));
                $this->info("      - Rows: " . ($table['rowCount'] ?? 0));
                $this->info("      - Fields: " . count($table['headers'] ?? []));
                if (!empty($table['data'])) {
                    $this->info("      - ✅ Structured data available (use tables[{$tableIndex}].data)");
                }
            }
        }

        // 生成時間戳
        $timestamp = date('Y-m-d_H-i-s');

        // 保存完整結果
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
                        'tableId' => $table['tableId'] ?? null,
                        'tableClass' => $table['tableClass'] ?? null,
                        'headers' => $table['headers'],
                        'rowCount' => $table['rowCount'],
                        'description' => '此文件包含結構化的表格資料，每行資料都是對象，字段名對應表頭。',
                        'data' => $table['data']
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    $this->info("   📋 Table {$tableIndex} structured data saved");
                }
            }
            
            $this->info("📊 Tables saved separately");
        }

        $this->info("💾 Full results saved to: {$fullResultPath}");

        // 移動截圖
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/dom_screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }
        
        $this->info("✅ Data processing completed!");
    }
}

