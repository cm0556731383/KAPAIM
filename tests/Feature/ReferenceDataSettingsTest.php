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

    public function test_lead_source_payment_method_and_email_template_can_be_edited(): void
    {
        $source = \App\Models\LeadSource::create(['name' => 'פייסבוק', 'is_active' => true]);
        $method = PaymentMethod::create(['name' => 'העברה', 'type' => 'bank_transfer', 'is_active' => true]);
        $template = EmailTemplate::create(['name' => 'אישור', 'template_type' => 'lead_confirmation', 'subject' => 'ישן', 'content' => 'תוכן ישן', 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('settings')
            ->call('editRecord', 'lead_source', $source->id)->set('editForm.name', 'אינסטגרם')->call('saveRecord')->assertHasNoErrors()
            ->call('editRecord', 'payment_method', $method->id)->set('editForm.name', 'העברה בנקאית')->call('saveRecord')->assertHasNoErrors()
            ->call('selectEmailTemplate', $template->id)->set('emailTemplateSubject', 'תודה על פנייתך')->set('emailTemplateContent', 'שלום {{name}}')->call('saveEmailTemplate')->assertHasNoErrors();

        $this->assertSame('אינסטגרם', $source->fresh()->name);
        $this->assertSame('העברה בנקאית', $method->fresh()->name);
        $this->assertSame('תודה על פנייתך', $template->fresh()->subject);
        $this->assertSame('שלום {{name}}', $template->fresh()->content);
    }

    public function test_a_lead_source_can_be_deactivated(): void
    {
        $source = \App\Models\LeadSource::create(['name' => 'פייסבוק', 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('settings')->call('toggleLeadSource', $source->id);

        $this->assertFalse($source->fresh()->is_active);
    }

    public function test_the_settings_page_no_longer_manages_statuses(): void
    {
        $this->actingAs($this->owner)->get('/settings')->assertOk()->assertDontSee('סטטוס חדש');

        Livewire::actingAs($this->owner)->test('settings')
            ->call('editRecord', 'status', StatusDefinition::create(['scope' => 'lead', 'name' => 'חדש', 'is_active' => true, 'sort_order' => 1])->id)
            ->assertNotFound();
    }

    public function test_settings_page_requires_auth(): void
    {
        $this->get('/settings')->assertRedirect('/login');
    }

    public function test_owner_can_create_a_payment_method(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->set('paymentMethodName', 'ביט')
            ->set('paymentMethodType', 'other')
            ->call('addPaymentMethod');

        $this->assertDatabaseHas('payment_methods', ['name' => 'ביט', 'type' => 'other', 'is_active' => true]);
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

    public function test_a_new_email_template_is_created_from_the_editor(): void
    {
        Livewire::actingAs($this->owner)->test('settings')
            ->call('selectEmailTemplate', 0)
            ->set('emailTemplateName', 'תבנית בדיקה')
            ->set('emailTemplateType', 'general')
            ->set('emailTemplateSubject', 'נושא בדיקה')
            ->set('emailTemplateContent', 'תוכן בדיקה')
            ->call('saveEmailTemplate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('email_templates', ['name' => 'תבנית בדיקה', 'template_type' => 'general', 'is_active' => true]);
    }

    public function test_the_email_templates_tab_lists_every_template_and_opens_the_chosen_one_with_a_preview(): void
    {
        $lead = EmailTemplate::create(['name' => 'אישור קליטת ליד', 'template_type' => 'lead_confirmation', 'subject' => 'שלום {{שם_איש_הקשר}}', 'content' => 'תודה, {{שם_בית_הספר}}', 'is_active' => true]);

        Livewire::actingAs($this->owner)->test('settings')
            ->set('activeTab', 'email-templates')
            ->assertSee('אישור קליטת ליד')
            ->assertDontSee('תצוגה מקדימה')
            ->assertDontSee('שדה מיזוג')
            ->call('selectEmailTemplate', $lead->id)
            ->assertSee('תצוגה מקדימה')
            ->assertSee('שלום רחל כהן')
            ->assertSee('+ שם בית הספר');
    }

    public function test_settings_page_renders_real_seeded_reference_data(): void
    {
        StatusDefinition::create(['scope' => 'lead', 'name' => 'חדש', 'is_active' => true, 'sort_order' => 1]);
        PaymentMethod::create(['name' => 'אשראי', 'type' => 'card', 'is_active' => true]);

        $response = $this->actingAs($this->owner)->get('/settings');

        $response->assertOk();
        $response->assertSee('חדש');

        $this->actingAs($this->owner)->get('/settings?tab=payment-methods')->assertSee('אשראי');
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
