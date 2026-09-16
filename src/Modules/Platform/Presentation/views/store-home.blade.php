{{-- Placeholder until the Content module builds the storefront homepage. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $store->name->in($locale) }}</title>
</head>
<body>
    <main>
        <h1>{{ $store->name->in($locale) }}</h1>
        <p>{{ __('platform::stores.placeholder') }}</p>
        <p data-currency="{{ $store->currencyCode }}">{{ $store->currencySymbol($locale) }}</p>
    </main>
</body>
</html>
