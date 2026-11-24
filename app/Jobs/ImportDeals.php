<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\DealImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs a deal import (settings → יבוא נתונים → עסקאות) in the background —
 * each deal joins mailing lists through Smove, too slow for the click itself
 * on a few hundred rows. The file is checked again here from scratch (the
 * screen only shows the check), and progress is kept in the cache under
 * self::cacheKey($importId) for the screen to poll.
 */
class ImportDeals implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $importId, public string $path, public int $userId) {}

    public static function cacheKey(string $importId): string
    {
        return "deal-import:{$importId}";
    }

    public static function status(string $importId): ?array
    {
        return Cache::get(self::cacheKey($importId));
    }

    public function handle(DealImport $import, ActivityLogger $activityLogger): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        Auth::setUser($user);

        try {
            $analysis = $import->analyze(Storage::disk('local')->path($this->path));

            if ($analysis['fileError']) {
                $this->put(['state' => 'error', 'message' => 'היבוא לא בוצע: '.$analysis['fileError']]);

                return;
            }

            $rows = $analysis['rows'];
            $valid = count(array_filter($rows, fn ($row) => $row['data'] !== null));

            $this->put(['state' => 'running', 'total' => $valid, 'done' => 0]);

            $result = $import->import($rows, $user, $activityLogger, fn (int $done) => $this->put(['state' => 'running', 'total' => $valid, 'done' => $done]));

            $this->put([
                'state' => 'done',
                'total' => $valid,
                'done' => $valid,
                'created' => count($result['created']),
                'failed' => $result['failed'],
                'skipped' => count($rows) - $valid,
            ]);
        } catch (Throwable $e) {
            report($e);
            $this->put(['state' => 'error', 'message' => 'היבוא נעצר בגלל שגיאה לא צפויה. עסקאות שכבר יובאו נשמרו.']);
        } finally {
            Storage::disk('local')->delete($this->path);
        }
    }

    private function put(array $status): void
    {
        Cache::put(self::cacheKey($this->importId), $status, now()->addDay());
    }
}
