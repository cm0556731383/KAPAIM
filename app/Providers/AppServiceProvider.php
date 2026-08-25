<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        // Generic resource+action permission check (3.12 — עקרון הרשאות): any ability
        // named "resource.action" (e.g. @can('users.delete')) is answered from
        // User::hasPermission(), which is backed by real role_permissions rows.
        // Abilities without a dot fall through to normal Gate/Policy resolution.
        Gate::before(function (User $user, string $ability) {
            if (! str_contains($ability, '.')) {
                return null;
            }

            [$resource, $action] = explode('.', $ability, 2);

            return $user->hasPermission($resource, $action);
        });
    }
}
