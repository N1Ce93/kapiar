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
        'last_message_id',
        'last_checked_at',
        'last_backfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_message_id' => 'integer',
            'last_checked_at' => 'datetime',
            'last_backfilled_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }
}
