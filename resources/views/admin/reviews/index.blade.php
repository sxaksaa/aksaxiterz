@extends('layouts.app')

@section('content')
    <div class="page-shell py-6 md:py-10">
        <section class="orders-hero fade-up mb-6">
            <div>
                <p class="mb-2 text-sm font-semibold text-[#C084FC]">Admin</p>
                <h1 class="text-3xl font-bold tracking-normal md:text-4xl">Reviews</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-gray-400 md:text-base">
                    Approve buyer feedback before it appears on product pages.
                </p>
            </div>

            <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['total'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Total reviews</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['pending'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Pending</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['approved'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Approved</div>
                </div>
                <div class="order-stat">
                    <div class="text-xl font-semibold text-white">{{ $stats['rejected'] }}</div>
                    <div class="mt-1 text-xs text-gray-400">Rejected</div>
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
            <form method="GET" action="{{ route('admin.reviews.index') }}"
                class="grid gap-3 md:grid-cols-[1fr_220px_auto] md:items-end">
                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Search</span>
                    <input name="search" value="{{ request('search') }}" class="search-bar w-full"
                        placeholder="Customer, product, or feedback">
                </label>

                <label class="block">
                    <span class="mb-2 block text-xs font-semibold text-gray-400">Status</span>
                    <select name="status" class="search-bar w-full">
                        <option value="">All statuses</option>
                        @foreach ($statusOptions as $statusValue => $statusLabel)
                            <option value="{{ $statusValue }}" @selected(request('status') === $statusValue)>
                                {{ $statusLabel }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <div class="flex gap-2">
                    <button class="btn-footer h-12">
                        <x-ui.icon name="filter" class="h-4 w-4" />
                        <span>Filter</span>
                    </button>
                    <a href="{{ route('admin.reviews.index') }}" class="btn-footer-secondary h-12">
                        <x-ui.icon name="rotate-ccw" class="h-4 w-4" />
                        <span>Reset</span>
                    </a>
                </div>
            </form>
        </section>

        <div class="orders-table-wrap hidden lg:block">
            <div class="flex items-center justify-between gap-3 border-b border-[#27272A] px-4 py-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">Buyer Feedback</h2>
                    <p class="mt-1 text-xs text-gray-500">Approved reviews appear on the matching product page.</p>
                </div>
                <span class="rounded-lg border border-[#9333EA]/30 bg-[#9333EA]/10 px-3 py-1 text-xs font-semibold text-[#C084FC]">
                    {{ $reviews->total() }} records
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-sm">
                    <thead class="bg-[#111115] text-xs uppercase tracking-normal text-gray-500">
                        <tr>
                            <th class="p-4 text-left">Review</th>
                            <th class="p-4 text-left">Product</th>
                            <th class="p-4 text-left">Customer</th>
                            <th class="p-4 text-left">Status</th>
                            <th class="p-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reviews as $review)
                            @php
                                $statusClass = match ($review->status) {
                                    \App\Models\ProductReview::STATUS_APPROVED => 'status-pill-paid',
                                    \App\Models\ProductReview::STATUS_REJECTED => 'status-pill-cancelled',
                                    default => 'status-pill-pending',
                                };
                            @endphp
                            <tr class="orders-table-row">
                                <td class="p-4">
                                    <div class="font-semibold text-white">{{ $review->rating }}/5</div>
                                    <p class="mt-2 max-w-[360px] text-xs leading-5 text-gray-300">{{ $review->body }}</p>
                                    <div class="mt-2 text-xs text-gray-500">{{ $review->created_at->format('d M Y, H:i') }}</div>
                                </td>
                                <td class="p-4 text-gray-300">
                                    {{ $review->product?->name ?? '-' }}
                                    <div class="mt-1 font-mono text-xs text-gray-500">{{ $review->order?->order_id ?? '-' }}</div>
                                </td>
                                <td class="p-4 text-gray-300">
                                    {{ $review->user?->name ?? 'Customer' }}
                                    <div class="mt-1 text-xs text-gray-500">{{ $review->user?->email }}</div>
                                </td>
                                <td class="p-4">
                                    <span class="status-pill {{ $statusClass }}">
                                        {{ $statusOptions[$review->status] ?? $review->status }}
                                    </span>
                                </td>
                                <td class="p-4 text-right">
                                    <div class="inline-flex justify-end gap-2">
                                        <form action="{{ route('admin.reviews.update', $review) }}" method="POST">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="{{ \App\Models\ProductReview::STATUS_APPROVED }}">
                                            <button class="order-action">
                                                <x-ui.icon name="check-circle" class="h-4 w-4" />
                                                <span>Approve</span>
                                            </button>
                                        </form>
                                        <form action="{{ route('admin.reviews.update', $review) }}" method="POST">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="{{ \App\Models\ProductReview::STATUS_REJECTED }}">
                                            <button class="order-action order-action-danger">
                                                <x-ui.icon name="x" class="h-4 w-4" />
                                                <span>Reject</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-8">
                                    <div class="empty-state">No reviews found</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-4 lg:hidden">
            @forelse ($reviews as $review)
                @php
                    $statusClass = match ($review->status) {
                        \App\Models\ProductReview::STATUS_APPROVED => 'status-pill-paid',
                        \App\Models\ProductReview::STATUS_REJECTED => 'status-pill-cancelled',
                        default => 'status-pill-pending',
                    };
                @endphp
                <article class="order-mobile-card motion-card">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-semibold text-white">{{ $review->product?->name ?? 'Product' }}</div>
                            <div class="mt-1 text-xs text-gray-500">{{ $review->rating }}/5 by {{ $review->user?->name ?? 'Customer' }}</div>
                        </div>
                        <span class="status-pill {{ $statusClass }}">
                            {{ $statusOptions[$review->status] ?? $review->status }}
                        </span>
                    </div>

                    <p class="mt-3 text-sm leading-6 text-gray-300">{{ $review->body }}</p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <form action="{{ route('admin.reviews.update', $review) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ \App\Models\ProductReview::STATUS_APPROVED }}">
                            <button class="order-action">
                                <x-ui.icon name="check-circle" class="h-4 w-4" />
                                <span>Approve</span>
                            </button>
                        </form>
                        <form action="{{ route('admin.reviews.update', $review) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ \App\Models\ProductReview::STATUS_REJECTED }}">
                            <button class="order-action order-action-danger">
                                <x-ui.icon name="x" class="h-4 w-4" />
                                <span>Reject</span>
                            </button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="empty-state">No reviews found</div>
            @endforelse
        </div>

        @include('partials.pagination', [
            'paginator' => $reviews,
            'label' => 'Reviews pagination',
            'itemLabel' => 'reviews',
        ])
    </div>
@endsection
