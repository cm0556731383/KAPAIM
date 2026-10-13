<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Models\ExternalOperation;
use App\Models\Lead;
use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Models\MaterialDelivery;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Services\Integrations\SummitClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Build-plan 12 — external integrations (Smove, Summit, landing-page
 * webhook). Every test here uses Http::fake() — no real network call is ever
 * made — and starts from ExternalIntegrationSetting rows that are NOT
 * active/configured by default (this stage's own scope decision: the wiring
 * is real, the credentials are left for the business owner), so each test
 * explicitly activates + configures whichever system it needs.
 */
class ExternalIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);
    }

    // ----- webhook auth (ExternalIntegrationSetting::verifyWebhookSecret) -----

    public function test_a_blank_configured_secret_never_matches_anything(): void
    {
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => false, 'settings' => []]);

        $this->assertFalse(ExternalIntegrationSetting::verifyWebhookSecret('landing_page', null));
        $this->assertFalse(ExternalIntegrationSetting::verifyWebhookSecret('landing_page', ''));
        $this->assertFalse(ExternalIntegrationSetting::verifyWebhookSecret('landing_page', 'anything'));
    }

    public function test_a_configured_secret_matches_only_itself(): void
    {
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);

        $this->assertTrue(ExternalIntegrationSetting::verifyWebhookSecret('landing_page', 'sekret'));
        $this->assertFalse(ExternalIntegrationSetting::verifyWebhookSecret('landing_page', 'wrong'));
    }

    // ----- FR-1.18: landing-page lead webhook -----

    public function test_landing_page_webhook_is_rejected_without_the_configured_secret(): void
    {
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);

        $this->postJson('/webhooks/landing-page/lead', [
            'contact_name' => 'דנה', 'school_name' => 'בית ספר הדס', 'email' => 'dana@example.com', 'phone' => '050-1234567',
        ])->assertStatus(403);

        $this->assertSame(0, Lead::count());
    }

    public function test_landing_page_webhook_creates_a_lead_and_sends_a_confirmation_email(): void
    {
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);
        $this->createLeadConfirmationTemplate();

        $response = $this->postJson('/webhooks/landing-page/lead', [
            'contact_name' => 'דנה', 'school_name' => 'בית ספר הדס', 'email' => 'dana@example.com', 'phone' => '050-1234567',
        ], ['X-Webhook-Secret' => 'sekret']);

        $response->assertStatus(201);

        $lead = Lead::firstOrFail();
        $this->assertSame('dana@example.com', $lead->email);
        $this->assertSame('חדש', $lead->status->name);
        $this->assertSame('בית ספר הדס', $lead->school->name);
        $this->assertSame('דף נחיתה', $lead->source->name);

        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'lead.created', 'lead_id' => $lead->id]);

        // Confirmation email now goes through Smove (never Laravel Mail,
        // per the business owner's 2026-09-03 instruction) — Smove isn't
        // configured in this test, so the push is attempted and recorded as
        // failed, same non-blocking pattern as every other Smove call.
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'smove.lead_confirmation.failed', 'lead_id' => $lead->id]);

        // FR-1.17: joins the primary mailing list, which also attempts (and
        // fails, since Smove itself isn't configured) a Smove push — but
        // that failure must never block lead creation.
        $this->assertTrue(MailingMembership::where('mailing_list_id', MailingList::primaryList()->id)->where('lead_id', $lead->id)->exists());
    }

    public function test_landing_page_webhook_logs_a_repeat_inquiry_instead_of_duplicating_the_lead(): void
    {
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);
        $this->createLeadConfirmationTemplate();

        $school = School::create(['name' => 'בית ספר קיים', 'phone' => '03-1112222']);
        $status = StatusDefinition::create(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME, 'is_active' => true, 'sort_order' => 1]);
        $existingLead = Lead::create(['school_id' => $school->id, 'status_id' => $status->id, 'email' => 'old@example.com', 'phone' => '03-1112222']);

        $this->postJson('/webhooks/landing-page/lead', [
            'contact_name' => 'מישהי', 'school_name' => 'בית ספר קיים', 'school_phone' => '03-1112222',
            'email' => 'new@example.com', 'phone' => '050-9998888',
        ], ['X-Webhook-Secret' => 'sekret'])->assertStatus(201);

        $this->assertSame(1, Lead::count());
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'lead.repeat_inquiry', 'lead_id' => $existingLead->id]);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'smove.lead_confirmation.failed', 'lead_id' => $existingLead->id]);
    }

    public function test_landing_page_webhook_auto_assigns_the_least_loaded_sales_rep(): void
    {
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);
        $this->createLeadConfirmationTemplate();

        $salesRepRole = Role::create(['name' => Role::SALES_REP_ROLE_NAME, 'is_active' => true]);
        $salesRepRole->permissions()->create(['resource' => 'leads', 'action' => 'view', 'is_allowed' => true]);
        $rep = User::create(['name' => 'נציגת מכירות', 'email' => 'rep@kapaim.test', 'password' => 'password', 'role_id' => $salesRepRole->id, 'is_active' => true]);

        $this->postJson('/webhooks/landing-page/lead', [
            'contact_name' => 'דנה', 'school_name' => 'בית ספר חדש', 'email' => 'dana2@example.com', 'phone' => '050-1234567',
        ], ['X-Webhook-Secret' => 'sekret'])->assertStatus(201);

        $this->assertSame($rep->id, Lead::firstOrFail()->assigned_user_id);
    }

    public function test_landing_page_webhook_leaves_the_lead_unassigned_when_no_sales_rep_exists(): void
    {
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);
        $this->createLeadConfirmationTemplate();

        $this->postJson('/webhooks/landing-page/lead', [
            'contact_name' => 'דנה', 'school_name' => 'בית ספר חדש', 'email' => 'dana3@example.com', 'phone' => '050-1234567',
        ], ['X-Webhook-Secret' => 'sekret'])->assertStatus(201);

        $this->assertNull(Lead::firstOrFail()->assigned_user_id);
    }

    // ----- FR-5.10/FR-5.11: Smove material-opened webhook -----

    public function test_material_opened_webhook_is_rejected_without_the_configured_secret(): void
    {
        $delivery = $this->createSentMaterialDelivery();

        $this->postJson("/webhooks/smove/material-opened/{$delivery->id}")->assertStatus(403);
        $this->assertNull($delivery->fresh()->opened_at);
    }

    public function test_material_opened_webhook_marks_the_delivery_opened(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);
        $delivery = $this->createSentMaterialDelivery();

        $this->postJson("/webhooks/smove/material-opened/{$delivery->id}", [], ['X-Webhook-Secret' => 'sekret'])->assertOk();

        $this->assertNotNull($delivery->fresh()->opened_at);
        $this->assertSame('נפתח', $delivery->fresh()->status->name);
    }

    // ----- receipts and list removal against SUMIT/Smove -----

    public function test_a_receipt_sumit_rejects_is_not_kept_and_can_be_issued_again(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::sequence()
            ->push(['Status' => 1, 'UserErrorMessage' => 'יש להזין מוטב/מחויב'], 200)
            ->push(['Status' => 0, 'Data' => ['DocumentID' => 559]], 200)]);
        $deal = $this->dealWithInvoice(400);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('העברה', 'bank_transfer')->id]);
        $payment = $deal->fresh()->recordPayment($deal->fresh()->version, 400.0);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->call('issueReceipt', $payment->id)
            ->assertSet('paymentError', 'ההפקה מול SUMIT נכשלה, ולכן הקבלה לא נרשמה: SUMIT: יש להזין מוטב/מחויב');
        $this->assertNull($payment->fresh()->receipt);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->call('issueReceipt', $payment->id)
            ->assertSet('paymentError', null);
        $this->assertNotNull($payment->fresh()->receipt);
    }

    public function test_a_receipt_before_payment_uses_the_deals_payment_method(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 560]], 200)]);
        $deal = $this->dealWithInvoice(400);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('העברה', 'bank_transfer')->id]);

        \App\Models\Receipt::issueFor($deal->fresh(), null, app(ExternalOperationRunner::class), app(SummitClient::class));

        Http::assertSent(fn ($request) => ($request['Details']['Type'] ?? null) === 2
            && $request['Payments'][0]['Type'] === 3
            && $request['Payments'][0]['Amount'] == 400);
    }

    public function test_leaving_a_list_not_linked_to_smove_makes_no_smove_call(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake();
        $deal = $this->dealWithInvoice();
        $list = MailingList::create(['name' => 'מנויים '.random_int(1, 999999), 'list_type' => 'subscription', 'is_active' => true]);

        MailingMembership::removeCustomer($list, $deal->customer);

        $this->assertSame(0, ExternalOperation::where('operation_type', 'mailing_list_remove')->count());
    }

    // ----- non-blocking Smove push on a mailing-list membership change -----

    public function test_a_failed_smove_push_never_blocks_joining_the_mailing_list(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(['rest.smoove.io/*' => Http::response(['message' => 'nope'], 500)]);

        $customer = $this->createCustomer();

        $this->assertTrue(MailingMembership::where('mailing_list_id', MailingList::primaryList()->id)->where('customer_id', $customer->id)->exists());
        $this->assertDatabaseHas('external_operations', ['system' => 'smove', 'operation_type' => 'mailing_list_join', 'status' => ExternalOperation::STATUS_FAILED]);
    }

    public function test_a_successful_smove_push_is_recorded(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(['rest.smoove.io/*' => Http::response(['id' => 'ext-123'], 200)]);

        MailingList::primaryList()->update(['smove_list_id' => 123]);

        $this->createCustomer();

        $this->assertDatabaseHas('external_operations', [
            'system' => 'smove', 'operation_type' => 'mailing_list_join',
            'status' => ExternalOperation::STATUS_SUCCESS, 'external_reference' => 'ext-123',
        ]);
    }

    /**
     * Regression test for the 2026-09-06 finding: Smove resets an
     * already-confirmed contact's canReceiveEmails back to false whenever a
     * POST /Contacts call includes lists_ToSubscribe — even for a list the
     * contact was never on before. MailingMembership::pushToSmove() must
     * therefore never issue a 'join' call at all once
     * SmoveClient::hasConfirmedConsent() says the contact is already
     * confirmed — joining a brand-new list is still recorded locally, just
     * never mirrored to Smove.
     */
    public function test_an_already_confirmed_smove_contact_is_never_re_subscribed(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        MailingList::primaryList()->update(['smove_list_id' => 123]);

        Http::fake(function ($request) {
            return $request->method() === 'GET'
                ? Http::response([['email' => 'confirmed@example.com', 'canReceiveEmails' => true]], 200)
                : Http::response(['id' => 'ext-123'], 200);
        });

        $customer = $this->createCustomer();
        $customer->contacts()->update(['email' => 'confirmed@example.com']);

        $joinsBefore = ExternalOperation::where('operation_type', 'mailing_list_join')->count();

        $program = Program::create(['name' => 'תוכנית לבדיקת רשימות', 'price' => 100, 'is_active' => true]);
        $programList = MailingList::forProgram($program);
        $programList->update(['smove_list_id' => 999]);

        MailingMembership::addCustomer($programList, $customer);

        // Recorded locally as a real, active membership on the new list...
        $this->assertTrue(
            MailingMembership::where('mailing_list_id', $programList->id)
                ->where('customer_id', $customer->id)
                ->where('membership_status', MailingMembership::STATUS_ACTIVE)
                ->exists(),
        );
        // ...but no new Smove push was attempted (would have reset consent).
        $this->assertSame($joinsBefore, ExternalOperation::where('operation_type', 'mailing_list_join')->count());
    }

    /**
     * The other half of the 2026-09-06 fix: while a contact is NOT yet
     * confirmed, a 'join' must broaden lists_ToSubscribe to every mailing
     * list this app has linked to Smove — not just the one list the caller
     * actually asked to join — so the contact's single confirmation click
     * covers every list up front. Without this, every later deal/program
     * purchase would need its own separate confirmation email.
     */
    public function test_a_not_yet_confirmed_contacts_first_join_is_broadened_to_every_linked_list(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        MailingList::primaryList()->update(['smove_list_id' => 111]);

        $program = Program::create(['name' => 'תוכנית לבדיקת רשימות', 'price' => 100, 'is_active' => true]);
        MailingList::forProgram($program)->update(['smove_list_id' => 222]);
        MailingList::subscribersList()->update(['smove_list_id' => 333]);

        Http::fake(function ($request) {
            return $request->method() === 'GET'
                ? Http::response([], 200) // not confirmed / not seen yet
                : Http::response(['id' => 'ext-123'], 200);
        });

        $this->createCustomer(); // triggers the very first join, on the primary list only

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/Contacts')) {
                return false;
            }

            $subscribed = $request->data()['lists_ToSubscribe'] ?? [];
            sort($subscribed);

            return $subscribed === [111, 222, 333];
        });
    }

    /**
     * 2026-09-08 fix: a customer can have more than one CONTACT row, but
     * addCustomer() used to push only the first primary contact to Smove —
     * a second contact never got its own Smove record, so a mailing-list
     * send never reached them. addCustomer() now pushes every contact with
     * an email on the membership's first activation.
     */
    public function test_joining_a_list_pushes_every_contact_not_just_the_first(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        MailingList::primaryList()->update(['smove_list_id' => 111]);

        Http::fake(function ($request) {
            return $request->method() === 'GET'
                ? Http::response([], 200)
                : Http::response(['id' => 'ext-123'], 200);
        });

        $customer = $this->createCustomer();
        $customer->contacts()->create([
            'school_id' => $customer->school_id, 'customer_id' => $customer->id,
            'name' => 'איש קשר שני', 'email' => 'second'.random_int(1, 999999).'@example.com',
        ]);

        MailingList::primaryList()->update(['smove_list_id' => 111]); // re-trigger a fresh join below
        MailingMembership::where('mailing_list_id', MailingList::primaryList()->id)->where('customer_id', $customer->id)->delete();
        MailingMembership::addCustomer(MailingList::primaryList(), $customer);

        $emailsPushed = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/Contacts'))
            ->map(fn ($pair) => $pair[0]->data()['email'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $this->assertCount(2, $emailsPushed);
    }

    /**
     * 2026-09-08 request: adding an additional contact to an already-active
     * customer must reach Smove too — addCustomer() alone no-ops once the
     * membership is already active, so ⚡customer-detail.blade.php's
     * addContact() also calls MailingMembership::syncContact(), which must
     * join the new contact to every list the customer is currently on,
     * tagged with its own externalId so Smove keeps a distinct record per
     * contact rather than merging them.
     */
    public function test_syncing_a_new_contact_joins_it_to_every_active_list_with_its_own_external_id(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        MailingList::primaryList()->update(['smove_list_id' => 111]);

        $program = Program::create(['name' => 'תוכנית לבדיקת אנשי קשר', 'price' => 100, 'is_active' => true]);
        MailingList::forProgram($program)->update(['smove_list_id' => 222]);

        Http::fake(['rest.smoove.io/*' => Http::response(['id' => 'ext-123'], 200)]);

        $customer = $this->createCustomer();
        MailingMembership::addCustomer(MailingList::forProgram($program), $customer);

        Http::fake(function ($request) {
            return $request->method() === 'GET'
                ? Http::response([], 200)
                : Http::response(['id' => 'ext-456'], 200);
        });

        $newContact = $customer->contacts()->create([
            'school_id' => $customer->school_id, 'customer_id' => $customer->id,
            'name' => 'הורה נוסף', 'email' => 'parent'.random_int(1, 999999).'@example.com',
        ]);

        MailingMembership::syncContact($customer, $newContact);

        Http::assertSent(function ($request) use ($newContact) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/Contacts')) {
                return false;
            }

            $data = $request->data();

            return ($data['email'] ?? null) === $newContact->email
                && ($data['externalId'] ?? null) === "contact-{$newContact->id}";
        });
    }

    /** End-to-end: adding a contact through the actual UI action (⚡customer-detail.blade.php) reaches Smove, not just the model method in isolation. */
    public function test_adding_a_contact_through_the_customer_screen_reaches_smove(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        MailingList::primaryList()->update(['smove_list_id' => 111]);

        Http::fake(['rest.smoove.io/*' => Http::response(['id' => 'ext-123'], 200)]);

        $customer = $this->createCustomer();

        Http::fake(function ($request) {
            return $request->method() === 'GET'
                ? Http::response([], 200)
                : Http::response(['id' => 'ext-456'], 200);
        });

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('contactName', 'הורה שני')
            ->set('contactEmail', 'second-parent'.random_int(1, 999999).'@example.com')
            ->call('addContact');

        $newContact = $customer->contacts()->latest('id')->first();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/Contacts')
            && ($request->data()['email'] ?? null) === $newContact->email
            && ($request->data()['externalId'] ?? null) === "contact-{$newContact->id}");
    }

    public function test_syncing_a_contact_with_no_email_touches_nothing(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(['rest.smoove.io/*' => Http::response(['id' => 'ext-123'], 200)]);

        $customer = $this->createCustomer();
        $noEmailContact = $customer->contacts()->create([
            'school_id' => $customer->school_id, 'customer_id' => $customer->id, 'name' => 'ללא מייל',
        ]);

        Http::fake();

        MailingMembership::syncContact($customer, $noEmailContact);

        Http::assertNothingSent();
    }

    // ----- invoice send pushes to Summit (Document::sendTo()) -----

    public function test_sending_an_invoice_pushes_it_to_summit(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555, 'DocumentNumber' => 10001]], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);

        $invoice->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertDatabaseHas('external_operations', [
            'system' => 'summit', 'operation_type' => 'issue_invoice', 'document_id' => $invoice->id,
            'status' => ExternalOperation::STATUS_SUCCESS, 'external_reference' => '555',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sumit.co.il/accounting/documents/create/'
            && $request['Credentials'] === ['CompanyID' => 12345678, 'APIKey' => 'x']
            // The helper's entity is an עוסק פטור — SUMIT only lets it issue a חשבון עסקה.
            && $request['Details']['Type'] === 3
            && $request['Details']['Customer']['ExternalIdentifier'] === 'kapaim-customer-'.$deal->customer_id
            && $request['Details']['SendByEmail']['EmailAddress'] === 'billing@example.com'
            && ! isset($request['Details']['IsDraft'])
            && $request['Items'][0]['TotalPrice'] == $deal->agreed_amount);
    }

    public function test_a_sumit_business_error_deletes_the_invoice_draft(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        // SUMIT answers HTTP 200 even for errors — only Status 0 is success.
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 1, 'UserErrorMessage' => 'מפתח API שגוי', 'Data' => null], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);

        $operations = $invoice->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertSame(ExternalOperation::STATUS_FAILED, $operations['summit']->status);
        $this->assertStringContainsString('מפתח API שגוי', $operations['summit']->error_message);
        $this->assertTrue($operations['deleted']);
        $this->assertNull($invoice->fresh());
        // The failed operation stays in the log, only unlinked from the deleted document.
        $this->assertSame($deal->id, $operations['summit']->fresh()->deal_id);
        // The deal can generate a new invoice again.
        $this->assertSame(0, $deal->documents()->where('document_type', 'invoice')->count());
    }

    public function test_test_mode_is_on_until_explicitly_turned_off(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => []]);

        $this->assertTrue(app(SummitClient::class)->isTestMode());
    }

    public function test_test_mode_issues_sumit_documents_as_drafts(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => true]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 556]], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();

        app(SummitClient::class)->issueInvoice($invoice);

        Http::assertSent(fn ($request) => ($request['Details']['IsDraft'] ?? null) === true);
    }

    public function test_an_invoice_whose_summit_issuance_fails_is_deleted_and_the_deal_shows_why(): void
    {
        // No Summit setting at all — exactly the "not configured yet" failure.
        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);

        $operations = $invoice->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertSame(ExternalOperation::STATUS_FAILED, $operations['summit']->status);
        $this->assertNull($invoice->fresh());
        $this->assertSame(0, \DB::table('document_recipients')->where('document_id', $invoice->id)->count());
        $this->assertNull($deal->fresh()->issuedInvoice());
    }

    public function test_sending_a_failing_invoice_from_its_screen_returns_to_the_deal_with_the_error(): void
    {
        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);

        Livewire::actingAs($this->owner)->test('document-view', ['document' => $invoice])
            ->set('workingRecipients', [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']])
            ->call('send', 'digital')
            ->assertRedirect(route('deal-detail', $deal->id));

        $this->assertNull($invoice->fresh());
        session()->put('documentError', 'טיוטת החשבונית נמחקה');
        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->assertSee('טיוטת החשבונית נמחקה');
    }

    public function test_a_credit_note_whose_summit_issuance_fails_stays_an_unsent_draft(): void
    {
        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $creditNote = Document::create([
            'deal_id' => $deal->id,
            'document_template_id' => $invoice->document_template_id,
            'business_entity_id' => $invoice->business_entity_id,
            'document_type' => 'credit_note',
            'status_id' => $invoice->status_id,
            'format' => 'digital',
            'rendered_content' => 'זיכוי',
        ]);

        $operations = $creditNote->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertSame(ExternalOperation::STATUS_FAILED, $operations['summit']->status);
        $this->assertArrayNotHasKey('deleted', $operations);
        $this->assertNotNull($creditNote->fresh());
        $this->assertNull($creditNote->fresh()->sent_at);
    }

    /**
     * An invoice/credit note is issued to Summit only — never emailed via
     * Smove (no online sign form or PDF exists for these two types, unlike
     * every other document type). Smove is active and configured here on
     * purpose, so a false "never touches Smove" pass can't be explained
     * away by Smove simply not being configured.
     */
    public function test_sending_an_invoice_never_touches_smove(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555]], 200), 'rest.smoove.io/*' => Http::response(['id' => 42], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);

        $operations = $invoice->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertNull($operations['smove']);
        $this->assertSame(ExternalOperation::STATUS_SUCCESS, $operations['summit']->status);
        // Not a blanket "nothing hit smoove.io" — creating the deal/customer above can trigger
        // unrelated mailing-list contact syncs (FR-5.21 etc.); only the email-sending endpoint matters here.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/Campaigns'));
    }

    public function test_a_quote_send_never_touches_summit(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake();

        $deal = $this->createDeal();
        $quote = Document::generateFor($deal, $this->createTemplate('quote'));

        $operations = $quote->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertNull($operations['summit']);
        Http::assertNothingSent();
    }

    // ----- document send pushes the email itself to Smove -----

    public function test_sending_a_document_sends_the_email_via_smove(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/Contacts')) {
                return Http::response([], 200); // not found yet — forces the create path
            }

            return Http::response(['id' => 42], 200);
        });

        $deal = $this->createDeal();
        $quote = Document::generateFor($deal, $this->createTemplate('quote'));

        $operations = $quote->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertSame(ExternalOperation::STATUS_SUCCESS, $operations['smove']->status);
        $this->assertDatabaseHas('external_operations', [
            'system' => 'smove', 'operation_type' => 'document_send', 'document_id' => $quote->id,
            'status' => ExternalOperation::STATUS_SUCCESS,
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns'));
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/Contacts') && str_contains($request->url(), 'updateIfExists'));
    }

    /**
     * Confirmed 2026-09-06 against Smove's real API: a write to an existing
     * contact via updateIfExists=true&restoreIfDeleted=true&restoreIfUnsubscribed=true
     * comes back with canReceiveEmails false regardless of what's sent — for
     * a pre-existing contact and a freshly created one alike. So a recipient
     * who already exists in Smove (found by email) must never be written to
     * again by a document send — only looked up and referenced by id.
     */
    public function test_sending_a_document_to_an_existing_smove_contact_never_overwrites_it(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/Contacts')) {
                return Http::response([['id' => 869408278, 'email' => 'billing@example.com', 'canReceiveEmails' => true]], 200);
            }

            return Http::response(['id' => 42], 200);
        });

        $deal = $this->createDeal();
        $quote = Document::generateFor($deal, $this->createTemplate('quote'));

        $operations = $quote->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertSame(ExternalOperation::STATUS_SUCCESS, $operations['smove']->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/Contacts') && $request->method() !== 'GET');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns') && in_array(869408278, $request->data()['toMembersById'] ?? [], true));
    }

    public function test_sending_a_document_with_no_valid_recipient_email_never_touches_smove(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake();

        $deal = $this->createDeal();
        $quote = Document::generateFor($deal, $this->createTemplate('quote'));

        $operations = $quote->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => null]],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertNull($operations['smove']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/Campaigns'));
    }

    /**
     * 2026-09-07 fix: `campaignAttachments` (undocumented in Smove's Swagger
     * description text, confirmed empirically against the real API) is what
     * makes "שליחה כ-PDF" actually attach the real file — the business
     * owner explicitly wants every document email sent through Smove only,
     * never a separate Laravel Mail send.
     */
    public function test_sending_a_document_as_pdf_attaches_the_real_file_via_smove(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/Contacts')) {
                return Http::response([], 200);
            }

            return Http::response(['id' => 42], 200);
        });

        $deal = $this->createDeal();
        $quote = Document::generateFor($deal, $this->createTemplate('quote'));

        $operations = $quote->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'pdf',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
            app(SmoveClient::class),
        );

        $this->assertSame(ExternalOperation::STATUS_SUCCESS, $operations['smove']->status);
        Http::assertSent(function ($request) use ($quote) {
            if (! str_contains($request->url(), '/Campaigns')) {
                return false;
            }

            $attachments = $request->data()['campaignAttachments'] ?? [];

            return count($attachments) === 1
                && str_contains($attachments[0], '/documents/pdf-file/')
                && ! str_contains($attachments[0], '?')
                && str_ends_with($attachments[0], '.pdf');
        });
    }

    /** The "קבלת המסמך כ-PDF במייל" button — routes/web.php's documents.email-pdf — now sends through Smove (campaignAttachments), never Laravel Mail. */
    public function test_the_request_pdf_by_email_link_sends_a_smove_campaign_with_the_attachment(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(function ($request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/Contacts')) {
                return Http::response([], 200);
            }

            return Http::response(['id' => 42], 200);
        });

        $deal = $this->createDeal();
        $quote = Document::generateFor($deal, $this->createTemplate('quote'));
        $url = $quote->emailPdfUrl('billing@example.com', 'לקוחה');

        $response = $this->get($url);

        $response->assertOk();
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/Campaigns')) {
                return false;
            }

            $attachments = $request->data()['campaignAttachments'] ?? [];

            return count($attachments) === 1 && str_contains($attachments[0], '/documents/pdf-file/');
        });
        $this->assertTrue(\App\Models\ActivityLog::where('activity_type', 'document.pdf_emailed')->where('document_id', $quote->id)->exists());
    }

    // ----- explicit Deal actions: card charge / standing-order registration -----

    public function test_charging_a_card_requires_summit_to_be_configured(): void
    {
        $deal = $this->createDeal();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('אשראי', 'card')->id]);

        $this->expectException(RuntimeException::class);
        $deal->chargeCardViaSummit($deal->version, 100.0, app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    public function test_charging_a_card_records_a_payment_only_after_summit_confirms(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['Payment' => ['ID' => 777, 'ValidPayment' => true]]], 200)]);
        $this->createBusinessEntity(); // the only SUMIT company — used when the deal has no invoice yet

        $deal = $this->createDeal(500);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('אשראי', 'card')->id]);

        $payment = $deal->fresh()->chargeCardViaSummit($deal->version, 500.0, app(ExternalOperationRunner::class), app(SummitClient::class));

        $this->assertSame(500.0, (float) $payment->amount);
        $this->assertSame(500.0, $deal->fresh()->totalPaid());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sumit.co.il/billing/payments/charge/'
            && $request['PreventDocumentCreation'] === true
            && $request['Items'][0]['UnitPrice'] == 500
            && ! isset($request['PaymentMethod']));
    }

    public function test_charging_a_card_records_no_payment_when_summit_declines(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['Payment' => ['ID' => 778, 'ValidPayment' => false, 'StatusDescription' => 'כרטיס חסום']]], 200)]);
        $this->createBusinessEntity(); // the only SUMIT company — used when the deal has no invoice yet

        $deal = $this->createDeal(500);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('אשראי', 'card')->id]);
        $deal->refresh();

        $this->expectException(RuntimeException::class);

        try {
            $deal->chargeCardViaSummit($deal->version, 500.0, app(ExternalOperationRunner::class), app(SummitClient::class));
        } finally {
            $this->assertSame(0.0, $deal->fresh()->totalPaid());
        }
    }

    public function test_registering_a_standing_order_requires_the_recurring_payment_method(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['RecurringCustomerItemIDs' => [11]]], 200)]);

        $deal = $this->createDeal();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('אשראי', 'card')->id]);

        $this->expectException(RuntimeException::class);
        $deal->fresh()->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    public function test_registering_a_standing_order_sends_a_monthly_recurring_item(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['RecurringCustomerItemIDs' => [11]]], 200)]);
        $this->createBusinessEntity(); // the only SUMIT company — used when the deal has no invoice yet

        $deal = $this->createDeal(1200);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('הוראת קבע', 'recurring')->id]);

        $deal->fresh()->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.sumit.co.il/billing/recurring/charge/'
            && $request['Items'][0]['Duration_Months'] === 1
            && $request['Items'][0]['UnitPrice'] == 1200);
        $this->assertDatabaseHas('external_operations', ['operation_type' => 'standing_order_registered', 'status' => ExternalOperation::STATUS_SUCCESS, 'external_reference' => '11']);
    }

    // ----- a deal is locked to the business entity SUMIT first issued from -----

    public function test_a_successful_sumit_issuance_locks_the_deal_to_its_business_entity(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555]], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);
        $this->assertNull($deal->business_entity_id);

        $invoice->sendTo([['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']], 'digital',
            app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class));

        $this->assertSame($invoice->business_entity_id, $deal->fresh()->business_entity_id);
    }

    public function test_a_failed_sumit_issuance_does_not_lock_the_deal(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 1, 'UserErrorMessage' => 'שגיאה'], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);
        $invoice->sendTo([['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']], 'digital',
            app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class));

        $this->assertNull($deal->fresh()->business_entity_id);
    }

    public function test_a_locked_deal_cannot_generate_an_invoice_from_another_entity(): void
    {
        $deal = $this->dealWithInvoice();
        $locked = $deal->documents()->where('document_type', 'invoice')->firstOrFail()->businessEntity;
        $deal->lockToBusinessEntity($locked);
        $deal->documents()->where('document_type', 'invoice')->delete();

        try {
            Document::generateFor($deal->fresh(), $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity()->id);
            $this->fail('expected the locked entity to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($locked->name, $e->getMessage());
        }

        $invoice = Document::generateFor($deal->fresh(), $this->createTemplate('invoice'), 'digital', $locked->id);
        $this->assertSame($locked->id, $invoice->business_entity_id);
    }

    public function test_a_locked_deal_refuses_sumit_documents_from_another_entity_and_charges_its_own(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555, 'Payment' => ['ValidPayment' => true, 'ID' => 9]]], 200)]);

        $deal = $this->dealWithInvoice();
        $other = $this->createBusinessEntity();
        $other->update(['sumit_company_id' => '99999999']);
        $deal->lockToBusinessEntity($other);

        try {
            app(SummitClient::class)->issueCreditNote($this->creditNoteFor($deal->fresh()));
            $this->fail('expected the locked entity to be enforced');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($other->name, $e->getMessage());
        }
        Http::assertNothingSent();

        app(SummitClient::class)->chargeCard($deal->fresh(), 100);
        Http::assertSent(fn ($request) => $request['Credentials']['CompanyID'] === 99999999);
    }

    public function test_the_deal_screen_shows_the_locked_entity_preselected(): void
    {
        $deal = $this->dealWithInvoice();
        $entity = $deal->documents()->where('document_type', 'invoice')->firstOrFail()->businessEntity;
        $deal->lockToBusinessEntity($entity);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->assertSet('invoiceBusinessEntityId', (string) $entity->id)
            ->assertSee('נעול לעסקה');
    }

    public function test_sumit_emails_the_receipt_to_the_customers_billing_address(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 557]], 200)]);

        $deal = $this->dealWithInvoice(400);
        Contact::create(['customer_id' => $deal->customer_id, 'name' => 'הנהלת חשבונות', 'email' => 'accounts@example.com', 'is_accounting_contact' => true]);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('העברה', 'bank_transfer')->id]);
        $payment = $deal->fresh()->recordPayment($deal->fresh()->version, 400.0);

        \App\Models\Receipt::issueFor($deal->fresh(), $payment, app(ExternalOperationRunner::class), app(SummitClient::class));

        Http::assertSent(fn ($request) => ($request['Details']['Type'] ?? null) === 2
            && ($request['Details']['SendByEmail']['EmailAddress'] ?? null) === 'accounts@example.com');
    }

    public function test_a_credit_note_is_issued_from_the_deals_business_entity(): void
    {
        $this->createTemplate('credit_note');
        $deal = $this->dealWithInvoice();
        $entity = $deal->documents()->where('document_type', 'invoice')->firstOrFail()->businessEntity;
        $deal->lockToBusinessEntity($entity);

        // Generated from the deal screen, which passes no entity for a credit note.
        $creditNote = Document::generateFor($deal->fresh(), DocumentTemplate::where('document_type', 'credit_note')->firstOrFail());
        $this->assertSame($entity->id, $creditNote->business_entity_id);
        $creditNote->delete();
        $this->assertSame($entity->id, Document::generateCreditNoteFor($deal->fresh(), 50)->business_entity_id);
    }

    public function test_a_licensed_dealer_issues_a_tax_invoice_and_credits_it(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555]], 200)]);

        $deal = $this->dealWithInvoice(400, 'עוסק מורשה');
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);
        $invoice->sendTo([['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']], 'digital',
            app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class));
        Http::assertSent(fn ($request) => ($request['Details']['Type'] ?? null) === 0);

        app(SummitClient::class)->issueCreditNote($this->creditNoteFor($deal->fresh()));
        Http::assertSent(fn ($request) => ($request['Details']['Type'] ?? null) === 5 && $request['OriginalDocumentID'] === 555);
    }

    public function test_an_exempt_dealer_credits_with_a_credit_receipt(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 556]], 200)]);

        $deal = $this->dealWithInvoice(400, 'עוסק פטור');

        app(SummitClient::class)->issueCreditNote($this->creditNoteFor($deal));
        Http::assertSent(fn ($request) => ($request['Details']['Type'] ?? null) === 7
            && ! isset($request['OriginalDocumentID'])
            && ! isset($request['Items'])
            && $request['Payments'][0]['Amount'] == 100);
    }

    // ----- QA pass 2026-10-13: duplicate issuance, payment types, locks -----

    private function summitConnected(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555, 'Payment' => ['ValidPayment' => true, 'ID' => 9]]], 200)]);
    }

    private function sendNow(Document $document): array
    {
        return $document->sendTo([['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']], 'digital',
            app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class));
    }

    public function test_a_sent_invoice_is_never_issued_in_summit_twice(): void
    {
        $this->summitConnected();
        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);
        $staleTab = Document::find($invoice->id); // loaded before the first send

        $this->sendNow($invoice);

        try {
            $this->sendNow($staleTab);
            $this->fail('A second send of the same document must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('כבר נשלח', $e->getMessage());
        }

        $this->assertSame(1, ExternalOperation::where('document_id', $invoice->id)->where('operation_type', 'issue_invoice')->count());
        $this->assertSame(1, $invoice->recipients()->count());
    }

    public function test_a_receipt_before_payment_is_issued_once_and_covers_the_deals_payments(): void
    {
        $this->summitConnected();
        $deal = $this->dealWithInvoice();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('העברה', 'bank_transfer')->id]);
        $runner = app(ExternalOperationRunner::class);
        $summit = app(SummitClient::class);

        \App\Models\Receipt::issueFor($deal->fresh(), null, $runner, $summit);

        try {
            \App\Models\Receipt::issueFor($deal->fresh(), null, $runner, $summit);
            $this->fail('A second receipt before payment must be refused.');
        } catch (RuntimeException) {
        }

        $payment = $deal->fresh()->recordPayment($deal->fresh()->version, 400);

        try {
            \App\Models\Receipt::issueFor($deal->fresh(), $payment, $runner, $summit);
            $this->fail('The receipt before payment already covers this payment.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, \App\Models\Receipt::count());
        $this->assertSame(1, ExternalOperation::where('operation_type', 'issue_receipt')->count());
    }

    public function test_a_receipt_before_payment_is_refused_once_payments_have_receipts(): void
    {
        $this->summitConnected();
        $deal = $this->dealWithInvoice();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('העברה', 'bank_transfer')->id]);
        $payment = $deal->fresh()->recordPayment($deal->fresh()->version, 100);
        \App\Models\Receipt::issueFor($deal->fresh(), $payment, app(ExternalOperationRunner::class), app(SummitClient::class));

        $this->expectException(RuntimeException::class);
        \App\Models\Receipt::issueFor($deal->fresh(), null, app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    public function test_a_deal_gets_one_credit_note_only(): void
    {
        $this->createTemplate('credit_note');
        $deal = $this->dealWithInvoice();

        Document::generateCreditNoteFor($deal->fresh(), 50);

        $this->assertFalse(Document::canGenerate($deal->fresh(), 'credit_note'));
        $this->expectException(RuntimeException::class);
        Document::generateCreditNoteFor($deal->fresh(), 50);
    }

    public function test_standing_order_and_custom_payment_methods_go_to_summit_with_a_type_it_accepts(): void
    {
        $this->summitConnected();
        $deal = $this->dealWithInvoice();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('הוראת קבע', 'recurring')->id]);
        $payment = $deal->fresh()->recordPayment($deal->fresh()->version, 100);
        \App\Models\Receipt::issueFor($deal->fresh(), $payment, app(ExternalOperationRunner::class), app(SummitClient::class));

        Http::assertSent(fn ($request) => ($request['Details']['Type'] ?? null) === 2
            && $request['Payments'][0]['Type'] === 5 && array_key_exists('Details_CreditCard', $request['Payments'][0]));

        $other = $this->dealWithInvoice();
        $other->update(['payment_method_id' => $this->createPaymentMethod('ביט', 'bit')->id]);
        \App\Models\Receipt::issueFor($other->fresh(), null, app(ExternalOperationRunner::class), app(SummitClient::class));

        // SUMIT rejects a "General" (1) payment without a payer — an unknown type goes as "Other" (8), named.
        Http::assertSent(fn ($request) => ($request['Payments'][0]['Type'] ?? null) === 8
            && str_starts_with($request['Payments'][0]['Details_Other']['Type'], 'ביט'));
        Http::assertNotSent(fn ($request) => ($request['Payments'][0]['Type'] ?? null) === 1);
    }

    public function test_a_confirmed_documents_fields_can_no_longer_be_changed(): void
    {
        $deal = $this->createDeal();
        $template = $this->createTemplate('order_form');
        $field = $template->fields()->create(['name' => 'הערה', 'field_type' => 'free_text', 'is_required' => false, 'sort_order' => 1]);
        $template->update(['content' => 'הערה: '.$field->placeholderToken()]);
        $orderForm = Document::generateFor($deal, $template->fresh());
        $orderForm->confirm([$field->id => 'מה שהלקוחה אישרה'], app(ActivityLogger::class));

        $this->expectException(RuntimeException::class);
        $orderForm->fresh()->submitFieldValues([$field->id => 'שינוי אחרי חתימה']);
    }

    public function test_a_card_is_not_charged_against_a_stale_deal_version(): void
    {
        $this->summitConnected();
        $deal = $this->dealWithInvoice();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('אשראי', 'card')->id]);
        $deal = $deal->fresh();
        $staleVersion = $deal->version;
        Deal::whereKey($deal->id)->increment('version'); // someone else changed the deal meanwhile

        try {
            $deal->chargeCardViaSummit($staleVersion, 100, app(ExternalOperationRunner::class), app(SummitClient::class));
            $this->fail('A stale version must be refused before the card is charged.');
        } catch (RuntimeException) {
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/billing/payments/charge/'));
        $this->assertSame(0, $deal->payments()->count());
    }

    private function creditNoteFor(Deal $deal): Document
    {
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $creditNote = Document::create([
            'deal_id' => $deal->id,
            'document_template_id' => $invoice->document_template_id,
            'business_entity_id' => $invoice->business_entity_id,
            'document_type' => 'credit_note',
            'status_id' => $invoice->status_id,
            'format' => 'digital',
            'rendered_content' => 'זיכוי',
        ]);
        $creditNote->addLine('זיכוי', 100);

        return $creditNote;
    }

    public function test_receipt_and_credit_note_are_linked_to_the_sumit_invoice(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 555]], 200)]);

        $deal = $this->dealWithInvoice(400);
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->update(['sent_at' => null]);
        $invoice->sendTo([['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']], 'digital',
            app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class));

        $deal->update(['payment_method_id' => $this->createPaymentMethod('העברה', 'bank_transfer')->id]);
        $payment = $deal->fresh()->recordPayment($deal->fresh()->version, 400.0);
        \App\Models\Receipt::issueFor($deal->fresh(), $payment, app(ExternalOperationRunner::class), app(SummitClient::class));

        Http::assertSent(fn ($request) => ($request['Details']['Type'] ?? null) === 2
            && $request['OriginalDocumentID'] === 555
            && $request['Payments'][0]['Amount'] == 400
            && $request['Payments'][0]['Type'] === 3);
    }

    // ----- settings screen (business owner fills in real credentials) -----

    public function test_each_business_entity_has_its_own_sumit_credentials_and_connection_check(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => false, 'settings' => []]);
        $entity = $this->createBusinessEntity();
        $entity->update(['sumit_company_id' => null, 'sumit_api_key' => null]);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['Rate' => 18]], 200)]);

        Livewire::actingAs($this->owner)->test('settings')
            ->assertSet('summitTestMode', true)
            ->set("sumitCompanies.{$entity->id}.name", 'כפיים בע"מ')
            ->set("sumitCompanies.{$entity->id}.connection_name", 'סאמיט — חשבון ראשי')
            ->set("sumitCompanies.{$entity->id}.company_id", '87654321')
            ->set("sumitCompanies.{$entity->id}.api_key", 'private-key')
            ->call('testSumitCompany', $entity->id)
            ->assertHasNoErrors()
            ->assertSet("sumitCompanies.{$entity->id}.api_key", '');

        $entity->refresh();
        $this->assertSame('כפיים בע"מ', $entity->name);
        $this->assertSame('סאמיט — חשבון ראשי', $entity->sumit_connection_name);
        $this->assertSame('87654321', $entity->sumit_company_id);
        $this->assertSame('private-key', $entity->sumit_api_key);
        $this->assertNotSame('private-key', \DB::table('business_entities')->where('id', $entity->id)->value('sumit_api_key'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sumit.co.il/accounting/general/getvatrate/'
            && $request['Credentials'] === ['CompanyID' => 87654321, 'APIKey' => 'private-key']);

        // Saving again with the key field left empty keeps the stored key.
        Livewire::actingAs($this->owner)->test('settings')->call('saveSumitCompany', $entity->id);
        $this->assertSame('private-key', $entity->fresh()->sumit_api_key);
    }

    public function test_two_sumit_connections_are_each_saved_under_their_own_name(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => false, 'settings' => []]);
        $first = $this->createBusinessEntity();
        $second = $this->createBusinessEntity();

        Livewire::actingAs($this->owner)->test('settings')
            ->set("sumitCompanies.{$first->id}.connection_name", 'סאמיט — כפיים')
            ->set("sumitCompanies.{$first->id}.api_key", 'first-key')
            ->call('saveSumitCompany', $first->id)
            ->set("sumitCompanies.{$second->id}.connection_name", 'סאמיט — חוויית תוכן')
            ->set("sumitCompanies.{$second->id}.company_id", '99999999')
            ->set("sumitCompanies.{$second->id}.api_key", 'second-key')
            ->call('saveSumitCompany', $second->id)
            ->assertHasNoErrors()
            ->assertSee('סאמיט — כפיים')
            ->assertSee('סאמיט — חוויית תוכן')
            // A connection must be named so it can be told apart.
            ->set("sumitCompanies.{$first->id}.connection_name", '')
            ->call('saveSumitCompany', $first->id)
            ->assertHasErrors(["sumitCompanies.{$first->id}.connection_name"]);

        $first->refresh();
        $second->refresh();
        $this->assertSame(['סאמיט — כפיים', '12345678', 'first-key'], [$first->sumit_connection_name, $first->sumit_company_id, $first->sumit_api_key]);
        $this->assertSame(['סאמיט — חוויית תוכן', '99999999', 'second-key'], [$second->sumit_connection_name, $second->sumit_company_id, $second->sumit_api_key]);
    }

    public function test_a_failed_sumit_connection_check_shows_the_error(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => false, 'settings' => []]);
        $entity = $this->createBusinessEntity();
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 1, 'UserErrorMessage' => 'פרטי ההתחברות שגויים'], 200)]);

        Livewire::actingAs($this->owner)->test('settings')
            ->call('testSumitCompany', $entity->id)
            ->assertHasErrors(["sumitConnection.{$entity->id}"]);
    }

    public function test_an_invoice_goes_to_the_sumit_company_of_its_business_entity(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['test_mode' => false]]);
        $this->createBusinessEntity()->update(['sumit_company_id' => '11111111']);
        Http::fake(['api.sumit.co.il/*' => Http::response(['Status' => 0, 'Data' => ['DocumentID' => 9]], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->businessEntity->update(['sumit_company_id' => '22222222', 'sumit_api_key' => 'second-key']);

        app(SummitClient::class)->issueInvoice($invoice->fresh());

        Http::assertSent(fn ($request) => $request['Credentials'] === ['CompanyID' => 22222222, 'APIKey' => 'second-key']);
    }

    public function test_an_entity_without_sumit_credentials_fails_clearly(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => []]);
        Http::fake();

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->businessEntity->update(['sumit_api_key' => null]);

        $this->expectExceptionMessage('לא הוגדרו מספר חברה ומפתח API');
        app(SummitClient::class)->issueInvoice($invoice->fresh());
    }

    // ----- other settings -----

    public function test_saving_smove_settings_persists_them(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => false, 'settings' => []]);
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => false, 'settings' => []]);
        ExternalIntegrationSetting::create(['system' => 'landing_page', 'is_active' => false, 'settings' => []]);

        Livewire::actingAs($this->owner)->test('settings')
            ->set('smoveApiKey', 'secret-key')
            ->set('smoveWebhookSecret', 'whsecret')
            ->set('smoveMaterialReminderHours', '72')
            ->call('saveSmoveSettings');

        $settings = ExternalIntegrationSetting::where('system', 'smove')->first()->settings;
        $this->assertSame('secret-key', $settings['api_key']);
        $this->assertSame(72, $settings['material_reminder_hours']);
    }

    // ----- helpers -----

    private function createLeadConfirmationTemplate(): void
    {
        \App\Models\EmailTemplate::create([
            'name' => 'אישור קליטת ליד', 'template_type' => 'lead_confirmation',
            'subject' => 'תודה שפניתם לכפיים!',
            'content' => "שלום {{contact_name}},\n\nקיבלנו את פנייתכם עבור {{school_name}}.",
            'is_active' => true,
        ]);
    }

    private function createSentMaterialDelivery(): MaterialDelivery
    {
        $customer = $this->createCustomer();
        $program = Program::create(['name' => 'תוכנית בדיקה '.random_int(1, 999999), 'price' => 100, 'is_premium' => false, 'is_subscription_type' => false, 'is_active' => true]);
        $status = StatusDefinition::firstOrCreate(['scope' => 'material_delivery', 'name' => MaterialDelivery::STATUS_NOT_OPENED], ['is_active' => true, 'sort_order' => 1]);

        return MaterialDelivery::create(['customer_id' => $customer->id, 'program_id' => $program->id, 'status_id' => $status->id, 'sent_at' => now()]);
    }

    private function createDeal(float $agreedAmount = 400): Deal
    {
        $customer = $this->createCustomer();
        $program = Program::create([
            'name' => 'תוכנית בדיקת אינטגרציות '.random_int(1, 999999),
            'price' => $agreedAmount, 'is_premium' => false, 'is_subscription_type' => false, 'is_active' => true,
        ]);

        return Deal::createForCustomer($customer, $program, null, $agreedAmount);
    }

    private function dealWithInvoice(float $agreedAmount = 400, string $classification = 'עוסק פטור'): Deal
    {
        $deal = $this->createDeal($agreedAmount);

        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $this->createTemplate('contract'));
        $contract->markSigned();

        // Document::generateFor() already adds a line for the deal's agreed amount.
        $invoice = Document::generateFor($deal, $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity($classification)->id);
        $invoice->update(['sent_at' => now()]); // issued — see Deal::issuedInvoice()

        return $deal->fresh();
    }

    private function createPaymentMethod(string $name, string $type): PaymentMethod
    {
        return PaymentMethod::create(['name' => $name.' '.random_int(1, 999999), 'type' => $type, 'is_active' => true]);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת אינטגרציות '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        Contact::create([
            'school_id' => $school->id,
            'name' => 'איש קשר לבדיקה',
            'email' => 'contact'.random_int(1, 999999).'@example.com',
            'is_primary' => true,
        ]);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }

    private function createTemplate(string $documentType): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => ucfirst($documentType).' תבנית בדיקת אינטגרציות '.random_int(1, 999999),
            'content' => 'תוכן בדיקה עבור '.$documentType,
            'is_active' => true,
        ]);
    }

    private function createBusinessEntity(string $classification = 'עוסק פטור'): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק לבדיקת אינטגרציות '.random_int(1, 999999),
            'classification' => $classification,
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'business'.random_int(1, 999999).'@example.com',
            'phone' => '03-0000000',
            'is_active' => true,
            'sumit_connection_name' => 'סאמיט לבדיקה',
            'sumit_company_id' => '12345678',
            'sumit_api_key' => 'x',
        ]);
    }
}
