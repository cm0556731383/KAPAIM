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

    public function test_credit_note_generation_succeeds_once_an_invoice_exists(): void
    {
        $this->createTemplate('credit_note');
        $subscription = $this->openSubscription(agreedAmount: 2000);
        $this->giveDealAnInvoice($subscription->deal);
        $subscription->cancel();

        $document = $subscription->fresh()->generateCreditNote();

        $this->assertSame('credit_note', $document->document_type);
        $this->assertSame($subscription->deal_id, $document->deal_id);
        $this->assertEquals((float) $subscription->fresh()->cancellation_credit, $document->totalAmount());
    }

    public function test_credit_note_cannot_be_generated_before_cancellation(): void
    {
        $subscription = $this->openSubscription();
        $this->giveDealAnInvoice($subscription->deal);

        $this->expectException(RuntimeException::class);
        $subscription->generateCreditNote();
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
        $invoice->addLine('שורת בדיקה', (float) $deal->agreed_amount);
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
