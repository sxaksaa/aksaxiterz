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
        ]);

        $response->assertRedirect(route('admin.downloads.index'));
        $download = DownloadItem::where('name', 'Test Tool')->firstOrFail();
        $this->assertSame('https://example.com/setup.zip', $download->links[0]['url']);

        $response = $this->actingAs($admin)->patch(route('admin.downloads.update', $download), [
            'name' => 'Updated Tool',
            'links_text' => "Main | https://example.com/main.zip\nMirror | https://example.com/mirror.zip",
        ]);

        $response->assertRedirect(route('admin.downloads.index'));
        $download->refresh();
        $this->assertSame('Updated Tool', $download->name);
        $this->assertCount(2, $download->links);

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

        $response = $this->get('/downloads');

        $response->assertOk();
        $response->assertSee('data-download-accordion', false);
        $response->assertSee('download-accordion-panel', false);
        $response->assertSeeInOrder(['Alpha Tool', 'Visible Tool', 'Zeta Tool']);
        $response->assertSee('Visible Tool');
    }
}
