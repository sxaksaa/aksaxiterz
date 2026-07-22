<?php

namespace App\Services;

use App\Models\GopayNotificationEvent;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GopayWebhookService
{
    public function __construct(
        private readonly OrderFulfillmentService $orderFulfillmentService
    ) {}

    public function handle(Request $request): array
    {
        $rawBody = $request->getContent();

        if (strlen($rawBody) > 16_384) {
            throw new HttpException(413, 'Webhook payload is too large');
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw new HttpException(422, 'Invalid JSON payload');
        }

        $this->authenticate($request, $rawBody, $payload);
        $event = $this->normalize($payload);

        return DB::transaction(function () use ($event): array {
            $stored = $this->lockedEvent($event);

            if (! $this->sameImmutableEvent($stored, $event)) {
                throw new HttpException(409, 'Conflicting event payload');
            }

            $stored->update(['last_received_at' => now()]);

            if ($stored->matched_order_id || str_starts_with($stored->status, 'matched')) {
                $order = Order::find($stored->matched_order_id);

                return [
                    'http_status' => 200,
                    'payload' => $this->matchedResponse($stored, $order, true),
                ];
            }

            if ($event['stale'] || $stored->status === 'stale') {
                $stored->update(['status' => 'stale']);

                return [
                    'http_status' => 202,
                    'payload' => [
                        'event_id' => $stored->event_id,
                        'status' => 'stale',
                        'message' => 'Old notification recorded for manual reconciliation.',
                    ],
                ];
            }

            $orders = $this->matchingOrders($event)->take(2);

            if ($orders->count() !== 1) {
                $status = $orders->isEmpty() ? 'unmatched' : 'ambiguous';
                $stored->update(['status' => $status]);

                return [
                    'http_status' => 202,
                    'payload' => [
                        'event_id' => $stored->event_id,
                        'status' => $status,
                        'message' => $status === 'unmatched'
                            ? 'Notification recorded for manual reconciliation.'
                            : 'More than one eligible order matched this notification.',
                    ],
                ];
            }

            $order = $orders->first();
            $orderPayload = is_array($order->payment_payload) ? $order->payment_payload : [];
            $orderPayload['scanner_status'] = 'matched';
            $orderPayload['notification_event_id'] = $stored->event_id;
            $orderPayload['notification_device_id'] = $stored->device_id;
            $orderPayload['notification_posted_at'] = Carbon::createFromTimestampMs(
                $stored->notification_posted_at_ms,
                'UTC'
            )->timezone(config('app.timezone'))->toIso8601String();
            $orderPayload['notification_title'] = $stored->title;

            $order->update([
                'payment_reference' => $stored->event_id,
                'payment_payload' => $orderPayload,
            ]);

            $deliveryPending = false;

            try {
                $this->orderFulfillmentService->fulfill($order);
            } catch (\Exception $error) {
                if ($error->getMessage() !== 'No license stock available for this package') {
                    throw $error;
                }

                $deliveryPending = true;
                $this->orderFulfillmentService->markPaid($order);
            }

            $stored->update([
                'status' => $deliveryPending ? 'matched_delivery_pending' : 'matched',
                'matched_order_id' => $order->id,
            ]);

            return [
                'http_status' => 200,
                'payload' => $this->matchedResponse($stored->fresh(), $order->fresh(), false, $deliveryPending),
            ];
        }, 3);
    }

    private function authenticate(Request $request, string $rawBody, array $payload): void
    {
        $token = (string) config('services.gopay_qris.webhook_token');
        $secret = (string) config('services.gopay_qris.webhook_secret');
        $device = trim((string) $request->header('X-Aksa-Device'));
        $timestamp = trim((string) $request->header('X-Aksa-Timestamp'));
        $signature = trim((string) $request->header('X-Aksa-Signature'));
        $allowedDevices = config('services.gopay_qris.allowed_devices', []);

        if ($token === '' || $secret === '' || ! is_array($allowedDevices) || $allowedDevices === []) {
            throw new HttpException(503, 'GoPay notification bridge is not configured');
        }

        $providedToken = (string) ($request->bearerToken() ?? '');

        if ($providedToken === '' || ! hash_equals($token, $providedToken)) {
            throw new HttpException(401, 'Invalid webhook authorization');
        }

        if ($device === '' || ! in_array($device, $allowedDevices, true)) {
            throw new HttpException(401, 'Unknown notification device');
        }

        if (! ctype_digit($timestamp)) {
            throw new HttpException(401, 'Invalid webhook timestamp');
        }

        $maxSkew = max(30, (int) config('services.gopay_qris.webhook_max_skew_seconds', 300));

        if (abs(now()->getTimestampMs() - (int) $timestamp) > $maxSkew * 1000) {
            throw new HttpException(401, 'Stale webhook timestamp');
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new HttpException(401, 'Invalid webhook signature');
        }

        if (
            ($payload['device_id'] ?? null) !== $device ||
            (string) ($payload['sent_at'] ?? '') !== $timestamp
        ) {
            throw new HttpException(422, 'Webhook envelope mismatch');
        }
    }

    private function normalize(array $payload): array
    {
        $eventId = strtolower(trim((string) ($payload['event_id'] ?? '')));
        $type = (string) ($payload['type'] ?? '');
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        $packageName = trim((string) ($payload['package_name'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $text = trim((string) ($payload['text'] ?? ''));
        $amount = filter_var($payload['amount_idr'] ?? null, FILTER_VALIDATE_INT);
        $postedAtMs = filter_var($payload['notification_posted_at'] ?? null, FILTER_VALIDATE_INT);

        if (! preg_match('/^[a-f0-9]{64}$/', $eventId)) {
            throw new HttpException(422, 'Invalid event ID');
        }

        if ($type !== 'qris.notification.received') {
            throw new HttpException(422, 'Invalid event type');
        }

        if ($packageName !== (string) config('services.gopay_qris.allowed_package')) {
            throw new HttpException(422, 'Invalid notification package');
        }

        if (! preg_match('/^Pembayaran QRIS (?:statis|dinamis) diterima$/iu', $title)) {
            throw new HttpException(422, 'Unrecognized payment notification');
        }

        if ($amount === false || $amount < 1 || $amount > 10_000_000 || $postedAtMs === false) {
            throw new HttpException(422, 'Invalid payment notification values');
        }

        $matches = [];

        if (! preg_match('/^Rp\s*([0-9][0-9.,]*)\s+di\s+(.+?)\.?$/iu', $text, $matches)) {
            throw new HttpException(422, 'Unrecognized payment notification text');
        }

        $textAmount = (int) preg_replace('/[^0-9]/', '', $matches[1]);
        $textMerchant = mb_strtolower(trim($matches[2], " \t\n\r\0\x0B."));
        $expectedMerchant = mb_strtolower(trim((string) config('services.gopay_qris.merchant_name')));

        if ($textAmount !== $amount || $expectedMerchant === '' || ! hash_equals($expectedMerchant, $textMerchant)) {
            throw new HttpException(422, 'Payment notification details do not match');
        }

        $postedAt = Carbon::createFromTimestampMs($postedAtMs, 'UTC')->timezone(config('app.timezone'));
        $maxAgeHours = max(1, (int) config('services.gopay_qris.notification_max_age_hours', 24));

        if ($postedAt->isAfter(now()->addMinutes(2))) {
            throw new HttpException(422, 'Payment notification is outside the accepted time window');
        }

        $stale = $postedAt->isBefore(now()->subHours($maxAgeHours));

        return compact('eventId', 'deviceId', 'packageName', 'title', 'text', 'amount', 'postedAt', 'stale');
    }

    private function lockedEvent(array $event): GopayNotificationEvent
    {
        try {
            GopayNotificationEvent::firstOrCreate(
                ['event_id' => $event['eventId']],
                [
                    'device_id' => $event['deviceId'],
                    'package_name' => $event['packageName'],
                    'title' => $event['title'],
                    'notification_text' => $event['text'],
                    'amount_idr' => $event['amount'],
                    'notification_posted_at_ms' => $event['postedAt']->getTimestampMs(),
                    'status' => 'received',
                    'received_at' => now(),
                    'last_received_at' => now(),
                ]
            );
        } catch (QueryException $error) {
            if (! str_contains(strtolower($error->getMessage()), 'event_id')) {
                throw $error;
            }
        }

        return GopayNotificationEvent::where('event_id', $event['eventId'])
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function sameImmutableEvent(GopayNotificationEvent $stored, array $event): bool
    {
        return $stored->device_id === $event['deviceId'] &&
            $stored->package_name === $event['packageName'] &&
            $stored->title === $event['title'] &&
            $stored->notification_text === $event['text'] &&
            (int) $stored->amount_idr === $event['amount'] &&
            (int) $stored->notification_posted_at_ms === $event['postedAt']->getTimestampMs();
    }

    private function matchingOrders(array $event)
    {
        $recoveryHours = max(1, (int) config('services.gopay_qris.recovery_hours', 24));
        $graceMinutes = max(0, (int) config('services.gopay_qris.grace_minutes', 2));
        $matchKey = hash('sha256', implode('|', [
            'gopay_qris',
            strtolower(trim((string) config('services.gopay_qris.merchant_reference'))),
            $event['amount'],
        ]));

        return Order::query()
            ->where('payment_method', 'gopay_qris')
            ->whereIn('status', ['pending', 'cancelled'])
            ->where('payment_match_key', $matchKey)
            ->where('price', $event['amount'])
            ->where('created_at', '<=', $event['postedAt'])
            ->where(function ($query) use ($event, $graceMinutes): void {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>=', $event['postedAt']->copy()->subMinutes($graceMinutes));
            })
            ->where(function ($query) use ($recoveryHours): void {
                $query->whereNull('expired_at')
                    ->orWhere('expired_at', '>=', now()->subHours($recoveryHours));
            })
            ->latest('id')
            ->lockForUpdate()
            ->get();
    }

    private function matchedResponse(
        GopayNotificationEvent $event,
        ?Order $order,
        bool $duplicate,
        bool $deliveryPending = false
    ): array {
        return [
            'event_id' => $event->event_id,
            'status' => $order?->status ?? 'paid',
            'order_id' => $order?->order_id,
            'duplicate' => $duplicate,
            'delivery_pending' => $deliveryPending || $event->status === 'matched_delivery_pending',
        ];
    }
}
