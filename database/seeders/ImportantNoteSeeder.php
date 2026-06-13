<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ImportantNoteSeeder extends Seeder
{
    public function run(): void
    {
        // Aurora
        $aurora = Product::where('name', 'Aurora-VN')->firstOrFail();

        $this->syncImportantNote($aurora, [
            'Duration-based license access',
            'Setup guide included',
            'Customer support available',
        ]);

        // XG-Team
        $xg = Product::where('name', 'XG-Team')->firstOrFail();

        $this->syncImportantNote($xg, [
            'Desktop access utility',
            'Setup tutorial included',
            'Customer support available',
        ]);

        $this->syncImportantNote(Product::where('name', 'Drip Client Root')->firstOrFail(), [
            'Android root client access',
            'Duration-based license delivery',
            'Setup support available',
        ]);

        $this->syncImportantNote(Product::where('name', 'Drip Client Non Root')->firstOrFail(), [
            'Android non-root client access',
            'Duration-based license delivery',
            'Setup support available',
        ]);

        $this->syncImportantNote(Product::where('name', 'Fluorite FF')->firstOrFail(), [
            'iOS Fluorite FF access',
            'Duration-based license delivery',
            'Setup support available',
        ]);

        $this->syncImportantNote(Product::where('name', 'Fluorite ML')->firstOrFail(), [
            'iOS Fluorite ML access',
            'Duration-based license delivery',
            'Setup support available',
        ]);
    }

    private function syncImportantNote(Product $product, array $notes): void
    {
        $product->update([
            'important_note' => collect($notes)
                ->map(fn ($note) => rtrim(trim($note), '.!?').'.')
                ->implode(' '),
        ]);
    }
}
