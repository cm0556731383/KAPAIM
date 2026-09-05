<?php

namespace Database\Seeders;

use App\Models\BusinessEntity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Illuminate\Database\Seeder;

/**
 * Build-plan 07 demo data: walks DealsDemoSeeder's demo deal (בית ספר יובלים)
 * through the full quote -> order_form -> contract -> invoice chain, the
 * same "example from the storyboard" spirit as the other demo seeders — so
 * there's a real, fully-progressed document chain to look at.
 */
class DocumentsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $activityLogger = app(ActivityLogger::class);
        $runner = app(ExternalOperationRunner::class);
        $summit = app(SummitClient::class);

        $deal = Deal::whereHas('customer.school', fn ($q) => $q->where('name', 'בית ספר יובלים'))->first();
        $businessEntity = BusinessEntity::where('is_active', true)->first();

        $quoteTemplate = DocumentTemplate::where('document_type', 'quote')->where('is_active', true)->first();
        $orderFormTemplate = DocumentTemplate::where('document_type', 'order_form')->where('is_active', true)->first();
        $contractTemplate = DocumentTemplate::where('document_type', 'contract')->where('is_active', true)->first();
        $invoiceTemplate = DocumentTemplate::where('document_type', 'invoice')->where('is_active', true)->first();

        if (! $deal || ! $businessEntity || ! $quoteTemplate || ! $orderFormTemplate || ! $contractTemplate || ! $invoiceTemplate) {
            return;
        }

        // ----- הצעת מחיר: נשלחה כ-PDF -----
        $quote = Document::generateFor($deal, $quoteTemplate, 'pdf');
        $quote->sendTo($this->recipientsFor($deal), 'pdf', $activityLogger, $runner, $summit);

        // ----- טופס הזמנה: נשלח כטופס דיגיטלי, מולא, והתקבל -----
        $orderForm = Document::generateFor($deal, $orderFormTemplate, 'digital');
        $orderForm->sendTo($this->recipientsFor($deal), 'digital', $activityLogger, $runner, $summit);
        $values = collect($orderForm->field_values)->map(fn ($v) => $v['value'])->all();
        $freeTextField = $orderForm->documentTemplate->fields->firstWhere('field_type', 'free_text');
        if ($freeTextField) {
            $values[$freeTextField->id] = 'אין הערות מיוחדות — אישור סופי לתחילת שנת הפעילות.';
        }
        $orderForm->submitFieldValues($values);
        $orderForm->markReceived();

        // ----- חוזה: מתמלא אוטומטית משדות ההזמנה (FR-4.13), נשלח ונחתם -----
        $contract = Document::generateFor($deal, $contractTemplate, 'digital');
        $contract->sendTo($this->recipientsFor($deal), 'digital', $activityLogger, $runner, $summit);
        $contract->markSigned();

        // ----- חשבונית: עוסק פטור + שורת פירוט, נשלחה כ-PDF -----
        $invoice = Document::generateFor($deal, $invoiceTemplate, 'pdf', $businessEntity->id);
        $invoice->addLine('מנוי שנתי — 10 תוכניות (' . $deal->program_name_snapshot . ')', (float) $deal->agreed_amount);
        $invoice->sendTo($this->recipientsFor($deal), 'pdf', $activityLogger, $runner, $summit);

        $activityLogger->log('document.demo_chain_seeded', "נוצרה שרשרת מסמכים מלאה (הצעה→הזמנה→חוזה→חשבונית) עבור עסקה #{$deal->id}", [
            'deal_id' => $deal->id, 'customer_id' => $deal->customer_id,
        ]);
    }

    private function recipientsFor(Deal $deal): array
    {
        $contacts = Contact::where('customer_id', $deal->customer_id)->where('is_primary', true)->get();

        if ($contacts->isEmpty()) {
            return [['contact_id' => null, 'name' => 'איש קשר ראשי', 'email' => null]];
        }

        return $contacts->map(fn ($c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])->all();
    }
}
