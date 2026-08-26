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

    <g id="icon-defs">
        <symbol id="icon-dashboard" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/>
            <rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/>
            <rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/>
            <rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>
        </symbol>
        <symbol id="icon-leads" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 4h16l-6.5 8.5v6L10.5 20v-7.5z"/>
        </symbol>
        <symbol id="icon-customers" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4.5 21V10l7.5-5 7.5 5v11"/>
            <path d="M4 21h16"/>
            <rect x="10" y="14" width="4" height="7"/>
        </symbol>
        <symbol id="icon-payments" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="6" width="18" height="13" rx="2.5"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
            <line x1="6" y1="15" x2="10.5" y2="15"/>
        </symbol>
        <symbol id="icon-mailing" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="5" width="18" height="14" rx="2"/>
            <path d="M3.5 6.5 12 13 20.5 6.5"/>
        </symbol>
        <symbol id="icon-suppliers" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 9.3 5 4h14l1 5.3"/>
            <path d="M3.5 9.3h17l-.6 2.2a2.2 2.2 0 0 1-4.3.2 2.2 2.2 0 0 1-4.3 0 2.2 2.2 0 0 1-4.3 0 2.2 2.2 0 0 1-4.3-.2Z"/>
            <path d="M5.3 11.8V20h13.4v-8.2"/>
            <path d="M10 20v-5h4v5"/>
        </symbol>
        <symbol id="icon-catalog" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 5.3c0-1 .8-1.5 1.9-1.3l5.1 1v14l-5.1-1c-1.1-.2-1.9-.5-1.9-1.5Z"/>
            <path d="M20 5.3c0-1-.8-1.5-1.9-1.3l-5.1 1v14l5.1-1c1.1-.2 1.9-.5 1.9-1.5Z"/>
            <line x1="11" y1="5" x2="11" y2="19"/>
        </symbol>
        <symbol id="icon-documents" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M7 3h6.5L18 7.5V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/>
            <path d="M13.5 3v4.5H18"/>
            <line x1="9" y1="13" x2="15" y2="13"/>
            <line x1="9" y1="16.5" x2="15" y2="16.5"/>
        </symbol>
        <symbol id="icon-settings" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/>
            <line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/>
            <line x1="4" y1="18" x2="20" y2="18"/><circle cx="10" cy="18" r="2"/>
        </symbol>
        <symbol id="icon-integrations" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 2.5v4"/><path d="M15 2.5v4"/>
            <path d="M6 6.5h12v3a6 6 0 0 1-12 0Z"/>
            <path d="M12 15.5v6"/>
        </symbol>
        <symbol id="icon-users" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="8.5" cy="8" r="3"/>
            <path d="M3 20c0-3.3 2.5-5.7 5.5-5.7s5.5 2.4 5.5 5.7"/>
            <circle cx="17" cy="9" r="2.3"/>
            <path d="M15 14.7c2.5.3 4.5 2.6 4.5 5.3"/>
        </symbol>
        <symbol id="icon-activity" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"/>
            <path d="M12 7v5l3.5 2"/>
        </symbol>
        <symbol id="icon-import" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3v11"/>
            <path d="M8 10l4 4 4-4"/>
            <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
        </symbol>
        <symbol id="icon-search" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="10.5" cy="10.5" r="6.5"/>
            <line x1="20" y1="20" x2="15.3" y2="15.3"/>
        </symbol>
        <symbol id="icon-logout" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h9"/>
            <path d="M10 12h11m0 0-3.5-3.5M21 12l-3.5 3.5"/>
        </symbol>
    </g>
</svg>

<div class="app">
    <aside class="sidebar">
        <div class="brand"><svg fill="currentColor"><use href="#hand-mark-shape"></use></svg>כפיים</div>

        <div class="sidebar-scroll">
            <nav>
                <div class="nav-group-label">עבודה יומית</div>
                <a href="{{ url('/') }}" class="{{ request()->is('/') ? 'active' : '' }}"><svg><use href="#icon-dashboard"></use></svg>מסך עבודה</a>
                @foreach ([
                    ['חיפוש גלובלי', 'icon-search'],
                ] as [$label, $icon])
                    <span class="nav-disabled" title="ייבנה בשלב עתידי"><svg><use href="#{{ $icon }}"></use></svg>{{ $label }}</span>
                @endforeach
                <a href="{{ route('leads') }}" class="{{ request()->routeIs('leads') || request()->routeIs('lead-detail') ? 'active' : '' }}"><svg><use href="#icon-leads"></use></svg>לידים</a>
                <a href="{{ route('customers') }}" class="{{ request()->routeIs('customers') || request()->routeIs('customer-detail') ? 'active' : '' }}"><svg><use href="#icon-customers"></use></svg>לקוחות</a>
                <a href="{{ route('collections') }}" class="{{ request()->routeIs('collections') ? 'active' : '' }}"><svg><use href="#icon-payments"></use></svg>גבייה ותשלומים</a>
                @foreach ([
                    ['חומרים ורשימות תפוצה', 'icon-mailing'],
                    ['ספקים והוצאות', 'icon-suppliers'],
                ] as [$label, $icon])
                    <span class="nav-disabled" title="ייבנה בשלב עתידי"><svg><use href="#{{ $icon }}"></use></svg>{{ $label }}</span>
                @endforeach
            </nav>
            <nav>
                <div class="nav-group-label">ניהול ומערכת</div>
                <a href="{{ route('programs-catalog') }}" class="{{ request()->routeIs('programs-catalog') ? 'active' : '' }}"><svg><use href="#icon-catalog"></use></svg>קטלוג תוכניות</a>
                <a href="{{ route('document-templates') }}" class="{{ request()->routeIs('document-templates') ? 'active' : '' }}"><svg><use href="#icon-documents"></use></svg>תבניות מסמכים</a>
                <a href="{{ route('settings') }}" class="{{ request()->routeIs('settings') ? 'active' : '' }}"><svg><use href="#icon-settings"></use></svg>הגדרות מערכת</a>
                <span class="nav-disabled" title="ייבנה בשלב עתידי"><svg><use href="#icon-integrations"></use></svg>אינטגרציות</span>
                <a href="{{ route('users-roles') }}" class="{{ request()->routeIs('users-roles') ? 'active' : '' }}"><svg><use href="#icon-users"></use></svg>משתמשות והרשאות</a>
                <a href="{{ route('activity-log') }}" class="{{ request()->routeIs('activity-log') ? 'active' : '' }}"><svg><use href="#icon-activity"></use></svg>יומן פעילות</a>
                <span class="nav-disabled" title="ייבנה בשלב עתידי"><svg><use href="#icon-import"></use></svg>ייבוא נתונים</span>
            </nav>
        </div>
    </aside>

    <main>
        @auth
            <div class="topbar-user" style="justify-content:flex-end; margin-bottom: var(--sp-md);">
                <span>{{ auth()->user()->name }} · {{ auth()->user()->role?->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost" style="padding:6px 12px;"><svg style="width:15px;height:15px;"><use href="#icon-logout"></use></svg>יציאה</button>
                </form>
            </div>
        @endauth

        {{ $slot }}
    </main>
</div>

@livewireScripts
</body>
</html>
