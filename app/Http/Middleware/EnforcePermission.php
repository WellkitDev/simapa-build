<?php
// app/Http/Middleware/EnforcePermission.php

namespace App\Http\Middleware;

use App\Support\PermissionMap;
use Closure;
use Illuminate\Http\Request;

/**
 * Penegak hak akses tunggal. Menerjemahkan nama route jadi permission lewat
 * config/permissions.php, lalu memanggil can(). FAIL-CLOSED: route bernama yang
 * tidak terpeta ditolak — route baru tidak bisa bocor diam-diam.
 * Superadmin lolos lebih dulu lewat Gate::before (AuthServiceProvider).
 *
 * Saat menolak, meniru idiom app (ManuscriptTrackerController::runOrFlash):
 *  - request AJAX/JSON        → 403 apa adanya (aman untuk fetch/API),
 *  - submit form (non-GET)    → redirect balik + flash error (SweetAlert), bukan 403 mentah,
 *  - navigasi GET ke halaman  → halaman 403 ramah (resources/views/errors/403.blade.php).
 */
class EnforcePermission
{
    public function handle(Request $request, Closure $next)
    {
        $name = $request->route()?->getName();

        // Route tanpa nama (mis. asset/fallback) atau route publik tidak dijaga di sini.
        if ($name === null || PermissionMap::isPublic($name)) {
            return $next($request);
        }

        $permission = PermissionMap::permissionFor($name);

        // Fail-closed: route bernama yang belum dipetakan ditolak.
        if ($permission === null || ! $request->user()?->can($permission)) {
            return $this->deny($request);
        }

        return $next($request);
    }

    private function deny(Request $request)
    {
        $message = 'Anda tidak berwenang mengakses fitur ini.';

        // AJAX/JSON: pertahankan 403 (kontrak API/fetch).
        if ($request->expectsJson()) {
            abort(403, $message);
        }

        // Submit form (POST/PUT/PATCH/DELETE): kembali ke halaman asal + alert,
        // supaya tidak menampilkan 403 mentah di tengah alur pengisian form.
        if (! $request->isMethodSafe()) {
            return back()->with('error', $message);
        }

        // Navigasi GET ke halaman terlarang: halaman 403 ramah (shell app).
        abort(403, $message);
    }
}
