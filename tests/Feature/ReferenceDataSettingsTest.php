<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\StatusDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReferenceDataSettingsTest extends TestCase
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

    public function test_settings_page_requires_auth(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_owner_can_create_a_status_definition(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->set('statusScope', 'lead')
            ->set('statusName', 'סטטוס בדיקה')
            ->call('addStatus');

        $this->assertDatabaseHas('status_definitions', [
            'scope' => 'lead', 'name' => 'סטטוס בדיקה', 'is_active' => true,
        ]);
    }

    public function test_toggling_a_status_flips_is_active_without_deleting_it(): void
    {
        $status = StatusDefinition::create(['scope' => 'lead', 'name' => 'חדש', 'is_active' => true, 'sort_order' => 1]);
        $before = StatusDefinition::count();

        Livewire::actingAs($this->owner)->test('settings')->call('toggleStatus', $status->id);

        $this->assertSame($before, StatusDefinition::count());
        $this->assertFalse($status->fresh()->is_active);

        Livewire::actingAs($this->owner)->test('settings')->call('toggleStatus', $status->id);
        $this->assertTrue($status->fresh()->is_active);
    }

    public function test_owner_can_create_a_payment_method(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->set('paymentMethodName', 'ביט')
            ->set('paymentMethodType', 'wallet')
            ->call('addPaymentMethod');

        $this->assertDatabaseHas('payment_methods', ['name' => 'ביט', 'type' => 'wallet', 'is_active' => true]);
    }

    public function test_toggling_a_payment_method_never_deletes_it(): void
    {
        $method = PaymentMethod::create(['name' => 'אשראי', 'type' => 'card', 'is_active' => true]);
        $before = PaymentMethod::count();

        Livewire::actingAs($this->owner)->test('settings')->call('togglePaymentMethod', $method->id);

        $this->assertSame($before, PaymentMethod::count());
        $this->assertFalse($method->fresh()->is_active);
    }

    public function test_reference_data_actions_are_written_to_the_activity_log(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->set('leadSourceName', 'טלמיטינג')
            ->call('addLeadSource');

        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'lead_source.created',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_email_template_merge_fields_can_be_added(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->set('emailTemplateName', 'תבנית בדיקה')
            ->set('emailTemplateType', 'test_type')
            ->set('emailTemplateSubject', 'נושא בדיקה')
            ->set('emailTemplateContent', 'תוכן בדיקה')
            ->call('addEmailTemplate');

        $template = EmailTemplate::where('name', 'תבנית בדיקה')->firstOrFail();

        Livewire::actingAs($this->owner)->test('settings')
            ->set('fieldTemplateId', $template->id)
            ->set('fieldName', 'שם ליד')
            ->set('fieldType', 'linked')
            ->set('fieldLinkedField', 'lead.name')
            ->call('addEmailTemplateField');

        $this->assertDatabaseHas('email_template_fields', [
            'email_template_id' => $template->id, 'name' => 'שם ליד', 'field_type' => 'linked',
        ]);
    }

    public function test_settings_page_renders_real_seeded_reference_data(): void
    {
        StatusDefinition::create(['scope' => 'lead', 'name' => 'חדש', 'is_active' => true, 'sort_order' => 1]);
        PaymentMethod::create(['name' => 'אשראי', 'type' => 'card', 'is_active' => true]);

        $response = $this->actingAs($this->owner)->get('/settings');

        $response->assertOk();
        $response->assertSee('חדש');
        $response->assertSee('אשראי');
    }

    public function test_no_delete_route_exists_for_the_six_primary_reference_resources(): void
    {
        $routeUris = collect(app('router')->getRoutes())->map(fn ($route) => strtolower($route->uri()))->values();

        foreach (['statuses', 'status', 'lead-sources', 'payment-methods', 'business-entities', 'email-templates', 'integrations'] as $fragment) {
            $this->assertFalse(
                $routeUris->contains(fn ($uri) => str_contains($uri, $fragment) && str_contains($uri, 'delete')),
                "Unexpected delete route matching '{$fragment}'."
            );
        }
    }
}
