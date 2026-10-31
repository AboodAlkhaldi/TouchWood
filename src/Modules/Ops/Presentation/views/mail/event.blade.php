<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $brand }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1f2937;">
    {{-- A message about a moment (ops.md §1.5, §1.6), with the store's logo and name (round 1 #23).
         The logo is linked at its address on APP_URL: EmailSender takes the body as HTML and text only.
         Every value is escaped: staff's words (a reason) and names come from other modules. --}}
    <p><img src="{{ $logo }}" width="96" height="96" alt="{{ $brand }}" style="display: block; border: 0;"></p>

    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach

    @if ($summary !== [])
        <table role="presentation" cellpadding="6" cellspacing="0" style="border-collapse: collapse; margin: 8px 0;">
            @foreach ($summary as $row)
                <tr>
                    <td style="border-bottom: 1px solid #e5e7eb;">{{ $row['label'] }}</td>
                    <td style="border-bottom: 1px solid #e5e7eb; white-space: nowrap;" dir="ltr">{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($link)
        <p><a href="{{ $link }}">{{ $action }}</a></p>
    @endif

    <p style="color: #6b7280; font-size: 12px;">{{ $brand }}</p>
</body>
</html>
