<?php

use App\Http\Controllers\Admin\PreTrainedModelController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpVerificationController;
use App\Http\Controllers\Auth\SignupController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\WatchlistController;
use App\Livewire\StockPrediction;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 1. Publik: Landing page (sebelum login)
|--------------------------------------------------------------------------
*/
Route::view('/', 'landing')->name('landing');

/*
|--------------------------------------------------------------------------
| 2. Guest only: Login & Signup
|    Error validasi ditampilkan di form = use case "Display Login/Signup Error"
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');

    Route::get('/signup', [SignupController::class, 'create'])->name('signup');
    Route::post('/signup', [SignupController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| 3. Sudah login tetapi belum verifikasi (Signup <<include>> Verify Account)
|    Nama 'verification.notice' dipakai middleware bawaan 'verified'.
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/verify-account', [OtpVerificationController::class, 'show'])->name('verification.notice');
    Route::post('/verify-account', [OtpVerificationController::class, 'verify'])
        ->middleware('throttle:6,1')->name('verification.otp.verify');
    Route::post('/verify-account/resend', [OtpVerificationController::class, 'resend'])
        ->middleware('throttle:3,1')->name('verification.otp.resend');
});

/*
|--------------------------------------------------------------------------
| 4. Administrator & Visitor (login + akun terverifikasi)
|    Search Stocks (filter 4 emiten), View Stock Prediction, Watchlists.
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard = View Stock Prediction (Livewire). {ticker} opsional, mis. /dashboard/BBRI
    Route::get('/dashboard/{ticker?}', StockPrediction::class)
        ->where('ticker', '[A-Za-z]{4}')->name('dashboard');

    // Export Data/Report (CSV atau PDF)
    Route::get('/export/{ticker}/{lookback}/{format}', ExportController::class)
        ->whereIn('lookback', [21, 42, 63, 126])->whereIn('format', ['csv', 'pdf'])
        ->name('export.prediction');

    Route::controller(WatchlistController::class)->prefix('watchlists')->name('watchlists.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::delete('/{stock}', 'destroy')->name('destroy');
    });

    /*
    |----------------------------------------------------------------------
    | 5. Khusus Administrator: CRUD pre-trained model
    |----------------------------------------------------------------------
    */
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('models', PreTrainedModelController::class)
            ->except('show')->parameters(['models' => 'model']);
    });
});
