<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $brand }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1f2937;">
    {{-- A security message (ops.md §1.5 C18), as Access's own email showed it. The logo on its cream
         tile (frontend.md §1.11) is linked at its address on APP_URL: Ops sends through EmailSender,
         which takes the body as HTML and text only, so the image cannot be embedded. --}}
    <p><img src="{{ $logo }}" width="96" height="96" alt="TouchWood" style="display: block; border: 0;"></p>

    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach

    @if ($link)
        <p><a href="{{ $link }}">{{ $action }}</a></p>
        <p style="color: #6b7280; font-size: 12px;">{{ $link }}</p>
    @endif
</body>
</html>
