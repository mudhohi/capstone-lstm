<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PreTrainedModel;
use App\Models\Stock;
use App\Services\OhlcvCsvParser;
use App\Services\PredictionService;
use App\Services\YfinanceClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin: kelola dataset OHLCV. Hanya Administrator (middleware 'admin').
 *   - fetch()   : tarik dari Yahoo Finance (kode saham + rentang tanggal) -> emiten baru atau existing
 *   - refresh() : tombol Update per baris: tarik data terbaru sampai hari ini
 *   - store()   : unggah CSV (cadangan saat offline)
 *   - destroy() : hapus data harga (opsional sekalian emiten dan model general-nya)
 */
class DatasetController extends Controller
{
    private const MODELS_DIR = 'ml_services/models';

    public function index()
    {
        $stocks = Stock::withCount(['prices', 'models as own_models_count' => fn ($q) => $q->where('model_type', 'price_scaled')])
            ->withMin('prices', 'date')->withMax('prices', 'date')
            ->with(['models' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('ticker')->get();

        $needsModel = $stocks->mapWithKeys(fn ($s) => [$s->id => $s->models->isEmpty()]);

        return view('admin.datasets.index', [
            'stocks' => $stocks,
            'lookbacks' => PreTrainedModel::LOOKBACKS,
            'needsModel' => $needsModel,
            'needsModelByTicker' => $stocks->mapWithKeys(fn ($s) => [$s->ticker => $s->models->isEmpty()]),
            'generalReady' => collect(PreTrainedModel::LOOKBACKS)->mapWithKeys(fn ($lb) => [$lb => $this->generalArtifactsReady($lb)]),
            'today' => now()->toDateString(),
            'defaultStart' => now()->subYears(3)->toDateString(),
        ]);
    }

    // ------------------------------------------------------------------ Yahoo Finance

    /** Form "Tarik data dari Yahoo Finance": emiten baru atau existing, rentang tanggal bebas. */
    public function fetch(Request $request, YfinanceClient $yf): RedirectResponse
    {
        $request->merge(['symbol' => Str::upper(trim((string) $request->input('symbol')))]);
        $option = $request->input('model_option');

        $data = $request->validate([
            'symbol'           => ['required', 'regex:/^[A-Z]{4}(\.JK)?$/'],
            'name'             => ['nullable', 'string', 'max:150'],
            'sector'           => ['nullable', 'string', 'max:100'],
            'start_date'       => ['required', 'date_format:Y-m-d', 'before_or_equal:end_date'],
            'end_date'         => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'model_option'     => ['nullable', Rule::in(['general', 'none'])],
            'general_lookback' => [Rule::requiredIf($option === 'general'), 'nullable', Rule::in(PreTrainedModel::LOOKBACKS)],
        ], [
            'symbol.regex'            => 'Kode saham harus 4 huruf, mis. ARTO atau ARTO.JK.',
            'start_date.before_or_equal' => 'Tanggal mulai harus sebelum atau sama dengan tanggal akhir.',
            'end_date.before_or_equal'   => 'Tanggal akhir tidak boleh melebihi hari ini.',
        ]);

        $ticker = Str::before($data['symbol'], '.');
        $existing = Stock::where('ticker', $ticker)->first();
        $needsModel = ! $existing || ! $existing->models()->where('is_active', true)->exists();
        $wantGeneral = $needsModel && $option === 'general';

        if ($wantGeneral) {
            $this->assertGeneralReady((int) $data['general_lookback']);
        }

        try {
            $fetched = $yf->fetch("{$ticker}.JK", $data['start_date'], $data['end_date']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['symbol' => $e->getMessage()]);
        }

        if ($fetched['ticker'] !== $ticker) {
            throw ValidationException::withMessages(['symbol' => "Layanan mengembalikan data {$fetched['ticker']}, bukan {$ticker}."]);
        }

        $company = $fetched['company_name'];
        $name = trim((string) ($data['name'] ?? '')) ?: (($company && strtoupper($company) !== "{$ticker}.JK") ? $company : $ticker);

        $summary = $this->persist(
            $existing,
            $existing ? null : ['ticker' => $ticker, 'name' => $name, 'sector' => ($data['sector'] ?? '') ?: 'Perbankan'],
            $fetched['rows'],
            $wantGeneral ? fn (Stock $s) => $this->registerGeneralModel($s, (int) $data['general_lookback']) : null,
        );

        $msg = $this->summaryMessage($ticker, $summary, 'Yahoo Finance');
        if ($wantGeneral) {
            $msg .= ' Model general terpasang; belum dievaluasi pada emiten ini.';
        }

        return redirect()->route('admin.datasets.index')->with('status', $msg);
    }

    /** Tombol Update pada baris tabel: data terbaru sampai hari ini (mundur 7 hari agar nilai sementara hari terakhir ikut dikoreksi). */
    public function refresh(Stock $stock, YfinanceClient $yf): RedirectResponse
    {
        $start = $stock->data_end_date
            ? $stock->data_end_date->copy()->subDays(7)->toDateString()
            : now()->subYears(3)->toDateString();

        try {
            $fetched = $yf->fetch("{$stock->ticker}.JK", $start, now()->toDateString());
        } catch (RuntimeException $e) {
            return back()->withErrors(['refresh' => "{$stock->ticker}: {$e->getMessage()}"]);
        }

        $summary = $this->persist($stock, null, $fetched['rows'], null);

        return redirect()->route('admin.datasets.index')->with('status', $this->summaryMessage($stock->ticker, $summary, 'Yahoo Finance'));
    }

    // ------------------------------------------------------------------ CSV (cadangan)

    public function store(Request $request, OhlcvCsvParser $parser): RedirectResponse
    {
        $isNew = $request->input('stock_id') === 'new';
        $existing = $isNew ? null : Stock::find($request->input('stock_id'));
        $needsModel = $isNew || ($existing && ! $existing->models()->where('is_active', true)->exists());
        $option = $request->input('model_option');

        if ($isNew) {
            $request->merge(['new_ticker' => Str::upper(Str::before(trim((string) $request->input('new_ticker')), '.'))]);
        }

        $rules = [
            'stock_id' => ['required', $isNew ? Rule::in(['new']) : 'exists:stocks,id'],
            'csv'      => ['required', 'file', 'extensions:csv,txt', 'max:20480'],
        ];

        if ($isNew) {
            $rules += [
                'new_ticker' => ['required', 'regex:/^[A-Z]{4}$/', 'unique:stocks,ticker'],
                'new_name'   => ['required', 'string', 'max:150'],
                'new_sector' => ['nullable', 'string', 'max:100'],
            ];
        }

        if ($needsModel) {
            $general = $option === 'general';
            $upload = $option === 'upload';
            $rules += [
                'model_option'     => ['required', Rule::in(['general', 'upload'])],
                'general_lookback' => [Rule::requiredIf($general), 'nullable', Rule::in(PreTrainedModel::LOOKBACKS)],
                'lookback_window'  => [Rule::requiredIf($upload), 'nullable', Rule::in(PreTrainedModel::LOOKBACKS)],
                'model_file'       => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:keras', 'max:51200'],
                'scaler_file'      => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:joblib', 'max:10240'],
                'meta_file'        => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:json', 'max:1024'],
            ];
        }

        $data = $request->validate($rules, [
            'new_ticker.regex'  => 'Ticker harus tepat 4 huruf, mis. BRIS (akhiran .JK boleh ditulis).',
            'new_ticker.unique' => 'Ticker ini sudah terdaftar. Pilih dari daftar emiten yang ada.',
            'csv.extensions'    => 'File harus berformat .csv.',
            'model_file.extensions'  => 'Model harus berformat .keras.',
            'scaler_file.extensions' => 'Scaler harus berformat .joblib.',
            'meta_file.extensions'   => 'Metadata harus berformat .json.',
        ]);

        if ($needsModel && $option === 'general') {
            $this->assertGeneralReady((int) $data['general_lookback']);
        }

        try {
            $parsed = $parser->parse($request->file('csv')->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['csv' => $e->getMessage()]);
        }

        $ticker = $isNew ? $data['new_ticker'] : $existing->ticker;

        if ($parsed['ticker'] && $parsed['ticker'] !== $ticker) {
            throw ValidationException::withMessages(['csv' => "CSV ini berisi data {$parsed['ticker']}, sedangkan emiten yang dipilih {$ticker}."]);
        }

        if ($needsModel && $option === 'upload') {
            $meta = json_decode((string) file_get_contents($request->file('meta_file')->getRealPath()), true);
            if (! is_array($meta)) {
                throw ValidationException::withMessages(['meta_file' => 'Isi metadata bukan JSON yang valid.']);
            }
            $metaLb = $meta['lookback_window'] ?? $meta['lookback'] ?? null;
            if ($metaLb !== null && (int) $metaLb !== (int) $data['lookback_window']) {
                throw ValidationException::withMessages(['lookback_window' => "Metadata menyebut lookback {$metaLb}, form memilih {$data['lookback_window']}."]);
            }
        }

        $register = null;
        if ($needsModel) {
            $register = $option === 'upload'
                ? fn (Stock $s) => $this->registerUploadedModel($request, $s, (int) $data['lookback_window'])
                : fn (Stock $s) => $this->registerGeneralModel($s, (int) $data['general_lookback']);
        }

        $summary = $this->persist(
            $existing,
            $isNew ? ['ticker' => $ticker, 'name' => $data['new_name'], 'sector' => ($data['new_sector'] ?? '') ?: 'Perbankan'] : null,
            $parsed['rows'],
            $register,
        );

        $msg = $this->summaryMessage($ticker, $summary, 'CSV');
        if ($needsModel) {
            $msg .= $option === 'general'
                ? ' Model general terpasang; belum dievaluasi pada emiten ini.'
                : ' Model baru terdaftar tanpa metrik evaluasi; isi metrik lewat menu Kelola model.';
        }

        return redirect()->route('admin.datasets.index')->with('status', $msg);
    }

    // ------------------------------------------------------------------ Hapus

    public function destroy(Request $request, Stock $stock, PredictionService $svc): RedirectResponse
    {
        $full = $request->boolean('delete_stock');

        // Emiten dengan model khusus (empat bank utama) memuat hasil evaluasi capstone: tidak boleh ikut terhapus
        if ($full && $stock->models()->where('model_type', 'price_scaled')->exists()) {
            return back()->withErrors(['delete' => "{$stock->ticker} memiliki model khusus. Hapus modelnya dulu lewat menu Kelola model, atau hapus data harganya saja."]);
        }

        $ticker = $stock->ticker;
        $svc->forgetForecasts($stock);   // sebelum record emiten hilang

        $rows = DB::transaction(function () use ($stock, $full) {
            $deleted = DB::table('stock_prices')->where('stock_id', $stock->id)->delete();

            if ($full) {
                DB::table('predictions')->where('stock_id', $stock->id)->delete();
                DB::table('pre_trained_models')->where('stock_id', $stock->id)->delete();   // hanya baris DB; berkas model tidak disentuh
                DB::table('watchlists')->where('stock_id', $stock->id)->delete();
                $stock->delete();
            } else {
                $stock->update(['data_start_date' => null, 'data_end_date' => null, 'total_observations' => 0]);
            }

            return $deleted;
        });

        return redirect()->route('admin.datasets.index')->with('status', $full
            ? "{$ticker} dihapus dari dashboard beserta {$rows} baris harga, prediksi, watchlist, dan modelnya."
            : "{$rows} baris harga {$ticker} dihapus. Data emiten tetap ada, tanggal dan jumlah baris direset.");
    }

    // ------------------------------------------------------------------ Bersama

    private function generalArtifactsReady(int $lookback): bool
    {
        return is_file(base_path(self::MODELS_DIR . "/model_general_{$lookback}.keras"))
            && is_file(base_path(self::MODELS_DIR . "/scaler_general_{$lookback}.joblib"));
    }

    private function assertGeneralReady(int $lb): void
    {
        if (! $this->generalArtifactsReady($lb)) {
            throw ValidationException::withMessages(['general_lookback' =>
                "Artefak model general lookback {$lb} belum ada. Salin model_general_{$lb}.keras, scaler_general_{$lb}.joblib, "
                . "dan metadata_general_{$lb}.json ke " . self::MODELS_DIR . '.']);
        }
    }

    /** Simpan harga + metadata emiten + (opsional) model dalam satu transaksi, lalu bersihkan cache proyeksi emiten itu. */
    private function persist(?Stock $existing, ?array $newStock, array $rows, ?callable $registerModel): array
    {
        $summary = DB::transaction(function () use ($existing, $newStock, $rows, $registerModel) {
            $stock = $existing ?? Stock::create($newStock);

            [$new, $updated, $changed] = $this->upsertPrices($stock, $rows);

            $agg = DB::table('stock_prices')->where('stock_id', $stock->id)
                ->selectRaw('MIN(date) as d1, MAX(date) as d2, COUNT(*) as n')->first();
            $stock->update(['data_start_date' => $agg->d1, 'data_end_date' => $agg->d2, 'total_observations' => $agg->n]);

            if ($registerModel) {
                $registerModel($stock);
            }

            return compact('stock', 'new', 'updated', 'changed', 'agg');
        });

        app(PredictionService::class)->forgetForecasts($summary['stock']);

        return $summary;
    }

    private function summaryMessage(string $ticker, array $s, string $source): string
    {
        $msg = sprintf('%s (%s): %d baris baru, %d baris diperbarui. Rentang data %s s.d. %s (%d baris).',
            $ticker, $source, $s['new'], $s['updated'], $s['agg']->d1, $s['agg']->d2, $s['agg']->n);

        if ($s['changed'] > 0) {
            $msg .= " Perhatian: {$s['changed']} tanggal lama berubah lebih dari 1% (kemungkinan beda penyesuaian dividen/split, atau data hari berjalan belum final). Pastikan konsisten dengan data latih model.";
        }

        return $msg;
    }

    /** @return array{0:int,1:int,2:int} [baris baru, baris diperbarui, tanggal lama yang berubah >1%] */
    private function upsertPrices(Stock $stock, array $rows): array
    {
        $existing = [];
        foreach (array_chunk(array_keys($rows), 500) as $chunk) {
            $existing += DB::table('stock_prices')->where('stock_id', $stock->id)->whereIn('date', $chunk)->pluck('close', 'date')->all();
        }

        $changed = 0;
        foreach ($existing as $date => $old) {
            if ($old > 0 && abs($rows[$date]['close'] - $old) / $old > 0.01) $changed++;
        }

        $now = now();
        $batch = [];
        foreach ($rows as $date => $r) {
            $batch[] = ['stock_id' => $stock->id, 'date' => $date] + $r + ['created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($batch, 150) as $chunk) {
            DB::table('stock_prices')->upsert($chunk, ['stock_id', 'date'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
        }

        return [count($rows) - count($existing), count($existing), $changed];
    }

    private function registerUploadedModel(Request $request, Stock $stock, int $lookback): void
    {
        $dir = base_path(self::MODELS_DIR);
        $tag = Str::lower($stock->ticker) . "_{$lookback}";   // nama dari ticker tervalidasi + angka, bukan dari nama file unggahan

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $request->file('model_file')->move($dir, "model_{$tag}.keras");
        $request->file('scaler_file')->move($dir, "scaler_{$tag}.joblib");
        $request->file('meta_file')->move($dir, "metadata_{$tag}.json");

        PreTrainedModel::create([
            'stock_id' => $stock->id, 'lookback_window' => $lookback,
            'file_path' => self::MODELS_DIR . "/model_{$tag}.keras", 'file_format' => 'keras',
            'model_type' => 'price_scaled', 'is_active' => true, 'trained_at' => now(),
        ]);
    }

    private function registerGeneralModel(Stock $stock, int $lookback): void
    {
        $path = base_path(self::MODELS_DIR . "/model_general_{$lookback}.keras");

        PreTrainedModel::create([
            'stock_id' => $stock->id, 'lookback_window' => $lookback,
            'file_path' => self::MODELS_DIR . "/model_general_{$lookback}.keras", 'file_format' => 'keras',
            'model_type' => 'general_return', 'is_active' => true,
            'trained_at' => is_file($path) ? now()->setTimestamp(filemtime($path)) : null,
        ]);
    }
}
