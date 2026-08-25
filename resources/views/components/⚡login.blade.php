<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new
#[Layout('layouts.guest', ['title' => 'התחברות — כפיים'])]
class extends Component
{
    public string $email = '';
    public string $password = '';
    public bool $remember = false;
    public ?string $errorMessage = null;

    public function login(): void
    {
        $this->errorMessage = null;

        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $this->remember)) {
            $this->errorMessage = 'אימייל או סיסמה שגויים.';

            return;
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            $this->errorMessage = 'המשתמשת אינה פעילה. פנו לבעלת העסק.';

            return;
        }

        session()->regenerate();

        $this->redirect('/', navigate: false);
    }
};
?>

<div>
    <h2 class="mb-1">התחברות</h2>
    <p class="text-text-secondary" style="margin-top:0; margin-bottom: var(--sp-lg);">כניסה למשתמשות קיימות בלבד — אין הרשמה עצמית.</p>

    @if ($errorMessage)
        <div class="mb-4" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small);">
            {{ $errorMessage }}
        </div>
    @endif

    <form wire:submit="login" class="flex flex-col" style="gap: var(--sp-md);">
        <div>
            <label for="email">אימייל</label>
            <input type="email" id="email" wire:model="email" class="ltr-num" dir="ltr" autofocus autocomplete="username">
            @error('email') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
        </div>

        <div>
            <label for="password">סיסמה</label>
            <input type="password" id="password" wire:model="password" autocomplete="current-password">
            @error('password') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
        </div>

        <div class="checkbox-row" style="display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="remember" wire:model="remember" style="width:auto;">
            <label for="remember" style="margin:0;">זכרי אותי</label>
        </div>

        <button type="submit" class="btn btn-primary" style="justify-content:center;">כניסה</button>
    </form>
</div>
