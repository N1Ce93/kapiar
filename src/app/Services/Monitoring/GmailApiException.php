<?php

namespace App\Services\Monitoring;

use RuntimeException;

class GmailApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $operation,
    ) {
        parent::__construct(sprintf('Gmail API %s failed with HTTP %d.', $operation, $status));
    }
}
