<?php

namespace App\Services;

use App\Models\Stock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Jembatan antara Laravel dan data prediksi.
 *  - series()  : membaca hasil prediksi yang sudah tersimpan di MySQL (cepat, untuk grafik)
 *  - nextDay() : memanggil layanan Python (FastAPI) untuk prediksi hari bursa berikutnya
 *
 * Kontrak API Python yang diasumsikan:
 *   GET {LSTM_API_URL}/predict?ticker=BBCA&lookback=21
 *   200 -> {"ticker":"BBCA","lookback":21,"next_date":"YYYY-MM-DD","next_close_pred":<float>}
 */
class PredictionService
{
    /** Deret aktual vs prediksi dalam format ApexCharts: [[timestamp_ms, harga], ...]. */
    public function series(Stock $stock, int $lookback): array
    {
        $model = $stock->models()->where('lookback_window', $lookback)->first();

        if (! $model) {
            return ['actual' => [], 'predicted' => [], 'holdoutStart' => null];
        }

        $rows = $model->predictions()
            ->orderBy('date')
            ->get(['date', 'phase', 'actual_price', 'predicted_price']);

        // Tanggal disimpan tanpa zona waktu; paksa UTC supaya sumbu x ApexCharts tidak mundur sehari.
        $ms = fn ($row) => $row->date->copy()->shiftTimezone('UTC')->getTimestamp() * 1000;

        return [
            'actual'       => $rows->whereNotNull('actual_price')
                                   ->map(fn ($r) => [$ms($r), (float) $r->actual_price])->values()->all(),
            'predicted'    => $rows->map(fn ($r) => [$ms($r), (float) $r->predicted_price])->values()->all(),
            'holdoutStart' => ($first = $rows->firstWhere('phase', 'holdout')) ? $ms($first) : null,
        ];
    }

    /** Prediksi hari bursa berikutnya dari layanan Python; null jika layanan tidak aktif. */
    public function nextDay(Stock $stock, int $lookback): ?array
    {
        $key = "lstm:next:{$stock->ticker}:{$lookback}";

        return Cache::remember($key, now()->addMinutes(10), function () use ($stock, $lookback) {
            try {
                $response = Http::baseUrl(config('services.lstm.url'))
                    ->timeout(5)->acceptJson()
                    ->get('/predict', ['ticker' => $stock->ticker, 'lookback' => $lookback]);

                return $response->successful() ? $response->json() : null;
            } catch (Throwable) {
                return null; // layanan Python mati: dashboard tetap jalan dari data MySQL
            }
        });
    }
}
