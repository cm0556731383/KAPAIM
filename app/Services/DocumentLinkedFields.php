<?php

namespace App\Services;

use App\Models\Deal;

/**
 * Build-plan 07 (FR-4.10): the fixed, deliberately small set of "linked"
 * fields a DOCUMENT_TEMPLATE_FIELD can point at — a concrete list instead of
 * a generic field-mapping DSL. Keys here are stored verbatim in
 * document_template_fields.linked_field and inside documents.field_values.
 */
class DocumentLinkedFields
{
    /** value => Hebrew label, shown in the template-field editor's picker. */
    public const OPTIONS = [
        'customer.school_name' => 'שם בית הספר (לקוחה)',
        'customer.school_address' => 'כתובת בית הספר (לקוחה)',
        'deal.agreed_amount' => 'סכום העסקה המוסכם',
        'deal.program_name' => 'שם התוכנית/המארז שנרכש',
    ];

    /**
     * FR-4.12's writable subset — only fields backed by a real, editable
     * business record can be updated by a submitted form value.
     * deal.agreed_amount / deal.program_name are frozen sale-time snapshots
     * (Deal::createForCustomer()) and are deliberately never written back.
     */
    private const WRITABLE = ['customer.school_name', 'customer.school_address'];

    public static function resolve(string $key, Deal $deal): ?string
    {
        $deal->loadMissing('customer.school');

        return match ($key) {
            'customer.school_name' => $deal->customer?->school?->name,
            'customer.school_address' => $deal->customer?->school?->address,
            'deal.agreed_amount' => (string) $deal->agreed_amount,
            'deal.program_name' => $deal->program_name_snapshot ?? $deal->bundle_name_snapshot,
            default => null,
        };
    }

    /**
     * FR-4.12: a value entered for a linked field updates the underlying
     * business record after the digital form is submitted.
     */
    public static function applyBack(string $key, Deal $deal, string $value): void
    {
        if (! in_array($key, self::WRITABLE, true)) {
            return;
        }

        $deal->loadMissing('customer.school');
        $school = $deal->customer?->school;

        if (! $school) {
            return;
        }

        match ($key) {
            'customer.school_name' => $school->update(['name' => $value]),
            'customer.school_address' => $school->update(['address' => $value]),
            default => null,
        };
    }
}
