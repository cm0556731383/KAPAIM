<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\CustomerImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs a customer import (settings → יבוא נתונים) in the background: the
 * file is checked against the Smove list again and each customer is created
 * and linked to it, too slow for the click itself on a few hundred rows. The file is checked
 * again here — the screen only shows the check, it never hands over the rows —
 * and progress is kept in the cache under self::cacheKey($importId) for the
 * screen to poll.
 */
class ImportCustomers implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /** $approveUnlisted: the user approved opening contacts not found on the Smove list (they join it). */
    public function __construct(public string $importId, public string $path, public int $userId, public bool $approveUnlisted = false) {}

    public static function cacheKey(string $importId): string
    {
        return "customer-import:{$importId}";
    }

    public static function status(string $importId): ?array
    {
        return Cache::get(self::cacheKey($importId));
    }

    public function handle(CustomerImport $import, ActivityLogger $activityLogger): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        // Whatever the import logs is in her name, as if she'd done it from the screen.
        Auth::setUser($user);

        try {
            // Checked again from scratch, Smove list included: a contact that
            // left the list since the check screen is a row error now too.
            $analysis = $import->analyze(Storage::disk('local')->path($this->path));

            if ($analysis['fileError']) {
                $this->put(['state' => 'error', 'message' => 'היבוא לא בוצע: '.$analysis['fileError']]);

                return;
            }

            $rows = $analysis['rows'];
            $smove = $analysis['smove'];
            $valid = count(array_filter($rows, fn ($row) => $row['data'] !== null));

            $this->put(['state' => 'running', 'total' => $valid, 'done' => 0]);

            $result = $import->import($rows, $user, $activityLogger, fn (int $done) => $this->put(['state' => 'running', 'total' => $valid, 'done' => $done]), $smove, $this->approveUnlisted);

            $this->put([
                'state' => 'done',
                'total' => $valid,
                'done' => $valid,
                'created' => count($result['created']),
                'failed' => $result['failed'],
                'smove' => $result['smove'],
                // Rows that failed the check here (e.g. a contact removed from Smove since the check screen).
                'skipped' => count($rows) - $valid,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->put(['state' => 'error', 'message' => 'היבוא נעצר בגלל שגיאה לא צפויה. לקוחות שכבר יובאו נשמרו.']);
        } finally {
            Storage::disk('local')->delete($this->path);
        }
    }

    private function put(array $status): void
    {
        Cache::put(self::cacheKey($this->importId), $status, now()->addDay());
    }
}
