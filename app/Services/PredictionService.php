<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Jembatan antara Laravel dan data prediksi/harga.
 *  - series()    : aktual vs prediksi tersimpan di DB (grafik LSTM)
 *  - nextDay()   : prediksi multi-step (rekursif) dari layanan Python FastAPI
 *  - lastActual(): penutupan aktual terakhir (acuan selisih proyeksi)
 *  - technical() : deret harga untuk Analisa Teknikal (OHLCV, atau fallback close-only)
 *
 * Kontrak API Python:
 *   GET {LSTM_API_URL}/predict?ticker=BBCA&lookback=21&horizon=7
 *   200 -> {"ticker","lookback","horizon","next_date","next_close_pred",
 *           "forecasts":[{"step":1,"date":"YYYY-MM-DD","predicted_close":<float>}, ...]}
 */
class PredictionService
{
    /** timestamp milidetik UTC untuk 'YYYY-MM-DD' (sumbu datetime ApexCharts). */
    private function ms(string $date): int
    {
        return strtotime($date . ' 00:00:00 UTC') * 1000;
    }

    /**
     * Deret grafik LSTM. 'actual' = harga aktual dari hasil evaluasi, diperpanjang dengan penutupan terbaru
     * dari stock_prices (setelah Admin memperbarui dataset). Emiten baru tanpa hasil evaluasi tetap punya
     * garis aktual dari stock_prices; garis 'predicted' kosong sampai ada evaluasi.
     */
    public function series(Stock $stock, int $lookback): array
    {
        $model = $stock->models()->where('lookback_window', $lookback)->first();

        $rows = $model
            ? $model->predictions()->orderBy('date')->get(['date', 'phase', 'actual_price', 'predicted_price'])
            : collect();

        $ms = fn ($row) => $this->ms($row->date->format('Y-m-d'));

        $actual = $rows->whereNotNull('actual_price')
            ->map(fn ($r) => [$ms($r), (float) $r->actual_price])->values()->all();

        $lastDate = $rows->isNotEmpty() ? $rows->last()->date->format('Y-m-d') : null;
        $newer = $stock->prices()->orderBy('date')
            ->when($lastDate, fn ($q) => $q->where('date', '>', $lastDate))
            ->get(['date', 'close'])
            ->map(fn ($p) => [$this->ms($p->date->format('Y-m-d')), (float) $p->close])->all();

        return [
            'actual'       => array_merge($actual, $newer),
            'predicted'    => $rows->map(fn ($r) => [$ms($r), (float) $r->predicted_price])->values()->all(),
            'holdoutStart' => ($first = $rows->firstWhere('phase', 'holdout')) ? $ms($first) : null,
        ];
    }

    /** Prediksi 1..20 hari bursa ke depan; null jika layanan Python tidak aktif. */
    public function nextDay(Stock $stock, int $lookback, int $horizon = 1): ?array
    {
        $key = "lstm:forecast:{$stock->ticker}:{$lookback}:{$horizon}";

        if ($hit = Cache::get($key)) {
            return $hit;
        }

        try {
            $response = Http::baseUrl(config('services.lstm.url'))
                ->timeout(20)->acceptJson()
                ->get('/predict', ['ticker' => $stock->ticker, 'lookback' => $lookback, 'horizon' => $horizon]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            // Kompatibel dengan versi API lama (tanpa 'forecasts')
            $data['forecasts'] ??= [[
                'step' => 1, 'date' => $data['next_date'] ?? null, 'predicted_close' => $data['next_close_pred'] ?? null,
            ]];

            Cache::put($key, $data, now()->addMinutes(10)); // hanya hasil sukses yang di-cache

            return $data;
        } catch (Throwable) {
            return null; // layanan Python mati: dashboard tetap jalan dari data DB
        }
    }

    /** Penutupan aktual terakhir: dari stock_prices (mengikuti dataset terbaru), cadangan: tabel predictions. */
    public function lastActual(Stock $stock, int $lookback): ?array
    {
        if ($p = $stock->prices()->orderByDesc('date')->first(['date', 'close'])) {
            return ['date' => $p->date->format('Y-m-d'), 'close' => (float) $p->close];
        }

        $model = $stock->models()->where('lookback_window', $lookback)->first();
        $row = $model?->predictions()->whereNotNull('actual_price')->orderByDesc('date')->first(['date', 'actual_price']);

        return $row ? ['date' => $row->date->format('Y-m-d'), 'close' => (float) $row->actual_price] : null;
    }

    /** Hapus cache proyeksi satu saham (dipanggil setelah dataset diperbarui). Hanya kunci milik saham ini. */
    public function forgetForecasts(Stock $stock): void
    {
        foreach (\App\Models\PreTrainedModel::LOOKBACKS as $lb) {
            foreach (range(1, 20) as $h) {
                Cache::forget("lstm:forecast:{$stock->ticker}:{$lb}:{$h}");
            }
        }
    }

    /**
     * Data Analisa Teknikal. Baris: [t_ms, open, high, low, close, volume].
     *  - mode 'ohlcv'      : dari tabel stock_prices
     *  - mode 'close_only' : fallback, hanya harga penutupan dari tabel predictions (tanpa candlestick/volume)
     *  - mode 'none'       : belum ada data apa pun
     */
    public function technical(Stock $stock, int $lookback): array
    {
        $prices = $stock->prices()->orderBy('date')->get(['date', 'open', 'high', 'low', 'close', 'volume']);

        if ($prices->isNotEmpty()) {
            return ['mode' => 'ohlcv', 'rows' => $prices->map(fn ($p) => [
                $this->ms($p->date->format('Y-m-d')),
                (float) $p->open, (float) $p->high, (float) $p->low, (float) $p->close, (int) $p->volume,
            ])->all()];
        }

        $model = $stock->models()->where('lookback_window', $lookback)->first();
        $rows = $model
            ? $model->predictions()->whereNotNull('actual_price')->orderBy('date')->get(['date', 'actual_price'])
            : collect();

        if ($rows->isEmpty()) {
            return ['mode' => 'none', 'rows' => []];
        }

        return ['mode' => 'close_only', 'rows' => $rows->map(fn ($r) => [
            $this->ms($r->date->format('Y-m-d')),
            ($c = (float) $r->actual_price), $c, $c, $c, 0,
        ])->all()];
    }
}
