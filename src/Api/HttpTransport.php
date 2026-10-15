<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Api;

interface HttpTransport
{
    /**
     * @param string[] $headers complete header lines ("Name: value")
     *
     * @return array{status: int, body: string}
     *
     * @throws ApiException if the API is not reachable (network, DNS, TLS)
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): array;
}
