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

    public function test_a_user_can_be_edited_deactivated_and_have_the_password_reset(): void
    {
        Livewire::actingAs($this->owner)->test('users-roles')
            ->call('editUser', $this->secretary->id)
            ->set('editName', 'נועה כהן')
            ->set('editIsActive', false)
            ->call('updateUser')
            ->assertHasNoErrors()
            ->call('resetPassword', $this->secretary->id)
            ->assertSet('generatedPasswordForName', 'נועה כהן');

        $secretary = $this->secretary->fresh();
        $this->assertSame('נועה כהן', $secretary->name);
        $this->assertFalse($secretary->is_active);
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('password', $secretary->password));
    }

    public function test_deactivating_a_user_or_resetting_her_password_ends_her_open_sessions(): void
    {
        $insertSession = fn (string $id, User $user) => \Illuminate\Support\Facades\DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
        $insertSession('secretary-1', $this->secretary);
        $insertSession('owner-1', $this->owner);
        $this->secretary->forceFill(['remember_token' => 'old-token'])->save();

        Livewire::actingAs($this->owner)->test('users-roles')
            ->call('resetPassword', $this->secretary->id);

        $this->assertDatabaseMissing('sessions', ['id' => 'secretary-1']);
        $this->assertDatabaseHas('sessions', ['id' => 'owner-1']);
        $this->assertNotSame('old-token', $this->secretary->fresh()->remember_token);

        $insertSession('secretary-2', $this->secretary);

        Livewire::actingAs($this->owner)->test('users-roles')
            ->call('editUser', $this->secretary->id)
            ->set('editIsActive', false)
            ->call('updateUser');

        $this->assertDatabaseMissing('sessions', ['id' => 'secretary-2']);
    }

    public function test_a_session_of_a_user_deactivated_after_login_is_logged_out(): void
    {
        $this->actingAs($this->secretary)->get('/')->assertOk();

        $this->secretary->update(['is_active' => false]);

        $this->actingAs($this->secretary->fresh())->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_is_locked_after_repeated_wrong_passwords(): void
    {
        $login = fn (string $password) => Livewire::test('login')
            ->set('email', 'owner@kapaim.test')
            ->set('password', $password)
            ->call('login');

        foreach (range(1, 5) as $_) {
            $login('wrong')->assertSet('errorMessage', 'אימייל או סיסמה שגויים.');
        }

        // Even the right password waits out the lockout.
        $login('password')->assertNoRedirect();
        $this->assertGuest();
        $this->assertStringContainsString('יותר מדי ניסיונות', (string) $login('password')->get('errorMessage'));

        \Illuminate\Support\Facades\RateLimiter::clear('login:owner@kapaim.test|127.0.0.1');
        \Illuminate\Support\Facades\RateLimiter::clear('login:owner@kapaim.test');
        $login('password')->assertRedirect('/');
    }

    public function test_a_user_cannot_deactivate_herself(): void
    {
        Livewire::actingAs($this->owner)->test('users-roles')
            ->call('editUser', $this->owner->id)
            ->set('editIsActive', false)
            ->call('updateUser')
            ->assertSet('userError', 'לא ניתן להשבית את המשתמשת שלך או להסיר ממנה את ההרשאה לניהול משתמשות.');

        $this->assertTrue($this->owner->fresh()->is_active);
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
        $limitedUser->refresh(); // the role's permissions are read once per request and cached on the model
        $this->assertTrue($limitedUser->hasPermission('leads', 'view'));
        $this->assertFalse($limitedUser->hasPermission('leads', 'edit'));
        $this->assertFalse($limitedUser->hasPermission('customers', 'view'));
    }

    /**
     * Stage 19 hardening: ⚡users-roles.blade.php previously had no
     * permission gate at all — harmless only because MVP's single real role
     * is full-access. A future limited role (e.g. "עובדת מכירות") must not
     * be able to reach user/role administration.
     */
    public function test_users_roles_page_is_blocked_without_the_users_permission(): void
    {
        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get('/users-roles')->assertStatus(403);
    }

    /**
     * Stage 19 hardening: ⚡activity-log.blade.php previously had no
     * permission gate at all — the log surfaces every business event
     * (leads, customers, deals, payments, ...), so a future limited role
     * must not be able to read the whole business's history through it.
     */
    public function test_activity_log_page_is_blocked_without_the_activity_log_permission(): void
    {
        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get('/activity-log')->assertStatus(403);
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

    public function test_activity_log_filters_by_several_users_at_once(): void
    {
        $third = User::create(['name' => 'משתמשת שלישית', 'email' => 'third@kapaim.test', 'password' => 'password', 'role_id' => $this->owner->role_id, 'is_active' => true]);
        $logger = app(ActivityLogger::class);
        $logger->log('user.created', 'פעולה של בעלת העסק', ['user' => $this->owner]);
        $logger->log('user.created', 'פעולה של המזכירה', ['user' => $this->secretary]);
        $logger->log('user.created', 'פעולה של השלישית', ['user' => $third]);

        Livewire::actingAs($this->owner)->test('activity-log')
            ->set('filterUserIds', [(string) $this->owner->id, (string) $third->id])
            ->assertSee('פעולה של בעלת העסק')
            ->assertSee('פעולה של השלישית')
            ->assertDontSee('פעולה של המזכירה')
            ->assertSee($this->owner->name.', משתמשת שלישית')
            ->assertSee('ניקוי — הכל');
    }
}
