<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Api;

use Plugin\everysize_checkout\PluginInfo;

/**
 * Client for the everysize checkout "External API" (merchant order API).
 *
 * Takes its configuration as plain values (credentials, API base URL, shop and
 * plugin version for the self-identification headers); the transport is
 * injectable so the whole client is unit-testable without a shop. The access
 * token lives only in memory: a sync run needs one token (valid 3600 s).
 */
class Client
{
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_REVOKED = 'REVOKED';

    public const REASON_MERCHANT_DECLINE = 'MERCHANT_DECLINE';
    public const REASON_CUSTOMER_REVOKE = 'CUSTOMER_REVOKE';
    public const REASON_RETOUR = 'RETOUR';

    /** Problem reason of a second merchant-order-number call — the number is already there. */
    public const REASON_NUMBER_ALREADY_SET = 'MERCHANT_ORDER_NUMBER_CANNOT_BE_MODIFIED';

    private const ENDPOINT_TOKEN = '/api/v1/oauth/token';
    private const ENDPOINT_ORDERS = '/api/v1/shops/%d/orders?status=PROCESSING,REVOKING,REVOKED&acknowledged=false&pageNumber=0&pageSize=%d';
    private const ENDPOINT_ORDER = '/api/v1/shops/%d/orders/%s';
    private const ENDPOINT_ORDER_NUMBER = '/api/v1/shops/%d/orders/%s/merchant-order-number';
    private const ENDPOINT_FULFILLMENT = '/api/v1/shops/%d/orders/%s/fulfillment';
    private const ENDPOINT_REVOCATION = '/api/v1/shops/%d/orders/%s/revocations';
    private const ENDPOINT_REFUNDS = '/api/v1/shops/%d/orders/%s/refunds';

    /** Renew the token this many seconds before it expires. */
    private const TOKEN_MARGIN = 60;

    private HttpTransport $http;

    private ?string $apiUrl;

    private ?string $token = null;

    private int $tokenExpiresAt = 0;

    private ?int $shopId = null;

    /**
     * @param string $apiUrl '' = EVERYSIZE_CHECKOUT_API_URL / production, resolved on first use
     */
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        string $apiUrl = '',
        private string $shopSystemVersion = '',
        private string $pluginVersion = PluginInfo::VERSION,
        ?HttpTransport $transport = null,
    ) {
        $this->http = $transport ?? new CurlTransport();
        $this->apiUrl = $apiUrl !== '' ? $apiUrl : null;
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl ?? ApiUrl::resolve();
    }

    /** everysize shop id the configured credentials belong to. */
    public function getShopId(): int
    {
        $this->authenticate();

        return (int) $this->shopId;
    }

    /**
     * Requests a token with the given credentials (also used by the connection
     * test in the admin, before the credentials are saved).
     *
     * @return array{access_token: string, expires_in: int, shop_id: int}
     */
    public function requestToken(string $clientId, string $clientSecret): array
    {
        $response = $this->http->request('POST', $this->getApiUrl() . self::ENDPOINT_TOKEN, $this->headers([
            'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ]));

        $data = $this->decode($response['body']);
        if ($response['status'] !== 200 || !isset($data['access_token'], $data['shop_id'])) {
            throw $this->error('Token', $response);
        }

        return [
            'access_token' => (string) $data['access_token'],
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
            'shop_id' => (int) $data['shop_id'],
        ];
    }

    /**
     * Orders that are paid (or revoked) at everysize and have no merchant order
     * number yet. Always the first page: every order that gets its number
     * reported drops out of the list, so the next run sees the next ones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOpenOrders(int $limit = 100): array
    {
        $response = $this->call('GET', sprintf(self::ENDPOINT_ORDERS, $this->getShopId(), $limit));
        if ($response['status'] !== 200) {
            throw $this->error('Get orders', $response);
        }

        $content = $this->decode($response['body'])['content'] ?? [];

        return \is_array($content) ? array_values(array_filter($content, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(string $everysizeOrderId): array
    {
        $response = $this->call('GET', sprintf(self::ENDPOINT_ORDER, $this->getShopId(), rawurlencode($everysizeOrderId)));
        $data = $this->decode($response['body']);
        if ($response['status'] !== 200 || !isset($data['everysizeOrderId'])) {
            throw $this->error(sprintf('Get order %s', $everysizeOrderId), $response);
        }

        return $data;
    }

    /**
     * @return bool true if the number was set now, false if everysize already had one
     */
    public function sendMerchantOrderNumber(string $everysizeOrderId, string $orderNumber): bool
    {
        $response = $this->call(
            'POST',
            sprintf(self::ENDPOINT_ORDER_NUMBER, $this->getShopId(), rawurlencode($everysizeOrderId)),
            ['merchantOrderNumber' => $orderNumber]
        );

        if ($response['status'] === 204) {
            return true;
        }

        $error = $this->error(sprintf('Order number for %s', $everysizeOrderId), $response);
        if ($error->getReason() === self::REASON_NUMBER_ALREADY_SET) {
            return false;
        }

        throw $error;
    }

    /**
     * Marks the order as shipped. everysize only stores carrier and tracking
     * code if both are given.
     */
    public function sendFulfillment(string $everysizeOrderId, string $carrier, string $trackingCode): void
    {
        $body = new \stdClass();
        if ($carrier !== '' && $trackingCode !== '') {
            $body->carrier = $carrier;
            $body->trackingCode = [$trackingCode];
        }

        $response = $this->call('POST', sprintf(self::ENDPOINT_FULFILLMENT, $this->getShopId(), rawurlencode($everysizeOrderId)), $body);
        if ($response['status'] !== 201) {
            throw $this->error(sprintf('Fulfillment for %s', $everysizeOrderId), $response);
        }
    }

    /**
     * Refunds the customer through everysize (PayPal, on the merchant's behalf).
     * The idempotency key makes a repeated request (double click, timeout and
     * retry) refund only once.
     *
     * @param string|null $amount "10.00", null = everything not refunded yet
     *
     * @return array<string, mixed> the refund: refundId, status, amount, currency
     */
    public function refund(string $everysizeOrderId, ?string $amount, string $idempotencyKey): array
    {
        $body = $amount === null ? new \stdClass() : ['amount' => $amount];
        $response = $this->call(
            'POST',
            sprintf(self::ENDPOINT_REFUNDS, $this->getShopId(), rawurlencode($everysizeOrderId)),
            $body,
            ['Idempotency-Key: ' . $idempotencyKey]
        );
        $data = $this->decode($response['body']);
        if ($response['status'] !== 201) {
            throw $this->error(sprintf('Refund for %s', $everysizeOrderId), $response);
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>> refundId, status, amount, currency, created
     */
    public function getRefunds(string $everysizeOrderId): array
    {
        $response = $this->call('GET', sprintf(self::ENDPOINT_REFUNDS, $this->getShopId(), rawurlencode($everysizeOrderId)));
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !\is_array($data)) {
            throw $this->error(sprintf('Refunds of %s', $everysizeOrderId), $response);
        }

        return array_values(array_filter($data, '\is_array'));
    }

    public function sendRevocation(string $everysizeOrderId, string $sku, string $reason, string $comment = ''): void
    {
        $body = ['sku' => $sku, 'reason' => $reason, 'remainingQuantity' => 0];
        if ($comment !== '') {
            $body['comment'] = mb_substr($comment, 0, 255);
        }

        $response = $this->call('POST', sprintf(self::ENDPOINT_REVOCATION, $this->getShopId(), rawurlencode($everysizeOrderId)), $body);
        if ($response['status'] !== 204) {
            throw $this->error(sprintf('Revocation (%s) for %s', $reason, $everysizeOrderId), $response);
        }
    }

    private function authenticate(): void
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt - self::TOKEN_MARGIN) {
            return;
        }

        $clientId = trim($this->clientId);
        $clientSecret = trim($this->clientSecret);
        if ($clientId === '' || $clientSecret === '') {
            throw new ApiException('everysize credentials (client ID / client secret) are not configured');
        }

        $token = $this->requestToken($clientId, $clientSecret);
        $this->token = $token['access_token'];
        $this->tokenExpiresAt = time() + $token['expires_in'];
        $this->shopId = $token['shop_id'];
    }

    /**
     * @param array<string, mixed>|\stdClass|null $payload
     * @param string[]                            $extraHeaders
     *
     * @return array{status: int, body: string}
     */
    private function call(string $method, string $path, $payload = null, array $extraHeaders = []): array
    {
        $this->authenticate();

        $headers = array_merge(['Authorization: Bearer ' . $this->token], $extraHeaders);
        $body = null;
        if ($payload !== null) {
            $body = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        return $this->http->request($method, $this->getApiUrl() . $path, $this->headers($headers), $body);
    }

    /**
     * @param string[] $additional
     *
     * @return string[]
     */
    private function headers(array $additional): array
    {
        return array_merge([
            'Accept: application/json',
            'ERP-Shop-System: JTL-Shop',
            'ERP-Shop-System-Version: ' . $this->shopSystemVersion,
            'Integration-Partner: everysize.com',
            'Interface-Version: ' . $this->pluginVersion,
            'User-Agent: everysizeCheckoutPlugin-JTL-Shop/' . $this->pluginVersion,
        ], $additional);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $data = json_decode($body, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array{status: int, body: string} $response
     */
    private function error(string $context, array $response): ApiException
    {
        $data = $this->decode($response['body']);
        $detail = '';
        foreach (['error_description', 'detail', 'title', 'message'] as $field) {
            if (isset($data[$field]) && \is_string($data[$field]) && $data[$field] !== '') {
                $detail = $data[$field];
                break;
            }
        }
        if ($detail === '') {
            $detail = $response['body'] === '' ? 'empty response' : mb_substr(strip_tags($response['body']), 0, 200);
        }

        return new ApiException(
            sprintf('%s failed: HTTP %d %s', $context, $response['status'], $detail),
            $response['status'],
            \is_string($data['reason'] ?? null) ? $data['reason'] : ''
        );
    }
}
