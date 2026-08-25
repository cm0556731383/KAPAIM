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

<div class="min-h-screen flex flex-col items-center justify-center px-4" style="background: var(--color-background);">
    <div class="flex items-center gap-2 mb-6" style="font-family: var(--font-display); font-weight:500; font-size:22px; color: var(--color-text-primary);">
        <svg class="hand-mark" fill="currentColor" style="width:17px; height:20px; color: var(--color-primary);"><use href="#hand-mark-shape"></use></svg>
        כפיים
    </div>

    <div class="card w-full" style="max-width: 380px;">
        {{ $slot }}
    </div>
</div>

@livewireScripts
</body>
</html>
