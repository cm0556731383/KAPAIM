<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>דוח הכנסות והוצאות — כפיים</title>
    <style>
        {{-- mpdf: DejaVu Sans (bundled, covers Hebrew), literal hex colors (no custom properties), tables only — same constraints as documents/pdf.blade.php. --}}
        body { font-family: 'DejaVu Sans', sans-serif; color: #20241F; direction: rtl; font-size: 12px; line-height: 1.6; }
        .head { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .head td { vertical-align: bottom; padding: 0; border: none; }
        h1 { font-size: 22px; font-weight: bold; margin: 0; color: #286E9A; }
        .meta { color: #5C6B5F; font-size: 11px; margin: 4px 0 0; }
        .rule { border-top: 3px solid #286E9A; margin: 14px 0 18px; }
        .ltr-num { direction: ltr; unicode-bidi: embed; }

        .st-table { width: 100%; border-collapse: collapse; }
        .st-table td { padding: 7px 12px; }
        .st-section td { background: #286E9A; color: #FFFFFF; font-weight: bold; font-size: 13px; }
        .st-line td { border-bottom: 1px solid #E6E3D6; }
        .st-empty td { color: #5C6B5F; }
        .st-item td { font-size: 10px; color: #5C6B5F; padding: 4px 12px 4px 12px; padding-right: 30px; border-bottom: 1px dotted #E6E3D6; }
        .st-total td { font-weight: bold; background: #EFEEE5; border-top: 1.5px solid #20241F; }
        .st-gap td { height: 12px; padding: 0; }
        .st-net td { font-weight: bold; font-size: 16px; padding: 12px; }
        .st-profit td { background: #DCFCE7; color: #166534; }
        .st-loss td { background: #FEE2E2; color: #B91C1C; }
        .st-amount { text-align: left; white-space: nowrap; width: 30%; }
        .st-muted { color: #5C6B5F; font-size: 10px; }

        .st-note { color: #5C6B5F; font-size: 9.5px; margin-top: 22px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width:68%">
                <h1>דוח הכנסות והוצאות{{ $detail === 'summary' ? ' — מקוצר' : ' — מפורט' }}</h1>
                <p class="meta">{{ $statement['period'] }}</p>
                <p class="meta">הופק בתאריך <span class="ltr-num">{{ now()->format('d/m/Y') }}</span></p>
            </td>
            <td style="width:32%; text-align:left">
                {{-- mpdf sizes an image by its attributes, not the stylesheet's height. --}}
                <img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/logo.png'))) }}" style="height:17mm; width:auto">
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    @include('reports.statement-body', ['statement' => $statement, 'detail' => $detail])
</body>
</html>
