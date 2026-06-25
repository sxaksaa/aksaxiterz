<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class ProductMarketingFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
    }

    public function test_storefront_uses_paid_orders_for_sales_badges_and_faq_lives_on_legal_page(): void
    {
        [$bestSeller, $bestSellerPackage] = $this->productWithPackage('Aurora Best', 'aurora-best', '30 Days');
        [$popular, $popularPackage] = $this->productWithPackage('Aurora Popular', 'aurora-popular', '7 Days');

        $this->createPaidOrder($bestSeller, $bestSellerPackage, [
            'order_id' => 'ORDER-BEST-OLD',
            'quantity' => 5,
            'paid_at' => now()->subDays(45),
        ]);

        $this->createPaidOrder($popular, $popularPackage, [
            'order_id' => 'ORDER-POPULAR-RECENT',
            'quantity' => 2,
            'paid_at' => now()->subDays(2),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Aurora Best')
            ->assertSee('Best Seller')
            ->assertSee('Aurora Popular')
            ->assertSee('Popular');

        $this->get(route('products.show', $bestSeller))
            ->assertOk()
            ->assertDontSee('Before checkout')
            ->assertDontSee('Where will my license appear?')
            ->assertSee('Best Seller');

        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('FAQ')
            ->assertSee('Where will my license appear?')
            ->assertSee('What if the order is still pending?');
    }

    public function test_paid_customer_review_requires_admin_approval_before_public_display(): void
    {
        config(['admin.emails' => ['admin@example.com']]);

        [$product, $package] = $this->productWithPackage('Review Tool', 'review-tool', '7 Days');
        $buyer = User::factory()->create(['name' => 'Akbar Buyer']);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $order = $this->createPaidOrder($product, $package, [
            'order_id' => 'ORDER-REVIEW-PAID',
            'user_id' => $buyer->id,
        ]);

        $this->actingAs($buyer)
            ->post(route('reviews.store'), [
                'product_id' => $product->id,
                'order_id' => $order->order_id,
                'rating' => 5,
                'body' => 'gacor min',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $review = ProductReview::firstOrFail();
        $this->assertSame(ProductReview::STATUS_PENDING, $review->status);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('gacor min');

        $this->actingAs($admin)
            ->patch(route('admin.reviews.update', $review), [
                'status' => ProductReview::STATUS_APPROVED,
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $review->refresh();
        $this->assertSame(ProductReview::STATUS_APPROVED, $review->status);
        $this->assertNotNull($review->approved_at);

        $this->actingAs($buyer)
            ->post(route('reviews.store'), [
                'product_id' => $product->id,
                'order_id' => $order->order_id,
                'rating' => 1,
                'body' => 'This should not reset the approved review.',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $review->refresh();
        $this->assertSame(ProductReview::STATUS_APPROVED, $review->status);
        $this->assertSame(5, $review->rating);

        $this->actingAs(User::factory()->create(['name' => 'Viewer Person']))
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Approved buyer feedback')
            ->assertSee('gacor min')
            ->assertSee('A***r')
            ->assertDontSee('Akbar Buyer');
    }

    public function test_unpaid_or_other_customer_cannot_review_product(): void
    {
        [$product, $package] = $this->productWithPackage('Locked Review Tool', 'locked-review-tool', '1 Day');
        $buyer = User::factory()->create();
        $otherCustomer = User::factory()->create();
        $order = $this->createPaidOrder($product, $package, [
            'order_id' => 'ORDER-LOCKED-REVIEW',
            'user_id' => $buyer->id,
        ]);

        $this->actingAs($otherCustomer)
            ->post(route('reviews.store'), [
                'product_id' => $product->id,
                'order_id' => $order->order_id,
                'rating' => 5,
                'body' => 'Trying to review without owning this order.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('review');

        $this->assertDatabaseCount('product_reviews', 0);
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

    private function createPaidOrder(Product $product, Package $package, array $attributes = []): Order
    {
        $user = isset($attributes['user_id']) ? null : User::factory()->create();
        $quantity = (int) ($attributes['quantity'] ?? 1);

        $order = Order::create(array_merge([
            'order_id' => 'ORDER-MARKETING-'.uniqid(),
            'product_id' => $product->id,
            'package_id' => $package->id,
            'user_id' => $user?->id ?? $attributes['user_id'],
            'status' => 'paid',
            'payment_method' => 'pakasir',
            'price' => $package->price * $quantity,
            'quantity' => $quantity,
            'paid_at' => now()->subMinutes(10),
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
