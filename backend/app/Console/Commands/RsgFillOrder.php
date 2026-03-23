<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;
use DateTime;
use DateInterval;
use DatePeriod;

class RsgFillOrder extends Command
{
    protected $signature = 'rsg:fill-order {site} {start_date} {end_date?}';
    protected $description = '下載 FTP 檔案至指定目錄 /Users/chacha/Downloads/RSG';

    private $excludeDirs = ['report_daily', '.', '..'];
    private $ftpConn = null;
    // 設定基礎下載路徑
    private $baseDownloadPath = '/Users/chacha/Downloads/RSG';

    public function handle()
    {
        set_time_limit(0);
        $site = strtoupper($this->argument('site'));
        $startDateStr = $this->argument('start_date');
        $endDateStr = $this->argument('end_date') ?: $startDateStr;

        $this->writeLog("========================================");
        $this->writeLog("[$site] 任務啟動...");

        $config = [
            'host' => env("FTP_{$site}_HOST"),
            'user' => env("FTP_{$site}_USER"),
            'pass' => env("FTP_{$site}_PASS"),
        ];

        if (!$this->connectFtp($config)) return;

        // 1. 取得第一層目錄 (例如 history_slot)
        $rootFolders = ftp_nlist($this->ftpConn, ".");
        if (!$rootFolders) {
            $this->writeLog("無法讀取根目錄", 'error');
            return;
        }

        try {
            $start = new DateTime($startDateStr);
            $end = (new DateTime($endDateStr))->modify('+1 day');
            $dateRange = new DatePeriod($start, new DateInterval('P1D'), $end);

            foreach ($dateRange as $date) {
                $targetDateFolder = $date->format('Ymd');
                $this->info(">>> 正在處理日期目錄: $targetDateFolder");

                foreach ($rootFolders as $rootFolder) {
                    $rootFolderName = basename($rootFolder);
                    if (in_array($rootFolderName, $this->excludeDirs)) continue;

                    // 進入第一層 (history_slot)
                    if (@ftp_chdir($this->ftpConn, "/{$rootFolderName}")) {
                        // 進入第二層 (日期資料夾 20260322)
                        if (@ftp_chdir($this->ftpConn, $targetDateFolder)) {
                            $this->downloadAllFilesFromCurrentDir($site, $rootFolderName, $targetDateFolder);
                        }
                        @ftp_chdir($this->ftpConn, "/"); 
                    }
                }
            }
        } catch (Exception $e) {
            $this->writeLog("執行出錯: " . $e->getMessage(), 'error');
        }

        $this->closeFtp();
        $this->writeLog("[$site] 任務結束");
    }

    private function downloadAllFilesFromCurrentDir($site, $rootFolder, $dateFolder)
    {
        $files = ftp_nlist($this->ftpConn, ".");
        if (!$files) return;

        foreach ($files as $file) {
            $fileName = basename($file);
            if ($fileName == '.' || $fileName == '..') continue;

            // 依照要求的路徑結構建構：/Users/chacha/Downloads/RSG/{site}/{dateFolder}/{rootFolder}
            $localPath = "{$this->baseDownloadPath}/{$site}/{$dateFolder}/{$rootFolder}";
            if (!is_dir($localPath)) {
                mkdir($localPath, 0755, true);
            }

            $localFile = "{$localPath}/{$fileName}";

            $this->info("發現檔案，開始下載: $fileName");
            ftp_pasv($this->ftpConn, true);
            if (@ftp_get($this->ftpConn, $localFile, $fileName, FTP_BINARY)) {
                $this->writeLog("成功存至: {$localFile}");
            } else {
                $this->writeLog("下載失敗: $fileName", 'warning');
            }
        }
    }

    private function connectFtp($config)
    {
        $this->ftpConn = ftp_connect($config['host'], 21, 20);
        if ($this->ftpConn && @ftp_login($this->ftpConn, $config['user'], $config['pass'])) {
            ftp_pasv($this->ftpConn, true);
            return true;
        }
        $this->writeLog("FTP 登入失敗", 'error');
        return false;
    }

    private function closeFtp()
    {
        if ($this->ftpConn) @ftp_close($this->ftpConn);
    }

    private function writeLog($message, $level = 'info')
    {
        $this->info("[RSG-FTP] $message");
        Log::$level("[RSG-FTP] $message");
    }
}