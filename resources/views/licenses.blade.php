@extends('layouts.app')

@section('content')
    @php
        $discordUrl = config('links.discord_url');
        $licenseCount = $licenses->count();
        $latestLicense = $licenses->first();
    @endphp

    <div class="page-shell py-6 md:py-10">

        <section class="license-hero mb-6 fade-up">
            <div class="grid gap-5 md:grid-cols-[1fr_auto] md:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">License Vault</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">My Licenses</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Your paid license keys are stored here. Copy the key you need and download the matching tools
                        when you are ready to set up.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 md:min-w-72">
                    <div class="license-stat">
                        <div class="text-xl font-semibold text-white">{{ $licenseCount }}</div>
                        <div class="mt-1 text-xs text-gray-400">Active licenses</div>
                    </div>
                    <div class="license-stat">
                        <div class="text-xl font-semibold text-white">
                            {{ $latestLicense?->created_at?->format('d M') ?? '-' }}
                        </div>
                        <div class="mt-1 text-xs text-gray-400">Latest purchase</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="discord-mini-panel mb-5 fade-up md:p-5">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-white">Need setup help or a license reset?</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        Contact support for setup questions, reset requests, and license delivery help.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="support-pill">Customer support</span>
                        <span class="support-pill">License reset</span>
                        <span class="support-pill">Setup guidance</span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="/downloads"
                        class="inline-flex items-center justify-center rounded-lg border border-[#27272A] px-3 py-2 text-xs font-semibold text-gray-300 transition hover:text-white">
                        Download Tools
                    </a>
                    <a href="{{ $discordUrl ?: '#' }}"
                        @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                        class="discord-cta px-3 py-2 text-xs {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                        Join Discord
                    </a>
                </div>
            </div>
        </div>

        <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Keys</p>
                <h2 class="mt-1 text-2xl font-semibold text-white">Available licenses</h2>
            </div>
            <a href="/downloads" class="btn-footer-secondary w-fit">Download Tools</a>
        </div>

        <div class="grid gap-4 md:gap-6">

            @forelse($licenses as $license)
                <div class="license-card motion-card p-4 md:p-6">

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
                        <span class="status-pill status-pill-paid self-start sm:self-auto">
                            Active
                        </span>

                    </div>

                    <!-- KEY -->
                    <div class="license-key-box flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                        <span id="key-{{ $license->id }}" class="font-mono text-xs sm:text-sm text-gray-300 break-all">
                            {{ $license->license_key }}
                        </span>

                        <button onclick="copyKey(event, '{{ $license->id }}')"
                            class="order-action btn-press self-end sm:self-auto">
                            Copy
                        </button>

                    </div>

                </div>

            @empty
                <div class="empty-state fade-up">
                    No licenses yet
                </div>
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
