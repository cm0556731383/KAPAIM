<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>תשלום — כפיים</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #286E9A;
            --color-success: #16A34A;
            --color-success-bg: #DCFCE7;
            --color-warning: #B45309;
            --color-warning-bg: #FEF3C7;
            --color-text-primary: #20241F;
            --color-text-secondary: #5C6B5F;
            --color-border: #DAD7C7;
            --color-background: #EFEEE5;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: 'Heebo', system-ui, -apple-system, sans-serif;
            color: var(--color-text-primary);
            background: var(--color-background);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            max-width: 420px;
            width: 100%;
            background: #FFFFFF;
            border: 1px solid var(--color-border);
            border-radius: 14px;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 20px 45px -14px rgb(28 35 31 / 0.30);
        }
        .brand { font-weight: 500; font-size: 20px; margin-bottom: 24px; color: var(--color-primary); }
        .icon {
            width: 56px; height: 56px; border-radius: 50%;
            background: var(--color-success-bg); color: var(--color-success);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
        }
        .icon.pending { background: var(--color-warning-bg); color: var(--color-warning); }
        h1 { font-size: 22px; font-weight: 600; margin: 0 0 8px; }
        p { color: var(--color-text-secondary); font-size: 15px; line-height: 1.6; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">כפיים</div>
        @if ($state === 'paid')
            <div class="icon">
                <svg width="28" height="28" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"/></svg>
            </div>
            <h1>התשלום התקבל, תודה!</h1>
            <p>שולמו <strong>₪{{ number_format((float) $link->amount, 2) }}</strong>. הקבלה נשלחה אלייך במייל.</p>
        @elseif ($state === 'cancelled')
            <div class="icon pending">
                <svg width="28" height="28" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round"><path d="M12 7v6M12 17h.01"/></svg>
            </div>
            <h1>התשלום לא בוצע</h1>
            <p>לא חויבת. אפשר לחזור לקישור שבמייל ולשלם מתי שנוח לך@if ($link->expires_at) (בתוקף עד <span dir="ltr">{{ $link->expires_at->format('d/m/Y') }}</span>)@endif.</p>
        @else
            <div class="icon pending">
                <svg width="28" height="28" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round"><path d="M12 7v5l3 2"/></svg>
            </div>
            <h1>התשלום בבדיקה</h1>
            <p>עדיין לא קיבלנו אישור על התשלום. אם שילמת, האישור והקבלה יגיעו בדקות הקרובות — אין צורך לשלם שוב.</p>
        @endif
    </div>
</body>
</html>
