<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model transfer: emiten baru yang belum punya model sendiri memakai file .keras milik emiten lain
 * (base_model_id). Scaler-nya di-fit dinamis oleh FastAPI pada harga emiten baru itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_trained_models', function (Blueprint $table) {
            $table->boolean('is_transfer')->default(false)->after('is_active');
            $table->unsignedBigInteger('base_model_id')->nullable()->after('is_transfer'); // model asal (transfer)
        });
    }

    public function down(): void
    {
        Schema::table('pre_trained_models', function (Blueprint $table) {
            $table->dropColumn(['is_transfer', 'base_model_id']);
        });
    }
};
