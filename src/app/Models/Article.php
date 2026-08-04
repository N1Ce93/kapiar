<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $fillable = [
        'monitored_site_id',
        'url',
        'title',
        'excerpt',
        'content_hash',
        'published_at',
        'discovered_at',
        'checked_at',
        'notified_at',
        'is_backfilled',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'discovered_at' => 'datetime',
            'checked_at' => 'datetime',
            'notified_at' => 'datetime',
            'is_backfilled' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(MonitoredSite::class, 'monitored_site_id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(ArticleKeywordHit::class);
    }
}
