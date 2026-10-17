<?php

namespace App\Support;

use App\Models\User;

/**
 * The per-page permission matrix shown on the users & roles screen: every
 * page has a "view" and (where the page changes anything) an "edit"
 * permission, stored as role_permissions rows ({page}.view / {page}.edit).
 *
 * view  — the page opens (and appears in the side menu).
 * edit  — the page's actions may write. A view-only user can open the page,
 *         but any write made from it is blocked (see ReadOnlyGuard).
 */
class PagePermissions
{
    /** page key => [label, has an edit permission, route name] — the side menu's pages, in its order. */
    public const PAGES = [
        'dashboard' => ['מסך עבודה', true, null],
        'leads' => ['לידים', true, 'leads'],
        'customers' => ['לקוחות', true, 'customers'],
        'payments' => ['גבייה ותשלומים', true, 'collections'],
        'mailing-lists' => ['רשימות תפוצה', true, 'mailing-lists'],
        'finance' => ['ספקים והוצאות', true, 'suppliers'],
        'catalog' => ['קטלוג תוכניות', true, 'programs-catalog'],
        'settings' => ['הגדרות מערכת', true, 'settings'],
        'users' => ['משתמשות והרשאות', true, 'users-roles'],
        'activity-log' => ['יומן פעילות', false, 'activity-log'],
        'search' => ['חיפוש גלובלי', false, 'search'],
    ];

    /** Livewire page component => the page whose permissions it follows. */
    public const COMPONENT_PAGES = [
        'home' => 'dashboard',
        'leads' => 'leads',
        'lead-detail' => 'leads',
        'customers' => 'customers',
        'customer-detail' => 'customers',
        'deal-detail' => 'customers',
        'document-view' => 'customers',
        'collections' => 'payments',
        'mailing-lists' => 'mailing-lists',
        'suppliers' => 'finance',
        'expenses' => 'finance',
        'cashflow-report' => 'finance',
        'programs-catalog' => 'catalog',
        'document-templates' => 'settings',
        'settings' => 'settings',
        'users-roles' => 'users',
        'activity-log' => 'activity-log',
        'global-search' => 'search',
    ];

    /**
     * Parts of a page checked by their own name in the code, answered by the
     * page they live on — access to a page is access to all of it (business
     * owner's call, 2026-10-17). So leads.view sees every lead, not only her
     * own, and leads.edit may also assign them.
     */
    public const ALIASES = [
        'leads-all' => 'leads',
        'deals' => 'customers',
        'documents' => 'customers',
        'subscriptions' => 'customers',
        'materials' => 'customers',
        'suppliers' => 'finance',
        'expenses' => 'finance',
        'cashflow-report' => 'finance',
        'document-templates' => 'settings',
    ];

    public static function label(string $page): string
    {
        return self::PAGES[$page][0] ?? $page;
    }

    public static function hasEdit(string $page): bool
    {
        return self::PAGES[$page][1] ?? false;
    }

    /** Where to send a user who may not open the dashboard: the first page she may view. */
    public static function firstViewableUrl(User $user): ?string
    {
        foreach (self::PAGES as $page => [, , $route]) {
            if ($route && $user->hasPermission($page, 'view')) {
                return route($route);
            }
        }

        return null;
    }
}
