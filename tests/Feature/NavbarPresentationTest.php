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
        $this->assertStringContainsString('href="/orders"', $html);
        $this->assertStringContainsString('href="/licenses"', $html);
        $this->assertStringContainsString('data-profile-toggle', $html);
        $this->assertStringContainsString('Admin Dashboard', $html);
        $this->assertStringContainsString('action="/logout"', $html);
    }
}
