<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * What a fresh installation needs to work, and nothing else: the full-access
 * role, statuses, payment methods, lead sources, integration rows, the
 * system mailing lists and the starting document templates. No users (the
 * first one comes from `php artisan app:create-admin`), no catalog, no demo
 * data — unlike DatabaseSeeder. Run by install.sh; a no-op once the system
 * already has its roles (so re-running install.sh never duplicates rows).
 */
class InstallSeeder extends Seeder
{
    public function run(): void
    {
        if (Role::exists()) {
            $this->command?->info('נתוני הבסיס כבר קיימים — דילוג.');

            return;
        }

        $fullAccess = Role::create(['name' => 'גישה מלאה', 'is_active' => true]);
        $fullAccess->permissions()->create(['resource' => '*', 'action' => '*', 'is_allowed' => true]);

        $this->call(ReferenceDataSeeder::class);
        $this->call(MailingListsSeeder::class);
        $this->call(DocumentTemplatesSeeder::class);
    }
}
