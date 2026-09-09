<?php

namespace Database\Seeders;

use App\Models\Bundle;
use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * Build-plan 03 seed data. Names/prices are the example catalog shown in
 * docs/storyboard/programs-catalog.html — placeholder/example data pending
 * the business owner's real catalog, same spirit as build-plan 02's
 * "proposed pending approval" seed comments.
 */
class ProgramsCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $rimonim = Program::create([
            'name' => 'רימונים בסתיו — תוכנית חודשית',
            'description' => 'תוכנית חודשית לעונת הסתיו.',
            'price' => 420,
            'is_premium' => false,
            'is_active' => true,
        ]);

        $nitzanei = Program::create([
            'name' => 'ניצני חורף — תוכנית חודשית',
            'description' => 'תוכנית חודשית לעונת החורף.',
            'price' => 420,
            'is_premium' => false,
            'is_active' => true,
        ]);

        $yomYetzira = Program::create([
            'name' => 'יום יצירה מיוחד — פרימיום',
            'description' => 'תוכנית פרימיום לפי פנייה בלבד (FR-3.10).',
            'price' => 650,
            'is_premium' => true,
            'is_active' => true,
        ]);

        // Seeded inactive on purpose — a real example of a disabled program
        // that must still render correctly (disable ≠ delete, FR-3.6/FR-8.25).
        Program::create([
            'name' => 'סדנת אמנות בית-ספרית — פרימיום',
            'description' => 'תוכנית פרימיום לפי פנייה בלבד (FR-3.10).',
            'price' => 780,
            'is_premium' => true,
            'is_active' => false,
        ]);

        $hagei = Bundle::create([
            'name' => 'מארז חגי תשרי',
            'description' => 'מארז לחגי תשרי.',
            'price' => 980,
            'is_active' => true,
        ]);
        $hagei->programs()->attach([$rimonim->id, $yomYetzira->id]);

        $sofShana = Bundle::create([
            'name' => 'מארז סוף שנה',
            'description' => 'מארז לסיום שנת הלימודים.',
            'price' => 750,
            'is_active' => true,
        ]);
        $sofShana->programs()->attach([$nitzanei->id]);

        // At most one active is_subscription_type=true bundle at a time
        // (build-plan 03, moved here from Program 2026-09-09) — enforced for
        // real in ⚡programs-catalog.blade.php, not just by this seeder only
        // creating one.
        $manuiShnati = Bundle::create([
            'name' => 'מנוי שנתי — 10 תוכניות',
            'description' => 'מנוי שנתי המקנה זכאות ל-10 תוכניות חודשיות (FR-3.12).',
            'price' => 4200,
            'is_subscription_type' => true,
            'is_active' => true,
        ]);
        $manuiShnati->programs()->attach([$rimonim->id, $nitzanei->id]);
    }
}

