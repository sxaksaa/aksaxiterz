<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GopayNotificationEvent;
use Illuminate\Http\Request;

class GopayNotificationEventController extends Controller
{
    private const ATTENTION_STATUSES = [
        'unmatched',
        'ambiguous',
        'stale',
        'matched_delivery_pending',
        'matched_delivery_failed',
    ];

    public function index(Request $request)
    {
        $statusOptions = [
            'all' => 'All events',
            'attention' => 'Needs attention',
            'matched' => 'Matched',
            'matched_delivery_pending' => 'Delivery pending',
            'matched_delivery_failed' => 'Delivery failed',
            'unmatched' => 'Unmatched',
            'ambiguous' => 'Ambiguous',
            'stale' => 'Stale',
            'received' => 'Received',
        ];
        $status = array_key_exists((string) $request->query('status'), $statusOptions)
            ? (string) $request->query('status')
            : 'attention';
        $search = trim((string) $request->query('search', ''));

        $events = GopayNotificationEvent::query()
            ->with('matchedOrder.user')
            ->when($status === 'attention', fn ($query) => $query->whereIn('status', self::ATTENTION_STATUSES))
            ->when($status === 'matched', fn ($query) => $query->whereIn('status', ['matched', 'matched_delivery_pending']))
            ->when(! in_array($status, ['all', 'attention', 'matched'], true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($filter) use ($search): void {
                    $filter->where('event_id', 'like', '%'.$search.'%')
                        ->orWhere('device_id', 'like', '%'.$search.'%')
                        ->orWhere('notification_text', 'like', '%'.$search.'%')
                        ->orWhereHas('matchedOrder', fn ($order) => $order->where('order_id', 'like', '%'.$search.'%'));

                    if (ctype_digit($search)) {
                        $filter->orWhere('amount_idr', (int) $search);
                    }
                });
            })
            ->orderByDesc('notification_posted_at_ms')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => GopayNotificationEvent::count(),
            'matched' => GopayNotificationEvent::whereIn('status', ['matched', 'matched_delivery_pending'])->count(),
            'attention' => GopayNotificationEvent::whereIn('status', self::ATTENTION_STATUSES)->count(),
            'delivery_pending' => GopayNotificationEvent::where('status', 'matched_delivery_pending')->count(),
        ];

        return view('admin.gopay-events.index', compact('events', 'stats', 'status', 'statusOptions'));
    }
}
