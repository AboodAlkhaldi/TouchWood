<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1f2937;">
    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach

    @if ($link)
        <p><a href="{{ $link }}">{{ $action }}</a></p>
        <p style="color: #6b7280; font-size: 12px;">{{ $link }}</p>
    @endif
</body>
</html>
