<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Harga harian OHLCV untuk Analisa Teknikal (candlestick, volume, MACD). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('open', 14, 2);
            $table->decimal('high', 14, 2);
            $table->decimal('low', 14, 2);
            $table->decimal('close', 14, 2);
            $table->unsignedBigInteger('volume')->default(0);
            $table->timestamps();

            $table->unique(['stock_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prices');
    }
};
