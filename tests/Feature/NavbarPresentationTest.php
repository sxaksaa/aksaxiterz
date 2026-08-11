<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class NavbarPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for navbar presentation tests.');
        }

        parent::setUp();
    }

    public function test_guest_navbar_uses_floating_pill_and_accessible_mobile_panel(): void
    {
        $html = view('partials.navbar', ['cartCount' => 0])->render();

        $this->assertStringContainsString('id="navbar"', $html);
        $this->assertStringContainsString('site-navbar-pill', $html);
        $this->assertStringContainsString('id="menuBtn"', $html);
        $this->assertStringContainsString('aria-controls="mobileMenu"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('id="mobileMenu"', $html);
        $this->assertStringContainsString('mobile-nav-panel', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('href="/auth/google"', $html);
        $this->assertStringContainsString('data-navbar-actions', $html);
        $this->assertStringContainsString('data-desktop-discord', $html);
        $this->assertStringContainsString('aria-label="Open Discord support"', $html);
        $this->assertStringContainsString('decoding="async" fetchpriority="high"', $html);
        $this->assertStringContainsString('discord-nav-icon', $html);
        $this->assertStringContainsString('mobile-discord-link', $html);
        $this->assertStringNotContainsString('Open cart with', $html);
    }

    public function test_authenticated_navbar_keeps_cart_account_and_admin_links(): void
    {
        $user = User::factory()->create();
        config(['admin.emails' => [strtolower($user->email)]]);
        $this->actingAs($user);

        $html = view('partials.navbar', ['cartCount' => 0])->render();

        $this->assertStringContainsString('Open cart with 0 items', $html);
        $this->assertStringContainsString('data-cart-count', $html);
        $this->assertStringContainsString('data-mini-cart-root', $html);
        $this->assertStringContainsString('data-mini-cart-url="'.route('cart.preview').'"', $html);
        $this->assertStringContainsString('data-mini-cart-panel', $html);
        $this->assertStringContainsString('data-mini-cart-content', $html);
        $this->assertStringContainsString('Close cart preview', $html);
        $this->assertStringContainsString('mini-cart-skeleton-row', $html);
        $this->assertStringContainsString('href="/orders"', $html);
        $this->assertStringContainsString('href="/licenses"', $html);
        $this->assertStringContainsString('data-profile-toggle', $html);
        $this->assertStringContainsString('aria-label="Open account menu"', $html);
        $this->assertStringContainsString('class="relative hidden shrink-0 xl:block"', $html);
        $this->assertStringContainsString('Admin Dashboard', $html);
        $this->assertStringContainsString('action="/logout"', $html);
    }

    public function test_pending_payment_reminder_stays_compact_and_links_to_the_invoice(): void
    {
        $html = view('partials.pending-payment-reminder', [
            'pendingOrderCount' => 1,
            'pendingOrder' => (object) ['order_id' => 'ORDER-PENDING-REMINDER'],
        ])->render();

        $this->assertStringContainsString('1 payment', $html);
        $this->assertStringContainsString('is waiting', $html);
        $this->assertStringContainsString('Continue Payment', $html);
        $this->assertStringContainsString('/orders/ORDER-PENDING-REMINDER/payment', $html);
    }
}
