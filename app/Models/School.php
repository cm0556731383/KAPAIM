<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCHOOL is its own entity separate from LEAD (build-plan 04) so a repeat
 * inquiry from the same school updates the existing lead instead of
 * creating a new one (FR-1.9) — see Lead::findDuplicateSchool() /
 * Lead::findFuzzyDuplicateSchool().
 */
#[Fillable(['name', 'phone', 'email', 'city', 'address'])]
class School extends Model
{
    use HasFactory;

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
