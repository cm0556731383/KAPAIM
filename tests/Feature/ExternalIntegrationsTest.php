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

    // ----- FR-4.27: Summit standing-order-collected webhook -----

    public function test_standing_order_webhook_is_rejected_without_the_configured_secret(): void
    {
        $deal = $this->dealWithInvoice();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('הוראת קבע', 'recurring')->id]);

        $this->postJson('/webhooks/summit/standing-order-collected', ['deal_id' => $deal->id, 'amount' => 100])->assertStatus(403);
        $this->assertSame(0.0, $deal->totalPaid());
    }

    public function test_standing_order_webhook_records_a_payment_and_auto_issues_a_receipt(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);
        $deal = $this->dealWithInvoice(1000);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('הוראת קבע', 'recurring')->id]);

        $response = $this->postJson('/webhooks/summit/standing-order-collected', [
            'deal_id' => $deal->id, 'amount' => 100,
        ], ['X-Webhook-Secret' => 'sekret']);

        $response->assertOk();
        $response->assertJson(['status' => ExternalOperation::STATUS_SUCCESS]);

        $deal->refresh();
        $this->assertSame(100.0, $deal->totalPaid());
        $this->assertSame(1, $deal->payments()->count());
        $this->assertNotNull($deal->payments()->first()->receipt);
    }

    public function test_standing_order_webhook_fails_gracefully_for_the_wrong_payment_method(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['webhook_secret' => 'sekret']]);
        $deal = $this->dealWithInvoice(); // no payment method set at all

        $response = $this->postJson('/webhooks/summit/standing-order-collected', [
            'deal_id' => $deal->id, 'amount' => 100,
        ], ['X-Webhook-Secret' => 'sekret']);

        $response->assertOk();
        $response->assertJson(['status' => ExternalOperation::STATUS_FAILED]);
        $this->assertSame(0.0, $deal->fresh()->totalPaid());
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
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['base_url' => 'https://summit.test', 'api_key' => 'x']]);
        Http::fake(['summit.test/*' => Http::response(['id' => 'inv-1'], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();

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
            'status' => ExternalOperation::STATUS_SUCCESS, 'external_reference' => 'inv-1',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/invoices'));
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
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['base_url' => 'https://summit.test', 'api_key' => 'x']]);
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(['summit.test/*' => Http::response(['id' => 'inv-1'], 200), 'rest.smoove.io/*' => Http::response(['id' => 42], 200)]);

        $deal = $this->dealWithInvoice();
        $invoice = $deal->documents()->where('document_type', 'invoice')->firstOrFail();

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
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['base_url' => 'https://summit.test', 'api_key' => 'x']]);
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
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['base_url' => 'https://summit.test', 'api_key' => 'x']]);
        Http::fake(['summit.test/*' => Http::response(['id' => 'chg-1'], 200)]);

        $deal = $this->createDeal(500);
        $deal->update(['payment_method_id' => $this->createPaymentMethod('אשראי', 'card')->id]);

        $payment = $deal->fresh()->chargeCardViaSummit($deal->version, 500.0, app(ExternalOperationRunner::class), app(SummitClient::class));

        $this->assertSame(500.0, (float) $payment->amount);
        $this->assertSame(500.0, $deal->fresh()->totalPaid());
    }

    public function test_charging_a_card_records_no_payment_when_summit_declines(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['base_url' => 'https://summit.test', 'api_key' => 'x']]);
        Http::fake(['summit.test/*' => Http::response(['message' => 'declined'], 402)]);

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
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['base_url' => 'https://summit.test', 'api_key' => 'x']]);
        Http::fake(['summit.test/*' => Http::response(['id' => 'so-1'], 200)]);

        $deal = $this->createDeal();
        $deal->update(['payment_method_id' => $this->createPaymentMethod('אשראי', 'card')->id]);

        $this->expectException(RuntimeException::class);
        $deal->fresh()->registerStandingOrderWithSummit(app(ExternalOperationRunner::class), app(SummitClient::class));
    }

    // ----- settings screen (business owner fills in real credentials) -----

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

    private function dealWithInvoice(float $agreedAmount = 400): Deal
    {
        $deal = $this->createDeal($agreedAmount);

        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $this->createTemplate('contract'));
        $contract->markSigned();

        Document::generateFor($deal, $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity()->id);

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

    private function createBusinessEntity(): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק לבדיקת אינטגרציות '.random_int(1, 999999),
            'classification' => 'עוסק פטור',
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'business'.random_int(1, 999999).'@example.com',
            'phone' => '03-0000000',
            'is_active' => true,
        ]);
    }
}
