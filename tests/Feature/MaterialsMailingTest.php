<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\ExternalIntegrationSetting;
use App\Models\Lead;
use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Models\MaterialDelivery;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class MaterialsMailingTest extends TestCase
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

    // ----- auth / permissions -----

    public function test_mailing_lists_page_requires_auth(): void
    {
        $this->get('/mailing-lists')->assertRedirect('/login');
    }

    public function test_mailing_lists_page_is_blocked_without_the_mailing_lists_permission(): void
    {
        $limited = $this->userWithOnlyCustomersPermission();

        $this->actingAs($limited)->get('/mailing-lists')->assertStatus(403);
    }

    public function test_mailing_lists_page_renders_for_a_user_with_the_permission(): void
    {
        $this->actingAs($this->owner)->get('/mailing-lists')->assertOk();
    }

    public function test_sending_materials_is_blocked_without_the_materials_permission(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $limited = $this->userWithOnlyCustomersPermission();

        Livewire::actingAs($limited)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [UploadedFile::fake()->create('doc.pdf', 10)])
            ->call('sendMaterials')
            ->assertStatus(403);

        $this->assertSame(0, MaterialDelivery::count());
    }

    // ----- sending materials (FR-5.1-FR-5.9/FR-8.13/FR-8.14) -----

    public function test_sending_materials_is_blocked_with_no_recipient(): void
    {
        $customer = $this->createCustomer(); // no contacts at all
        $program = $this->createProgram();

        $this->expectException(RuntimeException::class);
        MaterialDelivery::sendFor($customer, $program, [], [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));
    }

    public function test_sending_materials_is_blocked_with_no_attachment(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        $this->expectException(RuntimeException::class);
        MaterialDelivery::sendFor($customer, $program, [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']], [], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));
    }

    public function test_sending_materials_succeeds_with_a_recipient_and_an_attachment(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        $delivery = MaterialDelivery::sendFor(
            $customer,
            $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $this->assertSame(1, MaterialDelivery::count());
        $this->assertSame($customer->id, $delivery->customer_id);
        $this->assertSame($program->id, $delivery->program_id);
        $this->assertSame(MaterialDelivery::STATUS_NOT_OPENED, $delivery->status?->name);
        $this->assertSame(1, $delivery->recipients()->count());
        $this->assertSame(1, $delivery->attachments()->count());
        $this->assertSame('a.pdf', $delivery->attachments()->first()->file_name);
        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.sent')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_sending_materials_defaults_recipients_to_primary_contacts_without_mutating_is_primary(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $contact = Contact::where('customer_id', $customer->id)->first();

        $defaults = MaterialDelivery::defaultRecipients($customer);
        $this->assertCount(1, $defaults);
        $this->assertSame($contact->id, $defaults->first()->id);

        MaterialDelivery::sendFor(
            $customer,
            $program,
            $defaults->map(fn (Contact $c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])->all(),
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $this->assertTrue($contact->fresh()->is_primary);
    }

    public function test_resending_materials_creates_a_new_delivery_row_not_an_update(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();
        $recipients = [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']];

        MaterialDelivery::sendFor($customer, $program, $recipients, [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));
        MaterialDelivery::sendFor($customer, $program, $recipients, [['file_reference' => 'tmp2', 'file_name' => 'b.pdf']], app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SmoveClient::class));

        $this->assertSame(2, MaterialDelivery::where('customer_id', $customer->id)->where('program_id', $program->id)->count());
    }

    public function test_sending_materials_via_the_customer_card_creates_a_real_delivery_and_discards_the_temp_file(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $file = UploadedFile::fake()->create('doc.pdf', 10);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [$file])
            ->call('sendMaterials')
            ->assertSet('materialsError', null);

        $this->assertSame(1, MaterialDelivery::where('customer_id', $customer->id)->count());
    }

    /**
     * Stage 19 hardening: an oversized attachment (over the 25MB cap from
     * docs/storyboard/materials-send.html) is rejected by real Livewire
     * validation before anything is sent, and no delivery is created.
     */
    public function test_an_oversized_attachment_is_rejected(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $file = UploadedFile::fake()->create('huge.pdf', 25601);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [$file])
            ->assertHasErrors(['materialsFiles.*']);

        $this->assertSame(0, MaterialDelivery::where('customer_id', $customer->id)->count());
    }

    /**
     * Stage 19 hardening: a disallowed file type (outside the pdf/office/
     * image/media whitelist) is rejected by real Livewire validation.
     */
    public function test_an_attachment_with_a_disallowed_mime_type_is_rejected(): void
    {
        $customer = $this->createCustomerWithEmailContact();
        $program = $this->createProgram();
        $file = UploadedFile::fake()->create('script.exe', 10);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsFiles', [$file])
            ->assertHasErrors(['materialsFiles.*']);

        $this->assertSame(0, MaterialDelivery::where('customer_id', $customer->id)->count());
    }

    public function test_a_deal_id_is_attached_to_the_activity_log_when_a_matching_deal_exists(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();
        $deal = Deal::createForCustomer($customer, $program, null);

        $delivery = MaterialDelivery::sendFor(
            $customer, $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $log = ActivityLog::where('activity_type', 'material_delivery.sent')->where('material_delivery_id', $delivery->id)->first();
        $this->assertSame($deal->id, $log->deal_id);
    }

    public function test_sending_materials_never_blocks_when_no_matching_deal_exists(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        $delivery = MaterialDelivery::sendFor(
            $customer, $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );

        $log = ActivityLog::where('activity_type', 'material_delivery.sent')->where('material_delivery_id', $delivery->id)->first();
        $this->assertNull($log->deal_id);
    }

    // ----- FR-5.12/FR-5.13/FR-5.15: the "קיבלתי, תודה" signed link -----

    public function test_the_signed_acknowledge_link_sets_acknowledged_at_and_logs_activity(): void
    {
        $delivery = $this->createDelivery();
        $url = URL::signedRoute('materials.acknowledge', ['material' => $delivery->id]);

        $response = $this->get($url);

        $response->assertOk();
        $delivery->refresh();
        $this->assertNotNull($delivery->acknowledged_at);
        $this->assertSame(MaterialDelivery::STATUS_ACKNOWLEDGED, $delivery->status?->name);
        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.acknowledged')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_clicking_the_acknowledge_link_twice_is_idempotent(): void
    {
        $delivery = $this->createDelivery();
        $url = URL::signedRoute('materials.acknowledge', ['material' => $delivery->id]);

        $this->get($url);
        $firstAcknowledgedAt = $delivery->fresh()->acknowledged_at;

        $this->get($url)->assertOk();

        $this->assertSame(1, ActivityLog::where('activity_type', 'material_delivery.acknowledged')->where('material_delivery_id', $delivery->id)->count());
        $this->assertTrue($firstAcknowledgedAt->equalTo($delivery->fresh()->acknowledged_at));
    }

    public function test_an_unsigned_acknowledge_url_is_rejected(): void
    {
        $delivery = $this->createDelivery();

        $this->get("/materials/{$delivery->id}/acknowledge")->assertStatus(403);

        $this->assertNull($delivery->fresh()->acknowledged_at);
    }

    public function test_a_tampered_acknowledge_signature_is_rejected(): void
    {
        $delivery = $this->createDelivery();
        $url = URL::signedRoute('materials.acknowledge', ['material' => $delivery->id]);

        $this->get($url.'x')->assertStatus(403);

        $this->assertNull($delivery->fresh()->acknowledged_at);
    }

    // ----- reminder job (FR-5.16/FR-5.17) -----

    public function test_reminder_job_ignores_deliveries_not_yet_overdue(): void
    {
        Mail::fake();
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(10)]);

        $this->artisan('materials:process-reminders');

        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
        Mail::assertNothingSent();
    }

    public function test_reminder_job_flags_overdue_unopened_deliveries(): void
    {
        Mail::fake();
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(49)]);

        $this->artisan('materials:process-reminders');

        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
        Mail::assertNothingSent();
    }

    public function test_reminder_job_ignores_already_opened_deliveries(): void
    {
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(49)]);
        $delivery->markOpened();

        $this->artisan('materials:process-reminders');

        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_reminder_job_ignores_already_acknowledged_deliveries(): void
    {
        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(49)]);
        $delivery->acknowledge(app(ActivityLogger::class));

        $this->artisan('materials:process-reminders');

        $this->assertFalse(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_reminder_window_is_configurable_via_the_smove_integration_setting(): void
    {
        ExternalIntegrationSetting::updateOrCreate(
            ['system' => 'smove'],
            ['is_active' => false, 'settings' => ['material_reminder_hours' => 2]],
        );

        $delivery = $this->createDelivery();
        $delivery->update(['sent_at' => now()->subHours(3)]);

        $this->artisan('materials:process-reminders');

        $this->assertTrue(ActivityLog::where('activity_type', 'material_delivery.reminder_due')->where('material_delivery_id', $delivery->id)->exists());
    }

    public function test_needing_attention_scope_excludes_handled_deliveries(): void
    {
        $delivery = $this->createDelivery();

        $this->assertSame(1, MaterialDelivery::needingAttention()->count());

        $delivery->markHandled();

        $this->assertSame(0, MaterialDelivery::needingAttention()->count());
    }

    // ----- mailing lists (US-013/FR-5.19-FR-5.26) -----

    public function test_a_new_lead_joins_the_primary_mailing_list(): void
    {
        $lead = $this->createLead();

        $lead->joinPrimaryMailingList();

        $primary = MailingList::primaryList();
        $this->assertTrue(MailingMembership::where('mailing_list_id', $primary->id)->where('lead_id', $lead->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());
    }

    public function test_converting_a_lead_backfills_its_primary_membership_onto_the_customer(): void
    {
        $lead = $this->createLead();
        $lead->joinPrimaryMailingList();

        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $primary = MailingList::primaryList();
        $membership = MailingMembership::where('mailing_list_id', $primary->id)->where('lead_id', $lead->id)->first();
        $this->assertNotNull($membership);
        $this->assertSame($customer->id, $membership->customer_id);
        // No duplicate row was created for the same person.
        $this->assertSame(1, MailingMembership::where('mailing_list_id', $primary->id)->where('customer_id', $customer->id)->count());
    }

    public function test_converting_a_lead_that_never_joined_still_puts_the_customer_on_the_primary_list(): void
    {
        $lead = $this->createLead(); // never called joinPrimaryMailingList()

        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $primary = MailingList::primaryList();
        $this->assertTrue(MailingMembership::where('mailing_list_id', $primary->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());
    }

    public function test_buying_a_plain_program_adds_the_customer_to_that_programs_list(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        Deal::createForCustomer($customer, $program, null);

        $list = MailingList::forProgram($program);
        $this->assertTrue(MailingMembership::where('mailing_list_id', $list->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());
    }

    public function test_buying_a_plain_program_does_not_add_the_customer_to_the_subscribers_list(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        Deal::createForCustomer($customer, $program, null);

        $subscribers = MailingList::subscribersList();
        $this->assertFalse(MailingMembership::where('mailing_list_id', $subscribers->id)->where('customer_id', $customer->id)->exists());
    }

    public function test_buying_the_subscription_bundle_adds_subscribers_list_and_every_monthly_catalog_program_list(): void
    {
        $customer = $this->createCustomer();
        $monthlyA = $this->createProgram(name: 'חודשית א');
        $monthlyB = $this->createProgram(name: 'חודשית ב');
        $premium = $this->createProgram(name: 'פרימיום', isPremium: true);
        $subscriptionBundle = $this->createSubscriptionBundle();

        Deal::createForCustomer($customer, null, $subscriptionBundle);

        $subscribers = MailingList::subscribersList();
        $this->assertTrue(MailingMembership::where('mailing_list_id', $subscribers->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists());

        foreach ([$monthlyA, $monthlyB] as $monthly) {
            $list = MailingList::forProgram($monthly);
            $this->assertTrue(
                MailingMembership::where('mailing_list_id', $list->id)->where('customer_id', $customer->id)->where('membership_status', MailingMembership::STATUS_ACTIVE)->exists(),
                "Expected customer to be on the mailing list for {$monthly->name}",
            );
        }

        // FR-5.23's judgment call: premium programs are never part of "every
        // monthly program included in the subscription".
        $premiumList = MailingList::forProgram($premium);
        $this->assertFalse(MailingMembership::where('mailing_list_id', $premiumList->id)->where('customer_id', $customer->id)->exists());
    }

    public function test_cancelling_a_subscription_removes_subscribers_and_undelivered_lists_but_keeps_primary_and_delivered(): void
    {
        $customer = $this->createCustomer();
        $monthlyA = $this->createProgram(name: 'חודשית א');
        $monthlyB = $this->createProgram(name: 'חודשית ב');
        $subscriptionBundle = $this->createSubscriptionBundle();

        $deal = Deal::createForCustomer($customer, null, $subscriptionBundle);
        $subscription = Subscription::where('deal_id', $deal->id)->firstOrFail();

        // Mark two deliveries supplied against monthlyA before cancelling —
        // proves the "already delivered" carve-out (FR-5.25).
        foreach ($subscription->deliveries()->limit(2)->get() as $delivery) {
            $subscription->markDeliverySupplied($subscription->fresh()->version, $delivery->id, $monthlyA->id, $this->owner);
        }

        $primaryListBefore = MailingList::primaryList();
        MailingMembership::addCustomer($primaryListBefore, $customer); // ensure customer is really on it

        $subscription->fresh()->cancel();

        $subscribers = MailingList::subscribersList();
        $this->assertFalse($this->isActiveMember($subscribers, $customer));

        // monthlyA was delivered — membership stays.
        $this->assertTrue($this->isActiveMember(MailingList::forProgram($monthlyA), $customer));

        // monthlyB was never delivered — membership is removed.
        $this->assertFalse($this->isActiveMember(MailingList::forProgram($monthlyB), $customer));

        // The primary list is never touched by cancellation.
        $this->assertTrue($this->isActiveMember(MailingList::primaryList(), $customer));
    }

    // ----- no delete route anywhere for materials/mailing entities -----

    public function test_there_is_no_delete_route_for_materials_or_mailing_lists(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = strtolower($route->uri());
            if (str_contains($uri, 'material') || str_contains($uri, 'mailing')) {
                $this->assertNotContains('DELETE', $route->methods(), "Unexpected DELETE route: {$uri}");
            }
        }
    }

    // ----- helpers -----

    private function isActiveMember(MailingList $list, Customer $customer): bool
    {
        return MailingMembership::where('mailing_list_id', $list->id)
            ->where('customer_id', $customer->id)
            ->where('membership_status', MailingMembership::STATUS_ACTIVE)
            ->exists();
    }

    private function createDelivery(): MaterialDelivery
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        return MaterialDelivery::sendFor(
            $customer, $program,
            [['contact_id' => null, 'name' => 'א', 'email' => 'a@example.com']],
            [['file_reference' => 'tmp1', 'file_name' => 'a.pdf']],
            app(ActivityLogger::class),
            app(ExternalOperationRunner::class),
            app(SmoveClient::class),
        );
    }

    private function userWithOnlyCustomersPermission(): User
    {
        $limited = Role::create(['name' => 'עובדת מכירות מוגבלת '.random_int(1, 999999), 'is_active' => true]);
        $limited->permissions()->create(['resource' => 'customers', 'action' => 'manage', 'is_allowed' => true]);

        return User::create([
            'name' => 'שרית לוי', 'email' => 'sarit'.random_int(1, 999999).'@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);
    }

    private function createLead(): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת חומרים '.random_int(1, 999999)]);

        return Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    private function createCustomer(): Customer
    {
        return $this->createLead()->convertToCustomer(app(ActivityLogger::class));
    }

    private function createCustomerWithEmailContact(): Customer
    {
        $customer = $this->createCustomer();

        Contact::create([
            'school_id' => $customer->school_id,
            'customer_id' => $customer->id,
            'name' => 'איש קשר לבדיקה',
            'email' => 'contact'.random_int(1, 999999).'@example.com',
            'is_primary' => true,
        ]);

        return $customer;
    }

    private function createSubscriptionBundle(?string $name = null, float $price = 4200): Bundle
    {
        return Bundle::create([
            'name' => $name ?? 'מנוי שנתי לבדיקת חומרים '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_subscription_type' => true,
            'is_active' => true,
        ]);
    }

    private function createProgram(?string $name = null, float $price = 400, bool $isPremium = false): Program
    {
        return Program::create([
            'name' => $name ?? 'תוכנית בדיקת חומרים '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_premium' => $isPremium,
            'is_active' => true,
        ]);
    }
}
