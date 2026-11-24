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
 * (2026-11-24). Every Sunday backup-database.sh dumps the database and
 * hands it to `backup:weekly-package`, which puts it, the uploaded files and
 * APP_KEY in a regular zip locked with the password set in settings →
 * "גיבוי" (opens in Windows as is; restorable with move-import.sh) and keeps
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

    /** Both only for tests — the real ones are backups/weekly and storage/app. */
    public function __construct(private ?string $directory = null, private ?string $filesRoot = null) {}

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

    /** What goes inside the zip besides the files — README name. */
    public const README_NAME = 'קרא-אותי.txt';

    /** Step-by-step restore instructions to hand to Claude Code on a new server. */
    public const CLAUDE_NAME = 'הוראות-שחזור-לClaude.md';

    /** The uploaded files' folder inside the zip (storage/app). */
    public const FILES_DIR = 'files';

    /**
     * This week's zip — a regular zip with a password (classic zip
     * encryption, so Windows opens it without extra software): the database
     * dump, the encryption key, the code version and every uploaded file
     * under files/. move-import.sh restores from it. Prunes old ones.
     *
     * @return string the zip's path
     */
    public function package(string $dumpPath, string $version = 'unknown', ?string $repository = null): string
    {
        $password = $this->password() ?? throw new RuntimeException('לא הוגדרה סיסמה לגיבוי השבועי (הגדרות → גיבוי).');
        if (! is_file($dumpPath)) {
            throw new RuntimeException("Database dump not found: {$dumpPath}");
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
        $add = function (string $name) use ($zip) {
            $zip->setEncryptionName($name, ZipArchive::EM_TRAD_PKWARE);
        };

        $zip->addFile($dumpPath, 'database.dump');
        $add('database.dump');
        $zip->addFromString('app-key.env', 'APP_KEY='.config('app.key')."\n");
        $add('app-key.env');
        $zip->addFromString('version.txt', $version."\n");
        $add('version.txt');
        $zip->addFromString(self::README_NAME, $this->readme());
        $add(self::README_NAME);
        $zip->addFromString(self::CLAUDE_NAME, $this->claudeInstructions($version, self::repositoryUrl($repository)));
        $add(self::CLAUDE_NAME);

        foreach ($this->uploadedFiles() as $relative => $absolute) {
            $name = self::FILES_DIR.'/'.$relative;
            $zip->addFile($absolute, $name);
            $add($name);
        }

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

    /** @return array<string, string> path relative to storage/app => absolute path */
    private function uploadedFiles(): array
    {
        $root = $this->filesRoot ?? storage_path('app');
        $files = [];
        if (! is_dir($root)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            // Half-finished uploads and scratch files — not data.
            if (str_starts_with($relative, 'private/livewire-tmp/') || str_starts_with($relative, 'private/tmp/')) {
                continue;
            }
            $files[$relative] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    private function readme(): string
    {
        return implode("\r\n", [
            'גיבוי מלא של מערכת כפיים — '.now()->format('d/m/Y H:i'),
            '',
            'files\\           כל הקבצים שהועלו למערכת (תיקיות עסקה, חשבוניות ספקים, חומרים...)',
            'database.dump    מסד הנתונים (נפתח רק בשחזור המערכת)',
            'app-key.env      מפתח ההצפנה של המערכת — לשמור בסוד',
            '',
            'שחזור המערכת מהגיבוי על שרת חדש: ראו '.self::CLAUDE_NAME,
            '(אפשר להעביר את הקובץ הזה כמו שהוא ל-Claude Code על השרת החדש).',
            '',
        ]);
    }

    /** "git@github.com:owner/repo.git" → "https://github.com/owner/repo". */
    public static function repositoryUrl(?string $remote): ?string
    {
        $remote = trim((string) $remote);
        if ($remote === '') {
            return null;
        }
        if (preg_match('~^git@([^:]+):(.+?)(\.git)?$~', $remote, $m)) {
            return "https://{$m[1]}/{$m[2]}";
        }
        // Never leak a token embedded in an https remote (https://user:token@host/...).
        $remote = preg_replace('~^(https?://)[^@/]+@~', '$1', $remote);

        return preg_replace('~\.git$~', '', $remote);
    }

    private function claudeInstructions(string $version, ?string $repository): string
    {
        $repo = $repository ?? '(לא נמצא קישור — לבקש מבעלת העסק את כתובת המאגר ב-GitHub)';
        $date = now()->format('d/m/Y H:i');
        $site = config('app.url');

        return <<<MD
        # שחזור מערכת כפיים מגיבוי — הוראות ל-Claude Code

        ## מה לכתוב ל-Claude Code (להעתיק כמו שזה)

        > השרת של מערכת כפיים נפל. תתקין את המערכת מחדש על השרת הזה מ-GitHub
        > ותשחזר את כל הנתונים מקובץ הגיבוי. המאגר: {$repo}
        > קובץ הגיבוי נמצא בשרת ב: <הנתיב שאליו הועתק קובץ ה-zip>.
        > ההוראות המלאות בקובץ הוראות-שחזור-לClaude.md שבתוך ה-zip.

        ## פרטי הגיבוי

        - מאגר הקוד: {$repo}
        - גרסת הקוד בזמן הגיבוי (commit): {$version}
        - הגיבוי נוצר ב: {$date}
        - כתובת האתר הקודמת: {$site}
        - הנתונים בגיבוי הם נכונים לתאריך הזה. מה שהוזן אחריו צריך להזין מחדש.

        ## מה צריך להכין לפני

        1. שרת חדש (Linux) עם Claude Code.
        2. גישה למאגר ב-GitHub מהשרת החדש (אם המאגר פרטי).
        3. קובץ ה-zip הזה, מועתק לשרת (למשל עם WinSCP). לא פותחים אותו — מעתיקים כמו שהוא.
        4. הסיסמה של קובץ ה-zip. מקלידים אותה בעצמך כשהשחזור מבקש — לא כותבים אותה בשיחה.
        5. אם כתובת האתר נשארת: לעדכן את ה-DNS (למשל ב-Cloudflare) שיצביע לשרת החדש.

        ---

        ## Instructions for Claude Code (English)

        The old server is gone; rebuild the system on this server from the repository and restore
        all data from the weekly backup zip this file came in.

        1. `git clone {$repo} ~/kapaim` (needs GitHub access if private), then read `~/kapaim/CLAUDE.md`
           and follow "Installing on a new server" — the **restore from backup** path.
        2. Ask (one AskUserQuestion): the site address and whether DNS points here yet; where on this
           server the backup zip is. Do NOT ask for the zip's password in chat.
        3. `./install.sh <admin-email> https://<domain>` (the admin is replaced by the restored users).
        4. HTTPS reverse proxy as in DEPLOY.md §4.
        5. Restore: have the user run `! ./move-import.sh <path-to-zip>` and type the zip's password at
           the prompt herself. It backs up, restores the database, files and APP_KEY, migrates, restarts,
           and ends with `app:move-summary` (all files present, SUMIT keys readable).
           The backup's code version is `{$version}`; if move-import warns it isn't in this checkout,
           `git pull` first.
        6. Daily backup cron (DEPLOY.md §7), then verify per CLAUDE.md step 7. Users log in with their old
           passwords. Tell her data is as of {$date} and anything entered after must be re-entered.

        MD;
    }

    /**
     * Extracts a weekly zip into $dir (restore) — false on a wrong password
     * or a zip that isn't a weekly backup.
     */
    public static function open(string $zipPath, string $password, string $dir): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Not a backup zip: {$zipPath}");
        }
        $zip->setPassword($password);
        $ok = $zip->locateName('database.dump') !== false && @$zip->extractTo($dir);
        $zip->close();

        return $ok && is_file($dir.'/database.dump') && is_file($dir.'/app-key.env');
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
