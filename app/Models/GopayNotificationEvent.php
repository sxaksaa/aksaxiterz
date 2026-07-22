<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class GopayNotificationEvent extends Model
{
    protected $fillable = [
        'event_id',
        'device_id',
        'package_name',
        'title',
        'notification_text',
        'amount_idr',
        'notification_posted_at_ms',
        'status',
        'matched_order_id',
        'received_at',
        'last_received_at',
    ];

    protected $casts = [
        'amount_idr' => 'integer',
        'notification_posted_at_ms' => 'integer',
        'received_at' => 'datetime',
        'last_received_at' => 'datetime',
    ];

    public function matchedOrder()
    {
        return $this->belongsTo(Order::class, 'matched_order_id');
    }

    public function notificationPostedAt(): Carbon
    {
        return Carbon::createFromTimestampMs((int) $this->notification_posted_at_ms, 'UTC')
            ->timezone(config('app.timezone'));
    }
}
