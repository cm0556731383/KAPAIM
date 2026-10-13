<?php

namespace App\Jobs;

use App\Models\ExternalOperation;
use App\Models\MailingListSend;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one MailingListSend through Smove in the background — a list can
 * hold many recipients, and each is resolved against Smove one by one
 * (SmoveClient::sendCampaign()), too slow for the click itself. Recipients
 * are read when the job runs, so the send reaches the list as it is then.
 */
class SendMailingListEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $sendId) {}

    public function handle(ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        $send = MailingListSend::with('mailingList', 'user')->find($this->sendId);

        if (! $send || $send->status !== MailingListSend::STATUS_QUEUED) {
            return;
        }

        $recipients = $send->mailingList->recipients();

        $operation = $runner->run(
            'smove',
            'mailing_list_email',
            'app_action',
            fn () => $smove->sendListEmail($recipients, $send->subject, $send->body_html),
            ['user' => $send->user, 'description' => "שליחת מייל לרשימת התפוצה \"{$send->mailingList->name}\": {$send->subject}"],
        );

        $sent = $operation->status === ExternalOperation::STATUS_SUCCESS;

        $send->update([
            'recipient_count' => count($recipients),
            'status' => $sent ? MailingListSend::STATUS_SENT : MailingListSend::STATUS_FAILED,
            'error_message' => $sent ? null : $operation->error_message,
            'sent_at' => $sent ? now() : null,
        ]);
    }
}
