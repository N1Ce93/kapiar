<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailProcessingMessage extends Model
{
    protected $fillable = [
        'gmail_monitor_state_id',
        'gmail_message_id',
        'matched_keywords',
        'target_labels',
        'telegram_sent_at',
        'attempts',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'matched_keywords' => 'array',
            'target_labels' => 'array',
            'telegram_sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function monitorState(): BelongsTo
    {
        return $this->belongsTo(GmailMonitorState::class, 'gmail_monitor_state_id');
    }
}
