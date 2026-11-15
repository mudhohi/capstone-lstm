<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PreTrainedModel;
use App\Models\Stock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CRUD pre-trained model (khusus Administrator, dijaga middleware 'admin'). */
class PreTrainedModelController extends Controller
{
    public function index()
    {
        $models = PreTrainedModel::with('stock')->orderBy('stock_id')->orderBy('lookback_window')->get();

        return view('admin.models.index', compact('models'));
    }

    public function create()
    {
        return view('admin.models.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        PreTrainedModel::create($this->validated($request));

        return redirect()->route('admin.models.index')->with('status', 'Model ditambahkan.');
    }

    public function edit(PreTrainedModel $model)
    {
        return view('admin.models.edit', $this->formData() + ['model' => $model]);
    }

    public function update(Request $request, PreTrainedModel $model): RedirectResponse
    {
        $model->update($this->validated($request, $model));

        return redirect()->route('admin.models.index')->with('status', 'Model diperbarui.');
    }

    public function destroy(PreTrainedModel $model): RedirectResponse
    {
        $model->delete(); // prediksi terkait ikut terhapus (cascade)

        return redirect()->route('admin.models.index')->with('status', 'Model dihapus.');
    }

    private function formData(): array
    {
        return ['stocks' => Stock::orderBy('ticker')->get(), 'lookbacks' => PreTrainedModel::LOOKBACKS];
    }

    private function validated(Request $request, ?PreTrainedModel $model = null): array
    {
        $metric = ['nullable', 'numeric', 'min:0'];

        $data = $request->validate([
            'stock_id'        => ['required', 'exists:stocks,id'],
            // Hanya 21/42/63/126 dan satu model per kombinasi saham x lookback
            'lookback_window' => ['required', Rule::in(PreTrainedModel::LOOKBACKS),
                Rule::unique('pre_trained_models')->where('stock_id', $request->input('stock_id'))->ignore($model?->id)],
            'file_path'       => ['required', 'string', 'max:255'],
            'file_format'     => ['required', Rule::in(['keras', 'h5'])],
            'wfv_mae' => $metric, 'wfv_rmse' => $metric, 'wfv_mape' => $metric,
            'holdout_mae' => $metric, 'holdout_rmse' => $metric, 'holdout_mape' => $metric,
        ]);

        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
