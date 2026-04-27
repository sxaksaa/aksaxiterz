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
    @vite('resources/css/app.css')
</head>

<body class="text-white antialiased">

    @include('partials.navbar')

    @yield('content')

    @include('partials.footer')

    <div id="appToast" class="app-toast" data-variant="info" role="status" aria-live="polite">
        <div id="appToastTitle" class="app-toast-title">Notice</div>
        <div id="appToastMessage" class="app-toast-message"></div>
    </div>

    <script>
        let appToastTimer = null;

        window.showAppToast = function(title, message = '', options = {}) {
            const toast = document.getElementById('appToast');
            const toastTitle = document.getElementById('appToastTitle');
            const toastMessage = document.getElementById('appToastMessage');

            if (!toast || !toastTitle || !toastMessage) return;

            const variant = options.variant || 'info';
            const duration = Number(options.duration || 3400);

            toast.dataset.variant = variant;
            toastTitle.innerText = title;
            toastMessage.innerText = message;
            toast.classList.add('is-visible');

            clearTimeout(appToastTimer);

            if (options.redirectAfter) {
                appToastTimer = setTimeout(() => {
                    window.location.href = options.redirectAfter;
                }, options.redirectDelay || 900);
                return;
            }

            appToastTimer = setTimeout(() => {
                toast.classList.remove('is-visible');
            }, duration);
        };

        ['contextmenu', 'copy', 'cut', 'dragstart', 'selectstart'].forEach((eventName) => {
            document.addEventListener(eventName, (event) => event.preventDefault());
        });
    </script>
</body>

</html>
