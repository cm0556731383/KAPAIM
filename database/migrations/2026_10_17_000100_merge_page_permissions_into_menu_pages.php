<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission matrix now lists only the side menu's pages
 * (App\Support\PagePermissions::PAGES); the parts that had rows of their
 * own follow their page (PagePermissions::ALIASES). Suppliers, expenses and
 * the income statement become one "finance" page — allowed when any of the
 * three was. The other parts already had a page row of their own, which now
 * decides alone. The parts' own rows are removed.
 */
return new class extends Migration
{
    private const MERGED = [
        'finance' => ['suppliers', 'expenses', 'cashflow-report'],
    ];

    private const REMOVED = ['leads-all', 'deals', 'documents', 'suppliers', 'expenses', 'cashflow-report', 'document-templates'];

    public function up(): void
    {
        foreach (DB::table('roles')->pluck('id') as $roleId) {
            $rows = DB::table('role_permissions')->where('role_id', $roleId)->get();

            foreach (self::MERGED as $page => $parts) {
                foreach (['view', 'edit'] as $action) {
                    $exact = $rows->whereIn('resource', $parts)->where('action', $action);

                    if ($exact->isEmpty() || $rows->where('resource', $page)->where('action', $action)->isNotEmpty()) {
                        continue;
                    }

                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'resource' => $page,
                        'action' => $action,
                        'is_allowed' => $exact->contains('is_allowed', true),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('role_permissions')->whereIn('resource', self::REMOVED)->delete();
    }

    public function down(): void
    {
        // The parts' rows aren't restored — without them each part follows its page, as up() intended.
    }
};
