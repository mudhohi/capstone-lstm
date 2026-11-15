<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OtpVerificationController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }

        // Kirim OTP otomatis hanya jika belum ada atau sudah kedaluwarsa
        if (! $user->otp_code || $user->otp_expires_at?->isPast()) {
            $this->otp->issue($user);
        }

        return view('auth.verify-account', ['email' => $user->email]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);

        if ($error = $this->otp->verify($request->user(), $data['code'])) {
            throw ValidationException::withMessages(['code' => $error]);
        }

        return redirect()->route('dashboard')->with('status', 'Akun berhasil diverifikasi.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $this->otp->issue($request->user());

        return back()->with('status', 'Kode baru sudah dikirim ke email Anda.');
    }
}
