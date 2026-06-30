<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LicenseStock;
use App\Models\Package;
use App\Models\Product;
use App\Services\BrModsResetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LicenseStockController extends Controller
{
    public function index(Request $request)
    {
        $stocks = LicenseStock::with(['product', 'package', 'soldLicense.user', 'reservedOrder.user'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $query->where('license_key', 'like', '%'.$request->search.'%');
            })
            ->when($request->filled('product_id'), function ($query) use ($request) {
                $query->where('product_id', $request->integer('product_id'));
            })
            ->when($request->filled('package_id'), function ($query) use ($request) {
                $query->where('package_id', $request->integer('package_id'));
            })
            ->when($request->status === 'available', fn ($query) => $query->available())
            ->when($request->status === 'reserved', fn ($query) => $query->reserved())
            ->when($request->status === 'sold', fn ($query) => $query->where('is_sold', true))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $products = Product::orderBy('name')->get();
        $addableProducts = Product::visible()->orderBy('name')->get();
        $packages = Package::with('product')
            ->orderBy('product_id')
            ->orderBy('price')
            ->get();
        $addablePackages = Package::with('product')
            ->whereHas('product', fn ($query) => $query->visible())
            ->orderBy('product_id')
            ->orderBy('price')
            ->get();
        $editStock = $this->editableStock($request);

        $stats = [
            'total' => LicenseStock::count(),
            'available' => LicenseStock::available()->count(),
            'reserved' => LicenseStock::reserved()->count(),
            'sold' => LicenseStock::where('is_sold', true)->count(),
        ];

        return view('admin.license-stocks.index', compact(
            'stocks',
            'products',
            'addableProducts',
            'packages',
            'addablePackages',
            'editStock',
            'stats'
        ));
    }

    public function store(Request $request, BrModsResetService $brModsResetService)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'license_keys' => ['required', 'string', 'max:20000'],
        ]);

        $package = $this->packageForProduct($validated['package_id'], $validated['product_id']);

        if (! $package) {
            return back()
                ->withInput()
                ->withErrors(['package_id' => 'Select a package from the chosen product.']);
        }

        if (! $package->product?->is_visible) {
            return back()
                ->withInput()
                ->withErrors(['product_id' => 'Hidden products cannot receive new stock. Make the product public first.']);
        }

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

        if ($brModsResetService->supportsProduct($package->product)) {
            $invalidKeys = $keys->filter(
                fn (string $key): bool => $brModsResetService->extractUsername($key) === null
            );

            if ($invalidKeys->isNotEmpty()) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'license_keys' => 'BR Mods licenses must use the format 👤username🔑key. Invalid entries: '.$invalidKeys->take(3)->implode(', '),
                    ]);
            }
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

    public function update(
        Request $request,
        LicenseStock $licenseStock,
        BrModsResetService $brModsResetService,
    ) {
        if ($licenseStock->is_sold || $licenseStock->isReserved()) {
            return back()->withErrors(['license_key' => 'Sold or reserved license keys cannot be edited.']);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'license_key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('license_stocks', 'license_key')->ignore($licenseStock->id),
            ],
        ]);

        $package = $this->packageForProduct($validated['package_id'], $validated['product_id']);

        if (! $package) {
            return back()
                ->withInput()
                ->withErrors(['package_id' => 'Select a package from the chosen product.']);
        }

        $licenseKey = trim($validated['license_key']);

        if (
            $brModsResetService->supportsProduct($package->product) &&
            $brModsResetService->extractUsername($licenseKey) === null
        ) {
            return back()
                ->withInput()
                ->withErrors([
                    'license_key' => 'BR Mods licenses must use the format 👤username🔑key.',
                ]);
        }

        $licenseStock->update([
            'license_key' => $licenseKey,
            'product_id' => $package->product_id,
            'package_id' => $package->id,
        ]);

        return redirect()
            ->route('admin.license-stocks.index')
            ->with('info', 'License stock updated.');
    }

    public function destroy(LicenseStock $licenseStock)
    {
        if ($licenseStock->is_sold || $licenseStock->isReserved()) {
            return back()->withErrors(['license_key' => 'Sold or reserved license keys cannot be deleted.']);
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
            ->available()
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

    private function packageForProduct(int $packageId, int $productId): ?Package
    {
        return Package::with('product')
            ->whereKey($packageId)
            ->where('product_id', $productId)
            ->first();
    }
}
