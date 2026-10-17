<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LeadsManagementTest extends TestCase
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

    public function test_leads_list_requires_auth(): void
    {
        $this->get('/leads')->assertRedirect('/login');
    }

    public function test_lead_detail_requires_auth(): void
    {
        $lead = $this->createLead();

        $this->get("/leads/{$lead->id}")->assertRedirect('/login');
    }

    public function test_leads_page_is_blocked_without_the_leads_permission(): void
    {
        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get('/leads')->assertStatus(403);
    }

    public function test_owner_can_create_a_lead_with_only_email_and_phone(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'contact@example.com')
            ->set('newPhone', '050-1234567')
            ->call('addLead');

        $this->assertDatabaseHas('leads', [
            'email' => 'contact@example.com', 'phone' => '050-1234567', 'school_id' => null,
        ]);

        $lead = Lead::where('email', 'contact@example.com')->firstOrFail();
        $this->assertSame('חדש', $lead->status->name);
    }

    public function test_creating_a_lead_requires_email_and_phone(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', '')
            ->set('newPhone', '')
            ->call('addLead')
            ->assertHasErrors(['newEmail', 'newPhone']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_creating_a_lead_with_a_school_name_creates_the_school(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'school@example.com')
            ->set('newPhone', '050-1111111')
            ->set('newSchoolName', 'בית ספר הדוגמה')
            ->call('addLead');

        $this->assertDatabaseHas('schools', ['name' => 'בית ספר הדוגמה']);

        $school = School::where('name', 'בית ספר הדוגמה')->firstOrFail();
        $this->assertDatabaseHas('leads', ['email' => 'school@example.com', 'school_id' => $school->id]);
    }

    public function test_creating_a_lead_copies_its_email_and_phone_onto_the_new_school(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'school@example.com')
            ->set('newPhone', '050-1111111')
            ->set('newSchoolName', 'בית ספר הדוגמה')
            ->call('addLead');

        $this->assertDatabaseHas('schools', [
            'name' => 'בית ספר הדוגמה', 'email' => 'school@example.com', 'phone' => '050-1111111',
        ]);
    }

    public function test_creating_a_lead_keeps_an_explicit_school_phone(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'school@example.com')
            ->set('newPhone', '050-1111111')
            ->set('newSchoolName', 'בית ספר הדוגמה')
            ->set('newSchoolPhone', '03-5555555')
            ->call('addLead');

        $this->assertDatabaseHas('schools', ['name' => 'בית ספר הדוגמה', 'phone' => '03-5555555', 'email' => 'school@example.com']);
    }

    public function test_lead_card_shows_the_lead_email_and_phone_when_it_has_no_school(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'noschool@example.com')
            ->set('newPhone', '050-2222222')
            ->call('addLead');

        $lead = Lead::where('email', 'noschool@example.com')->firstOrFail();

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->assertSee('noschool@example.com')
            ->assertSee('050-2222222')
            ->assertSet('schoolEmail', 'noschool@example.com')
            ->assertSet('schoolPhone', '050-2222222');
    }

    /**
     * FR-1.9: an exact-match repeat inquiry from the same school must update
     * the existing lead, not create a new one.
     */
    public function test_repeat_inquiry_from_the_same_school_reuses_the_existing_lead_instead_of_creating_a_new_one(): void
    {
        $existingLead = $this->createLead(schoolName: 'בית ספר יובלים');

        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'other-contact@example.com')
            ->set('newPhone', '050-9999999')
            ->set('newSchoolName', '  בית ספר יובלים  ') // same name, different casing/whitespace
            ->call('addLead')
            ->assertSet('leadError', fn ($message) => ! empty($message));

        $this->assertSame(1, Lead::count());
        $this->assertDatabaseMissing('leads', ['email' => 'other-contact@example.com']);
        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'lead.repeat_inquiry', 'lead_id' => $existingLead->id,
        ]);
    }

    /**
     * FR-1.9: a fuzzy-matching school name warns but does not block creation.
     */
    public function test_a_similarly_named_school_shows_a_non_blocking_duplicate_warning(): void
    {
        $this->createLead(schoolName: 'בית ספר יובלים');

        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'similar@example.com')
            ->set('newPhone', '050-8888888')
            ->set('newSchoolName', 'בית ספר יובלימ') // one-character typo
            ->call('addLead')
            ->assertSet('duplicateWarning', fn ($message) => ! empty($message));

        // Non-blocking: the new lead (and its own school row) is still created.
        $this->assertDatabaseHas('leads', ['email' => 'similar@example.com']);
        $this->assertSame(2, School::count());
    }

    public function test_changing_lead_status_is_written_to_the_activity_log(): void
    {
        $lead = $this->createLead();
        $closedStatus = StatusDefinition::create(['scope' => 'lead', 'name' => 'נסגר ללא מכירה', 'is_active' => true, 'sort_order' => 4]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('selectedStatusId', (string) $closedStatus->id)
            ->call('updateStatus');

        $this->assertSame($closedStatus->id, $lead->fresh()->status_id);
        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'lead.status_changed', 'lead_id' => $lead->id, 'user_id' => $this->owner->id,
        ]);
    }

    /**
     * FR-1.6: the yellow-only sub-status must not survive a change away from
     * the yellow traffic-light color.
     */
    public function test_sub_status_is_cleared_when_status_changes_away_from_yellow(): void
    {
        $lead = $this->createLead();
        $lead->update(['sub_status' => 'ממתינה לשיחה חוזרת']);
        $greenStatus = StatusDefinition::create(['scope' => 'lead', 'name' => 'מוכנה לסגור / רוצה לרכוש', 'is_active' => true, 'sort_order' => 3]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('selectedStatusId', (string) $greenStatus->id)
            ->set('selectedSubStatus', 'ממתינה לשיחה חוזרת')
            ->call('updateStatus');

        $this->assertNull($lead->fresh()->sub_status);
    }

    public function test_owner_can_attach_a_school_to_a_leadless_school_and_edit_it(): void
    {
        $lead = $this->createLead(); // no school yet

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('editingSchool', true)
            ->set('schoolName', 'בית ספר חדש')
            ->set('schoolCity', 'חיפה')
            ->call('saveSchool');

        $lead->refresh();
        $this->assertNotNull($lead->school_id);
        $this->assertDatabaseHas('schools', ['name' => 'בית ספר חדש', 'city' => 'חיפה']);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'lead.school_updated', 'lead_id' => $lead->id]);
    }

    public function test_saving_school_details_for_an_existing_school_reuses_an_exact_duplicate_match(): void
    {
        $existingLead = $this->createLead(schoolName: 'בית ספר קיים');
        $newLead = $this->createLead(); // no school yet

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $newLead])
            ->set('editingSchool', true)
            ->set('schoolName', 'בית ספר קיים')
            ->call('saveSchool');

        $this->assertSame($existingLead->school_id, $newLead->fresh()->school_id);
        $this->assertSame(1, School::count());
    }

    public function test_owner_can_save_lead_source_and_notes(): void
    {
        $lead = $this->createLead();
        $source = LeadSource::create(['name' => 'הפניה', 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('leadSourceId', (string) $source->id)
            ->set('leadNotes', 'הערה לדוגמה')
            ->call('saveLeadDetails');

        $lead->refresh();
        $this->assertSame($source->id, $lead->lead_source_id);
        $this->assertSame('הערה לדוגמה', $lead->notes);
    }

    public function test_owner_can_edit_an_existing_contact(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר לעריכה');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'שם ישן', 'phone' => '050-0000000']);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('editContact', $contact->id)
            ->assertSet('contactName', 'שם ישן')
            ->set('contactName', 'שם חדש')
            ->set('contactPhone', '050-1111111')
            ->call('updateContact');

        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'name' => 'שם חדש', 'phone' => '050-1111111']);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'contact.updated']);
    }

    public function test_lead_never_gets_a_delete_route(): void
    {
        $routeUris = collect(app('router')->getRoutes())->map(fn ($route) => strtolower($route->uri()))->values();

        foreach (['leads', 'schools', 'contacts', 'tasks'] as $fragment) {
            $this->assertFalse(
                $routeUris->contains(fn ($uri) => str_contains($uri, $fragment) && str_contains($uri, 'delete')),
                "Unexpected delete route matching '{$fragment}'."
            );
        }
    }

    public function test_owner_can_add_a_contact_to_a_lead_school(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר הדקל');

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('contactName', 'אורית שני')
            ->set('contactRole', 'רכזת')
            ->set('contactPhone', '050-1112223')
            ->set('contactIsPrimary', true)
            ->call('addContact');

        $this->assertDatabaseHas('contacts', [
            'school_id' => $lead->school_id, 'name' => 'אורית שני', 'is_primary' => true,
        ]);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'contact.created']);
    }

    public function test_multiple_contacts_can_be_marked_primary_for_the_same_school(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר הדקל');

        $component = Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead]);

        $component->set('contactName', 'איש קשר א')->set('contactIsPrimary', true)->call('addContact');
        $component->set('contactName', 'איש קשר ב')->set('contactIsPrimary', true)->call('addContact');

        $this->assertSame(2, Contact::where('school_id', $lead->school_id)->where('is_primary', true)->count());
    }

    /**
     * Removing a contact is a logical delete (deleted_at), never a real one,
     * and is logged (build-plan 04).
     */
    public function test_removing_a_contact_only_soft_deletes_it_and_logs_the_removal(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר הדקל');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'איש קשר להסרה']);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('removeContact', $contact->id);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id]); // row still exists
        $this->assertNotNull($contact->fresh()->deleted_at);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'contact.removed']);
    }

    /**
     * FR-8.22: a contact removed from a lead's school can be restored via
     * the "פריטים שהוסרו לאחרונה" panel, reappears in the normal contact
     * list, and the restoration is logged.
     */
    public function test_a_removed_lead_contact_can_be_restored(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר הדקל');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'איש קשר להסרה']);

        $component = Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead]);
        $component->call('removeContact', $contact->id);

        $this->assertTrue($component->instance()->recentlyRemovedContacts->contains('id', $contact->id));

        $component->call('restoreContact', $contact->id);

        $this->assertNull($contact->fresh()->deleted_at);
        $this->assertTrue($component->instance()->contacts->contains('id', $contact->id));
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'contact.restored']);
    }

    public function test_owner_can_log_an_interaction_and_a_follow_up(): void
    {
        $lead = $this->createLead();

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('interactionType', 'שיחת טלפון')
            ->set('interactionSummary', 'שוחחנו על התוכנית')
            ->call('addInteraction');

        $this->assertDatabaseHas('lead_interactions', ['lead_id' => $lead->id, 'interaction_type' => 'שיחת טלפון']);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('followUpSummary', 'לחזור בעוד שבוע')
            ->set('followUpNextAt', now()->addWeek()->toDateString())
            ->call('addFollowUp');

        $this->assertDatabaseHas('follow_ups', ['lead_id' => $lead->id, 'summary' => 'לחזור בעוד שבוע']);
    }

    public function test_owner_can_create_and_cancel_a_personal_task(): void
    {
        $lead = $this->createLead();

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('taskTitle', 'להתקשר לבירור')
            ->call('addTask');

        $task = Task::where('title', 'להתקשר לבירור')->firstOrFail();
        $this->assertSame($lead->id, $task->lead_id);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('cancelTask', $task->id);

        $this->assertDatabaseHas('tasks', ['id' => $task->id]); // logical delete only
        $this->assertNotNull($task->fresh()->deleted_at);
    }

    /** FR-8.22: a cancelled personal task can be restored via the recovery panel. */
    public function test_a_cancelled_task_can_be_restored(): void
    {
        $lead = $this->createLead();

        $component = Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead]);
        $component->set('taskTitle', 'להתקשר לבירור')->call('addTask');
        $task = Task::where('title', 'להתקשר לבירור')->firstOrFail();

        $component->call('cancelTask', $task->id);
        $this->assertTrue($component->instance()->recentlyRemovedTasks->contains('id', $task->id));

        $component->call('restoreTask', $task->id);

        $this->assertNull($task->fresh()->deleted_at);
        $this->assertTrue($component->instance()->tasks->contains('id', $task->id));
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'task.restored', 'task_id' => $task->id]);
    }

    /** FR-8.22: the recovery window is roughly 30 days — older removals stay hidden. */
    public function test_the_recovery_panel_excludes_items_removed_more_than_thirty_days_ago(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר עם הסרה ישנה');
        $contact = Contact::create(['school_id' => $lead->school_id, 'name' => 'הסרה ישנה']);
        $contact->delete();
        $contact->forceFill(['deleted_at' => now()->subDays(45)])->saveQuietly();

        $component = Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead]);

        $this->assertFalse($component->instance()->recentlyRemovedContacts->contains('id', $contact->id));
    }

    public function test_owner_can_attach_and_remove_a_program_of_interest(): void
    {
        $lead = $this->createLead();
        $program = Program::create(['name' => 'תוכנית לדוגמה', 'price' => 100, 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('itemToAttach', "program:{$program->id}")
            ->call('attachItem');

        $this->assertTrue($lead->fresh()->interestedPrograms->contains($program->id));

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('removeProgram', $program->id);

        $this->assertFalse($lead->fresh()->interestedPrograms->contains($program->id));
    }

    public function test_owner_can_attach_and_remove_a_bundle_of_interest(): void
    {
        $lead = $this->createLead();
        $bundle = \App\Models\Bundle::create(['name' => 'מארז לדוגמה', 'price' => 900, 'is_subscription_type' => false, 'is_active' => true]);
        \App\Models\Bundle::create(['name' => 'מארז מושבת', 'price' => 900, 'is_subscription_type' => false, 'is_active' => false]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->assertSee('מארז לדוגמה')
            ->assertDontSee('מארז מושבת')
            ->set('itemToAttach', "bundle:{$bundle->id}")
            ->call('attachItem')
            ->assertHasNoErrors()
            ->assertSee('מארז: מארז לדוגמה');

        $this->assertTrue($lead->fresh()->interestedBundles->contains($bundle->id));
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'lead.bundle_interest_added', 'lead_id' => $lead->id]);

        $this->actingAs($this->owner)->get('/leads')->assertSee('מארז: מארז לדוגמה');

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('removeBundle', $bundle->id);

        $this->assertFalse($lead->fresh()->interestedBundles->contains($bundle->id));
    }

    public function test_leads_list_frames_only_leads_interested_in_a_subscription_bundle(): void
    {
        $subscriber = $this->createLead(schoolName: 'בית ספר מתעניין במנוי');
        $regular = $this->createLead(schoolName: 'בית ספר רגיל');
        $subscriptionBundle = \App\Models\Bundle::create(['name' => 'מנוי שנתי', 'price' => 4500, 'is_subscription_type' => true, 'is_active' => true]);
        $plainBundle = \App\Models\Bundle::create(['name' => 'מארז רגיל', 'price' => 900, 'is_subscription_type' => false, 'is_active' => true]);
        $subscriber->interestedBundles()->attach($subscriptionBundle->id);
        $regular->interestedBundles()->attach($plainBundle->id);

        $html = Livewire::actingAs($this->owner)->test('leads')->html();

        $this->assertMatchesRegularExpression('/<tr class="row-link is-subscription"[^>]*leads\/'.$subscriber->id.'\b/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<tr class="row-link is-subscription"[^>]*leads\/'.$regular->id.'\b/u', $html);
    }

    public function test_leads_list_filters_by_requested_program_or_bundle(): void
    {
        $programLead = $this->createLead(schoolName: 'מתעניין בתוכנית');
        $bundleLead = $this->createLead(schoolName: 'מתעניין במארז');
        $otherLead = $this->createLead(schoolName: 'ללא בקשה');
        $program = Program::create(['name' => 'תוכנית מבוקשת', 'price' => 500, 'is_premium' => false, 'is_active' => true]);
        $bundle = \App\Models\Bundle::create(['name' => 'מארז מבוקש', 'price' => 900, 'is_subscription_type' => false, 'is_active' => true]);
        $programLead->interestedPrograms()->attach($program->id);
        $bundleLead->interestedBundles()->attach($bundle->id);

        // Rows link via onclick to the lead-detail URL; school names also
        // appear in the school filter dropdown, so match on the row link.
        $row = fn (Lead $lead) => "window.location='".route('lead-detail', $lead)."'";

        Livewire::actingAs($this->owner)->test('leads')
            ->set('filterItem', "program:{$program->id}")
            ->assertSeeHtml($row($programLead))
            ->assertDontSeeHtml($row($bundleLead))
            ->assertDontSeeHtml($row($otherLead))
            ->set('filterItem', "bundle:{$bundle->id}")
            ->assertSeeHtml($row($bundleLead))
            ->assertDontSeeHtml($row($programLead))
            ->assertDontSeeHtml($row($otherLead))
            ->set('filterItem', '')
            ->assertSeeHtml($row($otherLead));
    }

    public function test_filtering_leads_by_a_program_includes_leads_interested_in_a_bundle_that_holds_it(): void
    {
        $subscriberLead = $this->createLead(schoolName: 'מתעניין במנוי');
        $otherLead = $this->createLead(schoolName: 'מתעניין במארז אחר');
        $program = Program::create(['name' => 'תשרי בתוך מנוי', 'price' => 500, 'is_premium' => false, 'is_active' => true]);
        $subscription = \App\Models\Bundle::create(['name' => 'מנוי שנתי', 'price' => 4500, 'is_subscription_type' => true, 'is_active' => true]);
        $subscription->programs()->attach($program->id);
        $otherBundle = \App\Models\Bundle::create(['name' => 'מארז אחר', 'price' => 900, 'is_subscription_type' => false, 'is_active' => true]);
        $subscriberLead->interestedBundles()->attach($subscription->id);
        $otherLead->interestedBundles()->attach($otherBundle->id);

        $row = fn (Lead $lead) => "window.location='".route('lead-detail', $lead)."'";

        Livewire::actingAs($this->owner)->test('leads')
            ->set('filterItem', "program:{$program->id}")
            ->assertSeeHtml($row($subscriberLead))
            ->assertDontSeeHtml($row($otherLead));
    }

    public function test_attaching_an_invalid_item_is_rejected(): void
    {
        $lead = $this->createLead();

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('itemToAttach', 'bundle:999999')
            ->call('attachItem')
            ->assertHasErrors(['itemToAttach']);
    }

    public function test_leads_list_page_renders_real_seeded_data(): void
    {
        $this->createLead(schoolName: 'בית ספר מוצג');

        $response = $this->actingAs($this->owner)->get('/leads');

        $response->assertOk();
        $response->assertSee('בית ספר מוצג');
    }

    public function test_lead_detail_page_renders(): void
    {
        $lead = $this->createLead(schoolName: 'בית ספר מוצג בכרטיס');

        $response = $this->actingAs($this->owner)->get("/leads/{$lead->id}");

        $response->assertOk();
        $response->assertSee('בית ספר מוצג בכרטיס');
    }

    // ----- edit / delete of interactions, Up Follows, reminders -----

    public function test_an_interaction_can_be_edited_and_logically_deleted(): void
    {
        $lead = $this->createLead();
        $interaction = $lead->interactions()->create(['user_id' => $this->owner->id, 'interaction_type' => 'שיחה', 'summary' => 'ישן', 'occurred_at' => now()]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('editTimelineItem', 'interaction', $interaction->id)
            ->assertSet('editInteractionType', 'שיחה')
            ->set('editInteractionType', 'פגישת היכרות בבית הספר')
            ->set('editSummary', 'חדש')
            ->call('updateTimelineItem')
            ->assertHasNoErrors()
            ->assertSee('פגישת היכרות בבית הספר')
            ->call('deleteTimelineItem', 'interaction', $interaction->id)
            ->assertDontSee('פגישת היכרות בבית הספר');

        $this->assertSoftDeleted('lead_interactions', ['id' => $interaction->id, 'summary' => 'חדש']);
        $this->assertDatabaseHas('activity_logs', ['activity_type' => 'lead.interaction_deleted', 'lead_id' => $lead->id]);
    }

    public function test_a_follow_up_can_be_edited_and_logically_deleted(): void
    {
        $lead = $this->createLead();
        $followUp = $lead->followUps()->create(['user_id' => $this->owner->id, 'summary' => 'ישן', 'occurred_at' => now()]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('editTimelineItem', 'follow_up', $followUp->id)
            ->set('editNextAt', '2030-01-15')
            ->call('updateTimelineItem')
            ->assertHasNoErrors();

        $this->assertSame('2030-01-15', $followUp->fresh()->next_at->toDateString());

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('deleteTimelineItem', 'follow_up', $followUp->id);

        $this->assertSoftDeleted('follow_ups', ['id' => $followUp->id]);
    }

    public function test_a_reminder_can_be_edited(): void
    {
        $lead = $this->createLead();
        $task = Task::create(['user_id' => $this->owner->id, 'lead_id' => $lead->id, 'task_type' => 'reminder', 'status' => 'open', 'title' => 'ישן']);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('editTask', $task->id)
            ->set('editTaskTitle', 'להתקשר שוב')
            ->set('editTaskDueAt', '2030-02-01')
            ->call('updateTask')
            ->assertHasNoErrors()
            ->assertSee('להתקשר שוב');

        $this->assertSame('2030-02-01', $task->fresh()->due_at->toDateString());
    }

    public function test_a_lead_page_only_touches_its_own_schools_contacts(): void
    {
        $lead = $this->createLead('בית ספר א');
        $other = $this->createLead('בית ספר ב');
        $foreign = \App\Models\Contact::create(['school_id' => $other->school_id, 'name' => 'איש קשר זר', 'is_primary' => true]);

        foreach (['editContact', 'removeContact'] as $action) {
            try {
                Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])->call($action, $foreign->id);
                $this->fail("{$action} reached another school's contact.");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            }
        }

        $this->assertNotSoftDeleted('contacts', ['id' => $foreign->id]);
        $this->assertSame('איש קשר זר', $foreign->fresh()->name);
    }

    public function test_a_lead_only_takes_a_lead_status(): void
    {
        $lead = $this->createLead();
        $dealStatus = StatusDefinition::create(['scope' => 'deal', 'name' => 'פתוחה', 'is_active' => true, 'sort_order' => 1]);

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->set('selectedStatusId', (string) $dealStatus->id)
            ->call('updateStatus')
            ->assertHasErrors('selectedStatusId');

        $this->assertNotSame($dealStatus->id, $lead->fresh()->status_id);
    }

    public function test_a_phone_number_needs_digits(): void
    {
        Livewire::actingAs($this->owner)->test('leads')
            ->set('newEmail', 'a@example.com')
            ->set('newPhone', 'abcdef')
            ->call('addLead')
            ->assertHasErrors('newPhone')
            ->set('newPhone', '050-123 4567')
            ->call('addLead')
            ->assertHasNoErrors();

        $this->assertSame(1, Lead::count());
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
