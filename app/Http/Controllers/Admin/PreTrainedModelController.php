<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PreTrainedModel;
use App\Models\Stock;
use App\Services\ModelMetadataReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * CRUD pre-trained model (khusus Administrator). Berkas diunggah langsung, tanpa mengetik path:
 *   price_scaled   : model_{ticker}_{lookback}.keras|h5 + scaler_{...}.joblib + metadata_{...}.json -> ml_services/models
 *   general_return : memakai artefak model general bersama (tidak ada unggahan; berkas bersama tidak pernah ditimpa)
 * Kolom file_path diisi otomatis (relatif terhadap root proyek, tanpa backslash).
 */
class PreTrainedModelController extends Controller
{
    private const DIR = 'ml_services/models';
    private const METRICS = ['wfv_mae', 'wfv_rmse', 'wfv_mape', 'holdout_mae', 'holdout_rmse', 'holdout_mape'];

    public function index()
    {
        $models = PreTrainedModel::with('stock')->orderBy('stock_id')->orderBy('lookback_window')->get();

        return view('admin.models.index', compact('models'));
    }

    public function create()
    {
        return view('admin.models.create', $this->formData());
    }

    public function store(Request $request, ModelMetadataReader $reader): RedirectResponse
    {
        $type = $request->input('model_type') === 'general_return' ? 'general_return' : 'price_scaled';
        $upload = $type === 'price_scaled';

        $data = $request->validate([
            'stock_id'        => ['required', 'exists:stocks,id'],
            'model_type'      => ['required', Rule::in(['price_scaled', 'general_return'])],
            'lookback_window' => ['required', Rule::in(PreTrainedModel::LOOKBACKS),
                Rule::unique('pre_trained_models')->where('stock_id', $request->input('stock_id'))],
            'model_file'      => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:keras,h5', 'max:51200'],
            'scaler_file'     => [Rule::requiredIf($upload), 'nullable', 'file', 'extensions:joblib', 'max:10240'],
            'meta_file'       => ['nullable', 'file', 'extensions:json', 'max:1024'],
        ] + $this->metricRules(), [
            'lookback_window.unique' => 'Emiten ini sudah punya model dengan lookback tersebut. Ubah modelnya lewat tombol Ubah.',
        ]);

        $stock = Stock::findOrFail($data['stock_id']);
        $lb = (int) $data['lookback_window'];
        $attrs = ['stock_id' => $stock->id, 'lookback_window' => $lb, 'model_type' => $type, 'is_active' => $request->boolean('is_active')];

        $meta = null;
        if ($upload) {
            $meta = $this->readMeta($request, $lb);
            $attrs += $this->storeFiles($request, $stock, $lb);
        } else {
            if (! $this->generalReady($lb)) {
                throw ValidationException::withMessages(['lookback_window' => "Artefak model general lookback {$lb} belum ada di " . self::DIR . '.']);
            }
            $attrs += ['file_path' => self::DIR . "/model_general_{$lb}.keras", 'file_format' => 'keras'];
        }

        [$metrics, $filled] = $this->metrics($request, $meta ? $reader->metrics($meta) : []);
        PreTrainedModel::create($attrs + $metrics + ['trained_at' => now()]);

        return redirect()->route('admin.models.index')->with('status', 'Model ditambahkan.' . $this->metaNote($meta, $filled));
    }

    public function edit(PreTrainedModel $model)
    {
        return view('admin.models.edit', $this->formData() + ['model' => $model->load('stock')]);
    }

    /** Emiten dan lookback tidak bisa diubah (nama berkas mengikutinya); berkas bersifat opsional. */
    public function update(Request $request, PreTrainedModel $model, ModelMetadataReader $reader): RedirectResponse
    {
        $request->validate([
            'model_file'  => ['nullable', 'file', 'extensions:keras,h5', 'max:51200'],
            'scaler_file' => ['nullable', 'file', 'extensions:joblib', 'max:10240'],
            'meta_file'   => ['nullable', 'file', 'extensions:json', 'max:1024'],
        ] + $this->metricRules());

        $attrs = ['is_active' => $request->boolean('is_active')];
        $meta = null;

        if (! $model->isGeneral()) {   // model general memakai berkas bersama: tidak ada yang diganti
            $meta = $this->readMeta($request, $model->lookback_window);
            $attrs += $this->replaceFiles($request, $model);
        }

        [$metrics, $filled] = $this->metrics($request, $meta ? $reader->metrics($meta) : []);
        $model->update($attrs + $metrics);

        return redirect()->route('admin.models.index')->with('status', 'Model diperbarui.' . $this->metaNote($meta, $filled));
    }

    public function destroy(PreTrainedModel $model): RedirectResponse
    {
        $model->delete(); // prediksi terkait ikut terhapus (cascade); berkas model tidak dihapus

        return redirect()->route('admin.models.index')->with('status', 'Model dihapus.');
    }

    // ------------------------------------------------------------------ util

    private function formData(): array
    {
        return [
            'stocks' => Stock::orderBy('ticker')->get(),
            'lookbacks' => PreTrainedModel::LOOKBACKS,
            'generalReady' => collect(PreTrainedModel::LOOKBACKS)->mapWithKeys(fn ($lb) => [$lb => $this->generalReady($lb)]),
        ];
    }

    private function generalReady(int $lb): bool
    {
        return is_file(base_path(self::DIR . "/model_general_{$lb}.keras"))
            && is_file(base_path(self::DIR . "/scaler_general_{$lb}.joblib"));
    }

    private function metricRules(): array
    {
        return array_fill_keys(self::METRICS, ['nullable', 'numeric', 'min:0']);
    }

    /** Metadata (opsional): harus JSON valid dan lookback-nya cocok. @return array<string,mixed>|null */
    private function readMeta(Request $request, int $lookback): ?array
    {
        $file = $request->file('meta_file');
        if (! $file) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($file->getRealPath()), true);
        if (! is_array($meta)) {
            throw ValidationException::withMessages(['meta_file' => 'Isi metadata bukan JSON yang valid.']);
        }

        $metaLb = $meta['lookback_window'] ?? $meta['lookback'] ?? null;
        if ($metaLb !== null && (int) $metaLb !== $lookback) {
            throw ValidationException::withMessages(['meta_file' => "Metadata menyebut lookback {$metaLb}, model ini {$lookback}."]);
        }

        return $meta;
    }

    /** Nilai form; kolom kosong diisi dari metadata bila ada. @return array{0: array<string,?float>, 1: string[]} */
    private function metrics(Request $request, array $fromMeta): array
    {
        $out = [];
        $filled = [];
        foreach (self::METRICS as $f) {
            $v = $request->input($f);
            if ($v === null || $v === '') {
                if (isset($fromMeta[$f])) {
                    $out[$f] = $fromMeta[$f];
                    $filled[] = $f;
                } else {
                    $out[$f] = null;
                }
            } else {
                $out[$f] = (float) $v;
            }
        }

        return [$out, $filled];
    }

    private function metaNote(?array $meta, array $filled): string
    {
        if (! $meta) {
            return '';
        }

        return $filled
            ? ' Metrik terisi dari metadata: ' . implode(', ', $filled) . ' (MAPE dianggap sudah dalam persen; mohon periksa).'
            : ' Metadata tidak berisi metrik evaluasi yang dikenali, atau kolom metrik sudah terisi.';
    }

    private function tag(Stock $stock, int $lb): string
    {
        return Str::lower($stock->ticker) . "_{$lb}";   // nama berkas dari ticker tervalidasi + angka, bukan dari nama unggahan
    }

    private function extension(Request $request): string
    {
        $ext = Str::lower((string) $request->file('model_file')->getClientOriginalExtension());

        return in_array($ext, ['keras', 'h5'], true) ? $ext : 'keras';
    }

    /** @return array{file_path:string,file_format:string} */
    private function storeFiles(Request $request, Stock $stock, int $lb): array
    {
        $dir = base_path(self::DIR);
        $tag = $this->tag($stock, $lb);
        $ext = $this->extension($request);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $request->file('model_file')->move($dir, "model_{$tag}.{$ext}");
        $request->file('scaler_file')->move($dir, "scaler_{$tag}.joblib");
        if ($request->file('meta_file')) {
            $request->file('meta_file')->move($dir, "metadata_{$tag}.json");
        }

        return ['file_path' => self::DIR . "/model_{$tag}.{$ext}", 'file_format' => $ext];
    }

    /** Ganti hanya berkas yang diunggah; yang kosong dipertahankan. @return array<string,string> */
    private function replaceFiles(Request $request, PreTrainedModel $model): array
    {
        $dir = base_path(self::DIR);
        $tag = $this->tag($model->stock, $model->lookback_window);
        $attrs = [];

        if ($request->file('model_file')) {
            $ext = $this->extension($request);
            $name = "model_{$tag}.{$ext}";
            $request->file('model_file')->move($dir, $name);

            // Bila ekstensi berganti (.keras <-> .h5), hapus berkas lama bernama baku milik model ini saja
            $old = basename((string) $model->file_path);
            if ($old !== $name && str_starts_with($old, "model_{$tag}.") && is_file("{$dir}/{$old}")) {
                @unlink("{$dir}/{$old}");
            }
            $attrs = ['file_path' => self::DIR . "/{$name}", 'file_format' => $ext];
        }
        if ($request->file('scaler_file')) {
            $request->file('scaler_file')->move($dir, "scaler_{$tag}.joblib");
        }
        if ($request->file('meta_file')) {
            $request->file('meta_file')->move($dir, "metadata_{$tag}.json");
        }

        return $attrs;
    }
}
