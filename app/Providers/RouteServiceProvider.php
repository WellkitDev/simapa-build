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

        // Sebelumnya HANYA login yang dibatasi; 225 route web lainnya terbuka
        // tanpa batas. Angkanya longgar supaya pemakaian normal (DataTables,
        // navigasi cepat, banyak aset) tak pernah tersenggol — yang dicegat
        // adalah skrip yang membanjiri, bukan manusia.
        RateLimiter::for('web', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        // Ekspor PDF/CSV membangkitkan dokumen lewat dompdf: berat CPU dan
        // sudah pernah menabrak batas 30 detik di produksi (public/error_log).
        RateLimiter::for('export', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Pengiriman surel (lupa password) memakai kuota SMTP dan bisa dipakai
        // membanjiri kotak masuk orang lain.
        RateLimiter::for('mail', function (Request $request) {
            return Limit::perMinutes(10, 5)->by($request->ip());
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
