<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GmailMonitorState extends Model
{
    protected $fillable = [
        'email_address',
        'history_id',
        'initialized_at',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'initialized_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function processingMessages(): HasMany
    {
        return $this->hasMany(GmailProcessingMessage::class);
    }
}
