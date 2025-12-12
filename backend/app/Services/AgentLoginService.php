<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class AgentLoginService extends Service
{
    private $baseUrl = 'https://agent2.chichengwld.com/';
    private $cookies = [];
    
    public function __construct() 
    {
        // 從配置文件中獲取 cookie 信息
        $this->cookies = [
            'auth' => config('chacha.agent.auth'),
            'bg_languageKey' => config('chacha.agent.bg_language_key'), 
            'token' => config('chacha.agent.token')
        ];
    }
    
    /**
     * 發送帶有 cookie 的請求
     * @param string $endpoint
     * @param string $method
     * @param array|string|null $data
     * @param array $customHeaders
     * @return array
     * @throws Exception
     */
    public function makeRequest($endpoint = '', $method = 'GET', $data = null, $customHeaders = [])
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        
        $ch = curl_init();
        
        // 基本 cURL 設置
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '', // 自動處理壓縮
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]);
        
        // 設置 cookie
        $cookieString = $this->buildCookieString();
        curl_setopt($ch, CURLOPT_COOKIE, $cookieString);
        
        // 設置請求頭部
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Referer: ' . $this->baseUrl
        ];
        
        // 添加自定義標頭
        foreach ($customHeaders as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        
        // 如果是 POST 請求
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                if (is_array($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
            }
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            Log::error('AgentLogin cURL Error', ['error' => $error, 'url' => $url]);
            throw new Exception('cURL Error: ' . $error);
        }
        
        // 處理字符編碼問題
        $cleanResponse = $this->cleanResponseEncoding($response);
        
        // 記錄請求日誌
        Log::info('AgentLogin API Request', [
            'url' => $url,
            'method' => $method,
            'status_code' => $httpCode,
            'response_length' => strlen($response),
            'clean_response_length' => strlen($cleanResponse)
        ]);
        
        return [
            'status_code' => $httpCode,
            'body' => $cleanResponse,
            'success' => $httpCode >= 200 && $httpCode < 300
        ];
    }
    
    /**
     * 建構 cookie 字串
     * @return string
     */
    private function buildCookieString()
    {
        $cookieParts = [];
        foreach ($this->cookies as $name => $value) {
            if (!empty($value)) {
                $cookieParts[] = $name . '=' . $value;
            }
        }
        return implode('; ', $cookieParts);
    }
    
    /**
     * 驗證登入狀態
     * @return bool
     */
    public function checkLoginStatus()
    {
        try {
            $response = $this->makeRequest();
            
            // 檢查回應中是否包含登入後才能看到的內容
            if (strpos($response['body'], 'logout') !== false || 
                strpos($response['body'], '登出') !== false ||
                strpos($response['body'], 'dashboard') !== false) {
                Log::info('AgentLogin: Login status verified - logged in');
                return true;
            }
            
            // 如果被重定向到登入頁面，表示 cookie 已失效
            if (strpos($response['body'], 'login') !== false && 
                strpos($response['body'], 'password') !== false) {
                Log::warning('AgentLogin: Login status check - redirected to login page');
                return false;
            }
            
            Log::info('AgentLogin: Login status check', ['success' => $response['success']]);
            return $response['success'];
        } catch (Exception $e) {
            Log::error('AgentLogin: Login check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * 獲取特定頁面內容
     * @param string $endpoint
     * @return array
     */
    public function getPageContent($endpoint)
    {
        return $this->makeRequest($endpoint);
    }
    
    /**
     * 更新 cookies（當獲得新的認證信息時）
     * @param array $newCookies
     * @return void
     */
    public function updateCookies($newCookies)
    {
        $this->cookies = array_merge($this->cookies, $newCookies);
        Log::info('AgentLogin: Cookies updated', ['cookie_names' => array_keys($newCookies)]);
    }

    /**
     * 獲取當前使用的 cookies
     * @return array
     */
    public function getCookies()
    {
        return $this->cookies;
    }
    
    /**
     * 清理回應內容的編碼問題
     * @param string $response
     * @return string
     */
    private function cleanResponseEncoding($response)
    {
        if (empty($response)) {
            return $response;
        }
        
        try {
            // 1. 檢測編碼
            $encoding = mb_detect_encoding($response, ['UTF-8', 'BIG5', 'GB2312', 'GBK', 'ISO-8859-1'], true);
            
            if ($encoding === false) {
                Log::warning('AgentLogin: Could not detect encoding, using UTF-8');
                $encoding = 'UTF-8';
            }
            
            // 2. 轉換為 UTF-8（如果不是 UTF-8）
            if ($encoding !== 'UTF-8') {
                Log::info('AgentLogin: Converting encoding from ' . $encoding . ' to UTF-8');
                $response = mb_convert_encoding($response, 'UTF-8', $encoding);
            }
            
            // 3. 移除或替換無效的 UTF-8 字符
            $response = mb_convert_encoding($response, 'UTF-8', 'UTF-8');
            
            // 4. 移除控制字符（除了換行和 tab）
            $response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $response);
            
            // 5. 確保字符串對 JSON 編碼安全
            if (!mb_check_encoding($response, 'UTF-8')) {
                Log::warning('AgentLogin: Response contains invalid UTF-8, applying fallback cleaning');
                $response = utf8_encode(utf8_decode($response));
            }
            
            return $response;
            
        } catch (Exception $e) {
            Log::error('AgentLogin: Error cleaning response encoding', ['error' => $e->getMessage()]);
            
            // 最後的備用方案：移除所有非 ASCII 字符
            return preg_replace('/[^\x20-\x7E\r\n\t]/', '?', $response);
        }
    }
}