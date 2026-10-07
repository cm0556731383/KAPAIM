<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An issuing business (עוסק), chosen on every invoice. Each one is also its
 * own SUMIT company — sumit_company_id + sumit_api_key are that company's
 * API credentials (SummitClient), the key encrypted at rest.
 */
#[Fillable(['name', 'classification', 'company_number', 'email', 'phone', 'is_active', 'sumit_company_id', 'sumit_api_key'])]
#[Hidden(['sumit_api_key'])]
class BusinessEntity extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sumit_api_key' => 'encrypted',
        ];
    }

    public function hasSumitCredentials(): bool
    {
        return filled($this->sumit_company_id) && filled($this->sumit_api_key);
    }
}
