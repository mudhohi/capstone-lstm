<?php

namespace App\Http\Controllers;

use App\Models\PreTrainedModel;
use App\Models\Stock;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Export Data/Report.
 *  - CSV: seluruh baris prediksi. Metrik ditulis di baris awal berawalan '#'
 *         (pandas: pd.read_csv(file, comment='#')).
 *  - PDF: ringkasan metrik + baris fase holdout/forecast agar ukuran file wajar.
 */
class ExportController extends Controller
{
    public function __invoke(string $ticker, int $lookback, string $format)
    {
        abort_unless(in_array($lookback, PreTrainedModel::LOOKBACKS, true), 404);

        $stock = Stock::where('ticker', strtoupper($ticker))->firstOrFail();
        $model = $stock->models()->where('lookback_window', $lookback)->firstOrFail();
        $name  = "prediksi_{$stock->ticker}_lookback{$lookback}";

        if ($format === 'pdf') {
            $rows = $model->predictions()->whereIn('phase', ['holdout', 'forecast'])->orderBy('date')->get();

            return Pdf::loadView('exports.prediction', compact('stock', 'model', 'rows'))->download("{$name}.pdf");
        }

        $rows = $model->predictions()->orderBy('date')->cursor(); // hemat memori

        return response()->streamDownload(function () use ($stock, $model, $lookback, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM supaya Excel membaca UTF-8
            fputcsv($out, ["# saham={$stock->ticker}", "lookback={$lookback}"]);
            fputcsv($out, ['# wfv_mae', $model->wfv_mae, 'wfv_rmse', $model->wfv_rmse, 'wfv_mape_persen', $model->wfv_mape]);
            fputcsv($out, ['# holdout_mae', $model->holdout_mae, 'holdout_rmse', $model->holdout_rmse, 'holdout_mape_persen', $model->holdout_mape]);
            fputcsv($out, ['tanggal', 'fase', 'fold', 'harga_aktual', 'harga_prediksi', 'abs_error', 'pct_error']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->date->toDateString(), $r->phase, $r->fold, $r->actual_price, $r->predicted_price, $r->abs_error, $r->pct_error]);
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
