<!DOCTYPE html>
<html>
<head>
    <title>Aksaxiterz</title>
    @vite('resources/css/app.css')
</head>

<body class="bg-[#0B0B0F] text-white antialiased">

    @include('partials.navbar')

    @yield('content')

</body>
</html>