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
