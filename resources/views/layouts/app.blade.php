@php
    $visitorCountry = collect([
        request()->header('CF-IPCountry'),
        request()->header('CloudFront-Viewer-Country'),
        request()->header('X-Vercel-IP-Country'),
    ])
        ->map(fn ($country) => strtoupper(trim((string) $country)))
        ->first(fn ($country) => $country !== 'XX' && preg_match('/^[A-Z]{2}$/', $country)) ?? '';
    $showDisplayCurrencySwitcher = request()->is(
        '/',
        'product/*',
        'cart',
        'checkout',
        'checkout/*'
    );
@endphp

<!DOCTYPE html>
<html lang="en" data-display-currency="idr" data-visitor-country="{{ $visitorCountry }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Aksa Xiterz</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/aksa-xiterz-mark.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/aksa-xiterz-mark.png') }}">
    <script nonce="{{ request()->attributes->get('csp_nonce') }}" data-currency-prepaint>
        (() => {
            const root = document.documentElement;
            const supportedCurrencies = new Set(['idr', 'usd']);
            const indonesianTimezones = new Set([
                'Asia/Jakarta',
                'Asia/Pontianak',
                'Asia/Makassar',
                'Asia/Ujung_Pandang',
                'Asia/Jayapura',
            ]);
            let currency = null;

            try {
                const savedCurrency = String(localStorage.getItem('aksa_display_currency') || '').toLowerCase();
                currency = supportedCurrencies.has(savedCurrency) ? savedCurrency : null;
            } catch (error) {
                currency = null;
            }

            if (!currency) {
                const visitorCountry = String(root.dataset.visitorCountry || '').toUpperCase();

                if (/^[A-Z]{2}$/.test(visitorCountry)) {
                    currency = visitorCountry === 'ID' ? 'idr' : 'usd';
                } else {
                    const primaryLanguage = Array.isArray(navigator.languages) && navigator.languages.length > 0
                        ? navigator.languages[0]
                        : navigator.language;
                    const usesIndonesian = /^id(?:-|$)/i.test(String(primaryLanguage || ''));
                    let timezone = '';

                    try {
                        timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
                    } catch (error) {
                        timezone = '';
                    }

                    currency = usesIndonesian || indonesianTimezones.has(timezone) ? 'idr' : 'usd';
                }
            }

            root.dataset.displayCurrency = currency;
            root.dataset.currencyReady = 'false';
        })();
    </script>
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="text-white antialiased">

    <div data-aksa-nav-shell>
        @include('partials.navbar')
    </div>

    <main id="aksaPageContent" data-aksa-page-content>
        @yield('content')
    </main>

    <div data-aksa-footer-shell>
        @include('partials.footer')
    </div>

    <div id="appToast" class="app-toast" data-variant="info" role="status" aria-live="polite">
        <div id="appToastTitle" class="app-toast-title">Notice</div>
        <div id="appToastMessage" class="app-toast-message"></div>
    </div>

    @stack('scripts')
</body>

</html>
