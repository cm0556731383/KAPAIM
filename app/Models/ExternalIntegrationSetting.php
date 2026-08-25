<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Config shell for external systems (Smove, Summit). Actually wired up to make
 * real calls in build-plan stage 12.
 */
#[Fillable(['system', 'is_active', 'settings'])]
class ExternalIntegrationSetting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }
}
