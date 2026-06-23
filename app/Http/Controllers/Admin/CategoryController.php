<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::query()
            ->withCount('products')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        $editCategory = $request->filled('edit')
            ? Category::withCount('products')->find($request->integer('edit'))
            : null;

        $stats = [
            'categories' => Category::count(),
            'used_categories' => Category::has('products')->count(),
            'products' => Product::count(),
        ];

        return view('admin.categories.index', compact('categories', 'editCategory', 'stats'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateCategory($request);

        Category::create($validated);

        return redirect()
            ->route('admin.categories.index')
            ->with('info', 'Category created.');
    }

    public function update(Request $request, Category $category)
    {
        $validated = $this->validateCategory($request, $category);

        $category->update($validated);

        return redirect()
            ->route('admin.categories.index')
            ->with('info', 'Category updated.');
    }

    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return back()->withErrors([
                'category' => 'Categories with products cannot be deleted. Move the products first.',
            ]);
        }

        $category->delete();

        return redirect()
            ->route('admin.categories.index')
            ->with('info', 'Category deleted.');
    }

    private function validateCategory(Request $request, ?Category $category = null): array
    {
        $request->merge([
            'slug' => $this->normalizeSlug(
                (string) ($request->input('slug') ?: $request->input('name'))
            ),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('categories', 'name')->ignore($category?->id)],
            'slug' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')->ignore($category?->id),
            ],
        ]);
    }

    private function normalizeSlug(string $value): string
    {
        return Str::slug($value) ?: 'category';
    }
}
