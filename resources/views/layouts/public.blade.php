<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'כפיים' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #286E9A;
            --color-error: #DC2626;
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
            padding: 32px 16px;
            line-height: 1.7;
        }
        .sheet {
            max-width: 720px;
            margin: 0 auto;
            background: #FFFFFF;
            border: 1px solid var(--color-border);
            border-radius: 14px;
            padding: 40px;
            box-shadow: 0 20px 45px -14px rgb(28 35 31 / 0.20);
        }
        .brand { margin-bottom: 24px; }
        .brand img { height: 48px; display: block; }
        h1 { font-size: 24px; font-weight: 600; margin: 0 0 4px; }
        .meta { color: var(--color-text-secondary); font-size: 14px; margin-bottom: 28px; }
        .content { white-space: pre-wrap; font-size: 16px; margin-bottom: 28px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 24px; }
        th, td { text-align: start; padding: 8px 10px; border-bottom: 1px solid var(--color-border); }
        .total-row td { font-weight: 700; font-size: 17px; border-top: 2px solid var(--color-text-primary); border-bottom: none; }
        .ltr-num { unicode-bidi: isolate; direction: ltr; display: inline-block; white-space: nowrap; }
        .field { margin-bottom: 18px; }
        .field label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        .field input {
            width: 100%; font-family: inherit; font-size: 15px;
            padding: 10px 12px; border: 1px solid var(--color-border); border-radius: 8px;
        }
        .field .required { color: var(--color-error); }
        .field-readonly {
            font-size: 15px; color: var(--color-text-secondary);
            background: var(--color-background); border-radius: 8px; padding: 10px 12px;
        }
        .error-banner { background: #FEE2E2; color: var(--color-error); border-radius: 8px; padding: 10px 14px; font-size: 14px; margin-bottom: 20px; }
        .btn-confirm {
            width: 100%; font-family: inherit; font-size: 16px; font-weight: 600; cursor: pointer;
            background: var(--color-primary); color: #fff; border: none; border-radius: 8px; padding: 14px 18px;
        }
        .confirmed-banner {
            background: var(--color-success-bg); color: var(--color-success);
            border-radius: 8px; padding: 14px 18px; font-size: 15px; font-weight: 600; text-align: center;
        }
    </style>
    @livewireStyles
</head>
<body>
    <div class="sheet">
        <div class="brand"><img src="{{ asset('images/logo.png') }}" alt="כפיים"></div>
        {{ $slot }}
    </div>
    @livewireScripts
</body>
</html>
