<?php

namespace App\Concerns;

use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Support\EmailMessage;
use Livewire\Attributes\Locked;

/**
 * The "תצוגה מקדימה" window every email-sending screen offers: the email
 * exactly as it goes out (EmailMessage — the same builder the real send
 * uses), shown in <x-email-preview /> (once per screen), plus "שליחת
 * בדיקה אליי" — one copy to the signed-in user's own address, sent right
 * away. A screen builds its EmailMessage and calls showEmailPreview().
 *
 * Locked: the browser can't swap in other HTML, so a test send is always
 * the email the screen itself built.
 */
trait PreviewsEmails
{
    /** @var array{subject: string, html: string, attachments: list<array{name: string, url: string}>, to: ?string, note: ?string}|null */
    #[Locked]
    public ?array $emailPreview = null;

    /**
     * @param  ?string  $to  who the real email goes to, shown above it
     * @param  ?string  $note  e.g. why its buttons do nothing in the preview
     */
    protected function showEmailPreview(EmailMessage $message, ?string $to = null, ?string $note = null): void
    {
        $this->emailPreview = [...$message->toArray(), 'to' => $to, 'note' => $note];
        $this->dispatch('open-modal', name: 'email-preview');
    }

    public function sendEmailPreviewToMe(ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        if (! $this->emailPreview) {
            return;
        }

        $user = auth()->user();
        $message = EmailMessage::fromArray($this->emailPreview);

        $operation = $runner->run('smove', 'email_test', 'app_action',
            fn () => $smove->send([['email' => $user->email, 'name' => $user->name]], new EmailMessage('[בדיקה] '.$message->subject, $message->html, $message->attachments)),
            ['description' => "מייל בדיקה: {$message->subject}"],
        );

        $operation->succeeded()
            ? $this->notifySuccess("מייל בדיקה נשלח אל {$user->email}.")
            : $this->notifyError('שליחת מייל הבדיקה נכשלה: '.$operation->error_message);
    }
}
