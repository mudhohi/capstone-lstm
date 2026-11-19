<?php

namespace App\Services;

use RuntimeException;

/**
 * Membaca CSV harga harian dengan dua format:
 *  - yfinance mentah (yf.download().to_csv()): baris 1 "Price,Close,High,Low,Open,Volume",
 *    baris 2 "Ticker,BBCA.JK,...", baris 3 "Date,,,"; data mulai baris 4. Dikenali otomatis.
 *  - CSV bersih/lama: satu baris header, mis. "date,close,high,low,open,volume" atau
 *    "Date,Open,High,Low,Close,Adj Close,Volume" (kolom dipetakan dari NAMA, bukan urutan).
 *
 * Kolom Close selalu "close", BUKAN "adj close".
 */
class OhlcvCsvParser
{
    private const COLS = [
        'date'   => ['date', 'datetime', 'tanggal', 'timestamp'],
        'open'   => ['open', 'open_price', 'pembukaan'],
        'high'   => ['high', 'high_price', 'tertinggi'],
        'low'    => ['low', 'low_price', 'terendah'],
        'close'  => ['close', 'close_price', 'penutupan'],
        'volume' => ['volume', 'vol'],
    ];

    /**
     * @return array{rows: array<string, array{open:float,high:float,low:float,close:float,volume:int}>,
     *               ticker: ?string, format: string, skipped: int}
     */
    public function parse(string $path): array
    {
        $h = @fopen($path, 'r');
        if (! $h) {
            throw new RuntimeException('File CSV tidak bisa dibaca.');
        }

        $first = (string) fgets($h);
        rewind($h);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $lines = [];
        while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            if ($r !== [null]) $lines[] = $r;   // lewati baris kosong
        }
        fclose($h);

        if (count($lines) < 2) {
            throw new RuntimeException('CSV kosong atau hanya berisi header.');
        }

        $header = array_map([$this, 'norm'], $lines[0]);
        $ticker = null;
        $format = 'standar';

        if (($header[0] ?? '') === 'price' && $this->norm($lines[1][0] ?? '') === 'ticker') {
            $format = 'yfinance';
            $ticker = strtoupper(explode('.', trim((string) ($lines[1][1] ?? '')))[0]) ?: null;
            $dateIdx = 0;   // kolom pertama berisi tanggal (judulnya "Price"); baris "Date" dilewati karena bukan tanggal
        } else {
            $dateIdx = $this->find($header, self::COLS['date']);
        }

        $idx = [];
        foreach (['open', 'high', 'low', 'close', 'volume'] as $k) {
            $idx[$k] = $this->find($header, self::COLS[$k]);
        }

        $missing = array_keys(array_filter(['date' => $dateIdx] + $idx, fn ($v) => $v === null));
        if ($missing) {
            throw new RuntimeException('Kolom ' . implode(', ', $missing) . ' tidak ditemukan. Header terbaca: ['
                . implode(', ', $header) . '].');
        }

        $rows = [];
        $skipped = 0;
        foreach ($lines as $r) {
            $d = trim((string) ($r[$dateIdx] ?? ''));
            if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) continue;   // header/baris "Date"/sampah

            $o = $this->num($r[$idx['open']] ?? null);
            $hi = $this->num($r[$idx['high']] ?? null);
            $lo = $this->num($r[$idx['low']] ?? null);
            $c = $this->num($r[$idx['close']] ?? null);

            if ($o <= 0 || $hi <= 0 || $lo <= 0 || $c <= 0) { $skipped++; continue; }

            $rows[substr($d, 0, 10)] = [   // kunci tanggal: duplikat otomatis tergabung
                'open' => round($o, 2), 'high' => round($hi, 2), 'low' => round($lo, 2), 'close' => round($c, 2),
                'volume' => (int) round($this->num($r[$idx['volume']] ?? null)),
            ];
        }

        if (! $rows) {
            throw new RuntimeException('Tidak ada baris data yang valid (tanggal YYYY-MM-DD dan harga > 0). Header terbaca: ['
                . implode(', ', $header) . '].');
        }

        ksort($rows);

        return ['rows' => $rows, 'ticker' => $ticker, 'format' => $format, 'skipped' => $skipped];
    }

    private function norm(mixed $v): string
    {
        $v = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $v)));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $v), '_');
    }

    private function find(array $header, array $candidates): ?int
    {
        foreach ($candidates as $c) {
            if (($i = array_search($c, $header, true)) !== false) return $i;
        }

        return null;
    }

    private function num(mixed $v): float
    {
        return (float) str_replace(',', '.', trim((string) $v));
    }
}
