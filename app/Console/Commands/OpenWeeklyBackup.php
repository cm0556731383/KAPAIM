<?php

namespace App\Console\Commands;

use App\Services\WeeklyBackup;
use Illuminate\Console\Command;

/**
 * Used by move-import.sh to restore from a weekly backup zip: reads the
 * password from stdin and extracts it into {dir}.
 */
class OpenWeeklyBackup extends Command
{
    protected $signature = 'backup:open-weekly {zip} {dir}';

    protected $description = 'Extract a weekly backup zip (password on stdin)';

    public function handle(): int
    {
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");

        if (! WeeklyBackup::open($this->argument('zip'), $password, $this->argument('dir'))) {
            $this->error('הסיסמה שגויה, או שהקובץ אינו גיבוי שבועי של המערכת.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
