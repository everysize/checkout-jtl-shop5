<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Shop;

use JTL\Checkout\Nummern;
use JTL\Shop;

/**
 * The JTL implementation of the shop gateway. Every call needs the shop
 * bootstrapped (config, defines, autoload) — the CLI commands, the cron job
 * and the webcron route do that before constructing it.
 */
final class JtlShopGateway implements ShopGateway
{
    public function encryptXTEA(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return Shop::Container()->getCryptoService()->encryptXTEA($text);
    }

    public function nextOrderNumber(): string
    {
        // Like OrderHandler::createOrderNo(): the thread-safe counter from
        // tnummern plus the configured start number, prefix/suffix with
        // their placeholders resolved, limited to 20 characters.
        $conf = Shop::getSettingSection(\CONF_KAUFABWICKLUNG);
        $number = new Nummern(\JTL_GENNUMBER_ORDERNUMBER);
        $orderNo = $number->getNextNumberThreadsafe()
            + (int) ($conf['bestellabschluss_bestellnummer_anfangsnummer'] ?? 1);

        $prefix = $this->orderNoFormat((string) ($conf['bestellabschluss_bestellnummer_praefix'] ?? ''));
        $suffix = $this->orderNoFormat((string) ($conf['bestellabschluss_bestellnummer_suffix'] ?? ''));
        $length = \mb_strlen($prefix . $orderNo);
        if ($length > 20) {
            $prefix = \mb_substr($prefix, $length - 20);
            $suffix = '';
            Shop::Container()->getLogService()->error('everysize checkout: order number exceeds the 20 char limit, prefix shortened');
        } elseif ($length + \mb_strlen($suffix) > 20) {
            $suffix = \mb_substr($suffix, 0, 20 - $length);
            Shop::Container()->getLogService()->error('everysize checkout: order number exceeds the 20 char limit, suffix shortened');
        }

        return $prefix . $orderNo . $suffix;
    }

    public function taxRateFor(int $taxClassId, string $countryIso): ?float
    {
        $rate = Shop::Container()->getDB()->getSingleObject(
            'SELECT ts.fSteuersatz
             FROM tsteuersatz ts
             JOIN tsteuerzoneland tzl ON tzl.kSteuerzone = ts.kSteuerzone
             WHERE ts.kSteuerklasse = :taxClass AND tzl.cISO = :iso
             ORDER BY ts.nPrio, ts.kSteuersatz
             LIMIT 1',
            ['taxClass' => $taxClassId, 'iso' => $countryIso]
        );

        return $rate !== null ? (float) $rate->fSteuersatz : null;
    }

    public function flushArticleCache(int $articleId): void
    {
        if ($articleId > 0) {
            Shop::Container()->getCache()->flushTags([\CACHING_GROUP_ARTICLE . '_' . $articleId]);
        }
    }

    public function sendOrderConfirmation(int $orderId): void
    {
        $db = Shop::Container()->getDB();
        $order = (new \JTL\Checkout\Bestellung($orderId, false, $db))->fuelleBestellung(false);
        if ((int) $order->kBestellung <= 0) {
            return;
        }

        $data = new \stdClass();
        $data->tkunde = new \JTL\Customer\Customer((int) $order->kKunde);
        $data->tbestellung = $order;
        $data->payments = $order->getIncommingPayments(false);
        // finalizeOrder() füllt cVerfuegbarkeit_arr via checkAvailability() —
        // ohne die Variable bricht das Bestellbestätigungs-Template mit
        // count(null) ab. Kein Überverkauf-Hinweis: leere Liste.
        $data->cVerfuegbarkeit_arr = ['cArtikelName_arr' => []];

        $mail = (new \JTL\Mail\Mail\Mail())->createFromTemplateID(\MAILTEMPLATE_BESTELLBESTAETIGUNG, $data);
        Shop::Container()->getMailer()->send($mail);
    }

    /**
     * Placeholders of the order number prefix/suffix: %Y year, %m month, %d
     * day, %W week — like OrderHandler::getOrderNoPrefix()/getOrderNoSuffix().
     */
    private function orderNoFormat(string $format): string
    {
        return \str_replace(
            ['%Y', '%m', '%d', '%W'],
            [\date('Y'), \date('m'), \date('d'), \date('W')],
            $format
        );
    }
}
