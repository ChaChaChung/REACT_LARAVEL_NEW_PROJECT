<?php

namespace App\Console\Commands;

use App\Services\AgentLoginService;
use Illuminate\Console\Command;
use Exception;

/**
 * Agent 狀態檢查命令
 */
class AgentStatus extends Command
{
    /**
     * 命令簽名
     * 執行方式：php artisan agent:status
     * @var string
     */
    protected $signature = 'agent:status';

    /**
     * 命令描述
     * @var string
     */
    protected $description = 'Check Agent login status and cookie validity';

    /**
     * 執行命令的主要處理方法
     * @param AgentLoginService $agentService Agent 登入服務實例（由 Laravel 自動注入）
     * @return int 返回 0 表示執行成功
     */
    public function handle(AgentLoginService $agentService)
    {
        $this->info('=== Agent Status Check ===');

        // 1. 檢查環境變數配置
        $this->checkEnvironmentVariables();

        // 2. 檢查 Cookie 配置
        $this->checkCookieConfiguration($agentService);

        // 3. 測試連線
        $this->testConnection($agentService);

        return 0;
    }

    /**
     * 檢查環境變數配置
     * @return bool 如果所有必要的環境變數都已配置則返回 true，否則返回 false
     */
    private function checkEnvironmentVariables()
    {
        $this->info('1. Checking environment configuration...');

        // 定義需要檢查的環境變數
        $requiredEnvs = [
            'AGENT_AUTH' => env('AGENT_AUTH'),
            'AGENT_TOKEN' => env('AGENT_TOKEN'),
            'AGENT_BG_LANGUAGE_KEY' => env('AGENT_BG_LANGUAGE_KEY')
        ];

        // 環境變數是否配置旗標
        $allConfigured = true;

        // 逐一檢查每個環境變數
        foreach ($requiredEnvs as $key => $value) {
            // 判斷環境變數是否為空
            if (empty($value)) {
                $this->error("   ❌ {$key} is not configured");
                $allConfigured = false;
            }
        }

        return $allConfigured;
    }

    /**
     * 檢查 Cookie 配置
     * @param AgentLoginService $agentService Agent 登入服務實例
     * @return bool 如果 Cookie 檢查成功則返回 true，否則返回 false
     */
    private function checkCookieConfiguration(AgentLoginService $agentService)
    {
        $this->info('2. Checking cookie configuration...');

        try {
            // 從服務中取得所有 Cookie
            $cookies = $agentService->getCookies();

            // 逐一檢查每個 Cookie
            foreach ($cookies as $name => $value) {
                if (empty($value)) {
                    // Cookie 為空時顯示警告
                    $this->warn("   ⚠️  {$name}: empty");
                }
            }

            return true;
        } catch (Exception $e) {
            // 如果取得 Cookie 時發生錯誤，顯示錯誤訊息
            $this->error("   ❌ Error checking cookies: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 測試與 Agent 系統的連線
     * @param AgentLoginService $agentService Agent 登入服務實例
     * @return bool 如果連線和認證都成功則返回 true，否則返回 false
     */
    private function testConnection(AgentLoginService $agentService)
    {
        $this->info('3. Testing connection to Agent system...');

        try {
            // 測試基本連線：嘗試取得首頁內容
            $this->line('   Testing basic connectivity...');
            $response = $agentService->getPageContent('/');

            if ($response['success']) {
                // 連線成功，顯示 HTTP 狀態碼和回應大小
                $this->info("   ✅ Connection successful (HTTP {$response['status_code']})");
            } else {
                // 連線失敗，顯示錯誤訊息
                $this->error("   ❌ Connection failed (HTTP {$response['status_code']})");
                return false;
            }

            // 測試登入狀態：檢查 Cookie 是否有效
            $this->line('   Checking authentication status...');
            $isLoggedIn = $agentService->checkLoginStatus();

            if ($isLoggedIn) {
                // 登入狀態有效
                $this->info('   ✅ Authentication successful - You are logged in!');
            } else {
                // 登入狀態無效，可能是 Cookie 過期或錯誤
                $this->error('   ❌ Authentication failed - Please check your cookies');
                return false;
            }
        } catch (Exception $e) {
            // 連線測試過程中發生異常
            $this->error('   ❌ Connection test failed: ' . $e->getMessage());

            // 顯示常見問題和解決方案
            $this->line('');
            $this->warn('💡 Common issues and solutions:');
            $this->line('• Network connectivity: Check internet connection');
            $this->line('• Firewall/Proxy: Ensure access to agent2.chichengwld.com');
            $this->line('• SSL issues: Check certificate validation');
            $this->line('• Rate limiting: Wait a moment and try again');

            return false;
        }

        return true;
    }
}