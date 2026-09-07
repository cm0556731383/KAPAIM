<?php

use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only, chronological view of ActivityLog (FR-7.17–FR-7.19). Deliberately
 * has no edit/delete affordance anywhere — the model itself refuses both
 * (see App\Models\ActivityLog::booted()).
 */
new
#[Layout('layouts.app', ['title' => 'יומן פעילות — כפיים'])]
class extends Component
{
    use WithPagination;

    public string $userFilter = '';
    public string $fromDate = '';
    public string $toDate = '';

    /**
     * Stage 19 hardening (FR-7.1-7.6 permission-gate exhaustiveness audit):
     * the activity log surfaces every business event across leads,
     * customers, deals, payments, etc. — genuinely sensitive, and
     * previously had no gate at all (harmless only because MVP's one real
     * role is full-access; a future limited role like "עובדת מכירות" would
     * otherwise be able to read the whole business's history through it).
     */
    public function mount(): void
    {
        abort_unless(auth()->user()->can('activity-log.manage'), 403);
    }

    public function updatedUserFilter(): void
    {
        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function entries()
    {
        return ActivityLog::with('user')
            ->when($this->userFilter !== '', fn ($q) => $q->where('user_id', $this->userFilter))
            ->when($this->fromDate !== '', fn ($q) => $q->whereDate('occurred_at', '>=', $this->fromDate))
            ->when($this->toDate !== '', fn ($q) => $q->whereDate('occurred_at', '<=', $this->toDate))
            ->orderByDesc('occurred_at')
            ->paginate(25);
    }

    #[Computed]
    public function filterUsers()
    {
        return User::orderBy('name')->get();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">יומן פעילות</h1>
            <p class="text-text-secondary m-0">רשומה כרונולוגית של כל פעולה משמעותית במערכת — ידנית ואוטומטית כאחד.</p>
        </div>
    </div>

    <div class="immutable-banner">
        <svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <rect x="5" y="11" width="14" height="9" rx="2"/>
            <path d="M8 11V7a4 4 0 0 1 8 0v4"/>
        </svg>
        <div>רשומות יומן הפעילות <strong>אינן ניתנות לעריכה או למחיקה</strong> — כל אירוע כולל לפחות מועד, משתמשת ותיאור הפעולה.</div>
    </div>

    <div class="filter-row">
        <div>
            <label for="userFilter">משתמשת</label>
            <select id="userFilter" wire:model.live="userFilter">
                <option value="">הכל</option>
                @foreach ($this->filterUsers as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="fromDate">מתאריך</label>
            <input type="date" id="fromDate" wire:model.live="fromDate">
        </div>
        <div>
            <label for="toDate">עד תאריך</label>
            <input type="date" id="toDate" wire:model.live="toDate">
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden">
        <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>תאריך ושעה</th>
                    <th>משתמשת</th>
                    <th>סוג פעולה</th>
                    <th>תיאור</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->entries as $entry)
                    <tr>
                        <td class="ltr-num" style="direction:ltr; text-align:right">{{ $entry->occurred_at->format('d/m/Y H:i') }}</td>
                        <td>
                            @if ($entry->isManual())
                                {{ $entry->user?->name ?? '—' }}
                            @else
                                מערכת חיצונית <span class="actor-auto">אוטומטי</span>
                            @endif
                        </td>
                        <td><span class="badge badge-info">{{ $entry->activity_type }}</span></td>
                        <td>{{ $entry->description }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-text-secondary">אין רשומות יומן פעילות התואמות לסינון.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">{{ $this->entries->links() }}</div>
</div>
