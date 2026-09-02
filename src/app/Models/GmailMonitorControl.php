<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailMonitorControl extends Model
{
    public const SINGLETON_ID = 1;

    protected $fillable = [
        'incident_id',
        'paused_at',
        'last_error_at',
        'last_error',
        'alert_attempted_at',
        'alert_delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
            'last_error_at' => 'datetime',
            'alert_attempted_at' => 'datetime',
            'alert_delivered_at' => 'datetime',
        ];
    }
}
