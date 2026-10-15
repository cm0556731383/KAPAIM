{{--
    The income statement's printable body for reports/statement-pdf.blade.php
    (mpdf) — the screen (⚡cashflow-report.blade.php) has its own collapsible
    layout. Tables only, no flexbox/grid, since mpdf supports neither.
    $statement is RevenueReport::statement(); $detail is 'summary' (the three
    totals only) or 'full' (every program/supplier and its individual
    payments/expenses).
--}}
@php
    $money = fn (float $amount) => ($amount < 0 ? '-' : '').'₪'.number_format(abs($amount), floor(abs($amount)) == abs($amount) ? 0 : 2);
@endphp

@if ($detail === 'summary')
    {{-- Short version: the three totals only. --}}
    <table class="st-table">
        <tr class="st-total">
            <td>הכנסות</td>
            <td class="st-amount"><span class="ltr-num">{{ $money($statement['revenue_total']) }}</span></td>
        </tr>
        <tr class="st-gap"><td colspan="2"></td></tr>
        <tr class="st-total">
            <td>הוצאות</td>
            <td class="st-amount"><span class="ltr-num">{{ $money($statement['expense_total']) }}</span></td>
        </tr>
        <tr class="st-gap"><td colspan="2"></td></tr>
        <tr class="st-net {{ $statement['profit'] < 0 ? 'st-loss' : 'st-profit' }}">
            <td>{{ $statement['profit'] < 0 ? 'הפסד' : 'רווח נקי' }}</td>
            <td class="st-amount"><span class="ltr-num">{{ $money($statement['profit']) }}</span></td>
        </tr>
    </table>
@else
    {{-- Detailed version: every program/supplier, each followed by its individual payments/expenses. --}}
    <table class="st-table">
        @foreach ([['title' => 'הכנסות', 'lines' => $statement['revenue'], 'total' => $statement['revenue_total'], 'empty' => 'אין הכנסות בתקופה שנבחרה'],
                   ['title' => 'הוצאות', 'lines' => $statement['expenses'], 'total' => $statement['expense_total'], 'empty' => 'אין הוצאות בתקופה שנבחרה']] as $section)
            <tr class="st-section"><td colspan="2">{{ $section['title'] }}</td></tr>
            @forelse ($section['lines'] as $line)
                <tr class="st-line">
                    <td>{{ $line['label'] }}</td>
                    <td class="st-amount"><span class="ltr-num">{{ $money($line['amount']) }}</span></td>
                </tr>
                @foreach ($line['items'] as $item)
                    <tr class="st-item">
                        <td><span class="ltr-num">{{ $item['date'] }}</span> · {{ $item['description'] }}</td>
                        <td class="st-amount"><span class="ltr-num">{{ $money($item['amount']) }}</span></td>
                    </tr>
                @endforeach
            @empty
                <tr class="st-line st-empty"><td colspan="2">{{ $section['empty'] }}</td></tr>
            @endforelse
            <tr class="st-total">
                <td>סה״כ {{ $section['title'] }}</td>
                <td class="st-amount"><span class="ltr-num">{{ $money($section['total']) }}</span></td>
            </tr>
            <tr class="st-gap"><td colspan="2"></td></tr>
        @endforeach
        <tr class="st-net {{ $statement['profit'] < 0 ? 'st-loss' : 'st-profit' }}">
            <td>{{ $statement['profit'] < 0 ? 'הפסד' : 'רווח נקי' }}</td>
            <td class="st-amount"><span class="ltr-num">{{ $money($statement['profit']) }}</span></td>
        </tr>
    </table>
@endif

<p class="st-note">הכנסה ממנוי נפרשת שווה בשווה על פני {{ \App\Models\Subscription::TOTAL_DELIVERIES }} חודשי המנוי, ובמנוי שבוטל נספרים רק החודשים שלפני הביטול. הכנסות מסווגות לפי התוכנית או המארז של העסקה, והוצאות לפי הספק.</p>
