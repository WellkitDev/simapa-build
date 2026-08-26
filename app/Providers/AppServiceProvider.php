<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Satu instance per request: otentikasi Drive dibayar paling banyak sekali,
        // dan halaman yang tak menyentuh berkas tidak membayarnya sama sekali.
        $this->app->singleton(\App\Services\GoogleDriveService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Begitu APP_URL memakai https, semua URL yang dibangun aplikasi ikut
        // https — termasuk action form dan tautan reset password. Menggantung
        // pada APP_URL, bukan pada environment, supaya dev lokal di http tidak
        // ikut dipaksa dan tidak perlu ada saklar terpisah.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        \Illuminate\Pagination\Paginator::useBootstrapFive();

        Blade::directive('idempotent', function () {
            return '<input type="hidden" name="_idempotency_key" value="<?php echo e(\Illuminate\Support\Str::uuid()); ?>">';
        });

        \App\Models\Payment::observe(\App\Observers\PaymentObserver::class);
        \App\Models\CashEntry::observe(\App\Observers\CashEntryObserver::class);

        \Illuminate\Support\Facades\View::composer('layouts.partials.notifications', function ($view) {
            $user = \Illuminate\Support\Facades\Auth::user();
            $unread = 0;
            $recent = collect();
            // Lonceng tampil di setiap halaman terautentikasi; jangan biarkan kegagalan
            // query notifikasi (mis. migrasi belum jalan) menjatuhkan seluruh halaman.
            if ($user) {
                try {
                    $unread = $user->unreadNotifications()->count();
                    $recent = $user->notifications()->latest()->take(7)->get();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Gagal memuat notifikasi navbar: ' . $e->getMessage());
                }
            }
            $view->with('navUnread', $unread);
            $view->with('navRecent', $recent);
        });

        \Illuminate\Support\Facades\View::composer('dashboard.partials.announcements', function ($view) {
            $user = \Illuminate\Support\Facades\Auth::user();
            $items = collect();
            if ($user) {
                try {
                    $items = app(\App\Services\AnnouncementService::class)->forDashboard($user);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Gagal memuat pengumuman dashboard: ' . $e->getMessage());
                }
            }
            $view->with('dashAnnouncements', $items);
        });

        \Illuminate\Support\Facades\View::composer('dashboard.partials.deadlines', function ($view) {
            $user = \Illuminate\Support\Facades\Auth::user();
            $deadlines = collect();
            $isOverseer = false;
            if ($user) {
                try {
                    $svc = app(\App\Services\TaskService::class);
                    $svc->notifyDueSoon(app(\App\Services\Notifier::class));
                    // `admin` sengaja DICABUT 2026-08-26 (izin user). Enam akun admin
                    // yang mengawasi seluruh tugas di kantor 13 orang lebih terasa
                    // sebagai kebisingan daripada pengawasan; mereka tetap punya halaman
                    // Monitor Tugas bila memang perlu melihat semuanya sekaligus.
                    $isOverseer = $user->hasAnyRole(['manager', 'superadmin']);
                    $deadlines = $isOverseer ? $svc->dueSoonAll() : $svc->dueSoonFor($user);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Gagal memuat alert deadline: ' . $e->getMessage());
                }
            }
            $view->with('deadlines', $deadlines);
            $view->with('deadlineIsOverseer', $isOverseer);
        });
    }
}
