<?php

namespace App\Console\Commands;

use App\Models\Prediction;
use App\Models\PreTrainedModel;
use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Impor hasil notebook (CSV di ml_services/data) ke MySQL/SQLite untuk 4 model terbaik.
 *
 *   php artisan lstm:import-results --dry-run   (baca + validasi, lalu batalkan semua perubahan)
 *   php artisan lstm:import-results             (simpan)
 *
 * Nama kolom CSV dicocokkan lewat daftar kandidat (huruf besar/kecil dan spasi diabaikan).
 * Bila kolom wajib tidak ditemukan, perintah berhenti dan menampilkan header asli file,
 * jadi tidak ada pemetaan yang ditebak diam-diam.
 */
class ImportLstmResults extends Command
{
    protected $signature = 'lstm:import-results
                            {--dir=ml_services : Folder ML, relatif terhadap root proyek}
                            {--dry-run : Hanya validasi, tidak menyimpan apa pun}';

    protected $description = 'Impor metrik evaluasi dan prediksi dari CSV notebook untuk 4 model terbaik';

    /** Keputusan akhir: satu lookback terbaik per emiten. */
    private const BEST = ['BBCA' => 21, 'BBNI' => 63, 'BBRI' => 126, 'BMRI' => 126];

    private const F_KEY = [
        'ticker'   => ['ticker', 'symbol', 'emiten', 'stock', 'kode', 'kode_saham'],
        'lookback' => ['lookback', 'lookback_window', 'window', 'window_size', 'lookback_days', 'lb'],
    ];
    private const F_PRED = [
        'date'   => ['date', 'tanggal', 'datetime', 'timestamp'],
        'actual' => ['actual', 'actual_price', 'actual_close', 'y_true', 'true', 'harga_aktual', 'aktual'],
        'pred'   => ['predicted', 'prediction', 'predicted_price', 'predicted_close', 'y_pred', 'pred', 'harga_prediksi', 'prediksi'],
        'fold'   => ['fold', 'fold_id', 'fold_no'],
    ];
    private const F_METRIC = [
        'mae'   => ['mae_mean', 'mean_mae', 'avg_mae', 'mae'],
        'rmse'  => ['rmse_mean', 'mean_rmse', 'avg_rmse', 'rmse'],
        'mape'  => ['mape_mean', 'mean_mape', 'avg_mape', 'mape_%', 'mape_pct', 'mape_percent', 'mape'],
        'model' => ['model', 'method', 'metode'],   // opsional: baris "naive" dilewati
    ];

    public function handle(): int
    {
        $dir = base_path(trim($this->option('dir'), '/\\'));

        DB::beginTransaction();
        try {
            $this->crossCheckBest($dir);
            $models = $this->registerModels($dir);
            $this->importMetrics($dir, $models);
            $this->importPredictions($dir, $models);

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('DRY RUN: semua perubahan dibatalkan. Jalankan tanpa --dry-run untuk menyimpan.');
            } else {
                DB::commit();
                $this->info('Impor selesai.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Saham', 'Lookback', 'MAE WFV', 'RMSE WFV', 'MAPE WFV %', 'MAE holdout', 'RMSE holdout', 'MAPE holdout %'],
            collect($models)->map(fn (PreTrainedModel $m, $t) => [
                $t, $m->lookback_window, $m->wfv_mae, $m->wfv_rmse, $m->wfv_mape, $m->holdout_mae, $m->holdout_rmse, $m->holdout_mape,
            ])->values()->all()
        );

        return self::SUCCESS;
    }

    /** Bandingkan BEST dengan best_lookback_per_ticker.csv (hanya peringatan, tidak menghentikan impor). */
    private function crossCheckBest(string $dir): void
    {
        try {
            [$rows] = $this->readCsv("{$dir}/data/best_lookback_per_ticker.csv", self::F_KEY, ['ticker', 'lookback']);
        } catch (RuntimeException $e) {
            $this->warn('Lewati cek best_lookback_per_ticker.csv: ' . $e->getMessage());

            return;
        }
        foreach ($rows as $r) {
            $t = $this->ticker($r['ticker']);
            if (isset(self::BEST[$t]) && (int) $r['lookback'] !== self::BEST[$t]) {
                $this->warn("{$t}: CSV menyebut lookback {$r['lookback']}, konfigurasi memakai " . self::BEST[$t] . '.');
            }
        }
    }

    /** Daftarkan 4 model terbaik; lookback lain milik emiten yang sama dinonaktifkan (tidak dihapus). */
    private function registerModels(string $dir): array
    {
        $models = [];
        foreach (self::BEST as $ticker => $lb) {
            $stock = Stock::where('ticker', $ticker)->first()
                ?? throw new RuntimeException("Saham {$ticker} belum ada. Jalankan: php artisan db:seed --class=StockSeeder");

            $relative = 'ml_services/models/model_' . Str::lower($ticker) . "_{$lb}.keras";
            $absolute = base_path($relative);
            if (! is_file($absolute)) {
                $this->warn("File model belum ditemukan: {$relative}");
            }

            $models[$ticker] = PreTrainedModel::updateOrCreate(
                ['stock_id' => $stock->id, 'lookback_window' => $lb],
                [
                    'file_path'   => $relative,   // relatif terhadap root proyek; dibaca main.py
                    'file_format' => 'keras',
                    'is_active'   => true,
                    'trained_at'  => is_file($absolute) ? Carbon::createFromTimestamp(filemtime($absolute)) : null,
                ]
            );

            PreTrainedModel::where('stock_id', $stock->id)->where('lookback_window', '!=', $lb)->update(['is_active' => false]);
        }

        return $models;
    }

    private function importMetrics(string $dir, array $models): void
    {
        $this->applyMetrics("{$dir}/data/validation_summary.csv", $models, 'wfv');
        $this->applyMetrics("{$dir}/data/final_holdout_results.csv", $models, 'holdout');
    }

    private function applyMetrics(string $file, array $models, string $prefix): void
    {
        [$rows, $headers] = $this->readCsv($file, self::F_KEY + self::F_METRIC, ['ticker', 'lookback', 'mae', 'rmse', 'mape']);

        $rows = array_values(array_filter($rows, fn ($r) => ! Str::contains(Str::lower($r['model'] ?? ''), 'naive')));
        $factor = $this->mapeFactor($rows, $headers['mape'], basename($file));

        foreach ($models as $ticker => $model) {
            $hit = array_values(array_filter($rows, fn ($r) =>
                $this->ticker($r['ticker']) === $ticker && (int) $r['lookback'] === $model->lookback_window));

            if (! $hit) {
                throw new RuntimeException(basename($file) . ": tidak ada baris untuk {$ticker} lookback {$model->lookback_window}.");
            }
            if (count($hit) > 1) {
                $this->warn(basename($file) . ": {$ticker} punya " . count($hit) . ' baris, dipakai rata-ratanya.');
            }

            $avg = fn (string $k) => array_sum(array_map(fn ($r) => $this->num($r[$k]), $hit)) / count($hit);

            $model->update([
                "{$prefix}_mae"  => round($avg('mae'), 4),
                "{$prefix}_rmse" => round($avg('rmse'), 4),
                "{$prefix}_mape" => round($avg('mape') * $factor, 4),   // disimpan dalam persen
            ]);
        }
    }

    /** MAPE di DB dalam persen. Jika CSV berisi pecahan (semua nilai < 1 dan header tanpa tanda persen), kalikan 100. */
    private function mapeFactor(array $rows, string $header, string $file): int
    {
        $isPercentHeader = Str::contains($header, ['%', 'pct', 'percent']);
        $max = $rows ? max(array_map(fn ($r) => abs($this->num($r['mape'])), $rows)) : 0;
        $factor = (! $isPercentHeader && $max < 1.0) ? 100 : 1;

        $this->line("{$file}: kolom '{$header}', nilai terbesar {$max} -> " . ($factor === 100 ? 'dianggap pecahan, dikali 100' : 'dianggap sudah persen'));

        return $factor;
    }

    private function importPredictions(string $dir, array $models): void
    {
        foreach ($models as $m) {
            Prediction::where('pre_trained_model_id', $m->id)->delete(); // impor ulang aman (idempoten)
        }

        // Urutan penting: holdout terakhir, sehingga menang jika ada tanggal yang sama.
        // CATATAN: tabel predictions memakai enum phase (validation|holdout|forecast),
        // jadi walk_forward disimpan sebagai 'validation'.
        $this->loadPredictions("{$dir}/data/walk_forward_predictions.csv", $models, 'validation');
        $this->loadPredictions("{$dir}/data/final_holdout_predictions.csv", $models, 'holdout');
    }

    private function loadPredictions(string $file, array $models, string $phase): void
    {
        [$rows, $headers] = $this->readCsv($file, self::F_KEY + self::F_PRED, ['ticker', 'date', 'actual', 'pred']);
        $hasLookback = isset($headers['lookback']);
        if (! $hasLookback) {
            $this->warn(basename($file) . ': tidak ada kolom lookback, semua baris per saham dianggap milik model terbaik.');
        }

        $now = now();
        foreach ($models as $ticker => $m) {
            $batch = [];
            foreach ($rows as $r) {
                if ($this->ticker($r['ticker']) !== $ticker) continue;
                if ($hasLookback && (int) $r['lookback'] !== $m->lookback_window) continue;

                $date = Carbon::parse($r['date'])->toDateString();
                $a = $this->num($r['actual']);
                $p = $this->num($r['pred']);
                $abs = abs($a - $p);

                $batch[$date] = [   // kunci tanggal: mencegah duplikat dalam satu file
                    'pre_trained_model_id' => $m->id,
                    'stock_id'             => $m->stock_id,
                    'date'                 => $date,
                    'phase'                => $phase,
                    'fold'                 => ($phase === 'validation' && ($r['fold'] ?? '') !== '') ? (int) $r['fold'] : null,
                    'actual_price'         => round($a, 2),
                    'predicted_price'      => round($p, 2),
                    'abs_error'            => round($abs, 4),
                    'pct_error'            => $a != 0.0 ? round($abs / abs($a) * 100, 4) : null,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ];
            }

            foreach (array_chunk(array_values($batch), 100) as $chunk) {
                Prediction::upsert($chunk, ['pre_trained_model_id', 'date'],
                    ['stock_id', 'phase', 'fold', 'actual_price', 'predicted_price', 'abs_error', 'pct_error', 'updated_at']);
            }

            if ($phase === 'validation' && $batch) {
                $folds = collect($batch)->pluck('fold')->filter()->unique()->count();
                if ($folds > 0) $m->update(['wfv_folds' => $folds]);
            }
            $this->line(sprintf('  %s lookback %d: %d baris %s', $ticker, $m->lookback_window, count($batch), $phase));
        }
    }

    // ------------------------------ util CSV ------------------------------

    /**
     * @return array{0: array<int, array<string,?string>>, 1: array<string,string>} [baris, field => nama header]
     */
    private function readCsv(string $file, array $fields, array $required): array
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

        $headers = array_map(fn ($i) => $norm[$i], $map);
        $this->line(basename($file) . ' -> ' . json_encode($headers));

        $rows = [];
        while (($r = fgetcsv($h, 0, $delim)) !== false) {
            if ($r === [null]) continue;
            $row = [];
            foreach ($map as $field => $i) $row[$field] = isset($r[$i]) ? trim($r[$i]) : null;
            $rows[] = $row;
        }
        fclose($h);

        return [$rows, $headers];
    }

    private function ticker(?string $v): string
    {
        return Str::upper(Str::before(trim((string) $v), '.')); // BBCA.JK -> BBCA
    }

    private function num(?string $v): float
    {
        return (float) str_replace(',', '.', (string) $v);
    }
}
