<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ScrapeBrowserContent extends Command
{
    protected $signature = 'agent:scrape-browser {url?} {--selector=} {--wait=5000} {--output=json}';
    protected $description = 'Scrape content directly from rendered page using browser automation';

    public function handle()
    {
        $url = $this->argument('url') ?? 'https://agent2.chichengwld.com/#/record/chessRecord';
        $selector = $this->option('selector');
        $wait = (int) $this->option('wait');
        $output = $this->option('output');
        
        $this->info('=== Browser Content Scraper ===');
        $this->info("Target URL: {$url}");
        
        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }
        
        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $selector, $wait);
        
        // 執行腳本
        $result = $this->runPuppeteerScript($scriptPath);
        
        if ($result) {
            $this->processScrapedData($result, $output);
            return 0;
        }
        
        return 1;
    }
    
    private function checkNodeJs()
    {
        $this->info('1. Checking Node.js installation...');
        
        $result = Process::run('node --version');
        
        if ($result->failed()) {
            $this->error('❌ Node.js is not installed');
            $this->line('Please install Node.js from: https://nodejs.org/');
            return false;
        }
        
        $this->info('✅ Node.js found: ' . trim($result->output()));
        
        // 檢查是否有 Puppeteer
        $puppeteerCheck = Process::run('npm list puppeteer --depth=0');
        
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
    
    private function createPuppeteerScript($url, $selector, $wait)
    {
        $this->info('2. Creating browser automation script...');
        
        // 從 .env 獲取 cookie 值
        $auth = env('AGENT_AUTH', '');
        $token = env('AGENT_TOKEN', '');
        $bgLang = env('AGENT_BG_LANGUAGE_KEY', 'zh-cn');
        
        $script = <<<JS
const puppeteer = require('puppeteer');
const fs = require('fs');

async function scrapeContent() {
    console.log('🚀 Starting browser automation...');
    
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
        
        // 設定視窗大小
        await page.setViewport({ width: 1920, height: 1080 });
        
        // 設定 User Agent
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        
        console.log('🔐 Setting authentication cookies...');
        
        // 設定 cookies
        const cookies = [];
        if ('$auth') cookies.push({ name: 'auth', value: '$auth', domain: 'agent2.chichengwld.com' });
        if ('$token') cookies.push({ name: 'token', value: '$token', domain: 'agent2.chichengwld.com' });
        if ('$bgLang') cookies.push({ name: 'bg_languageKey', value: '$bgLang', domain: 'agent2.chichengwld.com' });
        
        if (cookies.length > 0) {
            await page.setCookie(...cookies);
            console.log('✅ Cookies set:', cookies.length);
        } else {
            console.log('⚠️  No cookies found in environment variables');
        }
        
        // 監聽控制台錯誤
        page.on('console', msg => {
            if (msg.type() === 'error') {
                console.log('❌ Browser console error:', msg.text());
            }
        });
        
        // 監聽網路請求（可選）
        const networkRequests = [];
        page.on('response', async (response) => {
            const url = response.url();
            if (url.includes('/api/') && response.status() === 200) {
                try {
                    const contentType = response.headers()['content-type'];
                    if (contentType && contentType.includes('application/json')) {
                        const json = await response.json();
                        networkRequests.push({
                            url: url,
                            data: json,
                            timestamp: new Date().toISOString()
                        });
                        console.log('📡 Captured API call:', url);
                    }
                } catch (e) {
                    // 忽略非 JSON 回應
                }
            }
        });
        
        console.log('🌐 Navigating to:', '$url');
        
        // 導航到目標頁面
        await page.goto('$url', {
            waitUntil: 'networkidle2',
            timeout: 30000
        });
        
        console.log('⏳ Waiting for content to load (${wait}ms)...');
        
        // 等待內容載入
        await new Promise(resolve => setTimeout(resolve, $wait));
        
        // 嘗試等待特定元素（如果有提供選擇器）
        if ('$selector') {
            try {
                console.log('🎯 Waiting for selector: $selector');
                await page.waitForSelector('$selector', { timeout: 10000 });
                console.log('✅ Selector found');
            } catch (e) {
                console.log('⚠️  Selector not found, continuing anyway...');
            }
        }
        
        // 滾動頁面以觸發懶載入
        console.log('📜 Scrolling to trigger lazy loading...');
        await page.evaluate(() => {
            return new Promise((resolve) => {
                let totalHeight = 0;
                const distance = 100;
                const timer = setInterval(() => {
                    const scrollHeight = document.body.scrollHeight;
                    window.scrollBy(0, distance);
                    totalHeight += distance;

                    if(totalHeight >= scrollHeight){
                        clearInterval(timer);
                        resolve();
                    }
                }, 100);
            });
        });
        
        // 等待額外載入
        await new Promise(resolve => setTimeout(resolve, 2000));
        
        console.log('📊 Extracting data from page...');
        
        // 提取數據
        const extractedData = await page.evaluate((selector) => {
            // 如果有指定選擇器，嘗試提取該元素的數據
            if (selector) {
                const element = document.querySelector(selector);
                if (element) {
                    return {
                        type: 'element',
                        html: element.innerHTML,
                        text: element.innerText,
                        data: element.getAttribute('data-*') || null
                    };
                }
            }
            
            // 嘗試找到表格數據
            const tables = Array.from(document.querySelectorAll('table')).map(table => {
                const headers = Array.from(table.querySelectorAll('thead th, tr:first-child td')).map(th => th.innerText.trim());
                const rows = Array.from(table.querySelectorAll('tbody tr, tr:not(:first-child)')).map(tr => {
                    return Array.from(tr.querySelectorAll('td')).map(td => td.innerText.trim());
                });
                
                return { headers, rows };
            }).filter(table => table.headers.length > 0);
            
            // 嘗試找到列表數據
            const lists = Array.from(document.querySelectorAll('ul, ol')).map(list => {
                return Array.from(list.querySelectorAll('li')).map(li => li.innerText.trim());
            }).filter(list => list.length > 0);
            
            // 嘗試找到包含 "record", "chess", "data" 等關鍵字的元素
            const dataElements = Array.from(document.querySelectorAll('*')).filter(el => {
                const text = el.innerText || '';
                const className = el.className || '';
                const id = el.id || '';
                
                return (text.length > 10 && text.length < 10000) && 
                       (text.includes('record') || text.includes('chess') || 
                        className.includes('record') || className.includes('data') ||
                        id.includes('record') || id.includes('data'));
            }).map(el => ({
                tag: el.tagName,
                class: el.className,
                id: el.id,
                text: el.innerText.substring(0, 200) + (el.innerText.length > 200 ? '...' : '')
            }));
            
            return {
                type: 'page_analysis',
                url: window.location.href,
                title: document.title,
                tables: tables,
                lists: lists,
                dataElements: dataElements.slice(0, 10), // 限制數量
                pageText: document.body.innerText.substring(0, 1000) + '...'
            };
        }, '$selector');
        
        // 截圖（用於調試）
        await page.screenshot({ 
            path: 'scraped_page_screenshot.png',
            fullPage: true 
        });
        
        console.log('📸 Screenshot saved: scraped_page_screenshot.png');
        
        // 合併結果
        const result = {
            timestamp: new Date().toISOString(),
            url: '$url',
            extractedData: extractedData,
            networkRequests: networkRequests,
            success: true
        };
        
        // 保存結果
        fs.writeFileSync('scraped_result.json', JSON.stringify(result, null, 2));
        console.log('💾 Results saved to: scraped_result.json');
        console.log('📊 Extracted tables:', extractedData.tables?.length || 0);
        console.log('📋 Extracted lists:', extractedData.lists?.length || 0);
        console.log('📡 API calls captured:', networkRequests.length);
        
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

scrapeContent().then(() => {
    console.log('✅ Scraping completed successfully');
    process.exit(0);
}).catch((error) => {
    console.error('💥 Scraping failed:', error);
    process.exit(1);
});
JS;

        $scriptPath = storage_path('app/temp/scraper.js');
        
        // 確保目錄存在
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        file_put_contents($scriptPath, $script);
        
        $this->info("✅ Script created: {$scriptPath}");
        
        return $scriptPath;
    }
    
    private function runPuppeteerScript($scriptPath)
    {
        $this->info('3. Running browser automation...');
        
        // 改變到腳本目錄
        $workingDir = dirname($scriptPath);
        
        $result = Process::path($workingDir)->run("node " . basename($scriptPath));
        
        $this->line(""); // 空行
        $this->line("📋 Browser Output:");
        $this->line($result->output());
        
        if ($result->failed()) {
            $this->error("❌ Browser automation failed");
            $this->line("Error: " . $result->errorOutput());
            return null;
        }
        
        // 讀取結果文件
        $resultFile = $workingDir . '/scraped_result.json';
        
        if (file_exists($resultFile)) {
            $content = file_get_contents($resultFile);
            return json_decode($content, true);
        }
        
        $this->error("❌ No result file found");
        return null;
    }
    
    private function processScrapedData($result, $outputFormat)
    {
        $this->info('4. Processing scraped data...');
        
        if (!$result['success']) {
            $this->error("❌ Scraping failed: " . ($result['error'] ?? 'Unknown error'));
            return;
        }
        
        $extractedData = $result['extractedData'];
        $networkRequests = $result['networkRequests'] ?? [];
        
        // 分析結果
        $this->info("📊 Analysis Results:");
        $this->info("   Tables found: " . count($extractedData['tables'] ?? []));
        $this->info("   Lists found: " . count($extractedData['lists'] ?? []));
        $this->info("   Data elements found: " . count($extractedData['dataElements'] ?? []));
        $this->info("   API calls captured: " . count($networkRequests));
        
        // 保存數據
        $timestamp = date('Y-m-d_H-i-s');
        
        // 保存完整結果
        $fullResultPath = storage_path("app/scraped_data/browser_scrape_full_{$timestamp}.json");
        Storage::put("scraped_data/browser_scrape_full_{$timestamp}.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // 如果找到表格數據，另外保存
        if (!empty($extractedData['tables'])) {
            foreach ($extractedData['tables'] as $index => $table) {
                if ($outputFormat === 'csv' && !empty($table['headers'])) {
                    $csvPath = storage_path("app/scraped_data/table_{$index}_{$timestamp}.csv");
                    $this->saveTableAsCsv($table, $csvPath);
                }
            }
        }
        
        // 如果捕獲到 API 數據，保存
        if (!empty($networkRequests)) {
            Storage::put("scraped_data/api_calls_{$timestamp}.json", json_encode($networkRequests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("📡 API calls saved separately");
        }
        
        $this->info("💾 Full results saved to: {$fullResultPath}");
        
        // 移動截圖
        $screenshotSrc = storage_path('app/temp/scraped_page_screenshot.png');
        $screenshotDst = storage_path("app/scraped_data/screenshot_{$timestamp}.png");
        
        if (file_exists($screenshotSrc)) {
            rename($screenshotSrc, $screenshotDst);
            $this->info("📸 Screenshot saved to: {$screenshotDst}");
        }
        
        $this->info("✅ Data processing completed!");
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
        
        $this->info("📋 Table saved as CSV: {$filepath}");
    }
}