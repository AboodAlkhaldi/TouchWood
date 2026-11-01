<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $brand }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #0b2031;">
    {{-- With the store's logo and name (ops.md §1.1); every store's report, TouchWood's own. --}}
    <p><img src="{{ $logo }}" width="96" height="96" alt="{{ $brand }}" style="display: block; border: 0;"></p>

    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach

    <p style="color: #6b7280; font-size: 12px;">{{ $brand }}</p>
</body>
</html>
