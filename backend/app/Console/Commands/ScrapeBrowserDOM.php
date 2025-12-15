<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * 瀏覽器 DOM 內容爬蟲命令
 * 此命令示範如何從網頁的 DOM 元素中提取數據
 */
class ScrapeBrowserDOM extends Command
{
    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-dom {url} {--lang-switch=} {--lang-item=}
     * {url} - 要爬取的目標網址（必需參數）
     * {--lang-switch=} - 可選的語言切換按鈕選擇器，點擊後再提取數據
     * {--lang-item=} - 可選的語言菜單項選擇器（如果提供了，會先點擊按鈕展開菜單，再點擊此項）
     */
    protected $signature = 'agent:scrape-dom {url} {--lang-switch=} {--lang-item=}';

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
        $langSwitch = $this->option('lang-switch');
        $langItem = $this->option('lang-item');

        $this->info('=== Browser DOM Scraper ===');
        $this->info("Target URL: {$url}");
        if ($langSwitch) {
            $this->info("Language Switch Button: {$langSwitch}");
        }
        if ($langItem) {
            $this->info("Language Menu Item: {$langItem}");
        }
        
        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $langSwitch, $langItem);

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
     * 創建 Puppeteer 自動化腳本（從 DOM 提取數據）
     * @param string $url 要爬取的目標網址
     * @param string|null $langSwitch 可選的語言切換按鈕選擇器
     * @param string|null $langItem 可選的語言菜單項選擇器
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $langSwitch = null, $langItem = null)
    {
        $this->info('2. Creating browser automation script...');

        // 從 .env 環境變數獲取認證相關的 cookie 值
        $auth = env('AGENT_AUTH', '');
        $token = env('AGENT_TOKEN', '');
        $bgLang = env('AGENT_BG_LANGUAGE_KEY', 'zh-cn');

        // 將選擇器轉義，以便在 JavaScript 中使用
        $langSwitchJs = $langSwitch ? json_encode($langSwitch) : 'null';
        $langItemJs = $langItem ? json_encode($langItem) : 'null';

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');

            /**
             * 從 DOM 提取數據的函數
             * 使用 Puppeteer 自動化瀏覽器來爬取網頁 DOM 內容
             */
            async function scrapeDOMContent() {
                console.log('🚀 Starting browser automation for DOM scraping...');

                // 啟動無頭瀏覽器（headless mode）
                const browser = await puppeteer.launch({
                    headless: 'new',
                    args: [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-dev-shm-usage',
                        '--disable-accelerated-2d-canvas',
                        '--no-first-run',
                        '--no-zygote',
                        '--single-process',
                        '--disable-gpu',
                        '--disable-software-rasterizer',
                        '--disable-background-timer-throttling',
                        '--disable-backgrounding-occluded-windows',
                        '--disable-renderer-backgrounding',
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
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

                    console.log('🔐 Setting authentication cookies...');

                    // 設定認證 cookies
                    const cookies = [];
                    if ('$auth') cookies.push({ name: 'auth', value: '$auth', domain: 'agent2.chichengwld.com' });
                    if ('$token') cookies.push({ name: 'token', value: '$token', domain: 'agent2.chichengwld.com' });
                    if ('$bgLang') cookies.push({ name: 'bg_languageKey', value: '$bgLang', domain: 'agent2.chichengwld.com' });

                    if (cookies.length > 0) {
                        await page.setCookie(...cookies);
                        console.log('✅ Cookies set:', cookies.length);
                    }

                    // 監聽控制台錯誤
                    page.on('console', msg => {
                        if (msg.type() === 'error') {
                            console.log('❌ Browser console error:', msg.text());
                        }
                    });

                    console.log('🌐 Navigating to:', '$url');

                    // 導航到目標頁面
                    await page.goto('$url', {
                        waitUntil: 'networkidle2',
                        timeout: 30000
                    });

                    // 等待額外時間，確保動態內容完全載入
                    await new Promise(resolve => setTimeout(resolve, 2000));

                    // 如果指定了語言切換選擇器，點擊語言切換按鈕
                    const langSwitchSelector = $langSwitchJs;
                    const langItemSelector = $langItemJs;
                    
                    if (langSwitchSelector) {
                        console.log('🌐 Switching language...');
                        try {
                            // 等待語言切換按鈕出現
                            await page.waitForSelector(langSwitchSelector, { timeout: 10000 });
                            console.log('✅ Language switch button found:', langSwitchSelector);
                            
                            // 點擊語言切換按鈕（展開下拉菜單）
                            await page.click(langSwitchSelector);
                            console.log('✅ Language switch button clicked');
                            
                            // 如果指定了語言菜單項選擇器，點擊菜單項
                            if (langItemSelector) {
                                // 等待下拉菜單展開
                                await new Promise(resolve => setTimeout(resolve, 500));
                                
                                // 等待語言菜單項出現
                                await page.waitForSelector(langItemSelector, { timeout: 5000 });
                                console.log('✅ Language menu item found:', langItemSelector);
                                
                                // 點擊語言菜單項
                                await page.click(langItemSelector);
                                console.log('✅ Language menu item clicked');
                                
                                // 等待菜單關閉
                                await new Promise(resolve => setTimeout(resolve, 500));
                            }
                            
                            // 等待頁面內容更新（可能是異步加載）
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            // 等待網路空閒，確保內容已加載
                            await page.waitForNavigation({ 
                                waitUntil: 'networkidle2', 
                                timeout: 10000 
                            }).catch(() => {
                                // 如果沒有導航，繼續執行
                                console.log('⚠️  No navigation detected, continuing...');
                            });
                            
                            // 額外等待，確保動態內容已更新
                            await new Promise(resolve => setTimeout(resolve, 2000));
                            
                            console.log('✅ Language switched successfully');
                        } catch (e) {
                            console.log('⚠️  Failed to switch language:', e.message);
                            console.log('   Continuing with original language...');
                        }
                    }

                    console.log('📄 Extracting DOM content...');

                    // 使用 page.evaluate() 在瀏覽器環境中執行 JavaScript 來提取 DOM 數據
                    const domData = await page.evaluate(() => {
                        // 先處理表格數據（在對象字面量外）
                        const allTables = Array.from(document.querySelectorAll('table'));
                        const tableHeaders = {}; // 存儲每個表格的表頭
                        
                        // 第一遍：識別表頭表格（通常包含 th 標籤或 class 包含 header）
                        allTables.forEach((table, index) => {
                            const rows = Array.from(table.querySelectorAll('tr'));
                            const tableClass = table.className || '';
                            const isHeaderTable = tableClass.includes('header') || 
                                                 tableClass.includes('Header') ||
                                                 rows.some(row => row.querySelectorAll('th').length > 0);
                            
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
                            const rows = Array.from(table.querySelectorAll('tr'));
                            const tableClass = table.className || '';
                            const isHeaderTable = tableClass.includes('header') || 
                                                 tableClass.includes('Header') ||
                                                 rows.some(row => row.querySelectorAll('th').length > 0);
                            
                            let headerRow = null;
                            let dataStartIndex = 0;
                            
                            // 如果是表頭表格，只提取表頭，不提取數據
                            if (isHeaderTable) {
                                if (rows[0]) {
                                    const headerCells = rows[0].querySelectorAll('th, td');
                                    headerRow = Array.from(headerCells).map((cell, idx) => {
                                        const text = cell.textContent.trim();
                                        return text || 'column_' + idx;
                                    });
                                }
                                // 表頭表格通常沒有數據行
                                dataStartIndex = rows.length;
                            } else {
                                // 數據表格：嘗試找到對應的表頭
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
                                    headerRow = foundHeader;
                                    dataStartIndex = 0; // 所有行都是數據
                                } else {
                                    // 3. 否則檢查第一行是否包含 th（標準表頭）
                                    if (rows[0]) {
                                        const firstRowCells = rows[0].querySelectorAll('th, td');
                                        const hasTh = rows[0].querySelectorAll('th').length > 0;
                                        
                                        if (hasTh) {
                                            headerRow = Array.from(firstRowCells).map((cell, idx) => {
                                                const text = cell.textContent.trim();
                                                return text || 'column_' + idx;
                                            });
                                            dataStartIndex = 1;
                                        }
                                    }
                                    
                                    // 4. 如果還是沒有表頭，檢查第一行是否看起來像表頭
                                    if (!headerRow && rows.length > 0) {
                                        const firstRowCells = rows[0].querySelectorAll('td');
                                        const firstRowTexts = Array.from(firstRowCells).map(cell => cell.textContent.trim());
                                        
                                        // 檢查第一行是否看起來像數據（包含數字、日期等）
                                        const looksLikeData = firstRowTexts.some(text => {
                                            return /^\d+$/.test(text) || // 純數字
                                                   /^\d{4}-\d{2}-\d{2}/.test(text) || // 日期
                                                   /^\d+\.\d+$/.test(text); // 小數
                                        });
                                        
                                        if (!looksLikeData && firstRowTexts.length > 0) {
                                            // 第一行看起來像表頭
                                            headerRow = firstRowTexts.map((text, idx) => text || 'column_' + idx);
                                            dataStartIndex = 1;
                                        } else {
                                            // 第一行看起來像數據，創建默認字段名
                                            if (firstRowCells.length > 0) {
                                                headerRow = Array.from(firstRowCells).map((_, idx) => 'column_' + (idx + 1));
                                            } else {
                                                headerRow = [];
                                            }
                                            dataStartIndex = 0;
                                        }
                                    }
                                }
                            }
                            
                            // 將數據行轉換為對象數組
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
                                // 結構化數據（推薦使用）
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
                            
                            // 提取所有連結
                            links: Array.from(document.querySelectorAll('a[href]')).map(a => ({
                                text: a.textContent.trim(),
                                href: a.href,
                                title: a.title || null
                            })),
                            
                            // 提取所有圖片
                            images: Array.from(document.querySelectorAll('img[src]')).map(img => ({
                                src: img.src,
                                alt: img.alt || null,
                                title: img.title || null,
                                width: img.naturalWidth || null,
                                height: img.naturalHeight || null
                            })),
                            
                            // 提取所有表格數據（轉換為對象數組，字段名對應表頭）
                            tables: allTables.map((table, tableIndex) => processTable(table, tableIndex)),
                            
                            // 提取所有表單數據
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
                            
                            // 提取 JSON-LD 結構化數據（如果存在）
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
                                totalLinks: document.querySelectorAll('a[href]').length,
                                totalImages: document.querySelectorAll('img[src]').length,
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
                        timestamp: new Date().toISOString(),
                        url: '$url',
                        langSwitch: $langSwitchJs,
                        langItem: $langItemJs,
                        metadata: {
                            description: 'DOM 爬取結果數據結構說明',
                            dataStructure: {
                                pageInfo: '頁面基本信息（標題、URL、meta標籤）',
                                textContent: '頁面文本內容',
                                links: '所有連結數組，每個對象包含 text, href, title',
                                images: '所有圖片數組，每個對象包含 src, alt, title, width, height',
                                tables: '所有表格數組，每個表格包含：tableIndex, headers（表頭）, data（結構化數據，推薦使用）, rawRows（原始數組格式）',
                                forms: '所有表單數組，每個表單包含 action, method, inputs',
                                dataAttributes: '所有帶有 data-* 屬性的元素',
                                classes: '所有帶有 class 的元素',
                                jsonLd: 'JSON-LD 結構化數據',
                                metaTags: '所有 meta 標籤',
                                statistics: '頁面元素統計信息'
                            },
                            note: '表格數據建議使用 tables[].data 字段，這是結構化的對象數組，每個對象的鍵對應表頭名稱'
                        },
                        domData: domData,
                        success: true
                    };

                    // 保存結果
                    fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
                    console.log('💾 Results saved to: scraped_result.json');
                    console.log('📊 DOM elements extracted:');
                    console.log('   - Links:', domData.statistics.totalLinks);
                    console.log('   - Images:', domData.statistics.totalImages);
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
     * @return array|null 返回解析後的結果數據，失敗時返回 null
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
     * 處理和保存爬取的數據
     * @param array $result 爬取的結果數據
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

        // 顯示數據分析結果
        $this->info("📊 DOM Extraction Results:");
        $this->info("   Page Title: " . ($domData['pageInfo']['title'] ?? 'N/A'));
        $this->info("   Links: " . ($statistics['totalLinks'] ?? 0));
        $this->info("   Images: " . ($statistics['totalImages'] ?? 0));
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

        // 保存連結數據
        if (!empty($domData['links'])) {
            Storage::put("scraped_data/dom_links_{$timestamp}.json", json_encode($domData['links'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("🔗 Links saved separately");
        }

        // 保存圖片數據
        if (!empty($domData['images'])) {
            Storage::put("scraped_data/dom_images_{$timestamp}.json", json_encode($domData['images'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("🖼️  Images saved separately");
        }

        // 保存表格數據（包含原始和結構化格式）
        if (!empty($domData['tables'])) {
            // 保存完整表格信息（包含原始和結構化數據）
            Storage::put("scraped_data/dom_tables_{$timestamp}.json", json_encode($domData['tables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // 為每個表格單獨保存結構化數據（推薦使用）
            foreach ($domData['tables'] as $tableIndex => $table) {
                if (!empty($table['data'])) {
                    $tableFileName = "scraped_data/dom_table_{$tableIndex}_structured_{$timestamp}.json";
                    Storage::put($tableFileName, json_encode([
                        'tableIndex' => $table['tableIndex'],
                        'tableId' => $table['tableId'] ?? null,
                        'tableClass' => $table['tableClass'] ?? null,
                        'headers' => $table['headers'],
                        'rowCount' => $table['rowCount'],
                        'description' => '此文件包含結構化的表格數據，每行數據都是對象，字段名對應表頭。',
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

