<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BusinessEntity;
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
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Build-plan 12 follow-up — the public "אישור וחתימה" online form
 * (documents.sign) and the real generated PDF (documents.pdf), both signed
 * routes with no auth at all (same pattern MaterialsMailingTest already
 * covers for materials.acknowledge).
 */
class DocumentSigningTest extends TestCase
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

    public function test_an_unsigned_sign_url_is_rejected(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $this->get("/documents/{$document->id}/sign")->assertStatus(403);
    }

    public function test_a_signed_sign_url_is_reachable_without_auth(): void
    {
        $document = $this->generateQuote($this->createDeal());
        $url = URL::signedRoute('documents.sign', ['document' => $document->id]);

        $this->get($url)->assertOk()->assertSee($document->rendered_content);
    }

    public function test_confirming_a_quote_sets_confirmed_at_and_logs_activity(): void
    {
        $document = $this->generateQuote($this->createDeal());

        Livewire::test('document-sign', ['document' => $document])->call('confirm');

        $document->refresh();
        $this->assertNotNull($document->confirmed_at);
        $this->assertTrue(ActivityLog::where('activity_type', 'document.confirmed')->where('document_id', $document->id)->exists());
    }

    public function test_opening_the_sign_page_marks_the_document_viewed(): void
    {
        $document = $this->generateQuote($this->createDeal());
        $this->assertSame('', $document->engagementStatusLabel());

        Livewire::test('document-sign', ['document' => $document]);

        $document->refresh();
        $this->assertNotNull($document->viewed_at);
        $this->assertSame('פתיחת מסמך', $document->engagementStatusLabel());
    }

    public function test_confirming_shows_a_signed_engagement_status_even_without_a_prior_view(): void
    {
        $document = $this->generateQuote($this->createDeal());

        Livewire::test('document-sign', ['document' => $document])->call('confirm');

        $this->assertSame('נחתם', $document->fresh()->engagementStatusLabel());
    }

    public function test_reopening_the_sign_page_never_overwrites_an_earlier_view(): void
    {
        $document = $this->generateQuote($this->createDeal());

        Livewire::test('document-sign', ['document' => $document]);
        $firstViewedAt = $document->fresh()->viewed_at;

        Livewire::test('document-sign', ['document' => $document->fresh()]);

        $this->assertTrue($firstViewedAt->equalTo($document->fresh()->viewed_at));
    }

    public function test_confirming_an_order_form_also_marks_it_received(): void
    {
        $deal = $this->createDeal();
        $document = Document::generateFor($deal, $this->createTemplate('order_form'));

        Livewire::test('document-sign', ['document' => $document])->call('confirm');

        $document->refresh();
        $this->assertNotNull($document->confirmed_at);
        $this->assertNotNull($document->received_at);
    }

    public function test_confirming_a_contract_also_marks_it_signed(): void
    {
        $deal = $this->createDeal();
        $orderForm = Document::generateFor($deal, $this->createTemplate('order_form'));
        $orderForm->markReceived();
        $contract = Document::generateFor($deal, $this->createTemplate('contract'));

        Livewire::test('document-sign', ['document' => $contract])->call('confirm');

        $contract->refresh();
        $this->assertNotNull($contract->confirmed_at);
        $this->assertNotNull($contract->signed_at);
    }

    public function test_confirming_with_a_required_field_left_empty_is_rejected(): void
    {
        $deal = $this->createDeal();
        $template = $this->createTemplate('order_form', [
            ['name' => 'שם מלא', 'field_type' => 'free_text', 'is_required' => true],
        ]);
        $document = Document::generateFor($deal, $template);
        $fieldId = $template->fields->first()->id;

        Livewire::test('document-sign', ['document' => $document])
            ->set("fieldValues.{$fieldId}", '')
            ->call('confirm')
            ->assertSet('error', fn ($error) => filled($error));

        $this->assertNull($document->fresh()->confirmed_at);
    }

    public function test_confirming_with_required_fields_filled_stores_them(): void
    {
        $deal = $this->createDeal();
        $template = $this->createTemplate('order_form', [
            ['name' => 'שם מלא', 'field_type' => 'free_text', 'is_required' => true],
        ]);
        $document = Document::generateFor($deal, $template);
        $fieldId = $template->fields->first()->id;

        Livewire::test('document-sign', ['document' => $document])
            ->set("fieldValues.{$fieldId}", 'דנה כהן')
            ->call('confirm');

        $document->refresh();
        $this->assertNotNull($document->confirmed_at);
        $this->assertSame('דנה כהן', $document->field_values[$fieldId]['value']);
    }

    public function test_confirming_twice_is_idempotent(): void
    {
        $document = $this->generateQuote($this->createDeal());

        Livewire::test('document-sign', ['document' => $document])->call('confirm');
        $firstConfirmedAt = $document->fresh()->confirmed_at;

        Livewire::test('document-sign', ['document' => $document->fresh()])->call('confirm');

        $this->assertSame(1, ActivityLog::where('activity_type', 'document.confirmed')->where('document_id', $document->id)->count());
        $this->assertTrue($firstConfirmedAt->equalTo($document->fresh()->confirmed_at));
    }

    public function test_a_linked_field_with_an_existing_value_is_shown_readonly(): void
    {
        $deal = $this->createDeal();
        $schoolName = $deal->customer->school->name;
        $template = $this->createTemplate('order_form', [
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => false],
        ]);
        $document = Document::generateFor($deal, $template);
        $fieldId = $template->fields->first()->id;

        Livewire::test('document-sign', ['document' => $document])
            ->assertSet("readonlyFields.{$fieldId}", true)
            ->assertSet("fieldValues.{$fieldId}", $schoolName);
    }

    public function test_a_linked_field_with_no_existing_value_is_editable(): void
    {
        $deal = $this->createDeal();
        $this->assertNull($deal->customer->school->address);
        $template = $this->createTemplate('order_form', [
            ['name' => 'כתובת בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_address', 'is_required' => false],
        ]);
        $document = Document::generateFor($deal, $template);
        $fieldId = $template->fields->first()->id;

        Livewire::test('document-sign', ['document' => $document])
            ->assertSet("readonlyFields.{$fieldId}", false);
    }

    // ----- PDF -----

    public function test_an_unsigned_pdf_url_is_rejected(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $this->get("/documents/{$document->id}/pdf")->assertStatus(403);
    }

    public function test_a_signed_pdf_url_returns_a_pdf(): void
    {
        $document = $this->generateQuote($this->createDeal());
        $url = URL::signedRoute('documents.pdf', ['document' => $document->id]);

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /**
     * An invoice/credit note is issued to Summit only (Document::sendTo()) —
     * neither the online sign form nor the PDF route is relevant, so both
     * 404 for these two types even with a validly signed link.
     */
    public function test_a_signed_sign_url_404s_for_an_invoice(): void
    {
        $document = $this->generateInvoice($this->signedContractDeal());
        $url = URL::signedRoute('documents.sign', ['document' => $document->id]);

        $this->get($url)->assertStatus(404);
    }

    public function test_a_signed_pdf_url_404s_for_an_invoice(): void
    {
        $document = $this->generateInvoice($this->signedContractDeal());
        $url = URL::signedRoute('documents.pdf', ['document' => $document->id]);

        $this->get($url)->assertStatus(404);
    }

    /**
     * 2026-09-08 production incident: a real contract whose template content
     * was pasted from Word (real HTML — several nested dir="LTR"/dir="RTL"
     * bidi-override spans around a `<br>`, exactly the shape below) 500'd on
     * this route — mpdf's bidi bookkeeping (Mpdf\Tag\Br) throws a plain PHP
     * warning on that shape, escalated to a fatal ErrorException by
     * Laravel's HandleExceptions in a real request (never reproduced in a
     * CLI/tinker render, which is why it went unnoticed). Fixed by lowering
     * error_reporting around the mpdf calls in Document::renderPdfBinary()
     * — this pins that fix with the actual content shape that broke it.
     */
    public function test_a_pdf_with_nested_bidi_spans_and_a_br_renders_without_erroring(): void
    {
        $deal = $this->createDeal();
        $template = DocumentTemplate::create([
            'document_type' => 'quote',
            'name' => 'תבנית וורד לבדיקת חתימה '.random_int(1, 999999),
            'content' => '<p dir="RTL"><span lang="HE">טקסט לפני</span>'
                .'<span dir="LTR"></span><span dir="LTR"></span>'
                .'<span dir="LTR">, <br></span>'
                .'<span lang="HE">טקסט אחרי</span></p>',
            'is_active' => true,
        ]);
        $document = Document::generateFor($deal, $template);

        $response = $this->get($document->smoveAttachmentUrl());

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    // ----- documents.pdf-file (fetched server-side by Smove — Document::smoveAttachmentUrl()) -----

    public function test_a_genuine_smove_attachment_token_returns_a_pdf(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $response = $this->get($document->smoveAttachmentUrl());

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_the_smove_attachment_url_has_no_query_string_and_ends_in_pdf(): void
    {
        $document = $this->generateQuote($this->createDeal());

        $url = $document->smoveAttachmentUrl();

        $this->assertStringNotContainsString('?', $url);
        $this->assertStringEndsWith('.pdf', $url);
    }

    public function test_a_tampered_smove_attachment_token_is_rejected(): void
    {
        $document = $this->generateQuote($this->createDeal());
        $url = preg_replace('/[a-f0-9]{32}\.pdf$/', str_repeat('0', 32).'.pdf', $document->smoveAttachmentUrl());

        $this->get($url)->assertStatus(404);
    }

    public function test_an_expired_smove_attachment_token_is_rejected(): void
    {
        $document = $this->generateQuote($this->createDeal());
        $expired = now()->subDay()->timestamp;
        $hash = substr(hash_hmac('sha256', "{$document->id}.{$expired}", config('app.key')), 0, 32);
        $url = route('documents.pdf-file', ['token' => "{$document->id}-{$expired}-{$hash}.pdf"]);

        $this->get($url)->assertStatus(404);
    }

    public function test_a_smove_attachment_token_404s_for_an_invoice(): void
    {
        $document = $this->generateInvoice($this->signedContractDeal());

        $this->get($document->smoveAttachmentUrl())->assertStatus(404);
    }

    // ----- test helpers (mirrors DocumentsEngineTest.php) -----

    private function createDeal(): Deal
    {
        $customer = $this->createCustomer();
        $program = Program::create([
            'name' => 'תוכנית בדיקת חתימה '.random_int(1, 999999),
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

        $school = School::create(['name' => 'בית ספר לבדיקת חתימה '.random_int(1, 999999)]);

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
            'name' => ucfirst($documentType).' תבנית בדיקת חתימה '.random_int(1, 999999),
            'content' => 'תוכן בדיקה עבור '.$documentType,
            'is_active' => true,
        ]);

        foreach ($fields as $index => $field) {
            $template->fields()->create(array_merge($field, ['sort_order' => $index + 1]));
        }

        if (! empty($fields)) {
            $tokens = collect($template->fields)->map(fn ($f) => $f->placeholderToken())->implode(' ');
            $template->update(['content' => 'תוכן בדיקה: '.$tokens]);
        }

        return $template;
    }

    private function createBusinessEntity(): BusinessEntity
    {
        return BusinessEntity::create([
            'name' => 'עוסק לבדיקת חתימה '.random_int(1, 999999),
            'classification' => 'עוסק פטור',
            'company_number' => (string) random_int(100000000, 999999999),
            'email' => 'business'.random_int(1, 999999).'@example.com',
            'phone' => '03-0000000',
            'is_active' => true,
        ]);
    }
}
