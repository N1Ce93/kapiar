<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TelegramMessageKeywordHit extends Model
{
    protected $fillable = [
        'telegram_message_id',
        'keyword_id',
        'matched_text',
        'context',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TelegramMessage::class, 'telegram_message_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }
}
