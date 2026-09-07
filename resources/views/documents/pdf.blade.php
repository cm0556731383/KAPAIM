<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }} — עסקה #{{ $document->deal_id }} — כפיים</title>
    <style>
        {{-- mpdf has no network access for Google Fonts and limited CSS support (no flexbox/grid) — DejaVu Sans is bundled with mpdf and covers Hebrew correctly (dompdf, tried first, does not apply the Unicode bidi algorithm at all and renders Hebrew unreadable — see routes/web.php's documents.pdf route). --}}
        * { box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            color: #20241F;
            margin: 0;
            padding: 32px;
            line-height: 1.7;
            direction: rtl;
        }
        .brand { margin-bottom: 24px; }
        .brand img { height: 40px; }
        h1 { font-weight: normal; font-size: 24px; margin: 0 0 4px; }
        .meta { color: #5C6B5F; font-size: 13px; margin-bottom: 28px; }
        {{-- font-size/line-height matches resources/views/layouts/public.blade.php's .content exactly, so the printed/downloaded document reads the same as the online form. --}}
        .content { white-space: pre-wrap; font-size: 16px; line-height: 1.9; margin-bottom: 28px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 20px; }
        th, td { text-align: right; padding: 6px 10px; border-bottom: 1px solid #DAD7C7; }
        .total-row td { font-weight: bold; font-size: 15px; border-top: 2px solid #20241F; border-bottom: none; }
        .ltr-num { direction: ltr; unicode-bidi: embed; }
        .signature { margin-top: 56px; font-size: 14px; color: #5C6B5F; }
        .signature table { border-collapse: collapse; }
        .signature td { border: none; padding: 0 32px 0 0; }
    </style>
</head>
<body>
    {{-- Embedded as a base64 data URI, not a public_path()/asset() URL — mpdf's file/network access is
         locked down by default, and a data URI always works regardless of that config. --}}
    <div class="brand"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/logo.png'))) }}"></div>
    <h1>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }}</h1>
    <p class="meta">
        עסקה #{{ $document->deal_id }} · {{ $document->deal->customer->school?->name }}
        @if ($document->businessEntity) · עוסק: {{ $document->businessEntity->name }} ({{ $document->businessEntity->classification }}) @endif
        · הופק בתאריך <span class="ltr-num">{{ $document->created_at->format('d/m/Y') }}</span>
    </p>

    {{-- printFriendlyContent(), not rendered_content: any field still empty gets a blank line to fill by
         hand right where it sits in the text, instead of the online form's interactive input (FR-4.10/4.11). --}}
    <div class="content">{!! $document->printFriendlyContent() !!}</div>

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
</body>
</html>
