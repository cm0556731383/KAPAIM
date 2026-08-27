<?php

namespace Tests\Feature;

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
 * Build-plan 13 (FR-7.2–FR-7.5): the "עובדת מכירות" role isn't activated for
 * MVP, but its record-level LeadPolicy mechanism must already be correct —
 * these tests create such a user directly to prove it, mirroring the
 * permission tests in CoreAuthRolesActivityLogTest/LeadsManagementTest.
 */
class SalesRepPermissionsTest extends TestCase
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

    public function test_leads_view_only_user_sees_only_their_own_assigned_leads(): void
    {
        $leadA = $this->createLead($this->repA, schoolName: 'בית ספר של שרית');
        $leadB = $this->createLead($this->repB, schoolName: 'בית ספר של מיכל');

        $response = $this->actingAs($this->repA)->get('/leads');
        $response->assertOk();
        $response->assertSee('בית ספר של שרית');
        $response->assertDontSee('בית ספר של מיכל');

        $response = $this->actingAs($this->repB)->get('/leads');
        $response->assertOk();
        $response->assertSee('בית ספר של מיכל');
        $response->assertDontSee('בית ספר של שרית');

        // Sanity: both leads really exist, just scoped differently per user.
        $this->assertSame(2, Lead::count());
        $this->assertNotSame($leadA->id, $leadB->id);
    }

    public function test_leads_view_only_user_cannot_view_another_reps_lead_detail(): void
    {
        $leadB = $this->createLead($this->repB);

        $this->actingAs($this->repA)->get("/leads/{$leadB->id}")->assertStatus(403);
    }

    public function test_leads_view_only_user_can_view_their_own_not_yet_converted_lead(): void
    {
        $leadA = $this->createLead($this->repA, schoolName: 'בית ספר שרית');

        $response = $this->actingAs($this->repA)->get("/leads/{$leadA->id}");

        $response->assertOk();
        $response->assertSee('בית ספר שרית');
    }

    /**
     * FR-7.4, the critical case: conversion revokes lead-detail access too
     * (not just customer access) — automatically, as a direct consequence of
     * LeadPolicy::view() re-checking converted_at, no separate process.
     */
    public function test_converting_a_reps_lead_revokes_their_access_to_the_lead_detail_page_too(): void
    {
        $lead = $this->createLead($this->repA, schoolName: 'בית ספר להמרה');

        // Access works before conversion.
        $this->actingAs($this->repA)->get("/leads/{$lead->id}")->assertOk();

        $lead->convertToCustomer(app(ActivityLogger::class));

        $this->actingAs($this->repA)->get("/leads/{$lead->id}")->assertStatus(403);
    }

    public function test_leads_view_only_user_is_blocked_from_customers_screens(): void
    {
        $lead = $this->createLead($this->repA);
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $this->actingAs($this->repA)->get('/customers')->assertStatus(403);
        $this->actingAs($this->repA)->get("/customers/{$customer->id}")->assertStatus(403);
    }

    public function test_leads_manage_holder_sees_all_leads_including_other_reps_and_converted_ones_unfiltered(): void
    {
        $leadA = $this->createLead($this->repA, schoolName: 'בית ספר שרית');
        $leadB = $this->createLead($this->repB, schoolName: 'בית ספר מיכל');
        $leadB->convertToCustomer(app(ActivityLogger::class));

        $response = $this->actingAs($this->owner)->get('/leads');
        $response->assertOk();
        $response->assertSee('בית ספר שרית');
        $response->assertSee('בית ספר מיכל');

        // Direct detail access to both, including the already-converted one.
        $this->actingAs($this->owner)->get("/leads/{$leadA->id}")->assertOk();
        $this->actingAs($this->owner)->get("/leads/{$leadB->id}")->assertOk();
    }

    public function test_leads_manage_holder_can_reassign_a_lead_and_it_is_logged(): void
    {
        $lead = $this->createLead($this->repA);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('assignedUserId', (string) $this->repB->id)
            ->call('assignUser');

        $this->assertSame($this->repB->id, $lead->fresh()->assigned_user_id);
        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'lead.assigned', 'lead_id' => $lead->id, 'user_id' => $this->owner->id,
        ]);
    }

    public function test_leads_view_only_user_cannot_call_the_reassignment_action(): void
    {
        $lead = $this->createLead($this->repA);

        Livewire::actingAs($this->repA)->test('lead-detail', ['lead' => $lead])
            ->set('assignedUserId', (string) $this->repB->id)
            ->call('assignUser')
            ->assertStatus(403);

        $this->assertSame($this->repA->id, $lead->fresh()->assigned_user_id);
    }

    private function createLead(User $assignedUser, ?string $schoolName = null): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = $schoolName ? School::create(['name' => $schoolName]) : School::create(['name' => 'בית ספר '.random_int(1, 999999)]);

        return Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $assignedUser->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }
}
