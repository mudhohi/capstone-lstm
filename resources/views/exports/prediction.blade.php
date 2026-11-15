<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0F2A43; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: right; }
        th:first-child, td:first-child { text-align: left; }
        th { background: #EAF1F6; }
    </style>
</head>
<body>
    <h1>Laporan prediksi {{ $stock->ticker }} - lookback {{ $model->lookback_window }} hari</h1>
    <p>{{ $stock->name }}. Dibuat {{ now()->translatedFormat('d F Y H:i') }}.</p>

    <table>
        <tr><th></th><th>MAE (Rp)</th><th>RMSE (Rp)</th><th>MAPE (%)</th></tr>
        <tr><td>Walk-forward ({{ $model->wfv_folds }} fold)</td><td>{{ $model->wfv_mae ?? '-' }}</td><td>{{ $model->wfv_rmse ?? '-' }}</td><td>{{ $model->wfv_mape ?? '-' }}</td></tr>
        <tr><td>Final holdout</td><td>{{ $model->holdout_mae ?? '-' }}</td><td>{{ $model->holdout_rmse ?? '-' }}</td><td>{{ $model->holdout_mape ?? '-' }}</td></tr>
    </table>

    <table>
        <tr><th>Tanggal</th><th>Fase</th><th>Aktual (Rp)</th><th>Prediksi (Rp)</th><th>Error (%)</th></tr>
        @foreach ($rows as $r)
            <tr><td>{{ $r->date->format('d-m-Y') }}</td><td>{{ $r->phase }}</td><td>{{ $r->actual_price ?? '-' }}</td><td>{{ $r->predicted_price }}</td><td>{{ $r->pct_error ?? '-' }}</td></tr>
        @endforeach
    </table>
</body>
</html>
