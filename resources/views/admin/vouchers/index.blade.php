@extends('layouts.app')

@section('content')
    @php
        $isEditing = (bool) $editVoucher;
        $formAction = $isEditing
            ? route('admin.vouchers.update', $editVoucher)
            : route('admin.vouchers.store');
        $formatIdr = fn ($value) => 'Rp ' . number_format((int) $value, 0, ',', '.');
        $formatCrypto = fn ($value, $token) => rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') . ' ' . $token;
        $formatDateInput = fn ($value) => $value?->format('Y-m-d\TH:i');
        $selectedActiveStatus = (string) old('is_active', $editVoucher ? (int) $editVoucher->is_active : 1);
        $selectedActiveLabel = $selectedActiveStatus === '0' ? 'Inactive' : 'Active';
        $packageOptionLabel = fn ($package) => trim(($package->product?->name ?? 'Product') . ' - ' . $package->name);
        $selectedRequiredPackageIds = collect(old('required_package_ids', $editVoucher?->requiredPackageIds() ?? []))
            ->map(fn ($id) => (int) $id)
            ->all();
        $selectedRequiredPackageNames = collect($selectedRequiredPackageIds)
            ->map(fn ($id) => $packagesById->get($id))
            ->filter()
            ->map(fn ($package) => $packageOptionLabel($package))
            ->values();
        $bundlePickerLabel = $selectedRequiredPackageNames->isEmpty()
            ? 'General voucher'
            : ($selectedRequiredPackageNames->count() <= 2
                ? $selectedRequiredPackageNames->join(' + ')
                : $selectedRequiredPackageNames->count() . ' packages selected');
        $bundleLabel = function ($voucher) use ($packagesById, $packageOptionLabel) {
            $names = collect($voucher->requiredPackageIds())
                ->map(fn ($id) => $packagesById->get($id))
                ->filter()
                ->map(fn ($package) => $packageOptionLabel($package))
                ->values();

            return $names->isEmpty() ? 'General voucher' : 'Bundle: ' . $names->join(' + ');
        };
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Vouchers</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Configure separate QRIS, USDT, and USDC discount caps, usage limits, bundle rules, and promo schedules.
                        Each discount cap applies once per purchased license quantity.
                    </p>
                </div>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total vouchers</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['available'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Available now</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['paid_uses'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Paid uses</div>
                </div>
            </div>
        </section>

        @if (session('info'))
            <div class="mb-4 rounded-xl border border-[#9333EA]/30 bg-[#9333EA]/10 px-4 py-3 text-sm text-[#D8B4FE]">
                {{ session('info') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="product-section relative z-40 mb-6 overflow-visible fade-up">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">
                        {{ $isEditing ? 'Edit Voucher' : 'New Voucher' }}
                    </p>
                    <h2 class="mt-1 text-xl font-semibold text-white">
                        {{ $isEditing ? $editVoucher->code : 'Create Voucher' }}
                    </h2>
                </div>
                @if ($isEditing)
                    <a href="{{ route('admin.vouchers.index') }}" class="btn-footer-secondary">Cancel Edit</a>
                @endif
            </div>

            <form action="{{ $formAction }}" method="POST" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @csrf
                @if ($isEditing)
                    @method('PATCH')
                @endif

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Code</span>
                    <input name="code" value="{{ old('code', $editVoucher->code ?? '') }}" class="search-bar w-full uppercase"
                        placeholder="Type voucher code" maxlength="50" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Discount percent</span>
                    <input name="discount_percent" value="{{ old('discount_percent', $editVoucher->discount_percent ?? 10) }}"
                        type="number" min="1" max="99" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Maximum discount per license (IDR)</span>
                    <input name="max_discount" value="{{ old('max_discount', $editVoucher->max_discount ?? 10000) }}"
                        type="number" min="1" step="1" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Maximum discount per license (USDT)</span>
                    <input name="max_discount_usdt" value="{{ old('max_discount_usdt', $editVoucher->max_discount_usdt ?? 0.5) }}"
                        type="number" min="0.000001" max="999999.999999" step="0.000001" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Maximum discount per license (USDC)</span>
                    <input name="max_discount_usdc" value="{{ old('max_discount_usdc', $editVoucher->max_discount_usdc ?? 0.5) }}"
                        type="number" min="0.000001" max="999999.999999" step="0.000001" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Minimum purchase (IDR)</span>
                    <input name="minimum_purchase" value="{{ old('minimum_purchase', $editVoucher->minimum_purchase ?? 20000) }}"
                        type="number" min="0" step="1" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Total usage limit</span>
                    <input name="usage_limit" value="{{ old('usage_limit', $editVoucher->usage_limit ?? '') }}"
                        type="number" min="1" step="1" class="search-bar w-full" placeholder="Unlimited">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Limit per account</span>
                    <input name="per_user_limit"
                        value="{{ old('per_user_limit', ($editVoucher?->per_user_limit ?? 0) > 0 ? $editVoucher->per_user_limit : '') }}"
                        type="number" min="1" step="1" class="search-bar w-full" placeholder="Unlimited">
                </label>

                <div class="block md:col-span-2 xl:col-span-2">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Required bundle packages</span>
                    <div class="relative" data-bundle-picker>
                        <button type="button"
                            class="search-bar flex min-h-12 w-full items-center justify-between gap-3 text-left"
                            data-bundle-toggle aria-expanded="false" aria-controls="voucherBundlePackagePanel">
                            <span class="min-w-0 truncate" data-bundle-label>{{ $bundlePickerLabel }}</span>
                            <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" data-bundle-arrow
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <div id="voucherBundlePackagePanel"
                            class="absolute left-0 right-0 z-50 mt-2 hidden overflow-hidden rounded-xl border border-[#9333EA]/30 bg-[#111115] shadow-2xl"
                            data-bundle-panel>
                            <div class="max-h-64 overflow-y-auto p-2">
                                @forelse ($availablePackages as $package)
                                    @php($optionLabel = $packageOptionLabel($package))
                                    <label class="flex cursor-pointer items-start gap-3 rounded-lg px-3 py-2 text-sm text-gray-300 transition hover:bg-[#9333EA]/10">
                                        <input type="checkbox" name="required_package_ids[]" value="{{ $package->id }}"
                                            class="mt-1 h-4 w-4 rounded border-gray-600 bg-[#09090c]"
                                            style="accent-color: #9333EA"
                                            data-bundle-checkbox data-label="{{ $optionLabel }}"
                                            @checked(in_array((int) $package->id, $selectedRequiredPackageIds, true))>
                                        <span class="min-w-0">
                                            <span class="block truncate text-white">{{ $optionLabel }}</span>
                                            <span class="block text-xs text-gray-500">
                                                {{ $formatIdr($package->price) }} / {{ $formatCrypto($package->price_usdt, 'USDT') }}
                                            </span>
                                        </span>
                                    </label>
                                @empty
                                    <div class="px-3 py-2 text-sm text-gray-500">No packages available.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                    <span class="mt-2 block text-xs text-gray-500">Leave empty for general vouchers.</span>
                </div>

                <div class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Starts at (WIB)</span>
                    <div class="relative" data-datetime-picker>
                        <input type="hidden" name="starts_at" value="{{ old('starts_at', $formatDateInput($editVoucher?->starts_at)) }}"
                            data-datetime-value>
                        <button type="button" class="search-bar flex min-h-12 w-full items-center justify-between gap-3 text-left"
                            data-datetime-toggle aria-expanded="false" aria-controls="voucherStartsAtPanel">
                            <span class="min-w-0 truncate" data-datetime-label>Select date and time</span>
                            <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M7 3v3m10-3v3M4.5 9.5h15M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" />
                            </svg>
                        </button>

                        <div id="voucherStartsAtPanel"
                            class="absolute left-0 right-0 z-50 mt-2 hidden min-w-[320px] overflow-hidden rounded-xl border border-[#9333EA]/30 bg-[#111115] p-3 shadow-2xl"
                            data-datetime-panel>
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <button type="button" class="order-action h-9 w-9 p-0" data-datetime-prev aria-label="Previous month">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                                    </svg>
                                </button>
                                <div class="text-sm font-semibold text-white" data-datetime-month></div>
                                <button type="button" class="order-action h-9 w-9 p-0" data-datetime-next aria-label="Next month">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                                    </svg>
                                </button>
                            </div>

                            <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[11px] font-semibold text-gray-500">
                                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                            </div>
                            <div class="grid grid-cols-7 gap-1" data-datetime-days></div>

                            <div class="mt-3 flex items-end gap-2 border-t border-[#27272A] pt-3">
                                <label class="min-w-0 flex-1">
                                    <span class="mb-1 block text-[11px] font-semibold text-gray-500">Hour</span>
                                    <input type="number" min="0" max="23" step="1"
                                        class="search-bar h-10 rounded-xl px-3 py-2 text-sm" data-datetime-hour>
                                </label>
                                <label class="min-w-0 flex-1">
                                    <span class="mb-1 block text-[11px] font-semibold text-gray-500">Minute</span>
                                    <input type="number" min="0" max="59" step="1"
                                        class="search-bar h-10 rounded-xl px-3 py-2 text-sm" data-datetime-minute>
                                </label>
                                <button type="button" class="order-action h-10" data-datetime-now>Now</button>
                                <button type="button" class="order-action order-action-danger h-10" data-datetime-clear>Clear</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Expires at (WIB)</span>
                    <div class="relative" data-datetime-picker>
                        <input type="hidden" name="expires_at" value="{{ old('expires_at', $formatDateInput($editVoucher?->expires_at)) }}"
                            data-datetime-value>
                        <button type="button" class="search-bar flex min-h-12 w-full items-center justify-between gap-3 text-left"
                            data-datetime-toggle aria-expanded="false" aria-controls="voucherExpiresAtPanel">
                            <span class="min-w-0 truncate" data-datetime-label>Select date and time</span>
                            <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M7 3v3m10-3v3M4.5 9.5h15M6 5h12a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" />
                            </svg>
                        </button>

                        <div id="voucherExpiresAtPanel"
                            class="absolute left-0 right-0 z-50 mt-2 hidden min-w-[320px] overflow-hidden rounded-xl border border-[#9333EA]/30 bg-[#111115] p-3 shadow-2xl"
                            data-datetime-panel>
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <button type="button" class="order-action h-9 w-9 p-0" data-datetime-prev aria-label="Previous month">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                                    </svg>
                                </button>
                                <div class="text-sm font-semibold text-white" data-datetime-month></div>
                                <button type="button" class="order-action h-9 w-9 p-0" data-datetime-next aria-label="Next month">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1.8" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                                    </svg>
                                </button>
                            </div>

                            <div class="mb-2 grid grid-cols-7 gap-1 text-center text-[11px] font-semibold text-gray-500">
                                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                            </div>
                            <div class="grid grid-cols-7 gap-1" data-datetime-days></div>

                            <div class="mt-3 flex items-end gap-2 border-t border-[#27272A] pt-3">
                                <label class="min-w-0 flex-1">
                                    <span class="mb-1 block text-[11px] font-semibold text-gray-500">Hour</span>
                                    <input type="number" min="0" max="23" step="1"
                                        class="search-bar h-10 rounded-xl px-3 py-2 text-sm" data-datetime-hour>
                                </label>
                                <label class="min-w-0 flex-1">
                                    <span class="mb-1 block text-[11px] font-semibold text-gray-500">Minute</span>
                                    <input type="number" min="0" max="59" step="1"
                                        class="search-bar h-10 rounded-xl px-3 py-2 text-sm" data-datetime-minute>
                                </label>
                                <button type="button" class="order-action h-10" data-datetime-now>Now</button>
                                <button type="button" class="order-action order-action-danger h-10" data-datetime-clear>Clear</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <div class="relative" data-status-picker>
                        <input type="hidden" name="is_active" value="{{ $selectedActiveStatus === '0' ? '0' : '1' }}"
                            data-status-value>
                        <button type="button"
                            class="search-bar flex min-h-12 w-full items-center justify-between gap-3 text-left"
                            data-status-toggle aria-expanded="false" aria-controls="voucherStatusPanel">
                            <span data-status-label>{{ $selectedActiveLabel }}</span>
                            <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform" data-status-arrow
                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </button>

                        <div id="voucherStatusPanel"
                            class="absolute left-0 right-0 z-50 mt-2 hidden overflow-hidden rounded-xl border border-[#9333EA]/30 bg-[#111115] p-2 shadow-2xl"
                            data-status-panel>
                            <button type="button"
                                class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm font-semibold text-gray-300 transition hover:bg-[#9333EA]/10 hover:text-white"
                                data-status-option data-value="1" data-label="Active">
                                <span>Active</span>
                                <span class="hidden h-2 w-2 rounded-full bg-[#C084FC]" data-status-check></span>
                            </button>
                            <button type="button"
                                class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm font-semibold text-gray-300 transition hover:bg-[#9333EA]/10 hover:text-white"
                                data-status-option data-value="0" data-label="Inactive">
                                <span>Inactive</span>
                                <span class="hidden h-2 w-2 rounded-full bg-[#C084FC]" data-status-check></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex items-end">
                    <button class="btn-footer h-12">{{ $isEditing ? 'Save Voucher' : 'Create Voucher' }}</button>
                </div>
            </form>
        </section>

        <section class="product-section relative z-10 mb-6 fade-up">
            <form method="GET" action="{{ route('admin.vouchers.index') }}" class="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search code</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Enter voucher code">
                </label>
                <div class="flex gap-2">
                    <button class="btn-footer h-12">Filter</button>
                    <a href="{{ route('admin.vouchers.index') }}" class="btn-footer-secondary h-12">Reset</a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden md:block">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Voucher</th>
                            <th class="p-4 text-left">Offer</th>
                            <th class="p-4 text-left">Limits</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-left">Schedule</th>
                            <th class="p-4 text-left">Usage</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vouchers as $voucher)
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $voucher->code }}</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $bundleLabel($voucher) }}</div>
                                </td>
                                <td class="p-4 text-gray-300">
                                    <div>{{ $voucher->discount_percent }}% up to {{ $formatIdr($voucher->max_discount) }} QRIS</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $formatCrypto($voucher->max_discount_usdt, 'USDT') }} /
                                        {{ $formatCrypto($voucher->max_discount_usdc, 'USDC') }}
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">Minimum {{ $formatIdr($voucher->minimum_purchase) }}</div>
                                </td>
                                <td class="p-4 text-gray-300">
                                    <div>{{ $voucher->usage_limit ?? 'Unlimited' }} total</div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        {{ $voucher->per_user_limit > 0 ? $voucher->per_user_limit : 'Unlimited' }} per account
                                    </div>
                                </td>
                                <td class="p-4">
                                    <span class="status-pill {{ $voucher->availabilityBadgeClass() }}">
                                        {{ $voucher->availabilityLabel() }}
                                    </span>
                                </td>
                                <td class="p-4 text-xs text-gray-400">
                                    <div>{{ $voucher->starts_at?->format('d M Y, H:i') ?? 'Immediately' }}</div>
                                    <div class="mt-1">{{ $voucher->expires_at?->format('d M Y, H:i') ?? 'No expiry' }}</div>
                                </td>
                                <td class="p-4 text-gray-300">
                                    <div>{{ $voucher->active_uses_count }} active</div>
                                    <div class="mt-1 text-xs text-gray-500">{{ $voucher->paid_uses_count }} paid</div>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="inline-flex gap-2">
                                        <a href="{{ route('admin.vouchers.show', $voucher) }}" class="order-action">Uses</a>
                                        <a href="{{ route('admin.vouchers.index', ['edit' => $voucher->id]) }}" class="order-action">Edit</a>
                                        <form action="{{ route('admin.vouchers.destroy', $voucher) }}" method="POST"
                                            data-confirm="Delete this unused voucher?">
                                            @csrf
                                            @method('DELETE')
                                            <button class="order-action order-action-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-8"><div class="empty-state">No vouchers found</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 md:hidden">
            @forelse ($vouchers as $voucher)
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold text-white">{{ $voucher->code }}</div>
                            <div class="mt-1 text-xs text-[#C084FC]">{{ $voucher->discount_percent }}% up to {{ $formatIdr($voucher->max_discount) }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ $bundleLabel($voucher) }}</div>
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $formatCrypto($voucher->max_discount_usdt, 'USDT') }} /
                                {{ $formatCrypto($voucher->max_discount_usdc, 'USDC') }}
                            </div>
                        </div>
                        <span class="status-pill {{ $voucher->availabilityBadgeClass() }}">
                            {{ $voucher->availabilityLabel() }}
                        </span>
                    </div>
                    <div class="mt-4 text-xs text-gray-400">
                        Minimum {{ $formatIdr($voucher->minimum_purchase) }} · {{ $voucher->active_uses_count }} active uses
                    </div>
                    <div class="mt-4 flex gap-2">
                        <a href="{{ route('admin.vouchers.show', $voucher) }}" class="order-action">Uses</a>
                        <a href="{{ route('admin.vouchers.index', ['edit' => $voucher->id]) }}" class="order-action">Edit</a>
                        <form action="{{ route('admin.vouchers.destroy', $voucher) }}" method="POST" data-confirm="Delete this unused voucher?">
                            @csrf
                            @method('DELETE')
                            <button class="order-action order-action-danger">Delete</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty-state">No vouchers found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $vouchers,
            'label' => 'Voucher pagination',
            'itemLabel' => 'vouchers',
        ])
    </div>
@endsection

@push('scripts')
    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        document.querySelectorAll('[data-datetime-picker]').forEach((picker) => {
            const valueInput = picker.querySelector('[data-datetime-value]');
            const toggle = picker.querySelector('[data-datetime-toggle]');
            const label = picker.querySelector('[data-datetime-label]');
            const panel = picker.querySelector('[data-datetime-panel]');
            const monthLabel = picker.querySelector('[data-datetime-month]');
            const daysGrid = picker.querySelector('[data-datetime-days]');
            const hourInput = picker.querySelector('[data-datetime-hour]');
            const minuteInput = picker.querySelector('[data-datetime-minute]');
            const previousButton = picker.querySelector('[data-datetime-prev]');
            const nextButton = picker.querySelector('[data-datetime-next]');
            const nowButton = picker.querySelector('[data-datetime-now]');
            const clearButton = picker.querySelector('[data-datetime-clear]');
            const pad = (value) => String(value).padStart(2, '0');
            let selectedDate = null;
            let viewDate = new Date();

            const parseValue = (value) => {
                if (!value) return null;

                const [date, time = '00:00'] = value.split('T');
                const [year, month, day] = date.split('-').map(Number);
                const [hour, minute] = time.split(':').map(Number);

                if (!year || !month || !day || Number.isNaN(hour) || Number.isNaN(minute)) {
                    return null;
                }

                return new Date(year, month - 1, day, hour, minute);
            };

            const formatValue = (date) => (
                `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
            );

            const displayValue = (date) => date.toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });

            const clampTimeInput = (input, min, max) => {
                const numeric = Number(input.value);
                const value = Number.isFinite(numeric) ? Math.min(max, Math.max(min, numeric)) : min;

                input.value = pad(value);

                return value;
            };

            const syncInputs = () => {
                const source = selectedDate || new Date(new Date().setHours(0, 0, 0, 0));

                hourInput.value = pad(source.getHours());
                minuteInput.value = pad(source.getMinutes());
            };

            const updateValue = () => {
                if (!selectedDate) {
                    valueInput.value = '';
                    label.textContent = 'Select date and time';
                    return;
                }

                selectedDate.setHours(clampTimeInput(hourInput, 0, 23), clampTimeInput(minuteInput, 0, 59), 0, 0);
                valueInput.value = formatValue(selectedDate);
                label.textContent = displayValue(selectedDate);
            };

            const renderDays = () => {
                const year = viewDate.getFullYear();
                const month = viewDate.getMonth();
                const today = new Date();
                const firstWeekday = new Date(year, month, 1).getDay();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                const previousMonthDays = new Date(year, month, 0).getDate();

                monthLabel.textContent = new Date(year, month, 1).toLocaleString('en-GB', {
                    month: 'long',
                    year: 'numeric',
                });
                daysGrid.innerHTML = '';

                for (let index = 0; index < 42; index += 1) {
                    const dayOffset = index - firstWeekday + 1;
                    const isPreviousMonth = dayOffset <= 0;
                    const isNextMonth = dayOffset > daysInMonth;
                    const day = isPreviousMonth
                        ? previousMonthDays + dayOffset
                        : (isNextMonth ? dayOffset - daysInMonth : dayOffset);
                    const cellDate = new Date(
                        year,
                        month + (isPreviousMonth ? -1 : (isNextMonth ? 1 : 0)),
                        day,
                        Number(hourInput.value || 0),
                        Number(minuteInput.value || 0)
                    );
                    const isSelected = selectedDate &&
                        cellDate.getFullYear() === selectedDate.getFullYear() &&
                        cellDate.getMonth() === selectedDate.getMonth() &&
                        cellDate.getDate() === selectedDate.getDate();
                    const isToday =
                        cellDate.getFullYear() === today.getFullYear() &&
                        cellDate.getMonth() === today.getMonth() &&
                        cellDate.getDate() === today.getDate();
                    const button = document.createElement('button');

                    button.type = 'button';
                    button.textContent = day;
                    button.className = [
                        'h-9 rounded-lg text-sm font-semibold transition',
                        isSelected
                            ? 'bg-[#9333EA] text-white shadow-lg shadow-[#9333EA]/25'
                            : 'bg-white/[0.03] text-gray-200 hover:bg-[#9333EA]/20 hover:text-white',
                        isPreviousMonth || isNextMonth ? 'opacity-45' : '',
                        isToday && !isSelected ? 'ring-1 ring-[#C084FC]/70' : '',
                    ].filter(Boolean).join(' ');
                    button.addEventListener('click', () => {
                        selectedDate = cellDate;
                        viewDate = new Date(cellDate.getFullYear(), cellDate.getMonth(), 1);
                        updateValue();
                        renderDays();
                    });
                    daysGrid.appendChild(button);
                }
            };

            const close = () => {
                panel.classList.add('hidden');
                toggle.setAttribute('aria-expanded', 'false');
            };

            selectedDate = parseValue(valueInput.value);
            viewDate = selectedDate
                ? new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1)
                : new Date(new Date().getFullYear(), new Date().getMonth(), 1);
            syncInputs();
            updateValue();
            renderDays();

            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                const isOpen = !panel.classList.contains('hidden');

                document.querySelectorAll('[data-datetime-panel]').forEach((otherPanel) => {
                    if (otherPanel !== panel) {
                        otherPanel.classList.add('hidden');
                    }
                });
                panel.classList.toggle('hidden', isOpen);
                toggle.setAttribute('aria-expanded', String(!isOpen));
            });

            previousButton.addEventListener('click', () => {
                viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, 1);
                renderDays();
            });
            nextButton.addEventListener('click', () => {
                viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 1);
                renderDays();
            });
            [hourInput, minuteInput].forEach((input) => {
                input.addEventListener('change', () => {
                    updateValue();
                    renderDays();
                });
                input.addEventListener('blur', updateValue);
            });
            nowButton.addEventListener('click', () => {
                selectedDate = new Date();
                viewDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);
                syncInputs();
                updateValue();
                renderDays();
            });
            clearButton.addEventListener('click', () => {
                selectedDate = null;
                valueInput.value = '';
                label.textContent = 'Select date and time';
                syncInputs();
                renderDays();
            });
            document.addEventListener('click', (event) => {
                if (!picker.contains(event.target)) {
                    close();
                }
            });
            picker.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    close();
                    toggle.focus();
                }
            });
        });

        document.querySelectorAll('[data-bundle-picker]').forEach((picker) => {
            const toggle = picker.querySelector('[data-bundle-toggle]');
            const panel = picker.querySelector('[data-bundle-panel]');
            const label = picker.querySelector('[data-bundle-label]');
            const arrow = picker.querySelector('[data-bundle-arrow]');
            const checkboxes = Array.from(picker.querySelectorAll('[data-bundle-checkbox]'));

            const selectedText = () => {
                const selected = checkboxes
                    .filter((checkbox) => checkbox.checked)
                    .map((checkbox) => checkbox.dataset.label);

                if (selected.length === 0) {
                    return 'General voucher';
                }

                if (selected.length <= 2) {
                    return selected.join(' + ');
                }

                return `${selected.length} packages selected`;
            };

            const refresh = () => {
                label.textContent = selectedText();
            };

            const close = () => {
                panel.classList.add('hidden');
                toggle.setAttribute('aria-expanded', 'false');
                arrow.classList.remove('rotate-180');
            };

            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                const isOpen = !panel.classList.contains('hidden');

                panel.classList.toggle('hidden', isOpen);
                toggle.setAttribute('aria-expanded', String(!isOpen));
                arrow.classList.toggle('rotate-180', !isOpen);
            });

            checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refresh));

            document.addEventListener('click', (event) => {
                if (!picker.contains(event.target)) {
                    close();
                }
            });

            picker.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    close();
                    toggle.focus();
                }
            });

            refresh();
        });

        document.querySelectorAll('[data-status-picker]').forEach((picker) => {
            const valueInput = picker.querySelector('[data-status-value]');
            const toggle = picker.querySelector('[data-status-toggle]');
            const label = picker.querySelector('[data-status-label]');
            const panel = picker.querySelector('[data-status-panel]');
            const arrow = picker.querySelector('[data-status-arrow]');
            const options = Array.from(picker.querySelectorAll('[data-status-option]'));

            const refresh = () => {
                options.forEach((option) => {
                    const isSelected = option.dataset.value === valueInput.value;

                    option.classList.toggle('bg-[#9333EA]/15', isSelected);
                    option.classList.toggle('text-white', isSelected);
                    option.querySelector('[data-status-check]')?.classList.toggle('hidden', !isSelected);

                    if (isSelected) {
                        label.textContent = option.dataset.label;
                    }
                });
            };

            const close = () => {
                panel.classList.add('hidden');
                toggle.setAttribute('aria-expanded', 'false');
                arrow.classList.remove('rotate-180');
            };

            toggle.addEventListener('click', (event) => {
                event.stopPropagation();
                const isOpen = !panel.classList.contains('hidden');

                panel.classList.toggle('hidden', isOpen);
                toggle.setAttribute('aria-expanded', String(!isOpen));
                arrow.classList.toggle('rotate-180', !isOpen);
            });

            options.forEach((option) => {
                option.addEventListener('click', () => {
                    valueInput.value = option.dataset.value;
                    refresh();
                    close();
                });
            });

            document.addEventListener('click', (event) => {
                if (!picker.contains(event.target)) {
                    close();
                }
            });

            picker.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    close();
                    toggle.focus();
                }
            });

            refresh();
        });
    </script>
@endpush
