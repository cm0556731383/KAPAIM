<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Services\Integrations\SummitClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A quote sent from the lead card, before conversion (business owner's
 * call, 2026-10-27): filled from the lead's school/contacts and the offered
 * program; the recipient's confirmation converts the lead and opens the deal.
 */
class LeadQuoteTest extends TestCase
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

    public function test_a_lead_quote_is_filled_from_the_lead_and_the_offered_program(): void
    {
        [$lead, $program] = [$this->createLead(), $this->createProgram(1200)];
        Contact::create(['school_id' => $lead->school_id, 'name' => 'רחל המנהלת', 'email' => 'rachel@example.com', 'is_primary' => true]);
        $template = $this->createQuoteTemplate([
            ['name' => 'בית ספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name'],
            ['name' => 'איש קשר', 'field_type' => 'linked', 'linked_field' => 'contact_primary.name'],
            ['name' => 'תוכנית', 'field_type' => 'linked', 'linked_field' => 'deal.program_name'],
            ['name' => 'סכום', 'field_type' => 'linked', 'linked_field' => 'deal.agreed_amount'],
        ]);

        $quote = Document::generateForLead($lead, $template, $program, null, 1000, 30);

        $this->assertNull($quote->deal_id);
        $this->assertSame($lead->id, $quote->lead_id);
        $this->assertSame(['program_id' => $program->id, 'bundle_id' => null, 'agreed_amount' => 1000, 'students_count' => 30], $quote->lead_offer);
        $values = array_values($quote->currentFieldValues());
        $this->assertSame([$lead->school->name, 'רחל המנהלת', $program->name, '1,000'], $values);
        $this->assertSame(['rachel@example.com'], $quote->defaultRecipients()->pluck('email')->all());
        $this->assertSame($lead->school->name, $quote->partyName());
    }

    public function test_with_no_contact_the_lead_email_is_the_default_recipient(): void
    {
        $lead = $this->createLead();

        $quote = Document::generateForLead($lead, $this->createQuoteTemplate(), $this->createProgram(), null);

        $this->assertSame([$lead->email], $quote->defaultRecipients()->pluck('email')->all());
        $this->assertSame(500.0, (float) $quote->lead_offer['agreed_amount']);
    }

    public function test_confirming_a_lead_quote_converts_the_lead_and_opens_the_deal(): void
    {
        [$lead, $program] = [$this->createLead(), $this->createProgram(1200)];
        $quote = Document::generateForLead($lead, $this->createQuoteTemplate(), $program, null, 1000, 30);
        $this->send($quote);

        Livewire::test('document-sign', ['document' => $quote])->call('confirm')->assertHasNoErrors();

        $quote->refresh();
        $customer = Customer::where('lead_id', $lead->id)->firstOrFail();
        $deal = Deal::where('customer_id', $customer->id)->firstOrFail();
        $this->assertNotNull($quote->confirmed_at);
        $this->assertSame($deal->id, $quote->deal_id);
        $this->assertSame($lead->id, $quote->lead_id);
        $this->assertSame($program->id, $deal->program_id);
        $this->assertSame(1000.0, (float) $deal->agreed_amount);
        $this->assertSame(30, $deal->students_count);
        $this->assertNotNull($lead->fresh()->converted_at);
        $this->assertTrue(ActivityLog::where('activity_type', 'deal.created')->where('document_id', $quote->id)->exists());

        // The quote heads the new deal's chain: the order form follows from it.
        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $this->assertSame($quote->id, $orderForm->preceding_document_id);
    }

    public function test_confirming_twice_opens_one_deal(): void
    {
        $lead = $this->createLead();
        $quote = Document::generateForLead($lead, $this->createQuoteTemplate(), $this->createProgram(), null);

        $quote->confirm([], app(ActivityLogger::class));
        Document::find($quote->id)->confirm([], app(ActivityLogger::class));

        $this->assertSame(1, Deal::count());
        $this->assertSame(1, Customer::count());
    }

    public function test_a_lead_converted_by_hand_meanwhile_gets_the_deal_on_its_existing_customer(): void
    {
        $lead = $this->createLead();
        $quote = Document::generateForLead($lead, $this->createQuoteTemplate(), null, $this->createBundle());
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));

        $quote->confirm([], app(ActivityLogger::class));

        $this->assertSame(1, Customer::count());
        $this->assertSame($customer->id, $quote->fresh()->deal->customer_id);
    }

    public function test_a_value_corrected_on_the_form_reaches_the_new_customer_and_deal(): void
    {
        $lead = $this->createLead();
        $template = $this->createQuoteTemplate([
            ['name' => 'עיר', 'field_type' => 'linked', 'linked_field' => 'customer.school_city'],
            ['name' => 'תלמידות', 'field_type' => 'linked', 'linked_field' => 'deal.students_count'],
        ]);
        $quote = Document::generateForLead($lead, $template, $this->createProgram(), null);
        [$cityField, $studentsField] = $template->fields->pluck('id')->all();

        Livewire::test('document-sign', ['document' => $quote])
            ->set("fieldValues.{$cityField}", 'בני ברק')
            ->set("fieldValues.{$studentsField}", '42')
            ->call('confirm');

        $this->assertSame('בני ברק', $lead->school->fresh()->city);
        $this->assertSame(42, $quote->fresh()->deal->students_count);
    }

    public function test_a_converted_lead_gets_no_new_lead_quote(): void
    {
        $lead = $this->createLead();
        $lead->convertToCustomer(app(ActivityLogger::class));

        $this->expectException(RuntimeException::class);
        Document::generateForLead($lead->fresh(), $this->createQuoteTemplate(), $this->createProgram(), null);
    }

    public function test_a_lead_without_a_school_gets_no_quote(): void
    {
        $lead = $this->createLead();
        $lead->update(['school_id' => null]);

        $this->expectException(RuntimeException::class);
        Document::generateForLead($lead->fresh(), $this->createQuoteTemplate(), $this->createProgram(), null);
    }

    public function test_the_lead_card_creates_the_quote_and_opens_it(): void
    {
        $lead = $this->createLead();
        $program = $this->createProgram(900);
        $lead->interestedPrograms()->attach($program->id);
        $this->createQuoteTemplate();

        $component = Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('openQuoteForm')
            ->assertSet('quoteItem', "program:{$program->id}")
            ->assertSet('quoteAmount', '900')
            ->set('quoteAmount', '850')
            ->call('createQuote')
            ->assertHasNoErrors();

        $quote = Document::where('lead_id', $lead->id)->firstOrFail();
        $component->assertRedirect(route('document-view', $quote));
        $this->assertSame(850.0, (float) $quote->lead_offer['agreed_amount']);
        $this->actingAs($this->owner)->get(route('document-view', $quote))->assertOk()->assertSee('חזרה לכרטיס הליד');
        $this->actingAs($this->owner)->get(route('lead-detail', $lead))->assertOk()->assertSee($program->name);
    }

    public function test_a_sales_rep_with_leads_access_only_can_open_and_send_the_lead_quote(): void
    {
        $rep = $this->leadsOnlyUser();
        $lead = $this->createLead();
        $quote = Document::generateForLead($lead, $this->createQuoteTemplate(), $this->createProgram(), null);

        $this->actingAs($rep)->get(route('document-view', $quote))->assertOk();
        $this->actingAs($rep)->get(route('document-print', $quote))->assertOk();

        Livewire::actingAs($rep)->test('document-view', ['document' => $quote])
            ->call('send', Document::FORMAT_FORM_AND_PDF)
            ->assertSet('sendError', null);

        $this->assertNotNull($quote->fresh()->sent_at);
    }

    public function test_once_confirmed_the_quote_follows_the_customers_page_permissions(): void
    {
        $rep = $this->leadsOnlyUser();
        $quote = Document::generateForLead($this->createLead(), $this->createQuoteTemplate(), $this->createProgram(), null);
        $quote->confirm([], app(ActivityLogger::class));

        $this->actingAs($rep)->get(route('document-view', $quote))->assertForbidden();
    }

    // ----- order form + contract in one email (2026-10-27) -----

    public function test_confirming_a_lead_quote_sends_the_order_form_and_contract_together(): void
    {
        $sent = $this->captureSmoveSends();
        $lead = $this->createLead();
        Contact::create(['school_id' => $lead->school_id, 'name' => 'רחל', 'email' => 'rachel@example.com', 'is_primary' => true]);
        $quote = Document::generateForLead($lead, $this->createQuoteTemplate(), $this->createProgram(), null);
        $this->send($quote);
        $this->createTemplate('order_form');
        $this->createTemplate('contract');
        $sent->exchangeArray([]);

        Livewire::test('document-sign', ['document' => $quote])->call('confirm');

        $deal = $quote->fresh()->deal;
        $orderForm = $deal->documents()->where('document_type', 'order_form')->sole();
        $contract = $deal->documents()->where('document_type', 'contract')->sole();
        $this->assertNotNull($orderForm->sent_at);
        $this->assertEquals($orderForm->sent_at, $contract->sent_at);
        $this->assertSame($orderForm->id, $contract->preceding_document_id);
        $this->assertSame(['rachel@example.com'], $contract->recipients()->pluck('recipient_email')->all());
        $this->assertTrue($quote->fresh()->lead_offer['follow_up_sent']);

        // One email, to the one recipient, with both forms and both PDFs.
        $this->assertCount(1, $sent);
        [$to, $message] = $sent[0];
        $this->assertSame('rachel@example.com', $to[0]['email']);
        $this->assertSame('טופס הזמנה וחוזה', $message->subject);
        $this->assertStringContainsString($orderForm->signUrl(), $message->html);
        $this->assertStringContainsString($contract->signUrl(), $message->html);
        $this->assertSame(['טופס הזמנה.pdf', 'חוזה.pdf'], array_column($message->attachments, 'name'));
    }

    public function test_with_no_contract_template_the_confirmation_stands_and_the_home_screen_says_so(): void
    {
        $lead = $this->createLead();
        $quote = Document::generateForLead($lead, $this->createQuoteTemplate(), $this->createProgram(), null);

        $quote->confirm([], app(ActivityLogger::class));

        $quote->refresh();
        $this->assertNotNull($quote->confirmed_at);
        $this->assertNotNull($quote->deal_id);
        $this->assertFalse($quote->lead_offer['follow_up_sent']);
        $this->assertTrue(ActivityLog::where('activity_type', 'document.auto_send_failed')->exists());

        Livewire::actingAs($this->owner)->test('home')
            ->assertSee('הצעות מחיר שאושרו')
            ->assertSee($lead->school->name)
            ->assertSee('טופס ההזמנה והחוזה לא נשלחו')
            ->call('markQuoteNoticed', $quote->id)
            ->assertDontSee('הצעות מחיר שאושרו');

        $this->assertNotNull($quote->fresh()->confirmation_noticed_at);
    }

    public function test_the_deal_card_generates_the_order_form_and_contract_as_a_pair_sent_in_one_email(): void
    {
        $sent = $this->captureSmoveSends();
        $lead = $this->createLead();
        $customer = $lead->convertToCustomer(app(ActivityLogger::class));
        Contact::where('customer_id', $customer->id)->update(['name' => 'מנהלת']);
        $deal = Deal::createForCustomer($customer, $this->createProgram(), null);
        $this->createTemplate('order_form');
        $this->createTemplate('contract');

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->assertSee('+ הפקת טופס הזמנה וחוזה')
            ->call('generateOrderFormAndContract')
            ->assertHasNoErrors();

        $orderForm = $deal->documents()->where('document_type', 'order_form')->sole();
        $contract = $deal->documents()->where('document_type', 'contract')->sole();
        $this->assertSame([$contract->id], array_map(fn ($d) => $d->id, $orderForm->sendCompanions()));

        // The contract's own screen points to the order form; sending it alone is refused.
        Livewire::actingAs($this->owner)->test('document-view', ['document' => $contract])->assertSee('במסך טופס ההזמנה');
        Livewire::actingAs($this->owner)->test('document-view', ['document' => $contract])->call('send', Document::FORMAT_FORM_AND_PDF)->assertSet('sendError', 'החוזה נשלח יחד עם טופס ההזמנה, במייל אחד — שולחים אותו ממסך טופס ההזמנה.');

        Livewire::actingAs($this->owner)->test('document-view', ['document' => $orderForm])
            ->assertSee('שליחת טופס ההזמנה והחוזה')
            ->call('send', Document::FORMAT_FORM_AND_PDF)
            ->assertSet('sendError', null);

        $this->assertNotNull($contract->fresh()->sent_at);
        $this->assertCount(1, $sent);
        $this->assertCount(2, $sent[0][1]->attachments);
    }

    public function test_once_the_deal_has_a_contract_no_new_pair_is_generated(): void
    {
        $customer = $this->createLead()->convertToCustomer(app(ActivityLogger::class));
        $deal = Deal::createForCustomer($customer, $this->createProgram(), null);
        $this->createTemplate('order_form');
        $this->createTemplate('contract');
        Document::generateOrderFormAndContract($deal);

        $this->expectException(RuntimeException::class);
        Document::generateOrderFormAndContract($deal);
    }

    // ----- default contract per program/bundle (catalog) -----

    public function test_the_pair_uses_the_programs_default_contract_else_the_standard_one(): void
    {
        $this->createTemplate('order_form');
        $standard = DocumentTemplate::create(['document_type' => 'contract', 'name' => DocumentTemplate::STANDARD_CONTRACT_NAME, 'content' => 'סטנדרטי', 'is_active' => true]);
        $subscription = DocumentTemplate::create(['document_type' => 'contract', 'name' => 'חוזה מנוי', 'content' => 'מנוי', 'is_active' => true]);
        $customer = $this->createLead()->convertToCustomer(app(ActivityLogger::class));

        $plain = Deal::createForCustomer($customer, $this->createProgram(), null);
        $this->assertSame($standard->id, Document::generateOrderFormAndContract($plain)['contract']->document_template_id);

        $program = $this->createProgram();
        $program->update(['contract_template_id' => $subscription->id]);
        $chosen = Deal::createForCustomer($customer, $program, null);
        $this->assertSame($subscription->id, Document::generateOrderFormAndContract($chosen)['contract']->document_template_id);

        // A deactivated default falls back to the standard contract.
        $subscription->update(['is_active' => false]);
        $later = Deal::createForCustomer($customer, $program, null);
        $this->assertSame($standard->id, Document::generateOrderFormAndContract($later)['contract']->document_template_id);
    }

    public function test_the_catalog_starts_new_items_on_the_standard_contract_and_saves_a_change(): void
    {
        $standard = DocumentTemplate::create(['document_type' => 'contract', 'name' => DocumentTemplate::STANDARD_CONTRACT_NAME, 'content' => 'סטנדרטי', 'is_active' => true]);
        $other = DocumentTemplate::create(['document_type' => 'contract', 'name' => 'חוזה מנוי', 'content' => 'מנוי', 'is_active' => true]);

        $component = Livewire::actingAs($this->owner)->test('programs-catalog')
            ->assertSet('programContractTemplateId', (string) $standard->id)
            ->assertSet('bundleContractTemplateId', (string) $standard->id)
            ->set('programName', 'תוכנית חדשה')->set('programPrice', '300')
            ->call('addProgram')->assertHasNoErrors()
            ->set('bundleName', 'מארז חדש')->set('bundlePrice', '900')->set('bundleContractTemplateId', (string) $other->id)
            ->call('addBundle')->assertHasNoErrors();

        $program = Program::where('name', 'תוכנית חדשה')->sole();
        $this->assertSame($standard->id, $program->contract_template_id);
        $this->assertSame($other->id, Bundle::where('name', 'מארז חדש')->sole()->contract_template_id);

        $component->call('editItem', 'program', $program->id)
            ->assertSet('editContractTemplateId', (string) $standard->id)
            ->set('editContractTemplateId', (string) $other->id)
            ->call('saveItem')->assertHasNoErrors();
        $this->assertSame($other->id, $program->fresh()->contract_template_id);
    }

    // ----- helpers -----

    /** Every Smove send()'s [recipients, EmailMessage], instead of a real call. */
    private function captureSmoveSends(): \ArrayObject
    {
        $sent = new \ArrayObject;
        $this->partialMock(SmoveClient::class, function ($mock) use ($sent) {
            $mock->shouldReceive('send')->andReturnUsing(function (array $to, $message) use ($sent) {
                $sent[] = [$to, $message];

                return 'ref';
            });
        });

        return $sent;
    }

    private function send(Document $document): void
    {
        $document->sendTo(
            $document->defaultRecipients()->map(fn ($c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])->all(),
            Document::FORMAT_FORM_AND_PDF,
            app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class),
        );
    }

    private function leadsOnlyUser(): User
    {
        $role = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);

        foreach ([['leads', 'view'], ['leads', 'edit']] as [$resource, $action]) {
            $role->permissions()->create(['resource' => $resource, 'action' => $action, 'is_allowed' => true]);
        }

        return User::create([
            'name' => 'נציגה', 'email' => 'rep@kapaim.test', 'password' => 'password',
            'role_id' => $role->id, 'is_active' => true,
        ]);
    }

    private function createLead(): Lead
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        return Lead::create([
            'school_id' => School::create(['name' => 'בית ספר להצעת מחיר '.random_int(1, 999999)])->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ])->load('school');
    }

    private function createProgram(float $price = 500): Program
    {
        return Program::create([
            'name' => 'תוכנית להצעה '.random_int(1, 999999),
            'price' => $price, 'is_premium' => false, 'is_subscription_type' => false, 'is_active' => true,
        ]);
    }

    private function createBundle(): Bundle
    {
        return Bundle::create(['name' => 'מארז להצעה '.random_int(1, 999999), 'price' => 2000, 'is_active' => true]);
    }

    private function createQuoteTemplate(array $fields = []): DocumentTemplate
    {
        return $this->createTemplate('quote', $fields);
    }

    private function createTemplate(string $documentType, array $fields = []): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => "תבנית {$documentType} ".random_int(1, 999999),
            'content' => 'תוכן בדיקה עבור '.$documentType,
            'is_active' => true,
        ]);

        foreach ($fields as $index => $field) {
            $template->fields()->create(array_merge($field, ['sort_order' => $index + 1]));
        }

        if (! empty($fields)) {
            $tokens = $template->fields()->get()->map(fn ($f) => $f->placeholderToken())->implode(' ');
            $template->update(['content' => 'תוכן בדיקה: '.$tokens]);
        }

        return $template->fresh('fields');
    }
}
