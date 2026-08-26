<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build-plan 07 — DOCUMENT_LINE. Only ever created/removed through
 * Document::addLine()/removeLine(), which enforce FR-4.17/FR-4.18/FR-8.10.
 */
#[Fillable(['document_id', 'description', 'quantity', 'unit_price', 'amount', 'sort_order'])]
class DocumentLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
