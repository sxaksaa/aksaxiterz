<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Aksa Xiterz</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/aksa-xiterz-mark.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/aksa-xiterz-mark.png') }}">
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="text-white antialiased">

    @include('partials.navbar')

    @yield('content')

    @include('partials.footer')

    <div id="appToast" class="app-toast" data-variant="info" role="status" aria-live="polite">
        <div id="appToastTitle" class="app-toast-title">Notice</div>
        <div id="appToastMessage" class="app-toast-message"></div>
    </div>

    @stack('scripts')
</body>

</html>
