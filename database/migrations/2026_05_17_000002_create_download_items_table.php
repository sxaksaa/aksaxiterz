<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('links')->nullable();
            $table->timestamps();
        });

        $now = now();
        $downloads = collect(config('links.downloads', []))
            ->filter(fn ($download) => filled($download['name'] ?? null))
            ->values()
            ->map(fn ($download) => [
                'name' => (string) ($download['name'] ?? ''),
                'links' => json_encode(array_values($download['links'] ?? [])),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($downloads) {
            DB::table('download_items')->insert($downloads);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('download_items');
    }
};
