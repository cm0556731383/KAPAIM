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
}
