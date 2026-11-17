<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\CustomerImport;
use Illuminate\Console\Command;

/**
 * Links every contact to its contact on the system's Smove list
 * (contacts.smove_contact_id, shown as "Smove" on the customer card), by
 * email — the same match the customer import makes. A contact whose email
 * isn't on the list (any more) is unlinked. Nothing is sent to Smove.
 *
 * Joins already link as they happen (MailingMembership::pushToSmove()); this
 * fills in contacts that existed before that, and keeps the mark true when
 * someone is added to or removed from the list in Smove itself. Registered
 * daily in routes/console.php.
 */
class LinkSmoveContacts extends Command
{
    protected $signature = 'smove:link-contacts';

    protected $description = 'Link contacts to their contact on the Smove list, by email';

    public function handle(CustomerImport $import): int
    {
        $smove = $import->smoveList();

        if ($smove['state'] === 'off') {
            $this->info('Smove is not connected — nothing to link.');

            return self::SUCCESS;
        }

        if ($smove['state'] === 'error') {
            $this->error($smove['message']);

            return self::FAILURE;
        }

        $linked = 0;
        $unlinked = 0;

        Contact::query()->select(['id', 'email', 'smove_contact_id'])->chunkById(500, function ($contacts) use ($smove, &$linked, &$unlinked) {
            foreach ($contacts as $contact) {
                $id = $smove['members'][mb_strtolower(trim((string) $contact->email))]['id'] ?? null;

                if ((int) $contact->smove_contact_id === (int) $id) {
                    continue;
                }

                Contact::whereKey($contact->id)->update(['smove_contact_id' => $id]);
                $id ? $linked++ : $unlinked++;
            }
        });

        $this->info("Linked {$linked}, unlinked {$unlinked} contact(s).");

        return self::SUCCESS;
    }
}
