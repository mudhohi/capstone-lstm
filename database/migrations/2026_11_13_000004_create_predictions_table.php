<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pre_trained_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete(); // denormalisasi untuk filter cepat

            $table->date('date');
            // validation = data uji tiap fold WFV, holdout = 20% terakhir, forecast = di luar data
            $table->enum('phase', ['validation', 'holdout', 'forecast'])->default('validation');
            $table->unsignedTinyInteger('fold')->nullable();                  // 1..3 untuk phase validation

            $table->decimal('actual_price', 14, 2)->nullable();               // NULL pada phase forecast
            $table->decimal('predicted_price', 14, 2);
            $table->decimal('abs_error', 14, 4)->nullable();                  // |aktual - prediksi|
            $table->decimal('pct_error', 10, 4)->nullable();                  // |aktual - prediksi| / aktual x 100
            $table->timestamps();

            $table->unique(['pre_trained_model_id', 'date']);
            $table->index(['stock_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
