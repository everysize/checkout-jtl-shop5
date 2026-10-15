<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\paymentmethod;

use JTL\Plugin\Payment\Method;

/**
 * Payment method of imported everysize orders.
 *
 * It is created only so that imported orders carry a payment method at all —
 * it must never be offered in the checkout: everysize orders are paid at
 * everysize (PayPal), the shop only records them.
 */
class PaymentMethod extends Method
{
    /**
     * @inheritdoc
     */
    public function isValidIntern(array $args_arr = []): bool
    {
        return false;
    }
}
