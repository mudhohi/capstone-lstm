<?php

namespace App\Console\Concerns;

use Illuminate\Support\Str;
use RuntimeException;

/** Pembaca CSV dengan pencocokan nama kolom lewat daftar kandidat (abaikan huruf besar/kecil, spasi, BOM). */
trait ReadsCsv
{
    /**
     * @return array{0: array<int, array<string,?string>>, 1: array<string,string>} [baris, field => nama header]
     */
    protected function readCsv(string $file, array $fields, array $required): array
    {
        if (! is_file($file)) {
            throw new RuntimeException("File tidak ditemukan: {$file}");
        }

        $h = fopen($file, 'r');
        $firstLine = (string) fgets($h);
        rewind($h);
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $norm = array_map(
            fn ($c) => trim(preg_replace('/[^a-z0-9%]+/', '_', Str::lower(trim(str_replace("\xEF\xBB\xBF", '', (string) $c)))), '_'),
            fgetcsv($h, 0, $delim) ?: []
        );

        $map = [];
        foreach ($fields as $field => $candidates) {
            foreach ($candidates as $c) {
                if (($i = array_search($c, $norm, true)) !== false) { $map[$field] = $i; break; }
            }
        }

        if ($missing = array_diff($required, array_keys($map))) {
            fclose($h);
            throw new RuntimeException(basename($file) . ': kolom ' . implode(', ', $missing)
                . ' tidak dikenali. Header terbaca: [' . implode(', ', $norm) . ']. Kirim header ini agar pemetaan disesuaikan.');
        }

        $this->line(basename($file) . ' -> ' . json_encode(array_map(fn ($i) => $norm[$i], $map)));

        $rows = [];
        while (($r = fgetcsv($h, 0, $delim)) !== false) {
            if ($r === [null]) continue;
            $row = [];
            foreach ($map as $field => $i) $row[$field] = isset($r[$i]) ? trim($r[$i]) : null;
            $rows[] = $row;
        }
        fclose($h);

        return [$rows, array_map(fn ($i) => $norm[$i], $map)];
    }

    protected function ticker(?string $v): string
    {
        return Str::upper(Str::before(trim((string) $v), '.')); // BBCA.JK -> BBCA
    }

    protected function num(?string $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
