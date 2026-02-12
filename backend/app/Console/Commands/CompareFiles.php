<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 比對兩個檔案的差異
 * 支援文字檔、JSON 等，輸出統一格式 (unified diff) 或簡易行比對
 * 支援 --data 僅比對 JSON 內的 data 陣列
 */
class CompareFiles extends Command
{
    /**
     * 命令簽名
     * 執行方式：php artisan files:compare {file1} {file2} {--format=unified}
     * 僅比對 data：php artisan files:compare a.json b.json --data
     */
    protected $signature = 'files:compare 
                            {file1 : 第一個檔案路徑} 
                            {file2 : 第二個檔案路徑} 
                            {--format=unified : 輸出格式：unified（系統 diff -u）、simple（簡易行比對）}
                            {--ignore-whitespace : 忽略行首行尾空白}
                            {--data : 僅比對 JSON 檔案內的 data 陣列（忽略 metadata、headers 等）}
                            {--key= : 僅比對 data 中指定欄位（例如 Order_Number），需與 --data 一起使用}
                            {--by-date : 依「日期」對齊後比對（需與 --data 一起使用）}
                            {--fields=Total_Bet_Times,Total_Bet_Amount,Total_Win_Loss : 與 --by-date 一起使用時要比對的欄位，逗號分隔}
                            {--output= : 將差異結果輸出為 JSON 檔案（建議與 --data --by-date 一起使用）}';

    protected $description = '比對兩個檔案的差異（支援 unified diff、簡易行比對、或僅比對 JSON 的 data）';

    public function handle(): int
    {
        $file1 = $this->argument('file1');
        $file2 = $this->argument('file2');
        $format = $this->option('format');
        $ignoreWhitespace = $this->option('ignore-whitespace');

        // 解析為絕對路徑（支援相對路徑與 base_path）
        $path1 = $this->resolvePath($file1);
        $path2 = $this->resolvePath($file2);

        if (!$path1 || !is_file($path1)) {
            $this->error("檔案不存在: {$file1}");
            return 1;
        }
        if (!$path2 || !is_file($path2)) {
            $this->error("檔案不存在: {$file2}");
            return 1;
        }

        $this->info("比對檔案：");
        $this->line("  1) {$path1}");
        $this->line("  2) {$path2}");
        $this->newLine();

        if ($this->option('data')) {
            if ($this->option('by-date')) {
                $fields = array_map('trim', explode(',', (string) $this->option('fields')));
                $outputOpt = $this->option('output');
                $outputPath = $this->resolveOutputPath($outputOpt !== null && $outputOpt !== '' ? $outputOpt : '');
                return $this->compareJsonDataByDate($path1, $path2, $fields, $outputPath);
            }
            return $this->compareJsonData($path1, $path2, $this->option('key'));
        }

        if ($format === 'unified') {
            return $this->outputUnifiedDiff($path1, $path2, $ignoreWhitespace);
        }

        if ($format === 'simple') {
            return $this->outputSimpleDiff($path1, $path2, $ignoreWhitespace);
        }

        $this->error("不支援的 format，請使用 unified 或 simple");
        return 1;
    }

    /**
     * 解析路徑：若為相對路徑則以 base_path 為基準
     */
    private function resolvePath(string $path): ?string
    {
        if ($path === '' || $path === '.') {
            return null;
        }
        if (realpath($path) !== false) {
            return realpath($path);
        }
        $fromBase = base_path($path);
        if (is_file($fromBase)) {
            return realpath($fromBase);
        }
        return $path;
    }

    /** 解析輸出檔路徑：相對路徑以 base_path 為基準，目錄不存在會嘗試建立 */
    private function resolveOutputPath(string $path): string
    {
        if ($path === '') {
            return base_path('storage/app/scraped_data/compare_diff_' . date('Y-m-d_His') . '.json');
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path)) {
            return $path;
        }
        return base_path($path);
    }

    /**
     * 使用系統 diff -u 輸出統一格式差異
     */
    private function outputUnifiedDiff(string $path1, string $path2, bool $ignoreWhitespace): int
    {
        $w = $ignoreWhitespace ? ' -w' : '';
        $cmd = sprintf('diff -u%s %s %s 2>&1', $w, escapeshellarg($path1), escapeshellarg($path2));
        $lines = [];
        exec($cmd, $lines, $exitCode);

        if (empty($lines)) {
            $this->info('兩個檔案內容相同，沒有差異。');
            return 0;
        }

        foreach ($lines as $line) {
            if (str_starts_with($line, '-') && !str_starts_with($line, '---')) {
                $this->line('<fg=red>' . $line . '</>');
            } elseif (str_starts_with($line, '+') && !str_starts_with($line, '+++')) {
                $this->line('<fg=green>' . $line . '</>');
            } else {
                $this->line($line);
            }
        }

        return 0;
    }

    /**
     * 簡易行比對：逐行比較並標示差異
     */
    private function outputSimpleDiff(string $path1, string $path2, bool $ignoreWhitespace): int
    {
        $lines1 = $this->readLines($path1, $ignoreWhitespace);
        $lines2 = $this->readLines($path2, $ignoreWhitespace);
        $maxLines = max(count($lines1), count($lines2));
        $hasDiff = false;

        for ($i = 0; $i < $maxLines; $i++) {
            $line1 = $lines1[$i] ?? null;
            $line2 = $lines2[$i] ?? null;
            $num = $i + 1;

            if ($line1 === $line2) {
                $this->line(sprintf('  %4d  %s', $num, $line1 ?? ''));
                continue;
            }

            $hasDiff = true;
            if ($line1 !== null) {
                $this->line(sprintf('<fg=red>  %4d - %s</>', $num, $line1));
            }
            if ($line2 !== null) {
                $this->line(sprintf('<fg=green>  %4d + %s</>', $num, $line2));
            }
        }

        if (!$hasDiff) {
            $this->info('兩個檔案內容相同，沒有差異。');
        }

        return 0;
    }

    private function readLines(string $path, bool $trim): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($trim) {
            $lines = array_map('trim', $lines);
        }
        return $lines;
    }

    /**
     * 僅比對兩個 JSON 檔案內的 data 陣列；若指定 key 則只比對該欄位（如 Order_Number）
     */
    private function compareJsonData(string $path1, string $path2, ?string $key = null): int
    {
        $json1 = $this->loadJsonWithData($path1);
        $json2 = $this->loadJsonWithData($path2);

        if ($json1 === null) {
            $this->error("無法解析 JSON 或沒有 data 鍵: {$path1}");
            return 1;
        }
        if ($json2 === null) {
            $this->error("無法解析 JSON 或沒有 data 鍵: {$path2}");
            return 1;
        }

        [$data1, $data2] = [$json1['data'], $json2['data']];
        $count1 = is_array($data1) ? count($data1) : 0;
        $count2 = is_array($data2) ? count($data2) : 0;

        $this->info('--- data 筆數 ---');
        $this->line("  檔案1: {$count1} 筆");
        $this->line("  檔案2: {$count2} 筆");
        if ($key !== null && $key !== '') {
            $this->line("  比對欄位: {$key}");
        }
        $this->newLine();

        if (!is_array($data1) || !is_array($data2)) {
            $this->warn('其中一個檔案的 data 不是陣列，無法逐筆比對。');
            $this->line('data 是否相同: ' . ($data1 === $data2 ? '是' : '否'));
            return 0;
        }

        if ($key !== null && $key !== '') {
            return $this->compareDataByKey($data1, $data2, $key);
        }

        $diffs = $this->diffDataArrays($data1, $data2);

        if (empty($diffs)) {
            $this->info('兩個檔案的 data 內容相同。');
            return 0;
        }

        $this->warn('兩個檔案的 data 有差異：');
        foreach ($diffs as $msg) {
            $this->line('  ' . $msg);
        }
        return 0;
    }

    /**
     * 只比對 data 中指定 key 的值（依筆序逐筆比對）
     */
    private function compareDataByKey(array $data1, array $data2, string $key): int
    {
        $values1 = $this->extractKeyValues($data1, $key);
        $values2 = $this->extractKeyValues($data2, $key);

        $count1 = count($values1);
        $count2 = count($values2);
        $max = max($count1, $count2);
        $diffs = [];

        for ($i = 0; $i < $max; $i++) {
            $v1 = $values1[$i] ?? null;
            $v2 = $values2[$i] ?? null;
            $num = $i + 1;

            if ($v1 === null && $v2 === null) {
                continue;
            }
            if ($v1 === null) {
                $diffs[] = "第 {$num} 筆：僅檔案2 有 {$key} = " . $this->formatValue($v2);
                continue;
            }
            if ($v2 === null) {
                $diffs[] = "第 {$num} 筆：僅檔案1 有 {$key} = " . $this->formatValue($v1);
                continue;
            }
            if ($v1 !== $v2) {
                $diffs[] = "第 {$num} 筆：檔案1 {$key} = " . $this->formatValue($v1) . " ，檔案2 = " . $this->formatValue($v2);
            }
        }

        if (empty($diffs)) {
            $this->info("兩個檔案的 data 中「{$key}」全部一致（共 " . count($values1) . " 筆）。");
            return 0;
        }

        $this->warn("兩個檔案的 data 中「{$key}」有差異：");
        foreach ($diffs as $msg) {
            $this->line('  ' . $msg);
        }
        return 0;
    }

    /** 從每筆 data 取出指定 key 的值（支援 key 不存在或不同寫法） */
    private function extractKeyValues(array $data, string $key): array
    {
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                $out[] = null;
                continue;
            }
            // 先精確匹配，再試首字大寫的 snake_case（如 order_number -> Order_Number）
            if (array_key_exists($key, $row)) {
                $out[] = $row[$key];
                continue;
            }
            $lower = strtolower($key);
            $found = null;
            foreach ($row as $k => $v) {
                if (strtolower((string) $k) === $lower) {
                    $found = $v;
                    break;
                }
            }
            $out[] = $found;
        }
        return $out;
    }

    private function formatValue($v): string
    {
        if (is_scalar($v) || $v === null) {
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 載入 JSON 並回傳含 'data' 的陣列，若無 data 或解析失敗回傳 null
     */
    private function loadJsonWithData(string $path): ?array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !array_key_exists('data', $decoded)) {
            return null;
        }
        return $decoded;
    }

    /**
     * 彈性載入 JSON 的 data 陣列：支援根層 data 或 domData.tables[0].data
     */
    private function loadJsonDataFlexible(string $path): ?array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return null;
        }
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }
        if (isset($decoded['domData']['tables'][0]['data']) && is_array($decoded['domData']['tables'][0]['data'])) {
            return $decoded['domData']['tables'][0]['data'];
        }
        return null;
    }

    /**
     * 依「日期」對齊後比對指定欄位（Total_Bet_Times、Total_Bet_Amount、Total_Win_Loss 等）
     * 若指定 outputPath 且有不一致，會將差異寫入該 JSON 檔
     */
    private function compareJsonDataByDate(string $path1, string $path2, array $fields, ?string $outputPath = null): int
    {
        $data1 = $this->loadJsonDataFlexible($path1);
        $data2 = $this->loadJsonDataFlexible($path2);

        if ($data1 === null) {
            $this->error("無法解析 JSON 或取得 data: {$path1}");
            return 1;
        }
        if ($data2 === null) {
            $this->error("無法解析 JSON 或取得 data: {$path2}");
            return 1;
        }

        $byDate1 = $this->indexDataByDate($data1);
        $byDate2 = $this->indexDataByDate($data2);

        $dates1 = array_keys($byDate1);
        $dates2 = array_keys($byDate2);

        $this->info('--- 依日期比對 ---');
        $this->line('  比對欄位: ' . implode(', ', $fields));
        $this->line('  檔案1 日期數: ' . count($dates1));
        $this->line('  檔案2 日期數: ' . count($dates2));
        if ($outputPath) {
            $this->line('  差異輸出: ' . $outputPath);
        }
        $this->newLine();

        $onlyIn1 = array_values(array_diff($dates1, $dates2));
        $onlyIn2 = array_values(array_diff($dates2, $dates1));
        sort($onlyIn1);
        sort($onlyIn2);

        if (!empty($onlyIn1)) {
            $this->warn('僅在檔案1 有的日期: ' . implode(', ', $onlyIn1));
        }
        if (!empty($onlyIn2)) {
            $this->warn('僅在檔案2 有的日期: ' . implode(', ', $onlyIn2));
        }
        if (!empty($onlyIn1) || !empty($onlyIn2)) {
            $this->newLine();
        }

        $commonDates = array_intersect($dates1, $dates2);
        $diffs = [];
        $diffsByDate = [];
        foreach ($commonDates as $date) {
            $row1 = $byDate1[$date];
            $row2 = $byDate2[$date];
            $dateDiffs = [];
            foreach ($fields as $field) {
                $v1 = $this->getRowValue($row1, $field);
                $v2 = $this->getRowValue($row2, $field);
                if ($v1 !== $v2) {
                    $diffs[] = "{$date} {$field}: 檔案1 = " . $this->formatValue($v1) . " ，檔案2 = " . $this->formatValue($v2);
                    $dateDiffs[$field] = ['file1' => $v1, 'file2' => $v2];
                }
            }
            if (!empty($dateDiffs)) {
                $diffsByDate[] = array_merge(['date' => $date], $dateDiffs);
            }
        }

        $payload = [
            'file1' => $path1,
            'file2' => $path2,
            'compared_at' => date('c'),
            'compared_fields' => $fields,
            'only_in_file1' => $onlyIn1,
            'only_in_file2' => $onlyIn2,
            'differences' => $diffsByDate,
            'differences_count' => count($diffsByDate),
        ];

        if ($outputPath) {
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $written = @file_put_contents(
                $outputPath,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            if ($written !== false) {
                $this->info('已寫入差異 JSON: ' . $outputPath);
            } else {
                $this->warn('無法寫入檔案: ' . $outputPath);
            }
        }

        if (empty($diffs)) {
            $this->info('相同日期的「' . implode('、', $fields) . '」全部一致（共 ' . count($commonDates) . ' 個日期）。');
            return 0;
        }

        $this->warn('相同日期的指定欄位有差異：');
        foreach ($diffs as $msg) {
            $this->line('  ' . $msg);
        }
        return 0;
    }

    /** 將 data 陣列以 Date 欄位為 key 索引（同一日期多筆時保留最後一筆） */
    private function indexDataByDate(array $data): array
    {
        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = $this->getRowValue($row, 'Date');
            if ($date === null || $date === '') {
                continue;
            }
            $dateStr = $this->normalizeDate($date);
            $out[$dateStr] = $row;
        }
        return $out;
    }

    private function normalizeDate($date): string
    {
        if (is_numeric($date)) {
            return date('Y-m-d', (int) $date);
        }
        $str = (string) $date;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $str, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        $ts = strtotime($str);
        return $ts !== false ? date('Y-m-d', $ts) : $str;
    }

    /** 從一筆 row 取得指定 key 的值（支援大小寫不敏感） */
    private function getRowValue(array $row, string $key): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }
        $lower = strtolower($key);
        foreach ($row as $k => $v) {
            if (strtolower((string) $k) === $lower) {
                return $v;
            }
        }
        return null;
    }

    /**
     * 比對兩個 data 陣列，回傳差異說明陣列
     */
    private function diffDataArrays(array $data1, array $data2): array
    {
        $diffs = [];
        $count1 = count($data1);
        $count2 = count($data2);

        if ($count1 !== $count2) {
            $diffs[] = "筆數不同：檔案1 {$count1} 筆，檔案2 {$count2} 筆";
        }

        $max = max($count1, $count2);
        for ($i = 0; $i < $max; $i++) {
            $row1 = $data1[$i] ?? null;
            $row2 = $data2[$i] ?? null;

            if ($row1 === null) {
                $diffs[] = "第 " . ($i + 1) . " 筆：僅檔案2 有（檔案1 無此筆）";
                continue;
            }
            if ($row2 === null) {
                $diffs[] = "第 " . ($i + 1) . " 筆：僅檔案1 有（檔案2 無此筆）";
                continue;
            }

            $rowDiffs = $this->diffAssocRecursive($row1, $row2, "第 " . ($i + 1) . " 筆");
            foreach ($rowDiffs as $rd) {
                $diffs[] = $rd;
            }
        }

        return $diffs;
    }

    /**
     * 遞迴比對兩個關聯陣列，回傳差異列表
     */
    private function diffAssocRecursive($a, $b, string $prefix = ''): array
    {
        $diffs = [];
        if (!is_array($a) || !is_array($b)) {
            if ($a !== $b) {
                $diffs[] = "{$prefix}: 值不同（檔案1: " . json_encode($a, JSON_UNESCAPED_UNICODE) . " vs 檔案2: " . json_encode($b, JSON_UNESCAPED_UNICODE) . "）";
            }
            return $diffs;
        }

        $allKeys = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($allKeys as $k) {
            $v1 = $a[$k] ?? null;
            $v2 = $b[$k] ?? null;
            $label = $prefix ? "{$prefix}.{$k}" : (string) $k;

            if (!array_key_exists($k, $a)) {
                $diffs[] = "{$label}: 僅檔案2 有 = " . json_encode($v2, JSON_UNESCAPED_UNICODE);
                continue;
            }
            if (!array_key_exists($k, $b)) {
                $diffs[] = "{$label}: 僅檔案1 有 = " . json_encode($v1, JSON_UNESCAPED_UNICODE);
                continue;
            }

            if (is_array($v1) && is_array($v2)) {
                foreach ($this->diffAssocRecursive($v1, $v2, $label) as $d) {
                    $diffs[] = $d;
                }
            } elseif ($v1 !== $v2) {
                $diffs[] = "{$label}: 檔案1 = " . json_encode($v1, JSON_UNESCAPED_UNICODE) . " ，檔案2 = " . json_encode($v2, JSON_UNESCAPED_UNICODE);
            }
        }
        return $diffs;
    }
}
