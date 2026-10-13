<?php

namespace App\Concerns;

/**
 * Build-plan 16 — thin wrapper around Livewire's `$this->dispatch()` so
 * every component fires the shared toast the same way (FR-7.21-FR-7.23).
 *
 * success/info auto-dismiss. A "business-error" (FR-7.25) must stay
 * visible until the user has read it, so notifyError() — and a warning
 * sent with $sticky — pops up the same way but stays until closed
 * (owner's call, 2026-10-14: errors and rule warnings appear as a popup
 * right after the action that triggered them, not as standing notes).
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

    protected function notifyWarning(string $message, bool $sticky = false): void
    {
        $this->dispatch('notify', type: 'warning', message: $message, sticky: $sticky);
    }

    /** A business-rule error (FR-7.25) — stays on screen until closed. */
    protected function notifyError(string $message): void
    {
        $this->dispatch('notify', type: 'error', message: $message, sticky: true);
    }
}
