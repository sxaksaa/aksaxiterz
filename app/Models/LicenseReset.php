<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseReset extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'license_id',
        'user_id',
        'provider',
        'username',
        'status',
        'http_status',
        'provider_message',
        'succeeded_at',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'succeeded_at' => 'datetime',
    ];

    public function license()
    {
        return $this->belongsTo(License::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
