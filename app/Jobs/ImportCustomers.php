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
 * Smove list is read, and each new customer is linked to it or joins it — a
 * call to Smove per contact, so a few hundred rows are far too slow for the
 * click itself. The file is checked
 * again here — the screen only shows the check, it never hands over the rows —
 * and progress is kept in the cache under self::cacheKey($importId) for the
 * screen to poll.
 */
class ImportCustomers implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $importId, public string $path, public int $userId) {}

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
            $rows = $import->analyze(Storage::disk('local')->path($this->path))['rows'];
            $valid = count(array_filter($rows, fn ($row) => $row['data'] !== null));

            $this->put(['state' => 'running', 'total' => $valid, 'done' => 0]);

            // Read once, up front: who is already on the Smove list gets linked, not re-joined.
            $smove = $import->smoveList();

            $result = $import->import($rows, $user, $activityLogger, fn (int $done) => $this->put(['state' => 'running', 'total' => $valid, 'done' => $done]), $smove);

            $this->put([
                'state' => 'done',
                'total' => $valid,
                'done' => $valid,
                'created' => count($result['created']),
                'failed' => $result['failed'],
                'smove' => ['state' => $smove['state'], 'message' => $smove['message'] ?? null] + $result['smove'],
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
