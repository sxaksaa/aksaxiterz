<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aksaxiterz</title>
    @vite('resources/css/app.css')
</head>

<body class="text-white antialiased">

    @include('partials.navbar')

    @yield('content')

</body>

</html>
