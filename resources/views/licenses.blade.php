@extends('layouts.app')

@section('content')
    @php
        $discordUrl = config('links.discord_url');
        $licenseCount = $licenses->count();
        $latestLicense = $licenses->first();
        $orderStats = $orderStats ?? [
            'total' => 0,
            'paid' => 0,
            'pending' => 0,
        ];
        $selectedOrderId = request()->query('order');
        $selectedOrderId = is_string($selectedOrderId) ? trim($selectedOrderId) : '';
        $renderedOrderAnchors = [];
        $renderedReviewPrompts = [];
        $reviewLookup = $reviewLookup ?? collect();
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
                <p class="text-xs font-semibold uppercase tracking-normal text-[#C084FC]">Keys</p>
                <h2 class="mt-1 text-2xl font-semibold text-white">Available licenses</h2>
            </div>
            <a href="/downloads" class="btn-footer-secondary w-fit">
                <x-ui.icon name="download" class="h-4 w-4" />
                <span>Download Tools</span>
            </a>
        </div>

        <div class="grid gap-4 md:gap-6">

            @forelse($licenses as $license)
                @php
                    $licenseOrderId = (string) $license->order_id;
                    $isFirstForOrder = $licenseOrderId !== '' && ! isset($renderedOrderAnchors[$licenseOrderId]);
                    $licenseAnchor = $licenseOrderId !== ''
                        ? 'license-' . $licenseOrderId . ($isFirstForOrder ? '' : '-' . $license->id)
                        : 'license-' . $license->id;
                    $isSelectedLicense = $selectedOrderId !== '' && $licenseOrderId !== '' && hash_equals($selectedOrderId, $licenseOrderId);
                    $renderedOrderAnchors[$licenseOrderId] = true;
                    $reviewKey = $license->product_id . '|' . $licenseOrderId;
                    $existingReview = $reviewLookup->get($reviewKey);
                    $canRenderReviewPrompt = $licenseOrderId !== ''
                        && $license->product_id
                        && ! isset($renderedReviewPrompts[$reviewKey]);
                    $renderedReviewPrompts[$reviewKey] = true;
                @endphp

                <div id="{{ $licenseAnchor }}" class="license-card motion-card scroll-mt-28 p-4 md:p-6 {{ $isSelectedLicense ? 'license-card-selected' : '' }}">

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

                                @if ($isSelectedLicense)
                                    <span class="text-[10px] sm:text-xs bg-[#9333EA]/25 text-[#E9D5FF] px-2 py-1 rounded">
                                        SELECTED ORDER
                                    </span>
                                @endif
                            </h2>

                            <p class="text-xs sm:text-sm text-gray-400">
                                {{ str_replace(['1 Hari', '7 Hari', '30 Hari', 'Hari'], ['1 Day', '7 Days', '30 Days', 'Days'], $license->duration) }}
                            </p>

                            @if ($licenseOrderId !== '')
                                <p class="mt-1 font-mono text-[10px] sm:text-xs text-gray-500">
                                    Order: {{ $licenseOrderId }}
                                </p>
                            @endif

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

                        <button type="button" data-copy-license="{{ $license->id }}"
                            class="order-action btn-press self-end sm:self-auto">
                            <x-ui.icon name="copy" class="h-4 w-4" />
                            <span data-button-label>Copy</span>
                        </button>

                    </div>

                    @if ($canRenderReviewPrompt)
                        <div class="review-prompt">
                            @if ($existingReview && $existingReview->status !== \App\Models\ProductReview::STATUS_REJECTED)
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <div class="text-sm font-semibold text-white">Review submitted</div>
                                        <p class="mt-1 text-xs text-gray-400">
                                            Status: {{ \App\Models\ProductReview::statusOptions()[$existingReview->status] ?? $existingReview->status }}
                                        </p>
                                    </div>
                                    <span class="support-pill">{{ $existingReview->rating }}/5</span>
                                </div>
                            @else
                                <details>
                                    <summary>
                                        <span>Leave a review</span>
                                        <span class="text-xs text-gray-500">Only approved reviews appear publicly.</span>
                                    </summary>

                                    @if ($existingReview?->status === \App\Models\ProductReview::STATUS_REJECTED)
                                        <p class="mt-3 text-xs text-amber-200">Your previous review was not approved. You can update and send it again.</p>
                                    @endif

                                    <form action="{{ route('reviews.store') }}" method="POST" class="mt-4 grid gap-3">
                                        @csrf
                                        <input type="hidden" name="product_id" value="{{ $license->product_id }}">
                                        <input type="hidden" name="order_id" value="{{ $licenseOrderId }}">

                                        <div class="grid gap-3 sm:grid-cols-[150px_1fr]">
                                            <label class="block">
                                                <span class="mb-2 block text-xs font-semibold text-gray-400">Rating</span>
                                                <select name="rating" class="search-bar w-full" required>
                                                    @for ($rating = 5; $rating >= 1; $rating--)
                                                        <option value="{{ $rating }}" @selected((int) old('rating', $existingReview->rating ?? 5) === $rating)>
                                                            {{ $rating }}/5
                                                        </option>
                                                    @endfor
                                                </select>
                                            </label>

                                            <label class="block">
                                                <span class="mb-2 block text-xs font-semibold text-gray-400">Feedback</span>
                                                <textarea name="body" rows="3" class="search-bar min-h-24 w-full resize-y"
                                                    maxlength="420" required
                                                    placeholder="Share what helped you after buying this product.">{{ old('body', $existingReview->body ?? '') }}</textarea>
                                            </label>
                                        </div>

                                        <button class="btn-footer w-fit">
                                            <x-ui.icon name="sparkles" class="h-4 w-4" />
                                            <span>Submit Review</span>
                                        </button>
                                    </form>
                                </details>
                            @endif
                        </div>
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
