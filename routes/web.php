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

/**
 * The user guide (מדריך למשתמשת) — deliberately public, no login needed, so it
 * can be shared with a new user before she has an account. Static content
 * only; its screenshots (public/images/guide) were taken on demo data.
 */
Route::view('/guide', 'guide')->name('guide');

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
    // A link attachment (a video on YouTube/Drive) has no file here — its email links straight to it.
    if ($attachment->isLink()) {
        return redirect()->away($attachment->url);
    }

    return \Illuminate\Support\Facades\Storage::disk('local')->download($attachment->file_reference, $attachment->file_name);
})->middleware('signed')->name('materials.attachment.download');

/** The same, for a file attached to a mailing-list email (MailingListSendAttachment::downloadUrl()). */
Route::get('/mailing-lists/attachments/{attachment}/download', function (
    \App\Models\MailingListSendAttachment $attachment,
) {
    if ($attachment->isLink()) {
        return redirect()->away($attachment->url);
    }

    return \Illuminate\Support\Facades\Storage::disk('local')->download($attachment->file_reference, $attachment->file_name);
})->middleware('signed')->name('mailing-lists.attachment.download');

/**
 * Build-plan 12 follow-up (FR-4.2) — the online form embedded in a
 * "digital" document send (Document::signUrl()). Same outside-auth +
 * `signed` pattern as the two routes above: the recipient opening this link
 * isn't logged into the app at all.
 */
Route::livewire('/documents/{document}/sign', 'document-sign')->middleware('signed')->name('documents.sign');

/**
 * Where a supplier uploads a missing invoice, from the request email
 * (Expense::requestInvoice()/invoiceUploadUrl()). Outside auth, protected by
 * an expiring `signed` link — same pattern as documents.sign above.
 */
Route::livewire('/supplier-invoice/{expense}', 'supplier-invoice-upload')->middleware('signed')->name('supplier-invoice.upload');

/**
 * Build-plan 12 follow-up (FR-4.2) — the real generated PDF embedded in a
 * "PDF" document send (Document::pdfUrl()). Same signed-link pattern; the
 * PDF is generated on the fly from the frozen rendered_content (FR-4.14),
 * never stored, since regenerating it is cheap and content never changes.
 *
 * mpdf, not dompdf: confirmed by direct comparison (both rendered on the
 * exact same Hebrew text) that dompdf does not apply the Unicode
 * bidirectional algorithm at all here — Hebrew came out with its
 * characters in raw logical (reversed-looking) order, unreadable. mpdf
 * renders the identical text correctly out of the box, no manual
 * bidi-reordering workaround needed — the only sane choice for a
 * Hebrew-first business app.
 */
Route::get('/documents/{document}/pdf', function (\App\Models\Document $document) {
    abort_if(in_array($document->document_type, ['invoice', 'credit_note'], true), 404);

    $pdf = $document->renderPdfBinary();

    return response($pdf['binary'], 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="'.$pdf['fileName'].'"',
    ]);
})->middleware('signed')->name('documents.pdf');

/**
 * Build-plan 12 follow-up (2026-09-07) — fetched server-side by Smove
 * itself (never opened by a person) to attach the real PDF to a document
 * email (Document::smoveAttachmentUrl(), SmoveClient::sendCampaign()'s
 * `campaignAttachments`). Deliberately NOT the same route as documents.pdf
 * above: Smove's attachment fetch 400s on any URL with a query string, so
 * Laravel's normal `signed` middleware (query-string based) can't be used
 * here — the token carries its own HMAC+expiry in the path instead (see
 * Document::verifySmoveAttachmentToken()), with the same security property.
 *
 * Content-Length is set explicitly (Laravel's response() doesn't send one
 * for a plain string body under HTTP/2) — confirmed 2026-09-08 by direct
 * comparison against Smove's real API: an attachment fetch with no
 * Content-Length header 400s with `ErrAttachments` even though the exact
 * same URL/bytes are fine for a normal browser GET; a static file (which
 * always carries Content-Length) fetches and attaches without it.
 */
Route::get('/documents/pdf-file/{token}', function (string $token) {
    $document = \App\Models\Document::verifySmoveAttachmentToken($token);

    abort_if(! $document || in_array($document->document_type, ['invoice', 'credit_note'], true), 404);

    $binary = $document->renderPdfBinary()['binary'];

    return response($binary, 200, ['Content-Type' => 'application/pdf', 'Content-Length' => strlen($binary)]);
})->where('token', '[0-9]+-[0-9]+-[a-f0-9]{32}\.pdf')->name('documents.pdf-file');

/**
 * Build-plan 12 follow-up (2026-09-07) — the "קבלת המסמך כ-PDF" button on a
 * digital-form document send (Document::emailPdfUrl()). Unlike documents.pdf
 * above (a direct in-browser download), this sends the PDF as a real
 * attached file in a brand-new Smove campaign — see Document::
 * requestPdfBySmove() and SmoveClient::sendDocumentAttachmentEmail() (all
 * business email goes through Smove, never Laravel Mail — the business
 * owner's explicit instruction, 2026-09-07). Same outside-auth + `signed`
 * pattern as every other document/materials link: the recipient's email/
 * name travel signed in the URL itself since they aren't logged in when
 * clicking it.
 */
Route::get('/documents/{document}/email-pdf', function (
    \App\Models\Document $document,
    Request $request,
    ActivityLogger $activityLogger,
    \App\Services\Integrations\SmoveClient $smove,
) {
    abort_if(in_array($document->document_type, ['invoice', 'credit_note'], true), 404);

    $email = (string) $request->query('email');
    $name = (string) $request->query('name', $email);

    abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 404);

    $document->requestPdfBySmove($email, $name, $smove, $activityLogger);

    return view('documents.pdf-emailed', ['document' => $document]);
})->middleware('signed')->name('documents.email-pdf');

/**
 * Where SUMIT's payment page sends the customer back (PaymentLink — see
 * SummitClient::createPaymentPage()). Outside auth: she isn't logged in.
 * The unguessable token finds the link; nothing here is trusted from the
 * query string — the OG-PaymentID SUMIT appends is only looked up in SUMIT
 * itself, and PaymentLink::complete() accepts it only if SUMIT reports a
 * valid payment carrying this link's own ExternalIdentifier.
 */
Route::get('/pay/{token}/return', function (string $token, Request $request, ExternalOperationRunner $runner, SummitClient $summit, ActivityLogger $logger) {
    $link = \App\Models\PaymentLink::where('token', $token)->firstOrFail();

    if ($request->boolean('cancelled') && ! $link->isPaid()) {
        return view('payments.returned', ['link' => $link, 'state' => 'cancelled']);
    }

    $paymentId = (int) $request->query('OG-PaymentID');

    if (! $link->isPaid() && $paymentId > 0) {
        try {
            $entity = $link->deal->businessEntity ?? throw new \RuntimeException('העסקה אינה משויכת לעוסק.');
            $link->complete($summit->getPayment($entity, $paymentId), $runner, $logger);
        } catch (\Throwable $e) {
            // The payment sync picks it up later; the customer just sees "pending".
            report($e);
        }
    }

    return view('payments.returned', ['link' => $link->refresh(), 'state' => $link->isPaid() ? 'paid' : 'pending']);
})->where('token', '[A-Za-z0-9]{40}')->name('payment-links.return');

/**
 * Build-plan 12 — two public, unauthenticated-by-Laravel-auth webhook
 * endpoints (landing page, Smove) — SUMIT has no payment webhook; its
 * payments are read by the payment sync instead (sumit:sync-payments).
 * Each is gated by ExternalIntegrationSetting::verifyWebhookSecret() against an
 * X-Webhook-Secret header — see that method's docblock for why a blank
 * configured secret (the seeded default) always 403s rather than ever
 * silently accepting an unauthenticated request. All business logic lives
 * on the relevant model (Lead::createFromLandingPage(), MaterialDelivery::
 * markOpened()) — these closures are thin, matching the
 * materials.acknowledge route above.
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
    Route::get('/expenses/export', function (\App\Services\ExpensesSpreadsheet $spreadsheet) {
        abort_unless(auth()->user()->can('expenses.view'), 403);

        $xlsx = $spreadsheet->render();

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Length' => strlen($xlsx),
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                'attachment', 'הוצאות '.now()->format('d-m-Y').'.xlsx', 'expenses-'.now()->format('Y-m-d').'.xlsx',
            ),
        ]);
    })->name('expenses.export');
    // The supplier's invoice file attached to an expense — opens in the browser (PDF/image), or downloads with ?download=1.
    Route::get('/expenses/{expense}/invoice', function (\App\Models\Expense $expense) {
        abort_unless(auth()->user()->can('expenses.view'), 403);
        abort_unless($expense->hasInvoiceFile(), 404);

        $path = $expense->document->file_reference;
        // The Hebrew original name goes in filename*; old clients get an ASCII "invoice-{id}.ext".
        $disposition = \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
            request()->boolean('download') ? 'attachment' : 'inline',
            $expense->invoiceFileName(),
            "invoice-{$expense->id}.".pathinfo($path, PATHINFO_EXTENSION),
        );

        return \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Content-Disposition' => $disposition]);
    })->name('expenses.invoice');
    Route::livewire('/cashflow-report', 'cashflow-report')->name('cashflow-report');
    // The income statement as a PDF, with the same filters as the page (years[], months[], sources[]) — detail=summary|full.
    Route::get('/cashflow-report/pdf', function (\Illuminate\Http\Request $request, \App\Services\RevenueReport $report) {
        abort_unless(auth()->user()->can('cashflow-report.view'), 403);

        $filters = $request->validate([
            'years' => ['array'], 'years.*' => ['integer', 'between:2000,2100'],
            'months' => ['array'], 'months.*' => ['integer', 'between:1,12'],
            'sources' => ['array'], 'sources.*' => ['string', 'max:50'],
            'detail' => ['nullable', 'in:'.implode(',', array_keys(\App\Services\RevenueReport::PDF_DETAILS))],
        ]);
        $detail = $filters['detail'] ?? 'full';
        $pdf = $report->statementPdf($filters['years'] ?? [], $filters['months'] ?? [], $filters['sources'] ?? [], $detail);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => strlen($pdf),
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(
                'inline', 'דוח הכנסות והוצאות '.\App\Services\RevenueReport::PDF_DETAILS[$detail].' '.now()->format('d-m-Y').'.pdf', "income-statement-{$detail}-".now()->format('Y-m-d').'.pdf',
            ),
        ]);
    })->name('cashflow-report.pdf');
    // Document templates are a tab of the settings page now; the old address still leads there.
    Route::get('/document-templates', function () {
        abort_unless(auth()->user()->can('document-templates.view') && auth()->user()->can('settings.view'), 403);

        return redirect()->route('settings', ['tab' => 'document-templates']);
    })->name('document-templates');
    Route::livewire('/documents/{document}', 'document-view')->name('document-view');
    Route::get('/documents/{document}/print', function (\App\Models\Document $document) {
        abort_unless($document->isViewableBy(auth()->user()), 403);

        $document->load(['deal.customer.school', 'lead.school', 'businessEntity', 'lines']);

        return view('documents.print', ['document' => $document]);
    })->name('document-print');
});
