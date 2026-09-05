<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }} — עסקה #{{ $document->deal_id }} — כפיים</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #286E9A;
            --color-text-primary: #20241F;
            --color-text-secondary: #5C6B5F;
            --color-border: #DAD7C7;
            --color-background: #EFEEE5;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Heebo', system-ui, -apple-system, sans-serif;
            color: var(--color-text-primary);
            background: var(--color-background);
            margin: 0;
            padding: 40px;
            line-height: 1.7;
        }
        .sheet {
            max-width: 780px;
            margin: 0 auto;
            background: #FFFFFF;
            border: 1px solid var(--color-border);
            border-radius: 10px;
            padding: 48px;
        }
        h1 { font-weight: 500; font-size: 28px; margin: 0 0 4px; }
        .meta { color: var(--color-text-secondary); font-size: 14px; margin-bottom: 32px; }
        .content { white-space: pre-wrap; font-size: 16px; margin-bottom: 32px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-bottom: 24px; }
        th, td { text-align: start; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
        .total-row td { font-weight: 700; font-size: 18px; border-top: 2px solid var(--color-text-primary); border-bottom: none; }
        .ltr-num { unicode-bidi: isolate; direction: ltr; display: inline-block; white-space: nowrap; }
        .print-actions { max-width: 780px; margin: 0 auto 16px; text-align: end; }
        .print-actions button {
            font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
            background: var(--color-primary); color: #fff; border: none; border-radius: 6px; padding: 10px 18px;
        }
        @media print {
            .print-actions { display: none; }
            body { padding: 0; background: #fff; }
            .sheet { border: none; border-radius: 0; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="print-actions"><button type="button" onclick="window.print()">הדפסה / שמירה כ-PDF</button></div>
    <div class="sheet">
        <h1>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }}</h1>
        <p class="meta">
            עסקה #{{ $document->deal_id }} · {{ $document->deal->customer->school?->name }}
            @if ($document->businessEntity) · עוסק: {{ $document->businessEntity->name }} ({{ $document->businessEntity->classification }}) @endif
            · הופק בתאריך <span class="ltr-num">{{ $document->created_at->format('d/m/Y') }}</span>
        </p>

        <div class="content">{{ $document->rendered_content }}</div>

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
    </div>
</body>
</html>
