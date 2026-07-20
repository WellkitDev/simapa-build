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
 */
class EnforcePermission
{
    public function handle(Request $request, Closure $next)
    {
        $name = $request->route()?->getName();

        // Route tanpa nama (mis. asset/fallback) tidak dijaga di sini.
        if ($name === null || PermissionMap::isPublic($name)) {
            return $next($request);
        }

        $permission = PermissionMap::permissionFor($name);
        abort_if($permission === null, 403, 'Route belum terdaftar di peta hak akses.');
        abort_unless($request->user()?->can($permission), 403);

        return $next($request);
    }
}
