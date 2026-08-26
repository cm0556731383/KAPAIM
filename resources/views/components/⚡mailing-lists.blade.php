<?php

use App\Models\MailingList;
use App\Models\MailingMembership;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 10 — מסך רשימות תפוצה (docs/storyboard/mailing-lists.html).
 * Read-only: every membership change in this app happens automatically as a
 * side effect of a real business event (new lead, program/subscription
 * purchase, subscription cancellation — see Lead::joinPrimaryMailingList(),
 * Deal::assignMailingListsForProgramPurchase(), Subscription::cancel()) —
 * the storyboard shows no manual add/remove control, matching FR-5.19-FR-5.26's
 * "no manual maintenance" framing (US-013).
 */
new
#[Layout('layouts.app', ['title' => 'רשימות תפוצה — כפיים'])]
class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->can('mailing-lists.manage'), 403);
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
            <table>
                <thead>
                    <tr><th>שם רשימה</th><th>סוג</th><th>חברים פעילים</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->lists as $list)
                        <tr>
                            <td>{{ $list->name }}</td>
                            <td><span class="badge {{ $this->typeBadgeClass($list->list_type) }}">{{ $this->typeLabel($list->list_type) }}</span></td>
                            <td class="ltr-num">{{ $list->active_members_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3">אין עדיין רשימות תפוצה במערכת.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>עדכון אוטומטי — לא נדרשת תחזוקה ידנית</h3>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-8px">כל שינוי במצב העסקי מעדכן את השיוך ברשימות אוטומטית (US-013).</p>
            <ul style="list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:var(--sp-md); font-size:var(--fs-small)">
                <li><b>ליד חדש</b> ← רשימה ראשית (FR-5.21)</li>
                <li><b>רכישת תוכנית</b> ← רשימת התוכנית שנרכשה (FR-5.22)</li>
                <li><b>רכישת מנוי</b> ← רשימת מנויים + רשימות התוכניות החודשיות בקטלוג (FR-5.23)</li>
                <li><b>ביטול מנוי</b> ← הסרה ממנויים ומרשימות שטרם סופקו; השארה ברשימה הראשית ובמה שכבר סופק (FR-5.24/FR-5.25)</li>
                <li><b>ספק חדש</b> ← רשימת ספקים (FR-5.26, שלב 11)</li>
            </ul>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-lg); margin-bottom:0">
                כל שיוך אמור להיות גם קריאה בפועל ל-Smove — לא ממומש עד שלב 12; היום השיוך המקומי בלבד הוא אמיתי ומלא.
            </p>
        </div>
    </div>
</div>
