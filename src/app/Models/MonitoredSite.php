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
        'consecutive_failures',
        'last_checked_at',
        'last_backfilled_at',
        'last_queued_at',
        'last_success_at',
        'last_error_at',
        'last_error',
        'disabled_at',
        'disabled_reason',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_checked_at' => 'datetime',
            'last_backfilled_at' => 'datetime',
            'last_queued_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
