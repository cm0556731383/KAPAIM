<?php

use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\Task;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 08 — מסך גבייה ותשלומים (docs/storyboard/collections.html).
 * Read-only overview, live: every deal still owing money that was sent an
 * invoice or a payment link — whatever its status — with its open
 * collection TASK's due date if it has one (task_type = 'collection',
 * created/renewed/closed by App\Console\Commands\ProcessCollectionTasks)
 * and a direct link into the deal (FR-2.16), plus every check payment
 * still awaiting clearance. All actual
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

    /** Deals sent an invoice or a payment link that still have a balance, oldest request first. */
    #[Computed]
    public function awaitingPayment()
    {
        $deals = Deal::whereHas('status', fn ($q) => $q->whereNotIn('name', [Deal::PAID_STATUS_NAME, Deal::CANCELLED_STATUS_NAME]))
            ->where(fn ($q) => $q
                ->whereHas('documents', fn ($d) => $d->where('document_type', 'invoice')->whereNotNull('sent_at'))
                ->orWhereHas('paymentLinks', fn ($l) => $l->where('status', PaymentLink::STATUS_PENDING)))
            ->with(['customer.school', 'paymentMethod', 'payments', 'paymentLinks'])
            ->get()
            ->filter(fn (Deal $deal) => $deal->outstandingBalance() > 0);

        $tasks = Task::where('task_type', 'collection')->where('status', 'open')
            ->whereIn('deal_id', $deals->pluck('id'))
            ->get()
            ->keyBy('deal_id');

        return $deals->map(function (Deal $deal) use ($tasks) {
            $invoice = $deal->issuedInvoice();
            $link = $deal->paymentLinks->where('status', PaymentLink::STATUS_PENDING)->sortByDesc('id')->first();

            return (object) [
                'deal' => $deal,
                'invoiceSentAt' => $invoice?->sent_at,
                'link' => $link,
                'task' => $tasks->get($deal->id),
                'atRisk' => $deal->paymentRisk() !== null,
                'since' => collect([$invoice?->sent_at, $link?->created_at])->filter()->min(),
            ];
        })->sortBy('since')->values();
    }

    #[Computed]
    public function pendingChecks()
    {
        return Payment::whereHas('paymentMethod', fn ($q) => $q->where('type', 'check'))
            ->whereNull('cleared_date')
            ->with(['deal.customer.school'])
            ->orderByRaw('check_due_date IS NULL')
            ->orderBy('check_due_date')
            ->orderBy('payment_date')
            ->get();
    }

    #[Computed]
    public function totalOutstanding(): float
    {
        return (float) $this->awaitingPayment->sum(fn ($row) => $row->deal->outstandingBalance());
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
            <div class="field"><div class="k">עסקאות ממתינות לתשלום</div><div class="v" style="font-size:var(--fs-h2)">{{ $this->awaitingPayment->count() }}</div></div>
        </div>
    </div>

    <div class="card" style="margin-bottom:var(--sp-lg)">
        <h3>עסקאות ממתינות לתשלום</h3>
        @if ($this->awaitingPayment->isEmpty())
            <div class="empty-state">אין כרגע עסקאות הממתינות לתשלום.</div>
        @else
            <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th>בית ספר</th><th>עסקה</th><th>סכום עסקה</th><th>שולם עד כה</th><th>יתרת חוב</th><th>אמצעי תשלום</th><th>נשלח ללקוחה</th><th>יעד משימת גבייה</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->awaitingPayment as $row)
                        @php $deal = $row->deal; @endphp
                        <tr class="row-link" onclick="location.href='{{ route('deal-detail', $deal) }}'">
                            <td>{{ $deal->customer->school?->name ?? '—' }}</td>
                            <td>
                                {{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}
                                @if ($row->atRisk)<div><span class="badge badge-warning">חשש לתשלום כפול או ביתר</span></div>@endif
                            </td>
                            <td class="ltr-num">₪{{ number_format((float) $deal->agreed_amount, 0) }}</td>
                            <td class="ltr-num">
                                ₪{{ number_format($deal->totalPaid(), 0) }}
                                @php $pendingChecks = $deal->pendingChecks(); @endphp
                                @if ($pendingChecks->isNotEmpty())<div style="font-size:var(--fs-caption); color:var(--color-text-secondary)">+ ₪{{ number_format($pendingChecks->sum('amount'), 0) }} בצ'קים שטרם נפרעו</div>@endif
                            </td>
                            <td class="ltr-num" style="color:var(--color-error); font-weight:700">₪{{ number_format($deal->outstandingBalance(), 0) }}</td>
                            <td>{{ $deal->paymentMethod?->name ?? '—' }}</td>
                            <td>
                                @if ($row->invoiceSentAt)<div>חשבונית · <span class="ltr-num">{{ $row->invoiceSentAt->format('d/m/Y') }}</span></div>@endif
                                @if ($row->link)<div>קישור תשלום · <span class="ltr-num">{{ $row->link->created_at->format('d/m/Y') }}</span>@if ($row->link->isExpired()) <span class="badge badge-error">פג תוקף</span>@endif</div>@endif
                            </td>
                            <td class="ltr-num">{{ $row->task?->due_at?->format('d/m/Y') ?? '—' }}</td>
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
                <thead><tr><th>לקוחה</th><th>עסקה</th><th>מס' צ'ק</th><th>סכום</th><th>תאריך פירעון</th><th>התקבל</th><th>סטטוס</th></tr></thead>
                <tbody>
                    @foreach ($this->pendingChecks as $payment)
                        <tr>
                            <td>{{ $payment->deal->customer->school?->name ?? '—' }}</td>
                            <td><a href="{{ route('deal-detail', $payment->deal) }}">{{ $payment->deal->program_name_snapshot ?? $payment->deal->bundle_name_snapshot }}</a></td>
                            <td class="ltr-num">
                                {{ $payment->check_number ?? '—' }}
                                @if ($payment->checkBankLabel())<div style="font-size:var(--fs-caption); color:var(--color-text-secondary)">{{ $payment->checkBankLabel() }}</div>@endif
                            </td>
                            <td class="ltr-num">₪{{ number_format((float) $payment->amount, 0) }}</td>
                            <td class="ltr-num">{{ $payment->check_due_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="ltr-num">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                            <td><span class="badge {{ $payment->isCheckDue() ? 'badge-error' : 'badge-warning' }}">{{ $payment->isCheckDue() ? 'הגיע מועד הפירעון' : 'ממתין לפירעון' }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
