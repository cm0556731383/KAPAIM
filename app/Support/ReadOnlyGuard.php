<?php

namespace App\Support;

use App\Exceptions\EditNotAllowedException;

/**
 * Blocks database writes while a view-only user's Livewire request runs a
 * page's property updates and actions (switched on/off in
 * AppServiceProvider). Enforced at the query level, so every action on every
 * page is covered without a check in each one; a blocked write throws before
 * anything is saved, and an open transaction rolls back.
 */
class ReadOnlyGuard
{
    private static ?string $page = null;

    /** Why the page is read-only, when it isn't the user's permission (EditNotAllowedException's default). */
    private static ?string $reason = null;

    /** Framework bookkeeping tables that a read-only request may still write to. */
    private const ALLOWED_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'];

    public static function enable(string $page, ?string $reason = null): void
    {
        self::$page = $page;
        self::$reason = $reason ?? self::$reason;
    }

    public static function disable(): void
    {
        self::$page = null;
        self::$reason = null;
    }

    public static function check(string $sql): void
    {
        if (self::$page === null || preg_match('/^\s*(select|with|show|explain|set|savepoint|release|rollback)\b/i', $sql)) {
            return;
        }

        if (preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+"?(\w+)"?/i', $sql, $m) && in_array(strtolower($m[1]), self::ALLOWED_TABLES, true)) {
            return;
        }

        throw new EditNotAllowedException(self::$page, self::$reason);
    }
}
