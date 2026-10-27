<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The emails whose wording used to be fixed in the code — documents,
     * the order form + contract pair, payment / standing-order links and the
     * morning reminders — become templates in settings → תבניות דואר,
     * seeded with that same wording (EmailTemplate::SYSTEM_DEFAULTS).
     */
    public function up(): void
    {
        foreach (EmailTemplate::SYSTEM_DEFAULTS as $type => $template) {
            if (DB::table('email_templates')->where('template_type', $type)->exists()) {
                continue;
            }

            DB::table('email_templates')->insert([
                ...$template,
                'template_type' => $type,
                'content_format' => EmailTemplate::FORMAT_TEXT,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('template_type', array_keys(EmailTemplate::SYSTEM_DEFAULTS))->delete();
    }
};
