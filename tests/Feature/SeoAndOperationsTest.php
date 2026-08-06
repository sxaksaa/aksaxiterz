<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    public function test_private_pages_are_not_indexed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/cart')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
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

    public function test_sqlite_backup_command_creates_a_copy(): void
    {
        $source = storage_path('framework/testing/backup-source.sqlite');
        $destination = storage_path('framework/testing/backups');
        File::ensureDirectoryExists(dirname($source));
        File::put($source, 'sqlite-backup-test');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $source,
            'backup.path' => $destination,
        ]);

        $this->artisan('ops:backup-database')->assertSuccessful();

        $backups = File::glob($destination.'/database-*.sqlite');
        $this->assertCount(1, $backups);
        $this->assertSame('sqlite-backup-test', File::get($backups[0]));

        File::delete($source);
        File::deleteDirectory($destination);
    }
}
