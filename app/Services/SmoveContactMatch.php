<?php

namespace App\Services;

use App\Models\Contact;
use App\Services\Integrations\SmoveClient;
use Throwable;

/**
 * "Is this contact already in Smove?" — asked before a contact is opened by
 * hand: on the new-customer form (⚡customers.blade.php) and on "+ איש קשר
 * חדש" in the customer card (⚡customer-detail.blade.php). A match is shown
 * by its Smove name, with the other customers that already use the same
 * email, and is linked only after the user approves (business owner's
 * request, 2026-11-18: one contact is often shared by several customers).
 *
 * Linking follows the import's rule: a contact already on the system's Smove
 * list is never re-joined (a join resets its consent); one that isn't on the
 * list yet joins it as usual.
 */
class SmoveContactMatch
{
    public function __construct(private CustomerImport $import) {}

    /**
     * Looks each contact's email up in Smove.
     *
     * @param  array<int|string, array{name: string, email: ?string}>  $contacts
     * @return array{matches: array<int|string, array>, error: ?string}
     *                                                                   matches: key of $contacts => ['email', 'contact_name',
     *                                                                   'smove_name', 'id', 'can_receive', 'on_list', 'shared_with'];
     *                                                                   error: Smove couldn't be read — the caller should let the user retry.
     *                                                                   No Smove connection → no matches, no error.
     */
    public function find(array $contacts, ?int $exceptCustomerId = null): array
    {
        $emails = array_filter(array_map(fn ($c) => mb_strtolower(trim((string) ($c['email'] ?? ''))), $contacts));

        if ($emails === []) {
            return ['matches' => [], 'error' => null];
        }

        $list = $this->import->smoveList();

        if ($list['state'] === 'off') {
            return ['matches' => [], 'error' => null];
        }

        $retry = 'לא ניתן לבדוק כרגע אם איש הקשר כבר קיים ב-Smove — נסי שוב בעוד רגע.';

        if ($list['state'] === 'error') {
            return ['matches' => [], 'error' => $list['message'].' '.$retry];
        }

        $smove = app(SmoveClient::class);
        $matches = [];

        foreach ($emails as $key => $email) {
            try {
                $found = $smove->findContact($email);
            } catch (Throwable $e) {
                report($e);

                return ['matches' => [], 'error' => $retry];
            }

            if ($found) {
                $matches[$key] = [
                    'email' => $email,
                    'contact_name' => trim((string) $contacts[$key]['name']),
                    'smove_name' => $found['name'],
                    'id' => $found['id'],
                    'can_receive' => $found['can_receive'],
                    'on_list' => isset($list['members'][$email]),
                    'shared_with' => self::customersUsing($email, $exceptCustomerId),
                ];
            }
        }

        return ['matches' => $matches, 'error' => null];
    }

    /** Names of the customers (other than $exceptCustomerId) that already have a contact with this email. */
    public static function customersUsing(string $email, ?int $exceptCustomerId = null): array
    {
        return Contact::query()
            ->join('customers', 'customers.id', '=', 'contacts.customer_id')
            ->join('schools', 'schools.id', '=', 'customers.school_id')
            ->whereRaw('LOWER(TRIM(contacts.email)) = ?', [mb_strtolower(trim($email))])
            ->when($exceptCustomerId, fn ($q) => $q->where('contacts.customer_id', '!=', $exceptCustomerId))
            ->distinct()
            ->orderBy('schools.name')
            ->pluck('schools.name')
            ->all();
    }

    /** The approved matches split into what createCustomer()/the contact form need: email => link, and emails not to push. */
    public static function linksAndNoPush(array $matches): array
    {
        $links = [];
        $noPush = [];

        foreach ($matches as $match) {
            $links[$match['email']] = ['id' => $match['id']];

            if ($match['on_list']) {
                $noPush[] = $match['email'];
            }
        }

        return [$links, $noPush];
    }
}
