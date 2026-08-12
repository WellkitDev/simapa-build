<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Ekspor PDF/CSV membangkitkan dokumen lewat dompdf: berat CPU. Tanpa limiter
        // bernama terdaftar, `throttle:export` di route jatuh ke jalur maxAttempts
        // dinamis (Illuminate\Routing\Middleware\ThrottleRequests::resolveMaxAttempts),
        // yang membaca properti "export" dari user (selalu null → (int) 0) dan diam-diam
        // memblokir SETIAP request kedua dari user yang sama, bukan yang kesebelas.
        // Sudah didaftarkan di commit 4a2b7d06 (keamanan) pada branch lain yang belum
        // digabung ke branch ini; disalin ke sini supaya throttle:export benar-benar
        // 10/menit seperti yang dimaksud, bukan 1/menit.
        RateLimiter::for('export', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            Route::middleware('web')
                ->group(base_path('routes/access.php'));
        });
    }
}
