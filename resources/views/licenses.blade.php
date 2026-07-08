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

    <div class="page-shell public-account-page py-7 md:py-12">

        <section class="license-hero account-hero mb-8 fade-up">
            <div class="account-hero-layout">
                <div class="account-hero-copy">
                    <p class="account-eyebrow">License Vault</p>
                    <h1 class="account-title">My Licenses</h1>
                    <p class="account-copy">
                        Your paid license keys are stored here. Copy the key you need and download the matching tools
                        when you are ready to set up.
                    </p>
                </div>

                <div class="account-actions">
                    <a href="/downloads" class="btn-footer-secondary account-action-button">
                        <x-ui.icon name="download" class="h-4 w-4" />
                        <span>Download Tools</span>
                    </a>
                    <a href="{{ $discordUrl ?: '#' }}"
                        @if ($discordUrl) target="_blank" rel="noopener noreferrer" @endif
                        class="discord-cta account-action-button {{ $discordUrl ? '' : 'cursor-not-allowed opacity-50' }}">
                        <x-ui.icon name="discord" class="h-4 w-4" />
                        <span>Join Discord</span>
                    </a>
                </div>
            </div>

            <div class="account-stat-grid account-stat-grid-4">
                <div class="license-stat account-stat">
                    <div class="account-stat-value">{{ $licenseCount }}</div>
                    <div class="account-stat-label">Active licenses</div>
                </div>
                <div class="license-stat account-stat">
                    <div class="account-stat-value">{{ $orderStats['paid'] }}</div>
                    <div class="account-stat-label">Paid orders</div>
                </div>
                <div class="license-stat account-stat">
                    <div class="account-stat-value">{{ $orderStats['pending'] }}</div>
                    <div class="account-stat-label">Pending orders</div>
                </div>
                <div class="license-stat account-stat">
                    <div class="account-stat-value">
                        {{ $latestLicense?->created_at?->format('d M') ?? '-' }}
                    </div>
                    <div class="account-stat-label">Latest purchase</div>
                </div>
            </div>
        </section>

        @if (session('license_reset_success'))
            <div class="mb-5 rounded-xl border border-aksa-accent-30 bg-aksa-accent-10 px-4 py-3 text-sm text-aksa-accent-soft fade-up"
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

        <div class="mb-4 flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-normal text-aksa-accent">Keys</p>
                <h2 class="mt-1 text-2xl font-semibold text-white">Available licenses</h2>
            </div>
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
