<?php

namespace Database\Seeders;

use App\Models\Deal;
use App\Models\Program;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Seeder;

/**
 * Build-plan 09 demo data. DealsDemoSeeder's existing demo deal for בית ספר
 * יובלים already purchases "מנוי שנתי — 10 תוכניות" (the seeded
 * is_subscription_type bundle, moved here from Program 2026-09-09) — now that
 * Deal::openSubscriptionIfApplicable() is wired for real, that single deal
 * already produced a real SUBSCRIPTION row with 10 SUBSCRIPTION_DELIVERY
 * rows during DealsDemoSeeder, with no second deal needed here.
 *
 * This seeder's job is only to mark some of those deliveries supplied — a
 * real, non-zero example of the delivery log/monthly-payment/cancellation-
 * credit calculation in action, echoing docs/storyboard/subscription-cancel.html's
 * "8 מתוך 10 תוכניות סופקו" example for the same customer.
 */
class SubscriptionsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $activityLogger = app(ActivityLogger::class);

        $subscription = Subscription::whereHas('customer.school', fn ($q) => $q->where('name', 'בית ספר יובלים'))->first();
        $secretary = User::where('email', 'noa@kapaim.co.il')->first();

        if (! $subscription || ! $secretary) {
            return;
        }

        $suppliablePrograms = Program::where('is_active', true)->orderBy('id')->get();

        if ($suppliablePrograms->isEmpty()) {
            return;
        }

        $deliveriesToSupply = $subscription->deliveries()->orderBy('sequence_number')->limit(6)->get();

        foreach ($deliveriesToSupply as $index => $delivery) {
            $program = $suppliablePrograms[$index % $suppliablePrograms->count()];

            $subscription->markDeliverySupplied($subscription->version, $delivery->id, $program->id, $secretary);
            $subscription->refresh();
        }

        $activityLogger->log('subscription.demo_deliveries_seeded', "סומנו {$deliveriesToSupply->count()} תוכניות כסופקות במנוי #{$subscription->id}", [
            'subscription_id' => $subscription->id,
            'customer_id' => $subscription->customer_id,
            'deal_id' => $subscription->deal_id,
            'metadata' => ['supplied_count' => $deliveriesToSupply->count()],
        ]);
    }
}

