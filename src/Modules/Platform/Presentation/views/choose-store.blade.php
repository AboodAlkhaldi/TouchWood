{{-- Minimal country page. The frontend milestone replaces it with an Inertia page. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('platform::stores.choose_title') }}</title>
</head>
<body>
    <main>
        <h1>{{ __('platform::stores.choose_title') }}</h1>
        <p>{{ __('platform::stores.choose_intro') }}</p>
        <ul>
            @foreach ($stores as $store)
                <li><a href="{{ route('storefront.home', ['store' => $store->code, 'locale' => $locale]) }}">{{ $store->name->in($locale) }}</a></li>
            @endforeach
        </ul>
    </main>
</body>
</html>
