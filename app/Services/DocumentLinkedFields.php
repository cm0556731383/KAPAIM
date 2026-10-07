<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Deal;

/**
 * Build-plan 07 (FR-4.10): the fixed set of "linked" fields a
 * DOCUMENT_TEMPLATE_FIELD can point at — every customer-card detail
 * (school + primary/accounting contact) plus two deal snapshots — a concrete list instead of
 * a generic field-mapping DSL. Keys here are stored verbatim in
 * document_template_fields.linked_field and inside documents.field_values.
 */
class DocumentLinkedFields
{
    /** value => Hebrew label, shown in the template-field editor's picker. */
    public const OPTIONS = [
        'customer.school_name' => 'שם בית הספר (לקוחה)',
        'customer.school_address' => 'כתובת בית הספר (לקוחה)',
        'customer.school_city' => 'עיר (לקוחה)',
        'customer.school_phone' => 'טלפון (לקוחה)',
        'customer.school_email' => 'דוא"ל (לקוחה)',
        'customer.school_syllable' => 'הברה (לקוחה)',
        'customer.school_classes_per_grade' => 'מספר כיתות בשנתון (לקוחה)',
        'contact_primary.name' => 'שם איש הקשר הראשי',
        'contact_primary.role' => 'תפקיד איש הקשר הראשי',
        'contact_primary.phone' => 'טלפון איש הקשר הראשי',
        'contact_primary.phone_secondary' => 'טלפון נוסף של איש הקשר הראשי',
        'contact_primary.email' => 'דוא"ל איש הקשר הראשי',
        'contact_primary.email_secondary' => 'דוא"ל נוסף של איש הקשר הראשי',
        'contact_accounting.name' => 'שם הגורם החשבונאי',
        'contact_accounting.role' => 'תפקיד הגורם החשבונאי',
        'contact_accounting.phone' => 'טלפון הגורם החשבונאי',
        'contact_accounting.phone_secondary' => 'טלפון נוסף של הגורם החשבונאי',
        'contact_accounting.email' => 'דוא"ל הגורם החשבונאי',
        'contact_accounting.email_secondary' => 'דוא"ל נוסף של הגורם החשבונאי',
        'deal.agreed_amount' => 'סכום העסקה המוסכם',
        'deal.program_name' => 'שם התוכנית/המארז שנרכש',
    ];

    /** Headings for the quick-insert picker, keyed by OPTIONS key prefix. */
    public const GROUPS = [
        'customer' => 'פרטי בית הספר',
        'contact_primary' => 'איש קשר ראשי',
        'contact_accounting' => 'גורם חשבונאי',
    ];

    /** customer.* key => SCHOOL column. */
    private const SCHOOL_COLUMNS = [
        'customer.school_name' => 'name',
        'customer.school_address' => 'address',
        'customer.school_city' => 'city',
        'customer.school_phone' => 'phone',
        'customer.school_email' => 'email',
        'customer.school_syllable' => 'syllable',
        'customer.school_classes_per_grade' => 'classes_per_grade',
    ];

    /** contact_*.<column> — the CONTACT columns a template may reference. */
    private const CONTACT_COLUMNS = ['name', 'role', 'phone', 'phone_secondary', 'email', 'email_secondary'];

    public static function resolve(string $key, Deal $deal): ?string
    {
        $deal->loadMissing('customer.school', 'customer.contacts');

        if (isset(self::SCHOOL_COLUMNS[$key])) {
            $value = $deal->customer?->school?->{self::SCHOOL_COLUMNS[$key]};

            return $value === null ? null : (string) $value;
        }

        if ($column = self::contactColumn($key)) {
            return self::contactFor($key, $deal)?->{$column};
        }

        return match ($key) {
            'deal.agreed_amount' => (string) $deal->agreed_amount,
            'deal.program_name' => $deal->program_name_snapshot ?? $deal->bundle_name_snapshot,
            default => null,
        };
    }

    /**
     * FR-4.12: a value entered for a linked field updates the underlying
     * business record after the digital form is submitted. Only fields
     * backed by a real, editable record are written back —
     * deal.agreed_amount / deal.program_name are frozen sale-time snapshots
     * (Deal::createForCustomer()), and a contact field is written only when
     * that contact already exists on the customer card (a lone phone/email
     * value isn't enough to create a new contact from).
     */
    public static function applyBack(string $key, Deal $deal, string $value): void
    {
        $deal->loadMissing('customer.school', 'customer.contacts');

        if (isset(self::SCHOOL_COLUMNS[$key])) {
            $column = self::SCHOOL_COLUMNS[$key];

            if ($column === 'classes_per_grade' && ! ctype_digit($value)) {
                return;
            }

            $deal->customer?->school?->update([$column => $value]);

            return;
        }

        if ($column = self::contactColumn($key)) {
            self::contactFor($key, $deal)?->update([$column => $value]);
        }
    }

    private static function contactColumn(string $key): ?string
    {
        [$prefix, $column] = array_pad(explode('.', $key, 2), 2, null);

        return in_array($prefix, ['contact_primary', 'contact_accounting'], true) && in_array($column, self::CONTACT_COLUMNS, true)
            ? $column
            : null;
    }

    /** The primary contact falls back to the customer's first contact when none is flagged; the accounting contact has no fallback. */
    private static function contactFor(string $key, Deal $deal): ?Contact
    {
        $contacts = $deal->customer?->contacts?->sortBy('id');

        if (! $contacts) {
            return null;
        }

        return str_starts_with($key, 'contact_primary.')
            ? ($contacts->firstWhere('is_primary', true) ?? $contacts->first())
            : $contacts->firstWhere('is_accounting_contact', true);
    }

    /**
     * The subset shown in the template-editor's "quick insert" picker
     * (⚡document-templates.blade.php) — customer-card fields only (school
     * details and the primary/accounting contacts), never the deal-scoped ones above
     * (agreed_amount/program_name aren't part of the customer's own card,
     * they're specific to the sale) — those stay reachable only through the
     * older "הוספת שדה לתבנית" dropdown, which already uses real templates
     * (checked before removing anything from OPTIONS: templates 1-3 already
     * reference deal.agreed_amount/deal.program_name).
     */
    public static function customerCardOptions(): array
    {
        return array_filter(self::OPTIONS, fn ($key) => ! str_starts_with($key, 'deal.'), ARRAY_FILTER_USE_KEY);
    }
}
