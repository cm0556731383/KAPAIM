<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * MVP (FR-7.1): one "גישה מלאה" role, granted to the two real system users —
     * business owner and secretary — with identical full access.
     */
    public function run(): void
    {
        $fullAccess = Role::create([
            'name' => 'גישה מלאה',
            'is_active' => true,
        ]);

        // A single wildcard row expresses "allow everything" as real, queryable
        // data — not a hardcoded bypass. A future limited role (e.g. "עובדת
        // מכירות") is added the same way: specific resource/action rows, no
        // migration needed (build-plan 01 / PRD 3.4).
        $fullAccess->permissions()->create([
            'resource' => '*',
            'action' => '*',
            'is_allowed' => true,
        ]);

        User::create([
            'name' => 'בעלת העסק',
            'email' => 'dana@kapaim.co.il',
            'personal_email' => 'dana.personal@gmail.com',
            'password' => 'password',
            'role_id' => $fullAccess->id,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'מזכירה',
            'email' => 'noa@kapaim.co.il',
            'personal_email' => 'noa.personal@gmail.com',
            'password' => 'password',
            'role_id' => $fullAccess->id,
            'is_active' => true,
        ]);

        $this->call(ReferenceDataSeeder::class);
        $this->call(ProgramsCatalogSeeder::class);
        $this->call(MailingListsSeeder::class);
        $this->call(DocumentTemplatesSeeder::class);
        $this->call(LeadsDemoSeeder::class);
        $this->call(CustomersDemoSeeder::class);
        $this->call(DealsDemoSeeder::class);
        $this->call(DocumentsDemoSeeder::class);
        $this->call(PaymentsDemoSeeder::class);
        $this->call(SubscriptionsDemoSeeder::class);
        $this->call(MaterialsDemoSeeder::class);
        $this->call(SuppliersExpensesDemoSeeder::class);
    }
}
