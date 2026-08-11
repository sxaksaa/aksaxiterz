<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $period = in_array($request->input('period'), ['today', '7', '30', 'all'], true)
            ? $request->input('period')
            : 'all';
        $search = trim($request->string('search')->toString());
        $legacyProductIds = $search !== ''
            ? Product::query()->where('name', 'like', '%'.$search.'%')->limit(100)->pluck('id')
            : collect();
        $legacyPackageIds = $search !== ''
            ? Package::query()->where('name', 'like', '%'.$search.'%')->limit(100)->pluck('id')
            : collect();

        $logs = AdminActivityLog::query()
            ->when($search !== '', function ($query) use ($search, $legacyProductIds, $legacyPackageIds) {
                $query->where(function ($query) use ($search, $legacyProductIds, $legacyPackageIds) {
                    $query->where('admin_name', 'like', '%'.$search.'%')
                        ->orWhere('admin_email', 'like', '%'.$search.'%')
                        ->orWhere('subject_label', 'like', '%'.$search.'%')
                        ->orWhere('details', 'like', '%'.$search.'%')
                        ->orWhere('action', 'like', '%'.$search.'%');

                    foreach ($legacyProductIds as $productId) {
                        $query->orWhere('details', 'like', '%Product #'.$productId.' ·%');
                    }

                    foreach ($legacyPackageIds as $packageId) {
                        $query->orWhere('details', 'like', '%Package #'.$packageId);
                    }
                });
            })
            ->when(array_key_exists((string) $request->input('section'), AdminActivityLog::sectionOptions()), function ($query) use ($request) {
                $query->where('section', $request->input('section'));
            })
            ->when($period === 'today', fn ($query) => $query->where('created_at', '>=', now()->startOfDay()))
            ->when($period === '7', fn ($query) => $query->where('created_at', '>=', now()->subDays(7)))
            ->when($period === '30', fn ($query) => $query->where('created_at', '>=', now()->subDays(30)))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $this->hydrateReadableLicenseStockDetails($logs->getCollection());

        $stats = [
            'total' => AdminActivityLog::count(),
            'today' => AdminActivityLog::where('created_at', '>=', now()->startOfDay())->count(),
            'seven_days' => AdminActivityLog::where('created_at', '>=', now()->subDays(7))->count(),
            'admins' => AdminActivityLog::distinct('admin_email')->count('admin_email'),
        ];

        $sectionOptions = AdminActivityLog::sectionOptions();

        return view('admin.activity-logs.index', compact('logs', 'stats', 'sectionOptions', 'period'));
    }

    private function hydrateReadableLicenseStockDetails($logs): void
    {
        $productIds = [];
        $packageIds = [];

        foreach ($logs as $log) {
            preg_match_all('/Product #(\d+)/', (string) $log->details, $productMatches);
            preg_match_all('/Package #(\d+)/', (string) $log->details, $packageMatches);
            $productIds = [...$productIds, ...($productMatches[1] ?? [])];
            $packageIds = [...$packageIds, ...($packageMatches[1] ?? [])];
        }

        $products = Product::query()->whereKey(array_unique($productIds))->pluck('name', 'id');
        $packages = Package::query()->whereKey(array_unique($packageIds))->pluck('name', 'id');

        foreach ($logs as $log) {
            $details = (string) $log->details;
            $details = preg_replace_callback(
                '/Product #(\d+)/',
                fn ($match) => $products->get((int) $match[1], $match[0]),
                $details
            );
            $details = preg_replace_callback(
                '/Package #(\d+)/',
                fn ($match) => $packages->get((int) $match[1], $match[0]),
                $details
            );
            $log->setAttribute('display_details', $details);
        }
    }
}
