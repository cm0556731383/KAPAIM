<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Build-plan 12 — per-system config, read by App\Services\Integrations\SmoveClient
 * /SummitClient (base_url/api_key/webhook_secret, plus a couple of per-key
 * knobs like 'material_reminder_hours' — see ProcessMaterialReminders) and by
 * verifyWebhookSecret() below for every inbound webhook route. Seeded with
 * is_active=false and empty settings (ReferenceDataSeeder) — deliberately
 * left for the business owner to fill in for real via ⚡settings.blade.php;
 * every client/webhook here treats "not configured" as a normal, expected
 * failure state, never a crash.
 */
#[Fillable(['system', 'is_active', 'settings'])]
class ExternalIntegrationSetting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * Every inbound webhook (landing page, Smove material-opened, Summit
     * standing-order-collected) is a public, unauthenticated URL by
     * necessity — this is its only gate. A webhook_secret that is blank
     * (the seeded default) NEVER matches anything, including an
     * empty/missing provided value — so a webhook stays hard-403'd until the
     * business owner actually sets a secret in Settings, rather than silently
     * accepting unauthenticated requests in the meantime.
     */
    public static function verifyWebhookSecret(string $system, ?string $provided): bool
    {
        $configured = (string) (self::where('system', $system)->first()?->settings['webhook_secret'] ?? '');

        if ($configured === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($configured, $provided);
    }
}
