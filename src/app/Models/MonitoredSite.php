<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class MonitoredSite extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'source_type',
        'feed_url',
        'listing_url',
        'enabled',
        'last_checked_at',
        'last_backfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_backfilled_at' => 'datetime',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
