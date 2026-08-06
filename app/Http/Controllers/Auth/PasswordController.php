<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        // Bendera ikut dicabut di sini: middleware ForcePasswordChange mengurung
        // user di halaman profil selama bendera menyala, jadi kalau tidak
        // dibersihkan setelah password diganti, user terkunci selamanya.
        $request->user()->update([
            'password'              => Hash::make($validated['password']),
            'force_password_change' => false,
        ]);

        return back()->with('status', 'password-updated');
    }
}
