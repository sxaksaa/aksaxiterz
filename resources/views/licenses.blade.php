@extends('layouts.app')

@section('content')
    <div class="max-w-5xl mx-auto px-4 sm:px-6 md:px-12 py-6 md:py-10">

        <h1 class="text-xl sm:text-2xl font-semibold mb-6 md:mb-8 fade-up">
            My Licenses
        </h1>

        <div class="grid gap-4 md:gap-6">

            @forelse($licenses as $license)
                <div class="fade-up card-hover glow-hover bg-[#15151B] border border-[#27272A] rounded-xl p-4 md:p-6">

                    <!-- TOP -->
                    <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4">

                        <div>
                            <h2 class="font-semibold text-base sm:text-lg flex items-center gap-2 flex-wrap">
                                {{ $license->product->name ?? 'Product' }}

                                @if ($loop->first)
                                    <span class="text-[10px] sm:text-xs bg-[#9333EA]/20 text-[#C084FC] px-2 py-1 rounded">
                                        NEW
                                    </span>
                                @endif
                            </h2>

                            <p class="text-xs sm:text-sm text-gray-400">
                                {{ str_replace(['1 Hari', '7 Hari', '30 Hari', 'Hari'], ['1 Day', '7 Days', '30 Days', 'Days'], $license->duration) }}
                            </p>

                            <p class="text-[10px] sm:text-xs text-gray-500 mt-1">
                                Purchased: {{ $license->created_at->format('d M Y, H:i') }}
                            </p>
                        </div>

                        <!-- STATUS -->
                        <span
                            class="self-start sm:self-auto px-3 py-1 rounded-lg text-xs sm:text-sm bg-green-500/20 text-green-400">
                            Active
                        </span>

                    </div>

                    <!-- KEY -->
                    <div
                        class="bg-black/40 border border-[#27272A] rounded-lg px-3 sm:px-4 py-3 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">

                        <span id="key-{{ $license->id }}" class="font-mono text-xs sm:text-sm text-gray-300 break-all">
                            {{ $license->license_key }}
                        </span>

                        <button onclick="copyKey(event, '{{ $license->id }}')"
                            class="text-xs sm:text-sm text-[#C084FC] hover:text-white transition btn-press self-end sm:self-auto">
                            Copy
                        </button>

                    </div>

                </div>

            @empty
                <p class="text-gray-400 fade-up text-sm">No licenses yet</p>
            @endforelse

        </div>

    </div>

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
