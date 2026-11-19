<?php

namespace App\Livewire;

use App\Models\Stock;
use App\Services\PredictionService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dashboard prediksi: 4 emiten, 1 model terbaik per emiten (dipilih otomatis).
 *
 * Alur pemuatan (agar halaman terasa cepat):
 *  1. init()         -> grafik LSTM + data analisa teknikal (dari DB)
 *  2. loadForecast() -> dipanggil browser SETELAH grafik tergambar; memanggil FastAPI sekali
 *                       untuk 20 hari lalu dipotong sesuai slider, jadi menggeser slider instan.
 */
#[Layout('components.layouts.app')]
class StockPrediction extends Component
{
    public const MAX_HORIZON = 20;

    public bool $ready = false;
    public bool $forecastReady = false;
    public string $ticker = 'BBCA';
    public int $lookback = 21;   // diisi otomatis dari model aktif, tidak dipilih pengguna
    public int $horizon = 1;

    public function mount(?string $ticker = null): void
    {
        if ($ticker && Stock::where('ticker', strtoupper($ticker))->exists()) {
            $this->ticker = strtoupper($ticker);
        }
        $this->alignLookback();
    }

    /** Pilih lookback terbaik milik saham terpilih (model aktif dengan MAPE walk-forward terendah). */
    private function alignLookback(): void
    {
        $best = $this->models->sortBy(fn ($m) => $m->wfv_mape ?? PHP_INT_MAX)->first();
        if ($best) {
            $this->lookback = $best->lookback_window;
        }
    }

    public function init(): void
    {
        $this->ready = true;
        $this->pushChart();
    }

    public function selectStock(string $ticker): void
    {
        if (Stock::where('ticker', $ticker)->exists()) {
            $this->ticker = $ticker;
            unset($this->stock, $this->models, $this->forecast); // buang cache computed
            $this->alignLookback();
            $this->forecastReady = false;   // kartu proyeksi kembali ke skeleton
            $this->pushChart();
        }
    }

    /** Dipanggil browser setelah grafik LSTM selesai digambar. */
    public function loadForecast(): void
    {
        $this->forecastReady = true;
        $this->dispatch('forecast-updated', forecast: $this->forecastSeries());
    }

    /** Slider horizon (wire:model.live.debounce). */
    public function updatedHorizon(): void
    {
        $this->horizon = max(1, min(self::MAX_HORIZON, $this->horizon));

        if ($this->forecastReady) {
            $this->dispatch('forecast-updated', forecast: $this->forecastSeries());
        }
    }

    private function pushChart(): void
    {
        $svc = app(PredictionService::class);

        $this->dispatch('prediction-loaded', series: $svc->series($this->stock, $this->lookback));
        $this->dispatch('technical-loaded', payload: $svc->technical($this->stock, $this->lookback));
    }

    #[Computed]
    public function stock(): Stock
    {
        return Stock::where('ticker', $this->ticker)->firstOrFail();
    }

    /** Emiten yang punya data aktif (harga OHLCV atau model aktif): emiten baru dari Admin otomatis muncul. */
    #[Computed]
    public function stocks()
    {
        return Stock::where(fn ($q) => $q->has('prices')
                ->orWhereHas('models', fn ($m) => $m->where('is_active', true)))
            ->orderBy('ticker')->get();
    }

    /** Label model asal bila model aktif adalah model transfer, mis. "BMRI (126 hari)". */
    #[Computed]
    public function baseLabel(): ?string
    {
        $base = $this->models->get($this->lookback)?->baseModel()->with('stock')->first();

        return $base ? "{$base->stock->ticker} ({$base->lookback_window} hari)" : null;
    }

    /** Model AKTIF untuk saham terpilih (kebijakan best-model-only), diindeks per lookback. */
    #[Computed]
    public function models()
    {
        return $this->stock->models()->where('is_active', true)->orderBy('lookback_window')->get()->keyBy('lookback_window');
    }

    /** Proyeksi N hari bursa (N = slider) + selisih terhadap penutupan aktual terakhir; null jika API mati. */
    #[Computed]
    public function forecast(): ?array
    {
        if (! $this->models->has($this->lookback)) {
            return null;   // belum ada model aktif: tidak ada yang bisa diprediksi
        }

        $svc = app(PredictionService::class);
        $all = $svc->nextDay($this->stock, $this->lookback, self::MAX_HORIZON);
        $last = $svc->lastActual($this->stock, $this->lookback);

        if (! $all || empty($all['forecasts']) || ! $last) {
            return null;
        }

        $rows = array_slice($all['forecasts'], 0, $this->horizon);
        $target = end($rows);
        $delta = $target['predicted_close'] - $last['close'];

        return [
            'rows'   => $rows,
            'last'   => $last,
            'target' => $target,
            'delta'  => $delta,
            'pct'    => $last['close'] ? $delta / $last['close'] * 100 : null,
        ];
    }

    /** Titik garis putus-putus: berawal dari penutupan aktual terakhir agar tersambung ke grafik. */
    private function forecastSeries(): array
    {
        $f = $this->forecast;
        if (! $f) {
            return [];
        }

        $ms = fn (string $d) => strtotime($d . ' 00:00:00 UTC') * 1000;
        $points = [[$ms($f['last']['date']), (float) $f['last']['close']]];
        foreach ($f['rows'] as $r) {
            $points[] = [$ms($r['date']), (float) $r['predicted_close']];
        }

        return $points;
    }

    public function render()
    {
        return view('livewire.stock-prediction');
    }
}
