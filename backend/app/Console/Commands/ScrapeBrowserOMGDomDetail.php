<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\HasAgentAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * 瀏覽器 DOM 內容爬蟲命令 - OMG
 */
class ScrapeBrowserOMGDomDetail extends Command
{
    use HasAgentAuth;

    /**
     * 命令簽名和參數定義
     * @var string
     */
    protected $signature = 'agent:scrape-omg-dom-detail {url} {date_start?} {date_end?} {account_number?} {--concurrency=4}';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Scrape content from OMG DOM elements using browser automation with detailed information';

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
        $account_number = $this->argument('account_number');
        $concurrency = $this->option('concurrency');

        $this->info('=== OMG DOM Data Scraper (LocalStorage Login) ===');
        $this->info("Target URL: {$url}");
        $this->info("Start Date: {$date_start}");
        $this->info("End Date: {$date_end}");
        $this->info("Account Number: {$account_number}");
        $this->info("Concurrency: {$concurrency}");

        $this->info('Start of command at: ' . date('Y-m-d H:i:s'));

        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $date_start, $date_end, $concurrency, $account_number);

        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);

        // 如果執行成功，處理截圖和數據
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

        $result = Process::run('node --version');

        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            return false;
        }

        $this->info('✅ Node.js found: ' . trim($result->output()));

        $puppeteerCheck = Process::run('npm list puppeteer --depth=0');

        if ($puppeteerCheck->failed()) {
            $this->warn('⚠️  Puppeteer not found. Installing...');
            $install = Process::run('npm install puppeteer');

            if ($install->failed()) {
                $this->error('❌ Failed to install Puppeteer');
                return false;
            }

            $this->info('✅ Puppeteer installed successfully');
        } else {
            $this->info('✅ Puppeteer found');
        }

        return true;
    }

    /**
     * 創建 Puppeteer 自動化腳本（使用 localStorage 登入）
     */
    private function createPuppeteerScript($url, $dateStart = null, $dateEnd = null, $concurrency = 4, $accountNumber = null)
    {
        $this->info('2. Creating browser automation script...');

        // 獲取 OMG localStorage 登入程式碼片段
        $omgLoginCode = $this->generateOmgPuppeteerLoginInfoCode('page');

        // 轉義 JavaScript 字符串
        $urlJs = json_encode($url);
        $dateStartJs = $dateStart ? json_encode(date('Y-m-d', strtotime($dateStart))) : 'null';
        $dateEndJs = $dateEnd ? json_encode(date('Y-m-d', strtotime($dateEnd))) : 'null';
        $accountNumberJs = $accountNumber ? json_encode($accountNumber) : 'null';

        $workingDir = storage_path('app/temp');
        $workingDirJs = json_encode($workingDir);

        // 生成 Puppeteer JavaScript 腳本
        $script = <<<JS
            const puppeteer = require('puppeteer');
            const fs = require('fs');
            const path = require('path');
            
            const workingDir = $workingDirJs;

            /**
             * OMG 使用 localStorage 登入流程
             */
            async function loginWithLocalStorageAndScreenshot() {
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
                    ],
                    executablePath: process.env.CHROME_BIN || undefined
                });

                try {
                    const page = await browser.newPage();
                    await page.setViewport({ width: 1920, height: 1080 });
                    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

                    console.log('🔐 Starting OMG localStorage login process...');
                    
                    // 使用 trait 中的方法設置 localStorage
                    $omgLoginCode
                    
                    const targetUrl = $urlJs.replace(/^"|"\$/g, '');
                    
                    // 這裡可以加入具體的爬蟲邏輯，與 Wow 類似的 extractTableData 等
                    // 為簡化，這裡暫時保留核心登入邏輯，並做簡單截圖
                    
                    console.log('🌐 Navigating to target URL: ' + targetUrl);
                    await page.goto(targetUrl, {
                        waitUntil: 'networkidle2',
                        timeout: 60000
                    });
                     
                    await new Promise(resolve => setTimeout(resolve, 3000));
                    
                    // 解析日期參數
                    let dateStartParsed = null;
                    let dateEndParsed = null;
                    try {
                        dateStartParsed = $dateStartJs !== 'null' ? $dateStartJs.replace(/^"|"\$/g, '') : null;
                        dateEndParsed = $dateEndJs !== 'null' ? $dateEndJs.replace(/^"|"\$/g, '') : null;
                    } catch (e) {
                         console.error('Date parsing error', e);
                    }

                    // 填入日期
                    if (dateStartParsed || dateEndParsed) {
                         // DEBUG: Click date input, find specific date, and screenshot
                         try {
                             console.log('👆 Debug: Clicking Start Date input (#v-16-form-item)...');
                             await page.waitForSelector('#v-16-form-item', { timeout: 5000 });
                             await page.click('#v-16-form-item');
                             await new Promise(r => setTimeout(r, 1000)); // Wait for picker (if any)
                             
                             if (dateStartParsed) {
                                 console.log('🔍 Debug: Looking for element with date: ' + dateStartParsed);
                                 const clickedDate = await page.evaluate((dateStr) => {
                                     // 嘗試查找 Ant Design 或其他常見 UI 庫的日期單元格
                                     // AntD 通常使用 title="YYYY-MM-DD"
                                     let el = document.querySelector(`td[title="\${dateStr}"]`);
                                     
                                     // 如果找不到，嘗試 aria-label
                                     if (!el) {
                                          el = document.querySelector(`td[aria-label="\${dateStr}"]`);
                                     }
                                     
                                     // 嘗試查找包含該日期的 div 或 span (精確匹配內容)
                                     if (!el) {
                                         // 假設 dateStr 是 YYYY-MM-DD，取出 Day 部分
                                         const day = parseInt(dateStr.split('-')[2], 10).toString();
                                         // 查找所有可能的日期單元格
                                         const cells = Array.from(document.querySelectorAll('.ant-picker-cell-inner, .el-date-table__cell'));
                                         el = cells.find(c => c.textContent.trim() === day);
                                     }
                                     
                                     if (el) {
                                         el.click();
                                         return true;
                                     }
                                     return false;
                                 }, dateStartParsed);
                                 
                                 if (clickedDate) {
                                     console.log('✅ Debug: Found and clicked date element for ' + dateStartParsed);
                                     
                                     // Click OK button
                                     await new Promise(r => setTimeout(r, 500));
                                     const okClicked = await page.evaluate(() => {
                                         const buttons = Array.from(document.querySelectorAll('button'));
                                         const okBtn = buttons.find(b => 
                                             b.textContent.trim() === 'Ok' || 
                                             b.querySelector('span')?.textContent.trim() === 'Ok'
                                         );
                                         
                                         if (okBtn) {
                                             okBtn.click();
                                             return true;
                                         }
                                         return false;
                                     });
                                     
                                     if (okClicked) {
                                         console.log('✅ Debug: Clicked OK button');
                                     } else {
                                         console.log('⚠️ Debug: OK button not found');
                                     }
                                 } else {
                                     console.log('⚠️ Debug: Specific date element not found for ' + dateStartParsed);
                                 }
                             }
                             
                             await new Promise(r => setTimeout(r, 1000));
                             await page.screenshot({ path: path.join(workingDir, 'omg_debug_date_selected_ok.png'), fullPage: true });
                             console.log('📸 Debug screenshot saved: omg_debug_date_selected_ok.png');
                         } catch (e) {
                             console.error('❌ Debug interaction failed:', e.message);
                         }

                         console.log('📅 Filling dates...');
                         await page.evaluate(async (start, end) => {
                                const sleep = (ms) => new Promise(r => setTimeout(r, ms));
                                
                                if (start) {
                                     // 嘗試多種方式尋找 Start Date
                                     const startInput = document.querySelector('#v-16-form-item') || 
                                                        document.querySelector('input[placeholder="Start date"]');
                                     
                                     if (startInput) {
                                         console.log('Found start date input');
                                         // 移除 readonly 屬性以允許填寫
                                         startInput.removeAttribute('readonly');
                                         startInput.value = start + ' 00:00:00';
                                         
                                         // 觸發事件
                                         startInput.dispatchEvent(new Event('input', { bubbles: true }));
                                         startInput.dispatchEvent(new Event('change', { bubbles: true }));
                                         startInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                     } else {
                                         console.log('Start date input not found');
                                     }
                                }
                                
                                await sleep(500);

                                if (end) {
                                     // End Date 根據 placeholder 尋找
                                     const endInput = document.querySelector('input[placeholder="End date"]');
                                     
                                     if (endInput) {
                                         console.log('Found end date input');
                                         endInput.removeAttribute('readonly');
                                         endInput.value = end + ' 23:59:59';
                                         
                                         endInput.dispatchEvent(new Event('input', { bubbles: true }));
                                         endInput.dispatchEvent(new Event('change', { bubbles: true }));
                                         endInput.dispatchEvent(new Event('blur', { bubbles: true }));
                                     } else {
                                         console.log('End date input not found');
                                     }
                                }
                         }, dateStartParsed, dateEndParsed);
                         
                         // 等待一下讓UI反應
                         await new Promise(resolve => setTimeout(resolve, 1000));
                         
                         // 嘗試點擊搜尋按鈕 (假設有)
                         console.log('🔍 Clicking search...');
                         await page.evaluate(() => {
                             const buttons = Array.from(document.querySelectorAll('button'));
                             const searchBtn = buttons.find(b => b.textContent.includes('Search') || b.textContent.includes('查询') || b.textContent.includes('搜尋'));
                             if (searchBtn) searchBtn.click();
                         });
                         
                         await new Promise(resolve => setTimeout(resolve, 2000));
                    }

                    
                    // 截圖
                    const screenshotPath = path.join(workingDir, 'omg_scraped_result.png');
                    await page.screenshot({ path: screenshotPath, fullPage: true });
                    console.log('📸 Screenshot saved to: ' + screenshotPath);
                    
                    // 返回結果(模擬)
                    return {
                        success: true,
                        screenshot: screenshotPath,
                        data: []
                    };
                    
                } catch (error) {
                    console.error('❌ Error in Puppeteer script:', error);
                    return { success: false, error: error.message };
                } finally {
                    await browser.close();
                }
            }
            
            loginWithLocalStorageAndScreenshot().then(result => {
                console.log(JSON.stringify(result));
            }).catch(err => {
                console.error(err);
                process.exit(1);
            });
        JS;

        // 確保目錄存在
        if (!file_exists($workingDir)) {
            mkdir($workingDir, 0755, true);
        }

        $scriptPath = $workingDir . '/scrape_omg_' . time() . '.js';
        file_put_contents($scriptPath, $script);

        return $scriptPath;
    }

    /**
     * 執行 Puppeteer 腳本
     */
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running Puppeteer script...');
        $this->info("Script path: {$scriptPath}");

        $process = Process::run("node {$scriptPath}");

        if ($process->failed()) {
            $this->error('❌ Puppeteer script failed');
            $this->error($process->errorOutput());
            return null;
        }

        $output = $process->output();
        $this->line($output);

        // 嘗試解析 JSON 輸出
        preg_match('/\{.*"success":.*\}/s', $output, $matches);
        if (!empty($matches)) {
            return json_decode($matches[0], true);
        }

        return null;
    }

    /**
     * 處理爬取到的數據
     */
    private function processScrapedData($result)
    {
        $this->info('4. Processing scraped data...');
        if ($result && isset($result['success']) && $result['success']) {
            $this->info('✅ Scraping completed successfully!');
            if (isset($result['screenshot'])) {
                $this->info("Screenshot: {$result['screenshot']}");
            }
        }
    }
}
