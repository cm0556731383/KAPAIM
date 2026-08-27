<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build-plan 15 — light feature coverage for ⚡home.blade.php (US-019): the
 * page renders and requires auth, and its permission-based visibility
 * mirrors App\Services\DashboardSummary's scoping (see DashboardSummaryTest
 * for the exhaustive per-method coverage of that logic).
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $repA;

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
    }

    public function test_home_page_requires_auth(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_home_page_renders_for_an_authenticated_user(): void
    {
        $response = $this->actingAs($this->owner)->get('/');

        $response->assertOk();
        $response->assertSee('משימות ופריטי טיפול');
    }

    public function test_full_access_user_sees_both_lead_and_financial_kpis(): void
    {
        $this->createLead($this->owner, 'בית ספר לדשבורד');

        $response = $this->actingAs($this->owner)->get('/');

        $response->assertOk();
        $response->assertSee('לידים חדשים');
        $response->assertSee('סך פתוח לגבייה');
        $response->assertSee('להפקדה');
    }

    /**
     * FR-7.3, the important assertion for this screen: a leads.view-only
     * user must never see the financial/collections KPI cards at all, even
     * though genuinely-open collection data exists in the system.
     */
    public function test_leads_view_only_user_never_sees_financial_kpis(): void
    {
        $this->createLead($this->repA, 'ליד של שרית בדשבורד');

        $deal = $this->createDealWithOpenCollectionTask();

        $response = $this->actingAs($this->repA)->get('/');

        $response->assertOk();
        $response->assertSee('לידים חדשים');
        $response->assertDontSee('סך פתוח לגבייה');
        $response->assertDontSee('להפקדה');
        $response->assertDontSee("עסקה #{$deal->id}");
    }

    // ----- helpers -----

    private function createLead(User $assignedUser, string $schoolName): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => $schoolName]);

        return Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $assignedUser->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    private function createDealWithOpenCollectionTask(): Deal
    {
        $lead = $this->createLead($this->owner, 'בית ספר עם גבייה פתוחה');
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));
        $program = Program::create([
            'name' => 'תוכנית לבדיקת דשבורד '.random_int(1, 999999),
            'price' => 500, 'is_premium' => false, 'is_subscription_type' => false, 'is_active' => true,
        ]);
        $deal = Deal::createForCustomer($customer, $program, null, 500);

        Task::create([
            'user_id' => $this->owner->id, 'deal_id' => $deal->id, 'customer_id' => $deal->customer_id,
            'task_type' => 'collection', 'status' => 'open', 'title' => 'משימת גבייה', 'due_at' => now()->subDay(),
        ]);

        return $deal;
    }
}
