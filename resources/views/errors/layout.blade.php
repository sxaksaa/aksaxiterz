<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') - Aksa Xiterz</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css'])
</head>

<body class="text-white antialiased">
    <main class="page-shell flex min-h-screen items-center justify-center px-4 py-12">
        <section class="product-section w-full max-w-2xl text-center" aria-labelledby="errorTitle">
            <a href="{{ url('/') }}" class="mx-auto inline-flex" aria-label="Aksa Xiterz home">
                <img src="{{ asset('images/brand/aksa-xiterz-logo.png') }}" alt="Aksa Xiterz"
                    class="h-12 w-auto" width="612" height="195" decoding="async">
            </a>

            <p class="mt-8 text-sm font-semibold uppercase text-aksa-accent">@yield('code')</p>
            <h1 id="errorTitle" class="mt-2 text-3xl font-bold text-white md:text-4xl">@yield('heading')</h1>
            <p class="mx-auto mt-4 max-w-lg text-sm leading-6 text-gray-400 md:text-base">@yield('message')</p>

            <a href="{{ url('/') }}" class="btn-main mt-7 px-5 py-3">Back to Products</a>
        </section>
    </main>
</body>

</html>
