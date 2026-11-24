<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One program or bundle sold in a deal (2026-11-24) — exactly one of
 * program_id/bundle_id. name_snapshot / list_price freeze the catalog name
 * and the classes-tier price at the moment of sale (FR-3.6); amount is what
 * was agreed for this line. Created and changed only through Deal
 * (createWithItems() / replaceItems()).
 */
#[Fillable(['deal_id', 'program_id', 'bundle_id', 'name_snapshot', 'list_price', 'amount', 'sort_order'])]
class DealItem extends Model
{
    protected function casts(): array
    {
        return [
            'list_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    /** The catalog item — a Program or a Bundle. */
    public function item(): Program|Bundle|null
    {
        return $this->program ?? $this->bundle;
    }

    /** "program:{id}" / "bundle:{id}" — the key the screens pick items by. */
    public function itemKey(): string
    {
        return $this->program_id ? "program:{$this->program_id}" : "bundle:{$this->bundle_id}";
    }

    /** Its name as listed: a bundle's is prefixed "מארז:" only where it'd be ambiguous — so plain here. */
    public function label(): string
    {
        return $this->name_snapshot;
    }

    public function isSubscriptionBundle(): bool
    {
        return (bool) $this->bundle?->is_subscription_type;
    }
}
