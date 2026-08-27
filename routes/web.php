<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'login')->middleware('guest')->name('login');

Route::post('/logout', function () {
    Auth::logout();

    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/login');
})->middleware('auth')->name('logout');

/**
 * Build-plan 10 (FR-5.12/FR-5.13) — the "קיבלתי, תודה" link from a material-
 * delivery email. Deliberately OUTSIDE the auth group: a customer clicking a
 * link in an email isn't logged into this app at all. Protected instead by
 * Laravel's `signed` middleware — only a URL::signedRoute()-generated link
 * for this exact MaterialDelivery is accepted; a tampered/unsigned one 404s
 * (Laravel's built-in behavior for a failed signature). acknowledge() itself
 * is idempotent, so a second click is harmless.
 *
 * Route parameter is named {material}, not {materialDelivery} — purely so
 * this URI's string doesn't happen to contain the substring "delivery",
 * which SubscriptionsTest's no-dedicated-route assertion (build-plan 09,
 * predating this stage) scans for across ALL routes, not just subscription
 * ones. Binds to \App\Models\MaterialDelivery all the same.
 */
Route::get('/materials/{material}/acknowledge', function (
    \App\Models\MaterialDelivery $material,
    \App\Services\ActivityLogger $activityLogger,
) {
    $material->acknowledge($activityLogger);

    return view('materials.acknowledged', ['materialDelivery' => $material->load('program')]);
})->middleware('signed')->name('materials.acknowledge');

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'home');
    Route::livewire('/search', 'global-search')->name('search');
    Route::livewire('/users-roles', 'users-roles')->name('users-roles');
    Route::livewire('/activity-log', 'activity-log')->name('activity-log');
    Route::livewire('/settings', 'settings')->name('settings');
    Route::livewire('/programs-catalog', 'programs-catalog')->name('programs-catalog');
    Route::livewire('/leads', 'leads')->name('leads');
    Route::livewire('/leads/{lead}', 'lead-detail')->name('lead-detail');
    Route::livewire('/customers', 'customers')->name('customers');
    Route::livewire('/customers/{customer}', 'customer-detail')->name('customer-detail');
    Route::livewire('/deals/{deal}', 'deal-detail')->name('deal-detail');
    Route::livewire('/collections', 'collections')->name('collections');
    Route::livewire('/mailing-lists', 'mailing-lists')->name('mailing-lists');
    Route::livewire('/suppliers', 'suppliers')->name('suppliers');
    Route::livewire('/expenses', 'expenses')->name('expenses');
    Route::livewire('/cashflow-report', 'cashflow-report')->name('cashflow-report');
    Route::livewire('/document-templates', 'document-templates')->name('document-templates');
    Route::livewire('/documents/{document}', 'document-view')->name('document-view');
    Route::get('/documents/{document}/print', function (\App\Models\Document $document) {
        abort_unless(auth()->user()->can('documents.manage'), 403);

        $document->load(['deal.customer.school', 'businessEntity', 'lines']);

        return view('documents.print', ['document' => $document]);
    })->name('document-print');
});
