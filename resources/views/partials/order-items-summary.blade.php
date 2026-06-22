@php
    $summaryItems = $order->lineItems();
    $compact = $compact ?? false;
@endphp

<div class="{{ $compact ? 'grid gap-1' : 'grid gap-2' }}">
    @foreach ($summaryItems as $summaryItem)
        <div class="{{ $compact ? 'text-xs' : 'rounded-lg border border-[#27272A] bg-black/15 px-3 py-2 text-sm' }}">
            <span class="font-semibold text-white">{{ $summaryItem->product_name }}</span>
            <span class="text-gray-500">· {{ $summaryItem->package_name }}</span>
            <span class="text-[#C084FC]">×{{ $summaryItem->quantity }}</span>
        </div>
    @endforeach
</div>
