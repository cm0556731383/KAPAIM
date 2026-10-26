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
}
