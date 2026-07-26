<?php

namespace App\Services;

use App\Models\GopayNotificationEvent;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

class PendingGopayDeliveryService
{
    public function __construct(
        private readonly OrderFulfillmentService $orderFulfillmentService
    ) {}

    /**
     * @return array{checked: int, delivered: int, waiting_for_stock: int, failed: int}
     */
    public function retry(int $limit = 100): array
    {
        $summary = [
            'checked' => 0,
            'delivered' => 0,
            'waiting_for_stock' => 0,
            'failed' => 0,
        ];

        $events = GopayNotificationEvent::query()
            ->where('status', 'matched_delivery_pending')
            ->whereNotNull('matched_order_id')
            ->oldest('updated_at')
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($events as $event) {
            $summary['checked']++;
            $order = Order::find($event->matched_order_id);

            if (! $order || $order->status !== 'paid') {
                $summary['failed']++;
                $event->update(['status' => 'matched_delivery_failed']);
                Log::warning('Automatic GoPay license delivery stopped for an invalid matched order.', [
                    'event_id' => $event->event_id,
                    'matched_order_id' => $event->matched_order_id,
                    'order_status' => $order?->status,
                ]);

                continue;
            }

            try {
                $this->orderFulfillmentService->fulfill($order);
            } catch (\Exception $error) {
                if ($error->getMessage() === 'No license stock available for this package') {
                    $summary['waiting_for_stock']++;
                    $event->touch();

                    continue;
                }

                $summary['failed']++;
                $event->touch();
                Log::warning('Automatic GoPay license delivery retry failed.', [
                    'event_id' => $event->event_id,
                    'order_id' => $order->order_id,
                    'error_type' => $error::class,
                    'error_code' => (string) $error->getCode(),
                ]);

                continue;
            }

            $deliveredCount = $order->licenses()->count();

            if ($deliveredCount < max(1, (int) $order->quantity)) {
                $summary['waiting_for_stock']++;
                $event->touch();

                continue;
            }

            GopayNotificationEvent::whereKey($event->id)
                ->where('status', 'matched_delivery_pending')
                ->update(['status' => 'matched']);
            $summary['delivered']++;
        }

        return $summary;
    }
}
