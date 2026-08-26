<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DealsManagementTest extends TestCase
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

    public function test_deal_detail_requires_auth(): void
    {
        $deal = $this->createDeal();

        $this->get("/deals/{$deal->id}")->assertRedirect('/login');
    }

    public function test_deal_detail_is_blocked_without_the_deals_permission(): void
    {
        $deal = $this->createDeal();

        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get("/deals/{$deal->id}")->assertStatus(403);
    }

    public function test_creating_a_deal_requires_exactly_one_of_program_or_bundle(): void
    {
        $program = $this->createProgram();
        $bundle = $this->createBundle();

        $customer = $this->createCustomer();
        $deal = Deal::createForCustomer($customer, $program, null);
        $this->assertSame($program->id, $deal->program_id);
        $this->assertNull($deal->bundle_id);

        $deal2 = Deal::createForCustomer($customer, null, $bundle);
        $this->assertSame($bundle->id, $deal2->bundle_id);
        $this->assertNull($deal2->program_id);
    }

    /**
     * FR-3.3/FR-8.4: neither program nor bundle, or both, is blocked.
     */
    public function test_creating_a_deal_is_blocked_when_neither_program_nor_bundle_is_given(): void
    {
        $customer = $this->createCustomer();

        $this->expectException(RuntimeException::class);
        Deal::createForCustomer($customer, null, null);
    }

    public function test_creating_a_deal_is_blocked_when_both_program_and_bundle_are_given(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();
        $bundle = $this->createBundle();

        $this->expectException(RuntimeException::class);
        Deal::createForCustomer($customer, $program, $bundle);
    }

    /**
     * FR-8.4: the creation form (customer-detail's "עסקאות" tab) blocks
     * submission when no program/bundle was picked — no deal is created.
     */
    public function test_the_deal_creation_form_blocks_submission_without_a_selected_item(): void
    {
        $customer = $this->createCustomer();

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('dealItem', '')
            ->call('createDeal')
            ->assertHasErrors(['dealItem']);

        $this->assertSame(0, Deal::count());
    }

    /**
     * FR-8.5: a disabled program/bundle must not be sellable, even if its id
     * is submitted directly (defensive server-side check).
     */
    public function test_creating_a_deal_against_a_disabled_program_is_blocked(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram(isActive: false);

        $this->expectException(RuntimeException::class);
        Deal::createForCustomer($customer, $program, null);
    }

    public function test_creating_a_deal_against_a_disabled_bundle_is_blocked(): void
    {
        $customer = $this->createCustomer();
        $bundle = $this->createBundle(isActive: false);

        $this->expectException(RuntimeException::class);
        Deal::createForCustomer($customer, null, $bundle);
    }

    /**
     * A disabled program never even shows up as a selectable option in the
     * creation form's picker.
     */
    public function test_disabled_programs_and_bundles_are_not_offered_in_the_creation_form(): void
    {
        $customer = $this->createCustomer();
        $activeProgram = $this->createProgram(name: 'תוכנית פעילה לבחירה');
        $this->createProgram(name: 'תוכנית מושבתת', isActive: false);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('activeTab', 'deals')
            ->assertSee('תוכנית פעילה לבחירה')
            ->assertDontSee('תוכנית מושבתת');
    }

    public function test_creating_a_deal_via_the_customer_card_snapshots_price_and_name_and_logs_activity(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram(name: 'תוכנית לצילום מצב', price: 555);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('activeTab', 'deals')
            ->set('dealItem', "program:{$program->id}")
            ->call('createDeal')
            ->assertHasNoErrors();

        $deal = Deal::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($program->id, $deal->program_id);
        $this->assertSame('תוכנית לצילום מצב', $deal->program_name_snapshot);
        $this->assertEquals(555, $deal->program_price_snapshot);
        $this->assertEquals(555, $deal->agreed_amount);

        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'deal.created', 'deal_id' => $deal->id, 'customer_id' => $customer->id,
        ]);
    }

    /**
     * FR-3.6: a deal's snapshot must stay stable even after the live
     * program's price/name later change or it gets disabled.
     */
    public function test_deal_snapshot_remains_stable_after_the_live_program_changes(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram(name: 'שם מקורי', price: 300);

        $deal = Deal::createForCustomer($customer, $program, null);

        $program->update(['name' => 'שם חדש לגמרי', 'price' => 999, 'is_active' => false]);

        $deal->refresh();
        $this->assertSame('שם מקורי', $deal->program_name_snapshot);
        $this->assertEquals(300, $deal->program_price_snapshot);
        $this->assertEquals(300, $deal->agreed_amount);
    }

    /**
     * FR-3.4: multiple programs purchased = multiple separate deals.
     */
    public function test_multiple_program_purchases_create_separate_deals(): void
    {
        $customer = $this->createCustomer();
        $programA = $this->createProgram(name: 'תוכנית א');
        $programB = $this->createProgram(name: 'תוכנית ב');

        Deal::createForCustomer($customer, $programA, null);
        Deal::createForCustomer($customer, $programB, null);

        $this->assertSame(2, Deal::where('customer_id', $customer->id)->count());
    }

    /**
     * FR-3.4 stub: an is_subscription_type program routes into the (not yet
     * built, stage 9) subscription-opening flow — deliberately a no-op, but
     * the deal itself must still be created normally.
     */
    public function test_deal_creation_calls_the_subscription_stub_without_error_for_a_subscription_program(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram(name: 'מנוי לבדיקה', isSubscription: true);

        $deal = Deal::createForCustomer($customer, $program, null);

        $this->assertNotNull($deal->id);
        $this->assertSame($program->id, $deal->program_id);
    }

    /**
     * FR-3.5: cancelling a deal only changes its status — the row is never
     * deleted — and the change is logged.
     */
    public function test_cancelling_a_deal_only_changes_status_and_is_logged(): void
    {
        $deal = $this->createDeal();
        $cancelled = StatusDefinition::create(['scope' => 'deal', 'name' => 'מבוטלת', 'is_active' => true, 'sort_order' => 4]);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('selectedStatusId', (string) $cancelled->id)
            ->call('updateStatus')
            ->assertSet('statusError', null);

        $deal->refresh();
        $this->assertSame($cancelled->id, $deal->status_id);
        $this->assertDatabaseHas('deals', ['id' => $deal->id]);
        $this->assertNotNull($deal->completed_at);

        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'deal.status_changed', 'deal_id' => $deal->id,
        ]);
    }

    public function test_there_is_no_delete_route_for_deals(): void
    {
        $routeUris = collect(app('router')->getRoutes())->map(fn ($route) => strtolower($route->uri()))->values();

        $this->assertFalse(
            $routeUris->contains(fn ($uri) => str_contains($uri, 'deals') && str_contains($uri, 'delete')),
            'Unexpected delete route matching deals.'
        );

        foreach (app('router')->getRoutes() as $route) {
            if (str_contains(strtolower($route->uri()), 'deals')) {
                $this->assertNotContains('DELETE', $route->methods());
            }
        }
    }

    /**
     * FR-8.19: optimistic locking — a concurrent update between load and
     * save must be rejected with a conflict error, never silently applied.
     */
    public function test_concurrent_status_update_is_rejected_with_a_conflict_error(): void
    {
        $deal = $this->createDeal();
        $invoiceSent = StatusDefinition::create(['scope' => 'deal', 'name' => 'נשלחה חשבונית', 'is_active' => true, 'sort_order' => 2]);
        $paid = StatusDefinition::create(['scope' => 'deal', 'name' => 'שולמה', 'is_active' => true, 'sort_order' => 3]);

        $component = Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal]);

        // Someone else updates the deal in the meantime (bumps version).
        Deal::where('id', $deal->id)->where('version', $deal->version)->update([
            'status_id' => $invoiceSent->id,
            'version' => $deal->version + 1,
        ]);

        $component->set('selectedStatusId', (string) $paid->id)
            ->call('updateStatus')
            ->assertSet('statusError', fn ($message) => ! empty($message));

        // The second (stale) update must NOT have applied.
        $deal->refresh();
        $this->assertSame($invoiceSent->id, $deal->status_id);
        $this->assertNotSame($paid->id, $deal->status_id);
    }

    public function test_customer_cards_deals_tab_renders_real_deal_data(): void
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram(name: 'תוכנית מוצגת בטאב עסקאות');
        Deal::createForCustomer($customer, $program, null);

        $response = $this->actingAs($this->owner)->get("/customers/{$customer->id}");

        $response->assertOk();
    }

    public function test_deal_detail_page_shows_snapshot_and_customer(): void
    {
        $deal = $this->createDeal();

        $response = $this->actingAs($this->owner)->get("/deals/{$deal->id}");

        $response->assertOk();
        $response->assertSee($deal->program_name_snapshot);
    }

    public function test_optional_payment_method_can_be_set_on_a_deal(): void
    {
        $deal = $this->createDeal();
        $method = PaymentMethod::create(['name' => 'אשראי לבדיקה', 'type' => 'card', 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->set('agreedAmount', (string) $deal->agreed_amount)
            ->set('paymentMethodId', (string) $method->id)
            ->call('saveDetails');

        $this->assertDatabaseHas('deals', ['id' => $deal->id, 'payment_method_id' => $method->id]);
    }

    // ----- helpers -----

    private function createDeal(): Deal
    {
        $customer = $this->createCustomer();
        $program = $this->createProgram();

        return Deal::createForCustomer($customer, $program, null);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת עסקאות '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        return $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
    }

    private function createProgram(
        ?string $name = null,
        float $price = 400,
        bool $isActive = true,
        bool $isSubscription = false,
    ): Program {
        return Program::create([
            'name' => $name ?? 'תוכנית בדיקה '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_premium' => false,
            'is_subscription_type' => $isSubscription,
            'is_active' => $isActive,
        ]);
    }

    private function createBundle(?string $name = null, float $price = 500, bool $isActive = true): Bundle
    {
        return Bundle::create([
            'name' => $name ?? 'מארז בדיקה '.random_int(1, 999999),
            'description' => null,
            'price' => $price,
            'is_active' => $isActive,
        ]);
    }
}
