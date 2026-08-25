<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * is_subscription_type marks the one program (at most one active at a
 * time, enforced in the catalog Livewire component) whose "purchase" is
 * really an annual subscription (build-plan 03) — the discriminator
 * build-plan stage 6 (deal creation) will use to route into the
 * subscription flow (stage 9) instead of a plain one-off program sale.
 */
#[Fillable(['name', 'description', 'price', 'is_premium', 'is_subscription_type', 'is_active'])]
class Program extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_premium' => 'boolean',
            'is_subscription_type' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class);
    }
}
