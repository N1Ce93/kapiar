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
        'recovery_id',
        'recovery_status',
        'recovery_requested_at',
        'recovery_started_at',
        'recovery_finished_at',
        'recovery_error',
        'recovery_stats',
    ];

    protected function casts(): array
    {
        return [
            'paused_at' => 'datetime',
            'last_error_at' => 'datetime',
            'alert_attempted_at' => 'datetime',
            'alert_delivered_at' => 'datetime',
            'recovery_requested_at' => 'datetime',
            'recovery_started_at' => 'datetime',
            'recovery_finished_at' => 'datetime',
            'recovery_stats' => 'array',
        ];
    }
}
