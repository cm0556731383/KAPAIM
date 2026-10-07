<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Deal;

/**
 * Build-plan 07 (FR-4.10): the fixed set of "linked" fields a
 * DOCUMENT_TEMPLATE_FIELD can point at — everything shown on the customer
 * card (school details, contacts, the customer's own status/balance) plus
 * the document's deal and its subscription — a concrete list instead of a
 * generic field-mapping DSL. Keys here are stored verbatim in
 * document_template_fields.linked_field and inside documents.field_values,
 * so an existing key must never be renamed.
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
        'customer.school_invoice_name' => 'שם לחשבונית (לקוחה)',
        'customer.school_business_number' => 'ח.פ. (לקוחה)',
        'customer.school_syllable' => 'הברה (לקוחה)',
        'customer.school_classes_per_grade' => 'מספר כיתות בשנתון (לקוחה)',
        'customer.school_notes' => 'הערות (לקוחה)',
        'customer_info.since' => 'לקוחה מאז',
        'customer_info.status' => 'סטטוס הלקוחה',
        'customer_info.lead_source' => 'מקור הפנייה',
        'customer_info.assigned_user' => 'מטפלת בלקוחה',
        'customer_info.contacts_names' => 'כל אנשי הקשר',
        'customer_info.outstanding_balance' => 'יתרת חוב כוללת של הלקוחה',
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
        'deal.program_name' => 'שם התוכנית/המארז שנרכש',
        'deal.agreed_amount' => 'סכום העסקה המוסכם',
        'deal.students_count' => 'מספר תלמידות',
        'deal.list_price' => 'מחיר מחירון',
        'deal.discount' => 'הנחה (מחיר מחירון פחות הסכום המוסכם)',
        'deal.purchased_at' => 'תאריך פתיחת העסקה',
        'deal.status' => 'סטטוס העסקה',
        'deal.payment_method' => 'אמצעי תשלום',
        'deal.special_request' => 'בקשת התאמה מיוחדת',
        'deal.total_paid' => 'סכום ששולם בעסקה',
        'deal.outstanding_balance' => 'יתרה לתשלום בעסקה',
        'deal.completed_at' => 'תאריך השלמת העסקה',
        'subscription.start_date' => 'תאריך תחילת המנוי',
        'subscription.end_date' => 'תאריך סיום המנוי',
        'subscription.agreed_price' => 'מחיר המנוי שסוכם',
        'subscription.monthly_payment' => 'תשלום חודשי',
        'general.today' => 'תאריך היום',
    ];

    /** Headings for the quick-insert picker, keyed by OPTIONS key prefix, in display order. */
    public const GROUPS = [
        'customer' => 'פרטי בית הספר',
        'customer_info' => 'פרטי הלקוחה',
        'contact_primary' => 'איש קשר ראשי',
        'contact_accounting' => 'גורם חשבונאי',
        'deal' => 'העסקה',
        'subscription' => 'מנוי',
        'general' => 'כללי',
    ];

    /** customer.* key => SCHOOL column. */
    private const SCHOOL_COLUMNS = [
        'customer.school_name' => 'name',
        'customer.school_address' => 'address',
        'customer.school_city' => 'city',
        'customer.school_phone' => 'phone',
        'customer.school_email' => 'email',
        'customer.school_invoice_name' => 'invoice_name',
        'customer.school_business_number' => 'business_number',
        'customer.school_syllable' => 'syllable',
        'customer.school_classes_per_grade' => 'classes_per_grade',
        'customer.school_notes' => 'notes',
    ];

    /** contact_*.<column> — the CONTACT columns a template may reference. */
    private const CONTACT_COLUMNS = ['name', 'role', 'phone', 'phone_secondary', 'email', 'email_secondary'];

    public static function resolve(string $key, Deal $deal): ?string
    {
        $deal->loadMissing('customer.school', 'customer.contacts', 'customer.status', 'customer.lead.source', 'customer.lead.assignedUser', 'status', 'paymentMethod', 'subscription');

        if (isset(self::SCHOOL_COLUMNS[$key])) {
            $value = $deal->customer?->school?->{self::SCHOOL_COLUMNS[$key]};

            return $value === null ? null : (string) $value;
        }

        if ($column = self::contactColumn($key)) {
            return self::contactFor($key, $deal)?->{$column};
        }

        $customer = $deal->customer;
        $subscription = $deal->subscription;
        $listPrice = $deal->program_price_snapshot ?? $deal->bundle_price_snapshot;

        return match ($key) {
            'customer_info.since' => $customer?->converted_at?->format('d/m/Y'),
            'customer_info.status' => $customer?->status?->name,
            'customer_info.lead_source' => $customer?->lead?->source?->name,
            'customer_info.assigned_user' => $customer?->lead?->assignedUser?->name,
            'customer_info.contacts_names' => $customer?->contacts->sortByDesc('is_primary')->pluck('name')->filter()->implode(', ') ?: null,
            'customer_info.outstanding_balance' => $customer ? self::money($customer->outstandingBalance()) : null,
            'deal.program_name' => $deal->program_name_snapshot ?? $deal->bundle_name_snapshot,
            'deal.agreed_amount' => self::money($deal->agreed_amount),
            'deal.students_count' => $deal->students_count === null ? null : (string) $deal->students_count,
            'deal.list_price' => self::money($listPrice),
            'deal.discount' => $listPrice !== null && (float) $listPrice > (float) $deal->agreed_amount
                ? self::money((float) $listPrice - (float) $deal->agreed_amount)
                : null,
            'deal.purchased_at' => $deal->purchased_at?->format('d/m/Y'),
            'deal.status' => $deal->status?->name,
            'deal.payment_method' => $deal->paymentMethod?->name,
            'deal.special_request' => $deal->special_request,
            'deal.total_paid' => self::money($deal->totalPaid()),
            'deal.outstanding_balance' => self::money($deal->outstandingBalance()),
            'deal.completed_at' => $deal->completed_at?->format('d/m/Y'),
            'subscription.start_date' => $subscription?->start_date?->format('d/m/Y'),
            'subscription.end_date' => $subscription?->end_date?->format('d/m/Y'),
            'subscription.agreed_price' => $subscription ? self::money($subscription->agreed_price) : null,
            'subscription.monthly_payment' => $subscription ? self::money($subscription->monthlyPayment()) : null,
            'general.today' => now()->format('d/m/Y'),
            default => null,
        };
    }

    /**
     * FR-4.12: a value entered for a linked field updates the underlying
     * business record after the digital form is submitted. Only the
     * customer card's own editable details — plus the deal's student count,
     * which the customer is the one to know — are written back (isWritable());
     * everything else — the deal's terms, balances, statuses, dates — is a
     * computed or sale-time value the recipient can't change. A contact
     * field is written only when that contact already exists on the card
     * (a lone phone/email value isn't enough to create a new contact from).
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

            return;
        }

        if ($key === 'deal.students_count' && ctype_digit($value)) {
            $deal->update(['students_count' => (int) $value]);
        }
    }

    /** Whether a value submitted for this key is written back to the customer card (see applyBack()). */
    public static function isWritable(string $key): bool
    {
        return isset(self::SCHOOL_COLUMNS[$key]) || self::contactColumn($key) !== null || $key === 'deal.students_count';
    }

    /** Every option, for the template editor's grouped quick-insert picker (⚡document-templates.blade.php). */
    public static function pickerOptions(): array
    {
        return self::OPTIONS;
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

    /** "4,800" — or "4,800.50" when there are agorot. */
    private static function money(mixed $amount): ?string
    {
        if ($amount === null) {
            return null;
        }

        $amount = (float) $amount;

        return number_format($amount, floor($amount) == $amount ? 0 : 2);
    }
}
