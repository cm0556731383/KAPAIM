<?php

namespace Tests\Feature;

use App\Models\BusinessEntity;
use App\Models\Bundle;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Lead;
use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Models\PaymentMethod;
use App\Models\Program;
use App\Models\Role;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Services\Integrations\SummitClient;
use App\Services\RevenueReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * 2026-11-24: several programs/bundles in one deal — each a line with its own
 * amount; the quote, order form + contract and accounting documents cover
 * them all, the invoice line by line.
 */
class MultiItemDealsTest extends TestCase
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

    public function test_a_deal_holds_several_lines_and_its_amount_is_their_sum(): void
    {
        $customer = $this->createCustomer();
        [$a, $b] = [$this->createProgram('אלול', 1200), $this->createProgram('תשרי', 900)];
        $bundle = Bundle::create(['name' => 'מארז חגים', 'price' => 2000, 'is_active' => true]);

        $deal = Deal::createWithItems($customer, [
            ['program' => $a],
            ['program' => $b, 'amount' => 800],
            ['bundle' => $bundle],
        ]);

        $this->assertSame(['אלול', 'תשרי', 'מארז חגים'], $deal->items->pluck('name_snapshot')->all());
        $this->assertEquals([1200, 800, 2000], $deal->items->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(4000, (float) $deal->agreed_amount);
        $this->assertSame('אלול + תשרי + מארז חגים', $deal->itemsLabel());
        // The first line is mirrored on the deal row for single-item code.
        $this->assertSame($a->id, $deal->program_id);

        // Every program sold joins its own mailing list.
        foreach ([$a, $b] as $program) {
            $this->assertTrue(MailingMembership::where('mailing_list_id', MailingList::forProgram($program)->id)
                ->where('customer_id', $customer->id)->exists());
        }

        $this->assertTrue(Deal::withItem($b)->whereKey($deal->id)->exists());
        $this->assertTrue(Deal::withItem($bundle)->whereKey($deal->id)->exists());
    }

    public function test_a_deal_needs_at_least_one_line_and_one_subscription_bundle_at_most(): void
    {
        $customer = $this->createCustomer();

        try {
            Deal::createWithItems($customer, []);
            $this->fail('An empty deal was created.');
        } catch (RuntimeException) {
        }

        $this->expectException(RuntimeException::class);
        Deal::createWithItems($customer, [['bundle' => $this->subscriptionBundle('מנוי א')], ['bundle' => $this->subscriptionBundle('מנוי ב')]]);
    }

    public function test_a_subscription_bundle_with_a_program_opens_the_subscription_at_its_own_line_amount(): void
    {
        $customer = $this->createCustomer();
        $deal = Deal::createWithItems($customer, [
            ['program' => $this->createProgram('חשון', 500)],
            ['bundle' => $this->subscriptionBundle('מנוי שנתי'), 'amount' => 4000],
        ]);

        $subscription = Subscription::where('deal_id', $deal->id)->sole();
        $this->assertEquals(4000, (float) $subscription->agreed_price);
        $this->assertEquals(4500, (float) $deal->agreed_amount);
        $this->assertSame('מנוי שנתי', $deal->subscriptionBundle()?->name);
    }

    public function test_the_new_deal_window_adds_several_lines_with_editable_amounts(): void
    {
        $customer = $this->createCustomer();
        [$a, $b] = [$this->createProgram('כסלו', 1000), $this->createProgram('טבת', 700)];

        Livewire::actingAs($this->owner)->test('customer-detail', ['customer' => $customer])
            ->set('activeTab', 'deals')
            ->set('dealItem', "program:{$a->id}")
            ->set('dealItem', "program:{$b->id}")
            // The same item twice is ignored.
            ->set('dealItem', "program:{$a->id}")
            ->assertCount('dealLines', 2)
            ->assertSet('dealItem', '')
            ->set('dealLines.1.amount', '650')
            ->call('createDeal')
            ->assertHasNoErrors();

        $deal = Deal::sole();
        $this->assertEquals([1000, 650], $deal->items->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(1650, (float) $deal->agreed_amount);
    }

    public function test_the_invoice_has_a_line_per_item(): void
    {
        $deal = $this->signedDeal([['program' => $this->createProgram('שבט', 1000)], ['program' => $this->createProgram('אדר', 600)]]);

        $invoice = Document::generateFor($deal, 'invoice', 'digital', $this->createBusinessEntity()->id);

        $this->assertSame([1000.0, 600.0], $invoice->lines->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertStringStartsWith('שבט', $invoice->lines[0]->description);
        $this->assertStringStartsWith('אדר', $invoice->lines[1]->description);
    }

    public function test_the_items_field_renders_a_table_in_quotes_order_forms_and_contracts(): void
    {
        $deal = Deal::createWithItems($this->createCustomer(), [
            ['program' => $this->createProgram('ניסן', 1100)],
            ['program' => $this->createProgram('אייר', 900)],
        ]);
        $template = $this->createTemplate('quote', [
            ['name' => 'פירוט', 'field_type' => 'linked', 'linked_field' => 'deal.items'],
            ['name' => 'סה"כ', 'field_type' => 'linked', 'linked_field' => 'deal.agreed_amount'],
        ]);

        $quote = Document::generateFor($deal, $template);

        $this->assertSame("ניסן — ₪1,100\nאייר — ₪900", DocumentLinkedFields::resolve('deal.items', $deal));
        $this->assertStringContainsString('<table class="deal-items"', $quote->rendered_content);
        $this->assertStringContainsString('<td style="border:1px solid #d9d4c7; padding:6px 10px; text-align:right">ניסן</td>', $quote->rendered_content);
        $this->assertStringContainsString('₪1,100', $quote->rendered_content);
        $this->assertStringContainsString('2,000', $quote->rendered_content);
    }

    public function test_the_default_contract_is_the_shared_one_else_the_standard_one(): void
    {
        $standard = DocumentTemplate::create(['document_type' => 'contract', 'name' => DocumentTemplate::STANDARD_CONTRACT_NAME, 'content' => 'x', 'is_active' => true]);
        $special = DocumentTemplate::create(['document_type' => 'contract', 'name' => 'חוזה מיוחד', 'content' => 'x', 'is_active' => true]);
        [$a, $b, $c] = [$this->createProgram('א', 100), $this->createProgram('ב', 100), $this->createProgram('ג', 100)];
        $a->update(['contract_template_id' => $special->id]);
        $b->update(['contract_template_id' => $special->id]);

        $shared = Deal::createWithItems($this->createCustomer(), [['program' => $a], ['program' => $b]]);
        $mixed = Deal::createWithItems($this->createCustomer(), [['program' => $a], ['program' => $c]]);

        $this->assertSame($special->id, DocumentTemplate::defaultContractFor($shared)?->id);
        $this->assertSame($standard->id, DocumentTemplate::defaultContractFor($mixed)?->id);
    }

    public function test_lines_can_be_edited_on_the_deal_card_until_a_document_is_sent(): void
    {
        [$a, $b] = [$this->createProgram('סיון', 1000), $this->createProgram('תמוז', 500)];
        $deal = Deal::createWithItems($this->createCustomer(), [['program' => $a]]);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal])
            ->call('editItems')
            ->assertSet('itemLines.0.amount', '1000')
            ->set('itemPicker', "program:{$b->id}")
            ->set('itemLines.0.amount', '900')
            ->call('saveItems')
            ->assertHasNoErrors()
            ->assertSet('editingItems', false);

        $deal->refresh();
        $this->assertSame(['סיון', 'תמוז'], $deal->items->pluck('name_snapshot')->all());
        $this->assertEquals(1400, (float) $deal->agreed_amount);
        $this->assertTrue(MailingMembership::where('mailing_list_id', MailingList::forProgram($b)->id)->exists());

        // Once something went out to the customer, the lines are locked.
        Document::generateFor($deal, $this->createTemplate('quote'))->update(['sent_at' => now()]);

        Livewire::actingAs($this->owner)->test('deal-detail', ['deal' => $deal->fresh()])
            ->call('editItems')
            ->assertSet('editingItems', false)
            ->assertNotSet('itemsError', null);

        $this->expectException(RuntimeException::class);
        $deal->fresh()->replaceItems($deal->fresh()->version, [['program' => $a]]);
    }

    public function test_a_lead_quote_with_several_items_opens_one_deal_with_all_of_them(): void
    {
        $lead = $this->createLead();
        Contact::create(['school_id' => $lead->school_id, 'name' => 'רחל', 'email' => 'rachel@example.com', 'is_primary' => true]);
        [$a, $b] = [$this->createProgram('אב', 1000), $this->createProgram('אלול ב', 800)];
        $lead->interestedPrograms()->attach([$a->id, $b->id]);
        $this->createTemplate('quote');

        Livewire::actingAs($this->owner)->test('lead-detail', ['lead' => $lead])
            ->call('openQuoteForm')
            ->assertCount('quoteLines', 2)
            ->set('quoteLines.1.amount', '750')
            ->call('createQuote')
            ->assertHasNoErrors();

        $quote = Document::where('lead_id', $lead->id)->sole();
        $this->assertSame('אב + אלול ב', $quote->leadOfferItemName());
        $this->assertEquals(1750, (float) $quote->lead_offer['agreed_amount']);

        $quote->sendTo(
            $quote->defaultRecipients()->map(fn ($c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])->all(),
            Document::FORMAT_FORM_AND_PDF,
            app(ActivityLogger::class), app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class),
        );
        Livewire::test('document-sign', ['document' => $quote])->call('confirm')->assertHasNoErrors();

        $deal = $quote->fresh()->deal;
        $this->assertEquals([1000, 750], $deal->items->pluck('amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(1750, (float) $deal->agreed_amount);
    }

    public function test_revenue_by_program_splits_a_payment_by_the_lines_shares(): void
    {
        [$a, $b] = [$this->createProgram('מרחשון', 300), $this->createProgram('כסלו ב', 100)];
        $deal = Deal::createWithItems($this->createCustomer(), [['program' => $a], ['program' => $b]]);
        $method = PaymentMethod::create(['name' => 'העברה', 'type' => 'bank_transfer', 'is_active' => true]);
        $deal->updatePaymentMethod($method->id);
        $deal->fresh()->recordPayment($deal->fresh()->version, 200);

        $revenue = app(RevenueReport::class)->revenueByProgram();

        $this->assertSame(150.0, $revenue['מרחשון']);
        $this->assertSame(50.0, $revenue['כסלו ב']);
    }

    // ----- helpers -----

    private function signedDeal(array $items): Deal
    {
        $deal = Deal::createWithItems($this->createCustomer(), $items);
        Document::generateFor($deal, $this->createTemplate('order_form'))->markReceived();
        Document::generateFor($deal, $this->createTemplate('contract'))->markSigned();

        return $deal->fresh();
    }

    private function createProgram(string $name, float $price): Program
    {
        return Program::create(['name' => $name, 'price' => $price, 'is_active' => true]);
    }

    private function subscriptionBundle(string $name): Bundle
    {
        return Bundle::create(['name' => $name, 'price' => 4800, 'is_subscription_type' => true, 'is_active' => true]);
    }

    private function createLead(): Lead
    {
        $status = StatusDefinition::firstOrCreate(['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME], ['is_active' => true, 'sort_order' => 1]);

        return Lead::create([
            'school_id' => School::create(['name' => 'בית ספר '.random_int(1, 999999)])->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);
    }

    private function createCustomer(): Customer
    {
        $customer = $this->createLead()->convertToCustomer(app(ActivityLogger::class));
        $customer->contacts()->update(['email' => 'contact'.random_int(1, 999999).'@example.com']);

        return $customer->fresh();
    }

    private function createTemplate(string $documentType, array $fields = []): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => "תבנית {$documentType} ".random_int(1, 999999),
            'content' => 'תוכן בדיקה',
            'is_active' => true,
        ]);

        foreach ($fields as $index => $field) {
            $template->fields()->create($field + ['sort_order' => $index + 1]);
        }

        if ($fields) {
            $template->update(['content' => 'תוכן: '.$template->fields->map->placeholderToken()->implode(' ')]);
        }

        return $template;
    }

    private function createBusinessEntity(): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק '.random_int(1, 999999), 'classification' => 'עוסק פטור',
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'b'.random_int(1, 999999).'@example.com', 'phone' => '03-0000000', 'is_active' => true,
        ]);
    }
}
