<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The per-page permission matrix (App\Support\PagePermissions) makes
 * view/edit per page real: a page needs its own view row to open, and writes
 * need edit. The sales-rep role had only leads.view, which used to mean "her
 * own leads, editable" — give it the rows that keep that working.
 */
return new class extends Migration
{
    private const ROWS = [['leads', 'edit'], ['dashboard', 'view'], ['dashboard', 'edit'], ['search', 'view']];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('name', Role::SALES_REP_ROLE_NAME)->value('id');

        if (! $roleId) {
            return;
        }

        foreach (self::ROWS as [$resource, $action]) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'resource' => $resource, 'action' => $action],
                ['is_allowed' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', Role::SALES_REP_ROLE_NAME)->value('id');

        foreach (self::ROWS as [$resource, $action]) {
            DB::table('role_permissions')->where(['role_id' => $roleId, 'resource' => $resource, 'action' => $action])->delete();
        }
    }
};
