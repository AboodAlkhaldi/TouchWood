<!DOCTYPE html>
{{--
| The shell every page is rendered into (frontend.md §1.3, §2.1).
|
| The language, the direction and the theme are decided on the server and written here, so the very
| first paint is already right: no flash of the wrong theme, and no moment of left-to-right before
| an Arabic page turns around. The SSR renderer produces the same markup a browser would, because
| none of this is decided in the browser.
--}}
{{-- The campaign and the mode are separate: a campaign has its own light and its own dark. --}}
<html lang="{{ $page['props']['locale'] ?? app()->getLocale() }}"
      dir="{{ $page['props']['direction'] ?? 'rtl' }}"
      data-campaign="{{ $page['props']['theme']['campaign'] ?? 'base' }}"
      data-mode="{{ $page['props']['theme']['mode'] ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name') }}</title>

    {{-- The design's fonts, downloaded at build time and served from this domain (decision of
         2026-09-19): no page asks a font service for anything. --}}
    @fonts

    {{-- A campaign an admin made, written as the same custom properties the stylesheet uses, so a
         new campaign is a record and a poster rather than a deploy (owner, 2026-09-22). Empty for
         the campaign that ships with the system, whose values are already in themes.css. --}}
    @if (! empty($page['props']['theme']['style'] ?? null))
        <style id="tw-campaign">{!! $page['props']['theme']['style'] !!}</style>
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans bg-page text-ink antialiased">
@inertia
</body>
</html>
