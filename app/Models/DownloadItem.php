<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DownloadItem extends Model
{
    protected $fillable = [
        'name',
        'links',
    ];

    protected $casts = [
        'links' => 'array',
    ];

    public function publicPayload(): array
    {
        return [
            'name' => $this->name,
            'links' => $this->links ?: [],
        ];
    }

    public function getLinksTextAttribute(): string
    {
        return collect($this->links ?: [])
            ->map(function ($link) {
                $label = trim((string) ($link['label'] ?? 'Download'));
                $url = trim((string) ($link['url'] ?? ''));

                return $url === '' ? null : $label.' | '.$url;
            })
            ->filter()
            ->implode("\n");
    }
}
