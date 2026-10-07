<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1f2937;">
    {{-- The logo on its cream tile (the owner's file of 2026-10-07, frontend.md §1.11), carried inside
         the email rather than fetched, so it shows in every inbox and needs no public address. Only
         a real send has the mail itself as $message; a preview of the email (render()) has the
         message's key under that name instead, and goes without the logo. --}}
    @if (($message ?? null) instanceof \Illuminate\Mail\Message)
        <p><img src="{{ $message->embed(public_path('icons/email-logo.png')) }}" width="96" height="96" alt="TouchWood" style="display: block; border: 0;"></p>
    @endif

    @foreach ($lines as $line)
        <p>{{ $line }}</p>
    @endforeach

    @if ($link)
        <p><a href="{{ $link }}">{{ $action }}</a></p>
        <p style="color: #6b7280; font-size: 12px;">{{ $link }}</p>
    @endif
</body>
</html>
