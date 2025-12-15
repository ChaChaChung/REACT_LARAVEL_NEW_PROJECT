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
     * 執行方式：php artisan agent:scrape-dom {url} {--selector=} {--wait-for=}
     * {url} - 要爬取的目標網址（必需參數）
     * {--selector=} - 可選的 CSS 選擇器，用於指定要提取的元素
     * {--wait-for=} - 可選的選擇器，等待該元素出現後才開始提取
     */
    protected $signature = 'agent:scrape-dom {url} {--selector=} {--wait-for=}';

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
        $selector = $this->option('selector');
        $waitFor = $this->option('wait-for');

        $this->info('=== Browser DOM Scraper ===');
        $this->info("Target URL: {$url}");
        if ($selector) {
            $this->info("Target Selector: {$selector}");
        }
        if ($waitFor) {
            $this->info("Wait For Selector: {$waitFor}");
        }
        
        // 檢查 Node.js 是否安裝
        if (!$this->checkNodeJs()) {
            return 1;
        }

        // 創建 Puppeteer 腳本
        $scriptPath = $this->createPuppeteerScript($url, $selector, $waitFor);

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
     * @param string|null $selector 可選的 CSS 選擇器
     * @param string|null $waitFor 可選的等待選擇器
     * @return string 返回生成的腳本文件路徑
     */
    private function createPuppeteerScript($url, $selector = null, $waitFor = null)
    {
        $this->info('2. Creating browser automation script...');

        // 從 .env 環境變數獲取認證相關的 cookie 值
        $auth = env('AGENT_AUTH', '');
        $token = env('AGENT_TOKEN', '');
        $bgLang = env('AGENT_BG_LANGUAGE_KEY', 'zh-cn');

        // 將選擇器轉義，以便在 JavaScript 中使用
        $selectorJs = $selector ? json_encode($selector) : 'null';
        $waitForJs = $waitFor ? json_encode($waitFor) : 'null';

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

                    // 如果指定了等待選擇器，等待該元素出現
                    const waitForSelector = $waitForJs;
                    if (waitForSelector) {
                        console.log('⏳ Waiting for selector:', waitForSelector);
                        try {
                            await page.waitForSelector(waitForSelector, { timeout: 10000 });
                            console.log('✅ Element found:', waitForSelector);
                        } catch (e) {
                            console.log('⚠️  Selector not found:', waitForSelector);
                        }
                    }

                    // 等待額外時間，確保動態內容完全載入
                    await new Promise(resolve => setTimeout(resolve, 3000));

                    console.log('📄 Extracting DOM content...');

                    // 使用 page.evaluate() 在瀏覽器環境中執行 JavaScript 來提取 DOM 數據
                    const domData = await page.evaluate((targetSelector) => {
                        const result = {
                            // 基本頁面信息
                            pageInfo: {
                                title: document.title,
                                url: window.location.href,
                                description: document.querySelector('meta[name="description"]')?.content || null,
                                keywords: document.querySelector('meta[name="keywords"]')?.content || null,
                            },
                            
                            // 提取所有文本內容（可選：只提取特定選擇器）
                            textContent: targetSelector 
                                ? (() => {
                                    const element = document.querySelector(targetSelector);
                                    return element ? element.textContent.trim() : null;
                                })()
                                : document.body.innerText.trim(),
                            
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
                            
                            // 提取所有表格數據
                            tables: Array.from(document.querySelectorAll('table')).map((table, index) => {
                                const rows = Array.from(table.querySelectorAll('tr'));
                                return {
                                    index: index,
                                    headers: rows[0] ? Array.from(rows[0].querySelectorAll('th, td')).map(cell => cell.textContent.trim()) : [],
                                    rows: rows.slice(1).map(row => 
                                        Array.from(row.querySelectorAll('td')).map(cell => cell.textContent.trim())
                                    )
                                };
                            }),
                            
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
                            
                            // 提取特定選擇器的數據（如果提供了選擇器）
                            selectedElements: targetSelector ? (() => {
                                const elements = document.querySelectorAll(targetSelector);
                                return Array.from(elements).map((el, index) => ({
                                    index: index,
                                    tagName: el.tagName.toLowerCase(),
                                    textContent: el.textContent.trim(),
                                    innerHTML: el.innerHTML,
                                    attributes: Array.from(el.attributes).reduce((acc, attr) => {
                                        acc[attr.name] = attr.value;
                                        return acc;
                                    }, {}),
                                    // 提取子元素
                                    children: Array.from(el.children).map(child => ({
                                        tagName: child.tagName.toLowerCase(),
                                        textContent: child.textContent.trim(),
                                        className: child.className || null,
                                        id: child.id || null
                                    }))
                                }));
                            })() : null,
                            
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
                    }, $selectorJs);

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
                        selector: $selectorJs,
                        waitFor: $waitForJs,
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

        // 生成時間戳
        $timestamp = date('Y-m-d_H-i-s');

        // 保存完整結果
        $fullResultPath = storage_path("app/scraped_data/dom_scrape_full_{$timestamp}.json");
        Storage::put("scraped_data/dom_scrape_full_{$timestamp}.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 如果提取了特定選擇器的數據，單獨保存
        if (!empty($domData['selectedElements'])) {
            Storage::put("scraped_data/dom_selected_elements_{$timestamp}.json", json_encode($domData['selectedElements'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("📌 Selected elements saved separately");
        }

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

        // 保存表格數據
        if (!empty($domData['tables'])) {
            Storage::put("scraped_data/dom_tables_{$timestamp}.json", json_encode($domData['tables'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

