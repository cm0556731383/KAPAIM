<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Program;
use App\Services\ActivityLogger;
use Illuminate\Database\Seeder;

/**
 * Build-plan 06 demo data: gives בית ספר יובלים (converted by
 * CustomersDemoSeeder) a real demo deal — the "מנוי שנתי" purchase shown as
 * "עסקה #1042 — מנוי שנתי · ₪4,200" in docs/storyboard/customer-deal.html.
 */
class DealsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $activityLogger = app(ActivityLogger::class);

        $yuvalim = Customer::whereHas('school', fn ($q) => $q->where('name', 'בית ספר יובלים'))->first();
        $subscriptionProgram = Program::where('name', 'מנוי שנתי — 10 תוכניות')->first();

        if (! $yuvalim || ! $subscriptionProgram) {
            return;
        }

        $deal = Deal::createForCustomer($yuvalim, $subscriptionProgram, null);

        $activityLogger->log('deal.created', "נוצרה עסקה חדשה #{$deal->id} עבור לקוחה \"{$yuvalim->school->name}\": {$deal->program_name_snapshot}", [
            'deal_id' => $deal->id, 'customer_id' => $yuvalim->id,
        ]);
    }
}
