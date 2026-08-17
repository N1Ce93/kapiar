<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'next_check_at',
        'check_pending_at',
        'check_claim_token',
        'last_success_at',
        'last_error_at',
        'last_error',
        'last_error_type',
        'paused_at',
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
            'next_check_at' => 'datetime',
            'check_pending_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'paused_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
