<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

use App\Models\Order;
use App\Services\PaymentService;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function payMidtrans(Request $request, $id)
    {
        $user = Auth::user();

        $request->validate([
            'package_id' => 'required|exists:packages,id'
        ]);

        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        try {

            $snapToken = $this->paymentService->createMidtrans(
                $user,
                $id,
                $request->package_id
            );

            return view('midtrans-pay', compact('snapToken'));
        } catch (\Exception $e) {

            Log::error('MIDTRANS ERROR: ' . $e->getMessage());

            return back()->with('error', 'Payment failed');
        }
    }

    public function payCrypto(Request $request, $productId)
    {
        $user = Auth::user();

        $request->validate([
            'package_id' => 'required|exists:packages,id',
            'coin' => 'required|string|max:20'
        ]);

        Order::where('user_id', $user->id)
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        try {

            $url = $this->paymentService->createCrypto(
                $user,
                $productId,
                $request->package_id,
                $request->coin
            );

            return redirect($url);
        } catch (\Exception $e) {

            Log::error('CRYPTO ERROR: ' . $e->getMessage());

            return back()->withErrors([
                'payment' => $e->getMessage()
            ]);
        }
    }
}
