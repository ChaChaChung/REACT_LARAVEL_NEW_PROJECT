<?php

namespace App\Console\Commands;

use App\Services\AgentLoginService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SmartApiScraper extends Command
{
    protected $signature = 'agent:smart-scrape {--endpoint=} {--full-session}';
    protected $description = 'Smart API scraper with session establishment';

    public function handle(AgentLoginService $agentService)
    {
        $this->info('=== Smart API Scraper ===');
        
        // 1. 建立完整會話
        if ($this->option('full-session')) {
            $this->establishFullSession($agentService);
        }
        
        // 2. 嘗試發現的 API 端點
        $endpoint = $this->option('endpoint');
        if ($endpoint) {
            return $this->testSpecificEndpoint($agentService, $endpoint);
        }
        
        // 3. 系統性地測試所有發現的端點
        return $this->testAllDiscoveredEndpoints($agentService);
    }
    
    private function establishFullSession($agentService)
    {
        $this->info('🔄 Establishing full browser session...');
        
        $steps = [
            '/' => 'Home page',
            '/dashboard' => 'Dashboard',
            '/record' => 'Records section',
            '/record/chessRecord' => 'Chess records'
        ];
        
        foreach ($steps as $path => $description) {
            $this->line("   📄 Loading: {$description}");
            
            try {
                $response = $agentService->getPageContent($path);
                
                if ($response['success']) {
                    $this->line("      ✅ Success (HTTP {$response['status_code']})");
                    
                    // 查找可能的認證 tokens
                    $this->extractAuthTokens($response['body']);
                    
                    // 小延遲模擬人類瀏覽
                    sleep(1);
                } else {
                    $this->line("      ❌ Failed (HTTP {$response['status_code']})");
                }
                
            } catch (\Exception $e) {
                $this->line("      ❌ Error: " . $e->getMessage());
            }
        }
    }
    
    private function extractAuthTokens($html)
    {
        // 查找各種可能的認證 tokens
        $patterns = [
            'csrf_token' => '/csrf[_-]?token["\']?\s*[:=]\s*["\']([^"\']+)["\']/',
            'api_token' => '/api[_-]?token["\']?\s*[:=]\s*["\']([^"\']+)["\']/',
            'auth_token' => '/auth[_-]?token["\']?\s*[:=]\s*["\']([^"\']+)["\']/',
            'bearer_token' => '/bearer["\']?\s*[:=]\s*["\']([^"\']+)["\']/',
            'session_token' => '/session[_-]?token["\']?\s*[:=]\s*["\']([^"\']+)["\']/'
        ];
        
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $token = $matches[1];
                $this->line("      🔑 Found {$name}: " . substr($token, 0, 20) . "...");
                
                // 存儲 token 供後續使用
                $this->tokens[$name] = $token;
            }
        }
    }
    
    private function testSpecificEndpoint($agentService, $endpoint)
    {
        $this->info("🧪 Testing specific endpoint: {$endpoint}");
        
        $methods = [
            'GET' => 'GET request',
            'POST' => 'POST request'
        ];
        
        foreach ($methods as $method => $description) {
            $this->line("   Testing: {$description}");
            
            try {
                if ($method === 'GET') {
                    $response = $agentService->getPageContent($endpoint);
                } else {
                    $response = $agentService->postData($endpoint, []);
                }
                
                $this->analyzeResponse($response, $endpoint, $method);
                
            } catch (\Exception $e) {
                $this->warn("   ❌ Error: " . $e->getMessage());
            }
        }
        
        return 0;
    }
    
    private function testAllDiscoveredEndpoints($agentService)
    {
        $this->info('🔍 Testing all discovered API endpoints...');
        
        // 從瀏覽器自動化發現的端點
        $discoveredEndpoints = [
            '/api/platform/version',
            '/api/admin/info',
            '/api/sys/routes',
            '/api/public/checkIp',
            '/api/v1/dropMenu/getCurrencyList',
            '/api/v1/dropMenu/getGameList?gt_id=30',
            '/api/v1/dropMenu/getAgentList',
            '/api/v1/game/log?page=1&page_size=5&start_time=2025-12-11+00:00:00&end_time=2025-12-11+23:59:59&agent_id=8742&order_field=time&order_by=desc&gt_id=30'
        ];
        
        $successfulEndpoints = [];
        
        foreach ($discoveredEndpoints as $endpoint) {
            $this->line("\n📡 Testing: {$endpoint}");
            
            try {
                $response = $agentService->getPageContent($endpoint);
                $analysis = $this->analyzeResponse($response, $endpoint, 'GET');
                
                if ($analysis['is_successful']) {
                    $successfulEndpoints[] = [
                        'endpoint' => $endpoint,
                        'analysis' => $analysis
                    ];
                }
                
                // 防止請求過快
                usleep(500000); // 0.5 seconds
                
            } catch (\Exception $e) {
                $this->warn("   ❌ Error: " . $e->getMessage());
            }
        }
        
        // 總結結果
        $this->summarizeResults($successfulEndpoints);
        
        return 0;
    }
    
    private function analyzeResponse($response, $endpoint, $method)
    {
        $analysis = [
            'is_successful' => false,
            'status_code' => $response['status_code'],
            'content_type' => 'unknown',
            'data_type' => 'unknown',
            'message' => ''
        ];
        
        if (!$response['success']) {
            $this->warn("   ❌ Failed (HTTP {$response['status_code']})");
            $analysis['message'] = "HTTP {$response['status_code']}";
            return $analysis;
        }
        
        // 嘗試解析 JSON
        $data = json_decode($response['body'], true);
        
        if ($data) {
            $analysis['content_type'] = 'json';
            
            if (isset($data['code'])) {
                $code = $data['code'];
                $message = $data['message'] ?? 'No message';
                
                if ($code === 200 || $code === 0) {
                    $this->info("   ✅ Success: {$message}");
                    $analysis['is_successful'] = true;
                    $analysis['data_type'] = 'api_success';
                    
                    if (isset($data['data'])) {
                        $dataCount = is_array($data['data']) ? count($data['data']) : 1;
                        $this->line("      📊 Data items: {$dataCount}");
                    }
                } else {
                    $this->warn("   ❌ API Error ({$code}): {$message}");
                    $analysis['message'] = $message;
                }
            } else {
                $this->info("   ✅ JSON response (unknown format)");
                $analysis['is_successful'] = true;
                $analysis['data_type'] = 'json_unknown';
            }
        } else {
            // 非 JSON 回應
            $size = strlen($response['body']);
            $preview = substr($response['body'], 0, 100);
            
            $this->line("   📄 Non-JSON response ({$this->formatBytes($size)})");
            $this->line("      Preview: " . substr($preview, 0, 50) . "...");
            
            $analysis['content_type'] = 'html_or_text';
            $analysis['is_successful'] = $size > 100; // 假設有內容就算成功
        }
        
        return $analysis;
    }
    
    private function summarizeResults($successfulEndpoints)
    {
        $this->line("\n" . str_repeat("=", 50));
        $this->info("📊 SUMMARY OF RESULTS");
        $this->line(str_repeat("=", 50));
        
        if (empty($successfulEndpoints)) {
            $this->error("❌ No successful API endpoints found.");
            $this->line("\n💡 Recommendations:");
            $this->line("1. Check if cookies need refreshing");
            $this->line("2. Try manual browser analysis");
            $this->line("3. Look for additional authentication requirements");
            return;
        }
        
        $this->info("✅ Found " . count($successfulEndpoints) . " working endpoints:");
        
        foreach ($successfulEndpoints as $item) {
            $endpoint = $item['endpoint'];
            $analysis = $item['analysis'];
            
            $this->line("\n🔗 {$endpoint}");
            $this->line("   Status: ✅ Working");
            $this->line("   Type: {$analysis['data_type']}");
            
            if ($analysis['data_type'] === 'api_success') {
                $this->info("   💡 This endpoint can be used for scraping!");
            }
        }
        
        $this->line("\n🚀 Next Steps:");
        $this->line("1. Use successful endpoints with: php artisan agent:test-endpoint");
        $this->line("2. Build custom scrapers for working endpoints");
        $this->line("3. Set up scheduled data collection");
    }
    
    private function formatBytes($size)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $size >= 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
    
    private $tokens = [];
}