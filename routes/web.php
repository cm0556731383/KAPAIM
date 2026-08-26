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

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'home');
    Route::livewire('/users-roles', 'users-roles')->name('users-roles');
    Route::livewire('/activity-log', 'activity-log')->name('activity-log');
    Route::livewire('/settings', 'settings')->name('settings');
    Route::livewire('/programs-catalog', 'programs-catalog')->name('programs-catalog');
    Route::livewire('/leads', 'leads')->name('leads');
    Route::livewire('/leads/{lead}', 'lead-detail')->name('lead-detail');
    Route::livewire('/customers', 'customers')->name('customers');
    Route::livewire('/customers/{customer}', 'customer-detail')->name('customer-detail');
    Route::livewire('/deals/{deal}', 'deal-detail')->name('deal-detail');
    Route::livewire('/document-templates', 'document-templates')->name('document-templates');
    Route::livewire('/documents/{document}', 'document-view')->name('document-view');
    Route::get('/documents/{document}/print', function (\App\Models\Document $document) {
        abort_unless(auth()->user()->can('documents.manage'), 403);

        $document->load(['deal.customer.school', 'businessEntity', 'lines']);

        return view('documents.print', ['document' => $document]);
    })->name('document-print');
});
