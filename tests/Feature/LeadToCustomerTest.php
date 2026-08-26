<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadToCustomerTest extends TestCase
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

    public function test_customers_list_requires_auth(): void
    {
        $this->get('/customers')->assertRedirect('/login');
    }

    public function test_customer_detail_requires_auth(): void
    {
        $customer = $this->convertLead($this->createLead(schoolName: 'בית ספר לבדיקה'));

        $this->get("/customers/{$customer->id}")->assertRedirect('/login');
    }

    public function test_customers_page_is_blocked_without_the_customers_permission(): void
    {
        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get('/customers')->assertStatus(403);
    }

    public function test_converting_a_lead_creates_a_customer_backfills_contacts_and_logs_activity(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר יובלים לדוגמה');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'מירב כהן', 'is_primary' => true]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('convertToCustomer')
            ->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'lead_id' => $lead->id, 'school_id' => $lead->school_id,
        ]);

        $customer = Customer::where('lead_id', $lead->id)->firstOrFail();
        $this->assertNotNull($customer->converted_at);
        $this->assertSame('פעילה', $customer->status->name);

        // Contacts move conceptually to the customer without losing school_id.
        $this->assertSame($customer->id, $contact->fresh()->customer_id);
        $this->assertSame($lead->school_id, $contact->fresh()->school_id);

        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'customer.created', 'customer_id' => $customer->id, 'lead_id' => $lead->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'lead.converted', 'customer_id' => $customer->id, 'lead_id' => $lead->id,
        ]);

        // The lead itself is not deleted or duplicated.
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
        $this->assertSame(1, Lead::count());
    }

    /**
     * FR-2.8: if none of the school's existing contacts happens to be
     * primary yet, conversion promotes the first one so the customer is
     * never left without a primary contact.
     */
    public function test_conversion_promotes_a_primary_contact_when_none_exists_yet(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר ללא ראשי');
        Contact::create(['school_id' => $lead->school_id, 'name' => 'איש קשר יחיד']);

        $customer = $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));

        $this->assertSame(1, $customer->contacts()->where('is_primary', true)->count());
    }

    public function test_a_lead_without_a_school_cannot_be_converted(): void
    {
        $lead = $this->createLead(); // no school

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('convertToCustomer')
            ->assertSet('conversionError', fn ($message) => ! empty($message));

        $this->assertDatabaseCount('customers', 0);
    }

    /**
     * FR-1.15/FR-2.3/FR-8.2: a lead converts to a customer at most once.
     */
    public function test_a_lead_cannot_be_converted_twice(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר כפול');
        $customer = $this->convertLead($lead);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('convertToCustomer')
            ->assertSet('conversionError', fn ($message) => ! empty($message));

        $this->assertSame(1, Customer::count());
        $this->assertSame($customer->id, Customer::first()->id);
    }

    public function test_the_customers_table_enforces_a_unique_lead_id_at_the_database_level(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר ייחודיות');
        $status = StatusDefinition::create(['scope' => 'customer', 'name' => 'פעילה', 'is_active' => true, 'sort_order' => 1]);

        Customer::create(['school_id' => $lead->school_id, 'lead_id' => $lead->id, 'status_id' => $status->id, 'converted_at' => now()]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Customer::create(['school_id' => $lead->school_id, 'lead_id' => $lead->id, 'status_id' => $status->id, 'converted_at' => now()]);
    }

    public function test_lead_detail_shows_a_link_to_the_customer_card_after_conversion(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר עם קישור');
        $customer = $this->convertLead($lead);

        $response = $this->actingAs($this->owner)->get("/leads/{$lead->id}");

        $response->assertOk();
        $response->assertSee('הומר ללקוחה');
        $response->assertSee(route('customer-detail', $customer), false);
    }

    public function test_there_is_no_manual_create_route_for_customers(): void
    {
        $routeUris = collect(app('router')->getRoutes())->map(fn ($route) => strtolower($route->uri()))->values();

        $this->assertFalse(
            $routeUris->contains(fn ($uri) => $uri === 'customers/create' || $uri === 'customers/new'),
            'Unexpected manual customer-creation route.'
        );
    }

    public function test_customer_never_gets_a_delete_route(): void
    {
        $routeUris = collect(app('router')->getRoutes())->map(fn ($route) => strtolower($route->uri()))->values();

        $this->assertFalse(
            $routeUris->contains(fn ($uri) => str_contains($uri, 'customers') && str_contains($uri, 'delete')),
            'Unexpected delete route matching customers.'
        );
    }

    public function test_owner_can_edit_customer_school_details(): void
    {
        $customer = $this->convertLead($this->createLead(schoolName: 'בית ספר לעריכה בכרטיס לקוחה'));

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('editingSchool', true)
            ->set('schoolName', 'בית ספר לעריכה בכרטיס לקוחה')
            ->set('schoolCity', 'ירושלים')
            ->call('saveSchool');

        $this->assertDatabaseHas('schools', ['id' => $customer->school_id, 'city' => 'ירושלים']);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'customer.school_updated', 'customer_id' => $customer->id]);
    }

    public function test_owner_can_add_a_contact_to_a_customer(): void
    {
        $customer = $this->convertLead($this->createLead(schoolName: 'בית ספר עם איש קשר חדש'));

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('contactName', 'איש קשר חדש')
            ->set('contactPhone', '050-1112223')
            ->call('addContact');

        $this->assertDatabaseHas('contacts', [
            'customer_id' => $customer->id, 'school_id' => $customer->school_id, 'name' => 'איש קשר חדש',
        ]);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'contact.created', 'customer_id' => $customer->id]);
    }

    /**
     * FR-2.8: the very first contact added to a customer is forced primary.
     */
    public function test_first_contact_added_to_a_customer_with_no_contacts_is_forced_primary(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר ללא אנשי קשר');
        $customer = $this->convertLead($lead);
        $this->assertSame(0, $customer->contacts()->count());

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('contactName', 'איש קשר ראשון')
            ->set('contactIsPrimary', false)
            ->call('addContact');

        $this->assertDatabaseHas('contacts', ['customer_id' => $customer->id, 'name' => 'איש קשר ראשון', 'is_primary' => true]);
    }

    /**
     * FR-2.9: cannot unmark the primary flag from the last remaining primary
     * contact of a customer.
     */
    public function test_cannot_unmark_the_last_primary_contact_of_a_customer(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר ראשי יחיד');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'איש קשר ראשי יחיד', 'is_primary' => true]);
        $customer = $this->convertLead($lead);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->call('editContact', $contact->id)
            ->set('contactIsPrimary', false)
            ->call('updateContact')
            ->assertSet('contactError', fn ($message) => ! empty($message));

        $this->assertTrue($contact->fresh()->is_primary);
    }

    /**
     * FR-2.9: cannot remove/soft-delete the last remaining primary contact.
     */
    public function test_cannot_remove_the_last_primary_contact_of_a_customer(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר להסרה');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'איש קשר יחיד', 'is_primary' => true]);
        $customer = $this->convertLead($lead);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->call('removeContact', $contact->id)
            ->assertSet('contactError', fn ($message) => ! empty($message));

        $this->assertNull($contact->fresh()->deleted_at);
    }

    /**
     * A second primary contact can absorb the "ראשי" flag before the first
     * one is unmarked/removed — the rule only blocks the *last* one.
     */
    public function test_can_unmark_a_primary_contact_when_another_primary_remains(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר עם שני ראשיים');
        $first = Contact::create(['school_id' => $lead->school_id, 'name' => 'ראשי א', 'is_primary' => true]);
        Contact::create(['school_id' => $lead->school_id, 'name' => 'ראשי ב', 'is_primary' => true]);
        $customer = $this->convertLead($lead);

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->call('editContact', $first->id)
            ->set('contactIsPrimary', false)
            ->call('updateContact')
            ->assertSet('contactError', null);

        $this->assertFalse($first->fresh()->is_primary);
    }

    public function test_customers_list_page_renders_real_seeded_data(): void
    {
        $this->convertLead($this->createLead(schoolName: 'בית ספר מוצג ברשימת לקוחות'));

        $response = $this->actingAs($this->owner)->get('/customers');

        $response->assertOk();
        $response->assertSee('בית ספר מוצג ברשימת לקוחות');
    }

    public function test_customer_detail_page_renders_school_and_history(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר מוצג בכרטיס לקוחה');
        $customer = $this->convertLead($lead);

        $response = $this->actingAs($this->owner)->get("/customers/{$customer->id}");

        $response->assertOk();
        $response->assertSee('בית ספר מוצג בכרטיס לקוחה');
    }

    private function convertLead(Lead $lead): Customer
    {
        return $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
    }

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
}
