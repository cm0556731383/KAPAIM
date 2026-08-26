<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\MaterialDelivery;
use App\Models\Program;
use App\Services\ActivityLogger;
use Illuminate\Database\Seeder;

/**
 * Build-plan 10 demo data — wired LAST in DatabaseSeeder.php per this stage's
 * verification discipline: this is the final database-touching seeder, so a
 * `php artisan db:seed --force` always leaves at least one real, non-zero
 * MaterialDelivery row to look at. Uses the real MaterialDelivery::sendFor()
 * factory — not a bare ::create() — so recipients/attachments/activity-log
 * entries all exist exactly as a real send in the UI would produce them,
 * echoing docs/storyboard/materials-send.html's "בי"ס יובלים" example.
 */
class MaterialsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $activityLogger = app(ActivityLogger::class);

        $customer = Customer::whereHas('school', fn ($q) => $q->where('name', 'בית ספר יובלים'))->first();
        $program = Program::where('name', 'רימונים בסתיו — תוכנית חודשית')->first();

        if (! $customer || ! $program) {
            return;
        }

        $recipients = MaterialDelivery::defaultRecipients($customer)
            ->map(fn ($contact) => ['contact_id' => $contact->id, 'name' => $contact->name, 'email' => $contact->email])
            ->values()
            ->all();

        if (empty($recipients)) {
            return;
        }

        MaterialDelivery::sendFor(
            $customer,
            $program,
            $recipients,
            [['file_reference' => 'demo-seed-'.uniqid(), 'file_name' => 'חוברת-רימונים-בסתיו.pdf']],
            $activityLogger,
        );
    }
}
