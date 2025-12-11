<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class commonHelper
{
    /**
     * 取得 IP
     * @return string|null
     */
    public static function getIP()
    {
        $connect_ip = "";

        if (!empty($_SERVER["HTTP_CLIENT_IP"])) {
            $connect_ip = $_SERVER["HTTP_CLIENT_IP"];
        } elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $connect_ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $connect_ip = $_SERVER["REMOTE_ADDR"];
        }

        // 過濾 IPv4
        $ipv4 = self::filterIPv4($connect_ip);

        // 如果找不到 IPv4，就回傳原始 IP（可能是 IPv6）
        return $ipv4 ?: $connect_ip;
    }

    /**
     * 過濾 IPv4
     * @param string $ip IP 位址
     * @return string|null
     */
    private static function filterIPv4($ip)
    {
        $ip_array = explode(',', $ip);
        foreach ($ip_array as $single_ip) {
            $single_ip = trim($single_ip);
            if (filter_var($single_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $single_ip;
            }
        }
        // 找不到 IPv4
        return null;
    }
}
