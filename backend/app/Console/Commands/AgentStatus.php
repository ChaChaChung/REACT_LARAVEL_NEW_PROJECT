<?php

namespace App\Console\Commands;

use App\Services\AgentLoginService;
use Illuminate\Console\Command;
use Exception;

class AgentStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check Agent login status and cookie validity';

    /**
     * Execute the console command.
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

    private function checkEnvironmentVariables()
    {
        $this->info('1. Checking environment configuration...');
        
        $requiredEnvs = [
            'AGENT_AUTH' => env('AGENT_AUTH'),
            'AGENT_PHPSESSID' => env('AGENT_PHPSESSID'),
            'AGENT_TOKEN' => env('AGENT_TOKEN'),
            'AGENT_BG_LANGUAGE_KEY' => env('AGENT_BG_LANGUAGE_KEY', 'zh-cn')
        ];
        
        $allConfigured = true;
        
        foreach ($requiredEnvs as $key => $value) {
            if (empty($value) && $key !== 'AGENT_BG_LANGUAGE_KEY') {
                $this->error("   ❌ {$key} is not configured");
                $allConfigured = false;
            } else {
                $length = strlen($value);
                $this->info("   ✅ {$key}: configured (length: {$length})");
            }
        }
        
        if (!$allConfigured) {
            $this->line('');
            $this->warn('⚠️  Missing configuration. Please add to your .env file:');
            $this->line('AGENT_AUTH=your_auth_value');
            $this->line('AGENT_PHPSESSID=your_phpsessid');
            $this->line('AGENT_TOKEN=your_token');
            $this->line('AGENT_BG_LANGUAGE_KEY=zh-cn');
            $this->line('');
        }
        
        return $allConfigured;
    }
    
    private function checkCookieConfiguration(AgentLoginService $agentService)
    {
        $this->info('2. Checking cookie configuration...');
        
        try {
            $cookies = $agentService->getCookies();
            
            foreach ($cookies as $name => $value) {
                if (!empty($value)) {
                    $this->info("   ✅ {$name}: " . substr($value, 0, 10) . '...');
                } else {
                    $this->warn("   ⚠️  {$name}: empty");
                }
            }
            
            return true;
        } catch (Exception $e) {
            $this->error("   ❌ Error checking cookies: " . $e->getMessage());
            return false;
        }
    }
    
    private function testConnection(AgentLoginService $agentService)
    {
        $this->info('3. Testing connection to Agent system...');
        
        try {
            // 測試基本連線
            $this->line('   Testing basic connectivity...');
            $response = $agentService->getPageContent('/');
            
            if ($response['success']) {
                $this->info("   ✅ Connection successful (HTTP {$response['status_code']})");
                $this->info("   📄 Response size: " . $this->formatBytes(strlen($response['body'])));
            } else {
                $this->error("   ❌ Connection failed (HTTP {$response['status_code']})");
                return false;
            }
            
            // 測試登入狀態
            $this->line('   Checking authentication status...');
            $isLoggedIn = $agentService->checkLoginStatus();
            
            if ($isLoggedIn) {
                $this->info('   ✅ Authentication successful - You are logged in!');
            } else {
                $this->error('   ❌ Authentication failed - Please check your cookies');
                return false;
            }
        } catch (Exception $e) {
            $this->error('   ❌ Connection test failed: ' . $e->getMessage());
            
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
    
    private function formatBytes($size)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
}