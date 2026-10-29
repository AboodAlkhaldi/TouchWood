<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ $brand }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1f2937;">
    {{-- What staff wrote (ops.md §1.7, §1.8), or the newsletter's confirmation (§1.9). Staff's own
         text keeps its line breaks and nothing else: every line is escaped. --}}
    @foreach ($lines as $line)
        <p>{!! nl2br(e($line)) !!}</p>
    @endforeach

    @if ($link)
        <p><a href="{{ $link }}">{{ $action }}</a></p>
    @endif

    @if ($unsubscribe)
        <p style="color: #6b7280; font-size: 12px;">{{ $leave['text'] }} <a href="{{ $unsubscribe }}" style="color: #6b7280;">{{ $leave['action'] }}</a></p>
    @endif
</body>
</html>
