<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Aksaxiterz</title>
    @stack('head')
    @vite('resources/css/app.css')
</head>

<body class="text-white antialiased">

    @include('partials.navbar')

    @yield('content')

    <script>
        ['contextmenu', 'copy', 'cut', 'dragstart', 'selectstart'].forEach((eventName) => {
            document.addEventListener(eventName, (event) => event.preventDefault());
        });
    </script>
</body>

</html>
