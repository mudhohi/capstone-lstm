<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Verify Account (Signup <<include>> Verify Account).
 * Kode OTP 6 digit disimpan sebagai HASH, berlaku 10 menit, maksimal 5 percobaan salah.
 * Saat MAIL_MAILER=log (bawaan Laravel), isi email ada di storage/logs/laravel.log.
 */
class OtpService
{
    private const TTL_MINUTES  = 10;
    private const MAX_ATTEMPTS = 5;

    /** Buat OTP baru lalu kirim ke email pengguna. */
    public function issue(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'otp_code'       => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'otp_attempts'   => 0,
        ])->save();

        Mail::raw(
            "Kode verifikasi Lookback Bank: {$code}\nBerlaku " . self::TTL_MINUTES . " menit. Abaikan email ini jika Anda tidak mendaftar.",
            fn ($m) => $m->to($user->email)->subject('Kode verifikasi akun')
        );
    }

    /** Mengembalikan null jika berhasil, atau pesan error jika gagal. */
    public function verify(User $user, string $code): ?string
    {
        if (! $user->otp_code || ! $user->otp_expires_at || $user->otp_expires_at->isPast()) {
            return 'Kode sudah kedaluwarsa. Kirim ulang kode baru.';
        }
        if ($user->otp_attempts >= self::MAX_ATTEMPTS) {
            return 'Terlalu banyak percobaan salah. Kirim ulang kode baru.';
        }
        if (! Hash::check($code, $user->otp_code)) {
            $user->increment('otp_attempts');
            return 'Kode salah.';
        }

        $user->markEmailAsVerified();
        $user->forceFill(['otp_code' => null, 'otp_expires_at' => null, 'otp_attempts' => 0])->save();

        return null;
    }
}
