<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "שליחת בקשות לכל הספקים" on the expenses page sends each supplier one
     * email for all their missing invoices (Expense::requestAllMissingInvoices()).
     * A supplier missing several gets this 'supplier_invoices_request'
     * wording, with one upload link per expense where {{רשימת_ההוצאות}}
     * sits; a supplier missing just one gets the single-expense email.
     */
    public function up(): void
    {
        if (DB::table('email_templates')->where('template_type', 'supplier_invoices_request')->exists()) {
            return;
        }

        DB::table('email_templates')->insert([
            'name' => 'בקשת כמה חשבוניות מספק',
            'template_type' => 'supplier_invoices_request',
            'subject' => 'בקשה לחשבוניות עבור {{מספר_החשבוניות_החסרות}} תשלומים',
            'content' => "שלום {{שם_הספק}},\n\n"
                ."עדיין לא התקבלו אצלנו חשבוניות עבור {{מספר_החשבוניות_החסרות}} תשלומים ששילמנו לכם (סך הכול ₪{{סכום_כולל}}).\n\n"
                ."לכל תשלום ברשימה יש כפתור משלו להעלאת החשבונית (קובץ PDF או תמונה) — היא תגיע ישירות אלינו:\n\n"
                ."{{רשימת_ההוצאות}}\n\n"
                .'בברכה, צוות כפיים',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('template_type', 'supplier_invoices_request')->delete();
    }
};
