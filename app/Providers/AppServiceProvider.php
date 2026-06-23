<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Pagination\Paginator::useBootstrapFive();

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
    }
}
