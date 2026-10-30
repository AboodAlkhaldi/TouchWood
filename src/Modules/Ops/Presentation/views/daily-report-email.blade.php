<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #0b2031;">
    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach
</body>
</html>
