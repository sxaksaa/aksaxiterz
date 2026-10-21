<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_schema_is_json_ld_without_compiler_output_or_script_breakouts(): void
    {
        $description = '</script><script>alert("test")</script> & @context';
        config(['seo.description' => $description, 'security.content_security_policy' => true]);
        $product = $this->product('Schema');

        foreach (['/', '/downloads', route('products.show', $product)] as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertSame(1, preg_match(
                '/<script type="application\/ld\+json" nonce="([^"]+)">(.*?)<\/script>/s',
                $response->getContent(), $matches
            ));
            $schema = json_decode($matches[2], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('https://schema.org', $schema['@context'] ?? null);
            $this->assertSame('WebSite', $schema['@type']);
            $this->assertSame($description, $schema['description']);
            $this->assertStringNotContainsString('<?php', $matches[2]);
            $this->assertStringNotContainsString('<script', $matches[2]);
            $this->assertStringContainsString("'nonce-{$matches[1]}'", $response->headers->get('Content-Security-Policy'));
        }
    }

    public function test_missing_and_foreign_orders_return_the_same_generic_json_404(): void
    {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $product = $this->product('Order');
        $package = Package::create(['product_id' => $product->id, 'name' => 'Day', 'price' => 20000]);
        $order = Order::create([
            'order_id' => 'ORDER-SECURITY-FOREIGN', 'user_id' => $owner->id,
            'product_id' => $product->id, 'package_id' => $package->id,
            'price' => 20000, 'status' => 'pending', 'payment_method' => 'gopay_qris',
            'expired_at' => now()->addHour(),
        ]);
        config(['app.debug' => false]);

        foreach ([$order->id, $order->id + 1000] as $id) {
            $this->actingAs($user)->postJson('/cancel-order/'.$id)
                ->assertNotFound()->assertExactJson(['message' => 'The requested resource was not found.']);
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->get('/missing-security-page')->assertNotFound()->assertSee('Page not found');
    }

    public function test_mismatched_packages_return_generic_json_404_without_creating_cart_items(): void
    {
        $product = $this->product('First');
        $other = $this->product('Second');
        $package = Package::create(['product_id' => $other->id, 'name' => 'Day', 'price' => 20000]);
        $this->actingAs(User::factory()->create())
            ->postJson(route('cart.items.store', $product), ['package_id' => $package->id, 'quantity' => 1])
            ->assertNotFound()->assertExactJson(['message' => 'The requested resource was not found.']);
        $this->assertDatabaseCount('cart_items', 0);
    }

    private function product(string $name): Product
    {
        $category = Category::firstOrCreate(['slug' => 'security-test'], ['name' => 'Security Test']);

        return Product::create([
            'category_id' => $category->id, 'name' => $name, 'slug' => strtolower($name),
            'description' => 'Security regression fixture.', 'status' => Product::STATUS_READY, 'is_visible' => true,
        ]);
    }
}
