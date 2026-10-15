{{--
    The income statement itself — shared by ⚡cashflow-report.blade.php (on
    screen) and reports/statement-pdf.blade.php (mpdf). Tables only, no
    flexbox/grid, since mpdf supports neither; each side styles the st-*
    classes for its own medium. $statement is RevenueReport::statement().
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

@if (count($statement['months']) > 1)
    <div class="st-subtitle">פירוט לפי חודש</div>
    <div class="st-scroll">
    <table class="st-months">
        <thead>
            <tr><th>חודש</th><th class="st-amount">הכנסות</th><th class="st-amount">הוצאות</th><th class="st-amount">רווח</th></tr>
        </thead>
        <tbody>
            @foreach ($statement['months'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="st-amount"><span class="ltr-num">{{ $money($row['revenue']) }}</span></td>
                    <td class="st-amount"><span class="ltr-num">{{ $money($row['expense']) }}</span></td>
                    <td class="st-amount {{ $row['profit'] < 0 ? 'st-neg' : 'st-pos' }}"><span class="ltr-num">{{ $money($row['profit']) }}</span></td>
                </tr>
            @endforeach
            <tr class="st-months-total">
                <td>סה״כ</td>
                <td class="st-amount"><span class="ltr-num">{{ $money($statement['revenue_total']) }}</span></td>
                <td class="st-amount"><span class="ltr-num">{{ $money($statement['expense_total']) }}</span></td>
                <td class="st-amount {{ $statement['profit'] < 0 ? 'st-neg' : 'st-pos' }}"><span class="ltr-num">{{ $money($statement['profit']) }}</span></td>
            </tr>
        </tbody>
    </table>
    </div>
@endif

<p class="st-note">הכנסה ממנוי שנתי נפרשת על פני 12 חודשי המנוי. הכנסות מסווגות לפי התוכנית או המארז של העסקה, והוצאות לפי הספק.</p>
