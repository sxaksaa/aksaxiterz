<?php

namespace Tests\Feature;

use App\Models\GopayNotificationEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class AdminGopayNotificationEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for GoPay QRIS admin tests.');
        }

        parent::setUp();
        config(['admin.emails' => ['admin@example.com']]);
    }

    public function test_admin_can_review_unmatched_notification_ledger(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $event = $this->event('unmatched', 50_123);

        $this->actingAs($admin)
            ->get(route('admin.gopay-events.index'))
            ->assertOk()
            ->assertSee('GoPay QRIS Events')
            ->assertSee('Rp 50.123')
            ->assertSee('Unmatched')
            ->assertSee($event->event_id);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('QRIS events to review');
    }

    public function test_qris_notification_ledger_is_admin_only(): void
    {
        $customer = User::factory()->create(['email' => 'customer@example.com']);

        $this->actingAs($customer)
            ->get(route('admin.gopay-events.index'))
            ->assertNotFound();
    }

    private function event(string $status, int $amount): GopayNotificationEvent
    {
        return GopayNotificationEvent::create([
            'event_id' => hash('sha256', "admin-gopay-event|{$status}|{$amount}"),
            'device_id' => 'aksa-gopay-primary',
            'package_name' => 'com.gojek.gopaymerchant',
            'title' => 'Pembayaran QRIS statis diterima',
            'notification_text' => 'Rp '.number_format($amount, 0, ',', '.').' di Aksa Xiterz.',
            'amount_idr' => $amount,
            'notification_posted_at_ms' => now()->getTimestampMs(),
            'status' => $status,
            'received_at' => now(),
            'last_received_at' => now(),
        ]);
    }
}
