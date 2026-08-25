<?php

namespace Database\Seeders;

use App\Models\BusinessEntity;
use App\Models\EmailTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Models\LeadSource;
use App\Models\PaymentMethod;
use App\Models\StatusDefinition;
use Illuminate\Database\Seeder;

/**
 * Build-plan 02 seed data. Items marked "confirmed" below are backed by an FR
 * number in docs/prd.md; items marked "proposed" are the build-plan's own
 * suggestion, explicitly awaiting business-owner sign-off — seeded anyway since
 * this is dev/demo data, not a production launch.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // ----- מקורות ליד -----
        LeadSource::create(['name' => 'דף נחיתה', 'is_active' => true]); // confirmed — FR-1.1, FR-1.18
        LeadSource::create(['name' => 'הפניה', 'is_active' => true]); // proposed
        LeadSource::create(['name' => 'פנייה חוזרת', 'is_active' => true]); // proposed

        // ----- אמצעי תשלום ----- (all confirmed — PRD section 4)
        PaymentMethod::create(['name' => 'הוראת קבע', 'type' => 'recurring', 'is_active' => true]);
        PaymentMethod::create(['name' => 'אשראי', 'type' => 'card', 'is_active' => true]);
        PaymentMethod::create(['name' => 'העברה בנקאית', 'type' => 'bank_transfer', 'is_active' => true]);
        PaymentMethod::create(['name' => 'צ\'קים', 'type' => 'check', 'is_active' => true]);

        // ----- סטטוסים -----
        // lead: "חדש" confirmed (FR-1.3), the rest proposed. See the note in
        // ⚡settings.blade.php about the traffic-light/sub-status mapping that
        // still needs to be addressed in the Leads module (stage 4).
        $this->statuses('lead', ['חדש', 'בתהליך מכירה', 'מוכנה לסגור / רוצה לרכוש', 'נסגר ללא מכירה']);
        $this->statuses('customer', ['פעילה', 'לא פעילה']); // proposed
        $this->statuses('deal', ['פתוחה', 'נשלחה חשבונית', 'שולמה', 'מבוטלת']); // proposed
        $this->statuses('document', ['טיוטה', 'נשלח', 'נחתם/אושר']); // proposed
        $this->statuses('payment', ['ממתין', 'שולם', 'חלקי']); // proposed
        $this->statuses('subscription', ['פעיל', 'הסתיים', 'בוטל']); // confirmed — FR-3.16-3.18
        $this->statuses('expense', ['ממתינה לחשבונית', 'תועדה']); // proposed
        $this->statuses('material_delivery', ['טרם נפתח', 'נפתח', 'אושר שהתקבל']); // confirmed — FR-5.10-5.15

        // ----- עוסק לדוגמה -----
        BusinessEntity::create([
            'name' => 'כפיים — חוויית תוכן (לדוגמה)',
            'classification' => 'עוסק פטור',
            'company_number' => '000000000',
            'email' => 'billing@example.kapaim.test',
            'phone' => '03-0000000',
            'is_active' => true,
        ]);

        // ----- תבנית דואר לדוגמה: אישור קליטת ליד (FR-1.18) -----
        $leadConfirmation = EmailTemplate::create([
            'name' => 'אישור קליטת ליד',
            'template_type' => 'lead_confirmation',
            'subject' => 'תודה שפניתם לכפיים!',
            'content' => "שלום {{contact_name}},\n\nקיבלנו את פנייתכם עבור {{school_name}} ונחזור אליכם בהקדם.\n\nבברכה, צוות כפיים",
            'is_active' => true,
        ]);
        $leadConfirmation->fields()->createMany([
            ['name' => 'שם איש קשר', 'field_type' => 'linked', 'linked_field' => 'lead.contact_name', 'is_required' => true, 'sort_order' => 1],
            ['name' => 'שם בית ספר', 'field_type' => 'linked', 'linked_field' => 'lead.school_name', 'is_required' => true, 'sort_order' => 2],
            ['name' => 'פתיח חופשי', 'field_type' => 'free_text', 'linked_field' => null, 'is_required' => false, 'sort_order' => 3],
        ]);

        // ----- שלד אינטגרציות (שלב 12) -----
        ExternalIntegrationSetting::create(['system' => 'smove', 'is_active' => false, 'settings' => []]);
        ExternalIntegrationSetting::create(['system' => 'summit', 'is_active' => false, 'settings' => []]);
    }

    private function statuses(string $scope, array $names): void
    {
        foreach ($names as $index => $name) {
            StatusDefinition::create([
                'scope' => $scope,
                'name' => $name,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
