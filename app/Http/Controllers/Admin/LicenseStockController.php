<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LicenseStock;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LicenseStockController extends Controller
{
    public function index(Request $request)
    {
        $stocks = LicenseStock::with(['product', 'package', 'soldLicense.user'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('license_key', 'like', '%'.$request->search.'%');
            })
            ->when($request->filled('product_id'), function ($query) use ($request) {
                $query->where('product_id', $request->integer('product_id'));
            })
            ->when($request->filled('package_id'), function ($query) use ($request) {
                $query->where('package_id', $request->integer('package_id'));
            })
            ->when($request->status === 'available', fn ($query) => $query->where('is_sold', false))
            ->when($request->status === 'sold', fn ($query) => $query->where('is_sold', true))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $products = Product::orderBy('name')->get();
        $packages = Package::with('product')
            ->withCount('availableLicenseStocks')
            ->orderBy('product_id')
            ->orderBy('price')
            ->get();
        $editStock = $this->editableStock($request);
        $lowStockThreshold = (int) config('admin.low_stock_threshold', 3);
        $lowStockPackages = $packages
            ->filter(fn ($package) => (int) $package->available_license_stocks_count <= $lowStockThreshold)
            ->values();

        $stats = [
            'total' => LicenseStock::count(),
            'available' => LicenseStock::where('is_sold', false)->count(),
            'sold' => LicenseStock::where('is_sold', true)->count(),
            'low_stock' => $lowStockPackages->count(),
        ];

        return view('admin.license-stocks.index', compact(
            'stocks',
            'products',
            'packages',
            'editStock',
            'stats',
            'lowStockPackages',
            'lowStockThreshold'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'license_keys' => ['required', 'string', 'max:20000'],
        ]);

        $package = Package::findOrFail($validated['package_id']);
        $keys = $this->licenseKeys($validated['license_keys']);

        if ($keys->isEmpty()) {
            return back()
                ->withInput()
                ->withErrors(['license_keys' => 'Add at least one license key.']);
        }

        if ($keys->count() > 500) {
            return back()
                ->withInput()
                ->withErrors(['license_keys' => 'Import up to 500 license keys at a time.']);
        }

        $existingKeys = LicenseStock::whereIn('license_key', $keys)->pluck('license_key');

        if ($existingKeys->isNotEmpty()) {
            return back()
                ->withInput()
                ->withErrors([
                    'license_keys' => 'Duplicate keys already exist: '.$existingKeys->take(5)->implode(', '),
                ]);
        }

        DB::transaction(function () use ($keys, $package): void {
            foreach ($keys as $key) {
                LicenseStock::create([
                    'license_key' => $key,
                    'product_id' => $package->product_id,
                    'package_id' => $package->id,
                    'is_sold' => false,
                ]);
            }
        });

        return back()->with('info', $keys->count().' license keys added.');
    }

    public function update(Request $request, LicenseStock $licenseStock)
    {
        if ($licenseStock->is_sold) {
            return back()->withErrors(['license_key' => 'Sold license keys cannot be edited.']);
        }

        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'license_key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('license_stocks', 'license_key')->ignore($licenseStock->id),
            ],
        ]);

        $package = Package::findOrFail($validated['package_id']);

        $licenseStock->update([
            'license_key' => trim($validated['license_key']),
            'product_id' => $package->product_id,
            'package_id' => $package->id,
        ]);

        return redirect()
            ->route('admin.license-stocks.index')
            ->with('info', 'License stock updated.');
    }

    public function destroy(LicenseStock $licenseStock)
    {
        if ($licenseStock->is_sold) {
            return back()->withErrors(['license_key' => 'Sold license keys cannot be deleted.']);
        }

        $licenseStock->delete();

        return back()->with('info', 'License stock deleted.');
    }

    private function editableStock(Request $request): ?LicenseStock
    {
        if (! $request->filled('edit')) {
            return null;
        }

        return LicenseStock::with(['product', 'package'])
            ->where('is_sold', false)
            ->find($request->integer('edit'));
    }

    private function licenseKeys(string $value)
    {
        return collect(preg_split('/[\r\n,;]+/', $value))
            ->map(fn ($key) => trim((string) $key))
            ->filter()
            ->unique()
            ->values();
    }
}
