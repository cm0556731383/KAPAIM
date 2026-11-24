<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use ZipArchive;

/**
 * The weekly backup that downloads by itself to the owner's computer
 * (2026-11-24). backup-database.sh builds, every Sunday, the same bundle
 * move-export.sh makes (database + uploaded files + APP_KEY, restorable with
 * move-import.sh) and hands it to `backup:weekly-package`, which wraps it in
 * an AES-256 zip locked with the password set in settings → "גיבוי" and keeps
 * it in backups/weekly/. The first time the chosen user opens the system
 * after that, the layout starts the download in her browser — into whatever
 * folder her browser downloads to. Nothing is sent anywhere else.
 */
class WeeklyBackup
{
    private const PASSWORD_KEY = 'weekly_backup.password';

    private const USER_KEY = 'weekly_backup.user_id';

    private const DOWNLOADED_KEY = 'weekly_backup.downloaded';

    /** Packages kept on the server — the newest one or two. */
    private const KEEP = 2;

    public const MIN_PASSWORD_LENGTH = 8;

    /** The file name inside the zip — a move-export.sh bundle. */
    public const INNER_NAME = 'kapaim-backup.tar.gz';

    /** $directory only for tests — the real one is backups/weekly. */
    public function __construct(private ?string $directory = null) {}

    public function directory(): string
    {
        return $this->directory ?? base_path('backups/weekly');
    }

    public function hasPassword(): bool
    {
        return $this->password() !== null;
    }

    public function setPassword(string $password): void
    {
        AppSetting::put(self::PASSWORD_KEY, Crypt::encryptString($password));
    }

    private function password(): ?string
    {
        $stored = AppSetting::get(self::PASSWORD_KEY);

        return $stored ? Crypt::decryptString($stored) : null;
    }

    public function recipient(): ?User
    {
        $id = AppSetting::get(self::USER_KEY);

        return $id ? User::where('is_active', true)->find($id) : null;
    }

    public function setRecipient(?int $userId): void
    {
        AppSetting::put(self::USER_KEY, $userId ? (string) $userId : null);
    }

    /** Active only with a password and someone to receive it. */
    public function isActive(): bool
    {
        return $this->hasPassword() && $this->recipient() !== null;
    }

    /**
     * Wraps a move-export-style bundle into this week's locked zip and
     * prunes old ones.
     *
     * @return string the zip's path
     */
    public function package(string $bundlePath): string
    {
        $password = $this->password() ?? throw new RuntimeException('לא הוגדרה סיסמה לגיבוי השבועי (הגדרות → גיבוי).');
        if (! is_file($bundlePath)) {
            throw new RuntimeException("Bundle not found: {$bundlePath}");
        }

        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0700, true);
        }

        $path = $this->directory().'/kapaim-backup_'.now()->format('Y-m-d_H-i').'.zip';
        $partial = $path.'.partial';
        @unlink($partial);

        $zip = new ZipArchive;
        if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$partial}");
        }
        $zip->setPassword($password);
        $zip->addFile($bundlePath, self::INNER_NAME);
        // Already gzip'd — storing beats compressing it twice.
        $zip->setCompressionName(self::INNER_NAME, ZipArchive::CM_STORE);
        $zip->setEncryptionName(self::INNER_NAME, ZipArchive::EM_AES_256);
        if (! $zip->close()) {
            @unlink($partial);
            throw new RuntimeException('Writing the backup zip failed.');
        }

        rename($partial, $path);
        chmod($path, 0600);

        foreach (array_slice($this->packages(), self::KEEP) as $old) {
            @unlink($old);
        }

        return $path;
    }

    /** Extracts the bundle from a weekly zip (restore) — false on a wrong password. */
    public static function open(string $zipPath, string $password, string $bundlePath): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Not a backup zip: {$zipPath}");
        }
        $zip->setPassword($password);
        $in = $zip->getStream(self::INNER_NAME);
        if ($in === false) {
            $zip->close();

            return false;
        }

        $out = fopen($bundlePath, 'wb');
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $ok = $copied > 0 && $zip->getStatusString() !== false && self::isGzip($bundlePath);
        $zip->close();

        if (! $ok) {
            @unlink($bundlePath);
        }

        return $ok;
    }

    private static function isGzip(string $path): bool
    {
        $h = fopen($path, 'rb');
        $magic = fread($h, 2);
        fclose($h);

        return $magic === "\x1f\x8b";
    }

    /** @return list<string> newest first */
    private function packages(): array
    {
        $files = glob($this->directory().'/kapaim-backup_*.zip') ?: [];
        rsort($files);

        return $files;
    }

    /** @return array{path: string, name: string, created_at: Carbon, size: int}|null */
    public function latest(): ?array
    {
        $path = $this->packages()[0] ?? null;

        return $path ? [
            'path' => $path,
            'name' => basename($path),
            'created_at' => Carbon::createFromTimestamp(filemtime($path))->setTimezone(config('app.timezone')),
            'size' => filesize($path),
        ] : null;
    }

    /** The package that should download by itself for $user now, if any. */
    public function pendingFor(User $user): ?string
    {
        if ($this->recipient()?->id !== $user->id) {
            return null;
        }

        $latest = $this->latest();

        return $latest && $latest['name'] !== $this->lastDownloaded()['name'] ? $latest['name'] : null;
    }

    public function markDownloaded(string $name, User $user): void
    {
        AppSetting::put(self::DOWNLOADED_KEY, json_encode(['name' => $name, 'at' => now()->toIso8601String(), 'user' => $user->name]));
    }

    /** @return array{name: ?string, at: ?Carbon, user: ?string} */
    public function lastDownloaded(): array
    {
        $data = json_decode((string) AppSetting::get(self::DOWNLOADED_KEY), true) ?: [];

        return [
            'name' => $data['name'] ?? null,
            'at' => isset($data['at']) ? Carbon::parse($data['at'])->setTimezone(config('app.timezone')) : null,
            'user' => $data['user'] ?? null,
        ];
    }
}
