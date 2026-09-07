<?php

namespace App\Support;

/**
 * The branded HTML shell (logo header + white card + footer, per
 * docs/brand-guidelines.md's palette/Heebo) every outgoing email is wrapped
 * in, plus the bulletproof (table-based) CTA button markup used inside it.
 * Shared by SmoveClient (every Smove-sent business email) and DocumentPdfMail
 * (the one send that goes through Laravel Mail instead — see that
 * mailable's docblock for why). $innerHtml/$label are always the caller's
 * own already-built, already-escaped content — this only adds presentation
 * around it, never touches the words themselves.
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

    public static function button(string $url, string $label): string
    {
        $url = e($url);
        $label = e($label);

        return <<<HTML
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:20px 0;">
            <tr>
            <td align="center" style="border-radius:6px; background-color:#286E9A;">
            <a href="{$url}" target="_blank" style="display:inline-block; padding:14px 32px; font-family:Heebo, Arial, sans-serif; font-size:16px; font-weight:600; color:#FFFFFF; text-decoration:none; border-radius:6px;">{$label}</a>
            </td>
            </tr>
            </table>
            HTML;
    }
}
