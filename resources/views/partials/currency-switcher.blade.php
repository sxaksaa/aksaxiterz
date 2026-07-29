@php
    $compact = (bool) ($compact ?? false);
@endphp

<div data-currency-switcher
    class="{{ $compact ? 'hidden shrink-0 items-center gap-1 rounded-full border border-white/10 bg-black/20 p-1 lg:inline-flex' : 'flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-black/20 p-3' }}">
    @if (! $compact)
        <span class="text-xs font-semibold text-gray-300">Display prices</span>
    @else
        <span class="sr-only">Display prices</span>
    @endif

    <span class="inline-flex rounded-full bg-black/25 p-0.5" role="group" aria-label="Display currency">
        <button type="button" data-currency-option="idr" aria-pressed="true"
            class="rounded-full bg-aksa-accent px-2.5 py-1.5 text-[11px] font-bold text-white shadow-sm transition"
            title="Show catalog prices in Indonesian rupiah">
            IDR
        </button>
        <button type="button" data-currency-option="usd" aria-pressed="false"
            class="rounded-full px-2.5 py-1.5 text-[11px] font-bold text-gray-400 transition hover:text-white"
            title="Show catalog prices in US dollars">
            USD
        </button>
    </span>
</div>
