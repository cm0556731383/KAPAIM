<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Build-plan 07 seed data: one active template per document type
 * (quote/order_form/contract/invoice), Hebrew business tone matching
 * docs/storyboard/document-templates.html, plus one inactive "old" quote
 * template to demonstrate FR-4.14/FR-7.11 (an inactive template's already
 * generated documents are unaffected — see DocumentsEngineTest).
 */
class DocumentTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $quote = DocumentTemplate::create([
            'document_type' => 'quote',
            'name' => 'הצעת מחיר סטנדרטית',
            'content' => "הצעת מחיר — כפיים\n\nלכבוד {{שם_בית_הספר}},\n\nמצורפת הצעת מחיר עבור התוכנית \"{{שם_תוכנית}}\" בסך {{סכום_עסקה}} ש\"ח.\n\nההצעה בתוקף ל-14 יום ממועד שליחתה. נשמח לעמוד לרשותכם בכל שאלה.\n\nבברכה,\nצוות כפיים",
            'is_active' => true,
        ]);
        $quote->fields()->createMany([
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => true, 'sort_order' => 1],
            ['name' => 'שם תוכנית', 'field_type' => 'linked', 'linked_field' => 'deal.program_name', 'is_required' => true, 'sort_order' => 2],
            ['name' => 'סכום עסקה', 'field_type' => 'linked', 'linked_field' => 'deal.agreed_amount', 'is_required' => true, 'sort_order' => 3],
        ]);

        $orderForm = DocumentTemplate::create([
            'document_type' => 'order_form',
            'name' => 'טופס הזמנה — כללי',
            'content' => "טופס הזמנה — כפיים\n\nאנו, {{שם_בית_הספר}}, בכתובת {{כתובת_בית_הספר}}, מאשרים בזאת הזמנת התוכנית \"{{שם_תוכנית}}\" בסך {{סכום_עסקה}} ש\"ח.\n\nהערות נוספות: {{הערה_חופשית}}\n\nאישור טופס זה מהווה תנאי להמשך הפקת חוזה ההתקשרות.",
            'is_active' => true,
        ]);
        $orderForm->fields()->createMany([
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => true, 'sort_order' => 1],
            ['name' => 'כתובת בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_address', 'is_required' => false, 'sort_order' => 2],
            ['name' => 'שם תוכנית', 'field_type' => 'linked', 'linked_field' => 'deal.program_name', 'is_required' => true, 'sort_order' => 3],
            ['name' => 'סכום עסקה', 'field_type' => 'linked', 'linked_field' => 'deal.agreed_amount', 'is_required' => true, 'sort_order' => 4],
            ['name' => 'הערה חופשית', 'field_type' => 'free_text', 'linked_field' => null, 'is_required' => false, 'sort_order' => 5],
        ]);

        $contract = DocumentTemplate::create([
            'document_type' => 'contract',
            'name' => 'חוזה סטנדרטי',
            'content' => "הסכם התקשרות זה נערך ונחתם בין \"כפיים\" לבין {{שם_בית_הספר}} (\"הלקוחה\"), כתובת {{כתובת_בית_הספר}}.\n\nהלקוחה מאשרת רכישת התוכנית \"{{שם_תוכנית}}\" בסך {{סכום_עסקה}} ש\"ח, בהתאם לפרטים שנמסרו בטופס ההזמנה.\n\nחתימת הלקוחה על מסמך זה מהווה אישור סופי להתקדמות להפקת חשבונית.",
            'is_active' => true,
        ]);
        $contract->fields()->createMany([
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => true, 'sort_order' => 1],
            ['name' => 'כתובת בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_address', 'is_required' => false, 'sort_order' => 2],
            ['name' => 'שם תוכנית', 'field_type' => 'linked', 'linked_field' => 'deal.program_name', 'is_required' => true, 'sort_order' => 3],
            ['name' => 'סכום עסקה', 'field_type' => 'linked', 'linked_field' => 'deal.agreed_amount', 'is_required' => true, 'sort_order' => 4],
        ]);

        $invoice = DocumentTemplate::create([
            'document_type' => 'invoice',
            'name' => 'חשבונית — עוסק פטור',
            'content' => "חשבונית מס — עוסק פטור\n\nלכבוד: {{שם_בית_הספר}}\n\nהפירוט המלא מופיע בטבלת השורות המצורפת למסמך זה. סך כל השורות הוא הסכום לתשלום.\n\nתודה על רכישתכם!",
            'is_active' => true,
        ]);
        $invoice->fields()->createMany([
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => true, 'sort_order' => 1],
        ]);

        // ----- חשבונית זיכוי (build-plan 09) — מופקת רק בעת ביטול מנוי -----
        $creditNote = DocumentTemplate::create([
            'document_type' => 'credit_note',
            'name' => 'חשבונית זיכוי — ביטול מנוי',
            'content' => "חשבונית זיכוי — עוסק פטור\n\nלכבוד: {{שם_בית_הספר}}\n\nהזיכוי ניתן בהתאם לביטול המנוי, לפי מספר התוכניות שטרם סופקו ולפי המחיר שסוכם עם הלקוחה (FR-8.24). הפירוט המלא מופיע בטבלת השורות המצורפת למסמך זה.\n\nתודה על שיתוף הפעולה!",
            'is_active' => true,
        ]);
        $creditNote->fields()->createMany([
            ['name' => 'שם בית הספר', 'field_type' => 'linked', 'linked_field' => 'customer.school_name', 'is_required' => true, 'sort_order' => 1],
        ]);

        // Inactive "old" template — demonstrates FR-4.14/FR-7.11: deactivating
        // a template never touches documents already generated from it.
        DocumentTemplate::create([
            'document_type' => 'quote',
            'name' => 'הצעת מחיר — מנוי שנתי (ישן)',
            'content' => "נוסח הצעת מחיר ישן, הוחלף בתבנית הסטנדרטית.",
            'is_active' => false,
        ]);
    }
}
