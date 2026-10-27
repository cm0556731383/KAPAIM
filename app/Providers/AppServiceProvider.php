<?php

namespace App\Providers;

use App\Exceptions\EditNotAllowedException;
use App\Models\User;
use App\Support\PagePermissions;
use App\Support\ReadOnlyGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;

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
        // attachments go up to 95MB (⚡customer-detail's MATERIALS_FILE_RULES —
        // just under Cloudflare's 100MB request limit), and PHP/nginx are raised
        // to match (docker/php/uploads.ini, docker/nginx/default.conf).
        // A 95MB file on a slow office connection can take longer than
        // Livewire's default 5 minutes to upload.
        config([
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:97280'],
            'livewire.temporary_file_upload.max_upload_time' => 30,
        ]);

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

        $this->registerReadOnlyPages();
    }

    /**
     * Per-page "view only" (App\Support\PagePermissions): while a Livewire
     * request on a page applies property updates and runs actions, a user
     * without that page's edit permission has every database write blocked
     * (ReadOnlyGuard); the blocked action shows a message instead of an error
     * page. Rendering runs outside the guard. The first render (mount) is not a
     * Livewire update, so it is unaffected — opening the page is gated by
     * each page's own view check.
     */
    private function registerReadOnlyPages(): void
    {
        // A fresh app starts unguarded (the flag is static, so it would otherwise outlive the app between tests).
        ReadOnlyGuard::disable();

        DB::beforeExecuting(fn (string $query) => ReadOnlyGuard::check($query));

        Livewire::listen('hydrate', function (Component $component) {
            // A component whose page depends on the record says so itself (⚡document-view: a lead's quote).
            $page = method_exists($component, 'permissionPage')
                ? $component->permissionPage()
                : (PagePermissions::COMPONENT_PAGES[$component->getName()] ?? null);

            if ($page && PagePermissions::hasEdit($page) && ! auth()->user()?->hasPermission($page, 'edit')) {
                ReadOnlyGuard::enable($page);
            }
        });
        Livewire::listen('render', fn () => ReadOnlyGuard::disable());
        Livewire::listen('dehydrate', fn () => ReadOnlyGuard::disable());

        Livewire::listen('exception', function (Component $component, \Throwable $e, callable $stopPropagation) {
            if ($e instanceof EditNotAllowedException) {
                ReadOnlyGuard::disable();
                $component->dispatch('notify', type: 'error', message: $e->getMessage(), sticky: true);
                $stopPropagation();
            }
        });
    }
}
