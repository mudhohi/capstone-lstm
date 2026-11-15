# Starter Laravel - Capstone LSTM Lookback Window

Salin isi folder ini ke atas proyek Laravel 11/12 baru (struktur folder sudah sama).

## Setup

```bash
composer require livewire/livewire barryvdh/laravel-dompdf
npm install apexcharts tailwindcss @tailwindcss/vite   # Tailwind v4 (plugin Vite)
php artisan migrate
php artisan db:seed --class=StockSeeder
```

1. `vite.config.js`: tambahkan `import tailwindcss from '@tailwindcss/vite'` dan `tailwindcss()` pada `plugins`.
2. `bootstrap/app.php`: daftarkan alias middleware.
   ```php
   ->withMiddleware(function (Middleware $middleware) {
       $middleware->alias(['admin' => \App\Http\Middleware\EnsureUserIsAdmin::class]);
   })
   ```
3. `config/services.php`: tambahkan `'lstm' => ['url' => env('LSTM_API_URL', 'http://127.0.0.1:8001')],`
4. `app/Models/User.php`: implementasikan `MustVerifyEmail` (supaya middleware `verified` aktif) dan tambahkan `role` ke `$fillable`.
5. Buat admin pertama: `User::factory()->create(['role' => 'administrator'])` lewat tinker.

## Belum disertakan (controller yang dirujuk routes/web.php)
`Auth\LoginController`, `Auth\SignupController`, `Auth\OtpVerificationController`,
`WatchlistController`, `Admin\PreTrainedModelController`.

## Asumsi yang bisa diubah
- View Stock Prediction dan Watchlists butuh login + akun terverifikasi (tombol "Jelajahi Dashboard" mengarah ke login bagi tamu). Untuk membuka prediksi ke publik, pindahkan route `dashboard` keluar dari grup `auth`.
- Metrik dan prediksi tidak di-seed. Isi tabel `pre_trained_models` dan `predictions` dari hasil notebook asli (angka MAE/RMSE/MAPE nyata), kolom kosong tampil sebagai "-".
- Search Stocks berupa filter di antara 4 emiten.
