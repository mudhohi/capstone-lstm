<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('ticker', 10)->unique();          // BBCA, BBRI, BMRI, BBNI
            $table->string('name');                           // nama emiten lengkap
            $table->string('sector')->default('Perbankan');
            $table->date('data_start_date')->nullable();      // rentang data latih (CSV statis)
            $table->date('data_end_date')->nullable();
            $table->unsignedInteger('total_observations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
