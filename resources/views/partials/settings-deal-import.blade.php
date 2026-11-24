{{-- Settings → יבוא נתונים → עסקאות (App\Services\DealImport), included from ⚡settings. --}}
@php($canImport = auth()->user()->hasPermission('settings', 'edit') && auth()->user()->hasPermission('customers', 'edit'))
@php($tierLabels = \App\Models\Program::CLASS_TIERS)
<section class="settings-section">
    <div class="section-head">
        <h2>יבוא עסקאות מאקסל</h2>
        <p class="hint text-text-secondary" style="font-size:var(--fs-caption)">מורידים את הקובץ, ממלאים שורה לכל עסקה ומעלים. המערכת בודקת את כל הקובץ ומציגה את השגיאות לפני שמשהו נשמר.</p>
    </div>

    <div class="cols2">
        <div class="card">
            <h3 style="margin:0 0 4px">1. הורדת הקובץ למילוי</h3>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0 0 var(--sp-md)">
                קובץ אקסל עם כל השדות של כרטיס העסקה: לקוחה (מרשימה של הלקוחות שבמערכת), תאריך רכישה, מספר כיתות לתמחור, עד {{ \App\Services\DealImport::ITEMS }} פריטים (מרשימה של התוכניות והמארזים הפעילים) עם סכום לכל אחד, מספר תלמידות, אמצעי תשלום ובקשה מיוחדת — וגם מסמכים שכבר נשלחו או נחתמו לפני המערכת: הצעת מחיר, טופס הזמנה, חוזה וחשבונית, עם מצב ותאריך לכל אחד.
                הרשימות בקובץ נלקחות מהמערכת ברגע ההורדה — אחרי הוספת לקוחות או תוכניות כדאי להוריד אותו מחדש.
            </p>
            <a href="{{ route('settings.import-deals-template') }}" class="btn btn-secondary">הורדת קובץ האקסל</a>
        </div>

        <div class="card">
            <h3 style="margin:0 0 4px">2. העלאת הקובץ המלא לבדיקה</h3>
            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0 0 var(--sp-md)">
                בדיקת הקובץ לא שומרת כלום. מסמכים שסומנו בקובץ רק נרשמים בעסקה — לא נשלחים ללקוחה ולא מופקים ב־SUMIT. תשלומים מוסיפים אחר כך בכרטיס העסקה. עסקה עם מארז מנוי פותחת את המנוי, וכל עסקה מצרפת את הלקוחה לרשימות התפוצה, כמו עסקה ידנית.
            </p>
            <form wire:submit="checkDealImport" style="display:flex; flex-direction:column; gap:var(--sp-sm)">
                <input type="file" id="dealFile" wire:model="dealFile" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                @error('dealFile') <div style="{{ $errStyle }}">{{ $message }}</div> @enderror
                <div>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="dealFile,checkDealImport">
                        <span wire:loading.remove wire:target="checkDealImport">בדיקת הקובץ</span>
                        <span wire:loading wire:target="checkDealImport">בודקת…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if ($dealImportStatus)
        @php($status = $dealImportStatus)
        <div class="card" style="margin-top: var(--sp-md)" @if (in_array($status['state'], ['queued', 'running'], true)) wire:poll.2s @endif>
            @if (in_array($status['state'], ['queued', 'running'], true))
                <h3 style="margin:0 0 var(--sp-sm)">מייבאת… {{ $status['done'] ?? 0 }} מתוך {{ $status['total'] ?? 0 }}</h3>
                <div style="height:8px; background:var(--color-border); border-radius:4px; overflow:hidden">
                    <div style="height:100%; width:{{ ($status['total'] ?? 0) ? round(100 * ($status['done'] ?? 0) / $status['total']) : 0 }}%; background:var(--color-primary); transition:width .3s"></div>
                </div>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:var(--sp-sm) 0 0">אפשר לעזוב את המסך — היבוא ממשיך ברקע.</p>
            @elseif ($status['state'] === 'done')
                <h3 style="margin:0 0 var(--sp-sm)">✓ היבוא הסתיים: נוספו {{ $status['created'] }} עסקאות</h3>
                @if (! empty($status['skipped']))
                    <p style="{{ $errStyle }}">{{ $status['skipped'] }} שורות לא יובאו כי לא עברו את הבדיקה (שגיאות שלא תוקנו, או משהו שהשתנה במערכת בינתיים). אפשר להעלות את הקובץ שוב כדי לראות אותן.</p>
                @endif
                @if (! empty($status['failed']))
                    <p style="{{ $errStyle }}">{{ count($status['failed']) }} שורות לא יובאו:</p>
                    <ul style="font-size:var(--fs-caption); margin:0 0 var(--sp-sm)">
                        @foreach ($status['failed'] as $failure)
                            <li>שורה {{ $failure['row'] }} ({{ $failure['customer'] }}): {{ $failure['error'] }}</li>
                        @endforeach
                    </ul>
                @endif
                <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                    @can('customers.view')<a href="{{ route('customers') }}" class="btn btn-primary">למסך הלקוחות</a>@endcan
                    <button type="button" wire:click="resetDealImport" class="btn btn-ghost">יבוא קובץ נוסף</button>
                </div>
            @else
                <p style="{{ $errStyle }}">{{ $status['message'] ?? 'היבוא נכשל.' }}</p>
                <button type="button" wire:click="resetDealImport" class="btn btn-ghost">התחלה מחדש</button>
            @endif
        </div>
    @elseif ($dealCheck)
        <div class="card" style="margin-top: var(--sp-md)">
            @if ($dealCheck['fileError'])
                <p style="color: var(--color-error); margin:0 0 var(--sp-sm)">{{ $dealCheck['fileError'] }}</p>
                <button type="button" wire:click="resetDealImport" class="btn btn-ghost">העלאת קובץ אחר</button>
            @else
                @php($valid = $dealCheck['valid'])
                @php($invalid = $dealCheck['invalid'])
                <h3 style="margin:0 0 var(--sp-sm)">תוצאות הבדיקה</h3>
                <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap; margin-bottom:var(--sp-md)">
                    <span class="badge badge-neutral">{{ $dealCheck['total'] }} שורות בקובץ</span>
                    <span class="badge badge-success">{{ count($valid) }} תקינות</span>
                    @if ($invalid)
                        <span class="badge badge-error">{{ count($invalid) }} עם שגיאות</span>
                    @endif
                </div>

                {{-- 1. Valid deals --}}
                <div class="import-group" x-data="{ open: {{ $invalid ? 'false' : 'true' }} }">
                    <button type="button" class="import-group-head" @click="open = !open" :aria-expanded="open">
                        <span class="import-group-arrow" :class="open && 'open'">◀</span>
                        <span class="badge badge-success">{{ count($valid) }}</span>
                        <strong>עסקאות תקינות</strong>
                        <span class="text-text-secondary" style="font-size:var(--fs-caption)">— ייווצרו בכרטיסי הלקוחות</span>
                    </button>
                    <div x-show="open" x-cloak class="import-group-body">
                        @if ($valid)
                            <div class="table-scroll" style="max-height:420px; overflow-y:auto">
                            <table>
                                <thead><tr><th>שורה</th><th>לקוחה</th><th>תאריך</th><th>פריטים</th><th>פרטים</th></tr></thead>
                                <tbody>
                                    @foreach (array_slice($valid, 0, 500) as $row)
                                        <tr wire:key="deal-ok-{{ $row['row'] }}">
                                            <td class="ltr-num">{{ $row['row'] }}</td>
                                            <td>{{ $row['data']['customer'] }}</td>
                                            <td class="ltr-num">{{ $row['data']['purchased_at'] ? \Carbon\Carbon::parse($row['data']['purchased_at'])->format('d/m/Y') : 'היום' }}</td>
                                            <td style="font-size:var(--fs-caption)">
                                                @foreach ($row['data']['items'] as $item)
                                                    <div>{{ $item['type'] === 'bundle' ? 'מארז: ' : '' }}{{ $item['name'] }} — {{ $item['amount'] !== null ? '₪'.number_format($item['amount'], 2) : 'מחיר הקטלוג' }}</div>
                                                @endforeach
                                            </td>
                                            <td style="font-size:var(--fs-caption)">
                                                @if ($row['data']['class_tier'])<div>{{ $tierLabels[$row['data']['class_tier']] }}</div>@endif
                                                @if ($row['data']['students_count'] !== null)<div>{{ $row['data']['students_count'] }} תלמידות</div>@endif
                                                @if ($row['data']['payment_method'])<div>{{ $row['data']['payment_method'] }}</div>@endif
                                                @if ($row['data']['special_request'])<div>{{ \Illuminate\Support\Str::limit($row['data']['special_request'], 60) }}</div>@endif
                                                @foreach ($row['data']['documents'] ?? [] as $document)
                                                    <div><span class="badge badge-neutral">{{ \App\Models\Document::TYPE_LABELS[$document['type']] }}: {{ \App\Models\Document::externalStates($document['type'])[$document['state']] }}{{ $document['date'] ? ' '.\Carbon\Carbon::parse($document['date'])->format('d/m/Y') : '' }}</span></div>
                                                @endforeach
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
                        <strong>עסקאות עם פרטים שגויים</strong>
                        <span class="text-text-secondary" style="font-size:var(--fs-caption)">— אפשר לתקן כאן ולבדוק שוב</span>
                    </button>
                    <div x-show="open" x-cloak class="import-group-body">
                        @if ($invalid)
                            @php($customerNames = \App\Services\DealImport::customerNames())
                            @php($itemLabels = \App\Services\DealImport::itemLabels())
                            @php($methodNames = \App\Services\DealImport::paymentMethodNames())
                            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0 0 var(--sp-sm)">
                                מתקנים בשדה ולוחצים "שמירת התיקונים ובדיקה מחדש" — שורה שתוקנה עוברת לתקינות. התיקון נשמר גם בקובץ שהועלה.
                            </p>
                            <div class="table-scroll" style="max-height:520px; overflow-y:auto">
                            <table>
                                <thead><tr><th>שורה</th><th>לקוחה</th><th>שדה</th><th>ערך (לתיקון)</th><th>מה שגוי</th></tr></thead>
                                <tbody>
                                    @foreach (array_slice($invalid, 0, 500) as $row)
                                        @foreach ($row['fields'] as $key => $field)
                                            @php($model = "dealFixes.{$row['row']}.{$key}")
                                            @php($current = $dealFixes[$row['row']][$key] ?? '')
                                            <tr wire:key="deal-fix-{{ $row['row'] }}-{{ $key }}">
                                                @if ($loop->first)
                                                    <td class="ltr-num" rowspan="{{ count($row['fields']) }}">{{ $row['row'] }}</td>
                                                    <td rowspan="{{ count($row['fields']) }}">{{ $row['customer'] ?: '—' }}</td>
                                                @endif
                                                <td>{{ $field['label'] }}</td>
                                                <td>
                                                    @if ($key === 'customer' || str_starts_with($key, 'item') || $key === 'payment_method')
                                                        @php($options = $key === 'customer' ? $customerNames : ($key === 'payment_method' ? $methodNames : $itemLabels))
                                                        <select class="import-fix-input" wire:model="{{ $model }}" @disabled(! $canImport)>
                                                            <option value="">—</option>
                                                            @if ($current !== '' && ! in_array($current, $options, true))
                                                                <option value="{{ $current }}">{{ $current }} (מהקובץ)</option>
                                                            @endif
                                                            @foreach ($options as $option)
                                                                <option value="{{ $option }}">{{ $option }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif (str_ends_with($key, '_state'))
                                                        <select class="import-fix-input" wire:model="{{ $model }}" @disabled(! $canImport)>
                                                            <option value="">— אין —</option>
                                                            @if ($current !== '' && ! in_array($current, \App\Models\Document::externalStates(substr($key, 0, -6)), true))
                                                                <option value="{{ $current }}">{{ $current }} (מהקובץ)</option>
                                                            @endif
                                                            @foreach (\App\Models\Document::externalStates(substr($key, 0, -6)) as $label)
                                                                <option value="{{ $label }}">{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif ($key === 'class_tier')
                                                        <select class="import-fix-input" wire:model="{{ $model }}" @disabled(! $canImport)>
                                                            <option value="">לפי כרטיס הלקוחה</option>
                                                            @foreach ($tierLabels as $label)
                                                                <option value="{{ $label }}">{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif ($key === 'special_request')
                                                        <textarea class="import-fix-input" rows="2" wire:model="{{ $model }}" @disabled(! $canImport)></textarea>
                                                    @else
                                                        <input type="text" class="import-fix-input" wire:model="{{ $model }}" dir="ltr"
                                                            @if ($key === 'purchased_at' || str_ends_with($key, '_date')) placeholder="31/12/2025" @elseif (str_starts_with($key, 'amount')) inputmode="decimal" placeholder="ריק = מחיר הקטלוג" @endif
                                                            @disabled(! $canImport)>
                                                    @endif
                                                </td>
                                                <td style="color: var(--color-error); font-size:var(--fs-caption)">{{ $field['message'] }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                            @if (count($invalid) > 500)
                                <p class="text-text-secondary" style="font-size:var(--fs-caption)">מוצגות 500 השורות הראשונות מתוך {{ count($invalid) }}.</p>
                            @endif
                            @if ($canImport)
                                <div style="padding-top: var(--sp-sm)">
                                    <button type="button" wire:click="fixDealImport" wire:loading.attr="disabled" wire:target="fixDealImport" class="btn btn-secondary">
                                        <span wire:loading.remove wire:target="fixDealImport">שמירת התיקונים ובדיקה מחדש</span>
                                        <span wire:loading wire:target="fixDealImport">בודקת…</span>
                                    </button>
                                </div>
                            @endif
                        @else
                            <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">אין שורות עם שגיאות.</p>
                        @endif
                    </div>
                </div>

                @if ($valid)
                    @if ($invalid)
                        <p class="text-text-secondary" style="font-size:var(--fs-caption)">
                            אפשר לתקן את הפרטים השגויים ולבדוק שוב, או לייבא עכשיו רק את השורות התקינות — השורות עם השגיאות לא ייובאו.
                        </p>
                    @endif
                    <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
                        @if ($canImport)
                            <button type="button" wire:click="runDealImport" wire:loading.attr="disabled" wire:target="runDealImport" class="btn btn-primary"
                                wire:confirm="ליצור {{ count($valid) }} עסקאות? עסקה לא נמחקת אחרי שנוצרה (אפשר רק לבטל אותה).">
                                ייבוא {{ count($valid) }} {{ $invalid ? 'השורות התקינות' : 'עסקאות' }}
                            </button>
                        @else
                            <span class="text-text-secondary" style="font-size:var(--fs-caption)">ליבוא עצמו נדרשת הרשאת עריכה בהגדרות ובלקוחות.</span>
                        @endif
                        <button type="button" wire:click="resetDealImport" class="btn btn-ghost">ביטול</button>
                    </div>
                @else
                    <p class="text-text-secondary" style="font-size:var(--fs-caption)">אין עדיין שורות תקינות ליבוא. יש לתקן את הפרטים השגויים למעלה (או בקובץ ולהעלות אותו שוב).</p>
                    <button type="button" wire:click="resetDealImport" class="btn btn-ghost">העלאת קובץ אחר</button>
                @endif
            @endif
        </div>
    @endif
</section>
