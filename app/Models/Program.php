<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * is_subscription_type moved to Bundle 2026-09-09 — see Bundle's own
 * docblock and Deal::openSubscriptionIfApplicable().
 */
#[Fillable(['name', 'description', 'price', 'is_premium', 'is_active'])]
class Program extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_premium' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class);
    }
}
