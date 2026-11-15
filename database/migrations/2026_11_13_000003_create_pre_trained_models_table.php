<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu baris = satu model terlatih = satu kombinasi (saham x lookback).
 * Total target 16 baris (4 saham x 4 lookback). Metrik agregat hasil
 * Walk-Forward Validation (rata-rata 3 fold) dan Final Holdout disimpan
 * di sini supaya dashboard tidak perlu menghitung ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_trained_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();

            // Hanya 21, 42, 63, 126 yang valid (divalidasi di aplikasi: PreTrainedModel::LOOKBACKS)
            $table->unsignedSmallInteger('lookback_window');

            // Lokasi file model di storage yang dibaca layanan Python
            $table->string('file_path');
            $table->enum('file_format', ['keras', 'h5'])->default('keras');

            // Arsitektur tetap, mis. {"layers":2,"units":50,"dropout":0.2}
            $table->json('architecture')->nullable();

            // Metrik Walk-Forward Validation (rata-rata fold, 20% data terakhir TIDAK dipakai)
            $table->unsignedTinyInteger('wfv_folds')->default(3);
            $table->decimal('wfv_mae', 18, 4)->nullable();
            $table->decimal('wfv_rmse', 18, 4)->nullable();
            $table->decimal('wfv_mape', 10, 4)->nullable();   // dalam persen

            // Metrik Final Holdout (dipakai sekali di akhir)
            $table->decimal('holdout_mae', 18, 4)->nullable();
            $table->decimal('holdout_rmse', 18, 4)->nullable();
            $table->decimal('holdout_mape', 10, 4)->nullable();

            $table->timestamp('trained_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['stock_id', 'lookback_window']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_trained_models');
    }
};
