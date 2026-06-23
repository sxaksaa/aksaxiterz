<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class AdminCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
    }

    public function test_admin_can_create_update_and_delete_category(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.categories.store'), [
            'name' => 'Windows Tools',
            'slug' => '',
        ]);

        $response->assertRedirect(route('admin.categories.index'));
        $category = Category::where('name', 'Windows Tools')->firstOrFail();
        $this->assertSame('windows-tools', $category->slug);

        $response = $this->actingAs($admin)->patch(route('admin.categories.update', $category), [
            'name' => 'PC Tools',
            'slug' => 'pc-tools',
        ]);

        $response->assertRedirect(route('admin.categories.index'));
        $category->refresh();
        $this->assertSame('PC Tools', $category->name);
        $this->assertSame('pc-tools', $category->slug);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_admin_cannot_delete_category_that_has_products(): void
    {
        $admin = $this->adminUser();
        $category = Category::firstOrCreate(
            ['slug' => 'android'],
            ['name' => 'Android']
        );

        Product::create([
            'category_id' => $category->id,
            'name' => 'Android Tool',
            'slug' => 'android-tool',
            'status' => Product::STATUS_READY,
            'description' => 'Android product.',
        ]);

        $response = $this->actingAs($admin)->delete(route('admin.categories.destroy', $category));

        $response->assertSessionHasErrors('category');
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_admin_category_index_renders_platform_icon_labels(): void
    {
        $admin = $this->adminUser();
        Category::firstOrCreate(['slug' => 'pc'], ['name' => 'PC']);
        Category::firstOrCreate(['slug' => 'ios'], ['name' => 'iOS']);
        Category::firstOrCreate(['slug' => 'android'], ['name' => 'Android']);

        $this->actingAs($admin)
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee('Categories')
            ->assertSee('PC')
            ->assertSee('iOS')
            ->assertSee('Android');
    }

    public function test_public_home_shows_custom_categories_that_have_products(): void
    {
        $category = Category::create([
            'name' => 'Console',
            'slug' => 'console',
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Console Tool',
            'slug' => 'console-tool',
            'status' => Product::STATUS_READY,
            'description' => 'Console product.',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Console')
            ->assertSee('Console Tool');
    }

    private function adminUser(): User
    {
        config(['admin.emails' => ['admin@example.com']]);

        return User::factory()->create([
            'email' => 'admin@example.com',
        ]);
    }
}
