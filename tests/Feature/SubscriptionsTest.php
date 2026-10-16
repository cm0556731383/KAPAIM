<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\BusinessEntity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use App\Models\SubscriptionDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SubscriptionsTest extends TestCase
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

    // ----- FR-3.12/FR-3.13: opening a subscription -----

    public function test_creating_a_deal_for_the_subscription_bundle_opens_a_subscription_with_ten_undelivered_rows(): void
    {
        $customer = $this->createCustomer();
        $subscriptionBundle = $this->createSubscriptionBundle();

        $deal = Deal::createForCustomer($customer, null, $subscriptionBundle);

        $subscription = Subscription::where('deal_id', $deal->id)->first();

        $this->assertNotNull($subscription);
        $this->assertSame($customer->id, $subscription->customer_id);
        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $subscription->status?->name);
        $this->assertEquals((float) $deal->agreed_amount, (float) $subscription->agreed_price);
        $this->assertSame(10, $subscription->deliveries()->count());
        $this->assertSame(10, $subscription->deliveries()->where('is_supplied', false)->count());
        $this->assertSame(0, $subscription->deliveries()->whereNotNull('program_id')->count());
    }

    public function test_a_deal_for_a_non_subscription_program_never_opens_a_subscription(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        $deal = Deal::createForCustomer($customer, $program, null);

        $this->assertSame(0, Subscription::where('deal_id', $deal->id)->count());
    }

    // ----- FR-3.11: premium discount for active subscribers -----

    public function test_premium_program_gets_10_percent_off_for_a_customer_with_an_active_subscription(): void
    {
        $customer = $this->createCustomer();
        $subscriptionBundle = $this->createSubscriptionBundle();
        Deal::createForCustomer($customer, null, $subscriptionBundle);

        $premiumProgram = $this->createProgram(price: 1000, isPremium: true);
        $deal = Deal::createForCustomer($customer, $premiumProgram, null);

        $this->assertEquals(900.0, (float) $deal->agreed_amount);
    }

    public function test_premium_program_is_full_price_without_an_active_subscription(): void
    {
        $customer = $this->createCustomer();
        $premiumProgram = $this->createProgram(price: 1000, isPremium: true);

        $deal = Deal::createForCustomer($customer, $premiumProgram, null);

        $this->assertEquals(1000.0, (float) $deal->agreed_amount);
    }

    public function test_premium_discount_is_overridable_by_an_explicit_agreed_amount(): void
    {
        $customer = $this->createCustomer();
        $subscriptionBundle = $this->createSubscriptionBundle();
        Deal::createForCustomer($customer, null, $subscriptionBundle);

        $premiumProgram = $this->createProgram(price: 1000, isPremium: true);
        $deal = Deal::createForCustomer($customer, $premiumProgram, null, 750.0);

        $this->assertEquals(750.0, (float) $deal->agreed_amount);
    }

    public function test_premium_discount_does_not_apply_once_the_subscription_is_cancelled(): void
    {
        $customer = $this->createCustomer();
        $subscriptionBundle = $this->createSubscriptionBundle();
        $subscriptionDeal = Deal::createForCustomer($customer, null, $subscriptionBundle);
        Subscription::where('deal_id', $subscriptionDeal->id)->first()->cancel();

        $premiumProgram = $this->createProgram(price: 1000, isPremium: true);
        $deal = Deal::createForCustomer($customer, $premiumProgram, null);

        $this->assertEquals(1000.0, (float) $deal->agreed_amount);
    }

    // ----- FR-3.14/FR-8.19: marking a delivery supplied -----

    public function test_marking_a_delivery_supplied_rejects_a_disabled_program(): void
    {
        $subscription = $this->openSubscription();
        $delivery = $subscription->deliveries()->first();
        $disabledProgram = $this->createProgram(isActive: false);

        $this->expectException(RuntimeException::class);
        $subscription->markDeliverySupplied($subscription->version, $delivery->id, $disabledProgram->id, $this->owner);
    }

    public function test_marking_a_delivery_supplied_records_program_date_and_user(): void
    {
        $subscription = $this->openSubscription();
        $delivery = $subscription->deliveries()->first();
        $program = $this->createProgram();

        $updated = $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $this->owner);

        $this->assertTrue($updated->is_supplied);
        $this->assertSame($program->id, $updated->program_id);
        $this->assertSame($this->owner->id, $updated->supplied_by);
        $this->assertNotNull($updated->supplied_at);
    }

    /**
     * FR-3.15: marking supplied is a purely manual, independent action — this
     * test exercises it in complete isolation from anything materials-
     * sending related (which doesn't exist at all yet, build-plan 10) to
     * demonstrate no such coupling exists anywhere in the call path.
     */
    public function test_marking_a_delivery_supplied_works_with_no_materials_sending_concept_involved(): void
    {
        $subscription = $this->openSubscription();
        $delivery = $subscription->deliveries()->first();
        $program = $this->createProgram();

        $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $this->owner);

        $this->assertSame(1, $subscription->suppliedCount());
    }

    // ----- FR-3.16/FR-3.17: auto-close on the 10th delivery -----

    public function test_marking_the_ninth_delivery_does_not_close_the_subscription(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();

        foreach ($subscription->deliveries()->limit(9)->get() as $delivery) {
            $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $this->owner);
        }

        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $subscription->fresh()->status?->name);
        $this->assertNull($subscription->fresh()->end_date);
    }

    public function test_marking_the_tenth_delivery_closes_the_subscription(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();

        foreach ($subscription->deliveries()->get() as $delivery) {
            $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $this->owner);
        }

        $subscription->refresh();
        $this->assertSame(Subscription::ENDED_STATUS_NAME, $subscription->status?->name);
        $this->assertNotNull($subscription->end_date);
        $this->assertSame(10, $subscription->suppliedCount());
    }

    // ----- FR-8.19: optimistic locking on marking a delivery supplied -----

    public function test_concurrent_delivery_marking_is_rejected_with_a_conflict_error(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();
        $deliveries = $subscription->deliveries()->limit(2)->get();

        $staleVersion = $subscription->version;

        // First user marks a delivery successfully — bumps `version`.
        $subscription->markDeliverySupplied($staleVersion, $deliveries[0]->id, $program->id, $this->owner);

        // Second user, still holding the stale version loaded before the
        // first mark, must be rejected rather than silently applied.
        $this->expectException(RuntimeException::class);
        $subscription->markDeliverySupplied($staleVersion, $deliveries[1]->id, $program->id, $this->owner);
    }

    public function test_a_rejected_concurrent_delivery_marking_never_updates_the_delivery_row(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();
        $deliveries = $subscription->deliveries()->limit(2)->get();

        $staleVersion = $subscription->version;
        $subscription->markDeliverySupplied($staleVersion, $deliveries[0]->id, $program->id, $this->owner);

        try {
            $subscription->markDeliverySupplied($staleVersion, $deliveries[1]->id, $program->id, $this->owner);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($deliveries[1]->fresh()->is_supplied);
        $this->assertSame(1, $subscription->fresh()->suppliedCount());
    }

    // ----- US-010/FR-8.24: cancellation credit calculation -----

    public function test_cancellation_credit_is_computed_from_supplied_count_and_agreed_price(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 5000);
        $program = $this->createProgram();

        foreach ($subscription->deliveries()->limit(3)->get() as $delivery) {
            $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $this->owner);
        }

        $cancelled = $subscription->fresh()->cancel();

        $this->assertSame(Subscription::CANCELLED_STATUS_NAME, $cancelled->status?->name);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertEquals(5000 / 10 * 7, (float) $cancelled->cancellation_credit);
    }

    /** Proves the credit is based on THIS customer's agreed price, not catalog list price. */
    public function test_cancellation_credit_uses_the_agreed_price_not_the_catalog_price(): void
    {
        $subscriptionBundle = $this->createSubscriptionBundle(price: 4200);

        $customerA = $this->createCustomer();
        $dealA = Deal::createForCustomer($customerA, null, $subscriptionBundle, 3000.0);
        $subscriptionA = Subscription::where('deal_id', $dealA->id)->firstOrFail();

        $customerB = $this->createCustomer();
        $dealB = Deal::createForCustomer($customerB, null, $subscriptionBundle, 6000.0);
        $subscriptionB = Subscription::where('deal_id', $dealB->id)->firstOrFail();

        $creditA = $subscriptionA->cancel()->cancellation_credit;
        $creditB = $subscriptionB->cancel()->cancellation_credit;

        $this->assertEquals(3000.0, (float) $creditA);
        $this->assertEquals(6000.0, (float) $creditB);
        $this->assertNotEquals((float) $creditA, (float) $creditB);
    }

    /** FR-3.8: bundle/other deals never enter the cancellation-credit calculation. */
    public function test_other_deals_and_bundles_never_affect_the_cancellation_credit(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 4000);
        $customer = $subscription->customer;

        // An unrelated standalone program deal for the same customer.
        Deal::createForCustomer($customer, $this->createProgram(price: 999999), null);

        $cancelled = $subscription->cancel();

        $this->assertEquals(4000.0, (float) $cancelled->cancellation_credit);
    }

    public function test_cancelling_an_already_cancelled_subscription_is_blocked(): void
    {
        $subscription = $this->openSubscription();
        $subscription->cancel();

        $this->expectException(RuntimeException::class);
        $subscription->fresh()->cancel();
    }

    // ----- US-010: cancellation never deletes anything -----

    public function test_cancellation_only_changes_status_and_never_deletes_deal_subscription_or_deliveries(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();
        $delivery = $subscription->deliveries()->first();
        $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $this->owner);

        $dealId = $subscription->deal_id;
        $subscriptionId = $subscription->id;
        $deliveryCountBefore = $subscription->deliveries()->count();

        $subscription->fresh()->cancel();

        $this->assertDatabaseHas('deals', ['id' => $dealId]);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscriptionId]);
        $this->assertSame($deliveryCountBefore, SubscriptionDelivery::where('subscription_id', $subscriptionId)->count());
        $this->assertSame(Subscription::CANCELLED_STATUS_NAME, $subscription->fresh()->status?->name);
    }

    // ----- FR-4.5/FR-8.12: credit note requires an existing invoice -----

    public function test_credit_note_generation_is_blocked_without_an_invoice(): void
    {
        $this->createTemplate('credit_note');
        $subscription = $this->openSubscription();
        $subscription->cancel();

        $this->expectExceptionMessage('לא ניתן להפיק חשבונית זיכוי לעסקה שלא הופקה עבורה חשבונית');
        $subscription->fresh()->generateCreditNote();
    }

    public function test_the_customers_program_filter_includes_customers_who_bought_it_inside_a_bundle(): void
    {
        $program = $this->createProgram('תשרי לבדיקת סינון');
        $active = $this->openSubscription();
        $active->deal->bundle->programs()->attach($program->id);
        $cancelled = $this->openSubscription();
        $cancelled->deal->bundle->programs()->attach($program->id);
        $cancelled->cancel(1, 0, 'בדיקה');
        $other = $this->openSubscription();

        $row = fn (Subscription $s) => route('customer-detail', $s->customer_id);
        Livewire::actingAs($this->owner)->test('customers')
            ->set('filterProgramId', (string) $program->id)
            ->assertSeeHtml($row($active))
            ->assertDontSeeHtml($row($cancelled))
            ->assertDontSeeHtml($row($other));
    }

    public function test_customers_list_frames_only_subscribed_customers(): void
    {
        $subscription = $this->openSubscription();
        $other = $this->createCustomer();

        $html = Livewire::actingAs($this->owner)->test('customers')->html();

        $this->assertMatchesRegularExpression('/<tr class="row-link is-subscription"[^>]*customers\/'.$subscription->customer_id.'\b/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<tr class="row-link is-subscription"[^>]*customers\/'.$other->id.'\b/u', $html);
    }

    public function test_monthly_payment_counts_only_an_issued_invoice_with_an_amount(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 2000);
        $this->assertEquals(200, $subscription->monthlyPayment());

        $this->giveDealAnInvoice($subscription->deal);
        $invoice = $subscription->deal->documents()->where('document_type', 'invoice')->firstOrFail();
        $invoice->lines()->update(['amount' => 1500]);
        $this->assertEquals(150, $subscription->fresh()->monthlyPayment());

        // Never sent (or Summit failed) — not an issued invoice.
        $invoice->update(['sent_at' => null]);
        $this->assertEquals(200, $subscription->fresh()->monthlyPayment());

        // Sent but empty — not an issued invoice either.
        $invoice->update(['sent_at' => now()]);
        $invoice->lines()->delete();
        $this->assertEquals(200, $subscription->fresh()->monthlyPayment());
    }

    public function test_credit_note_generation_succeeds_once_an_invoice_exists(): void
    {
        $this->createTemplate('credit_note');
        $subscription = $this->openSubscription(agreedAmount: 2000);
        $this->giveDealAnInvoice($subscription->deal);
        $subscription->deal->issuedInvoice()->businessEntity->update(['classification' => 'עוסק מורשה']);
        $subscription->cancel();

        $document = $subscription->fresh()->generateCreditNote();

        $this->assertSame('credit_note', $document->document_type);
        $this->assertSame($subscription->deal_id, $document->deal_id);
        $this->assertEquals((float) $subscription->fresh()->cancellation_credit, $document->totalAmount());
    }

    public function test_generating_a_credit_note_with_an_invoice_asks_for_confirmation(): void
    {
        $this->createTemplate('credit_note');
        $subscription = $this->openSubscription();
        $this->giveDealAnInvoice($subscription->deal);
        $subscription->cancel();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->fresh()->customer])
            ->set('activeTab', 'subscription')
            ->assertSeeHtml('wire:confirm="הזיכוי יופק ב-SUMIT ויישלח ללקוחה במייל. זו פעולה חשבונאית בלתי הפיכה — להמשיך?"');
    }

    public function test_a_subscription_deals_credit_note_is_left_to_the_customer_card(): void
    {
        $this->createTemplate('credit_note');
        $subscription = $this->openSubscription();
        $this->giveDealAnInvoice($subscription->deal);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $subscription->deal->fresh()])
            ->assertDontSee('+ הפקת חשבונית זיכוי')
            ->set('creditAmount', '100')
            ->call('generateDealCreditNote')
            ->assertSet('documentError', 'זיכוי על מנוי מופק מכרטיס הלקוח, בלשונית מנוי — לפי החודשים שבוטלו.');
    }

    public function test_credit_note_cannot_be_generated_before_cancellation(): void
    {
        $subscription = $this->openSubscription();
        $this->giveDealAnInvoice($subscription->deal);

        $this->expectException(RuntimeException::class);
        $subscription->generateCreditNote();
    }

    // ----- cancellation from a chosen month -----

    public function test_cancelling_from_a_chosen_month_credits_only_that_month_onward_and_cancels_the_deal(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 5000);

        $cancelled = $subscription->cancel(7);

        $this->assertSame(7, $cancelled->cancelled_from_month);
        $this->assertEquals(5000 / 10 * 4, (float) $cancelled->cancellation_credit);
        $this->assertSame(Deal::CANCELLED_STATUS_NAME, $cancelled->deal->fresh()->status?->name);
        $this->assertNotNull($cancelled->deal->fresh()->completed_at);
    }

    public function test_cannot_cancel_from_a_month_already_supplied(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();
        $third = $subscription->deliveries()->where('sequence_number', 3)->first();
        $subscription->markDeliverySupplied($subscription->version, $third->id, $program->id, $this->owner);

        $this->assertSame(4, $subscription->fresh()->firstCancellableMonth());

        $this->expectExceptionMessage('ניתן לבטל את המנוי החל מחודש 4');
        $subscription->fresh()->cancel(3);
    }

    public function test_months_before_the_cancellation_month_can_still_be_supplied_but_cancelled_ones_cannot(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();
        $subscription->cancel(5);
        $subscription = $subscription->fresh();

        $second = $subscription->deliveries()->where('sequence_number', 2)->first();
        $subscription->markDeliverySupplied($subscription->version, $second->id, $program->id, $this->owner);
        $this->assertTrue($second->fresh()->is_supplied);
        $this->assertSame(Subscription::CANCELLED_STATUS_NAME, $subscription->fresh()->status?->name);

        $sixth = $subscription->deliveries()->where('sequence_number', 6)->first();
        $this->expectException(RuntimeException::class);
        $subscription->fresh()->markDeliverySupplied($subscription->fresh()->version, $sixth->id, $program->id, $this->owner);
    }

    public function test_credit_note_line_details_the_cancelled_months(): void
    {
        $this->createTemplate('credit_note');
        $subscription = $this->openSubscription(agreedAmount: 4000);
        // Month 1 = תשרי תשפ״ז (a leap year: אדר א׳ and אדר ב׳ are months 6 and 7).
        $subscription->update(['first_month_date' => '2026-09-12']);
        $this->giveDealAnInvoice($subscription->deal);
        $subscription->deal->issuedInvoice()->businessEntity->update(['classification' => 'עוסק מורשה']);
        $subscription->fresh()->cancel(5);

        $document = $subscription->fresh()->generateCreditNote();
        $line = $document->lines()->firstOrFail();

        $this->assertSame('זיכוי בגין ביטול מנוי החל מחודש 5 (שבט תשפ״ז – סיוון תשפ״ז): 6 חודשים × ₪400.00', $line->description);
        $this->assertEquals(2400, $document->totalAmount());
    }

    public function test_customer_card_cancels_from_the_chosen_month_and_the_customers_list_drops_the_frame(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 3000);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->customer])
            ->set('activeTab', 'subscription')
            ->call('cancelSubscription', $subscription->id)
            ->assertSet('subscriptionError', 'יש לבחור החל מאיזה חודש המנוי מבוטל.')
            ->set("cancelFromMonths.{$subscription->id}", '8')
            ->assertSet("cancelCredits.{$subscription->id}", '900')
            ->call('cancelSubscription', $subscription->id)
            ->assertSet('subscriptionError', 'יש לרשום את סיבת הביטול.')
            ->set("cancelReasons.{$subscription->id}", 'בית הספר צמצם תקציב')
            ->call('cancelSubscription', $subscription->id)
            ->assertSet('subscriptionError', null)
            ->assertSee('מבוטל החל מ')
            ->assertSee('בוטלה');

        $subscription = $subscription->fresh();
        $this->assertSame(Subscription::CANCELLED_STATUS_NAME, $subscription->status?->name);
        $this->assertSame(8, $subscription->cancelled_from_month);
        $this->assertEquals(900, (float) $subscription->cancellation_credit);

        $html = Livewire::actingAs($this->owner)->test('customers')->html();
        $this->assertDoesNotMatchRegularExpression('/<tr class="row-link is-subscription"[^>]*customers\/'.$subscription->customer_id.'\b/u', $html);
    }

    public function test_the_cancellation_window_credit_can_be_changed_and_the_details_show_on_the_cancelled_deal(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 3000);

        $component = Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->customer])
            ->set('activeTab', 'subscription')
            ->set("cancelFromMonths.{$subscription->id}", '8')
            ->set("cancelCredits.{$subscription->id}", '5000')
            ->set("cancelReasons.{$subscription->id}", 'עברו לספק אחר')
            ->call('cancelSubscription', $subscription->id)
            ->assertSet('subscriptionError', 'סכום הזיכוי חייב להיות בין ₪0 לסכום העסקה (₪3,000).');

        $component->set("cancelCredits.{$subscription->id}", '750')
            ->call('cancelSubscription', $subscription->id)
            ->assertSet('subscriptionError', null)
            ->assertSee('עברו לספק אחר');

        $subscription = $subscription->fresh();
        $this->assertEquals(750, (float) $subscription->cancellation_credit);
        $this->assertSame('עברו לספק אחר', $subscription->cancellation_reason);
        $this->assertStringNotContainsString('×', $subscription->creditDescription()); // a hand-set amount isn't months × price

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $subscription->deal])
            ->assertSee('פרטי ביטול המנוי')
            ->assertSee('עברו לספק אחר')
            ->assertSee('750');
    }

    public function test_the_cancellation_credit_lowers_the_balance_at_once_and_is_not_counted_twice_once_issued(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 4500);
        $this->giveDealAnInvoice($subscription->deal);
        $subscription->deal->issuedInvoice()->businessEntity->update(['classification' => 'עוסק מורשה']);
        $this->createTemplate('credit_note');
        $subscription->cancel(1, 3150, 'בדיקה');
        $deal = $subscription->deal->fresh();

        $this->assertEquals(3150, $deal->totalCredited());
        $this->assertEquals(1350, $deal->outstandingBalance());

        $subscription->fresh()->generateCreditNote()->update(['sent_at' => now()]); // as if issued in SUMIT

        $this->assertEquals(3150, $deal->fresh()->totalCredited());
        $this->assertEquals(1350, $deal->fresh()->outstandingBalance());
    }

    public function test_paying_off_a_cancelled_deals_remaining_balance_keeps_it_cancelled(): void
    {
        $subscription = $this->openSubscription(agreedAmount: 4500);
        $subscription->cancel(1, 4150, 'בדיקה');
        $deal = $subscription->deal->fresh();
        $transfer = \App\Models\PaymentMethod::create(['name' => 'העברה בנקאית', 'type' => 'bank_transfer', 'is_active' => true]);

        $deal->recordPayment($deal->version, 350, null, $transfer->id);

        $this->assertEquals(0, $deal->fresh()->outstandingBalance());
        $this->assertSame(Deal::CANCELLED_STATUS_NAME, $deal->fresh()->status->name);
    }

    public function test_a_new_subscription_pins_month_one_to_the_hebrew_month_set_in_settings(): void
    {
        $this->travelTo('2026-10-07'); // כ״ו תשרי תשפ״ז
        \App\Models\AppSetting::put(\App\Services\HebrewCalendar::START_MONTH_SETTING, '1'); // חשוון

        $subscription = $this->openSubscription();

        $this->assertSame('2026-10-12', $subscription->first_month_date->toDateString()); // א׳ חשוון תשפ״ז
        $this->assertSame('חשוון תשפ״ז', $subscription->monthLabel(1));
        $this->assertSame('אדר א׳ תשפ״ז', $subscription->monthLabel(5));
        $this->assertSame('תמוז תשפ״ז', $subscription->monthLabel(10));

        // A later settings change never relabels an existing subscription.
        \App\Models\AppSetting::put(\App\Services\HebrewCalendar::START_MONTH_SETTING, '0');
        $this->assertSame('חשוון תשפ״ז', $subscription->fresh()->monthLabel(1));
    }

    public function test_settings_page_saves_the_subscription_start_month(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->assertDontSee('חודש תחילת מנוי')
            ->set('activeTab', 'subscriptions')
            ->assertSee('חודש תחילת מנוי')
            ->set('subscriptionStartMonth', '2')
            ->call('saveSubscriptionSettings')
            ->assertHasNoErrors();

        $this->assertSame(2, \App\Services\HebrewCalendar::startMonth());

        Livewire::actingAs($this->owner)->test('settings')
            ->set('subscriptionStartMonth', '6') // not offered — אדר is 5
            ->call('saveSubscriptionSettings')
            ->assertHasErrors('subscriptionStartMonth');
    }

    public function test_a_supplied_delivery_can_be_unmarked_and_an_auto_ended_subscription_reopens(): void
    {
        $subscription = $this->openSubscription();
        $program = $this->createProgram();

        foreach ($subscription->deliveries as $delivery) {
            $subscription->fresh()->markDeliverySupplied($subscription->fresh()->version, $delivery->id, $program->id, $this->owner);
        }
        $this->assertSame(Subscription::ENDED_STATUS_NAME, $subscription->fresh()->status?->name);

        $last = $subscription->deliveries()->where('sequence_number', 10)->first();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->customer])
            ->call('unmarkDeliverySupplied', $subscription->id, $last->id)
            ->assertSet('subscriptionError', null);

        $this->assertFalse($last->fresh()->is_supplied);
        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $subscription->fresh()->status?->name);
        $this->assertNull($subscription->fresh()->end_date);
    }

    // ----- a subscription ends once its 10 months are over -----

    public function test_a_subscription_ends_by_itself_once_its_ten_months_are_over_even_with_unsupplied_months(): void
    {
        $this->travelTo('2026-10-16');
        $expired = $this->openSubscription();
        $program = $this->createProgram();
        $first = $expired->deliveries()->where('sequence_number', 1)->first();
        $expired->markDeliverySupplied($expired->version, $first->id, $program->id, $this->owner);
        $cancelled = $this->openSubscription();
        $cancelled->cancel();

        // The last day of month 10 — still active.
        $this->travelTo($expired->periodEndDate()->setTime(12, 0));
        $this->artisan('subscriptions:end-expired')->assertSuccessful();
        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $expired->fresh()->status?->name);

        // The next day the next cycle has begun — a subscription bought now belongs to it.
        $this->travel(1)->days();
        $current = $this->openSubscription();
        $this->artisan('subscriptions:end-expired')->assertSuccessful();

        $expired->refresh();
        $this->assertSame(Subscription::ENDED_STATUS_NAME, $expired->status?->name);
        $this->assertSame($expired->periodEndDate()->toDateString(), $expired->end_date->toDateString());
        $this->assertSame(9, $expired->deliveries()->where('is_supplied', false)->count());
        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $current->fresh()->status?->name);
        $this->assertSame(Subscription::CANCELLED_STATUS_NAME, $cancelled->fresh()->status?->name);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'subscription.ended', 'deal_id' => $expired->deal_id]);

        // The deal stays as history, and the subscriber discount on premium programs stops.
        $this->assertNotNull(Deal::find($expired->deal_id));
        $premium = $this->createProgram(price: 1000, isPremium: true);
        $this->assertEquals(1000, (float) Deal::createForCustomer($expired->customer, $premium, null)->agreed_amount);
    }

    public function test_an_ended_subscription_can_still_record_a_late_delivery_and_is_not_reopened_by_unmarking(): void
    {
        $this->travelTo('2026-10-16');
        $subscription = $this->openSubscription();
        $program = $this->createProgram();
        $this->travelTo($subscription->periodEndDate()->addDays(3));
        Subscription::endExpired(app(\App\Services\ActivityLogger::class));

        $subscription->refresh();
        $delivery = $subscription->deliveries()->where('sequence_number', 2)->first();
        $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $this->owner);
        $this->assertTrue($delivery->fresh()->is_supplied);

        $subscription->refresh()->unmarkDeliverySupplied($subscription->version, $delivery->id);
        $this->assertSame(Subscription::ENDED_STATUS_NAME, $subscription->fresh()->status?->name);
    }

    public function test_a_subscription_bought_after_the_cycle_ends_belongs_to_the_next_years_months(): void
    {
        \App\Models\AppSetting::put(\App\Services\HebrewCalendar::START_MONTH_SETTING, '12'); // אלול

        $this->travelTo('2026-10-16'); // חשוון תשפ״ז
        $thisYear = $this->openSubscription();
        $this->assertSame('אלול תשפ״ו', $thisYear->monthLabel(1));
        $this->assertSame('אייר תשפ״ז', $thisYear->monthLabel(10)); // a leap year — both Adars count

        $this->travelTo('2027-07-15'); // תמוז תשפ״ז, after that cycle
        $nextYear = $this->openSubscription();
        $this->assertSame('אלול תשפ״ז', $nextYear->monthLabel(1));
        $this->assertSame('סיוון תשפ״ח', $nextYear->monthLabel(10));

        $this->artisan('subscriptions:end-expired');
        $this->assertSame(Subscription::ENDED_STATUS_NAME, $thisYear->fresh()->status?->name);
        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $nextYear->fresh()->status?->name);
    }

    public function test_an_ended_subscription_leaves_the_subscribers_list_unless_a_renewal_is_active(): void
    {
        $isSubscriber = fn (Customer $customer) => \App\Models\MailingMembership::where('mailing_list_id', \App\Models\MailingList::subscribersList()->id)
            ->where('customer_id', $customer->id)->where('membership_status', \App\Models\MailingMembership::STATUS_ACTIVE)->exists();

        $this->travelTo('2026-10-16');
        $lapsed = $this->openSubscription();
        $renewed = $this->openSubscription();
        $this->assertTrue($isSubscriber($lapsed->customer));

        // The renewing customer buys next year's subscription once the next cycle has begun.
        $this->travelTo($renewed->periodEndDate()->addDays(1)->setTime(9, 0));
        $renewal = Subscription::where('deal_id', Deal::createForCustomer($renewed->customer, null, $this->createSubscriptionBundle())->id)->firstOrFail();

        $this->artisan('subscriptions:end-expired');

        $this->assertSame(Subscription::ENDED_STATUS_NAME, $lapsed->fresh()->status?->name);
        $this->assertFalse($isSubscriber($lapsed->customer));
        $this->assertSame(Subscription::ENDED_STATUS_NAME, $renewed->fresh()->status?->name);
        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $renewal->fresh()->status?->name);
        $this->assertTrue($isSubscriber($renewed->customer));
    }

    public function test_ending_on_the_tenth_delivery_leaves_the_subscribers_list_and_unmarking_rejoins_it(): void
    {
        $isSubscriber = fn (Customer $customer) => \App\Models\MailingMembership::where('mailing_list_id', \App\Models\MailingList::subscribersList()->id)
            ->where('customer_id', $customer->id)->where('membership_status', \App\Models\MailingMembership::STATUS_ACTIVE)->exists();

        $subscription = $this->openSubscription();
        $program = $this->createProgram();
        foreach ($subscription->deliveries as $delivery) {
            $subscription->fresh()->markDeliverySupplied($subscription->fresh()->version, $delivery->id, $program->id, $this->owner);
        }
        $this->assertSame(Subscription::ENDED_STATUS_NAME, $subscription->fresh()->status?->name);
        $this->assertFalse($isSubscriber($subscription->customer));

        $last = $subscription->deliveries()->where('sequence_number', 10)->first();
        $subscription->fresh()->unmarkDeliverySupplied($subscription->fresh()->version, $last->id);

        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $subscription->fresh()->status?->name);
        $this->assertTrue($isSubscriber($subscription->customer));
    }

    // ----- FR-8.23: standalone-program price-exceeded alert -----

    public function test_price_exceeded_alert_appears_when_standalone_purchases_exceed_the_subscription_price(): void
    {
        $this->createSubscriptionBundle(price: 1000);
        $customer = $this->createCustomer();

        Deal::createForCustomer($customer, $this->createProgram(price: 600), null);
        Deal::createForCustomer($customer, $this->createProgram(price: 600), null);

        $this->assertTrue($customer->exceedsSubscriptionPriceAlert());
    }

    public function test_price_exceeded_alert_does_not_appear_below_the_threshold(): void
    {
        $this->createSubscriptionBundle(price: 1000);
        $customer = $this->createCustomer();

        Deal::createForCustomer($customer, $this->createProgram(price: 600), null);

        $this->assertFalse($customer->exceedsSubscriptionPriceAlert());
    }

    public function test_bundle_deals_never_count_toward_the_price_exceeded_alert(): void
    {
        $this->createSubscriptionBundle(price: 500);
        $customer = $this->createCustomer();

        $bundle = Bundle::create([
            'name' => 'מארז בדיקה '.random_int(1, 999999),
            'description' => null,
            'price' => 999999,
            'is_active' => true,
        ]);
        Deal::createForCustomer($customer, null, $bundle);

        $this->assertFalse($customer->exceedsSubscriptionPriceAlert());
    }

    public function test_the_subscription_type_bundle_itself_never_counts_as_a_standalone_purchase(): void
    {
        $subscriptionBundle = $this->createSubscriptionBundle(price: 1000);
        $customer = $this->createCustomer();

        Deal::createForCustomer($customer, null, $subscriptionBundle);

        $this->assertFalse($customer->exceedsSubscriptionPriceAlert());
    }

    // ----- auth / permission gating -----

    public function test_marking_a_delivery_supplied_is_blocked_without_the_subscriptions_permission(): void
    {
        $subscription = $this->openSubscription();
        $delivery = $subscription->deliveries()->first();
        $program = $this->createProgram();
        $limitedUser = $this->userWithOnlyCustomersPermission();

        Livewire::actingAs($limitedUser)->test('customer-detail', ['customer' => $subscription->customer])
            ->set("deliveryProgramSelections.{$delivery->id}", (string) $program->id)
            ->call('markDeliverySupplied', $subscription->id, $delivery->id)
            ->assertStatus(403);

        $this->assertFalse($delivery->fresh()->is_supplied);
    }

    public function test_cancelling_a_subscription_is_blocked_without_the_subscriptions_permission(): void
    {
        $subscription = $this->openSubscription();
        $limitedUser = $this->userWithOnlyCustomersPermission();

        Livewire::actingAs($limitedUser)->test('customer-detail', ['customer' => $subscription->customer])
            ->call('cancelSubscription', $subscription->id)
            ->assertStatus(403);

        $this->assertSame(Subscription::ACTIVE_STATUS_NAME, $subscription->fresh()->status?->name);
    }

    public function test_generating_a_credit_note_is_blocked_without_the_subscriptions_permission(): void
    {
        $subscription = $this->openSubscription();
        $this->giveDealAnInvoice($subscription->deal);
        $subscription->cancel();
        $limitedUser = $this->userWithOnlyCustomersPermission();

        Livewire::actingAs($limitedUser)->test('customer-detail', ['customer' => $subscription->fresh()->customer])
            ->call('generateSubscriptionCreditNote', $subscription->id)
            ->assertStatus(403);
    }

    public function test_customer_card_renders_for_a_user_with_the_subscriptions_permission_and_shows_the_active_badge(): void
    {
        $subscription = $this->openSubscription();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $subscription->customer])
            ->assertOk()
            ->assertSee('מנויה פעילה');
    }

    // ----- no delete route anywhere for subscriptions/deliveries -----

    public function test_there_is_no_delete_route_for_subscriptions_or_deliveries(): void
    {
        $matchingRoutes = 0;

        foreach (app('router')->getRoutes() as $route) {
            $uri = strtolower($route->uri());
            if (str_contains($uri, 'subscription') || str_contains($uri, 'delivery') || str_contains($uri, 'deliveries')) {
                $matchingRoutes++;
                $this->assertNotContains('DELETE', $route->methods(), "Unexpected DELETE route: {$uri}");
            }
        }

        // Subscriptions have no dedicated routes at all yet (every action is a
        // Livewire method on ⚡customer-detail.blade.php) — assert that
        // directly instead, so this test still makes a real assertion.
        $this->assertSame(0, $matchingRoutes);
    }

    // ----- helpers -----

    private function openSubscription(float $agreedAmount = 4200): Subscription
    {
        $customer = $this->createCustomer();
        $subscriptionBundle = $this->createSubscriptionBundle();
        $deal = Deal::createForCustomer($customer, null, $subscriptionBundle, $agreedAmount);

        return Subscription::where('deal_id', $deal->id)->firstOrFail();
    }

    private function giveDealAnInvoice(Deal $deal): void
    {
        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $this->createTemplate('contract'));
        $contract->markSigned();

        $invoice = Document::generateFor($deal, $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity()->id);
        // Document::generateFor() already adds a line for the deal's agreed amount.
        $invoice->update(['sent_at' => now()]); // issued — see Deal::issuedInvoice()
    }

    private function userWithOnlyCustomersPermission(): User
    {
        $limited = Role::create(['name' => 'עובדת מכירות מוגבלת '.random_int(1, 999999), 'is_active' => true]);
        // Subscriptions live on the customer card, so they follow the customers page's edit permission.
        $limited->permissions()->create(['resource' => 'customers', 'action' => 'view', 'is_allowed' => true]);

        return User::create([
            'name' => 'שרית לוי', 'email' => 'sarit'.random_int(1, 999999).'@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת מנויים '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        return $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
    }

    private function createSubscriptionBundle(?string $name = null, float $price = 4200, bool $isActive = true): Bundle
    {
        return Bundle::create([
            'name' => $name ?? 'מנוי שנתי לבדיקה '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_subscription_type' => true,
            'is_active' => $isActive,
        ]);
    }

    private function createProgram(
        ?string $name = null,
        float $price = 400,
        bool $isActive = true,
        bool $isPremium = false,
    ): Program {
        return Program::create([
            'name' => $name ?? 'תוכנית בדיקת מנויים '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_premium' => $isPremium,
            'is_active' => $isActive,
        ]);
    }

    private function createTemplate(string $documentType): DocumentTemplate
    {
        return DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => ucfirst($documentType).' תבנית בדיקת מנויים '.random_int(1, 999999),
            'content' => 'תוכן בדיקה עבור '.$documentType,
            'is_active' => true,
        ]);
    }

    private function createBusinessEntity(): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק לבדיקת מנויים '.random_int(1, 999999),
            'classification' => 'עוסק פטור',
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'business'.random_int(1, 999999).'@example.com',
            'phone' => '03-0000000',
            'is_active' => true,
        ]);
    }
}
