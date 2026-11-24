<?php

namespace App\Console\Commands;

use App\Services\WeeklyBackup;
use Illuminate\Console\Command;

/**
 * Used by move-import.sh to restore from a weekly backup zip: reads the
 * password from stdin and writes the bundle inside it to {out}.
 */
class OpenWeeklyBackup extends Command
{
    protected $signature = 'backup:open-weekly {zip} {out}';

    protected $description = 'Extract the bundle from a weekly backup zip (password on stdin)';

    public function handle(): int
    {
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");

        if (! WeeklyBackup::open($this->argument('zip'), $password, $this->argument('out'))) {
            $this->error('הסיסמה שגויה, או שהקובץ אינו גיבוי שבועי של המערכת.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
