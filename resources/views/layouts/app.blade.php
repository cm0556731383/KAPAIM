<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'כפיים' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-background text-text-primary font-body">

<svg width="0" height="0" style="position:absolute">
    <symbol id="hand-mark-shape" viewBox="0 0 120 140">
        <rect x="32" y="44" width="10" height="32" rx="5" transform="rotate(-16 37 76)"/>
        <rect x="47" y="30" width="11" height="46" rx="5.5" transform="rotate(-6 52.5 76)"/>
        <rect x="61" y="24" width="12" height="52" rx="6" transform="rotate(2 67 76)"/>
        <rect x="75" y="31" width="11" height="44" rx="5.5" transform="rotate(11 80.5 76)"/>
        <rect x="17" y="68" width="14" height="32" rx="7" transform="rotate(-46 24 84)"/>
        <ellipse cx="59" cy="88" rx="38" ry="33"/>
    </symbol>
</svg>

<div class="grid min-h-screen" style="grid-template-columns: 248px 1fr;">

    <aside class="flex flex-col max-h-screen sticky top-0 px-4 pb-4 pt-6" style="background:#16262D; color:#A7B9C0;">
        <div class="flex items-center gap-2 mb-6 pb-4 px-1" style="font-family: var(--font-display); font-weight:500; font-size:21px; color:#F0F4F6; border-bottom:1px solid rgba(240,244,246,.09);">
            <svg class="hand-mark" fill="currentColor" style="width:15px; height:18px; color: var(--color-accent);"><use href="#hand-mark-shape"></use></svg>
            כפיים
        </div>

        <div class="flex-1 overflow-y-auto">
            <nav class="flex flex-col gap-0.5">
                <div class="px-3 pt-3 pb-1.5" style="font-size:11px; font-weight:700; letter-spacing:.07em; color:#63767E; text-transform:uppercase;">עבודה יומית</div>
                @foreach (['מסך עבודה', 'חיפוש גלובלי', 'לידים', 'לקוחות', 'גבייה ותשלומים', 'חומרים ורשימות תפוצה', 'ספקים והוצאות'] as $item)
                    <span class="flex items-center gap-3 px-3 py-2 rounded-control" style="font-size: var(--fs-small); font-weight:500; color:#63767E; border-inline-start:2px solid transparent;">{{ $item }}</span>
                @endforeach
            </nav>
            <nav class="flex flex-col gap-0.5">
                <div class="px-3 pt-3 pb-1.5" style="font-size:11px; font-weight:700; letter-spacing:.07em; color:#63767E; text-transform:uppercase;">ניהול ומערכת</div>
                @foreach (['קטלוג תוכניות', 'תבניות מסמכים', 'הגדרות מערכת', 'אינטגרציות', 'משתמשות והרשאות', 'יומן פעילות', 'ייבוא נתונים'] as $item)
                    <span class="flex items-center gap-3 px-3 py-2 rounded-control" style="font-size: var(--fs-small); font-weight:500; color:#63767E; border-inline-start:2px solid transparent;">{{ $item }}</span>
                @endforeach
            </nav>
        </div>
    </aside>

    <main class="px-8 py-8">
        {{ $slot }}
    </main>

</div>

@livewireScripts
</body>
</html>
