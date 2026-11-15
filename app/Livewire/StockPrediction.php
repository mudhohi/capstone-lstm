<?php

namespace App\Livewire;

use App\Models\PreTrainedModel;
use App\Models\Stock;
use App\Services\PredictionService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * View Stock Prediction (+ Search Stocks sebagai filter 4 emiten).
 *
 * Alur skeleton:
 *  1. Halaman tampil seketika dengan $ready = false -> semua blok berupa skeleton shimmer.
 *  2. wire:init memanggil init() setelah halaman terbuka -> data dimuat.
 *  3. Livewire mengirim event 'prediction-loaded'; grafik ApexCharts digambar di browser.
 *     Skeleton grafik baru hilang setelah chart.render() selesai (lihat view).
 */
#[Layout('components.layouts.app')] // layout eksplisit, tidak bergantung pada default config Livewire
class StockPrediction extends Component
{
    public bool $ready = false;
    public string $ticker = 'BBCA';
    public int $lookback = 21;
    public string $search = '';   // Search Stocks: filter nama/ticker

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

    /** Dipanggil wire:init: tandai siap, lalu kirim data ke grafik. */
    public function init(): void
    {
        $this->ready = true;
        $this->pushChart();
    }

    public function selectStock(string $ticker): void
    {
        if (Stock::where('ticker', $ticker)->exists()) {
            $this->ticker = $ticker;
            unset($this->stock, $this->models, $this->bestLookback); // buang cache computed
            $this->alignLookback();   // otomatis pindah ke lookback terbaik saham baru
            $this->pushChart();
        }
    }

    /** Dropdown lookback (wire:model.live) memicu method ini. */
    public function updatedLookback(): void
    {
        if (! in_array($this->lookback, $this->availableLookbacks(), true)) {
            $this->alignLookback(); // tolak lookback yang tidak punya model aktif
        }
        $this->pushChart();
    }

    private function pushChart(): void
    {
        $this->dispatch(
            'prediction-loaded',
            series: app(PredictionService::class)->series($this->stock, $this->lookback),
        );
    }

    #[Computed]
    public function stock(): Stock
    {
        return Stock::where('ticker', $this->ticker)->firstOrFail();
    }

    /** Model AKTIF untuk saham terpilih (kebijakan best-model-only), diindeks per lookback. */
    #[Computed]
    public function models()
    {
        return $this->stock->models()->where('is_active', true)->orderBy('lookback_window')->get()->keyBy('lookback_window');
    }

    /** Opsi dropdown: hanya lookback yang punya model aktif (fallback ke daftar penuh jika belum ada). */
    private function availableLookbacks(): array
    {
        $active = $this->models->keys()->map(fn ($k) => (int) $k)->all();

        return $active ?: PreTrainedModel::LOOKBACKS;
    }

    /** Lookback dengan MAPE walk-forward terendah (hanya jika metriknya sudah terisi). */
    #[Computed]
    public function bestLookback(): ?int
    {
        return $this->models->whereNotNull('wfv_mape')->sortBy('wfv_mape')->first()?->lookback_window;
    }

    #[Computed]
    public function filteredStocks()
    {
        $q = Str::lower(trim($this->search));

        return Stock::orderBy('ticker')->get()->filter(
            fn (Stock $s) => $q === '' || Str::contains(Str::lower($s->ticker . ' ' . $s->name), $q)
        );
    }

    /** Prediksi hari bursa berikutnya dari layanan Python (boleh null). */
    #[Computed]
    public function nextDay(): ?array
    {
        return app(PredictionService::class)->nextDay($this->stock, $this->lookback);
    }

    public function render()
    {
        return view('livewire.stock-prediction', [
            'lookbacks' => $this->availableLookbacks(),
        ]);
    }
}
