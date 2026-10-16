<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\PagePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The per-page view/edit matrix on the users & roles screen
 * (App\Support\PagePermissions): toggling a cell, what view-only means on a
 * page, and that a hidden page is gone from the menu and closed.
 */
class PagePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Role $fullAccess;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $this->fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->owner = User::create([
            'name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password',
            'role_id' => $this->fullAccess->id, 'is_active' => true,
        ]);
    }

    public function test_the_matrix_lists_every_page_for_every_role(): void
    {
        $response = $this->actingAs($this->owner)->get(route('users-roles'));

        $response->assertOk();
        foreach (PagePermissions::PAGES as [$label]) {
            $response->assertSee($label);
        }
    }

    public function test_turning_edit_off_overrides_the_wildcard_for_that_page_only(): void
    {
        $role = $this->limitedRole();

        Livewire::actingAs($this->owner)->test('users-roles')
            ->call('togglePermission', $this->fullAccess->id, 'suppliers', 'edit');

        $this->fullAccess->refresh();
        $this->assertFalse($this->fullAccess->hasPermission('suppliers', 'edit'));
        $this->assertTrue($this->fullAccess->hasPermission('suppliers', 'view'));
        $this->assertTrue($this->fullAccess->hasPermission('expenses', 'edit'));
        $this->assertTrue($role->hasPermission('dashboard', 'view'));
    }

    public function test_edit_turns_view_on_and_removing_view_turns_edit_off(): void
    {
        $role = $this->limitedRole();
        $component = Livewire::actingAs($this->owner)->test('users-roles');

        $component->call('togglePermission', $role->id, 'expenses', 'edit');
        $role->refresh();
        $this->assertTrue($role->hasPermission('expenses', 'view'));
        $this->assertTrue($role->hasPermission('expenses', 'edit'));

        $component->call('togglePermission', $role->id, 'expenses', 'view');
        $role->refresh();
        $this->assertFalse($role->hasPermission('expenses', 'view'));
        $this->assertFalse($role->hasPermission('expenses', 'edit'));
    }

    public function test_a_view_only_page_has_no_edit_cell(): void
    {
        Livewire::actingAs($this->owner)->test('users-roles')
            ->call('togglePermission', $this->fullAccess->id, 'activity-log', 'edit')
            ->assertStatus(404);
    }

    public function test_you_cannot_remove_the_users_page_from_your_own_role(): void
    {
        Livewire::actingAs($this->owner)->test('users-roles')
            ->call('togglePermission', $this->fullAccess->id, 'users', 'view');

        $this->assertTrue($this->fullAccess->fresh()->hasPermission('users', 'view'));
    }

    public function test_a_user_without_users_edit_cannot_change_permissions(): void
    {
        $role = $this->limitedRole([['users', 'view']]);

        Livewire::actingAs($this->userWith($role))->test('users-roles')
            ->call('togglePermission', $role->id, 'expenses', 'view')
            ->assertForbidden();

        $this->assertFalse($role->fresh()->hasPermission('expenses', 'view'));
    }

    public function test_a_view_only_page_opens_but_blocks_saving(): void
    {
        $user = $this->userWith($this->limitedRole([['suppliers', 'view']]));

        $this->actingAs($user)->get(route('suppliers'))->assertOk();

        Livewire::actingAs($user)->test('suppliers')
            ->set('name', 'ספק חדש')
            ->set('classification', Supplier::CLASSIFICATIONS[0])
            ->call('saveSupplier')
            ->assertDispatched('notify', type: 'error');

        $this->assertSame(0, Supplier::count());
    }

    public function test_with_edit_the_same_page_saves(): void
    {
        $user = $this->userWith($this->limitedRole([['suppliers', 'edit']]));

        Livewire::actingAs($user)->test('suppliers')
            ->set('name', 'ספק חדש')
            ->set('classification', Supplier::CLASSIFICATIONS[0])
            ->call('saveSupplier');

        $this->assertSame(1, Supplier::count());
    }

    public function test_a_page_without_view_is_closed_and_hidden_from_the_menu(): void
    {
        $user = $this->userWith($this->limitedRole([['dashboard', 'view']]));

        $this->actingAs($user)->get(route('suppliers'))->assertForbidden();
        $this->actingAs($user)->get('/')->assertOk()->assertDontSee(route('suppliers'));
    }

    public function test_without_the_dashboard_the_home_page_goes_to_the_first_page_she_may_open(): void
    {
        $user = $this->userWith($this->limitedRole([['expenses', 'view']]));

        $this->actingAs($user)->get('/')->assertRedirect(route('expenses'));
    }

    private function limitedRole(array $rows = [['dashboard', 'view']]): Role
    {
        $role = Role::create(['name' => 'תפקיד מוגבל '.random_int(1, 999999), 'is_active' => true]);
        foreach ($rows as [$resource, $action]) {
            $role->permissions()->create(['resource' => $resource, 'action' => $action, 'is_allowed' => true]);
        }

        return $role;
    }

    private function userWith(Role $role): User
    {
        return User::create([
            'name' => 'שרית לוי', 'email' => 'sarit'.random_int(1, 999999).'@kapaim.test', 'password' => 'password',
            'role_id' => $role->id, 'is_active' => true,
        ]);
    }
}
