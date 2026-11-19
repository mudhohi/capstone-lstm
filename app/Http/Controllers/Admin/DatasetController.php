<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PreTrainedModel;
use App\Models\Stock;
use App\Services\OhlcvCsvParser;
use App\Services\PredictionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Admin: perbarui dataset OHLCV (emiten existing) atau tambah emiten baru beserta modelnya.
 * Hanya dapat diakses Administrator (middleware 'admin' di routes/web.php).
 */
class DatasetController extends Controller
{
    public function index()
    {
        $stocks = Stock::withCount('prices')->withMin('prices', 'date')->withMax('prices', 'date')
            ->with(['models' => fn ($q) => $q->where('is_active', true)->with('baseModel.stock')])
            ->orderBy('ticker')->get();

        // Model yang boleh dijadikan baseline transfer: model aktif hasil latih sendiri (bukan transfer)
        $baselines = PreTrainedModel::with('stock')->where('is_active', true)->where('is_transfer', false)
            ->orderBy('stock_id')->orderBy('lookback_window')->get();

        return view('admin.datasets.index', [
            'stocks' => $stocks,
            'baselines' => $baselines,
            'lookbacks' => PreTrainedModel::LOOKBACKS,
        ]);
    }

    public function store(Request $request, OhlcvCsvParser $parser, PredictionService $svc): RedirectResponse
    {
        $isNew = $request->input('stock_id') === 'new';
        $option = $request->input('model_option');

        // Normalisasi ticker baru: "bris.jk" -> "BRIS"
        if ($isNew) {
            $request->merge(['new_ticker' => Str::upper(Str::before(trim((string) $request->input('new_ticker')), '.'))]);
        }

        $rules = [
            'stock_id' => ['required', $isNew ? Rule::in(['new']) : 'exists:stocks,id'],
            'csv'      => ['required', 'file', 'extensions:csv,txt', 'max:20480'],
        ];

        if ($isNew) {
            $upload = $option === 'upload';
            $rules += [
                'new_ticker'        => ['required', 'regex:/^[A-Z]{4}$/', 'unique:stocks,ticker'],
                'new_name'          => ['required', 'string', 'max:150'],
                'new_sector'        => ['nullable', 'string', 'max:100'],
                'model_option'      => ['required', Rule::in(['upload', 'transfer'])],
                'transfer_model_id' => [Rule::requiredIf($option === 'transfer'), 'nullable',
                    Rule::exists('pre_trained_models', 'id')->where('is_active', true)->where('is_transfer', false)],
                'lookback_window'   => [Rule::requiredIf($upload), 'nullable', Rule::in(PreTrainedModel::LOOKBACKS)],
                'model_file'        => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:keras', 'max:51200'],
                'scaler_file'       => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:joblib', 'max:10240'],
                'meta_file'         => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:json', 'max:1024'],
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

        // 1. Parse CSV (yfinance mentah maupun CSV bersih)
        try {
            $parsed = $parser->parse($request->file('csv')->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['csv' => $e->getMessage()]);
        }

        $ticker = $isNew ? $data['new_ticker'] : Stock::findOrFail($data['stock_id'])->ticker;

        // Pengaman: CSV yfinance menyebut tickernya sendiri. Cegah mengunggah data saham lain ke emiten ini.
        if ($parsed['ticker'] && $parsed['ticker'] !== $ticker) {
            throw ValidationException::withMessages(['csv' => "CSV ini berisi data {$parsed['ticker']}, sedangkan emiten yang dipilih {$ticker}."]);
        }

        // 2. Validasi isi metadata model baru (JSON valid, lookback konsisten) sebelum menyimpan apa pun
        if ($isNew && $option === 'upload') {
            $meta = json_decode((string) file_get_contents($request->file('meta_file')->getRealPath()), true);
            if (! is_array($meta)) {
                throw ValidationException::withMessages(['meta_file' => 'Isi metadata bukan JSON yang valid.']);
            }
            $metaLb = $meta['lookback_window'] ?? $meta['lookback'] ?? null;
            if ($metaLb !== null && (int) $metaLb !== (int) $data['lookback_window']) {
                throw ValidationException::withMessages(['lookback_window' => "Metadata menyebut lookback {$metaLb}, form memilih {$data['lookback_window']}."]);
            }
        }

        // 3. Simpan semuanya dalam satu transaksi
        $summary = DB::transaction(function () use ($request, $data, $parsed, $isNew, $option, $ticker) {
            $stock = $isNew
                ? Stock::create(['ticker' => $ticker, 'name' => $data['new_name'], 'sector' => $data['new_sector'] ?: 'Perbankan'])
                : Stock::findOrFail($data['stock_id']);

            [$new, $updated, $changed] = $this->upsertPrices($stock, $parsed['rows']);

            // Metadata emiten mengikuti isi tabel (bukan hanya isi CSV ini)
            $agg = DB::table('stock_prices')->where('stock_id', $stock->id)
                ->selectRaw('MIN(date) as d1, MAX(date) as d2, COUNT(*) as n')->first();
            $stock->update(['data_start_date' => $agg->d1, 'data_end_date' => $agg->d2, 'total_observations' => $agg->n]);

            if ($isNew) {
                $option === 'upload'
                    ? $this->registerUploadedModel($request, $stock, (int) $data['lookback_window'])
                    : $this->registerTransferModel($stock, (int) $data['transfer_model_id']);
            }

            return compact('stock', 'new', 'updated', 'changed', 'agg');
        });

        // 4. Proyeksi lama tidak berlaku lagi untuk saham ini
        $svc->forgetForecasts($summary['stock']);

        $msg = sprintf('%s: %d baris baru, %d baris diperbarui. Rentang data %s s.d. %s (%d baris).',
            $ticker, $summary['new'], $summary['updated'], $summary['agg']->d1, $summary['agg']->d2, $summary['agg']->n);
        if ($summary['changed'] > 0) {
            $msg .= " Perhatian: {$summary['changed']} tanggal lama berubah lebih dari 1% (kemungkinan penyesuaian dividen/split). Pastikan konsisten dengan data latih model.";
        }
        if ($isNew) {
            $msg .= $option === 'transfer'
                ? ' Prediksi memakai model transfer yang belum divalidasi untuk emiten ini.'
                : ' Model baru terdaftar tanpa metrik evaluasi; isi metrik lewat menu Kelola model.';
        }

        return redirect()->route('admin.datasets.index')->with('status', $msg);
    }

    /** @return array{0:int,1:int,2:int} [baris baru, baris diperbarui, tanggal lama yang berubah >1%] */
    private function upsertPrices(Stock $stock, array $rows): array
    {
        $dates = array_keys($rows);

        // Harga lama pada tanggal yang sama (untuk menghitung baru vs diperbarui dan mendeteksi perubahan besar)
        $existing = [];
        foreach (array_chunk($dates, 500) as $chunk) {
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

    /** Opsi A: simpan pasangan .keras/.joblib/.json dengan nama baku di ml_services/models. */
    private function registerUploadedModel(Request $request, Stock $stock, int $lookback): void
    {
        $dir = base_path('ml_services/models');
        $tag = Str::lower($stock->ticker) . "_{$lookback}";   // nama dibentuk dari ticker tervalidasi + angka, bukan dari nama file unggahan

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $request->file('model_file')->move($dir, "model_{$tag}.keras");
        $request->file('scaler_file')->move($dir, "scaler_{$tag}.joblib");
        $request->file('meta_file')->move($dir, "metadata_{$tag}.json");

        PreTrainedModel::create([
            'stock_id' => $stock->id, 'lookback_window' => $lookback,
            'file_path' => "ml_services/models/model_{$tag}.keras", 'file_format' => 'keras',
            'is_active' => true, 'is_transfer' => false, 'trained_at' => now(),
        ]);
    }

    /** Opsi B: pakai file .keras milik emiten lain; FastAPI akan men-fit scaler pada harga emiten baru. */
    private function registerTransferModel(Stock $stock, int $baseModelId): void
    {
        $base = PreTrainedModel::findOrFail($baseModelId);

        PreTrainedModel::create([
            'stock_id' => $stock->id, 'lookback_window' => $base->lookback_window,
            'file_path' => $base->file_path, 'file_format' => $base->file_format,
            'is_active' => true, 'is_transfer' => true, 'base_model_id' => $base->id,
        ]);
    }
}
