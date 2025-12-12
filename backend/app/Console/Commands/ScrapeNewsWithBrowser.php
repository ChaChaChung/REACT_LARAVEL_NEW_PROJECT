<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;
use App\Models\News;

class ScrapeNewsWithBrowser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:news-browser';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-fill form data using headless browser';

    private $url;
    
    // 要填入的數據
    private $formData = [
        'username' => 'uat_FQgameFG',
        'password' => 'Aa123456',
        // 添加其他欄位...
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->url = 'https://agent2.chichengwld.com/#/login?redirect=/dashboard';

        $this->info("開始自動填寫表單：{$this->url}");

        try {
            // 檢測運行環境並配置相應的路徑
            $isDocker = $this->isRunningInDocker();
            $this->info($isDocker ? "檢測到 Docker 環境" : "檢測到宿主機環境");

            // 方法一：使用 Browsershot 的 evaluate 執行 JavaScript
            $this->info("開始自動填寫表單...");

            $browsershot = Browsershot::url($this->url)
                ->waitUntilNetworkIdle()
                ->setDelay(3000);  // 等待 3 秒讓頁面加載

            // 根據環境配置不同的路徑
            if ($isDocker) {
                // Docker 環境配置
                $browsershot
                    ->setChromePath('/usr/bin/chromium')
                    ->setNodeBinary('/usr/bin/node')
                    ->setNpmBinary('/usr/bin/npm')
                    ->noSandbox()
                    ->setOption('args', ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']);
                $this->info("使用 Docker 環境配置");
            } else {
                // macOS 宿主機環境配置
                // 自動檢測 Node.js 路徑
                $nodePath = trim(shell_exec('which node') ?? '');
                $npmPath = trim(shell_exec('which npm') ?? '');
                
                if ($nodePath && $npmPath) {
                    $browsershot
                        ->setNodeBinary($nodePath)
                        ->setNpmBinary($npmPath);
                    $this->info("Node: {$nodePath}, npm: {$npmPath}");
                }
                
                // macOS 上的 Chrome 路徑
                $chromePaths = [
                    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
                    '/Applications/Chromium.app/Contents/MacOS/Chromium',
                    trim(shell_exec('which chromium') ?? ''),
                    trim(shell_exec('which google-chrome') ?? ''),
                ];
                
                foreach ($chromePaths as $path) {
                    if ($path && file_exists($path)) {
                        $browsershot->setChromePath($path);
                        $this->info("使用 Chrome: {$path}");
                        break;
                    }
                }
            }

            $this->info("正在執行 JavaScript 填寫表單...");
            
            // 執行 JavaScript 並獲取詳細調試信息
            $jsResult = $browsershot->evaluate($this->getAutoFillScript());
            $this->info("=== JavaScript 執行過程 ===");
            $this->info($jsResult);
            
            // 獲取頁面詳細信息進行調試
            $this->debugPageInfo($browsershot);

            // 保存截圖以供檢查
            $this->info("正在截圖...");
            $screenshotPath = storage_path('logs/auto-fill-screenshot.png');
            $result = $browsershot->screenshot();
            file_put_contents($screenshotPath, $result);
            $this->info("截圖已保存到: {$screenshotPath}");

            $this->info("✓ 表單填寫完成！");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("填寫失敗：" . $e->getMessage());
            Log::error("填寫失敗：" . $e->getMessage());

            // 嘗試方法二：創建 Puppeteer 腳本
            $this->info("\n嘗試使用 Puppeteer 腳本...");
            return $this->useNodeScript();
        }
    }

    /**
     * 檢測是否在 Docker 容器內運行
     */
    private function isRunningInDocker()
    {
        // 方法 1: 檢查 /.dockerenv 文件
        if (file_exists('/.dockerenv')) {
            return true;
        }
        
        // 方法 2: 檢查 /proc/1/cgroup
        if (file_exists('/proc/1/cgroup')) {
            $cgroup = file_get_contents('/proc/1/cgroup');
            if (strpos($cgroup, 'docker') !== false || strpos($cgroup, 'containerd') !== false) {
                return true;
            }
        }
        
        // 方法 3: 檢查工作目錄
        if (strpos(getcwd(), '/var/www/html') === 0) {
            return true;
        }
        
        return false;
    }

    /**
     * 生成自動填寫的 JavaScript 代碼
     */
    private function getAutoFillScript()
    {
        $username = $this->formData['username'];
        $password = $this->formData['password'];
        Log::alert("username => $username, password => $password");

        return <<<JS
            (function() {
                    // 等待一下確保頁面完全加載
                    return new Promise((resolve) => {
                    let debugLog = [];
                    debugLog.push('🚀 JavaScript 開始執行...');
                    debugLog.push('🌍 當前 URL: ' + window.location.href);
                    debugLog.push('📄 頁面標題: ' + document.title);
                    
                    setTimeout(() => {
                        debugLog.push('⏰ 延遲3秒後開始查找元素...');
                        
                        // 方法1: 通過 class name 找到所有 input
                        let inputs = document.querySelectorAll('.el-input__inner');
                        debugLog.push('📝 找到 ' + inputs.length + ' 個 .el-input__inner 欄位');
                        
                        // 如果沒找到，嘗試其他常見的選擇器
                        if (inputs.length === 0) {
                            debugLog.push('⚠️  沒有找到 .el-input__inner，嘗試其他選擇器...');
                            
                            const allInputs = document.querySelectorAll('input');
                            debugLog.push('📝 找到 ' + allInputs.length + ' 個 input 元素');
                            
                            // 嘗試其他可能的選擇器
                            const inputTypes = document.querySelectorAll('input[type="text"], input[type="password"], input[type="email"]');
                            debugLog.push('📝 找到 ' + inputTypes.length + ' 個文本/密碼/郵箱輸入框');
                            
                            // 嘗試常見的 Vue/Element UI 選擇器
                            const elInput = document.querySelectorAll('.el-input input');
                            debugLog.push('📝 找到 ' + elInput.length + ' 個 .el-input input');
                            
                            const inputControls = document.querySelectorAll('input.form-control, input.ant-input, input[class*="input"]');
                            debugLog.push('📝 找到 ' + inputControls.length + ' 個其他框架輸入框');
                            
                            // 使用最適合的選擇器
                            if (elInput.length >= 2) {
                                inputs = elInput;
                                debugLog.push('✅ 使用 .el-input input 選擇器');
                            } else if (inputTypes.length >= 2) {
                                inputs = inputTypes;
                                debugLog.push('✅ 使用 input[type] 選擇器');
                            } else if (allInputs.length >= 2) {
                                inputs = allInputs;
                                debugLog.push('✅ 使用通用 input 選擇器');
                            }
                            
                            // 顯示所有input的詳細信息
                            allInputs.forEach((input, index) => {
                                debugLog.push('Input ' + index + ': type=' + input.type + ', name=' + input.name + ', id=' + input.id + ', class=' + input.className + ', placeholder=' + input.placeholder);
                            });
                        }

                        // 通常登錄頁面的第一個是用戶名，第二個是密碼
                        if (inputs.length >= 2) {
                            debugLog.push('✅ 開始填寫表單...');
                            
                            // 填入用戶名
                            inputs[0].value = '$username';
                            inputs[0].dispatchEvent(new Event('input', { bubbles: true }));
                            inputs[0].dispatchEvent(new Event('change', { bubbles: true }));
                            debugLog.push('✏️  已填入用戶名: $username');
                            
                            // 填入密碼
                            inputs[1].value = '$password';
                            inputs[1].dispatchEvent(new Event('input', { bubbles: true }));
                            inputs[1].dispatchEvent(new Event('change', { bubbles: true }));
                            debugLog.push('🔑 已填入密碼');
                        } else {
                            debugLog.push('❌ 找不到足夠的input欄位 (需要至少2個)');
                        }

                        // 方法2: 如果有特定的 placeholder 或 name 屬性
                        inputs.forEach((input, index) => {
                            const placeholder = input.getAttribute('placeholder') || '';
                            const name = input.getAttribute('name') || '';
                            
                            debugLog.push('Input ' + index + ': placeholder=' + placeholder + ', name=' + name);
                            
                            // 根據 placeholder 填入對應的值
                            if (placeholder.includes('account') || placeholder.includes('username') || name === 'username') {
                                input.value = '$username';
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                debugLog.push('📝 通過 placeholder/name 填入用戶名');
                            }
                            
                            if (placeholder.includes('password') || placeholder.includes('password') || name === 'password') {
                                input.value = '$password';
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                                debugLog.push('📝 通過 placeholder/name 填入密碼');
                            }
                        });

                        // 尋找並點擊提交按鈕（可選）
                        const submitBtn = document.querySelector('button[type="button"], .el-button--primary, button.login-btn');
                        if (submitBtn) {
                            debugLog.push('🔍 找到提交按鈕，準備點擊...');
                            // submitBtn.click(); // 取消註解以自動點擊提交
                        } else {
                            debugLog.push('⚠️  未找到提交按鈕');
                        }

                        debugLog.push('🎉 JavaScript 執行完成');
                        debugLog.push('📊 最終使用了 ' + `inputs`.length + ' 個輸入欄位');
                        
                        // 返回所有調試信息
                        resolve(debugLog.join('\\n'));
                    }, 3000);
                });
            })();
        JS;
    }

    /**
     * 使用 Node.js Puppeteer 腳本（更強大的方法）
     */
    private function useNodeScript()
    {
        $scriptPath = storage_path('app/auto-fill.js');
        
        // 創建 Puppeteer 腳本
        $script = $this->generatePuppeteerScript();
        file_put_contents($scriptPath, $script);
        
        $this->info("Puppeteer 腳本已創建: {$scriptPath}");
        $this->info("請運行: node {$scriptPath}");
        
        return Command::SUCCESS;
    }

    /**
     * 生成完整的 Puppeteer 腳本
     */
    private function generatePuppeteerScript()
    {
        $username = $this->formData['username'];
        $password = $this->formData['password'];
        $url = $this->url;

        return <<<JS
            const puppeteer = require('puppeteer');

            (async () => {
                console.log('啟動瀏覽器...');
                const browser = await puppeteer.launch({
                    headless: false, // 改為 true 可以隱藏瀏覽器
                    slowMo: 100 // 放慢操作速度，方便觀察
                });

                const page = await browser.newPage();
                
                console.log('訪問頁面: $url');
                await page.goto('$url', { waitUntil: 'networkidle2' });

                // 等待 input 欄位出現
                console.log('等待 input 欄位...');
                await page.waitForSelector('.el-input__inner', { timeout: 5000 });

                // 獲取所有 input 欄位
                const inputs = await page.$$('.el-input__inner');
                console.log('找到 ' + inputs.length + ' 個 input 欄位');

                if (inputs.length >= 2) {
                    // 填入用戶名
                    console.log('填入用戶名...');
                    await inputs[0].type('$username', { delay: 100 });

                    // 填入密碼
                    console.log('填入密碼...');
                    await inputs[1].type('$password', { delay: 100 });

                    console.log('✓ 填寫完成！');

                    // 截圖保存
                    await page.screenshot({ path: 'storage/logs/filled-form.png' });
                    console.log('截圖已保存');

                    // 可選：自動點擊提交按鈕
                    // const submitBtn = await page.$('button[type="submit"]');
                    // if (submitBtn) {
                    //     console.log('點擊提交按鈕...');
                    //     await submitBtn.click();
                    //     await page.waitForNavigation({ waitUntil: 'networkidle2' });
                    //     console.log('✓ 表單已提交！');
                    // }
                }

                // 等待 5 秒以便觀察結果
                await new Promise(resolve => setTimeout(resolve, 5000));

                console.log('關閉瀏覽器');
                await browser.close();
            })();
        JS;
    }

    /**
     * 調試頁面信息
     */
    private function debugPageInfo($browsershot)
    {
        try {
            // 獲取頁面標題
            $title = $browsershot->evaluate('document.title');
            $this->info("📄 頁面標題: " . $title);
            
            // 獲取當前URL
            $url = $browsershot->evaluate('window.location.href');
            $this->info("🌍 當前URL: " . $url);
            
            // 檢查頁面是否完全載入
            $readyState = $browsershot->evaluate('document.readyState');
            $this->info("📊 頁面狀態: " . $readyState);
            
            // 統計頁面元素
            $inputCount = $browsershot->evaluate('document.querySelectorAll("input").length');
            $this->info("📝 總輸入框數量: " . $inputCount);
            
            $elInputCount = $browsershot->evaluate('document.querySelectorAll(".el-input__inner").length');
            $this->info("📝 .el-input__inner 數量: " . $elInputCount);
            
            // 獲取所有輸入框的詳細信息（返回JSON字符串）
            $inputDetails = $browsershot->evaluate('
                JSON.stringify(Array.from(document.querySelectorAll("input")).map((input, index) => ({
                    index: index,
                    type: input.type,
                    name: input.name,
                    id: input.id,
                    className: input.className,
                    placeholder: input.placeholder
                })))
            ');
            $this->info("🔍 輸入框詳情: " . $inputDetails);
            
        } catch (\Exception $e) {
            $this->error("調試信息獲取失敗: " . $e->getMessage());
        }
    }
}
