<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\ExternalIntegrationSetting;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** tasks:send-reminders — the morning email of each user's reminders for the day. */
class TaskRemindersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $salesRep;

    /** Smove answers normally, or fails every call. */
    private bool $smoveUp = true;

    protected function setUp(): void
    {
        parent::setUp();

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);
        $sales = Role::create(['name' => Role::SALES_REP_ROLE_NAME, 'is_active' => true]);
        $sales->permissions()->create(['resource' => 'leads', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create(['name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password', 'role_id' => $fullAccess->id, 'is_active' => true]);
        $this->salesRep = User::create(['name' => 'נועה', 'email' => 'noa@kapaim.test', 'password' => 'password', 'role_id' => $sales->id, 'is_active' => true]);

        ExternalIntegrationSetting::updateOrCreate(['system' => 'smove'], ['is_active' => true, 'settings' => ['api_key' => 'x']]);
        Http::fake(fn ($request) => $this->smoveUp
            ? Http::response($request->method() === 'GET' ? [] : ['id' => 42], 200)
            : Http::response(['message' => 'down'], 500));
    }

    public function test_a_lead_reminder_due_today_is_emailed_once_to_the_user_who_created_it(): void
    {
        $lead = $this->createLead();
        $today = $this->reminder($this->salesRep, $lead, today(), 'להתקשר למנהלת');
        $tomorrow = $this->reminder($this->salesRep, $lead, today()->addDay(), 'מחר');

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertNotNull($today->fresh()->reminder_sent_at);
        $this->assertNull($tomorrow->fresh()->reminder_sent_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns')
            && $request['subject'] === 'תזכורת להיום: להתקשר למנהלת'
            && str_contains($request['body'], e(route('lead-detail', $lead))));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Contacts') && $request->method() === 'POST' && $request['email'] === 'noa@kapaim.test');

        $this->artisan('tasks:send-reminders');
        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/Campaigns')));
    }

    public function test_one_email_per_user_lists_all_her_reminders(): void
    {
        $lead = $this->createLead();
        $this->reminder($this->owner, $lead, today(), 'ראשונה');
        $this->reminder($this->owner, $lead, today()->setTime(14, 0), 'שנייה');

        $this->artisan('tasks:send-reminders');

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/Campaigns')));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns')
            && $request['subject'] === 'התזכורות שלך להיום (2)'
            && str_contains($request['body'], 'ראשונה') && str_contains($request['body'], 'שנייה'));
    }

    public function test_done_cancelled_and_undated_reminders_are_not_emailed(): void
    {
        $lead = $this->createLead();
        $this->reminder($this->owner, $lead, today(), 'הושלמה')->update(['status' => 'done']);
        $this->reminder($this->owner, $lead, today(), 'בוטלה')->delete();
        $this->reminder($this->owner, $lead, null, 'ללא תאריך');

        $this->artisan('tasks:send-reminders');

        Http::assertNothingSent();
    }

    public function test_a_reminder_of_an_inactive_user_goes_to_the_business_owner(): void
    {
        $this->salesRep->update(['is_active' => false]);
        $this->reminder($this->salesRep, $this->createLead(), today(), 'של עובדת שעזבה');

        $this->artisan('tasks:send-reminders');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/Contacts') && $request->method() === 'POST' && $request['email'] === 'owner@kapaim.test');
    }

    public function test_a_new_collection_task_is_emailed_to_the_business_owner_when_its_user_has_no_payments_access(): void
    {
        $deal = $this->createDeal();
        $task = Task::create([
            'user_id' => $this->salesRep->id, 'customer_id' => $deal->customer_id, 'deal_id' => $deal->id,
            'task_type' => 'collection', 'status' => 'open', 'title' => 'משימת גבייה — בדיקה', 'due_at' => now()->addDays(7),
        ]);

        $this->artisan('tasks:send-reminders');

        $this->assertNotNull($task->fresh()->reminder_sent_at);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Contacts') && $request->method() === 'POST' && $request['email'] === 'owner@kapaim.test');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Campaigns')
            && str_contains($request['body'], e(route('deal-detail', $deal))));
    }

    public function test_a_failed_send_is_retried_on_the_next_run(): void
    {
        $this->smoveUp = false;
        $task = $this->reminder($this->owner, $this->createLead(), today(), 'נסיון');

        $this->artisan('tasks:send-reminders')->assertSuccessful();

        $this->assertNull($task->fresh()->reminder_sent_at);
    }

    private function reminder(User $user, Lead $lead, $dueAt, string $title): Task
    {
        return Task::create([
            'user_id' => $user->id, 'lead_id' => $lead->id, 'task_type' => 'reminder',
            'status' => 'open', 'title' => $title, 'due_at' => $dueAt,
        ]);
    }

    private function createLead(): Lead
    {
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);

        return Lead::create([
            'school_id' => School::create(['name' => 'בית ספר '.random_int(1, 999999)])->id,
            'assigned_user_id' => $this->salesRep->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    private function createDeal(): Deal
    {
        $customer = $this->createLead()->convertToCustomer(app(ActivityLogger::class));
        $program = Program::create(['name' => 'תוכנית '.random_int(1, 999999), 'price' => 400, 'is_premium' => false, 'is_subscription_type' => false, 'is_active' => true]);
        return Deal::createForCustomer($customer, $program, null, 400);
    }
}
