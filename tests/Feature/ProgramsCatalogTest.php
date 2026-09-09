<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProgramsCatalogTest extends TestCase
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

    public function test_catalog_page_requires_auth(): void
    {
        $this->get('/programs-catalog')->assertRedirect('/login');
    }

    public function test_owner_can_create_a_program(): void
    {
        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('programName', 'תוכנית בדיקה')
            ->set('programPrice', '199')
            ->call('addProgram');

        $this->assertDatabaseHas('programs', [
            'name' => 'תוכנית בדיקה', 'is_active' => true,
        ]);
    }

    public function test_program_price_must_be_greater_than_zero(): void
    {
        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('programName', 'תוכנית מחיר שגוי')
            ->set('programPrice', '0')
            ->call('addProgram')
            ->assertHasErrors(['programPrice']);

        $this->assertDatabaseMissing('programs', ['name' => 'תוכנית מחיר שגוי']);
    }

    public function test_bundle_price_must_be_greater_than_zero(): void
    {
        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('bundleName', 'מארז מחיר שגוי')
            ->set('bundlePrice', '-10')
            ->call('addBundle')
            ->assertHasErrors(['bundlePrice']);

        $this->assertDatabaseMissing('bundles', ['name' => 'מארז מחיר שגוי']);
    }

    public function test_toggling_a_program_never_deletes_it(): void
    {
        $program = Program::create(['name' => 'תוכנית קבועה', 'price' => 100, 'is_active' => true]);
        $before = Program::count();

        Livewire::actingAs($this->owner)->test('programs-catalog')->call('toggleProgram', $program->id);

        $this->assertSame($before, Program::count());
        $this->assertFalse($program->fresh()->is_active);
    }

    public function test_only_one_active_subscription_type_bundle_is_allowed(): void
    {
        Bundle::create([
            'name' => 'מנוי קיים', 'price' => 4000, 'is_subscription_type' => true, 'is_active' => true,
        ]);

        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('bundleName', 'מנוי שני')
            ->set('bundlePrice', '4500')
            ->set('bundleIsSubscriptionType', true)
            ->call('addBundle');

        $this->assertDatabaseMissing('bundles', ['name' => 'מנוי שני']);
    }

    public function test_activating_a_second_subscription_type_bundle_is_blocked(): void
    {
        Bundle::create(['name' => 'מנוי פעיל', 'price' => 4000, 'is_subscription_type' => true, 'is_active' => true]);
        $secondSub = Bundle::create(['name' => 'מנוי מושבת', 'price' => 4000, 'is_subscription_type' => true, 'is_active' => false]);

        Livewire::actingAs($this->owner)->test('programs-catalog')->call('toggleBundle', $secondSub->id);

        $this->assertFalse($secondSub->fresh()->is_active);
    }

    public function test_disabling_the_active_subscription_bundle_allows_activating_another(): void
    {
        $first = Bundle::create(['name' => 'מנוי א', 'price' => 4000, 'is_subscription_type' => true, 'is_active' => true]);
        $second = Bundle::create(['name' => 'מנוי ב', 'price' => 4000, 'is_subscription_type' => true, 'is_active' => false]);

        Livewire::actingAs($this->owner)->test('programs-catalog')->call('toggleBundle', $first->id);
        Livewire::actingAs($this->owner)->test('programs-catalog')->call('toggleBundle', $second->id);

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_bundle_program_membership_can_be_added_and_removed(): void
    {
        $bundle = Bundle::create(['name' => 'מארז בדיקה', 'price' => 300, 'is_active' => true]);
        $program = Program::create(['name' => 'תוכנית למארז', 'price' => 150, 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('membershipBundleId', $bundle->id)
            ->set('membershipProgramId', $program->id)
            ->call('addBundleProgram');

        $this->assertTrue($bundle->fresh()->programs->contains($program->id));

        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->call('removeBundleProgram', $bundle->id, $program->id);

        $this->assertFalse($bundle->fresh()->programs->contains($program->id));
        $this->assertDatabaseHas('bundles', ['id' => $bundle->id]);
        $this->assertDatabaseHas('programs', ['id' => $program->id]);
    }

    public function test_catalog_actions_are_written_to_the_activity_log(): void
    {
        Livewire::actingAs($this->owner)->test('programs-catalog')
            ->set('programName', 'תוכנית ליומן')
            ->set('programPrice', '250')
            ->call('addProgram');

        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'program.created', 'user_id' => $this->owner->id,
        ]);
    }

    public function test_catalog_page_renders_real_seeded_data(): void
    {
        Program::create(['name' => 'תוכנית מוצגת', 'price' => 100, 'is_active' => true]);
        Bundle::create(['name' => 'מארז מוצג', 'price' => 200, 'is_active' => true]);

        $response = $this->actingAs($this->owner)->get('/programs-catalog');

        $response->assertOk();
        $response->assertSee('תוכנית מוצגת');
        $response->assertSee('מארז מוצג');
    }

    public function test_no_delete_route_exists_for_programs_or_bundles(): void
    {
        $routeUris = collect(app('router')->getRoutes())->map(fn ($route) => strtolower($route->uri()))->values();

        foreach (['programs', 'bundles'] as $fragment) {
            $this->assertFalse(
                $routeUris->contains(fn ($uri) => str_contains($uri, $fragment) && str_contains($uri, 'delete')),
                "Unexpected delete route matching '{$fragment}'."
            );
        }
    }
}
