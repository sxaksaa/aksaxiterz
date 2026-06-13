<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private const PACKAGE_NAME_OPTIONS = [
        '1 Day',
        '3 Days',
        '7 Days',
        '10 Days',
        '15 Days',
        '30 Days',
        '1 Year',
    ];

    public function index(Request $request)
    {
        $products = Product::with([
            'category',
            'packages' => fn ($query) => $query
                ->withCount('availableLicenseStocks')
                ->orderBy('price')
                ->orderBy('name'),
        ])
            ->withCount(['packages', 'licenseStocks', 'availableLicenseStocks'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('category_id', $request->integer('category_id'));
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        $stats = [
            'products' => Product::count(),
            'packages' => Package::count(),
            'ready_products' => Product::where('status', Product::STATUS_READY)->count(),
            'updating_products' => Product::where('status', Product::STATUS_UPDATING)->count(),
        ];
        $statusOptions = Product::statusOptions();

        return view('admin.products.index', compact('products', 'categories', 'stats', 'statusOptions'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);
        $slug = $this->uniqueSlug(null, $validated['name']);

        $product = Product::create([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'status' => $validated['status'] ?? Product::STATUS_READY,
            'description' => $validated['description'],
        ]);

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('info', 'Product created. Add package prices before selling it.');
    }

    public function edit(Product $product)
    {
        $product->load([
            'category',
            'packages' => fn ($query) => $query
                ->withCount(['availableLicenseStocks', 'licenseStocks', 'orders'])
                ->orderBy('price')
                ->orderBy('name'),
        ])->loadCount(['packages', 'licenseStocks', 'availableLicenseStocks']);

        $categories = Category::orderBy('name')->get();
        $orderCount = Order::where('product_id', $product->id)->count();
        $statusOptions = Product::statusOptions();
        $packageNameOptions = $this->packageNameOptions($product);

        return view('admin.products.edit', compact('product', 'categories', 'orderCount', 'statusOptions', 'packageNameOptions'));
    }

    public function update(Request $request, Product $product)
    {
        $validated = $this->validateProduct($request, $product);
        $slug = $this->uniqueSlug(null, $validated['name'], $product);

        $product->update([
            'category_id' => $validated['category_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'status' => $validated['status'],
            'description' => $validated['description'],
        ]);

        return redirect()
            ->route('admin.products.edit', $product->fresh())
            ->with('info', 'Product details updated.');
    }

    public function destroy(Product $product)
    {
        if (Order::where('product_id', $product->id)->exists()) {
            return back()->withErrors(['product' => 'Products with orders cannot be deleted. Rename or edit it instead.']);
        }

        if ($product->licenseStocks()->exists()) {
            return back()->withErrors(['product' => 'Products with license stock cannot be deleted. Delete unused stock first.']);
        }

        $product->delete();

        return redirect()
            ->route('admin.products.index')
            ->with('info', 'Product deleted.');
    }

    public function updateImportantNote(Request $request, Product $product)
    {
        $validated = $request->validate([
            'important_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $product->update([
            'important_note' => filled($validated['important_note'] ?? null)
                ? trim($validated['important_note'])
                : null,
        ]);

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('info', $product->important_note ? 'Important note updated.' : 'Important note removed.');
    }

    public function storePackage(Request $request, Product $product)
    {
        $validated = $this->validatePackage($request, $product);

        $product->packages()->create($validated);

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('info', 'Package price added.');
    }

    public function updatePackage(Request $request, Package $package)
    {
        $validated = $this->validatePackage($request, $package->product, $package);

        $package->update($validated);

        return redirect()
            ->route('admin.products.edit', $package->product)
            ->with('info', 'Package price updated.');
    }

    public function destroyPackage(Package $package)
    {
        if ($package->orders()->exists()) {
            return back()->withErrors(['package' => 'Packages with orders cannot be deleted. Edit the price/name instead.']);
        }

        if ($package->licenseStocks()->exists()) {
            return back()->withErrors(['package' => 'Packages with license stock cannot be deleted. Delete unused stock first.']);
        }

        $product = $package->product;
        $package->delete();

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('info', 'Package deleted.');
    }

    private function validateProduct(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('products', 'name')->ignore($product?->id),
            ],
            'description' => ['required', 'string', 'max:1000'],
            'status' => ['required', 'string', Rule::in(array_keys(Product::statusOptions()))],
        ]);
    }

    private function validatePackage(Request $request, Product $product, ?Package $package = null): array
    {
        if ($request->filled('package_name')) {
            $request->merge([
                'package_name' => $this->canonicalPackageName((string) $request->input('package_name')),
            ]);
        }

        $validated = $request->validate([
            'package_name' => [
                'required',
                'string',
                'max:80',
                Rule::in($this->packageNameOptions($product, $package?->name)),
                Rule::unique('packages', 'name')
                    ->where('product_id', $product->id)
                    ->ignore($package?->id),
            ],
            'package_price' => ['required', 'integer', 'min:0', 'max:999999999'],
            'package_price_usdt' => ['nullable', 'numeric', 'min:0', 'max:999999.9999'],
        ]);

        return [
            'name' => $validated['package_name'],
            'price' => $validated['package_price'],
            'price_usdt' => $validated['package_price_usdt'] ?? null,
        ];
    }

    private function packageNameOptions(?Product $product = null, ?string $currentName = null): array
    {
        $names = collect(self::PACKAGE_NAME_OPTIONS);

        if ($product) {
            $names = $names->merge($product->packages()->pluck('name')->map(fn ($name) => $this->canonicalPackageName((string) $name)));
        }

        if ($currentName) {
            $names = $names->push($this->canonicalPackageName($currentName));
        }

        return $names
            ->filter()
            ->unique()
            ->sortBy(fn ($name) => $this->packageNameSortWeight($name))
            ->values()
            ->all();
    }

    private function packageNameSortWeight(string $name): int
    {
        if (preg_match('/(\d+)\s*year/i', $name, $matches)) {
            return ((int) $matches[1]) * 365;
        }

        if (preg_match('/(\d+)\s*day/i', $name, $matches)) {
            return (int) $matches[1];
        }

        return 9999;
    }

    private function canonicalPackageName(string $name): string
    {
        $normalized = Str::lower(trim($name));
        $compact = preg_replace('/[\s_\-]+/', '', $normalized) ?: $normalized;

        return [
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
        ][$compact] ?? (preg_replace('/\s+/', ' ', trim($name)) ?: $name);
    }

    private function uniqueSlug(?string $value, string $fallback, ?Product $product = null): string
    {
        $baseSlug = Str::slug($value ?: $fallback) ?: 'product';
        $slug = $baseSlug;
        $suffix = 2;

        while (
            Product::where('slug', $slug)
                ->when($product, fn ($query) => $query->whereKeyNot($product->id))
                ->exists()
        ) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
