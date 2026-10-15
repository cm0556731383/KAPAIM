{{--
    The income statement's printable body for reports/statement-pdf.blade.php
    (mpdf) — the screen (⚡cashflow-report.blade.php) has its own collapsible
    layout. Tables only, no flexbox/grid, since mpdf supports neither.
    $statement is RevenueReport::statement().
--}}
@php
    $money = fn (float $amount) => ($amount < 0 ? '-' : '').'₪'.number_format(abs($amount), floor(abs($amount)) == abs($amount) ? 0 : 2);
@endphp

<table class="st-table">
    <tr class="st-section"><td colspan="2">הכנסות</td></tr>
    @forelse ($statement['revenue'] as $line)
        <tr class="st-line">
            <td>{{ $line['label'] }}</td>
            <td class="st-amount"><span class="ltr-num">{{ $money($line['amount']) }}</span></td>
        </tr>
    @empty
        <tr class="st-line st-empty"><td colspan="2">אין הכנסות בתקופה שנבחרה</td></tr>
    @endforelse
    <tr class="st-total">
        <td>סה״כ הכנסות</td>
        <td class="st-amount"><span class="ltr-num">{{ $money($statement['revenue_total']) }}</span></td>
    </tr>

    <tr class="st-gap"><td colspan="2"></td></tr>

    <tr class="st-section"><td colspan="2">הוצאות</td></tr>
    @forelse ($statement['expenses'] as $line)
        <tr class="st-line">
            <td>{{ $line['label'] }} <span class="st-muted">({{ $line['count'] === 1 ? 'הוצאה אחת' : $line['count'].' הוצאות' }})</span></td>
            <td class="st-amount"><span class="ltr-num">{{ $money($line['amount']) }}</span></td>
        </tr>
    @empty
        <tr class="st-line st-empty"><td colspan="2">אין הוצאות בתקופה שנבחרה</td></tr>
    @endforelse
    <tr class="st-total">
        <td>סה״כ הוצאות</td>
        <td class="st-amount"><span class="ltr-num">{{ $money($statement['expense_total']) }}</span></td>
    </tr>

    <tr class="st-gap"><td colspan="2"></td></tr>

    <tr class="st-net {{ $statement['profit'] < 0 ? 'st-loss' : 'st-profit' }}">
        <td>{{ $statement['profit'] < 0 ? 'הפסד' : 'רווח נקי' }}</td>
        <td class="st-amount"><span class="ltr-num">{{ $money($statement['profit']) }}</span></td>
    </tr>
</table>

<p class="st-note">הכנסה ממנוי שנתי נפרשת על פני 12 חודשי המנוי. הכנסות מסווגות לפי התוכנית או המארז של העסקה, והוצאות לפי הספק.</p>
