<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ProductReview;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductReviewController extends Controller
{
    public function store(Request $request)
    {
        $request->merge([
            'body' => trim((string) $request->input('body')),
        ]);

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'order_id' => ['required', 'string', 'max:120'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'max:420'],
        ]);

        $order = Order::query()
            ->with('items')
            ->where('order_id', $validated['order_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'paid')
            ->first();

        if (! $order || ! $this->orderContainsProduct($order, (int) $validated['product_id'])) {
            throw ValidationException::withMessages([
                'review' => 'Only paid customers can review this product.',
            ]);
        }

        $identity = [
            'user_id' => $request->user()->id,
            'product_id' => (int) $validated['product_id'],
            'order_id' => $order->id,
        ];

        $existingReview = ProductReview::where($identity)->first();

        if ($existingReview && $existingReview->status !== ProductReview::STATUS_REJECTED) {
            $message = $existingReview->status === ProductReview::STATUS_APPROVED
                ? 'Review already approved and visible on the product page.'
                : 'Review already submitted. It will appear after admin approval.';

            return back()->with('info', $message);
        }

        $review = ProductReview::updateOrCreate($identity, [
            'rating' => (int) $validated['rating'],
            'body' => $validated['body'],
            'status' => ProductReview::STATUS_PENDING,
            'approved_at' => null,
        ]);

        return back()->with(
            'info',
            $review->wasRecentlyCreated
                ? 'Review submitted. It will appear after admin approval.'
                : 'Review updated and sent back for approval.'
        );
    }

    private function orderContainsProduct(Order $order, int $productId): bool
    {
        if ((int) $order->product_id === $productId) {
            return true;
        }

        return $order->lineItems()
            ->contains(fn ($item) => (int) $item->product_id === $productId);
    }
}
