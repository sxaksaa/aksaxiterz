<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\GopayNotificationEvent;
use App\Models\LicenseStock;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\PendingGopayDeliveryService;
use App\Services\StockReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\TestCase;

class GopayQrisWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-gopay-webhook-secret';

    private const TOKEN = 'test-gopay-webhook-token';

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for GoPay QRIS webhook tests.');
        }

        parent::setUp();

        config([
            'services.gopay_qris.enabled' => true,
            'services.gopay_qris.webhook_token' => self::TOKEN,
            'services.gopay_qris.webhook_secret' => self::SECRET,
            'services.gopay_qris.allowed_devices' => ['aksa-gopay-primary'],
            'services.gopay_qris.allowed_package' => 'com.gojek.gopaymerchant',
            'services.gopay_qris.merchant_name' => 'Aksa Xiterz',
            'services.gopay_qris.merchant_reference' => 'ID102432979310',
            'services.gopay_qris.webhook_max_skew_seconds' => 300,
            'services.gopay_qris.notification_max_age_hours' => 72,
            'services.gopay_qris.grace_minutes' => 2,
            'services.gopay_qris.delayed_recovery_min_minutes' => 60,
            'services.gopay_qris.recovery_hours' => 72,
        ]);
    }

    public function test_signed_exact_notification_fulfills_once_and_retry_is_idempotent(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt);
        $this->stock($order, 'GOPAY-LICENSE');

        $payload = $this->payload(50_123, $postedAt);

        $this->send($payload)
            ->assertOk()
            ->assertExactJson([
                'event_id' => $payload['event_id'],
                'status' => 'paid',
                'order_id' => $order->order_id,
                'duplicate' => false,
                'delivery_pending' => false,
            ]);

        $retryTimestamp = now()->addSecond()->getTimestampMs();
        $payload['sent_at'] = $retryTimestamp;

        $this->send($payload, $retryTimestamp)
            ->assertOk()
            ->assertExactJson([
                'event_id' => $payload['event_id'],
                'status' => 'paid',
                'order_id' => $order->order_id,
                'duplicate' => true,
                'delivery_pending' => false,
            ]);

        $this->assertDatabaseCount('gopay_notification_events', 1);
        $this->assertDatabaseCount('licenses', 1);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($payload['event_id'], $order->fresh()->payment_reference);
    }

    public function test_tampered_signature_is_rejected_without_recording_event(): void
    {
        $payload = $this->payload(50_123, now()->getTimestampMs());

        $this->send($payload, signature: 'sha256='.str_repeat('0', 64))
            ->assertUnauthorized();

        $this->assertDatabaseCount('gopay_notification_events', 0);
    }

    public function test_invalid_bearer_token_is_rejected_without_recording_event(): void
    {
        $payload = $this->payload(50_123, now()->getTimestampMs());

        $this->send($payload, authorization: 'Bearer wrong-token')
            ->assertUnauthorized();

        $this->assertDatabaseCount('gopay_notification_events', 0);
    }

    public function test_stale_transport_timestamp_is_rejected_without_recording_event(): void
    {
        $payload = $this->payload(50_123, now()->getTimestampMs());
        $staleTimestamp = now()->subMinutes(6)->getTimestampMs();

        $this->send($payload, $staleTimestamp)
            ->assertUnauthorized();

        $this->assertDatabaseCount('gopay_notification_events', 0);
    }

    public function test_unknown_device_is_rejected_without_recording_event(): void
    {
        $payload = $this->payload(50_123, now()->getTimestampMs());
        $payload['device_id'] = 'unknown-phone';

        $this->send($payload, device: 'unknown-phone')
            ->assertUnauthorized();

        $this->assertDatabaseCount('gopay_notification_events', 0);
    }

    public function test_wrong_notification_package_is_rejected_without_recording_event(): void
    {
        $payload = $this->payload(50_123, now()->getTimestampMs());
        $payload['package_name'] = 'com.example.fake';

        $this->send($payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('gopay_notification_events', 0);
    }

    public function test_signed_wrong_amount_is_recorded_and_acknowledged(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt);

        $this->send($this->payload(50_124, $postedAt))
            ->assertStatus(202)
            ->assertJsonPath('status', 'unmatched');

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseHas('gopay_notification_events', [
            'amount_idr' => 50_124,
            'status' => 'unmatched',
            'matched_order_id' => null,
        ]);
    }

    public function test_same_event_id_with_mutated_core_is_rejected(): void
    {
        $postedAt = now()->getTimestampMs();
        $payload = $this->payload(50_123, $postedAt);

        $this->send($payload)->assertStatus(202);

        $payload['amount_idr'] = 50_124;
        $payload['text'] = 'Rp 50.124 di Aksa Xiterz.';

        $this->send($payload)->assertStatus(409);
        $this->assertDatabaseCount('gopay_notification_events', 1);
    }

    public function test_old_signed_notification_is_stored_as_stale_and_acknowledged(): void
    {
        $postedAt = now()->subHours(73)->getTimestampMs();

        $this->send($this->payload(50_123, $postedAt))
            ->assertStatus(202)
            ->assertJsonPath('status', 'stale');

        $this->assertDatabaseHas('gopay_notification_events', ['status' => 'stale']);
    }

    public function test_notification_delayed_for_more_than_a_day_recovers_exact_cancelled_order(): void
    {
        $paidAt = now()->subHours(25);
        $expiry = $paidAt->copy()->addMinutes(10);
        $notificationPostedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(
            50_123,
            $paidAt->getTimestampMs(),
            $expiry,
            'cancelled'
        );
        $this->stock($order, 'OFFLINE-DAY-GOPAY-LICENSE');
        $payload = $this->payload(50_123, $notificationPostedAt);

        $this->send($payload)
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('order_id', $order->order_id)
            ->assertJsonPath('delayed_recovery', true);

        $retryTimestamp = now()->addSecond()->getTimestampMs();
        $payload['sent_at'] = $retryTimestamp;
        $this->send($payload, $retryTimestamp)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('delayed_recovery', true);

        $freshOrder = $order->fresh();

        $this->assertSame('paid', $freshOrder->status);
        $this->assertSame('delayed_recovery', $freshOrder->payment_payload['scanner_match_mode'] ?? null);
        $this->assertDatabaseHas('licenses', ['license_key' => 'OFFLINE-DAY-GOPAY-LICENSE']);
    }

    public function test_payment_posted_after_expiry_grace_does_not_match(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt, now()->subMinutes(3));

        $this->send($this->payload(50_123, $postedAt))
            ->assertStatus(202)
            ->assertJsonPath('status', 'unmatched');

        $this->assertNotSame('paid', $order->fresh()->status);
    }

    public function test_notification_posted_before_order_creation_cannot_pay_new_order(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt);
        $createdAfterPayment = Carbon::createFromTimestampMs($postedAt, config('app.timezone'))->addSecond();
        $order->forceFill([
            'created_at' => $createdAfterPayment,
            'updated_at' => $createdAfterPayment,
        ])->saveQuietly();
        $this->stock($order, 'TOO-EARLY-GOPAY-LICENSE');

        $this->send($this->payload(50_123, $postedAt))
            ->assertStatus(202)
            ->assertJsonPath('status', 'unmatched');

        $this->assertNotSame('paid', $order->fresh()->status);
        $this->assertDatabaseMissing('licenses', ['license_key' => 'TOO-EARLY-GOPAY-LICENSE']);
    }

    public function test_payment_posted_before_expiry_can_recover_cancelled_order_later(): void
    {
        $expiry = now()->subHour();
        $postedAt = $expiry->copy()->subMinute()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt, $expiry, 'cancelled');
        $this->stock($order, 'LATE-GOPAY-LICENSE');

        $this->send($this->payload(50_123, $postedAt))
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseHas('licenses', ['license_key' => 'LATE-GOPAY-LICENSE']);
    }

    public function test_verified_payment_without_stock_is_paid_and_marked_delivery_pending(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt);

        $this->send($this->payload(50_123, $postedAt))
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('delivery_pending', true);

        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseHas('gopay_notification_events', [
            'status' => 'matched_delivery_pending',
            'matched_order_id' => $order->id,
        ]);
    }

    public function test_delivery_pending_payment_is_fulfilled_automatically_after_stock_arrives(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt);

        $this->send($this->payload(50_123, $postedAt))
            ->assertOk()
            ->assertJsonPath('delivery_pending', true);

        $this->stock($order->fresh(), 'RESTOCKED-GOPAY-LICENSE');
        $summary = app(PendingGopayDeliveryService::class)->retry();

        $this->assertSame([
            'checked' => 1,
            'delivered' => 1,
            'waiting_for_stock' => 0,
            'failed' => 0,
        ], $summary);
        $this->assertDatabaseHas('licenses', [
            'order_id' => $order->order_id,
            'license_key' => 'RESTOCKED-GOPAY-LICENSE',
        ]);
        $this->assertDatabaseHas('gopay_notification_events', [
            'matched_order_id' => $order->id,
            'status' => 'matched',
        ]);

        $this->assertSame([
            'checked' => 0,
            'delivered' => 0,
            'waiting_for_stock' => 0,
            'failed' => 0,
        ], app(PendingGopayDeliveryService::class)->retry());
        $this->assertDatabaseCount('licenses', 1);
    }

    public function test_second_distinct_notification_cannot_fulfill_the_same_order_twice(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt);
        $this->stock($order, 'SINGLE-GOPAY-LICENSE');

        $this->send($this->payload(50_123, $postedAt))->assertOk();
        $this->send($this->payload(50_123, $postedAt + 1))
            ->assertStatus(202)
            ->assertJsonPath('status', 'unmatched');

        $this->assertDatabaseCount('gopay_notification_events', 2);
        $this->assertDatabaseCount('licenses', 1);
    }

    public function test_delivery_retry_stops_reprocessing_an_invalid_non_paid_match(): void
    {
        $postedAt = now()->getTimestampMs();
        $order = $this->gopayOrder(50_123, $postedAt);
        $payload = $this->payload(50_123, $postedAt);

        GopayNotificationEvent::create([
            'event_id' => $payload['event_id'],
            'device_id' => $payload['device_id'],
            'package_name' => $payload['package_name'],
            'title' => $payload['title'],
            'notification_text' => $payload['text'],
            'amount_idr' => $payload['amount_idr'],
            'notification_posted_at_ms' => $payload['notification_posted_at'],
            'status' => 'matched_delivery_pending',
            'matched_order_id' => $order->id,
            'received_at' => now(),
            'last_received_at' => now(),
        ]);

        $this->assertSame(1, app(PendingGopayDeliveryService::class)->retry()['failed']);
        $this->assertDatabaseHas('gopay_notification_events', [
            'matched_order_id' => $order->id,
            'status' => 'matched_delivery_failed',
        ]);
        $this->assertSame(0, app(PendingGopayDeliveryService::class)->retry()['checked']);
    }

    public function test_final_event_remains_idempotent_when_matched_order_was_removed(): void
    {
        $postedAt = now()->getTimestampMs();
        $payload = $this->payload(50_123, $postedAt);

        GopayNotificationEvent::create([
            'event_id' => $payload['event_id'],
            'device_id' => $payload['device_id'],
            'package_name' => $payload['package_name'],
            'title' => $payload['title'],
            'notification_text' => $payload['text'],
            'amount_idr' => $payload['amount_idr'],
            'notification_posted_at_ms' => $payload['notification_posted_at'],
            'status' => 'matched',
            'matched_order_id' => null,
            'received_at' => now(),
            'last_received_at' => now(),
        ]);

        $this->send($payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('status', 'paid');

        $this->assertDatabaseCount('gopay_notification_events', 1);
    }

    private function payload(int $amount, int $postedAt): array
    {
        $sentAt = now()->getTimestampMs();
        $formatted = number_format($amount, 0, ',', '.');

        return [
            'event_id' => hash('sha256', "gopay|{$amount}|{$postedAt}"),
            'type' => 'qris.notification.received',
            'device_id' => 'aksa-gopay-primary',
            'package_name' => 'com.gojek.gopaymerchant',
            'title' => 'Pembayaran QRIS statis diterima',
            'text' => "Rp {$formatted} di Aksa Xiterz.",
            'amount_idr' => $amount,
            'notification_posted_at' => $postedAt,
            'sent_at' => $sentAt,
        ];
    }

    private function send(
        array $payload,
        ?int $timestamp = null,
        ?string $signature = null,
        string $device = 'aksa-gopay-primary',
        ?string $authorization = null,
    ): TestResponse {
        $timestamp ??= (int) $payload['sent_at'];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature ??= 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, self::SECRET);
        $authorization ??= 'Bearer '.self::TOKEN;

        return $this->call(
            'POST',
            '/api/payments/gopay-qris/notifications',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => $authorization,
                'HTTP_X_AKSA_DEVICE' => $device,
                'HTTP_X_AKSA_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_AKSA_SIGNATURE' => $signature,
            ],
            $raw
        );
    }

    private function gopayOrder(
        int $amount,
        int $postedAt,
        $expiresAt = null,
        string $status = 'pending'
    ): Order {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'QRIS', 'slug' => 'qris-'.uniqid()]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'QRIS Product '.uniqid(),
            'slug' => 'qris-product-'.uniqid(),
            'status' => Product::STATUS_READY,
            'description' => 'QRIS webhook test product.',
        ]);
        $package = Package::create([
            'product_id' => $product->id,
            'name' => '1 Day',
            'price' => $amount - 123,
            'price_usdt' => 1,
        ]);
        $expiresAt ??= now()->addMinutes(10);
        $order = Order::create([
            'order_id' => 'ORDER-GOPAY-'.strtoupper(substr(uniqid(), -8)),
            'product_id' => $product->id,
            'package_id' => $package->id,
            'user_id' => $user->id,
            'status' => $status,
            'payment_method' => 'gopay_qris',
            'price' => $amount,
            'payment_match_key' => $this->matchKey($amount),
            'payment_payload' => [
                'type' => 'gopay_qris_notification',
                'base_amount' => $amount - 123,
                'unique_amount' => 123,
                'total_payment' => $amount,
                'scanner_status' => 'pending',
            ],
            'expired_at' => $expiresAt,
        ]);
        $createdAt = Carbon::createFromTimestampMs($postedAt, config('app.timezone'))->subMinutes(2);

        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $order;
    }

    private function stock(Order $order, string $key): void
    {
        LicenseStock::create([
            'product_id' => $order->product_id,
            'package_id' => $order->package_id,
            'license_key' => $key,
            'is_sold' => false,
        ]);

        if ($order->status === 'pending') {
            app(StockReservationService::class)->reserve($order);
        }
    }

    private function matchKey(int $amount): string
    {
        return hash('sha256', implode('|', ['gopay_qris', 'id102432979310', $amount]));
    }
}
