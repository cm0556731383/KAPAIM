<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }} — כפיים</title>
    <style>
        {{-- mpdf has no network access for Google Fonts and limited CSS support (no flexbox/grid) — DejaVu Sans is bundled with mpdf and covers Hebrew correctly (dompdf, tried first, does not apply the Unicode bidi algorithm at all and renders Hebrew unreadable — see routes/web.php's documents.pdf route). Colors/spacing below are otherwise a deliberate match of resources/views/layouts/public.blade.php (the online sign form), per the 2026-09-08 request that a downloaded/emailed PDF read as the same document, not a plainer one — right down to the same --color-* values, just as literal hex since mpdf's CSS support doesn't extend to custom properties. --}}
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #20241F;
            background: #FFFFFF;
            margin: 0;
            padding: 24px;
            line-height: 1.7;
            direction: rtl;
        }
        .sheet {
            background: #FFFFFF;
            padding: 40px;
        }
        .brand { margin-bottom: 24px; }
        .brand img { height: 48px; }
        h1 { font-weight: bold; font-size: 24px; margin: 0 0 4px; }
        .meta { color: #5C6B5F; font-size: 14px; margin-bottom: 28px; }
        .content { white-space: pre-wrap; font-size: 16px; margin-bottom: 28px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 24px; }
        th, td { text-align: right; padding: 8px 10px; border-bottom: 1px solid #DAD7C7; }
        .total-row td { font-weight: bold; font-size: 17px; border-top: 2px solid #20241F; border-bottom: none; }
        .ltr-num { direction: ltr; unicode-bidi: embed; }
        .signature { margin-top: 56px; font-size: 14px; color: #5C6B5F; }
        .signature table { border-collapse: collapse; }
        .signature td { border: none; padding: 0 32px 0 0; }
    </style>
</head>
<body>
<div class="sheet">
    {{-- Embedded as a base64 data URI, not a public_path()/asset() URL — mpdf's file/network access is
         locked down by default, and a data URI always works regardless of that config. --}}
    <div class="brand"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/logo.png'))) }}"></div>
    <h1>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }}</h1>
    <p class="meta">
        {{ $document->deal->customer->school?->name }}
        @if ($document->businessEntity) · עוסק: {{ $document->businessEntity->name }} ({{ $document->businessEntity->classification }}) @endif
        · הופק בתאריך <span class="ltr-num">{{ $document->created_at->format('d/m/Y') }}</span>
    </p>

    @php
        $printFriendlyContent = $document->printFriendlyContent();

        // nl2br() only for genuinely plain-text content (no tags at all) — confirmed 2026-09-08
        // that mpdf's HTML engine collapses a PLAIN template's authored line breaks (single `\n`
        // between a sign-off's name/title, double `\n\n` between paragraphs) into one run-on
        // paragraph, `white-space: pre-wrap` CSS notwithstanding, so those need an explicit <br>.
        // BUT a template authored in ⚡document-templates.blade.php's rich-text editor (real HTML,
        // e.g. content pasted from Word) already carries its own line breaks as actual <p>/<br>
        // tags — its raw `\n` characters are just source-formatting whitespace *inside* tags and
        // attributes (confirmed 2026-09-08 against a real contract: nl2br() on that content
        // inserted a literal <br> mid-`style="..."` attribute, corrupting the markup enough that
        // mpdf 500'd trying to parse it — a real production incident, not a hypothetical one).
        // strip_tags() unchanged is the plain-text tell: real markup always differs after stripping.
        $printFriendlyContent = strip_tags($printFriendlyContent) === $printFriendlyContent
            ? nl2br($printFriendlyContent)
            : $printFriendlyContent;
    @endphp
    <div class="content">{!! $printFriendlyContent !!}</div>

    @if ($document->document_type === 'invoice')
        <table>
            <thead><tr><th>תיאור</th><th>כמות</th><th>מחיר יחידה</th><th>סכום</th></tr></thead>
            <tbody>
                @foreach ($document->lines as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="ltr-num">{{ $line->quantity }}</td>
                        <td class="ltr-num">₪{{ number_format((float) $line->unit_price, 0) }}</td>
                        <td class="ltr-num">₪{{ number_format((float) $line->amount, 0) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="3">סה"כ לתשלום</td>
                    <td class="ltr-num">₪{{ number_format($document->totalAmount(), 0) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- The printed counterpart of the online form's universal "אישור וחתימה" button
         (⚡document-sign.blade.php) — a physical signature line, for whoever prints this
         instead of confirming online. --}}
    <div class="signature">
        <table>
            <tr>
                <td>חתימה: {{ str_repeat('_', 28) }}</td>
                <td>תאריך: {{ str_repeat('_', 16) }}</td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
