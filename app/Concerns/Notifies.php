<?php

namespace App\Concerns;

/**
 * Build-plan 16 — thin wrapper around Livewire's `$this->dispatch()` so
 * every component fires the shared toast the same way (FR-7.21-FR-7.23).
 *
 * Deliberately covers only success/info/warning. A "business-error" (the
 * 4th type, FR-7.25) is NOT a toast — it must stay visible and block the
 * action until resolved, which is exactly what each component's own
 * `?string $xxxError` property + <x-business-error-banner> already do.
 * Converting it into an auto-dismissing toast would violate FR-7.25.
 *
 * The browser-side listener lives once in layouts/app.blade.php.
 */
trait Notifies
{
    protected function notifySuccess(string $message): void
    {
        $this->dispatch('notify', type: 'success', message: $message);
    }

    protected function notifyInfo(string $message): void
    {
        $this->dispatch('notify', type: 'info', message: $message);
    }

    protected function notifyWarning(string $message): void
    {
        $this->dispatch('notify', type: 'warning', message: $message);
    }
}
