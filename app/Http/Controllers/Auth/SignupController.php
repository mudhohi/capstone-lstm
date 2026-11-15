<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class SignupController extends Controller
{
    public function create()
    {
        return view('auth.signup');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        // Peran bawaan 'visitor' (default kolom). Password di-hash otomatis oleh cast 'hashed' di model User.
        $user = User::create($data);

        Auth::login($user);

        // Halaman verifikasi akan mengirim OTP pertama kali (lihat OtpVerificationController@show)
        return redirect()->route('verification.notice');
    }
}
