<?php

use App\Models\MailingList;
use App\Models\MailingMembership;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 10 — מסך רשימות תפוצה (docs/storyboard/mailing-lists.html).
 * Membership itself is read-only here: every membership change in this app
 * happens automatically as a side effect of a real business event (new lead,
 * program/subscription purchase, subscription cancellation — see
 * Lead::joinPrimaryMailingList(), Deal::assignMailingListsForProgramPurchase(),
 * Subscription::cancel()) — the storyboard shows no manual add/remove
 * control, matching FR-5.19-FR-5.26's "no manual maintenance" framing
 * (US-013). The one thing this screen DOES let the business owner edit is
 * each list's smove_list_id — Smove has no "find list by name" lookup, so
 * this is the one-time link between a local list and its real Smove list
 * (created directly in Smove's own dashboard).
 */
new
#[Layout('layouts.app', ['title' => 'רשימות תפוצה — כפיים'])]
class extends Component
{
    /** @var array<int, string> keyed by mailing_list id, editable Smove list-id input */
    public array $smoveListIds = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('mailing-lists.manage'), 403);

        $this->smoveListIds = MailingList::pluck('smove_list_id', 'id')
            ->map(fn ($id) => $id === null ? '' : (string) $id)
            ->all();
    }

    public function saveSmoveListId(int $listId, ActivityLogger $activityLogger): void
    {
        abort_unless(auth()->user()->can('mailing-lists.manage'), 403);

        $raw = trim((string) ($this->smoveListIds[$listId] ?? ''));

        if ($raw !== '' && ! ctype_digit($raw)) {
            $this->addError("smoveListIds.{$listId}", 'מזהה רשימה ב-Smove חייב להיות מספר.');

            return;
        }

        $list = MailingList::findOrFail($listId);
        $list->update(['smove_list_id' => $raw === '' ? null : (int) $raw]);

        $activityLogger->log('mailing_list.smove_id_updated', "עודכן מזהה Smove לרשימה \"{$list->name}\"");

        unset($this->lists);
    }

    #[Computed]
    public function lists()
    {
        return MailingList::withCount([
            'memberships as active_members_count' => fn ($q) => $q->where('membership_status', MailingMembership::STATUS_ACTIVE),
        ])
            ->orderByRaw("CASE list_type WHEN 'primary' THEN 0 WHEN 'program' THEN 1 WHEN 'subscription' THEN 2 WHEN 'supplier' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get();
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
            <p style="color:var(--color-text-secondary); margin:0">כל פעולות הדיוור בפועל מתבצעות באמצעות Smove — המערכת מנהלת רק את השיוך (שלב 12).</p>
        </div>
    </div>

    <div class="cols2">
        <div class="card">
            <h3>הרשימות במערכת</h3>
            <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>שם רשימה</th><th>סוג</th><th>חברים פעילים</th><th>מזהה רשימה ב-Smove</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->lists as $list)
                        <tr>
                            <td>{{ $list->name }}</td>
                            <td><span class="badge {{ $this->typeBadgeClass($list->list_type) }}">{{ $this->typeLabel($list->list_type) }}</span></td>
                            <td class="ltr-num">{{ $list->active_members_count }}</td>
                            <td>
                                <div style="display:flex; gap:6px; align-items:center">
                                    <input type="text" wire:model="smoveListIds.{{ $list->id }}" class="ltr-num" dir="ltr" placeholder="—" style="width:90px">
                                    <button type="button" wire:click="saveSmoveListId({{ $list->id }})" class="btn btn-ghost btn-sm">שמירה</button>
                                </div>
                                @error("smoveListIds.{$list->id}") <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">אין עדיין רשימות תפוצה במערכת.</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <div class="card">
            <h3>עדכון אוטומטי — לא נדרשת תחזוקה ידנית</h3>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-8px">כל שינוי במצב העסקי מעדכן את השיוך ברשימות אוטומטית.</p>
            <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:var(--sp-md); font-size:var(--fs-small)">
                <li><b>ליד חדש</b> ← רשימה ראשית</li>
                <li><b>רכישת תוכנית</b> ← רשימת התוכנית שנרכשה</li>
                <li><b>רכישת מנוי</b> ← רשימת מנויים + רשימות התוכניות החודשיות בקטלוג</li>
                <li><b>ביטול מנוי</b> ← הסרה ממנויים ומרשימות שטרם סופקו; השארה ברשימה הראשית ובמה שכבר סופק</li>
                <li><b>ספק חדש</b> ← רשימת ספקים (שלב 11)</li>
            </ul>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-lg); margin-bottom:0">
                כל שיוך מקומי דוחף גם קריאה אמיתית ל-Smove (שלב 12) — עבור רשימה שטרם קיבלה מזהה Smove בטבלה משמאל, הקריאה נכשלת בלי לחסום את השיוך המקומי (רואים זאת ביומן הפעילות).
            </p>
        </div>
    </div>
</div>
