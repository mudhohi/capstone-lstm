<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * model_type:
 *   price_scaled   = model per emiten (input harga Close di-scale), seperti model_bbca_21.keras
 *   general_return = model global multi-emiten (input log-return), artefak model_general_{lookback}.keras
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_trained_models', function (Blueprint $table) {
            $table->string('model_type', 30)->default('price_scaled')->after('file_format');
        });

        DB::table('pre_trained_models')->where('file_path', 'like', '%model_general_%')->update(['model_type' => 'general_return']);
    }

    public function down(): void
    {
        Schema::table('pre_trained_models', function (Blueprint $table) {
            $table->dropColumn('model_type');
        });
    }
};
