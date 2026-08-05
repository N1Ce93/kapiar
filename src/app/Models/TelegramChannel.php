<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class TelegramChannel extends Model
{
    protected $fillable = [
        'title',
        'username',
        'url',
        'telegram_peer',
        'enabled',
        'consecutive_failures',
        'last_message_id',
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
            'last_message_id' => 'integer',
            'last_checked_at' => 'datetime',
            'last_backfilled_at' => 'datetime',
            'last_queued_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }
}
