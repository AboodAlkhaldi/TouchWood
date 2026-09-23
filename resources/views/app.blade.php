<!DOCTYPE html>
{{--
| The shell every page is rendered into (frontend.md §1.3, §2.1).
|
| The language, the direction and the theme are decided on the server and written here, so the very
| first paint is already right: no flash of the wrong theme, and no moment of left-to-right before
| an Arabic page turns around. The SSR renderer produces the same markup a browser would, because
| none of this is decided in the browser.
--}}
<html lang="{{ $page['props']['locale'] ?? app()->getLocale() }}"
      dir="{{ $page['props']['direction'] ?? 'rtl' }}"
      data-theme="{{ $page['props']['theme'] ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name') }}</title>

    {{-- The design's fonts, downloaded at build time and served from this domain (decision of
         2026-09-19): no page asks a font service for anything. --}}
    @fonts

    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans bg-page text-ink antialiased">
@inertia
</body>
</html>
