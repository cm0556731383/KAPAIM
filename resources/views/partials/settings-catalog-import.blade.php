{{-- Settings → יבוא נתונים → תוכניות ומארזים (App\Services\CatalogImport), included from ⚡settings. --}}
@php($canImport = auth()->user()->hasPermission('settings', 'edit') && auth()->user()->hasPermission('catalog', 'edit'))
@php($sheetNames = \App\Services\CatalogImport::SHEETS)
<section class="settings-section">
    <div class="section-head">
        <h2>יבוא תוכניות ומארזים מאקסל</h2>
    </div>

    <div class="cols2">
        <div class="card">
            <h3 style="margin:0 0 4px">1. הורדת הקובץ למילוי</h3>
            <a href="{{ route('settings.import-catalog-template') }}" class="btn btn-secondary">הורדת קובץ האקסל</a>
        </div>

        <div class="card">
            <h3 style="margin:0 0 4px">2. העלאת הקובץ המלא לבדיקה</h3>
            <form wire:submit="checkCatalogImport" style="display:flex; flex-direction:column; gap:var(--sp-sm)">
                <input type="file" id="catalogFile" wire:model="catalogFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                @error('catalogFile') <div style="{{ $errStyle }}">{{ $message }}</div> @enderror
                <div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="catalogFile,checkCatalogImport">
                        <span wire:loading.remove wire:target="checkCatalogImport">בדיקת הקובץ</span>
                        <span wire:loading wire:target="checkCatalogImport">בודקת…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($catalogResult)
        <div class="card" style="margin-top: var(--sp-md)">
            <h3 style="margin:0 0 var(--sp-sm)">✓ היבוא הסתיים: נוספו {{ $catalogResult['programs'] }} תוכניות ו־{{ $catalogResult['bundles'] }} מארזים</h3>
            @if (! empty($catalogResult['skipped']))
                <p style="{{ $errStyle }}">{{ $catalogResult['skipped'] }} שורות עם שגיאות לא יובאו. אפשר להעלות את הקובץ שוב כדי לתקן אותן.</p>
            @endif
            <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                @can('catalog.view')<a href="{{ route('programs-catalog') }}" class="btn btn-primary">לקטלוג התוכניות</a>@endcan
                <button type="button" wire:click="resetCatalogImport" class="btn btn-ghost">יבוא קובץ נוסף</button>
            </div>
        </div>
    @elseif ($catalogCheck)
        <div class="card" style="margin-top: var(--sp-md)">
            @if ($catalogCheck['fileError'])
                <p style="color: var(--color-error); margin:0 0 var(--sp-sm)">{{ $catalogCheck['fileError'] }}</p>
                <button type="button" wire:click="resetCatalogImport" class="btn btn-ghost">העלאת קובץ אחר</button>
            @else
                @php($valid = $catalogCheck['valid'])
                @php($invalid = $catalogCheck['invalid'])
                <h3 style="margin:0 0 var(--sp-sm)">תוצאות הבדיקה</h3>
                <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap; margin-bottom:var(--sp-md)">
                    <span class="badge badge-neutral">{{ $catalogCheck['total'] }} שורות בקובץ</span>
                    <span class="badge badge-success">{{ count($valid) }} תקינות</span>
                    @if ($invalid)
                        <span class="badge badge-error">{{ count($invalid) }} עם שגיאות</span>
                    @endif
                </div>

                {{-- 1. Valid rows — what will be added --}}
                <div class="import-group" x-data="{ open: {{ $invalid ? 'false' : 'true' }} }">
                    <button type="button" class="import-group-head" @click="open = !open" :aria-expanded="open">
                        <span class="import-group-arrow" :class="open && 'open'">◀</span>
                        <span class="badge badge-success">{{ count($valid) }}</span>
                        <strong>תוכניות ומארזים תקינים</strong>
                    </button>
                    <div x-show="open" x-cloak class="import-group-body">
                        @if ($valid)
                            <div class="table-scroll" style="max-height:420px; overflow-y:auto">
                            <table>
                                <thead><tr><th>גיליון</th><th>שורה</th><th>שם</th><th>מחיר 1–2 / 3–4 / 5–6</th><th>פרטים</th></tr></thead>
                                <tbody>
                                    @foreach ($valid as $row)
                                        <tr wire:key="cat-ok-{{ $row['sheet'] }}-{{ $row['row'] }}">
                                            <td>{{ $sheetNames[$row['sheet']] }}</td>
                                            <td class="ltr-num">{{ $row['row'] }}</td>
                                            <td>{{ $row['name'] }}</td>
                                            <td class="ltr-num">₪{{ number_format($row['data']['price'], 2) }} / {{ $row['data']['price_3_4'] !== null ? '₪'.number_format($row['data']['price_3_4'], 2) : '—' }} / {{ $row['data']['price_5_6'] !== null ? '₪'.number_format($row['data']['price_5_6'], 2) : '—' }}</td>
                                            <td style="font-size:var(--fs-caption)">
                                                @if (! $row['data']['is_active'])<span class="badge badge-neutral">לא פעיל</span>@endif
                                                @if ($row['data']['is_premium'] ?? false)<span class="badge badge-neutral">פרימיום</span>@endif
                                                @if ($row['data']['is_subscription_type'] ?? false)<span class="badge badge-neutral">מארז מנוי {{ $row['data']['school_year'] ? \App\Services\HebrewCalendar::schoolYearLabel($row['data']['school_year']) : '' }}</span>@endif
                                                @if ($row['data']['programs'] ?? [])<div>תוכניות: {{ implode(', ', $row['data']['programs']) }}</div>@endif
                                                @if ($row['data']['included_bundles'] ?? [])<div>מארזים כלולים: {{ implode(', ', $row['data']['included_bundles']) }}</div>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        @else
                            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">אין שורות בקבוצה הזו.</p>
                        @endif
                    </div>
                </div>

                {{-- 2. Rows with errors — fixed here, written back into the file and checked again --}}
                <div class="import-group" x-data="{ open: {{ $invalid ? 'true' : 'false' }} }" style="{{ $invalid ? 'border-color:var(--color-error)' : '' }}">
                    <button type="button" class="import-group-head" @click="open = !open" :aria-expanded="open">
                        <span class="import-group-arrow" :class="open && 'open'">◀</span>
                        <span class="badge badge-error">{{ count($invalid) }}</span>
                        <strong>שורות עם פרטים שגויים</strong>
                    </button>
                    <div x-show="open" x-cloak class="import-group-body">
                        @if ($invalid)
                            <div class="table-scroll" style="max-height:520px; overflow-y:auto">
                            <table>
                                <thead><tr><th>גיליון</th><th>שורה</th><th>שם</th><th>שדה</th><th>ערך (לתיקון)</th><th>מה שגוי</th></tr></thead>
                                <tbody>
                                    @foreach ($invalid as $row)
                                        @foreach ($row['fields'] as $key => $field)
                                            <tr wire:key="cat-fix-{{ $row['sheet'] }}-{{ $row['row'] }}-{{ $key }}">
                                                @if ($loop->first)
                                                    <td rowspan="{{ count($row['fields']) }}">{{ $sheetNames[$row['sheet']] }}</td>
                                                    <td class="ltr-num" rowspan="{{ count($row['fields']) }}">{{ $row['row'] }}</td>
                                                    <td rowspan="{{ count($row['fields']) }}">{{ $row['name'] ?: '—' }}</td>
                                                @endif
                                                <td>{{ $field['label'] }}</td>
                                                <td>
                                                    @if (in_array($key, ['flag', 'is_active'], true))
                                                        <select class="import-fix-input" wire:model="catalogFixes.{{ $row['sheet'] }}.{{ $row['row'] }}.{{ $key }}" @disabled(! $canImport)>
                                                            <option value="">—</option><option value="כן">כן</option><option value="לא">לא</option>
                                                        </select>
                                                    @elseif ($key === 'contract')
                                                        <select class="import-fix-input" wire:model="catalogFixes.{{ $row['sheet'] }}.{{ $row['row'] }}.{{ $key }}" @disabled(! $canImport)>
                                                            <option value="">החוזה הסטנדרטי</option>
                                                            @foreach (\App\Services\CatalogImport::contractNames() as $contract)
                                                                <option value="{{ $contract }}">{{ $contract }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif ($key === 'school_year')
                                                        <select class="import-fix-input" wire:model="catalogFixes.{{ $row['sheet'] }}.{{ $row['row'] }}.{{ $key }}" @disabled(! $canImport)>
                                                            <option value="">—</option>
                                                            @foreach (\App\Services\HebrewCalendar::schoolYearOptions() as $year => $label)
                                                                <option value="{{ $year }}">{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif (in_array($key, ['programs', 'included_bundles', 'description'], true))
                                                        <textarea class="import-fix-input" rows="2" wire:model="catalogFixes.{{ $row['sheet'] }}.{{ $row['row'] }}.{{ $key }}" @disabled(! $canImport)></textarea>
                                                    @else
                                                        <input type="text" class="import-fix-input" wire:model="catalogFixes.{{ $row['sheet'] }}.{{ $row['row'] }}.{{ $key }}"
                                                            @if (str_starts_with($key, 'price')) dir="ltr" inputmode="decimal" @endif @disabled(! $canImport)>
                                                    @endif
                                                </td>
                                                <td style="color: var(--color-error); font-size:var(--fs-caption)">{{ $field['message'] }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                            @if ($canImport)
                                <div style="padding-top: var(--sp-sm)">
                                    <button type="button" wire:click="fixCatalogImport" wire:loading.attr="disabled" wire:target="fixCatalogImport" class="btn btn-secondary">
                                        <span wire:loading.remove wire:target="fixCatalogImport">שמירת התיקונים ובדיקה מחדש</span>
                                        <span wire:loading wire:target="fixCatalogImport">בודקת…</span>
                                    </button>
                                </div>
                            @endif
                        @else
                            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">אין שורות עם שגיאות.</p>
                        @endif
                    </div>
                </div>

                @if ($valid)
                    <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                        @if ($canImport)
                            <button type="button" wire:click="runCatalogImport" wire:loading.attr="disabled" wire:target="runCatalogImport" class="btn btn-primary"
                                wire:confirm="להוסיף לקטלוג {{ count($valid) }} תוכניות ומארזים?">
                                ייבוא {{ count($valid) }} {{ $invalid ? 'השורות התקינות' : 'תוכניות ומארזים' }}
                            </button>
                        @else
                            <span class="text-text-secondary" style="font-size:var(--fs-caption)">ליבוא עצמו נדרשת הרשאת עריכה בהגדרות ובקטלוג התוכניות.</span>
                        @endif
                        <button type="button" wire:click="resetCatalogImport" class="btn btn-ghost">ביטול</button>
                    </div>
                @else
                    <p class="text-text-secondary" style="font-size:var(--fs-caption)">אין שורות תקינות ליבוא.</p>
                    <button type="button" wire:click="resetCatalogImport" class="btn btn-ghost">העלאת קובץ אחר</button>
                @endif
            @endif
        </div>
    @endif
</section>
