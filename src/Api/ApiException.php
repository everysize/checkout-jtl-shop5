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

    /** Set when the failure happened before the POST left (token request): nothing was sent. */
    private bool $beforePost = false;

    /** The same failure, marked as "the POST was never sent" (see Client::refund()). */
    public function asBeforePost(): static
    {
        $copy = new static($this->getMessage(), $this->status, $this->reason);
        $copy->beforePost = true;

        return $copy;
    }

    /** The refund POST never went out (token request failed): nothing happened at everysize. */
    public function isBeforePost(): bool
    {
        return $this->beforePost;
    }

    /**
     * Refund rule R0: only these answers of the refund POST prove that no money moved - 422 with
     * PAYPAL_REFUND_FAILED or REFUND_AMOUNT_INVALID, 400 with ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS.
     */
    public function isSafelyRejected(): bool
    {
        if ($this->beforePost) {
            return false;
        }

        return ($this->status === 422 && \in_array($this->reason, ['PAYPAL_REFUND_FAILED', 'REFUND_AMOUNT_INVALID'], true))
            || ($this->status === 400 && $this->reason === 'ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS');
    }

    /**
     * Anything else that went wrong with the refund POST (no answer, timeout, 3xx, 401, 403, 404, 408, 409, 425,
     * 429, 5xx, IDEMPOTENCY_KEY_REUSED, unknown answers): nobody knows, the money may have moved.
     */
    public function isUnclear(): bool
    {
        return !$this->beforePost && !$this->isSafelyRejected();
    }

    /** Machine-readable "reason" of an everysize problem response, e.g. MERCHANT_ORDER_NUMBER_CANNOT_BE_MODIFIED. */
    public function getReason(): string
    {
        return $this->reason;
    }
}
