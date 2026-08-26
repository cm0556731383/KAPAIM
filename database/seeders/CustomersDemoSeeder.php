<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\School;
use App\Services\ActivityLogger;
use Illuminate\Database\Seeder;

/**
 * Build-plan 05 demo data: converts a couple of LeadsDemoSeeder's leads into
 * real customers, the same "example from the storyboard" spirit as the other
 * demo seeders — docs/storyboard/customer-deal.html uses "בית ספר יובלים" as
 * its example customer, so that's the one converted here.
 */
class CustomersDemoSeeder extends Seeder
{
    public function run(): void
    {
        $activityLogger = app(ActivityLogger::class);

        // ----- בית ספר יובלים — כבר בתהליך מכירה מתקדם, הופך ללקוחה (docs/storyboard/customer-deal.html) -----
        $yuvalim = School::where('name', 'בית ספר יובלים')->first();
        $yuvalimLead = $yuvalim ? Lead::where('school_id', $yuvalim->id)->first() : null;
        $yuvalimLead?->convertToCustomer($activityLogger);

        // ----- בית ספר האלה — מנוי שנתי, לקוחה שנייה לדוגמה -----
        $haela = School::where('name', 'בית ספר האלה')->first();
        $haelaLead = $haela ? Lead::where('school_id', $haela->id)->first() : null;
        $haelaLead?->convertToCustomer($activityLogger);
    }
}
