<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new
#[Layout('layouts.app', ['title' => 'כפיים'])]
class extends Component
{
    //
};
?>

<div>
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="mb-0.5">ברוכות הבאות ל-כפיים</h1>
            <p class="text-text-secondary m-0">התשתית הבסיסית מוכנה — Laravel, Livewire, Tailwind ו-PostgreSQL רצים.</p>
        </div>
    </div>

    <div class="card">
        <h3>שלב 0 — תשתית בסיס</h3>
        <p class="text-text-secondary">מסכי המערכת האמיתיים ייבנו בשלבים הבאים לפי <code>docs/build-plan/</code>.</p>
    </div>
</div>
