<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SCHOOL is its own entity separate from LEAD (build-plan 04) so a repeat
 * inquiry from the same school updates the existing lead instead of
 * creating a new one (FR-1.9) — see Lead::findDuplicateSchool() /
 * Lead::findFuzzyDuplicateSchool(). FR-2.1: a school has at most one
 * CUSTOMER card for the lifetime of the relationship (build-plan 05) — see
 * customer() below.
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

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }
}
