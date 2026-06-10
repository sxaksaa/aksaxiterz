<?php

namespace Tests\Unit;

use App\Services\CheckoutLockService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CheckoutLockServiceTest extends TestCase
{
    public function test_same_user_checkout_cannot_enter_while_lock_is_held(): void
    {
        config(['services.payments.checkout_lock_wait_seconds' => 0]);

        $heldLock = Cache::lock('payment-checkout:user:123', 120);
        $this->assertTrue($heldLock->get());

        try {
            $this->expectException(LockTimeoutException::class);

            app(CheckoutLockService::class)->run(123, fn () => 'should not run');
        } finally {
            $heldLock->release();
        }
    }

    public function test_different_users_can_checkout_independently(): void
    {
        config(['services.payments.checkout_lock_wait_seconds' => 0]);

        $heldLock = Cache::lock('payment-checkout:user:123', 120);
        $this->assertTrue($heldLock->get());

        try {
            $result = app(CheckoutLockService::class)->run(456, fn () => 'ran');
        } finally {
            $heldLock->release();
        }

        $this->assertSame('ran', $result);
    }
}
