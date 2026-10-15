<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "שליחת בקשה לחשבונית" on the expenses page: emails the supplier a link
     * to upload the missing invoice themselves (Expense::requestInvoice()).
     * invoice_requested_at is when the last such email went out; the email's
     * wording is the one 'supplier_invoice_request' EMAIL_TEMPLATE, edited on
     * the document-templates page — seeded here so it exists from day one.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->timestamp('invoice_requested_at')->nullable();
        });

        if (! DB::table('email_templates')->where('template_type', 'supplier_invoice_request')->exists()) {
            DB::table('email_templates')->insert([
                'name' => 'בקשת חשבונית מספק',
                'template_type' => 'supplier_invoice_request',
                'subject' => 'בקשה לחשבונית עבור תשלום מתאריך {{תאריך_התשלום}}',
                'content' => "שלום {{שם_הספק}},\n\n"
                    ."ביום {{תאריך_התשלום}} שילמנו לכם ₪{{סכום}}, ועדיין לא התקבלה אצלנו חשבונית עבור התשלום הזה.\n\n"
                    ."נודה לכם אם תעלו את החשבונית (קובץ PDF או תמונה) בלחיצה על הכפתור — היא תגיע ישירות אלינו:\n\n"
                    ."{{כפתור_העלאה}}\n\n"
                    .'בברכה, צוות כפיים',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->where('template_type', 'supplier_invoice_request')->delete();

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('invoice_requested_at');
        });
    }
};
