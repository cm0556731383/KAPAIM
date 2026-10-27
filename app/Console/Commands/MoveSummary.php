<?php

namespace App\Console\Commands;

use App\Models\BusinessEntity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The check at the end of move-import.sh (DEPLOY.md, section 9): what came
 * over, whether every uploaded file the database points to is here, and
 * whether the SUMIT keys open with this server's APP_KEY.
 */
class MoveSummary extends Command
{
    protected $signature = 'app:move-summary';

    protected $description = 'Summarize the data on this server and check its files and encryption key';

    /** table => [column, disk] of every uploaded file the database points to. */
    private const FILE_COLUMNS = [
        'documents' => ['file_reference', 'local'],
        'material_delivery_attachments' => ['file_reference', 'local'],
        'mailing_list_send_attachments' => ['file_reference', 'local'],
        'schools' => ['logo_path', 'public'],
    ];

    public function handle(): int
    {
        $this->table(['מה', 'כמה'], collect([
            'users' => 'משתמשות',
            'leads' => 'לידים',
            'customers' => 'לקוחות',
            'deals' => 'עסקאות',
            'documents' => 'מסמכים',
            'expenses' => 'הוצאות',
            'material_deliveries' => 'משלוחי חומרים',
        ])->map(fn (string $label, string $table) => [$label, DB::table($table)->count()])->values()->all());

        $missing = collect(self::FILE_COLUMNS)->flatMap(fn (array $spec, string $table) => DB::table($table)
            ->whereNotNull($spec[0])
            ->pluck($spec[0])
            ->reject(fn (string $path) => Storage::disk($spec[1])->exists($path))
            ->map(fn (string $path) => "{$table}: {$path}"));

        $missing->isEmpty()
            ? $this->info('כל הקבצים שמסד הנתונים מפנה אליהם נמצאים כאן.')
            : $this->error("חסרים {$missing->count()} קבצים (לא הועתקו מ-storage/app):\n".$missing->take(20)->implode("\n"));

        $unreadable = BusinessEntity::whereNotNull('sumit_api_key')->get()->filter(function (BusinessEntity $entity) {
            try {
                $entity->sumit_api_key;

                return false;
            } catch (\Illuminate\Contracts\Encryption\DecryptException) {
                return true;
            }
        });

        $unreadable->isEmpty()
            ? $this->info('מפתחות SUMIT נפתחים עם מפתח ההצפנה של השרת הזה.')
            : $this->error('מפתחות SUMIT לא נפתחים ('.$unreadable->pluck('name')->implode(', ').') — APP_KEY ב-.env אינו של השרת הישן.');

        return $missing->isEmpty() && $unreadable->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
