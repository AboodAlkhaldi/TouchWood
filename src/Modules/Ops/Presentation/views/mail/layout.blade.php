<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand }}</title>
</head>
@php($start = $direction === 'rtl' ? 'right' : 'left')
<body style="margin: 0; padding: 0; background-color: #ffffff; font-family: Arial, sans-serif; line-height: 1.6; color: #0b2031;">
    {{-- The one look of every email Ops sends (flags-2026-11.md S5): a navy band with the logo and the
         store's name, a copper line, a white body, a cream footer. Email HTML: tables and inline colours
         only - mail apps drop CSS variables and style blocks. No dark version (F10); no contact lines (F9).
         The logo is linked at its address on APP_URL: EmailSender takes the body as HTML and text only. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">
        <tr>
            <td align="center" style="background-color: #02365e; padding: 16px 24px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; max-width: 100%;">
                    <tr>
                        <td align="{{ $start }}" style="width: 40px; vertical-align: middle;"><img src="{{ $logo }}" width="40" height="40" alt="{{ $brand }}" style="display: block; border: 0;"></td>
                        <td align="{{ $start }}" style="vertical-align: middle; padding-{{ $start }}: 12px; color: #ffffff; font-size: 18px; font-weight: bold;">{{ $brand }}</td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr><td style="background-color: #dca071; height: 3px; line-height: 3px; font-size: 0;">&nbsp;</td></tr>
        <tr>
            <td align="center" style="background-color: #ffffff; padding: 24px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; max-width: 100%;">
                    <tr>
                        <td align="{{ $start }}" style="color: #0b2031; font-size: 15px;">
                            @yield('body')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td align="center" style="background-color: #f6efe8; padding: 20px 24px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; max-width: 100%;">
                    <tr>
                        <td align="{{ $start }}" style="color: #53626b; font-size: 12px;">
                            <strong style="color: #0b2031;">{{ $brand }}</strong><br>
                            {{ trans('ops::email.sent_by', ['store' => $brand], $locale) }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
