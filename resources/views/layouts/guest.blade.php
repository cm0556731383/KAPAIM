<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'כפיים' }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('images/favicon-512.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/favicon-180.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-background text-text-primary font-body">

<div class="min-h-screen flex flex-col items-center justify-center px-4" style="background: var(--color-background);">
    <img src="{{ asset('images/logo-transparent.png') }}" alt="כפיים — חווית תוכן מהדהדת" class="mb-6" style="display:block; height:72px; width:auto">

    <div class="card w-full" style="max-width: 380px;">
        {{ $slot }}
    </div>

    @include('partials.site-credit')
</div>

@livewireScripts
</body>
</html>
