<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Build-plan 14 (FR-7.14/FR-7.15, US-018): the global search box must
 * respect the exact same record-level scoping as the leads/customers list
 * screens themselves (build-plan 13) — see ⚡global-search.blade.php's
 * docblock for the reasoning. Test setup mirrors SalesRepPermissionsTest.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $repA;

    private User $repB;

    protected function setUp(): void
    {
        parent::setUp();

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $fullAccess->id, 'is_active' => true,
        ]);

        $salesRep = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $salesRep->permissions()->create(['resource' => 'leads', 'action' => 'view', 'is_allowed' => true]);

        $this->repA = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $salesRep->id, 'is_active' => true,
        ]);

        $this->repB = User::create([
            'name' => 'מיכל כהן', 'email' => 'michal@kapaim.test', 'password' => 'password',
            'role_id' => $salesRep->id, 'is_active' => true,
        ]);
    }

    public function test_search_page_requires_auth(): void
    {
        $this->get('/search')->assertRedirect('/login');
    }

    public function test_search_page_renders_for_any_authenticated_user(): void
    {
        $response = $this->actingAs($this->repA)->get('/search');

        $response->assertOk();
        $response->assertSee('חיפוש גלובלי');
    }

    public function test_searching_by_school_name_finds_both_a_matching_lead_and_a_matching_customer(): void
    {
        $lead = $this->createLead($this->owner, schoolName: 'בית ספר יובלים ב׳ (סניף)');
        $customer = $this->createCustomer('בית ספר יובלים');

        Livewire::actingAs($this->owner)->test('global-search')
            ->set('query', 'יובלים')
            ->assertSee('בית ספר יובלים ב׳ (סניף)')
            ->assertSee('בית ספר יובלים')
            ->assertSee('ליד')
            ->assertSee('לקוחה')
            ->assertSee(route('lead-detail', $lead), false)
            ->assertSee(route('customer-detail', $customer), false);
    }

    public function test_searching_by_phone_matches_regardless_of_formatting(): void
    {
        $lead = $this->createLead($this->owner, schoolName: 'בית ספר טלפון');
        Contact::create([
            'school_id' => $lead->school_id, 'name' => 'מירב כהן', 'role' => 'רכזת',
            'phone' => '052-111-2223', 'is_primary' => true,
        ]);

        Livewire::actingAs($this->owner)->test('global-search')
            ->set('query', '0521112223') // stored with dashes, searched without
            ->assertSee('בית ספר טלפון')
            ->assertSee('מירב כהן');
    }

    public function test_searching_by_contact_name_finds_the_right_lead(): void
    {
        $lead = $this->createLead($this->owner, schoolName: 'בית ספר איש קשר');
        Contact::create([
            'school_id' => $lead->school_id, 'name' => 'דנה אורן', 'role' => 'מנהלת', 'is_primary' => true,
        ]);

        $otherLead = $this->createLead($this->owner, schoolName: 'בית ספר אחר לגמרי');

        Livewire::actingAs($this->owner)->test('global-search')
            ->set('query', 'דנה אורן')
            ->assertSee('בית ספר איש קשר')
            ->assertDontSee('בית ספר אחר לגמרי');
    }

    public function test_searching_by_contact_name_finds_the_right_customer(): void
    {
        $customer = $this->createCustomer('בית ספר לקוחה עם קשר', contactName: 'רונית שגיא');

        Livewire::actingAs($this->owner)->test('global-search')
            ->set('query', 'רונית שגיא')
            ->assertSee('בית ספר לקוחה עם קשר')
            ->assertSee('רונית שגיא')
            ->assertSee('לקוחה');
    }

    public function test_leads_view_only_user_only_gets_their_own_not_yet_converted_lead_never_a_customer(): void
    {
        // Matches term "יובלים": repA's own lead, repB's lead (not theirs),
        // and a customer — repA should only ever see their own lead.
        $ownLead = $this->createLead($this->repA, schoolName: 'בית ספר יובלים של שרית');
        $otherRepLead = $this->createLead($this->repB, schoolName: 'בית ספר יובלים של מיכל');
        $this->createCustomer('מכללת יובלים להכשרה');

        // Their own already-converted lead must also disappear (FR-7.4).
        $convertedOwnLead = $this->createLead($this->repA, schoolName: 'בית ספר יובלים שהומר');
        $convertedOwnLead->convertToCustomer(app(ActivityLogger::class));

        $component = Livewire::actingAs($this->repA)->test('global-search')->set('query', 'יובלים');

        $component->assertSee('בית ספר יובלים של שרית');
        $component->assertDontSee('בית ספר יובלים של מיכל');
        $component->assertDontSee('מכללת יובלים להכשרה');
        $component->assertDontSee('בית ספר יובלים שהומר');
        $component->assertDontSee('לקוחה');

        $this->assertNotSame($ownLead->id, $otherRepLead->id);
    }

    public function test_leads_manage_and_customers_manage_holder_gets_both_result_types(): void
    {
        $this->createLead($this->owner, schoolName: 'בית ספר יובלים משותף א');
        $this->createCustomer('בית ספר יובלים משותף ב');

        $component = Livewire::actingAs($this->owner)->test('global-search')->set('query', 'יובלים משותף');

        $component->assertSee('בית ספר יובלים משותף א');
        $component->assertSee('בית ספר יובלים משותף ב');
        $component->assertSee('ליד');
        $component->assertSee('לקוחה');
    }

    public function test_no_results_for_a_query_that_matches_nothing(): void
    {
        $this->createLead($this->owner, schoolName: 'בית ספר קיים');

        Livewire::actingAs($this->owner)->test('global-search')
            ->set('query', 'מחרוזת שלא קיימת בשום מקום')
            ->assertDontSee('בית ספר קיים')
            ->assertSee('לא נמצאו תוצאות');
    }

    public function test_empty_or_very_short_query_does_not_error_and_shows_no_results(): void
    {
        $this->createLead($this->owner, schoolName: 'בית ספר קיים גם כן');

        Livewire::actingAs($this->owner)->test('global-search')
            ->assertOk()
            ->assertDontSee('בית ספר קיים גם כן');

        Livewire::actingAs($this->owner)->test('global-search')
            ->set('query', 'א')
            ->assertOk()
            ->assertDontSee('בית ספר קיים גם כן');
    }

    private function createLead(User $assignedUser, ?string $schoolName = null): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => $schoolName ?? 'בית ספר '.random_int(1, 999999)]);

        return Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $assignedUser->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    private function createCustomer(string $schoolName, ?string $contactName = null): Customer
    {
        $lead = $this->createLead($this->owner, schoolName: $schoolName);

        Contact::create([
            'school_id' => $lead->school_id,
            'name' => $contactName ?? 'איש קשר '.random_int(1, 999999),
            'is_primary' => true,
        ]);

        return $lead->convertToCustomer(app(ActivityLogger::class));
    }
}
