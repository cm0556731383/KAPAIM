<?php

use App\Models\Deal;
use App\Models\ExternalIntegrationSetting;
use App\Models\Lead;
use App\Models\MaterialDelivery;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Illuminate\Http\Request;
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

/**
 * Build-plan 12 — the download link embedded in a Smove materials email
 * (Smove's real API has no attachment-upload endpoint, so the file itself
 * stays here — see MaterialDeliveryAttachment::downloadUrl()). Same
 * outside-auth + `signed` pattern as the acknowledge route above: the
 * recipient clicking this link isn't logged into the app.
 */
Route::get('/materials/attachments/{attachment}/download', function (
    \App\Models\MaterialDeliveryAttachment $attachment,
) {
    return \Illuminate\Support\Facades\Storage::disk('local')->download($attachment->file_reference, $attachment->file_name);
})->middleware('signed')->name('materials.attachment.download');

/**
 * Build-plan 12 — three public, unauthenticated-by-Laravel-auth webhook
 * endpoints (landing page, Smove, Summit). Each is gated instead by
 * ExternalIntegrationSetting::verifyWebhookSecret() against an
 * X-Webhook-Secret header — see that method's docblock for why a blank
 * configured secret (the seeded default) always 403s rather than ever
 * silently accepting an unauthenticated request. All business logic lives
 * on the relevant model (Lead::createFromLandingPage(), MaterialDelivery::
 * markOpened(), Deal::collectStandingOrderPayment()) — these closures are
 * thin, matching the materials.acknowledge route above.
 */
Route::post('/webhooks/landing-page/lead', function (
    Request $request,
    ActivityLogger $activityLogger,
    \App\Services\Integrations\ExternalOperationRunner $runner,
    \App\Services\Integrations\SmoveClient $smove,
) {
    abort_unless(ExternalIntegrationSetting::verifyWebhookSecret('landing_page', $request->header('X-Webhook-Secret')), 403);

    $data = $request->validate([
        'contact_name' => ['nullable', 'string', 'max:255'],
        'school_name' => ['nullable', 'string', 'max:255'],
        'school_phone' => ['nullable', 'string', 'max:50'],
        'email' => ['required', 'email', 'max:255'],
        'phone' => ['required', 'string', 'max:50'],
    ]);

    $lead = Lead::createFromLandingPage($data, $activityLogger, $runner, $smove);

    return response()->json(['lead_id' => $lead->id], 201);
})->name('webhooks.landing-page.lead');

/**
 * FR-5.10/FR-5.11: Smove's own open-tracking callback for a specific
 * delivery — {material} bound the same way as the materials.acknowledge
 * route above, but via the shared secret instead of a per-link signature
 * (Smove calls this once per open, from its own server, not from a link a
 * customer clicked).
 */
Route::post('/webhooks/smove/material-opened/{material}', function (Request $request, MaterialDelivery $material) {
    abort_unless(ExternalIntegrationSetting::verifyWebhookSecret('smove', $request->header('X-Webhook-Secret')), 403);

    $material->markOpened();

    return response()->json(['status' => 'ok']);
})->name('webhooks.smove.material-opened');

/**
 * FR-4.27: Summit's confirmation that a month's standing-order collection
 * actually cleared. $data['deal_id'] is expected to be exactly the id
 * Deal::registerStandingOrderWithSummit() sent Summit as its own merchant
 * reference — see that method's docblock. A business-rule failure (wrong
 * payment method, version conflict) or an unknown deal_id both come back as
 * a normal failed EXTERNAL_OPERATION (logged, visible in the activity log)
 * rather than a non-200 response, so Summit doesn't endlessly retry a
 * request that will never succeed differently — only an auth failure 403s.
 */
Route::post('/webhooks/summit/standing-order-collected', function (Request $request, ExternalOperationRunner $runner, SummitClient $summit) {
    abort_unless(ExternalIntegrationSetting::verifyWebhookSecret('summit', $request->header('X-Webhook-Secret')), 403);

    $data = $request->validate([
        'deal_id' => ['required', 'integer'],
        'amount' => ['required', 'numeric', 'gt:0'],
        'reference' => ['nullable', 'string'],
    ]);

    $operation = $runner->run(
        'summit',
        'standing_order_collected',
        'webhook',
        function () use ($data, $runner, $summit) {
            $deal = Deal::findOrFail($data['deal_id']);
            $deal->collectStandingOrderPayment((float) $data['amount'], $runner, $summit);

            return $data['reference'] ?? (string) $deal->id;
        },
        ['deal_id' => $data['deal_id'], 'user' => null, 'description' => "גבייה אוטומטית בהוראת קבע עבור עסקה #{$data['deal_id']}"],
    );

    return response()->json(['status' => $operation->status]);
})->name('webhooks.summit.standing-order-collected');

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
