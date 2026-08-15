<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoAndOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_expose_search_and_social_metadata(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<meta name="description"', false)
            ->assertSee('<link rel="canonical"', false)
            ->assertSee('<meta property="og:title"', false)
            ->assertSee('<meta name="twitter:card"', false)
            ->assertSee('application/ld+json', false);
    }

    public function test_storefront_controls_have_accessible_names_and_states(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-aksa-intro-page="true"', false)
            ->assertSee('data-site-intro-prepaint', false)
            ->assertSee('id="aksaSiteIntro"', false)
            ->assertSee('data-site-intro-lockup', false)
            ->assertSee('data-site-brand-logo', false)
            ->assertSee('aria-label="Main navigation"', false)
            ->assertSee('<label for="searchInput" class="sr-only">Search products</label>', false)
            ->assertSee('role="group" aria-label="Product categories"', false)
            ->assertSee('data-category-filter-row', false)
            ->assertSee('data-category-filter-glider', false)
            ->assertSee('data-category-filter data-category="" aria-pressed="true"', false)
            ->assertSee('loading="lazy" decoding="async"', false)
            ->assertDontSee('<a href="#" data-category-filter', false);
    }

    public function test_branded_intro_is_scoped_to_the_homepage(): void
    {
        $this->get('/guides')
            ->assertOk()
            ->assertSee('<h1 class="text-3xl font-semibold text-white md:text-4xl">Guides</h1>', false)
            ->assertSee('page-shell pb-16 pt-14 md:pb-20 md:pt-16', false)
            ->assertDontSee('download-hero', false)
            ->assertDontSee('Practical guides for')
            ->assertDontSee('Knowledge Base')
            ->assertDontSee('Choose a guide')
            ->assertDontSee('data-aksa-intro-page="true"', false)
            ->assertDontSee('id="aksaSiteIntro"', false);
    }

    public function test_private_pages_are_not_indexed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/cart')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_public_errors_are_branded_and_offer_a_safe_way_back(): void
    {
        $this->get('/page-that-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found')
            ->assertSee('Back to Products')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);

        foreach ([500, 503] as $status) {
            $html = view("errors.{$status}")->render();

            $this->assertStringContainsString('Aksa Xiterz', $html);
            $this->assertStringContainsString('Back to Products', $html);
            $this->assertStringContainsString('noindex, nofollow', $html);
        }
    }

    public function test_sitemap_contains_public_pages_and_visible_products_only(): void
    {
        $category = Category::firstOrCreate(['slug' => 'pc'], ['name' => 'PC']);
        $visible = Product::create([
            'category_id' => $category->id,
            'name' => 'Visible Product',
            'slug' => 'visible-product',
            'status' => Product::STATUS_READY,
            'is_visible' => true,
            'description' => 'Visible description.',
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => 'Hidden Product',
            'slug' => 'hidden-product',
            'status' => Product::STATUS_READY,
            'is_visible' => false,
            'description' => 'Hidden description.',
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(route('products.show', $visible->slug), false)
            ->assertDontSee('hidden-product');
    }
}
