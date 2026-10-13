<?php

use App\Concerns\Notifies;
use App\Jobs\SendMailingListEmail;
use App\Models\MailingList;
use App\Models\MailingListSend;
use App\Models\MailingMembership;
use App\Models\Program;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Support\BrandedEmail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Build-plan 10 — מסך רשימות תפוצה. Membership itself is read-only here:
 * every membership change happens automatically as a side effect of a real
 * business event (new lead, program/subscription purchase, subscription
 * cancellation, a program added to the subscription bundle — see
 * Lead::joinPrimaryMailingList(), Deal::assignMailingListsFor*Purchase(),
 * Subscription::cancel(), Bundle::syncSubscriberProgramList()).
 *
 * Smove holds a single list for everyone (its id is set under settings →
 * אינטגרציות → Smove); the local lists here are what an email is aimed at:
 * the general list (every lead and customer), the subscribers, one per
 * catalog program, and the suppliers. Sending (owner's call, 2026-10-14)
 * goes to the chosen list's recipients through Smove, in the background
 * (App\Jobs\SendMailingListEmail) — either the owner's text inside the same
 * branded shell every other system email uses, or an uploaded HTML file
 * used as is.
 */
new
#[Layout('layouts.app', ['title' => 'רשימות תפוצה — כפיים'])]
class extends Component
{
    use Notifies;
    use WithFileUploads;

    public string $sendListId = '';
    public string $sendSubject = '';
    /** text = the owner's text in the branded shell; html = an uploaded HTML file, as is. */
    public string $sendMode = 'text';
    public string $sendBody = '';
    public $htmlFile = null;

    public ?string $previewHtml = null;

    public ?string $sendError = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('mailing-lists.manage'), 403);

        // Every catalog program has its list, even before anyone joined it.
        Program::where('is_active', true)->get()->each(fn (Program $program) => MailingList::forProgram($program));
        MailingList::primaryList();
        MailingList::subscribersList();
        MailingList::suppliersList();
    }

    public function preview(): void
    {
        $this->sendError = null;
        $html = $this->buildBody();

        if ($html !== null) {
            $this->previewHtml = $html;
        }
    }

    public function closePreview(): void
    {
        $this->previewHtml = null;
    }

    /** One copy to the signed-in user's own address — sent right away, not queued. */
    public function sendTest(ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        abort_unless(auth()->user()->can('mailing-lists.manage'), 403);

        $this->sendError = null;
        $this->validate(['sendSubject' => ['required', 'string', 'max:255']], [], ['sendSubject' => 'נושא']);
        $html = $this->buildBody();

        if ($html === null) {
            return;
        }

        $user = auth()->user();
        $operation = $runner->run('smove', 'mailing_list_email_test', 'app_action',
            fn () => $smove->sendListEmail([['email' => $user->email, 'name' => $user->name]], '[בדיקה] '.$this->sendSubject, $html),
            ['description' => "מייל בדיקה לרשימת תפוצה: {$this->sendSubject}"],
        );

        $operation->status === \App\Models\ExternalOperation::STATUS_SUCCESS
            ? $this->notifySuccess("מייל בדיקה נשלח אל {$user->email}.")
            : $this->sendError = 'שליחת מייל הבדיקה נכשלה: '.$operation->error_message;
    }

    public function send(ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('mailing-lists.manage'), 403);

        $this->sendError = null;

        $data = $this->validate([
            'sendListId' => ['required', 'exists:mailing_lists,id'],
            'sendSubject' => ['required', 'string', 'max:255'],
        ], [], ['sendListId' => 'רשימה', 'sendSubject' => 'נושא']);

        $html = $this->buildBody();

        if ($html === null) {
            return;
        }

        $list = MailingList::findOrFail($data['sendListId']);
        $recipientCount = count($list->recipients());

        if ($recipientCount === 0) {
            $this->sendError = "ברשימה \"{$list->name}\" אין עדיין נמענים עם כתובת דוא\"ל.";

            return;
        }

        $send = MailingListSend::create([
            'mailing_list_id' => $list->id,
            'user_id' => auth()->id(),
            'subject' => $data['sendSubject'],
            'content_type' => $this->sendMode,
            'body_html' => $html,
            'recipient_count' => $recipientCount,
            'status' => MailingListSend::STATUS_QUEUED,
        ]);

        SendMailingListEmail::dispatch($send->id);

        $activityLogger->log('mailing_list.email_sent', "נשלח מייל לרשימת התפוצה \"{$list->name}\" ({$recipientCount} נמענים): {$send->subject}", [
            'metadata' => ['mailing_list_send_id' => $send->id],
        ]);

        $this->reset(['sendSubject', 'sendBody', 'htmlFile', 'previewHtml']);
        unset($this->sends);
        $this->notifySuccess("המייל נשלח ל-{$recipientCount} נמענים ברשימה \"{$list->name}\". השליחה מתבצעת ברקע.");
    }

    /** The email's HTML — or null after setting $sendError / a validation error. */
    private function buildBody(): ?string
    {
        if ($this->sendMode === 'html') {
            $this->validate(['htmlFile' => ['required', 'file', 'max:2048', 'mimes:html,htm']], [], ['htmlFile' => 'קובץ HTML']);
            $html = (string) file_get_contents($this->htmlFile->getRealPath());

            if (trim(strip_tags($html)) === '' && ! str_contains($html, '<img')) {
                $this->sendError = 'קובץ ה-HTML ריק.';

                return null;
            }

            return $html;
        }

        $this->validate(['sendBody' => ['required', 'string']], [], ['sendBody' => 'תוכן המייל']);

        return BrandedEmail::wrap('<p>'.nl2br(e($this->sendBody)).'</p>');
    }

    #[Computed]
    public function lists()
    {
        $order = [MailingList::TYPE_PRIMARY => 0, MailingList::TYPE_SUBSCRIPTION => 1, MailingList::TYPE_PROGRAM => 2, MailingList::TYPE_SUPPLIER => 3];

        return MailingList::with('program')
            ->withCount(['memberships as active_members_count' => fn ($q) => $q->where('membership_status', MailingMembership::STATUS_ACTIVE)])
            ->get()
            // A program list shows while its program is active, or while it still has members.
            ->filter(fn (MailingList $list) => $list->list_type !== MailingList::TYPE_PROGRAM || $list->program?->is_active || $list->active_members_count > 0)
            ->sortBy([fn ($a, $b) => ($order[$a->list_type] ?? 9) <=> ($order[$b->list_type] ?? 9), fn ($a, $b) => strcmp($a->name, $b->name)])
            ->values()
            ->map(function (MailingList $list) {
                $list->recipients_count = count($list->recipients());

                return $list;
            });
    }

    #[Computed]
    public function sends()
    {
        return MailingListSend::with('mailingList', 'user')->latest('id')->limit(20)->get();
    }

    private const TYPE_LABELS = [
        'primary' => 'כללית',
        'program' => 'תוכנית',
        'subscription' => 'מנויים',
        'supplier' => 'ספקים',
    ];

    private const TYPE_BADGE_CLASSES = [
        'primary' => 'badge-primary',
        'program' => 'badge-info',
        'subscription' => 'badge-success',
        'supplier' => 'badge-warning',
    ];

    public function typeLabel(string $listType): string
    {
        return self::TYPE_LABELS[$listType] ?? $listType;
    }

    public function typeBadgeClass(string $listType): string
    {
        return self::TYPE_BADGE_CLASSES[$listType] ?? 'badge-neutral';
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1>רשימות תפוצה</h1>
        </div>
    </div>

    <div class="card" style="margin-bottom:var(--sp-lg)">
        <h3>הרשימות במערכת</h3>
        <div class="table-scroll">
        <table>
            <thead>
                <tr><th>שם רשימה</th><th>סוג</th><th>חברים</th><th>כתובות דוא"ל</th></tr>
            </thead>
            <tbody>
                @foreach ($this->lists as $list)
                    <tr wire:key="list-{{ $list->id }}">
                        <td>{{ $list->list_type === 'primary' ? 'כללית — כל הלידים והלקוחות' : $list->name }}</td>
                        <td><span class="badge {{ $this->typeBadgeClass($list->list_type) }}">{{ $this->typeLabel($list->list_type) }}</span></td>
                        <td class="ltr-num">{{ $list->active_members_count }}</td>
                        <td class="ltr-num">{{ $list->recipients_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <div class="card" style="margin-bottom:var(--sp-lg)">
        <h3>שליחת מייל לרשימה</h3>
        <form wire:submit="send" class="form-grid">
            <div>
                <label for="sendListId">רשימה</label>
                <select id="sendListId" wire:model.live="sendListId">
                    <option value="">בחרו רשימה</option>
                    @foreach ($this->lists as $list)
                        <option value="{{ $list->id }}">{{ $list->list_type === 'primary' ? 'כללית' : $list->name }} ({{ $list->recipients_count }})</option>
                    @endforeach
                </select>
                @error('sendListId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="sendSubject">נושא</label>
                <input type="text" id="sendSubject" wire:model="sendSubject">
                @error('sendSubject') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div class="full" role="radiogroup" aria-label="סוג התוכן" style="display:flex; gap:var(--sp-xl); flex-wrap:wrap">
                <label style="display:inline-flex; align-items:center; gap:8px; margin:0; font-weight:500; cursor:pointer"><input type="radio" value="text" wire:model.live="sendMode" style="width:auto; margin:0">טקסט בתבנית המיילים של המערכת</label>
                <label style="display:inline-flex; align-items:center; gap:8px; margin:0; font-weight:500; cursor:pointer"><input type="radio" value="html" wire:model.live="sendMode" style="width:auto; margin:0">קובץ HTML משלי</label>
            </div>
            @if ($sendMode === 'html')
                <div class="full">
                    <label for="htmlFile">קובץ HTML</label>
                    <input type="file" id="htmlFile" wire:model="htmlFile" accept=".html,.htm,text/html">
                    @error('htmlFile') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
            @else
                <div class="full">
                    <label for="sendBody">תוכן המייל</label>
                    <textarea id="sendBody" wire:model="sendBody" rows="8"></textarea>
                    @error('sendBody') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
            @endif
            <div class="full" style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="send, htmlFile"
                    wire:confirm="לשלוח את המייל לכל הנמענים ברשימה שנבחרה?">שליחה לרשימה</button>
                <button type="button" class="btn btn-secondary" wire:click="preview" wire:loading.attr="disabled" wire:target="preview, htmlFile">תצוגה מקדימה</button>
                <button type="button" class="btn btn-ghost" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest, htmlFile">שליחת בדיקה אליי</button>
            </div>
        </form>
    </div>

    @if ($previewHtml !== null)
        <div class="card" style="margin-bottom:var(--sp-lg)">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--sp-sm)">
                <h3 style="margin:0">תצוגה מקדימה</h3>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="closePreview">סגירה</button>
            </div>
            {{-- sandbox: an uploaded file's scripts never run inside the app. --}}
            <iframe sandbox srcdoc="{{ $previewHtml }}" title="תצוגה מקדימה של המייל" style="width:100%; height:520px; border:1px solid var(--color-border); border-radius:var(--radius-control); background:#FFFFFF"></iframe>
        </div>
    @endif

    <div class="card" @if ($this->sends->contains('status', \App\Models\MailingListSend::STATUS_QUEUED)) wire:poll.5s @endif>
        <h3>מיילים שנשלחו</h3>
        @if ($this->sends->isEmpty())
            <div class="empty-state">עדיין לא נשלחו מיילים לרשימות.</div>
        @else
            <div class="table-scroll">
            <table>
                <thead><tr><th>תאריך</th><th>רשימה</th><th>נושא</th><th>נמענים</th><th>סטטוס</th><th>נשלח ע"י</th></tr></thead>
                <tbody>
                    @foreach ($this->sends as $send)
                        <tr wire:key="send-{{ $send->id }}">
                            <td class="ltr-num">{{ $send->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $send->mailingList?->list_type === 'primary' ? 'כללית' : $send->mailingList?->name }}</td>
                            <td>{{ $send->subject }}</td>
                            <td class="ltr-num">{{ $send->recipient_count }}</td>
                            <td>
                                <span class="badge {{ ['sent' => 'badge-success', 'failed' => 'badge-error'][$send->status] ?? 'badge-warning' }}" @if ($send->error_message) title="{{ $send->error_message }}" @endif>{{ \App\Models\MailingListSend::STATUS_LABELS[$send->status] ?? $send->status }}</span>
                            </td>
                            <td>{{ $send->user?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
