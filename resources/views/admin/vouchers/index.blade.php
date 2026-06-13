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
    @endphp

    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <div>
                    <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                    <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Vouchers</h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                        Configure separate QRIS, USDT, and USDC discount caps, usage limits, and promo schedules.
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

        <section class="product-section mb-6 fade-up">
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
                        placeholder="AKSA10" maxlength="50" required>
                    <span class="mt-2 block text-xs text-gray-500">Use a code that is not easy to guess. Preview attempts are rate-limited.</span>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Discount percent</span>
                    <input name="discount_percent" value="{{ old('discount_percent', $editVoucher->discount_percent ?? 10) }}"
                        type="number" min="1" max="99" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Maximum discount (IDR)</span>
                    <input name="max_discount" value="{{ old('max_discount', $editVoucher->max_discount ?? 15000) }}"
                        type="number" min="1" step="1" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Maximum discount (USDT)</span>
                    <input name="max_discount_usdt" value="{{ old('max_discount_usdt', $editVoucher->max_discount_usdt ?? 0.25) }}"
                        type="number" min="0.000001" max="999999.999999" step="0.000001" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Maximum discount (USDC)</span>
                    <input name="max_discount_usdc" value="{{ old('max_discount_usdc', $editVoucher->max_discount_usdc ?? 0.25) }}"
                        type="number" min="0.000001" max="999999.999999" step="0.000001" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Minimum purchase (IDR)</span>
                    <input name="minimum_purchase" value="{{ old('minimum_purchase', $editVoucher->minimum_purchase ?? 45000) }}"
                        type="number" min="0" step="1" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Total usage limit</span>
                    <input name="usage_limit" value="{{ old('usage_limit', $editVoucher->usage_limit ?? '') }}"
                        type="number" min="1" step="1" class="search-bar w-full" placeholder="Unlimited">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Limit per account</span>
                    <input name="per_user_limit" value="{{ old('per_user_limit', $editVoucher->per_user_limit ?? 1) }}"
                        type="number" min="1" step="1" class="search-bar w-full" required>
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Starts at (WIB)</span>
                    <input name="starts_at" value="{{ old('starts_at', $formatDateInput($editVoucher?->starts_at)) }}"
                        type="datetime-local" class="search-bar w-full">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Expires at (WIB)</span>
                    <input name="expires_at" value="{{ old('expires_at', $formatDateInput($editVoucher?->expires_at)) }}"
                        type="datetime-local" class="search-bar w-full">
                    <span class="mt-2 block text-xs text-gray-500">The server rejects the voucher immediately after this time.</span>
                </label>

                <label class="flex items-center gap-3 rounded-lg border border-[#27272A] bg-black/15 px-4 py-3">
                    <input type="checkbox" name="is_active" value="1"
                        @checked(old('is_active', $editVoucher?->is_active ?? true))>
                    <span class="text-sm font-semibold text-gray-300">Voucher active</span>
                </label>

                <div class="flex items-end xl:col-span-3">
                    <button class="btn-footer h-12">{{ $isEditing ? 'Save Voucher' : 'Create Voucher' }}</button>
                </div>
            </form>
        </section>

        <section class="product-section mb-6 fade-up">
            <form method="GET" action="{{ route('admin.vouchers.index') }}" class="grid gap-3 md:grid-cols-[1fr_auto] md:items-end">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search code</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full" placeholder="AKSA10">
                </label>
                <div class="flex gap-2">
                    <button class="btn-footer h-12">Filter</button>
                    <a href="{{ route('admin.vouchers.index') }}" class="btn-footer-secondary h-12">Reset</a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden md:block">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1040px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Voucher</th>
                            <th class="p-4 text-left">Offer</th>
                            <th class="p-4 text-left">Limits</th>
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
                                    <div class="mt-1 text-xs {{ $voucher->is_active ? 'text-emerald-300' : 'text-gray-500' }}">
                                        {{ $voucher->is_active ? 'Active' : 'Inactive' }}
                                    </div>
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
                                    <div class="mt-1 text-xs text-gray-500">{{ $voucher->per_user_limit }} per account</div>
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
                            <tr><td colspan="6" class="p-8"><div class="empty-state">No vouchers found</div></td></tr>
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
                            <div class="mt-1 text-xs text-gray-500">
                                {{ $formatCrypto($voucher->max_discount_usdt, 'USDT') }} /
                                {{ $formatCrypto($voucher->max_discount_usdc, 'USDC') }}
                            </div>
                        </div>
                        <span class="text-xs {{ $voucher->is_active ? 'text-emerald-300' : 'text-gray-500' }}">
                            {{ $voucher->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                    <div class="mt-4 text-xs text-gray-400">
                        Minimum {{ $formatIdr($voucher->minimum_purchase) }} · {{ $voucher->active_uses_count }} active uses
                    </div>
                    <div class="mt-4 flex gap-2">
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
