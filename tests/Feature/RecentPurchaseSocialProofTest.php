<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\License;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentPurchaseSocialProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_shows_recent_paid_purchases_without_exposing_full_buyer_name(): void
    {
        [$product, $package] = $this->productWithPackage('Social Proof Tool', 'social-proof-tool', '7 Days');

        $paidBuyer = User::factory()->create([
            'name' => 'Akbar Rizki',
            'email' => 'akbar@example.com',
        ]);
        $this->createOrder($product, $package, $paidBuyer, [
            'order_id' => 'ORDER-PAID-SOCIAL',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(8),
        ]);

        $pendingBuyer = User::factory()->create([
            'name' => 'Pending Person',
            'email' => 'pending@example.com',
        ]);
        $this->createOrder($product, $package, $pendingBuyer, [
            'order_id' => 'ORDER-PENDING-SOCIAL',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('data-recent-purchase-toast', false)
            ->assertSee('A***r', false)
            ->assertSee('Social Proof Tool', false)
            ->assertSee('7 Days', false)
            ->assertDontSee('Akbar Rizki', false)
            ->assertDontSee('P*****g', false);
    }

    public function test_product_detail_recent_purchase_feed_is_scoped_to_current_product(): void
    {
        [$targetProduct, $targetPackage] = $this->productWithPackage('Target Social Tool', 'target-social-tool', '7 Days');
        [$otherProduct, $otherPackage] = $this->productWithPackage('Outside Social Tool', 'outside-social-tool', '30 Days');

        $auroraBuyer = User::factory()->create(['name' => 'Akbar']);
        $otherBuyer = User::factory()->create(['name' => 'Other Buyer']);

        $this->createOrder($targetProduct, $targetPackage, $auroraBuyer, [
            'order_id' => 'ORDER-TARGET-PAID',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(3),
        ]);
        $this->createOrder($otherProduct, $otherPackage, $otherBuyer, [
            'order_id' => 'ORDER-OUTSIDE-PAID',
            'status' => 'paid',
            'paid_at' => now()->subMinutes(2),
        ]);

        $this->get(route('products.show', $targetProduct))
            ->assertOk()
            ->assertSee('data-recent-purchase-toast', false)
            ->assertSee('data-recent-purchase-endpoint="'.route('purchases.recent', [], false).'"', false)
            ->assertSee('data-recent-purchase-product-slug="'.$targetProduct->slug.'"', false)
            ->assertSee('A***r', false)
            ->assertDontSee('O***r', false)
            ->assertDontSee('Outside Social Tool', false);
    }

    public function test_public_polling_feed_returns_a_purchase_after_pending_order_becomes_paid(): void
    {
        [$product, $package] = $this->productWithPackage(
            'Polling Social Tool',
            'polling-social-tool',
            '14 Days'
        );
        $buyer = User::factory()->create(['name' => 'Polling Buyer']);
        $order = $this->createOrder($product, $package, $buyer, [
            'order_id' => 'ORDER-POLL-TRANSITION',
            'status' => 'pending',
            'paid_at' => null,
        ]);

        $this->getJson(route('purchases.recent'))
            ->assertOk()
            ->assertExactJson(['purchases' => []]);

        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->getJson(route('purchases.recent'));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'purchases')
            ->assertJsonPath('purchases.0.buyer', 'P*****g')
            ->assertJsonPath('purchases.0.product', 'Polling Social Tool')
            ->assertJsonPath('purchases.0.package', '14 Days')
            ->assertJsonPath('purchases.0.quantity', 1)
            ->assertJsonPath('purchases.0.ago', 'just now');

        $this->assertNotEmpty($response->json('purchases.0.key'));
        $this->assertNotEmpty($response->json('purchases.0.paid_at'));

        $this->getJson(route('purchases.recent'))
            ->assertOk()
            ->assertJsonPath('purchases.0.key', $response->json('purchases.0.key'));
    }

    public function test_public_polling_feed_exposes_only_whitelisted_fields_and_no_purchase_secrets(): void
    {
        [$product, $package] = $this->productWithPackage(
            'Privacy Social Tool',
            'privacy-social-tool',
            '30 Days'
        );
        $buyer = User::factory()->create([
            'name' => 'Akbar Private Buyer',
            'email' => 'akbar.private@example.com',
        ]);
        $order = $this->createOrder($product, $package, $buyer, [
            'order_id' => 'ORDER-POLLING-SECRET',
            'payment_reference' => 'PAYMENT-REFERENCE-SECRET',
            'payment_match_key' => 'PAYMENT-MATCH-SECRET',
            'payment_payload' => ['wallet' => 'PAYMENT-PAYLOAD-SECRET'],
            'quantity' => 2,
            'paid_at' => now()->subMinute(),
        ]);
        $orderItem = $order->items()->firstOrFail();

        License::create([
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'license_key' => 'LICENSE-POLLING-SECRET',
            'duration' => $package->name,
            'order_id' => $order->order_id,
            'order_item_id' => $orderItem->id,
        ]);

        $response = $this->getJson(route('purchases.recent'));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'purchases')
            ->assertJsonPath('purchases.0.buyer', 'A***r')
            ->assertJsonPath('purchases.0.product', $product->name)
            ->assertJsonPath('purchases.0.package', $package->name)
            ->assertJsonPath('purchases.0.quantity', 2);

        $purchase = $response->json('purchases.0');
        $keys = array_keys($purchase);
        sort($keys);

        $this->assertSame([
            'ago',
            'buyer',
            'key',
            'package',
            'paid_at',
            'product',
            'quantity',
        ], $keys);
        $this->assertIsString($purchase['key']);
        $this->assertNotSame('', $purchase['key']);
        $this->assertNotSame($order->order_id, $purchase['key']);

        $body = $response->getContent();

        foreach ([
            'Akbar Private Buyer',
            'akbar.private@example.com',
            'ORDER-POLLING-SECRET',
            'LICENSE-POLLING-SECRET',
            'PAYMENT-REFERENCE-SECRET',
            'PAYMENT-MATCH-SECRET',
            'PAYMENT-PAYLOAD-SECRET',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_empty_public_feed_is_safe_and_home_still_renders_the_polling_host(): void
    {
        $response = $this->getJson(route('purchases.recent'));

        $response
            ->assertOk()
            ->assertExactJson(['purchases' => []]);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));

        $this->get('/')
            ->assertOk()
            ->assertSee('data-recent-purchase-toast', false)
            ->assertSee('data-recent-purchase-endpoint="'.route('purchases.recent', [], false).'"', false)
            ->assertSee('<template data-recent-purchase-data>[]</template>', false)
            ->assertDontSee('data-recent-purchase-product-slug=', false);
    }

    public function test_public_polling_feed_scopes_visible_products_and_rejects_hidden_or_unknown_slugs(): void
    {
        [$targetProduct, $targetPackage] = $this->productWithPackage(
            'Scoped Polling Tool',
            'scoped-polling-tool',
            '7 Days'
        );
        [$otherProduct, $otherPackage] = $this->productWithPackage(
            'Outside Polling Tool',
            'outside-polling-tool',
            '30 Days'
        );
        [$hiddenProduct, $hiddenPackage] = $this->productWithPackage(
            'Hidden Polling Tool',
            'hidden-polling-tool',
            'Lifetime'
        );
        $hiddenProduct->update(['is_visible' => false]);

        $this->createOrder($targetProduct, $targetPackage, User::factory()->create(['name' => 'Target Buyer']), [
            'order_id' => 'ORDER-SCOPED-TARGET',
            'paid_at' => now()->subMinutes(3),
        ]);
        $this->createOrder($otherProduct, $otherPackage, User::factory()->create(['name' => 'Outside Buyer']), [
            'order_id' => 'ORDER-SCOPED-OUTSIDE',
            'paid_at' => now()->subMinutes(2),
        ]);
        $this->createOrder($hiddenProduct, $hiddenPackage, User::factory()->create(['name' => 'Hidden Buyer']), [
            'order_id' => 'ORDER-SCOPED-HIDDEN',
            'paid_at' => now()->subMinute(),
        ]);

        $this->getJson(route('purchases.recent', ['product' => $targetProduct->slug]))
            ->assertOk()
            ->assertJsonCount(1, 'purchases')
            ->assertJsonPath('purchases.0.product', $targetProduct->name)
            ->assertDontSee($otherProduct->name)
            ->assertDontSee($hiddenProduct->name);

        $this->getJson(route('purchases.recent', ['product' => $hiddenProduct->slug]))
            ->assertNotFound();

        $this->getJson(route('purchases.recent', ['product' => 'unknown-polling-product']))
            ->assertNotFound();
    }

    private function productWithPackage(string $name, string $slug, string $packageName): array
    {
        $category = Category::create([
            'name' => 'PC',
            'slug' => $slug.'-category',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => $slug,
            'status' => Product::STATUS_READY,
            'description' => $name.' description.',
        ]);

        $package = Package::create([
            'product_id' => $product->id,
            'name' => $packageName,
            'price' => 20000,
            'price_usdt' => 1.25,
        ]);

        return [$product, $package];
    }

    private function createOrder(Product $product, Package $package, User $user, array $attributes): Order
    {
        $quantity = (int) ($attributes['quantity'] ?? 1);

        $order = Order::create(array_merge([
            'order_id' => 'ORDER-SOCIAL-'.uniqid(),
            'product_id' => $product->id,
            'package_id' => $package->id,
            'user_id' => $user->id,
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'price' => $package->price,
            'quantity' => $quantity,
            'paid_at' => now()->subMinutes(5),
        ], $attributes));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'package_id' => $package->id,
            'product_name' => $product->name,
            'package_name' => $package->name,
            'quantity' => $quantity,
            'unit_price_idr' => $package->price,
            'unit_price_usdt' => $package->price_usdt,
            'line_total_idr' => $package->price * $quantity,
            'line_total_usdt' => $package->price_usdt * $quantity,
        ]);

        return $order;
    }
}
