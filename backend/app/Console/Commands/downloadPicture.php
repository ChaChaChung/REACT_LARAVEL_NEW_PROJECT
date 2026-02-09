<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class DownloadPicture extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'download:pictures';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '下載 $imageUrls 中的圖片並打包成 ZIP 檔案';

    /**
     * 直接在這裡填寫圖片網址
     * 當使用 --source=array 或沒有指定來源時會使用這個陣列
     *
     * @var array
     */
    protected $imageUrls = [
        'https://img.dyn123.com/images/slot-images/galaxsys/knifedrop.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/olympianlegends.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/funnyfaces.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/eidorado.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/plinkodice.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/playme.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/crash.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/rocketon.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/keno101minute.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/blackJack.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/keno102minute.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/keno81minute.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/keno82minute.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/kenoexpress.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/penalty.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/sicbo.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/hilo.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/goldenra.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/mrthimble.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/crasher.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/ninjacrash.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/plinkoman.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/junglewheel.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/roulettex.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/coinflip.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/scratchmmap.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/magicdice.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/totem.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/fmines.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/turbomines.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/bingostar.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/maestro.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/atlantis.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/limbocrash.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/towerrush.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/hotgear.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/lottoboom.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/drshocker.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/starlight.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/cosmosaga.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/hamstermania.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/slapshot.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/ninjacrash500.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/figoal.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/gatesofasgard.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/fruitywildsjackpot777.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/chickencrash.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/guardiansofglory.png',
        'https://img.dyn123.com/images/slot-images/galaxsys/cashshow.png'
    ];

    /**
     * 自訂圖片檔名（選填）
     * 如果有指定，會使用這裡的檔名，否則自動命名為 image_0001.png
     * 注意：陣列索引要和 $imageUrls 對應
     *
     * @var array
     */
    protected $imageNames = [
        'galaxsys_KnifeDrop.png',
        'galaxsys_OlympianLegends.png',
        'galaxsys_FunnyFaces.png',
        'galaxsys_ElDorado.png',
        'galaxsys_PlinkoDice.png',
        'galaxsys_Playme.png',
        'galaxsys_Crash.png',
        'galaxsys_Rocketon.png',
        'galaxsys_Keno101Minute.png',
        'galaxsys_BlackJack.png',
        'galaxsys_Keno102Minute.png',
        'galaxsys_Keno81Minute.png',
        'galaxsys_Keno82Minute.png',
        'galaxsys_KenoExpress.png',
        'galaxsys_Penalty.png',
        'galaxsys_SicBo.png',
        'galaxsys_Hilo.png',
        'galaxsys_GoldenRA.png',
        'galaxsys_MrThimble.png',
        'galaxsys_Crasher.png',
        'galaxsys_NinjaCrash.png',
        'galaxsys_Plinkoman.png',
        'galaxsys_JungleWheel.png',
        'galaxsys_RouletteX.png',
        'galaxsys_CoinFlip.png',
        'galaxsys_ScratchMap.png',
        'galaxsys_MagicDice.png',
        'galaxsys_Totem.png',
        'galaxsys_FMines.png',
        'galaxsys_TurboMines.png',
        'galaxsys_BingoStar.png',
        'galaxsys_Maestro.png',
        'galaxsys_Atlantis.png',
        'galaxsys_LimboCrash.png',
        'galaxsys_TowerRush.png',
        'galaxsys_HotGear.png',
        'galaxsys_LottoBoom.png',
        'galaxsys_DrShocker.png',
        'galaxsys_Starlight.png',
        'galaxsys_CosmoSaga.png',
        'galaxsys_HamsterMania.png',
        'galaxsys_SlapShot.png',
        'galaxsys_NinjaCrash500.png',
        'galaxsys_Figoal.png',
        'galaxsys_GatesofAsgard.png',
        'galaxsys_FruityWildsJackpot777.png',
        'galaxsys_ChickenCrash.png',
        'galaxsys_GuardiansOfGlory.png',
        'galaxsys_CashShow.png'
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tempDir = 'temp/downloads/' . time();
        $outputName = 'galaxsys_images_' . date('Ymd_His') . '.zip';

        // 使用類別中定義的圖片網址
        $urls = $this->imageUrls;

        if (empty($urls)) {
            $this->error('❌ $imageUrls 陣列是空的！');
            return Command::FAILURE;
        }

        $this->info("📦 準備下載 " . count($urls) . " 張圖片...");

        // 建立暫存目錄
        Storage::makeDirectory($tempDir);

        // 下載圖片
        $downloadedFiles = $this->downloadImages($urls, $tempDir);

        if (empty($downloadedFiles)) {
            $this->error('❌ 沒有成功下載任何圖片！');
            Storage::deleteDirectory($tempDir);
            return Command::FAILURE;
        }

        // 打包成 ZIP
        $zipPath = $this->createZipArchive($downloadedFiles, $tempDir, $outputName);

        if ($zipPath) {
            $this->info("✅ 成功！ZIP 檔案已建立：{$zipPath}");
            $this->info("📊 總共下載：" . count($downloadedFiles) . " / " . count($urls) . " 張圖片");
            
            // 清理暫存檔案
            $this->cleanupTempFiles($tempDir);
            
            return Command::SUCCESS;
        } else {
            $this->error('❌ ZIP 檔案建立失敗！');
            return Command::FAILURE;
        }
    }

    /**
     * 下載圖片
     */
    private function downloadImages($urls, $tempDir)
    {
        $downloadedFiles = [];
        $bar = $this->output->createProgressBar(count($urls));
        $bar->start();

        foreach ($urls as $index => $url) {
            try {
                // 使用 Laravel HTTP Client 下載
                $response = Http::timeout(30)
                    ->retry(3, 1000)
                    ->get($url);

                if ($response->successful()) {
                    // 檢查是否有自訂檔名
                    if (!empty($this->imageNames[$index])) {
                        // 使用自訂檔名
                        $filename = $this->imageNames[$index];
                    } else {
                        // 自動產生檔名
                        $ext = $this->getExtensionFromUrl($url, $response->header('Content-Type'));
                        $filename = 'image_' . str_pad($index + 1, 4, '0', STR_PAD_LEFT) . '.' . $ext;
                    }
                    
                    $filepath = $tempDir . '/' . $filename;

                    // 儲存檔案
                    Storage::put($filepath, $response->body());
                    $downloadedFiles[] = $filepath;
                } else {
                    $this->newLine();
                    $this->warn("⚠️  下載失敗 [{$response->status()}]：{$url}");
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ 錯誤：{$url} - {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        return $downloadedFiles;
    }

    /**
     * 從網址或 Content-Type 取得副檔名
     */
    private function getExtensionFromUrl($url, $contentType = null)
    {
        // 先從網址取得副檔名
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        
        if ($ext && in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'])) {
            return strtolower($ext);
        }

        // 從 Content-Type 判斷
        if ($contentType) {
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/bmp' => 'bmp',
            ];

            foreach ($mimeToExt as $mime => $extension) {
                if (str_contains($contentType, $mime)) {
                    return $extension;
                }
            }
        }

        return 'jpg'; // 預設
    }

    /**
     * 建立 ZIP 壓縮檔
     */
    private function createZipArchive($files, $tempDir, $outputName)
    {
        $zipPath = storage_path('app/' . $outputName);
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('❌ 無法建立 ZIP 檔案');
            return false;
        }

        $this->info('📦 正在打包 ZIP 檔案...');

        foreach ($files as $file) {
            $realPath = Storage::path($file);
            $filename = basename($file);
            
            if (file_exists($realPath)) {
                $zip->addFile($realPath, $filename);
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * 清理暫存檔案
     */
    private function cleanupTempFiles($tempDir)
    {
        if ($this->confirm('🗑️  是否刪除暫存檔案？', true)) {
            Storage::deleteDirectory($tempDir);
            $this->info('✅ 暫存檔案已清理');
        } else {
            $this->info("📁 暫存檔案保留在：" . Storage::path($tempDir));
        }
    }
}
