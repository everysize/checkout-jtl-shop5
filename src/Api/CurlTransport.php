<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Api;

final class CurlTransport implements HttpTransport
{
    public function __construct(
        private int $connectTimeout = 10,
        private int $timeout = 30,
    ) {
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $ch = curl_init($url);
        $options = [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            \CURLOPT_TIMEOUT => $this->timeout,
            \CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[\CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new ApiException(sprintf('everysize API not reachable (%s %s): %s', $method, $url, $error));
        }

        $status = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response];
    }
}
