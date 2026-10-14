<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Build-plan 02/12 — EMAIL_TEMPLATE: operational emails the system sends
 * directly via SMTP (Laravel Mail, config/mail.php — 'log' driver until the
 * business owner configures a real one), never via Smove (see this model's
 * settings-screen note distinguishing the two). render() below is this
 * stage's addition — a pure, stateless {{key}}-placeholder substitution
 * (matching ReferenceDataSeeder's seeded 'אישור קליטת ליד' template content
 * exactly), used by Lead::createFromLandingPage() (FR-1.18).
 */
#[Fillable(['name', 'template_type', 'subject', 'content', 'is_active'])]
class EmailTemplate extends Model
{
    use HasFactory;

    /**
     * What a template is used for, by Hebrew label. The key is what the code
     * looks up (e.g. Lead::sendLandingPageConfirmation() wants
     * 'lead_confirmation'); 'general' is a template nothing sends automatically.
     */
    public const TYPE_LABELS = [
        'lead_confirmation' => 'אישור קליטת ליד (נשלח אוטומטית)',
        'general' => 'כללי',
    ];

    /**
     * The Hebrew placeholders a template's subject/content can use, mapped to
     * the data key render() receives. The older English spelling
     * ({{contact_name}}) still works — render() accepts both.
     */
    public const PLACEHOLDERS = [
        'שם_איש_הקשר' => 'contact_name',
        'שם_בית_הספר' => 'school_name',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(EmailTemplateField::class)->orderBy('sort_order');
    }

    /**
     * @param  array<string, string>  $data  e.g. ['contact_name' => ..., 'school_name' => ...]
     * @return array{subject: string, content: string}
     */
    public function render(array $data): array
    {
        $replace = static fn (string $text): string => preg_replace_callback(
            '/\{\{\s*([\p{L}\p{N}_.]+)\s*\}\}/u',
            fn ($m) => $data[self::PLACEHOLDERS[$m[1]] ?? $m[1]] ?? $m[0],
            $text,
        );

        return ['subject' => $replace($this->subject), 'content' => $replace($this->content)];
    }
}
