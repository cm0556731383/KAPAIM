<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>תודה — כפיים</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #286E9A;
            --color-success: #16A34A;
            --color-success-bg: #DCFCE7;
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
        h1 { font-size: 22px; font-weight: 600; margin: 0 0 8px; }
        p { color: var(--color-text-secondary); font-size: 15px; line-height: 1.6; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">כפיים</div>
        <div class="icon">
            <svg width="28" height="28" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9.5"/>
                <path d="M7.5 12.3l3 3 6-6.2"/>
            </svg>
        </div>
        <h1>קיבלתי, תודה!</h1>
        <p>
            אישור הקבלה עבור חומרי הלימוד
            @if ($materialDelivery->program?->name)
                "<strong>{{ $materialDelivery->program->name }}</strong>"
            @endif
            נקלט בהצלחה. תודה שאישרתם!
        </p>
    </div>
</body>
</html>
