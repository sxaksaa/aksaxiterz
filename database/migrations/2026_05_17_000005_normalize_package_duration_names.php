<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('packages')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('packages')
                ->select(['id', 'product_id', 'name'])
                ->orderBy('product_id')
                ->orderBy('id')
                ->get()
                ->groupBy(fn ($package) => $package->product_id.'|'.$this->canonicalPackageName((string) $package->name))
                ->each(function ($packages): void {
                    $canonicalName = $this->canonicalPackageName((string) $packages->first()->name);
                    $primary = $this->primaryPackage($packages, $canonicalName);

                    DB::table('packages')
                        ->where('id', $primary->id)
                        ->update([
                            'name' => $canonicalName,
                            'updated_at' => now(),
                        ]);

                    $duplicateIds = $packages
                        ->pluck('id')
                        ->reject(fn ($id) => (int) $id === (int) $primary->id)
                        ->values();

                    if ($duplicateIds->isEmpty()) {
                        return;
                    }

                    if (Schema::hasTable('license_stocks')) {
                        DB::table('license_stocks')
                            ->whereIn('package_id', $duplicateIds)
                            ->update([
                                'package_id' => $primary->id,
                                'product_id' => $primary->product_id,
                                'updated_at' => now(),
                            ]);
                    }

                    if (Schema::hasTable('orders')) {
                        DB::table('orders')
                            ->whereIn('package_id', $duplicateIds)
                            ->update([
                                'package_id' => $primary->id,
                                'updated_at' => now(),
                            ]);
                    }

                    DB::table('packages')
                        ->whereIn('id', $duplicateIds)
                        ->delete();
                });
        });
    }

    public function down(): void
    {
        // Data normalization cannot safely restore previous typo variants.
    }

    private function primaryPackage($packages, string $canonicalName)
    {
        return $packages
            ->sortBy(fn ($package) => (Str::lower((string) $package->name) === Str::lower($canonicalName) ? 0 : 100000000) + (int) $package->id)
            ->first();
    }

    private function canonicalPackageName(string $name): string
    {
        $normalized = Str::lower(trim($name));
        $compact = preg_replace('/[\s_\-]+/', '', $normalized) ?: $normalized;

        $aliases = [
            '1day' => '1 Day',
            '1days' => '1 Day',
            '1hari' => '1 Day',
            '3day' => '3 Days',
            '3days' => '3 Days',
            '3hari' => '3 Days',
            '7day' => '7 Days',
            '7days' => '7 Days',
            '7hari' => '7 Days',
            '10day' => '10 Days',
            '10days' => '10 Days',
            '10hari' => '10 Days',
            '15day' => '15 Days',
            '15days' => '15 Days',
            '15hari' => '15 Days',
            '30day' => '30 Days',
            '30days' => '30 Days',
            '30hari' => '30 Days',
            '1month' => '30 Days',
            '1months' => '30 Days',
            'onemonth' => '30 Days',
            '1year' => '1 Year',
            '1years' => '1 Year',
            '1tahun' => '1 Year',
        ];

        return $aliases[$compact] ?? (preg_replace('/\s+/', ' ', trim($name)) ?: $name);
    }
};
