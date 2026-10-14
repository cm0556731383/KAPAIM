<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build-plan 12 — EXTERNAL_OPERATION, the audit ledger for every real call
 * to/from Smove or Summit (plus the landing-page webhook — see the
 * migration's docblock). The only place a row here is ever created is
 * App\Services\Integrations\ExternalOperationRunner::run() — never directly.
 */
#[Fillable([
    'document_id', 'expense_id', 'material_delivery_id', 'lead_id', 'deal_id', 'payment_id',
    'integration_setting_id', 'triggered_by_user_id', 'system', 'operation_type',
    'trigger_source', 'status', 'external_reference', 'error_message', 'attempted_at', 'completed_at',
])]
class ExternalOperation extends Model
{
    use HasFactory;

    /** How each connected system is named on screen, instead of its technical key. */
    public const SYSTEM_LABELS = ['summit' => 'SUMIT', 'smove' => 'Smove', 'landing_page' => 'דף הנחיתה'];

    /** How each operation type is named on screen, instead of its technical key. */
    public const OPERATION_LABELS = [
        'issue_invoice' => 'הפקת חשבונית',
        'issue_receipt' => 'הפקת קבלה',
        'issue_credit_note' => 'הפקת חשבונית זיכוי',
        'card_charge' => 'חיוב כרטיס אשראי',
        'standing_order_registered' => 'הקמת הוראת קבע',
        'standing_order_stopped' => 'עצירת הוראת קבע',
        'payment_link_created' => 'יצירת קישור לתשלום',
        'payment_link_send' => 'שליחת קישור לתשלום',
        'document_send' => 'שליחת מסמך',
        'materials_send' => 'שליחת חומרי לימוד',
        'material_reminder' => 'תזכורת על חומרי לימוד',
        'expense_invoice_reminder' => 'תזכורת על חשבונית הוצאה',
        'lead_confirmation' => 'מייל אישור לליד',
        'mailing_list_join' => 'הצטרפות לרשימת תפוצה',
        'mailing_list_remove' => 'הסרה מרשימת תפוצה',
        'mailing_list_email' => 'שליחת מייל לרשימות',
        'mailing_list_email_test' => 'מייל בדיקה לרשימות',
        'payments_sync' => 'סנכרון תשלומים',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function materialDelivery(): BelongsTo
    {
        return $this->belongsTo(MaterialDelivery::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function integrationSetting(): BelongsTo
    {
        return $this->belongsTo(ExternalIntegrationSetting::class, 'integration_setting_id');
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function succeeded(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
