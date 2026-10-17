<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The first user of a fresh installation (install.sh) — or a way back in if
 * every admin is locked out. Prints a generated password once.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin {email : כתובת המייל להתחברות} {--name=בעלת העסק : השם שיוצג במערכת} {--if-none : לא לעשות דבר אם כבר יש משתמשות (להרצה חוזרת של install.sh)}';

    protected $description = 'Create a full-access user with a generated password';

    public function handle(): int
    {
        if ($this->option('if-none') && User::exists()) {
            $this->info('כבר קיימות משתמשות במערכת — לא נוצרה משתמשת חדשה.');

            return self::SUCCESS;
        }

        $email = Str::lower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('כתובת המייל אינה תקינה.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("כבר קיימת משתמשת עם המייל {$email}. לאיפוס סיסמה — ממסך משתמשות והרשאות.");

            return self::FAILURE;
        }

        $role = Role::whereHas('permissions', fn ($q) => $q->where('resource', '*')->where('action', '*')->where('is_allowed', true))->first();

        if (! $role) {
            $this->error('לא נמצא תפקיד עם גישה מלאה — יש להריץ קודם: php artisan db:seed --class=InstallSeeder');

            return self::FAILURE;
        }

        $password = Str::password(14, symbols: false);

        User::create([
            'name' => $this->option('name'),
            'email' => $email,
            'password' => $password,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->info("נוצרה משתמשת מנהלת: {$email}");
        $this->line("סיסמה ראשונית: {$password}");
        $this->line('שמרו אותה — היא לא תוצג שוב (אפשר לאפס ממסך משתמשות והרשאות).');

        return self::SUCCESS;
    }
}
