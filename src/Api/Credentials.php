<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Api;

/**
 * API credentials. Settings implements it, so the client can be handed the
 * live settings as well as fixed test values.
 */
interface Credentials
{
    public function getClientId(): string;

    public function getClientSecret(): string;
}
