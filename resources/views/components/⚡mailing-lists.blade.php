<?php

use App\Concerns\Notifies;
use App\Concerns\PreviewsEmails;
use App\Jobs\SendMailingListEmail;
use App\Models\MailingList;
use App\Models\EmailTemplate;
use App\Models\MailingListSend;
use App\Models\MailingListSendAttachment;
use App\Models\MaterialDeliveryAttachment;
use App\Models\MailingMembership;
use App\Models\Program;
use App\Services\ActivityLogger;
use App\Support\BrandedEmail;
use App\Support\EmailMessage;
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
 * used as is. Materials (files and links) can be attached to either — the
 * same way as a materials send: files are kept here and the email links to
 * them (MailingListSendAttachment), in place of {{רשימת_החומרים}} or at the
 * end of the email.
 */
new
#[Layout('layouts.app', ['title' => 'רשימות תפוצה — כפיים'])]
class extends Component
{
    use Notifies;
    use PreviewsEmails;
    use WithFileUploads;

    /** @var array<int, string> ids of the lists the email goes to */
    public array $sendListIds = [];
    public string $sendSubject = '';
    /** text = the owner's text in the branded shell; html = an uploaded HTML file, as is. */
    public string $sendMode = 'text';
    public string $sendBody = '';
    public $htmlFile = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile[] */
    public array $sendFiles = [];

    /** [{name, url}, ...] — links sent with the files, e.g. a video too big to upload. */
    public array $sendLinks = [];

    /**
     * MailingListSendAttachment ids already stored for the current files and
     * links (by a preview or a test send) — reused by the real send, and
     * dropped whenever the files or links change.
     *
     * @var array<int, int>
     */
    public array $storedAttachmentIds = [];

    public ?string $sendError = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('mailing-lists.view'), 403);

        // Every catalog program has its list, even before anyone joined it.
        Program::where('is_active', true)->get()->each(fn (Program $program) => MailingList::forProgram($program));
        MailingList::primaryList();
        MailingList::subscribersList();
        MailingList::suppliersList();
    }

    /** The email exactly as it would go out — in the preview window, with "שליחת בדיקה אליי". */
    public function preview(): void
    {
        $this->sendError = null;
        $this->validate(['sendSubject' => ['required', 'string', 'max:255']], [], ['sendSubject' => 'נושא']);
        $html = $this->buildBody();

        if ($html === null) {
            return;
        }

        $to = $this->sendListIds
            ? MailingList::whereIn('id', $this->sendListIds)->get()->map->displayName()->implode(', ')." ({$this->selectedRecipientCount} כתובות)"
            : null;

        $this->showEmailPreview(new EmailMessage($this->sendSubject, $html), $to);
    }

    /**
     * No list ticked pops a warning up and sends nothing. Otherwise, once the
     * email is valid, the app's confirm dialog asks first, and only its
     * "אישור" calls send(true) — the real send.
     */
    public function send(ActivityLogger $activityLogger, bool $confirmed = false): void
    {
        abort_unless(auth()->user()->can('mailing-lists.manage'), 403);

        $this->sendError = null;

        if (! $this->sendListIds) {
            $this->notifyWarning('לא נבחרה רשימת תפוצה. יש לסמן לפחות רשימה אחת לפני שליחת המייל.', sticky: true);

            return;
        }

        $data = $this->validate([
            'sendListIds' => ['required', 'array', 'min:1'],
            'sendListIds.*' => ['exists:mailing_lists,id'],
            'sendSubject' => ['required', 'string', 'max:255'],
        ], ['sendListIds.required' => 'יש לבחור לפחות רשימה אחת.'], ['sendListIds' => 'רשימות', 'sendSubject' => 'נושא']);

        $html = $this->buildBody();

        if ($html === null) {
            return;
        }

        $lists = MailingList::whereIn('id', $data['sendListIds'])->get();
        $names = $lists->map->displayName()->implode(', ');
        $recipientCount = count(MailingList::recipientsOf($lists));

        if ($recipientCount === 0) {
            $this->sendError = $lists->count() === 1
                ? "ברשימה \"{$names}\" אין עדיין נמענים עם כתובת דוא\"ל."
                : 'ברשימות שנבחרו אין עדיין נמענים עם כתובת דוא"ל.';

            return;
        }

        if (! $confirmed) {
            $question = "הודעות המייל יישלחו לכל הנמענים ברשימות התפוצה ({$names} — {$recipientCount} נמענים). האם לשלוח?";
            $this->js('confirmDialog('.json_encode($question, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).').then((ok) => ok && $wire.send(true))');

            return;
        }

        $send = MailingListSend::create([
            'user_id' => auth()->id(),
            'subject' => $data['sendSubject'],
            'content_type' => $this->sendMode,
            'body_html' => $html,
            'recipient_count' => $recipientCount,
            'status' => MailingListSend::STATUS_QUEUED,
        ]);
        $send->mailingLists()->attach($lists->pluck('id'));
        MailingListSendAttachment::whereIn('id', $this->storedAttachmentIds)->whereNull('mailing_list_send_id')->update(['mailing_list_send_id' => $send->id]);
        $this->storedAttachmentIds = [];

        SendMailingListEmail::dispatch($send->id);

        $activityLogger->log('mailing_list.email_sent', "נשלח מייל לרשימות התפוצה {$names} ({$recipientCount} נמענים): {$send->subject}", [
            'metadata' => ['mailing_list_send_id' => $send->id],
        ]);

        foreach ($this->sendFiles as $file) {
            $file->delete();
        }

        $this->reset(['sendListIds', 'sendSubject', 'sendBody', 'htmlFile', 'sendFiles', 'sendLinks', 'emailPreview']);
        unset($this->sends);
        $this->notifySuccess("המייל נשלח ל-{$recipientCount} נמענים ({$names}). השליחה מתבצעת ברקע.");
    }

    public function addSendLink(): void
    {
        $this->sendLinks[] = ['name' => '', 'url' => ''];
    }

    public function removeSendLink(int $index): void
    {
        unset($this->sendLinks[$index]);
        $this->sendLinks = array_values($this->sendLinks);
        $this->resetErrorBag();
        $this->discardStoredAttachments();
    }

    public function updatedSendFiles(): void
    {
        $this->validate(['sendFiles.*' => MaterialDeliveryAttachment::FILE_RULES], [], ['sendFiles.*' => 'קובץ מצורף']);
        $this->discardStoredAttachments();
    }

    public function updatedSendLinks(): void
    {
        $this->discardStoredAttachments();
    }

    public function removeSendFile(int $index): void
    {
        ($this->sendFiles[$index] ?? null)?->delete();
        unset($this->sendFiles[$index]);
        $this->sendFiles = array_values($this->sendFiles);
        $this->discardStoredAttachments();
    }

    /** The email's HTML — or null after setting $sendError / a validation error. */
    private function buildBody(): ?string
    {
        $links = $this->attachmentLinks();

        if ($this->sendMode === 'html') {
            $this->validate(['htmlFile' => ['required', 'file', 'max:2048', 'mimes:html,htm']], [], ['htmlFile' => 'קובץ HTML']);
            $html = (string) file_get_contents($this->htmlFile->getRealPath());

            if (trim(strip_tags($html)) === '' && ! str_contains($html, '<img')) {
                $this->sendError = 'קובץ ה-HTML ריק.';

                return null;
            }

            return BrandedEmail::htmlWithBlocks($html, $this->materialsBlock($html, $links));
        }

        $this->validate(['sendBody' => ['required', 'string']], [], ['sendBody' => 'תוכן המייל']);

        return BrandedEmail::wrap(BrandedEmail::textWithBlocks($this->sendBody, $this->materialsBlock($this->sendBody, $links)));
    }

    /**
     * The materials list for the email, under its own heading when the body
     * doesn't place it with {{רשימת_החומרים}}; nothing when nothing is attached.
     *
     * @param  array<int, array{name: string, url: string}>  $links
     * @return array<string, string>
     */
    private function materialsBlock(string $body, array $links): array
    {
        if (! $links) {
            return [];
        }

        $heading = str_contains($body, EmailTemplate::MATERIALS_LIST_TOKEN) ? '' : '<p style="font-weight:700;">חומרים מצורפים:</p>';

        return [EmailTemplate::MATERIALS_LIST_TOKEN => $heading.BrandedEmail::linksList($links)];
    }

    /**
     * Validates and stores the attached files and links (once — see
     * $storedAttachmentIds), and returns them as [{name, url}] for the email.
     *
     * @return array<int, array{name: string, url: string}>
     */
    private function attachmentLinks(): array
    {
        $this->validate([
            'sendFiles.*' => MaterialDeliveryAttachment::FILE_RULES,
            'sendLinks.*.url' => ['required', 'url:http,https', 'max:2048'],
            'sendLinks.*.name' => ['nullable', 'string', 'max:255'],
        ], [], ['sendFiles.*' => 'קובץ מצורף', 'sendLinks.*.url' => 'קישור', 'sendLinks.*.name' => 'שם הקישור']);

        if (! $this->storedAttachmentIds) {
            $files = array_map(fn ($file) => MailingListSendAttachment::create([
                'file_reference' => $file->storeAs('mailing-list-attachments', $file->getFilename(), 'local'),
                'file_name' => $file->getClientOriginalName(),
            ]), $this->sendFiles);

            // A link without a name is shown by its address.
            $links = array_map(fn (array $link) => MailingListSendAttachment::create([
                'url' => trim($link['url']),
                'file_name' => trim((string) ($link['name'] ?? '')) ?: (parse_url(trim($link['url']), PHP_URL_HOST) ?: trim($link['url'])),
            ]), $this->sendLinks);

            $this->storedAttachmentIds = array_map(fn (MailingListSendAttachment $a) => $a->id, [...$files, ...$links]);
        }

        return MailingListSendAttachment::whereIn('id', $this->storedAttachmentIds)->orderBy('id')->get()
            ->map(fn (MailingListSendAttachment $a) => ['name' => $a->file_name, 'url' => $a->downloadUrl()])
            ->all();
    }

    /** Drops attachments stored for files/links that have since changed — never one of a real send. */
    private function discardStoredAttachments(): void
    {
        $stale = MailingListSendAttachment::whereIn('id', $this->storedAttachmentIds)->whereNull('mailing_list_send_id')->get();
        \Illuminate\Support\Facades\Storage::disk('local')->delete($stale->pluck('file_reference')->filter()->all());
        MailingListSendAttachment::whereIn('id', $stale->pluck('id'))->delete();
        $this->storedAttachmentIds = [];
        $this->emailPreview = null;
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

    /** Unique addresses across the lists currently ticked — what "שליחה" would reach. */
    #[Computed]
    public function selectedRecipientCount(): int
    {
        return $this->sendListIds ? count(MailingList::recipientsOf(MailingList::whereIn('id', $this->sendListIds)->get())) : 0;
    }

    #[Computed]
    public function sends()
    {
        return MailingListSend::with('mailingLists', 'user')->latest('id')->limit(20)->get();
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
        <h3>שליחת מייל לרשימות</h3>
        <form wire:submit="send" class="form-grid">
            <div class="full">
                <label>רשימות</label>
                <div style="display:flex; flex-wrap:wrap; gap:var(--sp-sm) var(--sp-lg)">
                    @foreach ($this->lists as $list)
                        <label wire:key="pick-{{ $list->id }}" style="display:inline-flex; align-items:center; gap:8px; margin:0; font-weight:500; cursor:pointer">
                            <input type="checkbox" value="{{ $list->id }}" wire:model.live="sendListIds" style="width:auto; margin:0">
                            {{ $list->displayName() }} <span class="text-text-secondary ltr-num" style="font-weight:400">({{ $list->recipients_count }})</span>
                        </label>
                    @endforeach
                </div>
                @if ($sendListIds)
                    <div class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:6px">סה"כ <span class="ltr-num">{{ $this->selectedRecipientCount }}</span> כתובות — כתובת שמופיעה בכמה רשימות תקבל את המייל פעם אחת.</div>
                @endif
                @error('sendListIds') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div class="full">
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
            <div class="full" x-data="{ uploading: false, progress: 0, failed: false }"
                x-on:livewire-upload-start="uploading = true; failed = false; progress = 0"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
                x-on:livewire-upload-finish="uploading = false"
                x-on:livewire-upload-error="uploading = false; failed = true"
                x-on:livewire-upload-cancel="uploading = false">
                <label for="sendFiles">חומרים מצורפים (לא חובה)</label>
                <input type="file" id="sendFiles" wire:model="sendFiles" multiple>
                <div class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">PDF, Word, PowerPoint, תמונה, וידאו או שמע — עד 95MB לקובץ. סרטון גדול יותר — כקישור (למטה). במייל יופיע קישור להורדת כל קובץ — במקום <span dir="ltr">{{ \App\Models\EmailTemplate::MATERIALS_LIST_TOKEN }}</span> אם כתבתם אותו בתוכן או בקובץ ה-HTML, ואחרת בסוף המייל.</div>
                <div x-show="uploading" x-cloak class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">מעלה את הקבצים… <span class="ltr-num" x-text="progress + '%'"></span></div>
                <div x-show="failed" x-cloak style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">העלאת הקובץ נכשלה — ייתכן שהוא גדול מ-95MB (או שכמה קבצים יחד עוברים 100MB), או שהחיבור נקטע. נסו לבחור אותו שוב, או שלחו סרטון גדול כקישור.</div>
                @error('sendFiles.*') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                @if (! empty($sendFiles))
                    <div class="chip-row" style="display:flex; flex-wrap:wrap; gap:var(--sp-sm); margin-top:var(--sp-sm)">
                        @foreach ($sendFiles as $i => $file)
                            <span class="badge badge-neutral" wire:key="send-file-{{ $i }}">{{ $file->getClientOriginalName() }} <button type="button" wire:click="removeSendFile({{ $i }})" aria-label="הסרת הקובץ" style="background:none; border:none; cursor:pointer; color:inherit; font-weight:700; padding:0 2px">×</button></span>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="full">
                <label>קישורים</label>
                @foreach ($sendLinks as $i => $link)
                    <div class="material-link-row" wire:key="send-link-{{ $i }}">
                        <div>
                            <input type="text" wire:model.blur="sendLinks.{{ $i }}.name" placeholder="שם (למשל: סרטון הדרכה)" aria-label="שם הקישור">
                            @error("sendLinks.$i.name") <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <input type="url" wire:model.blur="sendLinks.{{ $i }}.url" class="ltr-num" dir="ltr" placeholder="https://" aria-label="כתובת הקישור">
                            @error("sendLinks.$i.url") <div class="field-error">{{ $message }}</div> @enderror
                        </div>
                        <button type="button" wire:click="removeSendLink({{ $i }})" class="btn btn-ghost btn-sm" aria-label="הסרת הקישור">×</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addSendLink" class="btn btn-ghost btn-sm">+ הוספת קישור</button>
            </div>
            <div class="full" style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="send, htmlFile, sendFiles">שליחה לרשימות</button>
                <button type="button" class="btn btn-secondary" wire:click="preview" wire:loading.attr="disabled" wire:target="preview, htmlFile, sendFiles">תצוגה מקדימה</button>
            </div>
        </form>
    </div>

    <x-email-preview />

    <div class="card" @if ($this->sends->contains('status', \App\Models\MailingListSend::STATUS_QUEUED)) wire:poll.5s @endif>
        <h3>מיילים שנשלחו</h3>
        @if ($this->sends->isEmpty())
            <div class="empty-state">עדיין לא נשלחו מיילים לרשימות.</div>
        @else
            <div class="table-scroll">
            <table>
                <thead><tr><th>תאריך</th><th>רשימות</th><th>נושא</th><th>נמענים</th><th>סטטוס</th><th>נשלח ע"י</th></tr></thead>
                <tbody>
                    @foreach ($this->sends as $send)
                        <tr wire:key="send-{{ $send->id }}">
                            <td class="ltr-num">{{ $send->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $send->listNames() }}</td>
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
