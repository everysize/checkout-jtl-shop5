<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Shop;

/**
 * Everything the importer needs from the shop runtime (JTL), kept behind an
 * interface so the importer itself stays testable: the tests substitute a
 * fake that records what the importer asks the shop to do.
 */
interface ShopGateway
{
    /**
     * JTL stores customer/address names, companies, street and additional
     * info XTEA-encrypted (Customer::encryptCustomerData(),
     * Adresse::encrypt()) — everything else is stored in plain text and is
     * decrypted by the Wawi on read.
     */
    public function encryptXTEA(string $text): string;

    /**
     * The next order number like OrderHandler::createOrderNo(): counter from
     * tnummern (thread-safe) plus the configured start number, with the
     * configured prefix/suffix (placeholders resolved) and the 20 char limit.
     */
    public function nextOrderNumber(): string;

    /**
     * Tax rate of a tax class for a delivery country (tsteuersatz via
     * tsteuerzoneland, lowest priority value wins), null if unknown.
     */
    public function taxRateFor(int $taxClassId, string $countryIso): ?float;

    /**
     * Flushes the article cache tag of an article after a stock change
     * (CACHING_GROUP_ARTICLE_<id>).
     */
    public function flushArticleCache(int $articleId): void;

    /**
     * Sends the JTL order confirmation mail for an order (template
     * MAILTEMPLATE_BESTELLBESTAETIGUNG), exactly like the checkout does.
     */
    public function sendOrderConfirmation(int $orderId): void;
}
