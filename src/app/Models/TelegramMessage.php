<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $fillable = [
        'telegram_channel_id',
        'message_id',
        'text',
        'url',
        'posted_at',
        'checked_at',
        'notified_at',
        'is_backfilled',
    ];

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'posted_at' => 'datetime',
            'checked_at' => 'datetime',
            'notified_at' => 'datetime',
            'is_backfilled' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(TelegramChannel::class, 'telegram_channel_id');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(TelegramMessageKeywordHit::class);
    }
}
