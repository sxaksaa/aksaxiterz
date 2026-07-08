@extends('layouts.app')

@section('content')
    @php
        $discordUrl = config('links.discord_url');
        $licenseCount = $licenses->count();
        $latestLicense = $licenses->first();
        $licenseResetStates = $licenseResetStates ?? [];
        $orderStats = $orderStats ?? [
            'total' => 0,
            'paid' => 0,
            'pending' => 0,
        ];
        $selectedOrderId = request()->query('order');
        $selectedOrderId = is_string($selectedOrderId) ? trim($selectedOrderId) : '';
        $formatDuration = static fn ($duration) => str_replace(
            ['1 Hari', '7 Hari', '30 Hari', 'Hari'],
            ['1 Day', '7 Days', '30 Days', 'Days'],
            (string) $duration,
        );
        $licenseGroups = $licenses->groupBy(static function ($license) {
            $orderId = trim((string) $license->order_id);

            return $orderId !== '' ? 'order:' . $orderId : 'license:' . $license->id;
        });
    @endphp

    <div class="page-shell py-6 md:py-10">

        <section class="license-hero mb-6 fade-up">
            <div class="grid gap-5 md:grid-cols-[1fr_auto] md:items-end">
                <div>
                    <p class="mb-2 text-sm font-semibold text-aksa-accent">License Vault</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">My Licenses</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Your paid license keys are stored here. Copy the key you need and download the matching tools
                        when you are ready to set up.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:min-w-[420px]">
                    <div class="license-stat">
                        <div class="text-xl font-semibold text-white">{{ $licenseCount }}</div>
                        <div class="mt-1 text-xs text-gray-400">Active licenses</div>
                    </div>
                    <div class="license-stat">
                        <div class="text-xl font-semibold text-white">{{ $orderStats['paid'] }}</div>
                        <div class="mt-1 text-xs text-gray-400">Paid orders</div>
                    </div>
                    <div class="license-stat">
                        <div class="text-xl font-semibold text-white">{{ $orderStats['pending'] }}</div>
                        <div class="mt-1 text-xs text-gray-400">Pending orders</div>
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

        @if (session('license_reset_success'))
            <div class="mb-5 rounded-xl border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-200 fade-up"
                role="status">
                {{ session('license_reset_success') }}
            </div>
        @endif

        @if ($errors->has('license_reset'))
            <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200 fade-up"
                role="alert">
                {{ $errors->first('license_reset') }}
            </div>
        @endif

        <div class="discord-mini-panel mb-5 fade-up md:p-5">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-white">Need setup help or buyer support?</h2>
                    <p class="mt-1 text-sm text-gray-400">
                        Join Discord to claim buyer support, ask for setup help, and request eligible license resets.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span class="support-pill">Buyer support</span>
                        <span class="support-pill">Customer support</span>
                        <span class="support-pill">License reset</span>
                        <span class="support-pill">Setup guidance</span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="/downloads"
                        class="inline-flex items-center justify-center rounded-lg border border-[#27272A] px-3 py-2 text-xs font-semibold text-gray-300 transition hover:text-white">
                        <x-ui.icon name="download" class="h-4 w-4" />
                        <span>Download Tools</span>
                    </a>
                    <a href="{{ $discordUrl ?: '#' }}"
                        @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                        class="discord-cta px-3 py-2 text-xs {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                        <x-ui.icon name="discord" class="h-4 w-4" />
                        <span>Join Discord</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Keys</p>
                <h2 class="mt-1 text-2xl font-semibold text-white">Available licenses</h2>
            </div>
            <a href="/downloads" class="btn-footer-secondary w-fit">
                <x-ui.icon name="download" class="h-4 w-4" />
                <span>Download Tools</span>
            </a>
        </div>

        <div class="grid gap-4 md:gap-6">

            @forelse($licenseGroups as $orderLicenses)
                @php
                    $firstLicense = $orderLicenses->first();
                    $licenseOrderId = trim((string) $firstLicense->order_id);
                    $licenseAnchor = $licenseOrderId !== ''
                        ? 'license-' . $licenseOrderId
                        : 'license-' . $firstLicense->id;
                    $isSelectedOrder = $selectedOrderId !== ''
                        && $licenseOrderId !== ''
                        && hash_equals($selectedOrderId, $licenseOrderId);
                    $copyAllValue = $orderLicenses->map(static function ($license) use ($formatDuration) {
                        $productName = trim((string) ($license->product->name ?? 'Product'));

                        return ($productName !== '' ? $productName : 'Product')
                            . ' | ' . $formatDuration($license->duration)
                            . ' | ' . $license->license_key;
                    })->implode("\n");
                @endphp

                <div id="{{ $licenseAnchor }}" data-license-order="{{ $licenseOrderId }}"
                    class="license-card motion-card scroll-mt-28 p-4 md:p-6 {{ $isSelectedOrder ? 'license-card-selected' : '' }}">

                    <!-- TOP -->
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                        <div>
                            <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold sm:text-lg">
                                {{ $orderLicenses->count() }} {{ Str::plural('license', $orderLicenses->count()) }} in this order

                                @if ($loop->first)
                                    <span class="text-[10px] sm:text-xs bg-aksa-accent-20 text-aksa-accent px-2 py-1 rounded">
                                        NEW
                                    </span>
                                @endif

                                @if ($isSelectedOrder)
                                    <span class="text-[10px] sm:text-xs bg-aksa-accent-25 text-aksa-accent-bright px-2 py-1 rounded">
                                        SELECTED ORDER
                                    </span>
                                @endif
                            </h2>

                            @if ($licenseOrderId !== '')
                                <p class="mt-1 font-mono text-[10px] sm:text-xs text-gray-500">
                                    Order: {{ $licenseOrderId }}
                                </p>
                            @endif

                            <p class="text-[10px] sm:text-xs text-gray-500 mt-1">
                                Purchased: {{ $firstLicense->created_at->format('d M Y, H:i') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <span class="status-pill status-pill-paid">Active</span>

                            @if ($orderLicenses->count() > 1)
                                <button type="button" data-copy-value="{{ $copyAllValue }}"
                                    data-copy-title="Order licenses copied"
                                    data-copy-message="All keys and durations are ready to paste."
                                    class="order-action btn-press">
                                    <x-ui.icon name="copy" class="h-4 w-4" />
                                    <span data-button-label>Copy All</span>
                                </button>
                            @endif
                        </div>

                    </div>

                    <div class="grid gap-3">
                        @foreach ($orderLicenses as $license)
                            @php
                                $resetState = $licenseResetStates[$license->id] ?? [
                                    'supported' => false,
                                    'provider' => null,
                                    'provider_label' => null,
                                    'identifier' => null,
                                    'identifier_label' => 'license',
                                    'username' => null,
                                    'is_paid_purchase' => false,
                                    'configured' => false,
                                    'available_at' => null,
                                    'remaining_seconds' => 0,
                                    'cooldown_hours' => 24,
                                    'can_reset' => false,
                                ];
                                $resetProviderLabel = $resetState['provider_label'] ?? 'HWID';
                                $resetIdentifier = $resetState['identifier'] ?? $resetState['username'] ?? null;
                                $resetIdentifierLabel = $resetState['identifier_label'] ?? 'license';
                                $resetCooldownHours = max(1, (int) ($resetState['cooldown_hours'] ?? 24));
                                $resetMinutes = max(0, (int) ceil(($resetState['remaining_seconds'] ?? 0) / 60));
                                $resetHours = intdiv($resetMinutes, 60);
                                $resetMinuteRemainder = $resetMinutes % 60;
                                $resetWaitLabel = $resetHours > 0
                                    ? $resetHours . 'h' . ($resetMinuteRemainder > 0 ? ' ' . $resetMinuteRemainder . 'm' : '')
                                    : $resetMinuteRemainder . 'm';
                            @endphp
                            <div class="license-key-box flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="mb-2 flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-white">
                                            {{ $license->product->name ?? 'Product' }}
                                        </span>
                                        <span class="rounded-md border border-aksa-accent-30 bg-aksa-accent-10 px-2 py-0.5 text-[11px] font-semibold text-aksa-accent-soft">
                                            {{ $formatDuration($license->duration) }}
                                        </span>
                                    </div>
                                    <span id="key-{{ $license->id }}"
                                        class="break-all font-mono text-xs text-gray-300 sm:text-sm">
                                        {{ $license->license_key }}
                                    </span>

                                    @if ($resetState['supported'] && $resetIdentifier)
                                        <p class="mt-2 text-[11px] text-gray-500">
                                            HWID reset {{ $resetIdentifierLabel }}: {{ $resetIdentifier }} · once every {{ $resetCooldownHours }} hours
                                        </p>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 self-end shrink-0 sm:self-auto">
                                    @if ($resetState['supported'])
                                        @if ($resetState['can_reset'])
                                            <form method="POST" action="{{ route('licenses.reset-hwid', $license) }}"
                                                data-confirm="Reset {{ $resetProviderLabel }} HWID for {{ $resetIdentifier }}? This action is limited to once every {{ $resetCooldownHours }} hours."
                                                data-license-reset-form>
                                                @csrf
                                                <button type="submit"
                                                    class="order-action license-reset-action btn-press">
                                                    <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                                                    <span data-button-label>Reset HWID</span>
                                                </button>
                                            </form>
                                        @elseif (($resetState['remaining_seconds'] ?? 0) > 0)
                                            <button type="button" class="order-action license-reset-action" disabled
                                                title="Reset available at {{ $resetState['available_at']?->timezone(config('app.timezone'))->format('d M Y, H:i') }} WIB">
                                                <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                                                <span>Reset in {{ $resetWaitLabel }}</span>
                                            </button>
                                        @elseif (! $resetState['configured'])
                                            <button type="button" class="order-action license-reset-action" disabled
                                                title="HWID reset is temporarily unavailable">
                                                <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                                                <span>Reset unavailable</span>
                                            </button>
                                        @else
                                            <button type="button" class="order-action license-reset-action" disabled
                                                title="Contact support for this license">
                                                <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                                                <span>Contact Support</span>
                                            </button>
                                        @endif
                                    @endif

                                    <button type="button" data-copy-license="{{ $license->id }}"
                                        aria-label="Copy {{ $license->product->name ?? 'product' }} license key"
                                        class="order-action btn-press">
                                        <x-ui.icon name="copy" class="h-4 w-4" />
                                        <span data-button-label>Copy</span>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                </div>

            @empty
                <div class="empty-state fade-up">
                    <span class="empty-state-icon">
                        <x-ui.icon name="key-round" class="h-6 w-6" />
                    </span>
                    <span class="empty-state-title">No licenses yet</span>
                    <p class="empty-state-copy">Paid orders with delivered keys will appear here automatically.</p>
                    <a href="/orders" class="btn-footer mt-5">
                        <x-ui.icon name="receipt" class="h-4 w-4" />
                        <span>Open Orders</span>
                    </a>
                </div>
            @endforelse

        </div>

    </div>
@endsection
