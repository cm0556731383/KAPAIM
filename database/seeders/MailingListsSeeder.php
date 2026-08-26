<?php

namespace Database\Seeders;

use App\Models\MailingList;
use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * Build-plan 10 seed data (US-013): pre-seeds one MAILING_LIST row per
 * currently-catalog program (any type — every program gets its own list per
 * FR-5.22, not just the "monthly" subset used by FR-5.23) plus the three
 * singleton lists. The demo leads/customers/subscription flowing through
 * this stage's now-wired-up hooks (LeadsDemoSeeder, CustomersDemoSeeder,
 * DealsDemoSeeder, SubscriptionsDemoSeeder) already produce real non-zero
 * MAILING_MEMBERSHIP rows on top of these — this seeder only guarantees the
 * LIST rows themselves all exist up front, including ones nothing in the
 * demo flow happens to touch (e.g. a premium program, or the still-empty
 * "ספקים" shell — FR-5.26 is build-plan 11's job, not this one's).
 */
class MailingListsSeeder extends Seeder
{
    public function run(): void
    {
        MailingList::primaryList();
        MailingList::subscribersList();
        MailingList::suppliersList();

        Program::all()->each(fn (Program $program) => MailingList::forProgram($program));
    }
}
