<?php

namespace App\Support;

/**
 * The branded HTML shell (logo header + white card + footer, per
 * docs/brand-guidelines.md's palette/Heebo) every outgoing email is wrapped
 * in, plus the bulletproof (table-based) CTA button markup used inside it.
 * Used by SmoveClient for every Smove-sent business email — every outgoing
 * email in this app goes through Smove, none through Laravel Mail.
 * $innerHtml/$label are always the caller's own already-built,
 * already-escaped content — this only adds presentation around it, never
 * touches the words themselves.
 */
final class BrandedEmail
{
    public static function wrap(string $innerHtml): string
    {
        return view('emails.layout', [
            'innerHtml' => $innerHtml,
            'logoUrl' => asset('images/logo.png'),
        ])->render();
    }

    /**
     * The main call-to-action of an email (e.g. "לצפייה במסמך, מילוי פרטים
     * ואישור מקוון") — large, bold, pill-shaped with a soft shadow, meant to
     * draw the eye. See subtleButton() below for the deliberately
     * de-emphasized counterpart (2026-09-08 request).
     */
    public static function primaryButton(string $url, string $label): string
    {
        $url = e($url);
        $label = e($label);

        return <<<HTML
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:28px 0;">
            <tr>
            <td align="center" style="border-radius:32px; background-color:#286E9A; box-shadow:0 8px 20px -6px rgba(40,110,154,0.5);">
            <a href="{$url}" target="_blank" style="display:inline-block; padding:18px 44px; font-family:Heebo, Arial, sans-serif; font-size:17px; font-weight:700; letter-spacing:0.3px; color:#FFFFFF; text-decoration:none; border-radius:32px;">{$label}</a>
            </td>
            </tr>
            </table>
            HTML;
    }

    /**
     * A secondary, low-emphasis action (e.g. "קבלת המסמך כ-PDF במייל") —
     * small, pale, outlined rather than filled, meant to sit quietly below
     * the main call-to-action rather than compete with it (2026-09-08
     * request: smaller, lower, "anemic").
     */
    public static function subtleButton(string $url, string $label): string
    {
        $url = e($url);
        $label = e($label);

        return <<<HTML
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:24px 0 4px;">
            <tr>
            <td align="center" style="border-radius:6px; border:1px solid #DAD7C7;">
            <a href="{$url}" target="_blank" style="display:inline-block; padding:8px 18px; font-family:Heebo, Arial, sans-serif; font-size:12px; font-weight:500; color:#5C6B5F; text-decoration:none; border-radius:6px;">{$label}</a>
            </td>
            </tr>
            </table>
            HTML;
    }

    /**
     * Plain text as escaped paragraphs, each token replaced by its block's
     * HTML — a block whose token the text doesn't place is appended at the
     * end, in the given order.
     *
     * @param  array<string, string>  $blocks  token => HTML
     */
    public static function textWithBlocks(string $body, array $blocks): string
    {
        $missing = array_filter($blocks, fn (string $token) => ! str_contains($body, $token), ARRAY_FILTER_USE_KEY);
        $parts = $blocks
            ? preg_split('/('.implode('|', array_map(fn ($token) => preg_quote($token, '/'), array_keys($blocks))).')/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE)
            : [$body];

        $html = collect($parts)
            ->map(fn (string $part) => $blocks[$part] ?? (trim($part) === '' ? '' : '<p>'.nl2br(e(trim($part, "\n"))).'</p>'))
            ->implode('');

        return $html.implode('', $missing);
    }

    /**
     * The owner's own uploaded HTML, used as is, with each token replaced by
     * its block — a block whose token the file doesn't place goes just
     * before </body> (or at the very end when there is none).
     *
     * @param  array<string, string>  $blocks  token => HTML
     */
    public static function htmlWithBlocks(string $html, array $blocks): string
    {
        $missing = '';

        foreach ($blocks as $token => $block) {
            str_contains($html, $token) ? $html = str_replace($token, $block, $html) : $missing .= $block;
        }

        if ($missing === '') {
            return $html;
        }

        $bodyEnd = strripos($html, '</body>');

        return $bodyEnd === false ? $html.$missing : substr_replace($html, $missing, $bodyEnd, 0);
    }

    /**
     * The attached materials as a list of links (files are kept on our side
     * and linked — see MaterialDeliveryAttachment::downloadUrl()).
     *
     * @param  array<int, array{name: string, url: string}>  $links
     */
    public static function linksList(array $links): string
    {
        return '<ul style="padding-inline-start:20px;">'.collect($links)
            ->map(fn (array $a) => '<li style="margin-bottom:8px;"><a href="'.e($a['url']).'" style="color:#286E9A; font-weight:600;">'.e($a['name']).'</a></li>')
            ->implode('').'</ul>';
    }

    /** The brand's blue — the buttons' color when the email has no color of its own (docs/brand-guidelines.md). */
    public const BRAND_COLOR = '#286E9A';

    /**
     * The two buttons at the bottom of every materials email: "להורדת
     * החומרים" (filled — every file at once, MaterialDelivery::downloadAllUrl())
     * and "קיבלתי, תודה" (outlined — MaterialDelivery::acknowledge()), side
     * by side and centered, in $color. No download button when nothing was
     * uploaded (only links, listed above them).
     */
    public static function materialsButtons(?string $downloadUrl, string $acknowledgeUrl, string $color = self::BRAND_COLOR): string
    {
        $color = e($color);
        $text = e(self::readableTextOn($color));
        // On white the outline button's words take the color itself — unless it is too light to read there.
        $outlineText = $text === '#FFFFFF' ? $color : '#1F2A22';
        $font = 'font-family:Heebo, Arial, sans-serif; font-size:16px; font-weight:700; text-decoration:none; border-radius:32px; display:inline-block; padding:14px 32px;';
        $cells = [];

        if ($downloadUrl !== null) {
            $cells[] = '<td align="center" style="padding:6px;"><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td align="center" style="border-radius:32px; background-color:'.$color.'; box-shadow:0 8px 20px -8px '.$color.';">'
                .'<a href="'.e($downloadUrl).'" target="_blank" style="'.$font.' color:'.$text.'; border:2px solid '.$color.';">להורדת החומרים</a></td></tr></table></td>';
        }

        $cells[] = '<td align="center" style="padding:6px;"><table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr><td align="center" style="border-radius:32px; background-color:#FFFFFF;">'
            .'<a href="'.e($acknowledgeUrl).'" target="_blank" style="'.$font.' color:'.$outlineText.'; border:2px solid '.$color.';">קיבלתי, תודה</a></td></tr></table></td>';

        return '<table role="presentation" align="center" dir="rtl" border="0" cellpadding="0" cellspacing="0" style="margin:28px auto 12px;"><tr>'.implode('', $cells).'</tr></table>';
    }

    /**
     * The main color of the owner's own HTML — the one its backgrounds use
     * most (a header, a button), else the one it uses most at all; greys,
     * near-white and near-black don't count. Null when it has none.
     */
    public static function accentColor(string $html): ?string
    {
        $color = '#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}[^)]*\)';
        $weights = [];

        preg_match_all('/(?:background(?:-color)?\s*:[^;"\'>]*?|bgcolor\s*=\s*["\']?)('.$color.')/i', $html, $backgrounds);
        preg_match_all('/'.$color.'/i', $html, $all);

        foreach ([[$backgrounds[1], 3], [$all[0], 1]] as [$found, $weight]) {
            foreach ($found as $raw) {
                $hex = self::toHex($raw);

                if ($hex !== null && self::isAccent($hex)) {
                    $weights[$hex] = ($weights[$hex] ?? 0) + $weight;
                }
            }
        }

        if (! $weights) {
            return null;
        }

        arsort($weights);

        return array_key_first($weights);
    }

    /** White on a dark enough color, the brand's dark green otherwise (WCAG contrast 3:1 for large bold text). */
    private static function readableTextOn(string $hex): string
    {
        [$r, $g, $b] = array_map(function (int $c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, sscanf($hex, '#%02x%02x%02x'));
        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return 1.05 / ($luminance + 0.05) >= 3 ? '#FFFFFF' : '#1F2A22';
    }

    private static function toHex(string $raw): ?string
    {
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $raw, $m)) {
            return strtoupper("#{$m[1]}{$m[1]}{$m[2]}{$m[2]}{$m[3]}{$m[3]}");
        }

        if (preg_match('/^#[0-9a-f]{6}$/i', $raw)) {
            return strtoupper($raw);
        }

        if (preg_match('/rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+))?/i', $raw, $m)) {
            // A see-through color isn't what the eye sees.
            if (isset($m[4]) && (float) $m[4] < 0.6) {
                return null;
            }

            return sprintf('#%02X%02X%02X', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return null;
    }

    /** A real color — not grey, near-white or near-black (HSL saturation/lightness). */
    private static function isAccent(string $hex): bool
    {
        [$r, $g, $b] = array_map(fn (int $c) => $c / 255, sscanf($hex, '#%02x%02x%02x'));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $saturation = $max === $min ? 0 : ($max - $min) / (1 - abs(2 * $lightness - 1));

        return $saturation >= 0.25 && $lightness >= 0.15 && $lightness <= 0.85;
    }
}
