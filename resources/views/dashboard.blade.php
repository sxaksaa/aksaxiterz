<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-gray-100">

<div class="max-w-4xl mx-auto p-6">

    <h1 class="text-2xl font-bold mb-4">
        👋 Halo, {{ auth()->user()->name }}
    </h1>

    <h2 class="text-xl mb-4">🔑 My Licenses</h2>

    @forelse($licenses as $license)
        <div class="bg-white p-4 rounded-lg shadow mb-4 flex justify-between items-center">

            <div>
                <p class="text-gray-500 text-sm">Product ID: {{ $license->product_id }}</p>
                <h3 class="font-bold text-lg">{{ $license->license_key }}</h3>
            </div>

            <button onclick="copyText('{{ $license->license_key }}')" 
                class="bg-blue-500 text-white px-3 py-1 rounded">
                Copy
            </button>

        </div>
    @empty
        <p class="text-gray-500">Belum ada license 😢</p>
    @endforelse

</div>

<script>
function copyText(text) {
    navigator.clipboard.writeText(text);
    alert("License copied!");
}
</script>

</body>
</html>