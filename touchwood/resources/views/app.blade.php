<!DOCTYPE html>
{{--
| The shell every page is rendered into (frontend.md §1.3, §2.1).
|
| The language, the direction and the theme are decided on the server and written here, so the very
| first paint is already right: no flash of the wrong theme, and no moment of left-to-right before
| an Arabic page turns around - but for the "System" theme, which only the device knows: a one-line
| script in the head sets the dark mode from it before the first paint. Everything else the SSR
| renderer produces is the markup a browser would.
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

    {{-- System (frontend.md §1.11; owner, 2026-10-02): the device decides, and the server cannot
         see the device, so it rendered light. These lines run before anything is painted and turn
         the page dark first when the device is - the way Geist's own setup does it, so nothing
         flashes. A Content-Security-Policy added at hosting needs this script's hash or a nonce. --}}
    @if (($page['props']['theme']['choice'] ?? 'system') === 'system')
        <script>if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.dataset.mode = 'dark';</script>
    @endif

    <title inertia>{{ config('app.name') }}</title>

    {{-- The icons are the owner's files of 2026-10-07 (frontend.md §1.11): the tab's, a simpler mark
         on the navy tile that still reads at 32 px - the SVG, and the same drawn at 32 px for a
         browser without SVG icons; a phone's home screen gets the navy square, edge to edge, which
         the phone rounds itself (iPhones from apple-touch-icon, Android from the manifest). --}}
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">

    {{-- The design's fonts, downloaded at build time and served from this domain (decision of
         2026-09-19): no page asks a font service for anything. --}}
    @fonts

    {{-- A campaign an admin made, written as the same custom properties the stylesheet uses, so a
         new campaign is a record and a poster rather than a deploy (owner, 2026-09-22). Empty for
         the campaign that ships with the system, whose values are already in themes.css. --}}
    @if (! empty($page['props']['theme']['style'] ?? null))
        <style id="tw-campaign">{!! $page['props']['theme']['style'] !!}</style>
    @endif

    {{-- Each page is its own file (frontend.md §5). Naming this page's here - Laravel's own Inertia
         setup - has the browser fetch it with the app rather than after it, so the page draws at
         once instead of one round trip later. --}}
    @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
    @inertiaHead
</head>
<body class="font-sans bg-page text-ink antialiased">
@inertia
</body>
</html>
