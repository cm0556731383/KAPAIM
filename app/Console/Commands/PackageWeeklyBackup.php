<?php

namespace App\Console\Commands;

use App\Services\WeeklyBackup;
use Illuminate\Console\Command;

/**
 * Called by backup-database.sh every Sunday with the night's database dump:
 * packs it with the uploaded files into this week's password zip (App\Services\WeeklyBackup), which
 * then downloads by itself the next time the chosen user opens the system.
 */
class PackageWeeklyBackup extends Command
{
    protected $signature = 'backup:weekly-package {dump : path to the pg_dump file} {--app-version=unknown : git commit of the code}';

    protected $description = 'Pack the database dump and uploaded files into the weekly password-protected zip';

    public function handle(WeeklyBackup $backup): int
    {
        if (! $backup->hasPassword()) {
            $this->warn('Weekly backup skipped: no password set yet (settings → גיבוי).');

            return self::SUCCESS;
        }

        $path = $backup->package($this->argument('dump'), (string) $this->option('app-version'));
        $this->info('Weekly backup ready: '.$path.' ('.round(filesize($path) / 1048576, 1).'MB)');
        if (! $backup->recipient()) {
            $this->warn('No user chosen to receive it yet (settings → גיבוי).');
        }

        return self::SUCCESS;
    }
}
