<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['scope', 'name', 'is_active', 'sort_order'])]
class StatusDefinition extends Model
{
    use HasFactory;

    /**
     * Status names the code itself looks up or colours by name (the
     * *_STATUS_NAME constants, Lead's traffic-light map, collection tasks'
     * "נשלחה חשבונית") — renaming one would silently break that logic, so
     * the settings page only lets these be re-ordered, never renamed.
     */
    public const SYSTEM_NAMES = [
        'lead' => ['חדש', 'בתהליך מכירה', 'מוכנה לסגור / רוצה לרכוש', 'נסגר ללא מכירה'],
        'customer' => ['פעילה'],
        'deal' => ['פתוחה', 'נשלחה חשבונית', 'שולמה', 'מבוטלת'],
        'document' => ['טיוטה'],
        'subscription' => ['פעיל', 'הסתיים', 'בוטל'],
        'expense' => ['ממתינה לחשבונית', 'תועדה'],
        'material_delivery' => ['טרם נפתח', 'נפתח', 'אושר שהתקבל'],
    ];

    public function isSystem(): bool
    {
        return in_array($this->name, self::SYSTEM_NAMES[$this->scope] ?? [], true);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
