<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Ends every active subscription whose 10 Hebrew months are over —
 * Subscription::endExpired(). Registered daily in routes/console.php.
 */
class EndExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:end-expired';

    protected $description = 'Mark active subscriptions whose 10 months are over as ended';

    public function handle(ActivityLogger $activityLogger): int
    {
        $count = Subscription::endExpired($activityLogger);

        $this->info("Ended {$count} subscription(s).");

        return self::SUCCESS;
    }
}
