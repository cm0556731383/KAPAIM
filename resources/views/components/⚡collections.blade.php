<?php

use App\Models\Payment;
use App\Models\Task;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 08 — מסך גבייה ותשלומים (docs/storyboard/collections.html).
 * Read-only overview of every open collection TASK (task_type = 'collection'
 * — created/renewed/closed by App\Console\Commands\ProcessCollectionTasks,
 * see routes/console.php) with a direct link into the underlying deal
 * (FR-2.16), plus every check payment still awaiting clearance. All actual
 * actions — recording a payment, marking a check cleared, issuing a receipt
 * — happen on the deal's own card (⚡deal-detail.blade.php).
 */
new
#[Layout('layouts.app', ['title' => 'גבייה ותשלומים — כפיים'])]
class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->can('payments.manage'), 403);
    }

    #[Computed]
    public function openCollectionTasks()
    {
        return Task::where('task_type', 'collection')
            ->where('status', 'open')
            ->with(['deal.customer.school', 'deal.paymentMethod', 'deal.payments'])
            ->orderBy('due_at')
            ->get()
            ->filter(fn (Task $task) => $task->deal !== null);
    }

    #[Computed]
    public function pendingChecks()
    {
        return Payment::whereHas('paymentMethod', fn ($q) => $q->where('type', 'check'))
            ->whereNull('cleared_date')
            ->with(['deal.customer.school'])
            ->orderBy('payment_date')
            ->get();
    }

    #[Computed]
    public function totalOutstanding(): float
    {
        return (float) $this->openCollectionTasks->sum(fn (Task $task) => $task->deal->outstandingBalance());
    }
}
?>

<div>
    <div class="topbar">
        <div>
            <h1>גבייה ותשלומים</h1>
        </div>
    </div>

    <div class="cols2" style="margin-bottom:var(--sp-lg)">
        <div class="card">
            <div class="field"><div class="k">סך פתוח לגבייה</div><div class="v ltr-num" style="color:var(--color-error); font-size:var(--fs-h2)">₪{{ number_format($this->totalOutstanding, 0) }}</div></div>
        </div>
        <div class="card">
            <div class="field"><div class="k">משימות גבייה פתוחות</div><div class="v" style="font-size:var(--fs-h2)">{{ $this->openCollectionTasks->count() }}</div></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:var(--sp-lg)">
        <h3>משימות גבייה פתוחות</h3>
        @if ($this->openCollectionTasks->isEmpty())
            <div class="empty-state">אין כרגע משימות גבייה פתוחות.</div>
        @else
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>בית ספר</th><th>עסקה</th><th>סכום עסקה</th><th>שולם עד כה</th><th>יתרת חוב</th><th>אמצעי תשלום</th><th>תאריך יעד</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->openCollectionTasks as $task)
                        @php $deal = $task->deal; @endphp
                        <tr class="row-link" onclick="location.href='{{ route('deal-detail', $deal) }}'">
                            <td>{{ $deal->customer->school?->name ?? '—' }}</td>
                            <td>{{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</td>
                            <td class="ltr-num">₪{{ number_format((float) $deal->agreed_amount, 0) }}</td>
                            <td class="ltr-num">₪{{ number_format($deal->totalPaid(), 0) }}</td>
                            <td class="ltr-num" style="color:var(--color-error); font-weight:700">₪{{ number_format($deal->outstandingBalance(), 0) }}</td>
                            <td>{{ $deal->paymentMethod?->name ?? '—' }}</td>
                            <td class="ltr-num">{{ $task->due_at?->format('d/m/Y') ?? '—' }}</td>
                            <td><a href="{{ route('deal-detail', $deal) }}" class="btn btn-secondary btn-sm" onclick="event.stopPropagation()">רישום תשלום</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="card">
        <h3>צ'קים במעקב</h3>
        @if ($this->pendingChecks->isEmpty())
            <div class="empty-state">אין כרגע צ'קים הממתינים לפירעון.</div>
        @else
            <div class="table-scroll">
            <table>
                <thead><tr><th>לקוחה</th><th>עסקה</th><th>סכום</th><th>תאריך קבלה</th><th>סטטוס</th></tr></thead>
                <tbody>
                    @foreach ($this->pendingChecks as $payment)
                        <tr>
                            <td>{{ $payment->deal->customer->school?->name ?? '—' }}</td>
                            <td><a href="{{ route('deal-detail', $payment->deal) }}">{{ $payment->deal->program_name_snapshot ?? $payment->deal->bundle_name_snapshot }}</a></td>
                            <td class="ltr-num">₪{{ number_format((float) $payment->amount, 0) }}</td>
                            <td class="ltr-num">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                            <td><span class="badge badge-warning">ממתין לפירעון</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
