@extends('layouts.app')

@section('content')
    @php
        $licenseCount = $licenses->count();
        $latestLicense = $licenses->first();
        $licenseResetStates = $licenseResetStates ?? [];
        $selectedOrderId = request()->query('order');
        $selectedOrderId = is_string($selectedOrderId) ? trim($selectedOrderId) : '';
        $formatDuration = static fn ($duration) => str_replace(
            ['1 Hari', '7 Hari', '30 Hari', 'Hari'],
            ['1 Day', '7 Days', '30 Days', 'Days'],
            (string) $duration,
        );
        $licenseGroups = $licenses->groupBy(static fn ($license) =>
            'product:' . ($license->product_id ?: 'unknown-' . $license->id)
        );
        $licenseProducts = $licenseGroups->map(static fn ($group) => [
            'id' => (string) ($group->first()->product_id ?: 'unknown'),
            'name' => (string) ($group->first()->product->name ?? 'Product'),
        ])->sortBy('name')->values();
        $renderedOrderAnchors = [];
        $licenseSummaryStats = [
            ['value' => $licenseCount, 'label' => 'Licenses'],
            ['value' => $latestLicense?->created_at?->format('d M') ?? '-', 'label' => 'Latest'],
        ];
        $licenseResetSuccess = session('license_reset_success');
        $licenseResetSuccessMessage = is_array($licenseResetSuccess)
            ? (string) ($licenseResetSuccess['message'] ?? '')
            : (string) $licenseResetSuccess;
        $recentlyResetLicenseId = is_array($licenseResetSuccess)
            ? (int) ($licenseResetSuccess['license_id'] ?? 0)
            : 0;
    @endphp

    <div class="page-shell public-account-page py-7 md:py-12">

        <section class="license-hero account-hero mb-5 fade-up">
            <div class="account-hero-layout">
                <div class="account-hero-copy">
                    <h1 class="account-title">My Licenses</h1>
                    <p class="account-copy">Manage and copy your purchased license keys.</p>
                </div>

                <div class="license-summary-strip" aria-label="License summary">
                    @foreach ($licenseSummaryStats as $summaryStat)
                        <div class="license-summary-stat">
                            <strong>{{ $summaryStat['value'] }}</strong>
                            <span>{{ $summaryStat['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        @if ($licenseResetSuccessMessage !== '')
            <div class="mb-5 rounded-xl border border-aksa-accent-30 bg-aksa-accent-10 px-4 py-3 text-sm text-aksa-accent-soft fade-up"
                role="status">
                {{ $licenseResetSuccessMessage }}
            </div>
        @endif

        @if ($errors->has('license_reset'))
            <div class="mb-5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200 fade-up"
                role="alert">
                {{ $errors->first('license_reset') }}
            </div>
        @endif

        @if ($licenses->isNotEmpty())
            <div class="license-toolbar fade-up">
                <input id="licenseSearch" type="search" class="search-bar" placeholder="Search product or order..."
                    autocomplete="off">
                <select id="licenseProductFilter" class="search-bar" aria-label="Filter licenses by product">
                    <option value="">All products</option>
                    @foreach ($licenseProducts as $licenseProduct)
                        <option value="{{ $licenseProduct['id'] }}">{{ $licenseProduct['name'] }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <div id="licenseGroups" class="grid gap-4 md:gap-6">

            @forelse($licenseGroups as $orderLicenses)
                @php
                    $firstLicense = $orderLicenses->first();
                    $productId = (string) ($firstLicense->product_id ?: 'unknown');
                    $productName = (string) ($firstLicense->product->name ?? 'Product');
                    $licenseAnchor = 'license-product-' . $productId;
                    $isSelectedOrder = $selectedOrderId !== '' && $orderLicenses->contains(
                        fn ($license) => hash_equals($selectedOrderId, trim((string) $license->order_id))
                    );
                    $copyAllValue = $orderLicenses->map(static function ($license) use ($formatDuration) {
                        $productName = trim((string) ($license->product->name ?? 'Product'));

                        return ($productName !== '' ? $productName : 'Product')
                            . ' | ' . $formatDuration($license->duration)
                            . ' | ' . $license->license_key;
                    })->implode("\n");
                @endphp

                <div id="{{ $licenseAnchor }}" data-license-group data-license-product="{{ $productId }}"
                    data-license-search="{{ Str::lower($productName.' '.$orderLicenses->pluck('order_id')->implode(' ')) }}"
                    class="license-card motion-card scroll-mt-28 p-4 md:p-6 {{ $isSelectedOrder ? 'license-card-selected' : '' }}">

                    <!-- TOP -->
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                        <div>
                            <h2 class="flex flex-wrap items-center gap-2 text-base font-semibold sm:text-lg">
                                {{ $productName }}

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

                            <p class="text-[10px] sm:text-xs text-gray-500 mt-1">
                                {{ $orderLicenses->count() }} {{ Str::plural('license', $orderLicenses->count()) }} · latest purchase {{ $firstLicense->created_at->format('d M Y, H:i') }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @if ($orderLicenses->count() > 1)
                                <button type="button" data-copy-value="{{ $copyAllValue }}"
                                    data-copy-all-licenses
                                    data-copy-success-label="{{ $orderLicenses->count() }} Keys Copied"
                                    data-copy-title="Product licenses copied"
                                    data-copy-message="All licenses and durations for this product are ready to paste."
                                    class="order-action btn-press">
                                    <x-ui.icon name="copy" class="h-4 w-4" />
                                    <span data-button-label>Copy {{ $orderLicenses->count() }} Keys</span>
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
                                $rawLicenseKey = (string) $license->license_key;
                                $maskedLicenseKey = strlen($rawLicenseKey) > 8
                                    ? substr($rawLicenseKey, 0, 4) . str_repeat('•', strlen($rawLicenseKey) - 8) . substr($rawLicenseKey, -4)
                                    : str_repeat('•', max(4, strlen($rawLicenseKey)));
                            @endphp
                            <div class="license-key-box flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between {{ $orderLicenses->count() > 3 && ! $isSelectedOrder && $loop->iteration > 3 ? 'hidden' : '' }}"
                                @if ($orderLicenses->count() > 3 && ! $isSelectedOrder && $loop->iteration > 3) data-license-extra @endif>
                                <div class="min-w-0">
                                    @if (filled($license->order_id) && ! isset($renderedOrderAnchors[$license->order_id]))
                                        @php($renderedOrderAnchors[$license->order_id] = true)
                                        <span id="license-{{ $license->order_id }}" class="block h-0 scroll-mt-28" aria-hidden="true"></span>
                                    @endif
                                    <div class="mb-2 flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold text-white">
                                            {{ $license->product->name ?? 'Product' }}
                                        </span>
                                        <span class="rounded-md border border-aksa-accent-30 bg-aksa-accent-10 px-2 py-0.5 text-[11px] font-semibold text-aksa-accent-soft">
                                            {{ $formatDuration($license->duration) }}
                                        </span>
                                    </div>
                                    <span id="key-{{ $license->id }}"
                                        data-license-key-value="{{ $license->license_key }}" data-license-masked="true"
                                        class="break-all font-mono text-xs text-gray-300 sm:text-sm">
                                        {{ $maskedLicenseKey }}
                                    </span>
                                    <p class="mt-1 font-mono text-[10px] text-gray-500">Order: {{ $license->order_id }}</p>

                                    @if ($resetState['supported'] && $resetIdentifier)
                                        <p class="mt-2 text-[11px] text-gray-500">
                                            HWID reset {{ $resetIdentifierLabel }}: {{ $resetIdentifier }} · once every {{ $resetCooldownHours }} hours
                                        </p>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 self-end shrink-0 sm:self-auto">
                                    <button type="button" data-reveal-license="{{ $license->id }}" class="order-action btn-press"
                                        aria-controls="key-{{ $license->id }}" aria-pressed="false">
                                        <x-ui.icon name="eye" class="h-4 w-4" />
                                        <span data-button-label>Reveal</span>
                                    </button>
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
                                            <button type="button"
                                                class="order-action license-reset-action {{ $recentlyResetLicenseId === (int) $license->id ? 'is-reset-success' : '' }}"
                                                @if ($recentlyResetLicenseId === (int) $license->id)
                                                    data-license-reset-success
                                                    data-reset-final-label="Reset in {{ $resetWaitLabel }}"
                                                @endif
                                                disabled
                                                title="Reset available at {{ $resetState['available_at']?->timezone(config('app.timezone'))->format('d M Y, H:i') }} WIB">
                                                <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                                                <span data-button-label>
                                                    {{ $recentlyResetLicenseId === (int) $license->id ? 'Reset successful' : 'Reset in '.$resetWaitLabel }}
                                                </span>
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

                    @if ($orderLicenses->count() > 3 && ! $isSelectedOrder)
                        <button type="button" class="license-show-all btn-press" data-license-show-all
                            data-collapsed-label="Show {{ $orderLicenses->count() - 3 }} more"
                            aria-expanded="false">
                            <span data-button-label>Show {{ $orderLicenses->count() - 3 }} more</span>
                            <x-ui.icon name="chevron-down" class="h-4 w-4 transition-transform" data-show-all-chevron />
                        </button>
                    @endif

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
