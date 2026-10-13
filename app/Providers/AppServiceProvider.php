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
        // Livewire's temporary upload accepts 12MB by default; materials
        // attachments go up to 25MB (⚡customer-detail's MATERIALS_FILE_RULES),
        // and PHP is raised to match (docker/php/uploads.ini).
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:25600']]);

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
