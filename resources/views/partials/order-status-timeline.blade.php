@php
    $status = (string) ($order->status ?? '');
    $isPaid = $status === 'paid';
    $isPastDue = $status === 'pending' && $order->expired_at && now()->gte($order->expired_at);
    $isPending = $status === 'pending' && ! $isPastDue;
    $wasExpiredBySystem = $status === 'cancelled' &&
        $order->expired_at &&
        $order->updated_at &&
        $order->updated_at->gte($order->expired_at);
    $isExpired = $status === 'expired' || $isPastDue || $wasExpiredBySystem;
    $isCancelled = $status === 'cancelled' && ! $isExpired;
    $isClosed = $isExpired || $isCancelled;
    $deliveredCount = (int) ($order->licenses_count ?? 0);
    $quantity = max(1, (int) ($order->quantity ?? $order->total_quantity ?? 1));
    $isDelivered = $isPaid && $deliveredCount >= $quantity;

    $timelineSteps = [
        ['label' => 'Created', 'state' => 'is-complete'],
        ['label' => $isClosed ? 'Closed' : 'Payment', 'state' => $isPaid ? 'is-complete' : ($isPending ? 'is-current' : ($isClosed ? 'is-muted' : ''))],
        ['label' => 'Paid', 'state' => $isPaid ? 'is-complete' : ''],
        ['label' => 'Delivered', 'state' => $isDelivered ? 'is-complete' : ($isPaid ? 'is-current' : '')],
    ];
@endphp

<div class="order-timeline" aria-label="Order progress">
    @foreach ($timelineSteps as $step)
        <span class="order-timeline-step {{ $step['state'] }}">
            <span class="order-timeline-dot" aria-hidden="true"></span>
            <span>{{ $step['label'] }}</span>
        </span>
    @endforeach
</div>
