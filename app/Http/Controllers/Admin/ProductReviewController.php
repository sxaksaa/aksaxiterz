<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductReviewController extends Controller
{
    public function index(Request $request)
    {
        $reviews = ProductReview::with(['user', 'product', 'order'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->search);

                $query->where(function ($query) use ($search) {
                    $query->where('body', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn ($query) => $query
                            ->where('name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%'))
                        ->orWhereHas('product', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $stats = [
            'total' => ProductReview::count(),
            'pending' => ProductReview::where('status', ProductReview::STATUS_PENDING)->count(),
            'approved' => ProductReview::where('status', ProductReview::STATUS_APPROVED)->count(),
            'rejected' => ProductReview::where('status', ProductReview::STATUS_REJECTED)->count(),
        ];

        return view('admin.reviews.index', [
            'reviews' => $reviews,
            'stats' => $stats,
            'statusOptions' => ProductReview::statusOptions(),
        ]);
    }

    public function update(Request $request, ProductReview $productReview)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(ProductReview::statusOptions()))],
        ]);

        $productReview->update([
            'status' => $validated['status'],
            'approved_at' => $validated['status'] === ProductReview::STATUS_APPROVED ? now() : null,
        ]);

        return back()->with('info', 'Review status updated.');
    }
}
