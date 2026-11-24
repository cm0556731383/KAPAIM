<?php

namespace App\Console\Commands;

use App\Services\WeeklyBackup;
use Illuminate\Console\Command;

/**
 * Called by backup-database.sh every Sunday with a move-export-style bundle:
 * locks it in this week's password zip (App\Services\WeeklyBackup), which
 * then downloads by itself the next time the chosen user opens the system.
 */
class PackageWeeklyBackup extends Command
{
    protected $signature = 'backup:weekly-package {bundle : path to the .tar.gz bundle}';

    protected $description = 'Wrap a backup bundle into the weekly password-protected zip that downloads to the owner';

    public function handle(WeeklyBackup $backup): int
    {
        if (! $backup->hasPassword()) {
            $this->warn('Weekly backup skipped: no password set yet (settings → גיבוי).');

            return self::SUCCESS;
        }

        $path = $backup->package($this->argument('bundle'));
        $this->info('Weekly backup ready: '.$path.' ('.round(filesize($path) / 1048576, 1).'MB)');
        if (! $backup->recipient()) {
            $this->warn('No user chosen to receive it yet (settings → גיבוי).');
        }

        return self::SUCCESS;
    }
}
