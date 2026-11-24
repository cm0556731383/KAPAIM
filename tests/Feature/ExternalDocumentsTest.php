<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 2026-11-25: recording on a deal a document made outside the system (Document::recordExternal()). */
class ExternalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $role->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);
        $this->owner = User::create(['name' => 'בעלת העסק', 'email' => 'owner@kapaim.test', 'password' => 'password', 'role_id' => $role->id, 'is_active' => true]);

        $school = School::create(['name' => 'אורות', 'classes_per_grade' => 2]);
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);
        $lead = Lead::create(['school_id' => $school->id, 'status_id' => $status->id, 'assigned_user_id' => $this->owner->id, 'phone' => '0501234567', 'email' => 'a@orot.test']);
        $this->actingAs($this->owner);
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $this->deal = Deal::createForCustomer($customer, Program::create(['name' => 'מוגנות', 'price' => 1000, 'is_active' => true]), null);
    }

    public function test_a_contract_signed_on_paper_is_recorded_from_the_deal_card(): void
    {
        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal])
            ->assertSee('+ רישום מסמך שנעשה מחוץ למערכת')
            ->set('externalType', 'contract')
            ->set('externalState', 'signed')
            ->set('externalDate', '2026-09-03')
            ->set('externalNumber', 'C-12')
            ->call('recordExternalDocument')
            ->assertHasNoErrors()
            ->assertSet('documentError', null)
            ->assertSee('מחוץ למערכת')
            ->assertSee('C-12');

        $contract = $this->deal->documents()->sole();
        $this->assertTrue($contract->is_external);
        $this->assertSame('2026-09-03', $contract->signed_at->toDateString());
        $this->assertSame('נחתם', $contract->engagementStatusLabel());
        $this->assertTrue(Document::canGenerate($this->deal, 'invoice'));

        $this->get(route('document-view', $contract))->assertOk()->assertSee('מסמך שנעשה מחוץ למערכת')->assertDontSee('תצוגת הדפסה / PDF');
    }

    public function test_an_invoice_can_only_be_marked_issued_and_only_once(): void
    {
        $component = Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal])
            ->set('externalType', 'invoice')
            ->assertSet('externalState', 'sent')
            ->set('externalState', 'signed')
            ->set('externalDate', '2026-09-10')
            ->call('recordExternalDocument')
            ->assertHasErrors('externalState')
            ->set('externalState', 'sent')
            ->call('recordExternalDocument')
            ->assertHasNoErrors();

        $this->assertSame(Deal::INVOICE_SENT_STATUS_NAME, $this->deal->fresh()->status->name);

        $component->set('externalType', 'invoice')->set('externalState', 'sent')->set('externalDate', '2026-09-11')
            ->call('recordExternalDocument')
            ->assertSet('documentError', 'לעסקה זו כבר יש חשבונית — ניתן לרשום חשבונית אחת בלבד לכל עסקה.');
    }

    public function test_a_future_date_is_refused(): void
    {
        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal])
            ->set('externalType', 'quote')->set('externalState', 'sent')->set('externalDate', now()->addDay()->toDateString())
            ->call('recordExternalDocument')
            ->assertHasErrors('externalDate');

        $this->assertSame(0, $this->deal->documents()->count());
    }

    public function test_a_document_recorded_by_mistake_can_be_deleted_and_the_deal_reopens(): void
    {
        $invoice = Document::recordExternal($this->deal, 'invoice', 'sent', now()->subDay(), '5', app(ActivityLogger::class));
        $this->assertSame(Deal::INVOICE_SENT_STATUS_NAME, $this->deal->fresh()->status->name);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $this->deal->fresh()])
            ->call('deleteExternalDocument', $invoice->id)
            ->assertSet('documentError', null);

        $this->assertSame(0, $this->deal->documents()->count());
        $this->assertSame(Deal::DEFAULT_STATUS_NAME, $this->deal->fresh()->status->name);
    }

    public function test_a_document_with_a_later_one_resting_on_it_cannot_be_deleted(): void
    {
        $orderForm = Document::recordExternal($this->deal, 'order_form', 'signed', now()->subDays(3), null, app(ActivityLogger::class));
        Document::recordExternal($this->deal, 'contract', 'signed', now()->subDays(2), null, app(ActivityLogger::class));

        $this->assertFalse($orderForm->isDeletableExternal());
    }
}
