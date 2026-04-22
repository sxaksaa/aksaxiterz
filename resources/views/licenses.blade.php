@extends('layouts.app')

@section('content')

<div class="max-w-5xl mx-auto px-12 py-10">

    <h1 class="text-2xl font-semibold mb-8 fade-up">
        My Licenses
    </h1>

    <div class="grid gap-6">

        @forelse($licenses as $license)
        <div class="fade-up card-hover glow-hover bg-[#15151B] border border-[#27272A] rounded-xl p-6">

            <!-- TOP -->
            <div class="flex justify-between items-center mb-4">

                <div>
                    <h2 class="font-semibold text-lg flex items-center gap-2">
                        {{ $license->product->name ?? 'Product' }}

                        @if($loop->first)
                        <span class="text-xs bg-[#9333EA]/20 text-[#C084FC] px-2 py-1 rounded">
                            NEW
                        </span>
                        @endif
                    </h2>

                    <p class="text-sm text-gray-400">
                        {{ $license->duration }}
                    </p>

                    <p class="text-xs text-gray-500 mt-1">
                        Dibeli: {{ $license->created_at->format('d M Y, H:i') }}
                    </p>
                </div>

                <!-- STATUS -->
                <span class="px-3 py-1 rounded-lg text-sm bg-green-500/20 text-green-400">
                    Active
                </span>

            </div>

            <!-- KEY -->
            <div class="bg-black/40 border border-[#27272A] rounded-lg px-4 py-3 flex justify-between items-center">

                <span id="key-{{ $license->id }}" class="font-mono text-sm text-gray-300">
                    {{ $license->license_key }}
                </span>

                <button onclick="copyKey(event, '{{ $license->id }}')" 
                class="text-sm text-[#C084FC] hover:text-white transition btn-press">
                    Copy
                </button>

            </div>

        </div>

        @empty
        <p class="text-gray-400 fade-up">Belum ada license 😢</p>
        @endforelse

    </div>

</div>

<style>
/* FADE UP */
.fade-up {
    animation: fadeUp 0.5s ease;
}

@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(15px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* CARD HOVER */
.card-hover {
    transition: all 0.25s ease;
}

.card-hover:hover {
    transform: translateY(-4px) scale(1.01);
}

/* BUTTON PRESS */
.btn-press:active {
    transform: scale(0.96);
}

/* GLOW */
.glow-hover:hover {
    box-shadow: 0 0 20px rgba(147, 51, 234, 0.25);
}
</style>

<script>
function copyKey(event, id) {

    const text = document.getElementById('key-' + id).innerText;

    navigator.clipboard.writeText(text);

    const btn = event.target;
    btn.innerText = "Copied!";
    btn.classList.add("text-green-400");

    setTimeout(() => {
        btn.innerText = "Copy";
        btn.classList.remove("text-green-400");
    }, 1200);
}
</script>

@endsection