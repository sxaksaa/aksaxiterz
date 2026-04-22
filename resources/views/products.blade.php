<!DOCTYPE html>
<html>
<head>
    <title>Aksaxiterz Store</title>
    @vite('resources/css/app.css')
</head>

<body class="bg-[#0f172a] text-white">

<!-- NAVBAR -->
<div class="bg-white/5 backdrop-blur-md border-b border-white/10 p-4 flex justify-between items-center">

    <h1 class="font-semibold text-lg text-blue-300">
        Aksaxiterz
    </h1>

    <div>
        @auth
            <span class="mr-4 text-gray-300">
                {{ auth()->user()->name }}
            </span>

            <a href="/dashboard" class="text-gray-300 hover:text-blue-400 mr-4 transition">
                Dashboard
            </a>

            <a href="/licenses" class="text-gray-300 hover:text-blue-400 mr-4 transition">
                Licenses
            </a>

            <form action="/logout" method="POST" class="inline">
                @csrf
                <button class="text-gray-400 hover:text-red-400 transition">Logout</button>
            </form>
        @else
            <a href="/auth/google" class="text-gray-300 hover:text-blue-400">
                Login
            </a>
        @endauth
    </div>

</div>

<!-- CONTENT -->
<div class="max-w-6xl mx-auto p-6">

    <!-- TITLE -->
    <h1 class="text-4xl font-semibold text-center mb-10 text-white/90">
        Aksaxiterz Store
    </h1>

    <!-- PRODUCTS -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        @foreach($products as $product)
        <div class="bg-white/5 backdrop-blur-md border border-white/10 
        p-6 rounded-2xl 
        shadow-lg 
        hover:shadow-blue-500/10 
        transition duration-300">

            <h2 class="text-xl font-medium mb-2 text-white">
                {{ $product->name }}
            </h2>

            <p class="text-gray-400 mb-5 text-sm">
                {{ $product->description }}
            </p>

            <div class="flex justify-between items-center">

                <span class="text-blue-400 font-semibold text-lg">
                    Rp {{ number_format($product->price) }}
                </span>

                <form action="/checkout/{{ $product->id }}" method="GET">
                    <button class="px-4 py-2 rounded-xl 
                    bg-gradient-to-r from-blue-500 to-blue-600 
                    hover:from-blue-600 hover:to-blue-700 
                    transition duration-300 
                    shadow-md shadow-blue-500/20">
                        Beli
                    </button>
                </form>

            </div>

        </div>
        @endforeach

    </div>

</div>

</body>
</html>