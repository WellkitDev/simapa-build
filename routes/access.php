<?php

use App\Http\Controllers\Pages\PermissionController;
use Illuminate\Support\Facades\Route;

// Halaman Hak Akses. Sengaja di berkas terpisah dari web.php.
// role:superadmin dipakai sementara sampai middleware EnforcePermission terpasang;
// setelah itu permission.manage di config/permissions.php yang menjaganya.
Route::middleware(['auth', 'role:superadmin'])->group(function () {
    Route::get('hak-akses', [PermissionController::class, 'index'])->name('permission.index');
    Route::put('hak-akses', [PermissionController::class, 'update'])->name('permission.update');
});
