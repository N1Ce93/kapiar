<?php

namespace App\Services\Monitoring;

use RuntimeException;

class GmailApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $operation,
        ?string $detail = null,
    ) {
        $message = sprintf('Gmail API %s failed with HTTP %d.', $operation, $status);

        if ($detail !== null && trim($detail) !== '') {
            $message .= ' '.trim($detail);
        }

        parent::__construct($message);
    }
}
