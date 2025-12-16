<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
        $this->registerPolicies();

        Blade::if('role', function ($roles)
        {
            $roles = is_array($roles) ? $roles : \explode('|', $roles);
            return Auth::check() && Auth::user()->hasAnyRole($roles);
        });

        Blade::if('permission', function ($permission) {
            $permission = \is_array($permission) ? $permission : explode('|', $permission);
             return Auth::check() && Auth::user()->hasAnyRole($permission);
        });

        Gate::before(function ($user, $ability) {
            if ($user->hasRole('superadmin')) {
                return true;
            }
        });
        Gate::define('access-usermanagement', fn($user) =>
            $user->hasRole('superadmin') || $user->hasRole('manager')
            // $user->hasRole('marketing')
        );
    }
}
