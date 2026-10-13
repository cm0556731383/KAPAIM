<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Build-plan 07 seed data: one active template per document type this app
 * lays out itself (quote/order_form/contract), Hebrew business tone matching
 * docs/storyboard/document-templates.html. Invoices, credit notes and
 * expense invoices have none (DocumentTemplate::SYSTEM_TYPES).
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

        // Invoices, credit notes and expense invoices have no template
        // (DocumentTemplate::SYSTEM_TYPES) — SUMIT lays them out from their lines.
    }
}
