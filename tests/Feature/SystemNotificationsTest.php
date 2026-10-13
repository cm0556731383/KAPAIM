<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Build-plan 16 — הודעות מערכת (UX Feedback Layer), FR-7.21-FR-7.26.
 *
 * This is a cross-cutting stage: no new DB entities, so these tests check
 * the shared toast listener, the App\Concerns\Notifies wiring into a few
 * representative flows, the wire:confirm coverage for irreversible actions,
 * and that the blocking messages the per-component ?string $xxxError
 * properties always produced (FR-7.25) now pop up as a sticky error toast
 * (Notifies::renderedNotifies()) instead of a standing banner (FR-7.26:
 * none of this replaces ActivityLog).
 */
class SystemNotificationsTest extends TestCase
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

    // ----- shared toast listener (FR-7.21/FR-7.22/FR-7.23) -----

    public function test_the_shared_toast_listener_renders_on_an_authenticated_page(): void
    {
        $response = $this->actingAs($this->owner)->get('/');

        $response->assertOk();
        $response->assertSee('@notify.window', false);
        $response->assertSee('toast-stack', false);
    }

    // ----- representative flows dispatching a notify event -----

    public function test_creating_a_lead_dispatches_a_success_notification(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'contact@example.com')
            ->set('newPhone', '050-1234567')
            ->call('addLead')
            ->assertDispatched('notify', type: 'success', message: 'ליד חדש נוצר בהצלחה.');
    }

    public function test_a_fuzzy_duplicate_school_name_dispatches_an_info_notification(): void
    {
        School::create(['name' => 'בית ספר יובלים']);

        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'other@example.com')
            ->set('newPhone', '050-9999999')
            ->set('newSchoolName', 'בית ספר יובלימ') // one-character typo → fuzzy match
            ->call('addLead')
            ->assertDispatched('notify', type: 'info');
    }

    public function test_changing_a_deal_status_dispatches_a_success_notification(): void
    {
        $deal = $this->createDeal();
        $paid = StatusDefinition::create(['scope' => 'deal', 'name' => 'שולמה', 'is_active' => true, 'sort_order' => 3]);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('selectedStatusId', (string) $paid->id)
            ->call('updateStatus')
            ->assertDispatched('notify', type: 'success', message: "סטטוס העסקה עודכן ל\"{$paid->name}\".");
    }

    /** FR-7.26 spot check: the toast is additional feedback, never a replacement for the activity log. */
    public function test_recording_a_payment_dispatches_a_success_notification_and_still_logs_activity(): void
    {
        $deal = $this->createDeal(agreedAmount: 1000);
        $method = PaymentMethod::create(['name' => 'מזומן לבדיקה', 'type' => 'cash', 'is_active' => true]);
        $deal->updatePaymentMethod($method->id);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('paymentAmount', '400')
            ->call('recordPayment')
            ->assertDispatched('notify', type: 'success');

        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'payment.recorded', 'deal_id' => $deal->id,
        ]);
    }

    // ----- wire:confirm coverage for irreversible actions (FR-7.24) -----

    /**
     * The "הסרה" button only appears while that contact is in edit mode
     * (see ⚡lead-detail.blade.php's contacts list) — call editContact()
     * first, same as a user clicking "עריכה" would.
     */
    public function test_removing_a_lead_contact_requires_confirmation(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר לבדיקת אישור');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'איש קשר לבדיקה']);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('editContact', $contact->id)
            ->assertSeeHtml('wire:confirm="הסרת איש קשר זה היא מחיקה לוגית — האם להמשיך?"');
    }

    public function test_removing_a_customer_contact_requires_confirmation(): void
    {
        $customer = $this->createCustomer();
        $contact = Contact::create(['school_id' => $customer->school_id, 'customer_id' => $customer->id, 'name' => 'איש קשר לבדיקה', 'is_primary' => true]);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->call('editContact', $contact->id)
            ->assertSeeHtml('wire:confirm="הסרת איש קשר זה היא מחיקה לוגית — האם להמשיך?"');
    }

    public function test_cancelling_a_personal_task_requires_confirmation(): void
    {
        $lead = $this->createLead();
        Task::create([
            'user_id' => $this->owner->id, 'lead_id' => $lead->id, 'task_type' => 'reminder',
            'status' => 'open', 'title' => 'תזכורת לבדיקה',
        ]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->assertSeeHtml('wire:confirm="ביטול התזכורת הוא מחיקה לוגית — האם להמשיך?"');
    }

    /** Cancelling goes through a window: month, credit (filled from the month), reason, then an explicit confirm. */
    public function test_cancelling_a_subscription_goes_through_a_confirmation_window(): void
    {
        $subscription = $this->openSubscription();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->deal->customer])
            ->set('activeTab', 'subscription')
            ->set("cancelFromMonths.{$subscription->id}", '3')
            ->assertSet("cancelCredits.{$subscription->id}", (string) $subscription->creditFor(3))
            ->assertSee('סיבת הביטול')
            ->assertSee('אישור ביטול המנוי')
            ->assertSee('פעולה זו אינה הפיכה');
    }

    /** No invoice yet: no "irreversible" confirm — clicking pops the blocking reason up instead. */
    public function test_a_credit_note_without_an_invoice_pops_up_the_reason_instead_of_confirming(): void
    {
        $subscription = $this->openSubscription();
        $subscription->cancel();

        $component = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->deal->customer]);
        $component->set('activeTab', 'subscription')
            ->assertDontSeeHtml('wire:confirm="הזיכוי יופק ב-SUMIT ויישלח ללקוחה במייל. זו פעולה חשבונאית בלתי הפיכה — להמשיך?"')
            ->call('generateSubscriptionCreditNote', $subscription->id)
            ->assertDispatched('notify', fn ($name, $params) => $params['type'] === 'error' && $params['sticky'] === true
                && $params['message'] === $component->get('subscriptionError'));
    }

    public function test_customer_card_errors_pop_up_once_and_sending_materials_without_recipients_explains_why(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();

        $component = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->deal->customer])
            ->set('activeTab', 'materials')
            ->set('materialsProgramId', (string) $program->id)
            ->set('materialsLinks', [['url' => 'https://example.com/video', 'name' => '']])
            ->call('sendMaterials')
            ->assertDispatched('notify', type: 'error', message: 'אין עדיין נמענים — יש להוסיף לפחות נמען אחד לפני השליחה.', sticky: true);

        // An unrelated re-render doesn't pop the same error again.
        $component->set('materialsRecipientName', 'x')->assertNotDispatched('notify');
    }

    /** Cancelling a deal (status -> מבוטלת) needs confirmation; routine progression does not. */
    public function test_selecting_the_cancelled_deal_status_requires_confirmation_but_other_statuses_do_not(): void
    {
        $deal = $this->createDeal();
        $paid = StatusDefinition::create(['scope' => 'deal', 'name' => 'שולמה', 'is_active' => true, 'sort_order' => 3]);
        $cancelled = StatusDefinition::create(['scope' => 'deal', 'name' => 'מבוטלת', 'is_active' => true, 'sort_order' => 4]);

        $component = Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal]);

        $component->set('selectedStatusId', (string) $paid->id)
            ->assertDontSeeHtml('wire:confirm="ביטול העסקה אינו הפיך — האם להמשיך?"');

        $component->set('selectedStatusId', (string) $cancelled->id)
            ->assertSeeHtml('wire:confirm="ביטול העסקה אינו הפיך — האם להמשיך?"');
    }

    public function test_converting_a_lead_to_a_customer_already_has_a_confirmation(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר להמרה');
        Contact::create(['school_id' => $lead->school_id, 'name' => 'איש קשר ראשי', 'is_primary' => true]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->assertSeeHtml('wire:confirm="להמיר ליד זה ללקוחה? הליד יכול להיות מומר פעם אחת בלבד."');
    }

    // ----- business errors pop up (FR-7.25) -----

    /** ProgramsCatalogTest's gate (only one active subscription-type bundle) — same rule, now a sticky popup. */
    public function test_a_business_error_pops_up_and_blocks_a_duplicate_subscription_bundle(): void
    {
        Bundle::create(['name' => 'מנוי קיים', 'price' => 4000, 'is_subscription_type' => true, 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('bundleName', 'מנוי שני')
            ->set('bundlePrice', '4500')
            ->set('bundleIsSubscriptionType', true)
            ->call('addBundle')
            ->assertDispatched('notify', type: 'error', message: 'קיים כבר מארז מנוי אחד פעיל בקטלוג. יש להשבית אותו לפני יצירת מארז מנוי חדש.', sticky: true);

        $this->assertDatabaseMissing('bundles', ['name' => 'מנוי שני']);
    }

    /** Second gate: the deal-status optimistic-lock conflict (DealsManagementTest) pops up too. */
    public function test_a_concurrent_status_update_conflict_pops_up(): void
    {
        $deal = $this->createDeal();
        $invoiceSent = StatusDefinition::create(['scope' => 'deal', 'name' => 'נשלחה חשבונית', 'is_active' => true, 'sort_order' => 2]);
        $paid = StatusDefinition::create(['scope' => 'deal', 'name' => 'שולמה', 'is_active' => true, 'sort_order' => 3]);

        $component = Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal]);

        Deal::where('id', $deal->id)->where('version', $deal->version)->update([
            'status_id' => $invoiceSent->id,
            'version' => $deal->version + 1,
        ]);

        $component->set('selectedStatusId', (string) $paid->id)
            ->call('updateStatus')
            ->assertSet('statusError', fn ($message) => ! empty($message));

        $component->assertDispatched('notify', type: 'error', message: $component->get('statusError'), sticky: true);
    }

    // ----- helpers -----

    private function createLead(?string $schoolName = null): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = $schoolName ? School::create(['name' => $schoolName]) : null;

        return Lead::create([
            'school_id' => $school?->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת הודעות '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        $customer = $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
        // These fixtures add their own contacts — drop the primary one conversion opens from the lead's details.
        $customer->contacts()->forceDelete();

        return $customer;
    }

    private function createProgram(float $price = 400): Program
    {
        return Program::create([
            'name' => 'תוכנית בדיקת הודעות '.random_int(1, 999999),
            'price' => $price,
            'is_premium' => false,
            'is_active' => true,
        ]);
    }

    private function createDeal(float $agreedAmount = 400): Deal
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram($agreedAmount);

        return Deal::createForCustomer($customer, $program, null, $agreedAmount);
    }

    private function openSubscription(float $agreedAmount = 4200): Subscription
    {
        $customer = $this->createCustomer();
        $subscriptionBundle = Bundle::create([
            'name' => 'מנוי בדיקת הודעות '.random_int(1, 999999),
            'price' => $agreedAmount,
            'is_subscription_type' => true,
            'is_active' => true,
        ]);
        $deal = Deal::createForCustomer($customer, null, $subscriptionBundle, $agreedAmount);

        return Subscription::where('deal_id', $deal->id)->firstOrFail();
    }
}
