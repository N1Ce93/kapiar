<?php

namespace App\Services\Monitoring;

use RuntimeException;

class GmailMonitoringPausedException extends RuntimeException
{
    // Raised when a queued or manual check encounters the persisted pause gate.
}
