<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Live22 瀏覽器 DOM 內容爬蟲命令
 */
class ScrapeBrowserLive22DOMDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     * 執行方式：php artisan agent:scrape-live22-dom-detail {url}
     */
    protected $signature = 'agent:scrape-live22-dom-detail {url} {date_start?} {date_end?} {account_number?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from Live22 DOM elements using browser automation with detailed information';

    /**
     * 執行命令的主要處理方法
     * @return int 返回狀態碼：0 表示成功，1 表示失敗
     */
    public function handle()
    {
        // 獲取命令參數
        $url = $this->argument('url');
        $dateStart = $this->argument('date_start');
        $dateEnd = $this->argument('date_end');
        $accountNumber = $this->argument('account_number');
        $concurrency = $this->option('concurrency');

        $this->info('=== Live22 DOM Data Scraper ===');
        $this->info("Target URL: {$url}");
        $this->info("Date Start: {$dateStart}");
        $this->info("Date End: {$dateEnd}");
        $this->info("Account Number: {$accountNumber}");
        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 建立儲存目錄
        $storagePath = storage_path('app/scraped_data');
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $dateStart, $dateEnd, $concurrency, $accountNumber);
        
        // 執行腳本
        $resultData = $this->runPuppeteerScript($scriptPath);

        if ($resultData && ($resultData['success'] ?? false)) {
            $this->processScrapedData($resultData, $dateStart, $dateEnd);
            $this->info('End of command at: ' . date('Y-m-d H:i:s'));
            $this->info("✅ Data scraping completed!");
            return 0;
        }

        $this->error('❌ Scraping failed or no data found');
        return 1;
    }

    /**
     * 處理爬取的資料並儲存到 scraped_data
     * @param array $resultData
     * @param string|null $dateStart
     * @param string|null $dateEnd
     */
    private function processScrapedData($resultData, $dateStart, $dateEnd)
    {
        $this->info('4. Processing and saving data...');

        $dataRows = $resultData['data'] ?? [];
        if (empty($dataRows)) {
            $this->warn('⚠️  No data rows found to save');
            return;
        }

        // 格式化檔名: live22_data_YYYYMMDD_YYYYMMDD_timestamp.json
        $ds = $dateStart ? date('Ymd', strtotime($dateStart)) : 'any';
        $de = $dateEnd ? date('Ymd', strtotime($dateEnd)) : 'any';
        $timestamp = date('Ymd_His');
        $fileName = "live22_data_{$ds}_{$de}_{$timestamp}.json";
        $filePath = storage_path("app/scraped_data/{$fileName}");

        $output = [
            'metadata' => [
                'source' => 'Live22',
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'scraped_at' => date('Y-m-d H:i:s'),
                'total_rows' => count($dataRows)
            ],
            'data' => $dataRows
        ];

        file_put_contents($filePath, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("✅ Data saved to: {$filePath}");
        $this->info("📊 Total rows: " . count($dataRows));
    }

    /**
     * 檢查 Node.js 和 Puppeteer 環境
     * @return bool 返回 true 表示環境檢查通過，false 表示失敗
     */
    private function checkNodeJs()
    {
        $this->info('1. Checking Node.js installation...');

        $result = Process::run('node --version');

        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            return false;
        }

        $this->info('✅ Node.js found: ' . trim($result->output()));
        return true;
    }

    /**
     * 創建 Puppeteer 自動化腳本
     * @param string $url 要爬取的目標網址
     * @param string|null $date_start
     * @param string|null $date_end
     * @param int $concurrency
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $date_start = null, $date_end = null, $concurrency = 4, $accountNumber = null)
    {
        $this->info('2. Creating browser automation script...');

        // 格式化日期為 YYYY-MM-DD
        if ($date_start && strlen($date_start) === 8) {
            $date_start = substr($date_start, 0, 4) . '-' . substr($date_start, 4, 2) . '-' . substr($date_start, 6, 2);
        }
        if ($date_end && strlen($date_end) === 8) {
            $date_end = substr($date_end, 0, 4) . '-' . substr($date_end, 4, 2) . '-' . substr($date_end, 6, 2);
        }

        // 獲獲 Live22 登入流程程式碼片段 (localStorage)
        $authCode = $this->generateLive22PuppeteerLoginCode('page', $url);
        
        $storageScrapedPath = storage_path('app/scraped_data');
        $resultJsonPath = storage_path('app/temp/scraped_result.json');

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

async function scrapeDOMContent() {
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1920, height: 1080 });
        
        // 轉發瀏覽器 console 訊息
        page.on('console', msg => console.log('PAGE LOG:', msg.text()));

        // 1. 設置 localStorage 認證
        $authCode

        // 2. 導航到目標網址
        console.log('🌐 Navigating to target URL: $url');
        await page.goto('$url', {
            waitUntil: 'networkidle2',
            timeout: 60000
        });

        // 等待頁面載入
        await new Promise(resolve => setTimeout(resolve, 15000));
        
        await page.evaluate(() => {
            console.log('DEBUG: Title = ' + document.title);
            console.log('DEBUG: Body length = ' + document.body.innerText.length);
            console.log('DEBUG: Body sample = ' + document.body.innerText.substring(0, 500));
        });

        // 3. 設置日期範圍
        const date_start = '$date_start';
        const date_end = '$date_end';
        
        if (date_start || date_end) {
            console.log(`📅 Setting date range: \${date_start} to \${date_end}`);
            
            // 嘗試多種方式設置 React 輸入框
            await page.evaluate((start, end) => {
                function setReactValue(el, value) {
                    if (!el) return false;
                    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, "value").set;
                    setter.call(el, value);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                    el.dispatchEvent(new Event('blur', { bubbles: true }));
                    return true;
                }
                const s = document.querySelector('input[date-range="start"]');
                const e = document.querySelector('input[date-range="end"]');
                if (s && start) setReactValue(s, start);
                if (e && end) setReactValue(e, end);
            }, date_start, date_end);

            // 補充鍵盤輸入確保觸發
            async function typeInput(selector, value) {
                const input = await page.\$(selector);
                if (input) {
                    await input.click({ clickCount: 3 });
                    await page.keyboard.press('Backspace');
                    await page.keyboard.type(value);
                    await page.keyboard.press('Tab');
                }
            }
            if (date_start) await typeInput('input[date-range="start"]', date_start);
            if (date_end) await typeInput('input[date-range="end"]', date_end);

            await new Promise(resolve => setTimeout(resolve, 1000));
            
            console.log('🔍 Clicking Search/Query button...');
            const clicked = await page.evaluate(() => {
                const buttons = Array.from(document.querySelectorAll('button'));
                const searchBtn = buttons.find(b => {
                    const text = b.innerText.trim().toLowerCase();
                    return text === 'search' || text === '搜尋' || text === '查詢' || text.includes('search');
                }) || document.querySelector('.ant-btn-primary') || document.querySelector('button[type="submit"]');
                
                if (searchBtn) {
                    searchBtn.click();
                    return true;
                }
                return false;
            });
            
            console.log(clicked ? '✅ Search button clicked' : '⚠️ Search button not found');
            
            // 等待較長時間確保資料載入
            await new Promise(resolve => setTimeout(resolve, 15000));

            await page.evaluate(() => {
                const s = document.querySelector('input[date-range="start"]');
                const e = document.querySelector('input[date-range="end"]');
                console.log(`DEBUG: Final Check -> Input Start: \${s?.value}, End: \${e?.value}`);
                const body = document.body.innerText;
                const drMatch = body.match(/dateRange:\\s*(\\d{4}-\\d{2}-\\d{2}\\s*to\\s*\\d{4}-\\d{2}-\\d{2})/i);
                console.log(`DEBUG: Page displays dateRange: \${drMatch ? drMatch[1] : 'not found in text'}`);
            });
        }

        // 4. 提取表格資料
        console.log('🔍 Analyzing page structure for data...');
        const tableData = await page.evaluate(() => {
            try {
                const results = [];
                
                // 搜尋關鍵字位置
                const keywords = ['validBet', 'betAmount', 'returnAmount', 'gameIncome', 'gamePayout'];
                
                function scan(doc, prefix = '') {
                    // 找 table，但排除 ant-picker (日曆)
                    const allTables = Array.from(doc.querySelectorAll('table, .vxe-table, .el-table, .ant-table, [class*="Table"], [class*="Report"]'));
                    const containers = allTables.filter(t => !t.classList.contains('ant-picker-content') && !t.closest('.ant-picker-panel'));
                    
                    containers.forEach((c, idx) => {
                        const trs = Array.from(c.querySelectorAll('tr, .vxe-body--row, .el-table__row'));
                        // 排除只有 1-2 行的可能是空表格或小裝飾
                        if (trs.length > 0) {
                            const headers = [];
                            const headerCells = c.querySelectorAll('th, .vxe-header--column, .el-table__column');
                            headerCells.forEach((cell, i) => {
                                const text = (cell.innerText || '').trim();
                                headers.push(text || `col_\${i}`);
                            });

                            const rows = [];
                            trs.forEach(row => {
                                const cells = Array.from(row.querySelectorAll('td, .vxe-body--column, .el-table__cell'));
                                if (cells.length > 0) {
                                    const rowData = {};
                                    cells.forEach((cell, i) => {
                                        const header = headers[i] || `col_\${i}`;
                                        let val = (cell.innerText || '').trim();
                                        if (/^-?[\d,]+(\.[\d]+)?$/.test(val)) val = val.replace(/,/g, '');
                                        rowData[header] = val;
                                    });
                                    rows.push(rowData);
                                }
                            });
                            
                            const finalData = rows.filter(r => Object.keys(r).length > 0 && !Object.values(r).every(v => v === ''));
                            if (finalData.length > 0) {
                                // 檢查是否包含關鍵字，如果是，給予較高權重
                                const hasKeywords = keywords.some(k => JSON.stringify(headers).includes(k));
                                results.push({
                                    data: finalData,
                                    rowCount: finalData.length,
                                    headers: headers,
                                    source: prefix + c.tagName + '.' + c.className,
                                    weight: hasKeywords ? 1000 : finalData.length
                                });
                            }
                        }
                    });

                    const iframes = doc.querySelectorAll('iframe');
                    iframes.forEach((ifr, i) => {
                        try { if (ifr.contentDocument) scan(ifr.contentDocument, `ifr[\${i}]_`); } catch(e) {}
                    });
                }

                scan(document);

                if (results.length === 0) return { found: false, error: 'No data tables found' };

                // 挑選權重最高的 (包含關鍵字的優先)
                results.sort((a, b) => b.weight - a.weight);
                const best = results[0];

                return {
                    found: true,
                    data: best.data,
                    rowCount: best.rowCount,
                    headers: best.headers,
                    source: best.source
                };
            } catch (e) {
                return { found: false, error: e.message };
            }
        });

        // 截圖存證 (改存到 scraped_data)
        const ds = '$date_start'.replace(/\-/g, '');
        const de = '$date_end'.replace(/\-/g, '');
        const timestamp = new Date().getTime();
        const screenshotPath = `{$storageScrapedPath}/live22_screenshot_\${ds}_\${de}_\${timestamp}.png`;
        await page.screenshot({ path: screenshotPath, fullPage: true });
        console.log(`📸 Screenshot saved: \${screenshotPath}`);

        // 儲存結果
        fs.writeFileSync('{$resultJsonPath}', JSON.stringify({
            success: tableData.found,
            data: tableData.data || [],
            headers: tableData.headers || [],
            timestamp: new Date().toISOString(),
            url: '$url',
            screenshot: screenshotPath,
            error: tableData.error,
            rowCount: tableData.rowCount
        }, null, 2));

    } catch (error) {
        console.error('❌ Error during scraping:', error.message);
        fs.writeFileSync('{$resultJsonPath}', JSON.stringify({
            success: false,
            error: error.message
        }, null, 2));
    } finally {
        await browser.close();
    }
}

scrapeDOMContent().then(() => {
    process.exit(0);
}).catch((error) => {
    console.error('Fatal Error:', error);
    process.exit(1);
});
JS;

        $scriptPath = storage_path('app/temp/live22_scrape.js');
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        
        return $scriptPath;
    }
    /**
     * 執行 Puppeteer 腳本
     * @param string $scriptPath
     * @return array|bool
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation...');
        
        $workingDir = dirname($scriptPath);
        $result = Process::path($workingDir)->timeout(120)->run("node " . basename($scriptPath));

        if ($result->failed()) {
            $this->error("❌ Scraping failed");
            $this->line("Error: " . $result->errorOutput());
            return false;
        }

        $this->line($result->output());

        // 讀取結果檔案
        $resultJsonPath = $workingDir . '/scraped_result.json';
        if (file_exists($resultJsonPath)) {
            $data = json_decode(file_get_contents($resultJsonPath), true);
            
            // 顯示調試資訊
            if ($data['error'] ?? null) {
                $this->warn("⚠️  Scraper Error: " . $data['error']);
            }
            if ($data['logs'] ?? null) {
                foreach ($data['logs'] as $log) {
                    $this->line("   📄 " . $log);
                }
            }
            if ($data['keywords'] ?? null) {
                $this->info("   🔑 Keywords found: " . count($data['keywords']));
                foreach (array_slice($data['keywords'], 0, 5) as $kw) {
                    $this->line("      - [{$kw['tag']}] {$kw['text']} (class: {$kw['class']})");
                }
            }

            // 刪除臨時檔案
            @unlink($resultJsonPath);
            return $data;
        }

        return false;
    }
}
