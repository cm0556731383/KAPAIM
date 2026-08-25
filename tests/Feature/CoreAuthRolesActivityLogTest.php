<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class CoreAuthRolesActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private Role $fullAccess;
    private User $owner;
    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $this->fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $this->fullAccess->id, 'is_active' => true,
        ]);

        $this->secretary = User::create([
            'name' => 'מזכירה', 'email' => 'secretary@kapaim.test', 'password' => 'password',
            'role_id' => $this->fullAccess->id, 'is_active' => true,
        ]);
    }

    public function test_home_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_both_seeded_style_users_can_log_in(): void
    {
        foreach ([$this->owner, $this->secretary] as $user) {
            Livewire::test('login')
                ->set('email', $user->email)
                ->set('password', 'password')
                ->call('login')
                ->assertRedirect('/');

            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    public function test_wrong_password_is_rejected(): void
    {
        Livewire::test('login')
            ->set('email', $this->owner->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertSet('errorMessage', fn ($message) => ! empty($message));

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->owner->update(['is_active' => false]);

        Livewire::test('login')
            ->set('email', $this->owner->email)
            ->set('password', 'password')
            ->call('login')
            ->assertSet('errorMessage', fn ($message) => ! empty($message));

        $this->assertGuest();
    }

    public function test_activity_logger_creates_a_real_row_and_tracks_manual_vs_system(): void
    {
        $this->actingAs($this->owner);

        $manual = app(ActivityLogger::class)->log('user.created', 'נוצרה משתמשת חדשה');
        $this->assertDatabaseHas('activity_logs', ['id' => $manual->id, 'user_id' => $this->owner->id]);
        $this->assertTrue($manual->isManual());

        $system = app(ActivityLogger::class)->log('integration.sync', 'סנכרון אוטומטי מול Smove', ['user' => null]);
        $this->assertDatabaseHas('activity_logs', ['id' => $system->id, 'user_id' => null]);
        $this->assertFalse($system->isManual());
    }

    public function test_activity_log_entries_cannot_be_updated_or_deleted(): void
    {
        $entry = app(ActivityLogger::class)->log('user.created', 'נוצרה משתמשת חדשה', ['user' => $this->owner]);

        $this->expectException(RuntimeException::class);
        $entry->update(['description' => 'ניסיון לשנות רשומה']);
    }

    public function test_activity_log_entries_cannot_be_deleted(): void
    {
        $entry = app(ActivityLogger::class)->log('user.created', 'נוצרה משתמשת חדשה', ['user' => $this->owner]);

        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    public function test_permission_mechanism_is_data_driven_not_hardcoded(): void
    {
        // Full-access role: wildcard row allows anything.
        $this->assertTrue($this->owner->hasPermission('leads', 'edit'));
        $this->assertDatabaseCount('role_permissions', 1);

        // A role with zero rows must deny by default — proves the check
        // actually reads role_permissions instead of always returning true.
        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->assertFalse($limitedUser->hasPermission('customers', 'view'));

        // Grant one specific resource+action — only that exact permission opens up.
        $limited->permissions()->create(['resource' => 'leads', 'action' => 'view', 'is_allowed' => true]);
        $this->assertTrue($limitedUser->hasPermission('leads', 'view'));
        $this->assertFalse($limitedUser->hasPermission('leads', 'edit'));
        $this->assertFalse($limitedUser->hasPermission('customers', 'view'));
    }

    public function test_users_roles_page_renders_real_seeded_users(): void
    {
        $response = $this->actingAs($this->owner)->get('/users-roles');

        $response->assertOk();
        $response->assertSee($this->owner->name);
        $response->assertSee($this->secretary->name);
        $response->assertSee($this->fullAccess->name);
    }

    public function test_activity_log_page_renders_a_real_logged_entry(): void
    {
        app(ActivityLogger::class)->log('user.created', 'תיאור ייחודי לבדיקה — נוצרה משתמשת חדשה', ['user' => $this->owner]);

        $response = $this->actingAs($this->owner)->get('/activity-log');

        $response->assertOk();
        $response->assertSee('תיאור ייחודי לבדיקה');
        $response->assertSee($this->owner->name);
    }
}
