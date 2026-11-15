<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom peran (Administrator/Visitor) dan OTP verifikasi akun
 * ke tabel users bawaan Laravel. Kolom email_verified_at bawaan Laravel
 * dipakai sebagai penanda "Verify Account" sudah berhasil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['administrator', 'visitor'])->default('visitor')->after('email');
            $table->string('otp_code')->nullable()->after('remember_token');   // simpan HASH, bukan OTP asli
            $table->timestamp('otp_expires_at')->nullable()->after('otp_code');
            $table->unsignedTinyInteger('otp_attempts')->default(0)->after('otp_expires_at'); // batasi percobaan
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'otp_code', 'otp_expires_at', 'otp_attempts']);
        });
    }
};
