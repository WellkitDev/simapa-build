<?php
// app/Http/Middleware/ForcePasswordChange.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menegakkan bendera `force_password_change`.
 *
 * Admin mencentangnya saat membuatkan akun dan password sementaranya dikirim
 * polos lewat surel — tapi bendera itu tidak pernah dibaca siapa pun, jadi
 * password sementara tersebut berlaku selamanya sambil mengendap permanen di
 * kotak masuk. Selama bendera menyala, user hanya boleh membuka halaman profil
 * (tempat formulir ganti password berada) dan keluar.
 */
class ForcePasswordChange
{
    /** Route yang tetap boleh dibuka supaya user tidak terkunci total. */
    private const DIIZINKAN = [
        'profile',
        'profile.image',
        'password.update',
        'password.confirm',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->force_password_change) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::DIIZINKAN, true)) {
            return $next($request);
        }

        $pesan = 'Password Anda masih password sementara. Ganti dulu sebelum melanjutkan.';

        if ($request->expectsJson()) {
            abort(403, $pesan);
        }

        return redirect()->route('profile')->with('warning', $pesan);
    }
}
