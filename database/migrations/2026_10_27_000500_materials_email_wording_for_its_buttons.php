<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The wording seeded on 2026-10-18 — its list/button tokens are now the two buttons under every materials email. */
    private const OLD_CONTENT = "שלום,\n\nמצורפים קישורים לחומרי הלימוד:\n\n{{רשימת_החומרים}}\n\nאחרי שהורדתם את החומרים, נשמח לאישור שהם התקבלו:\n\n{{כפתור_אישור_קבלה}}\n\nבברכה, כפיים";

    /** Only a wording the owner never changed — an edited one is hers. */
    public function up(): void
    {
        DB::table('email_templates')
            ->where('template_type', EmailTemplate::MATERIAL_DELIVERY)
            ->where('content', self::OLD_CONTENT)
            ->update(['content' => EmailTemplate::MATERIAL_DELIVERY_DEFAULT_CONTENT, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('template_type', EmailTemplate::MATERIAL_DELIVERY)
            ->where('content', EmailTemplate::MATERIAL_DELIVERY_DEFAULT_CONTENT)
            ->update(['content' => self::OLD_CONTENT, 'updated_at' => now()]);
    }
};
