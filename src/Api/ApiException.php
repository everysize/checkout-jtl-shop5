<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Api;

class ApiException extends \RuntimeException
{
    private int $status;

    private string $reason;

    public function __construct(string $message, int $status = 0, string $reason = '')
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->reason = $reason;
    }

    /** HTTP status of the failed call, 0 if the API was not reachable at all. */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Machine-readable "reason" of an everysize problem response, e.g. MERCHANT_ORDER_NUMBER_CANNOT_BE_MODIFIED. */
    public function getReason(): string
    {
        return $this->reason;
    }
}
