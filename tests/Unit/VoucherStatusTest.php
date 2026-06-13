<?php

namespace Tests\Unit;

use App\Models\Voucher;
use Tests\TestCase;

class VoucherStatusTest extends TestCase
{
    public function test_voucher_availability_status_explains_manual_and_schedule_state(): void
    {
        $voucher = new Voucher(['is_active' => false]);
        $this->assertSame('Inactive', $voucher->availabilityLabel());

        $voucher = new Voucher(['is_active' => true, 'starts_at' => now()->addHour()]);
        $this->assertSame('Scheduled', $voucher->availabilityLabel());

        $voucher = new Voucher(['is_active' => true, 'expires_at' => now()->subHour()]);
        $this->assertSame('Expired', $voucher->availabilityLabel());

        $voucher = new Voucher(['is_active' => true, 'usage_limit' => 2]);
        $voucher->setAttribute('active_uses_count', 2);
        $this->assertSame('Limit Reached', $voucher->availabilityLabel());

        $voucher = new Voucher(['is_active' => true]);
        $this->assertSame('Active Now', $voucher->availabilityLabel());
    }
}
