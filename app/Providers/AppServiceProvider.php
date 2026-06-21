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
            $view->with('navUnread', $user ? $user->unreadNotifications()->count() : 0);
            $view->with('navRecent', $user ? $user->notifications()->latest()->take(7)->get() : collect());
        });
    }
}
