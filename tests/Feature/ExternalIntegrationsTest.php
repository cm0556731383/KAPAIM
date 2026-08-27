<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Mail\LeadConfirmationMail;
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
use App\Services\Integrations\SummitClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        Mail::fake();
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

        Mail::assertSent(LeadConfirmationMail::class, fn (LeadConfirmationMail $mail) => $mail->hasTo('dana@example.com'));

        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'lead.created', 'lead_id' => $lead->id]);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'lead.confirmation_email_sent', 'lead_id' => $lead->id]);

        // FR-1.17: joins the primary mailing list, which also attempts (and
        // fails, since Smove itself isn't configured) a Smove push — but
        // that failure must never block lead creation.
        $this->assertTrue(MailingMembership::where('mailing_list_id', MailingList::primaryList()->id)->where('lead_id', $lead->id)->exists());
    }

    public function test_landing_page_webhook_logs_a_repeat_inquiry_instead_of_duplicating_the_lead(): void
    {
        Mail::fake();
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
        Mail::assertSent(LeadConfirmationMail::class, fn (LeadConfirmationMail $mail) => $mail->hasTo('new@example.com'));
    }

    public function test_landing_page_webhook_auto_assigns_the_least_loaded_sales_rep(): void
    {
        Mail::fake();
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
        Mail::fake();
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
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['base_url' => 'https://smove.test', 'api_key' => 'x']]);
        Http::fake(['smove.test/*' => Http::response(['message' => 'nope'], 500)]);

        $customer = $this->createCustomer();

        $this->assertTrue(MailingMembership::where('mailing_list_id', MailingList::primaryList()->id)->where('customer_id', $customer->id)->exists());
        $this->assertDatabaseHas('external_operations', ['system' => 'smove', 'operation_type' => 'mailing_list_join', 'status' => ExternalOperation::STATUS_FAILED]);
    }

    public function test_a_successful_smove_push_is_recorded(): void
    {
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => true, 'settings' => ['base_url' => 'https://smove.test', 'api_key' => 'x']]);
        Http::fake(['smove.test/*' => Http::response(['id' => 'ext-123'], 200)]);

        $this->createCustomer();

        $this->assertDatabaseHas('external_operations', [
            'system' => 'smove', 'operation_type' => 'mailing_list_join',
            'status' => ExternalOperation::STATUS_SUCCESS, 'external_reference' => 'ext-123',
        ]);
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
        );

        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertDatabaseHas('external_operations', [
            'system' => 'summit', 'operation_type' => 'issue_invoice', 'document_id' => $invoice->id,
            'status' => ExternalOperation::STATUS_SUCCESS, 'external_reference' => 'inv-1',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/invoices'));
    }

    public function test_a_quote_send_never_touches_summit(): void
    {
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => true, 'settings' => ['base_url' => 'https://summit.test', 'api_key' => 'x']]);
        Http::fake();

        $deal = $this->createDeal();
        $quote = Document::generateFor($deal, $this->createTemplate('quote'));

        $operation = $quote->sendTo(
            [['contact_id' => null, 'name' => 'לקוחה', 'email' => 'billing@example.com']],
            'digital',
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SummitClient::class),
        );

        $this->assertNull($operation);
        Http::assertNothingSent();
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
            ->set('smoveBaseUrl', 'https://api.smove.co.il')
            ->set('smoveApiKey', 'secret-key')
            ->set('smoveWebhookSecret', 'whsecret')
            ->set('smoveMaterialReminderHours', '72')
            ->call('saveSmoveSettings');

        $settings = ExternalIntegrationSetting::where('system', 'smove')->first()->settings;
        $this->assertSame('https://api.smove.co.il', $settings['base_url']);
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
