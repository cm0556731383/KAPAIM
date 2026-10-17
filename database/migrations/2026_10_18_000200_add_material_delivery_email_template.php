<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The materials email itself (MaterialDelivery::sendFor()) as the
     * business owner's 'material_delivery' template — the same wording it
     * had in code, with the materials list at {{רשימת_החומרים}} and the
     * "received" button at {{כפתור_אישור_קבלה}}.
     */
    public function up(): void
    {
        if (DB::table('email_templates')->where('template_type', 'material_delivery')->exists()) {
            return;
        }

        DB::table('email_templates')->insert([
            'name' => 'שליחת חומרי לימוד',
            'template_type' => 'material_delivery',
            'subject' => 'חומרי לימוד: {{תוכנית}}',
            'content' => "שלום,\n\n"
                ."מצורפים קישורים לחומרי הלימוד:\n\n"
                ."{{רשימת_החומרים}}\n\n"
                ."אחרי שהורדתם את החומרים, נשמח לאישור שהם התקבלו:\n\n"
                ."{{כפתור_אישור_קבלה}}\n\n"
                .'בברכה, כפיים',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('template_type', 'material_delivery')->delete();
    }
};
