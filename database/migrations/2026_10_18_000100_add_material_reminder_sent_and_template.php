<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A delivery gets one reminder (materials:process-reminders) —
     * reminder_sent_at marks it sent. Its wording is the business owner's
     * 'material_reminder' email template (settings → תבניות דואר), with the
     * "received" button where {{כפתור_אישור_קבלה}} sits.
     */
    public function up(): void
    {
        Schema::table('material_deliveries', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('handled_at');
        });

        if (DB::table('email_templates')->where('template_type', 'material_reminder')->exists()) {
            return;
        }

        DB::table('email_templates')->insert([
            'name' => 'תזכורת חומרי לימוד',
            'template_type' => 'material_reminder',
            'subject' => 'תזכורת: חומרי הלימוד "{{תוכנית}}" ממתינים לכם',
            'content' => "שלום,\n\n"
                ."לפני כמה ימים שלחנו אליכם את חומרי הלימוד \"{{תוכנית}}\", ועדיין לא קיבלנו אישור שהם הגיעו.\n"
                ."נשמח אם תאשרו את קבלתם בלחיצה על הכפתור:\n\n"
                ."{{כפתור_אישור_קבלה}}\n\n"
                .'בברכה, כפיים',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('template_type', 'material_reminder')->delete();

        Schema::table('material_deliveries', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
