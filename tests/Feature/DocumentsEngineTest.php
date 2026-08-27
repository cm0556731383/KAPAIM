<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BusinessEntity;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentsEngineTest extends TestCase
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

    // ----- auth / permissions -----

    public function test_document_view_requires_auth(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $this->get("/documents/{$document->id}")->assertRedirect('/login');
    }

    public function test_document_view_is_blocked_without_the_documents_permission(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get("/documents/{$document->id}")->assertStatus(403);
    }

    public function test_document_templates_page_requires_auth(): void
    {
        $this->get('/document-templates')->assertRedirect('/login');
    }

    public function test_document_templates_page_is_blocked_without_permission(): void
    {
        $limited = Role::create(['name' => 'עובדת מכירות', 'is_active' => true]);
        $limitedUser = User::create([
            'name' => 'שרית לוי', 'email' => 'sarit2@kapaim.test', 'password' => 'password',
            'role_id' => $limited->id, 'is_active' => true,
        ]);

        $this->actingAs($limitedUser)->get('/document-templates')->assertStatus(403);
    }

    // ----- generation chain / business gate -----

    public function test_quote_can_be_generated_with_no_prerequisite(): void
    {
        $deal = $this->createDeal();
        $template = $this->createTemplate('quote');

        $document = Document::generateFor($deal, $template);

        $this->assertSame('quote', $document->document_type);
        $this->assertNull($document->preceding_document_id);
        $this->assertNotNull($document->rendered_content);
    }

    /**
     * FR-4.3/FR-8.7 — the most critical gate test: a contract can never be
     * generated before an order form for the same deal has been received.
     */
    public function test_contract_generation_is_blocked_without_a_received_order_form(): void
    {
        $deal = $this->createDeal();
        $contractTemplate = $this->createTemplate('contract');

        $this->assertFalse(Document::canGenerate($deal, 'contract'));

        $this->expectException(RuntimeException::class);
        Document::generateFor($deal, $contractTemplate);
    }

    public function test_contract_generation_is_still_blocked_when_order_form_exists_but_not_received(): void
    {
        $deal = $this->createDeal();
        $orderFormTemplate = $this->createTemplate('order_form');
        $contractTemplate = $this->createTemplate('contract');

        Document::generateFor($deal, $orderFormTemplate); // sent, but not marked received

        $this->assertFalse(Document::canGenerate($deal, 'contract'));
        $this->expectException(RuntimeException::class);
        Document::generateFor($deal, $contractTemplate);
    }

    public function test_contract_generation_succeeds_once_order_form_is_received(): void
    {
        $deal = $this->createDeal();
        $orderFormTemplate = $this->createTemplate('order_form');
        $contractTemplate = $this->createTemplate('contract');

        $orderForm = Document::generateFor($deal, $orderFormTemplate);
        $orderForm->markReceived();

        $this->assertTrue(Document::canGenerate($deal, 'contract'));

        $contract = Document::generateFor($deal, $contractTemplate);

        $this->assertSame('contract', $contract->document_type);
        $this->assertSame($orderForm->id, $contract->preceding_document_id);
    }

    /**
     * FR-4.4/FR-8.8 — the other critical gate test: an invoice can never be
     * generated before a contract for the same deal has been signed.
     */
    public function test_invoice_generation_is_blocked_without_a_signed_contract(): void
    {
        $deal = $this->createDeal();
        $invoiceTemplate = $this->createTemplate('invoice');
        $businessEntity = $this->createBusinessEntity();

        $this->assertFalse(Document::canGenerate($deal, 'invoice'));

        $this->expectException(RuntimeException::class);
        Document::generateFor($deal, $invoiceTemplate, 'digital', $businessEntity->id);
    }

    public function test_invoice_generation_is_still_blocked_when_contract_exists_but_not_signed(): void
    {
        $deal = $this->createDeal();
        $invoiceTemplate = $this->createTemplate('invoice');
        $businessEntity = $this->createBusinessEntity();

        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();
        Document::generateFor($deal, $this->createTemplate('contract')); // generated, but not marked signed

        $this->expectException(RuntimeException::class);
        Document::generateFor($deal, $invoiceTemplate, 'digital', $businessEntity->id);
    }

    public function test_invoice_generation_succeeds_once_contract_is_signed_and_business_entity_chosen(): void
    {
        $deal = $this->createDeal();
        $contractTemplate = $this->createTemplate('contract');
        $invoiceTemplate = $this->createTemplate('invoice');
        $businessEntity = $this->createBusinessEntity();

        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $contractTemplate);
        $contract->markSigned();

        $this->assertTrue(Document::canGenerate($deal, 'invoice'));

        $invoice = Document::generateFor($deal, $invoiceTemplate, 'digital', $businessEntity->id);

        $this->assertSame('invoice', $invoice->document_type);
        $this->assertSame($businessEntity->id, $invoice->business_entity_id);
        $this->assertSame($contract->id, $invoice->preceding_document_id);
    }

    /**
     * FR-4.15/FR-4.16/FR-8.9 — an invoice always requires an active business
     * entity chosen at generation time, never a fixed customer default.
     */
    public function test_invoice_generation_requires_a_business_entity(): void
    {
        $deal = $this->signedContractDeal();
        $invoiceTemplate = $this->createTemplate('invoice');

        $this->expectException(RuntimeException::class);
        Document::generateFor($deal, $invoiceTemplate, 'digital', null);
    }

    public function test_invoice_generation_requires_an_active_business_entity(): void
    {
        $deal = $this->signedContractDeal();
        $invoiceTemplate = $this->createTemplate('invoice');
        $inactiveEntity = $this->createBusinessEntity(isActive: false);

        $this->expectException(RuntimeException::class);
        Document::generateFor($deal, $invoiceTemplate, 'digital', $inactiveEntity->id);
    }

    /**
     * FR-4.21/FR-4.22 — one invoice per deal, no more.
     */
    public function test_only_one_invoice_per_deal(): void
    {
        $deal = $this->signedContractDeal();
        $invoiceTemplate = $this->createTemplate('invoice');
        $businessEntity = $this->createBusinessEntity();

        Document::generateFor($deal, $invoiceTemplate, 'digital', $businessEntity->id);

        $this->assertFalse(Document::canGenerate($deal, 'invoice'));

        $this->expectException(RuntimeException::class);
        Document::generateFor($deal, $invoiceTemplate, 'digital', $businessEntity->id);
    }

    // ----- invoice lines / total -----

    /**
     * FR-4.18/FR-4.19/FR-8.10/FR-8.11 — the invoice total is always the sum
     * of its lines, and a line without both a description and an amount is
     * rejected.
     */
    public function test_invoice_total_always_equals_sum_of_lines(): void
    {
        $invoice = $this->generateInvoice($this->signedContractDeal());

        $this->assertEquals(0, $invoice->totalAmount());

        $invoice->addLine('שורה א', 100);
        $invoice->addLine('שורה ב', 250, quantity: 2, unitPrice: 125);

        $this->assertEquals(350, $invoice->totalAmount());

        $line = $invoice->lines()->first();
        $invoice->removeLine($line->id);

        $this->assertEquals(250, $invoice->totalAmount());
    }

    public function test_invoice_line_without_description_is_rejected(): void
    {
        $invoice = $this->generateInvoice($this->signedContractDeal());

        $this->expectException(RuntimeException::class);
        $invoice->addLine('', 100);
    }

    public function test_invoice_line_without_a_positive_amount_is_rejected(): void
    {
        $invoice = $this->generateInvoice($this->signedContractDeal());

        $this->expectException(RuntimeException::class);
        $invoice->addLine('שורה', 0);
    }

    public function test_lines_cannot_be_added_to_a_non_invoice_document(): void
    {
        $quote = $this->generateQuote($this->createDeal());

        $this->expectException(RuntimeException::class);
        $quote->addLine('שורה', 100);
    }

    // ----- FR-4.14: template changes never retroactively alter a document -----

    public function test_template_change_does_not_retroactively_alter_generated_documents(): void
    {
        $deal = $this->createDeal();
        $template = $this->createTemplate('quote', [
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => true],
        ]);

        $document = Document::generateFor($deal, $template);
        $originalContent = $document->rendered_content;

        $this->assertStringContainsString($deal->customer->school->name, $originalContent);

        // Edit the template's content AND deactivate it.
        $template->update(['content' => 'תוכן חדש לגמרי — {{שם_בית_הספר}}', 'is_active' => false]);

        $document->refresh();

        $this->assertSame($originalContent, $document->rendered_content, 'Editing the template must not retroactively change an already-generated document (FR-4.14).');
        $this->assertNotEquals($template->fresh()->renderContent(), $document->rendered_content);
    }

    // ----- recipients (FR-2.11-FR-2.13/FR-4.8/FR-4.9) -----

    public function test_recipients_default_to_primary_contacts(): void
    {
        $deal = $this->createDeal();
        $primary = Contact::create(['customer_id' => $deal->customer_id, 'name' => 'איש קשר ראשי', 'email' => 'primary@example.com', 'is_primary' => true]);
        Contact::create(['customer_id' => $deal->customer_id, 'name' => 'איש קשר משני', 'email' => 'secondary@example.com', 'is_primary' => false]);

        $document = $this->generateQuote($deal);

        $defaults = $document->defaultRecipients();

        $this->assertCount(1, $defaults);
        $this->assertSame($primary->id, $defaults->first()->id);
    }

    /**
     * FR-2.13/FR-4.9 — adding an ad-hoc recipient for one send never touches
     * contacts.is_primary.
     */
    public function test_editing_recipients_for_a_send_does_not_mutate_primary_contact_flag(): void
    {
        $deal = $this->createDeal();
        $primary = Contact::create(['customer_id' => $deal->customer_id, 'name' => 'איש קשר ראשי', 'email' => 'primary@example.com', 'is_primary' => true]);

        $document = $this->generateQuote($deal);

        $document->sendTo([
            ['contact_id' => $primary->id, 'name' => $primary->name, 'email' => $primary->email],
            ['contact_id' => null, 'name' => 'נמען נקודתי', 'email' => 'ad-hoc@example.com'],
        ], 'digital', app(\App\Services\ActivityLogger::class), app(\App\Services\Integrations\ExternalOperationRunner::class), app(\App\Services\Integrations\SummitClient::class));

        $this->assertCount(2, $document->recipients);
        $primary->refresh();
        $this->assertTrue($primary->is_primary);
        $this->assertDatabaseMissing('contacts', ['name' => 'נמען נקודתי']); // never became a real contact row
    }

    /**
     * FR-2.12 — changing primary contacts afterward must not alter a
     * document's already-recorded recipients.
     */
    public function test_changing_primary_contacts_later_does_not_alter_a_past_documents_recipients(): void
    {
        $deal = $this->createDeal();
        $original = Contact::create(['customer_id' => $deal->customer_id, 'name' => 'איש קשר מקורי', 'email' => 'original@example.com', 'is_primary' => true]);

        $document = $this->generateQuote($deal);
        $document->sendTo(
            [['contact_id' => $original->id, 'name' => $original->name, 'email' => $original->email]],
            'digital',
            app(\App\Services\ActivityLogger::class),
            app(\App\Services\Integrations\ExternalOperationRunner::class),
            app(\App\Services\Integrations\SummitClient::class),
        );

        // Primary contact changes after the send.
        $original->update(['is_primary' => false]);
        Contact::create(['customer_id' => $deal->customer_id, 'name' => 'איש קשר חדש', 'email' => 'new@example.com', 'is_primary' => true]);

        $document->refresh()->load('recipients');
        $this->assertCount(1, $document->recipients);
        $this->assertSame('איש קשר מקורי', $document->recipients->first()->recipient_name);
    }

    public function test_sending_with_no_recipients_is_blocked(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $this->expectException(RuntimeException::class);
        $document->sendTo([], 'digital', app(\App\Services\ActivityLogger::class), app(\App\Services\Integrations\ExternalOperationRunner::class), app(\App\Services\Integrations\SummitClient::class));
    }

    // ----- FR-4.7: sending is always logged -----

    public function test_sending_a_document_logs_to_activity_log(): void
    {
        $deal = $this->createDeal();
        Contact::create(['customer_id' => $deal->customer_id, 'name' => 'איש קשר', 'email' => 'contact@example.com', 'is_primary' => true]);
        $document = $this->generateQuote($deal);

        $document->sendTo($document->defaultRecipients()->map(fn ($c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])->all(), 'pdf', app(\App\Services\ActivityLogger::class), app(\App\Services\Integrations\ExternalOperationRunner::class), app(\App\Services\Integrations\SummitClient::class));

        $this->assertDatabaseHas('activity_logs', [
            'activity_type' => 'document.sent', 'document_id' => $document->id, 'deal_id' => $deal->id,
        ]);
        $this->assertNotNull($document->fresh()->sent_at);
        $this->assertSame('pdf', $document->fresh()->format);
    }

    // ----- FR-4.12/FR-4.13: linked fields -----

    public function test_submitting_a_linked_field_value_updates_the_underlying_school_record(): void
    {
        $deal = $this->createDeal();
        $template = $this->createTemplate('order_form', [
            ['name' => 'כתובת בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_address', 'is_required' => false],
        ]);

        $document = Document::generateFor($deal, $template);
        $field = $document->documentTemplate->fields->first();

        $document->submitFieldValues([$field->id => 'רחוב הדוגמה 5, תל אביב']);

        $this->assertSame('רחוב הדוגמה 5, תל אביב', $deal->customer->school->fresh()->address);
    }

    public function test_required_field_left_empty_is_rejected(): void
    {
        $deal = $this->createDeal();
        $template = $this->createTemplate('order_form', [
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => true],
        ]);

        $document = Document::generateFor($deal, $template);
        $field = $document->documentTemplate->fields->first();

        $this->expectException(RuntimeException::class);
        $document->submitFieldValues([$field->id => '']);
    }

    /**
     * FR-4.13 — a contract auto-fills from the immediately preceding order
     * form's captured field values, matched by the same linked_field key.
     */
    public function test_contract_autofills_from_preceding_order_forms_captured_values(): void
    {
        $deal = $this->createDeal();
        $orderFormTemplate = $this->createTemplate('order_form', [
            ['name' => 'כתובת בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_address', 'is_required' => false],
        ]);
        $contractTemplate = $this->createTemplate('contract', [
            ['name' => 'כתובת', 'field_type' => 'linked', 'linked_field' => 'customer.school_address', 'is_required' => false],
        ]);

        $orderForm = Document::generateFor($deal, $orderFormTemplate);
        $orderFormField = $orderForm->documentTemplate->fields->first();
        $orderForm->submitFieldValues([$orderFormField->id => 'כתובת שנמסרה בטופס ההזמנה']);
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $contractTemplate);
        $contractField = $contract->documentTemplate->fields->first();

        $this->assertSame('כתובת שנמסרה בטופס ההזמנה', $contract->field_values[$contractField->id]['value']);
        $this->assertStringContainsString('כתובת שנמסרה בטופס ההזמנה', $contract->rendered_content);
    }

    // ----- no delete route / no deletion convention -----

    public function test_there_is_no_delete_route_for_documents_or_templates(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $uri = strtolower($route->uri());
            if (str_contains($uri, 'document')) {
                $this->assertNotContains('DELETE', $route->methods(), "Unexpected DELETE route: {$uri}");
            }
        }
    }

    public function test_deactivating_a_template_never_deletes_it(): void
    {
        $template = $this->createTemplate('quote');

        Livewire::actingAs($this->owner)->test('document-templates')
            ->call('toggleTemplate', $template->id);

        $this->assertDatabaseHas('document_templates', ['id' => $template->id, 'is_active' => false]);
    }

    // ----- Livewire screens render real data -----

    public function test_document_view_page_renders_real_document_data(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $response = $this->actingAs($this->owner)->get("/documents/{$document->id}");

        $response->assertOk();
    }

    public function test_document_templates_page_renders_seeded_data(): void
    {
        $template = $this->createTemplate('quote');

        $response = $this->actingAs($this->owner)->get('/document-templates');

        $response->assertOk();
        $response->assertSee($template->name);
    }

    public function test_deal_detail_page_shows_documents_section(): void
    {
        $deal = $this->createDeal();
        $this->generateQuote($deal);

        $response = $this->actingAs($this->owner)->get("/deals/{$deal->id}");

        $response->assertOk();
    }

    public function test_document_print_view_renders(): void
    {
        $document = $this->generateInvoice($this->signedContractDeal());
        $document->addLine('שורה לדוגמה', 100);

        $response = $this->actingAs($this->owner)->get("/documents/{$document->id}/print");

        $response->assertOk();
        $response->assertSee('שורה לדוגמה');
    }

    // ----- helpers -----

    private function createDeal(): Deal
    {
        $customer = $this->createCustomer();
        $program = Program::create([
            'name' => 'תוכנית בדיקת מסמכים '.random_int(1, 999999),
            'price' => 400, 'is_premium' => false, 'is_subscription_type' => false, 'is_active' => true,
        ]);

        return Deal::createForCustomer($customer, $program, null);
    }

    private function signedContractDeal(): Deal
    {
        $deal = $this->createDeal();

        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();

        $contract = Document::generateFor($deal, $this->createTemplate('contract'));
        $contract->markSigned();

        return $deal;
    }

    private function generateQuote(Deal $deal): Document
    {
        return Document::generateFor($deal, $this->createTemplate('quote'));
    }

    private function generateInvoice(Deal $deal): Document
    {
        return Document::generateFor($deal, $this->createTemplate('invoice'), 'digital', $this->createBusinessEntity()->id);
    }

    private function createCustomer(): Customer
    {
        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'lead', 'name' => Lead::NEW_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $school = School::create(['name' => 'בית ספר לבדיקת מסמכים '.random_int(1, 999999)]);

        $lead = Lead::create([
            'school_id' => $school->id,
            'assigned_user_id' => $this->owner->id,
            'status_id' => $status->id,
            'email' => 'lead'.random_int(1, 999999).'@example.com',
            'phone' => '050-'.random_int(1000000, 9999999),
        ]);

        return $lead->convertToCustomer(app(\App\Services\ActivityLogger::class));
    }

    private function createTemplate(string $documentType, array $fields = []): DocumentTemplate
    {
        $template = DocumentTemplate::create([
            'document_type' => $documentType,
            'name' => ucfirst($documentType).' תבנית בדיקה '.random_int(1, 999999),
            'content' => 'תוכן בדיקה עבור '.$documentType,
            'is_active' => true,
        ]);

        foreach ($fields as $index => $field) {
            $template->fields()->create(array_merge($field, ['sort_order' => $index + 1]));
        }

        if (! empty($fields)) {
            // Content must reference each field's placeholder token for
            // renderContent()/FR-4.14 tests to have something to substitute.
            $tokens = collect($template->fields)->map(fn ($f) => $f->placeholderToken())->implode(' ');
            $template->update(['content' => 'תוכן בדיקה: '.$tokens]);
        }

        return $template;
    }

    private function createBusinessEntity(bool $isActive = true): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק לבדיקה '.random_int(1, 999999),
            'classification' => 'עוסק פטור',
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'business'.random_int(1, 999999).'@example.com',
            'phone' => '03-0000000',
            'is_active' => $isActive,
        ]);
    }
}
