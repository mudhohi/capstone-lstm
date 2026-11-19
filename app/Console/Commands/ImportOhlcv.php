<?php

namespace App\Console\Commands;

use App\Console\Concerns\ReadsCsv;
use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Impor harga OHLCV harian ke tabel stock_prices.
 *
 * Sumber (dicari berurutan untuk tiap saham):
 *   1. ml_services/data/ohlcv_bbca.csv        (satu file per saham: date,open,high,low,close,volume)
 *   2. ml_services/data/historical_ohlcv.csv  (satu file gabungan: ditambah kolom ticker)
 *
 *   php artisan lstm:import-ohlcv --dry-run
 *   php artisan lstm:import-ohlcv
 */
class ImportOhlcv extends Command
{
    use ReadsCsv;

    protected $signature = 'lstm:import-ohlcv
                            {--dir=ml_services : Folder ML, relatif terhadap root proyek}
                            {--dry-run : Hanya validasi, tidak menyimpan apa pun}';

    protected $description = 'Impor harga OHLCV harian (CSV dari Colab) untuk Analisa Teknikal';

    private const TICKERS = ['BBCA', 'BBNI', 'BBRI', 'BMRI'];

    private const F_TICKER = ['ticker' => ['ticker', 'symbol', 'emiten', 'stock', 'kode', 'kode_saham']];

    private const F_OHLCV = [
        'date'   => ['date', 'tanggal', 'datetime', 'timestamp'],
        'open'   => ['open', 'open_price', 'pembukaan'],
        'high'   => ['high', 'high_price', 'tertinggi'],
        'low'    => ['low', 'low_price', 'terendah'],
        'close'  => ['close', 'close_price', 'penutupan'],   // sengaja BUKAN adj_close
        'volume' => ['volume', 'vol'],
    ];

    public function handle(): int
    {
        $dir = base_path(trim($this->option('dir'), '/\\')) . '/data';
        $combined = null;
        $report = [];

        DB::beginTransaction();
        try {
            foreach (self::TICKERS as $ticker) {
                $stock = Stock::where('ticker', $ticker)->first()
                    ?? throw new RuntimeException("Saham {$ticker} belum ada. Jalankan: php artisan db:seed --class=StockSeeder");

                $rows = $this->rowsFor($dir, $ticker, $combined);
                if ($rows === null) {
                    $this->warn("{$ticker}: tidak ada ohlcv_" . Str::lower($ticker) . '.csv maupun baris di historical_ohlcv.csv. Dilewati.');
                    continue;
                }

                $report[] = $this->store($stock, $rows);
            }

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
            $this->option('dry-run') ? $this->warn('DRY RUN: semua perubahan dibatalkan.') : $this->info('Impor OHLCV selesai.');
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Saham', 'Baris', 'Dilewati', 'Awal', 'Akhir', 'Close beda >1% vs data model'], $report);

        return self::SUCCESS;
    }

    /** @return array<int, array<string,?string>>|null */
    private function rowsFor(string $dir, string $ticker, ?array &$combined): ?array
    {
        $required = ['date', 'open', 'high', 'low', 'close', 'volume'];

        foreach ([Str::lower($ticker), $ticker] as $name) {
            if (is_file("{$dir}/ohlcv_{$name}.csv")) {
                return $this->readCsv("{$dir}/ohlcv_{$name}.csv", self::F_OHLCV, $required)[0];
            }
        }

        if (is_file("{$dir}/historical_ohlcv.csv")) {
            $combined ??= $this->readCsv("{$dir}/historical_ohlcv.csv", self::F_TICKER + self::F_OHLCV, array_merge(['ticker'], $required))[0];
            $mine = array_values(array_filter($combined, fn ($r) => $this->ticker($r['ticker']) === $ticker));

            return $mine ?: null;
        }

        return null;
    }

    private function store(Stock $stock, array $rows): array
    {
        $now = now();
        $batch = [];
        $skipped = 0;

        foreach ($rows as $r) {
            [$o, $h, $l, $c] = [$this->num($r['open']), $this->num($r['high']), $this->num($r['low']), $this->num($r['close'])];
            if ($o <= 0 || $h <= 0 || $l <= 0 || $c <= 0 || ! $r['date']) { $skipped++; continue; }

            $date = Carbon::parse($r['date'])->toDateString();
            $batch[$date] = [
                'stock_id' => $stock->id, 'date' => $date,
                'open' => round($o, 2), 'high' => round($h, 2), 'low' => round($l, 2), 'close' => round($c, 2),
                'volume' => (int) round($this->num($r['volume'])),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        ksort($batch);

        foreach (array_chunk(array_values($batch), 150) as $chunk) {
            StockPrice::upsert($chunk, ['stock_id', 'date'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
        }

        // Sanity check: Close harus sama dengan harga yang dipakai notebook (bukan Adj Close)
        $join = fn ($q) => $q->join('predictions as pr', fn ($j) => $j->on('pr.stock_id', '=', 'p.stock_id')->on('pr.date', '=', 'p.date'))
            ->where('p.stock_id', $stock->id)->whereNotNull('pr.actual_price');
        $total = $join(DB::table('stock_prices as p'))->count();
        $diff  = $join(DB::table('stock_prices as p'))->whereRaw('ABS(p.close - pr.actual_price) > 0.01 * pr.actual_price')->count();

        return [
            $stock->ticker, count($batch), $skipped, array_key_first($batch), array_key_last($batch),
            $total ? "{$diff} dari {$total} tanggal" : 'belum bisa dicek (prediksi kosong)',
        ];
    }
}
