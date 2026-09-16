<?php

namespace Tests\Feature;

use App\Models\DownloadItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\TestCase;

class AdminDownloadManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not available in this PHP environment.');
        }

        parent::setUp();
    }

    public function test_admin_can_manage_download_items(): void
    {
        config(['admin.emails' => ['admin@example.com']]);

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $response = $this->actingAs($admin)->post(route('admin.downloads.store'), [
            'name' => 'Test Tool',
            'links_text' => 'Download Files | https://example.com/setup.zip',
            'is_visible' => '0',
        ]);

        $response->assertRedirect(route('admin.downloads.index'));
        $download = DownloadItem::where('name', 'Test Tool')->firstOrFail();
        $this->assertSame('https://example.com/setup.zip', $download->links[0]['url']);
        $this->assertFalse($download->is_visible);

        $response = $this->actingAs($admin)->patch(route('admin.downloads.update', $download), [
            'name' => 'Updated Tool',
            'links_text' => "Main | https://example.com/main.zip\nMirror | https://example.com/mirror.zip",
            'is_visible' => '1',
        ]);

        $response->assertRedirect(route('admin.downloads.index'));
        $download->refresh();
        $this->assertSame('Updated Tool', $download->name);
        $this->assertCount(2, $download->links);
        $this->assertTrue($download->is_visible);

        $this->actingAs($admin)
            ->get(route('admin.downloads.index'))
            ->assertOk()
            ->assertSee('data-download-copy="'.$download->id.'"', false)
            ->assertSee('data-copy-value="https://example.com/main.zip', false)
            ->assertSee('https://example.com/mirror.zip', false);

        $this->actingAs($admin)
            ->delete(route('admin.downloads.destroy', $download))
            ->assertRedirect(route('admin.downloads.index'));

        $this->assertDatabaseMissing('download_items', ['id' => $download->id]);
    }

    public function test_inline_download_edit_preserves_filters_and_recovers_invalid_links(): void
    {
        config(['admin.emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $download = DownloadItem::create(['name' => 'Test tool', 'links' => []]);
        $query = ['search' => 'Test', 'page' => 1];
        $catalog = route('admin.downloads.index', $query);
        $update = route('admin.downloads.update', ['download' => $download, ...$query]);
        $this->actingAs($admin)->get($catalog)->assertOk()->assertSee('data-catalog-edit-button', false);
        $this->from($catalog)->patch($update, [
            'edit_download_id' => $download->id, 'name' => 'Test changed', 'links_text' => 'Invalid URL',
        ])->assertRedirect($catalog)->assertSessionHasErrors('links_text')->assertSessionHasInput('edit_download_id', $download->id);
        $this->get($catalog)->assertOk()->assertSee('Invalid URL')->assertSee('>Save</button>', false);
        $this->assertSame('Test tool', $download->fresh()->name);
        $this->patch($update, [
            'edit_download_id' => $download->id, 'name' => 'Test changed', 'links_text' => 'Setup | https://example.com/setup.zip',
        ])->assertRedirect($catalog)->assertSessionHasNoErrors();
        $this->assertSame('https://example.com/setup.zip', $download->fresh()->links[0]['url']);
        $this->delete(route('admin.downloads.destroy', ['download' => $download, ...$query]))->assertRedirect($catalog);
        $this->assertDatabaseMissing('download_items', ['id' => $download->id]);
    }

    public function test_public_downloads_use_database_items_alphabetically(): void
    {
        DownloadItem::create([
            'name' => 'Visible Tool',
            'links' => [['label' => 'Setup', 'url' => 'https://example.com/setup.zip']],
        ]);

        DownloadItem::create([
            'name' => 'Zeta Tool',
            'links' => [['label' => 'Setup', 'url' => 'https://example.com/zeta.zip']],
        ]);

        DownloadItem::create([
            'name' => 'Alpha Tool',
            'links' => [['label' => 'Setup', 'url' => 'https://example.com/alpha.zip']],
        ]);

        DownloadItem::create([
            'name' => 'Hidden Tool',
            'links' => [['label' => 'Setup', 'url' => 'https://example.com/hidden.zip']],
            'is_visible' => false,
        ]);

        $response = $this->get('/downloads');

        $response->assertOk();
        $response->assertSee('data-download-accordion', false);
        $response->assertSee('download-accordion-panel', false);
        $response->assertSee('data-download-resource', false);
        $response->assertSee('data-download-complete-label=', false);
        $response->assertSee('<h1 class="text-3xl font-semibold text-white md:text-4xl">Downloads</h1>', false);
        $response->assertSee('id="downloadSearch"', false);
        $response->assertSee('page-shell pb-16 pt-14 md:pb-20 md:pt-16', false);
        $response->assertDontSee('download-hero', false);
        $response->assertDontSee('All setup files in');
        $response->assertDontSee('Choose what you need');
        $response->assertDontSee('>Files</p>', false);
        $response->assertSeeInOrder(['Alpha Tool', 'Visible Tool', 'Zeta Tool']);
        $response->assertSee('Visible Tool');
        $response->assertDontSee('Hidden Tool');
        $response->assertDontSee('https://example.com/hidden.zip', false);
    }

    public function test_hidden_downloads_do_not_trigger_the_config_fallback(): void
    {
        DownloadItem::query()->delete();
        DownloadItem::create([
            'name' => 'Private Tool',
            'links' => [['label' => 'Setup', 'url' => 'https://example.com/private.zip']],
            'is_visible' => false,
        ]);

        $this->get('/downloads')
            ->assertOk()
            ->assertDontSee('Private Tool')
            ->assertSee('No public downloads have been configured yet.');
    }
}
