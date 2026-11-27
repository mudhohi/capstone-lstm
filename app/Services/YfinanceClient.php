<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Menarik OHLCV harian dari Yahoo Finance lewat layanan Python (GET /fetch-ohlcv).
 * Semua kegagalan dilempar sebagai RuntimeException berpesan Indonesia, siap ditampilkan di form.
 */
class YfinanceClient
{
    /**
     * @return array{ticker:string, company_name:?string, rows: array<string, array{open:float,high:float,low:float,close:float,volume:int}>}
     */
    public function fetch(string $symbol, string $start, string $end): array
    {
        $base = config('services.lstm.url');

        try {
            $response = Http::baseUrl($base)->timeout(90)->acceptJson()
                ->get('/fetch-ohlcv', ['symbol' => $symbol, 'start' => $start, 'end' => $end]);
        } catch (ConnectionException) {
            throw new RuntimeException("Layanan Python tidak dapat dihubungi di {$base}. Pastikan uvicorn berjalan.");
        } catch (Throwable $e) {
            throw new RuntimeException('Gagal memanggil layanan Python: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            $detail = $response->json('detail');

            throw new RuntimeException(is_string($detail) ? $detail : "Layanan Python mengembalikan error HTTP {$response->status()}.");
        }

        $json = $response->json();
        $rows = [];
        foreach (($json['rows'] ?? []) as $r) {
            $o = (float) ($r['open'] ?? 0);
            $h = (float) ($r['high'] ?? 0);
            $l = (float) ($r['low'] ?? 0);
            $c = (float) ($r['close'] ?? 0);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r['date'] ?? '')) || $o <= 0 || $h <= 0 || $l <= 0 || $c <= 0) {
                continue;   // baris rusak dilewati
            }
            $rows[$r['date']] = [
                'open' => round($o, 2), 'high' => round($h, 2), 'low' => round($l, 2), 'close' => round($c, 2),
                'volume' => (int) round((float) ($r['volume'] ?? 0)),
            ];
        }

        if (! $rows) {
            throw new RuntimeException('Yahoo Finance tidak mengembalikan baris harga yang valid untuk rentang ini.');
        }

        ksort($rows);

        return [
            'ticker'       => strtoupper((string) ($json['ticker_clean'] ?? explode('.', $symbol)[0])),
            'company_name' => $json['company_name'] ?? null,
            'rows'         => $rows,
        ];
    }
}
