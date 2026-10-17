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
    /** page key => [label, has an edit permission, route name] — in side-menu order. */
    public const PAGES = [
        'dashboard' => ['מסך עבודה', true, null],
        'leads' => ['לידים', true, 'leads'],
        'leads-all' => ['לידים — כל הלידים (לא רק המשויכים אליה)', true, null],
        'customers' => ['לקוחות', true, 'customers'],
        'deals' => ['עסקאות', true, null],
        'documents' => ['מסמכים', true, null],
        'payments' => ['גבייה ותשלומים', true, 'collections'],
        'mailing-lists' => ['רשימות תפוצה', true, 'mailing-lists'],
        'suppliers' => ['ספקים', true, 'suppliers'],
        'expenses' => ['הוצאות', true, 'expenses'],
        'cashflow-report' => ['דוח הכנסות והוצאות', false, 'cashflow-report'],
        'catalog' => ['קטלוג תוכניות', true, 'programs-catalog'],
        'document-templates' => ['תבניות מסמכים (לשונית בהגדרות מערכת)', true, null],
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
        'deal-detail' => 'deals',
        'document-view' => 'documents',
        'collections' => 'payments',
        'mailing-lists' => 'mailing-lists',
        'suppliers' => 'suppliers',
        'expenses' => 'expenses',
        'cashflow-report' => 'cashflow-report',
        'programs-catalog' => 'catalog',
        'document-templates' => 'document-templates',
        'settings' => 'settings',
        'users-roles' => 'users',
        'activity-log' => 'activity-log',
        'global-search' => 'search',
    ];

    // 'leads-all': not a page of its own — view = sees every lead, not only the ones assigned to her;
    // edit = may also assign leads to users. Without it, the leads page shows only her own open leads.

    /** Older resource names used inside pages, answered by the page they live on. */
    public const ALIASES = [
        'subscriptions' => 'customers',
        'materials' => 'customers',
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
