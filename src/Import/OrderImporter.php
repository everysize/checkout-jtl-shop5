<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Import;

use Plugin\everysize_checkout\Api\Amount;
use Plugin\everysize_checkout\Api\Client;
use Plugin\everysize_checkout\Db\Connection;
use Plugin\everysize_checkout\OrderLinks;
use Plugin\everysize_checkout\Settings;
use Plugin\everysize_checkout\Shop\ShopGateway;

/**
 * Creates a JTL order (tbestellung) from an everysize order.
 *
 * The order is written with the prices the customer paid at everysize — not
 * through the shop's basket, which would recalculate with the shop's own
 * prices. Customer, basket, positions, addresses, order, stock and the
 * everysize link are written in one transaction: a failure leaves nothing
 * behind, no orphaned customer per failed attempt. Everything JTL has a
 * class or a documented convention for (XTEA-encrypted fields, the order
 * number counter, tax rates per delivery country, stock rules, the
 * confirmation mail) is done through the ShopGateway, so the Wawi and the
 * backend read exactly what they expect.
 */
class OrderImporter
{
    /** twarenkorbpos.nPosTyp: product. */
    private const POS_TYP_PRODUCT = 1;

    /** twarenkorbpos.nPosTyp: shipping. */
    private const POS_TYP_SHIPPING = 2;

    public function __construct(
        private Connection $db,
        private int $pluginId,
        private Settings $settings,
        private OrderLinks $links,
        private ShopGateway $shop
    ) {
    }

    /**
     * @param array<string, mixed> $esOrder order as returned by the everysize API
     *
     * @return array{orderId: int, orderNumber: string, warnings: string[]}
     */
    public function import(array $esOrder): array
    {
        $everysizeOrderId = $this->requireString($esOrder, 'everysizeOrderId');
        $customer = \is_array($esOrder['customer'] ?? null) ? $esOrder['customer'] : [];
        $email = $this->requireString($customer, 'email', 'customer.email');
        // Information only: the JTL customer keeps the forwarding address above.
        $originalEmail = filter_var(trim((string) ($customer['originalEmail'] ?? '')), \FILTER_VALIDATE_EMAIL) ?: '';
        $billing = $this->address($esOrder['billingAddress'] ?? null, 'billingAddress');
        $shipping = \is_array($esOrder['shippingAddress'] ?? null) && $esOrder['shippingAddress'] !== []
            ? $this->address($esOrder['shippingAddress'], 'shippingAddress')
            : $billing;
        $revoked = strtoupper((string) ($esOrder['status'] ?? '')) === Client::STATUS_REVOKED;
        $warnings = [];

        $this->requireShopCurrency($esOrder['currency'] ?? 'EUR');
        $paymentMethod = $this->paymentMethod();
        $lines = $this->resolveLines($esOrder['lineItems'] ?? null, $shipping['countryIso']);
        $prices = PriceCalculator::calculate(
            array_map(static function (array $line): array {
                return ['gross' => $line['gross'], 'quantity' => $line['quantity'], 'vat' => $line['vat']];
            }, $lines),
            Amount::parse($esOrder['shippingCosts'] ?? 0, 'shippingCosts')
        );
        $checkTotal = PriceCalculator::checkTotal($prices, Amount::parse($esOrder['grossPrice'] ?? $prices['totalOrderSum'], 'grossPrice'), $everysizeOrderId);
        if ($checkTotal !== null) {
            $warnings[] = $checkTotal;
        }

        $payment = \is_array($esOrder['payment'] ?? null) ? $esOrder['payment'] : [];
        $payPalCaptureId = (string) ($payment['resourceId'] ?? $payment['transactionId'] ?? '');
        $orderDate = $this->localDate($esOrder['created'] ?? null);
        $paidDate = isset($esOrder['processed']) ? $this->localDate($esOrder['processed']) : $orderDate;

        $db = $this->db;
        $db->beginTransaction();
        try {
            $customerId = $this->findOrCreateCustomer($email, $billing);
            $shippingAddressId = $shipping === $billing ? 0 : $this->insertAddress('tlieferadresse', $customerId, $shipping, '');
            $billingAddressId = $this->insertAddress('trechnungsadresse', $customerId, $billing, $email);
            $basketId = $db->insert('twarenkorb', ['kKunde' => $customerId, 'kLieferadresse' => $shippingAddressId, 'kZahlungsInfo' => 0]);

            $orderNumber = $this->shop->nextOrderNumber();
            $shippingMethod = $this->shippingMethodFor($shipping['countryIso']);
            $orderId = $db->insert('tbestellung', [
                'kWarenkorb' => $basketId,
                'kKunde' => $customerId,
                'kLieferadresse' => $shippingAddressId,
                'kRechnungsadresse' => $billingAddressId,
                'kZahlungsart' => $paymentMethod['id'],
                'kVersandart' => $shippingMethod['id'],
                'kSprache' => $this->defaultLanguageId(),
                'kWaehrung' => $this->shopCurrencyId($esOrder['currency'] ?? 'EUR'),
                'fGuthaben' => 0,
                'fGesamtsumme' => $prices['totalOrderSum'],
                'cSession' => '',
                'cVersandartName' => $shippingMethod['name'],
                'cZahlungsartName' => $paymentMethod['name'],
                'cBestellNr' => $orderNumber,
                'cVersandInfo' => '',
                'dVersandDatum' => null,
                'dBezahltDatum' => $revoked ? null : \date('Y-m-d', (int) strtotime($paidDate)),
                // The everysize order id must be visible to the Wawi (dbeS
                // reads cKommentar) and to the backend order view.
                'cKommentar' => 'everysize Order: ' . $everysizeOrderId,
                'cLogistiker' => '',
                'cTrackingURL' => '',
                'cIP' => '',
                'cAbgeholt' => 'N',
                'cStatus' => (string) ($revoked ? '-1' : $this->settings->getImportOrderStatus()),
                'dErstellt' => $orderDate,
                'fWaehrungsFaktor' => 1,
            ]);
            if ($orderId <= 0) {
                throw new ImportException(sprintf('%s: order could not be saved', $everysizeOrderId));
            }
            // Like OrderHandler::persistOrder(): a unique id plus a status
            // row for the order (used by the backend and the Wawi).
            $now = \date('Y-m-d H:i:s');
            $db->insert('tbestellid', ['cId' => \uniqid('', true), 'kBestellung' => $orderId, 'dDatum' => $now]);
            $db->insert('tbestellstatus', ['kBestellung' => $orderId, 'cUID' => \uniqid('', true), 'dDatum' => $now]);

            // Shipping is a basket position of its own (nPosTyp 2): without
            // it the Wawi sums 119.99 instead of 124.89. It is NOT an
            // everysize item (no _item row).
            $shippingNet = $prices['deliveryCost'] / (1 + $prices['deliveryVat'] / 100);
            $positionSum = 0.0;
            $positionId = $db->insert('twarenkorbpos', [
                'kWarenkorb' => $basketId,
                'kArtikel' => 0,
                'kVersandklasse' => 1,
                'cName' => $shippingMethod['name'],
                'cLieferstatus' => '',
                'cArtNr' => '',
                'cEinheit' => '',
                'fPreisEinzelNetto' => $shippingNet,
                'fPreis' => $shippingNet,
                'fMwSt' => $prices['deliveryVat'],
                'nAnzahl' => 1,
                'nPosTyp' => self::POS_TYP_SHIPPING,
                'cHinweis' => '',
                'cUnique' => '',
                'cResponsibility' => 'core',
                'kKonfigitem' => 0,
                'fLagerbestandVorAbschluss' => 0,
            ]);
            if ($positionId <= 0) {
                throw new ImportException(sprintf('%s: shipping position could not be saved', $everysizeOrderId));
            }
            $positionSum += $shippingNet * (1 + $prices['deliveryVat'] / 100);

            foreach ($lines as $line) {
                $article = $line['article'];
                $stockBefore = 0.0;
                if ((string) $article['cLagerBeachten'] === 'Y') {
                    $stockBefore = (float) ($article['stock'] ?? 0);
                    if ($stockBefore < $line['quantity'] * (float) $article['fPackeinheit']) {
                        $warnings[] = sprintf(
                            '%s: article %s stock %s is below the ordered amount %d',
                            $everysizeOrderId,
                            $article['artNr'],
                            $stockBefore,
                            $line['quantity']
                        );
                    }
                }
                $positionId = $db->insert('twarenkorbpos', [
                    'kWarenkorb' => $basketId,
                    'kArtikel' => $article['id'],
                    'kVersandklasse' => 1,
                    'cName' => $article['name'] !== '' ? $article['name'] : $line['title'],
                    'cLieferstatus' => '',
                    'cArtNr' => $article['artNr'],
                    'cEinheit' => '',
                    'fPreisEinzelNetto' => $line['unitNet'],
                    'fPreis' => $line['unitNet'],
                    'fMwSt' => $line['vat'],
                    'nAnzahl' => $line['quantity'],
                    'nPosTyp' => self::POS_TYP_PRODUCT,
                    'cHinweis' => '',
                    'cUnique' => '',
                    'cResponsibility' => 'core',
                    'kKonfigitem' => 0,
                    'fLagerbestandVorAbschluss' => $stockBefore,
                ]);
                if ($positionId <= 0) {
                    throw new ImportException(sprintf('%s: order position %s could not be saved', $everysizeOrderId, $line['sku']));
                }
                $positionSum += $line['unitNet'] * (1 + $line['vat'] / 100) * $line['quantity'];

                if (!$revoked) {
                    $this->reduceStock($article, (int) $line['quantity'], $warnings);
                }
                $this->links->addOrderItem($positionId, $orderId, $line['sku']);
            }

            // The written positions must sum up to the stored total — a
            // deviation means the Wawi would see a different amount than the
            // customer paid.
            if (abs($positionSum - $prices['totalOrderSum']) > 0.009) {
                throw new ImportException(sprintf(
                    '%s: positions sum to %.2f but the order total is %.2f',
                    $everysizeOrderId,
                    $positionSum,
                    $prices['totalOrderSum']
                ));
            }

            // dbeS Push/Payments reads tzahlungseingang only — without it the
            // Wawi sees an unpaid order. Revoked orders were never paid.
            if (!$revoked) {
                $db->insert('tzahlungseingang', [
                    'kBestellung' => $orderId,
                    'cZahlungsanbieter' => $paymentMethod['name'],
                    'fBetrag' => $prices['totalOrderSum'],
                    'fZahlungsgebuehr' => 0,
                    'cISO' => strtoupper((string) ($esOrder['currency'] ?? 'EUR')),
                    'cEmpfaenger' => '',
                    'cZahler' => $email,
                    'dZeit' => $paidDate,
                    'cHinweis' => $payPalCaptureId,
                    'cAbgeholt' => 'N',
                ]);
            }

            $this->links->addOrder($orderId, $everysizeOrderId, $payPalCaptureId, $originalEmail);
            $db->commit();
        } catch (ImportException $e) {
            $db->rollback();

            throw $e;
        } catch (\Throwable $e) {
            $db->rollback();

            throw new ImportException(sprintf('%s: %s: %s', $everysizeOrderId, \get_class($e), $e->getMessage()), 0, $e);
        }

        if (!$revoked && $this->settings->shouldSendOrderConfirmation()) {
            try {
                $this->shop->sendOrderConfirmation($orderId);
            } catch (\Throwable $e) {
                // The order is imported; a broken mail must not undo it.
                $warnings[] = sprintf('order confirmation mail failed: %s', $e->getMessage());
            }
        }

        return ['orderId' => $orderId, 'orderNumber' => $orderNumber, 'warnings' => $warnings];
    }

    /**
     * @return array<int, array{sku: string, title: string, quantity: int, gross: float, vat: float, unitNet: float, article: array{id: int, artNr: string, name: string, vat: float, fPackeinheit: float, cLagerBeachten: string, stock: float}}>
     */
    private function resolveLines($lineItems, string $countryIso): array
    {
        if (!\is_array($lineItems) || $lineItems === []) {
            throw new ImportException('everysize order has no line items');
        }

        $lines = [];
        foreach (array_values($lineItems) as $item) {
            if (!\is_array($item)) {
                throw new ImportException('invalid line item in everysize order');
            }
            $sku = $this->requireString($item, 'sku', 'lineItems.sku');
            $article = $this->findArticle($sku);
            $vat = $this->shop->taxRateFor((int) $article['kSteuerklasse'], $countryIso);
            if ($vat === null) {
                throw new ImportException(sprintf('No tax rate for tax class %d and country %s', $article['kSteuerklasse'], $countryIso));
            }
            $unitGross = Amount::parse($item['price'] ?? null, 'lineItems.price');
            $lines[] = [
                'sku' => $sku,
                'title' => trim((string) ($item['title'] ?? '')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'gross' => $unitGross,
                'vat' => $vat,
                'unitNet' => $unitGross / (1 + $vat / 100),
                'article' => $article,
            ];
        }

        return $lines;
    }

    /**
     * The SKU everysize sends is the article number (tartikel.cArtNr) of the
     * variant the customer bought. cArtNr is not unique in JTL: an exact
     * (case-sensitive) match beats a case-insensitive one; a father article
     * is never a buyable variant and is excluded. Two equally good
     * candidates are an error.
     *
     * @return array{kArtikel: int, cArtNr: string, cName: string, kSteuerklasse: int, fPackeinheit: float, cLagerBeachten: string, stock: float, kVaterArtikel: int}
     */
    private function findArticle(string $sku): array
    {
        $rows = $this->db->getObjects(
            'SELECT kArtikel, cArtNr, cName, kSteuerklasse, fPackeinheit, cLagerBeachten, fLagerbestand, kVaterArtikel,
                    (BINARY cArtNr = :exact) AS exactMatch
             FROM tartikel
             WHERE cArtNr = :sku AND nIstVater = 0
             ORDER BY exactMatch DESC, kArtikel ASC',
            ['sku' => $sku, 'exact' => $sku]
        );
        if ($rows === []) {
            throw new ImportException(sprintf('No article with article number %s', $sku));
        }
        if (\count($rows) > 1 && (int) $rows[0]->exactMatch === (int) $rows[1]->exactMatch) {
            throw new ImportException(sprintf('Article number %s is not unique in the shop', $sku));
        }
        $article = $rows[0];

        return [
            'id' => (int) $article->kArtikel,
            'artNr' => (string) $article->cArtNr,
            'name' => (string) $article->cName,
            'vat' => 0.0,
            'kSteuerklasse' => (int) $article->kSteuerklasse,
            'fPackeinheit' => (float) $article->fPackeinheit,
            'cLagerBeachten' => (string) $article->cLagerBeachten,
            'stock' => (float) $article->fLagerbestand,
            'kVaterArtikel' => (int) $article->kVaterArtikel,
        ];
    }

    /**
     * Stock like JTL does (StockUpdater::updateStock()): only when the
     * article watches stock, packed units counted, a father article kept in
     * sync, floor at zero (JTL uses GREATEST), the article cache flushed.
     * A stock below the ordered amount is reported as a warning, not an
     * error — the order is fine, the article is oversold.
     *
     * @param array{id: int, artNr: string, kVaterArtikel: int, fPackeinheit: float, cLagerBeachten: string, stock: float} $article
     */
    private function reduceStock(array $article, int $quantity, array &$warnings): void
    {
        if ((string) $article['cLagerBeachten'] !== 'Y') {
            return;
        }
        $amount = $quantity * ($article['fPackeinheit'] > 0 ? $article['fPackeinheit'] : 1.0);
        $this->db->execute(
            'UPDATE tartikel SET fLagerbestand = GREATEST(fLagerbestand - :amount, 0) WHERE kArtikel = :id',
            ['amount' => $amount, 'id' => $article['id']]
        );
        $this->shop->flushArticleCache($article['id']);
        if ($article['kVaterArtikel'] > 0) {
            $this->db->execute(
                'UPDATE tartikel SET fLagerbestand = GREATEST(fLagerbestand - :amount, 0) WHERE kArtikel = :id',
                ['amount' => $amount, 'id' => $article['kVaterArtikel']]
            );
            $this->shop->flushArticleCache($article['kVaterArtikel']);
        }
    }

    /**
     * Customers are identified by the e-mail everysize sends — a forwarding
     * alias, stable per customer. Only a registered, not locked customer
     * matches (Customer.php: a guest account is not a login); a new
     * customer is a guest like a JTL guest checkout (cPasswort empty,
     * dVeraendert set). The encrypted fields are XTEA-encrypted via the
     * gateway, exactly like Customer::encryptCustomerData().
     *
     * @param array<string, string> $billing
     */
    private function findOrCreateCustomer(string $email, array $billing): int
    {
        $existing = $this->db->getScalar(
            "SELECT kKunde FROM tkunde WHERE cMail = :mail AND nRegistriert = 1
             AND (cSperre IS NULL OR cSperre <> 'Y') ORDER BY kKunde LIMIT 1",
            ['mail' => $email]
        );
        if ($existing !== null && (int) $existing > 0) {
            return (int) $existing;
        }

        $shop = $this->shop;
        $encrypted = static function (string $value) use ($shop): string {
            return $shop->encryptXTEA($value);
        };

        return $this->db->insert('tkunde', [
            'kKundengruppe' => $this->defaultCustomerGroupId(),
            'kSprache' => $this->defaultLanguageId(),
            'cKundenNr' => '',
            'cPasswort' => '',
            'cAnrede' => '',
            'cTitel' => '',
            'cVorname' => $billing['firstName'],
            'cNachname' => $encrypted($billing['lastName']),
            'cFirma' => $encrypted(''),
            'cZusatz' => $encrypted($billing['addInfo']),
            'cStrasse' => $encrypted($billing['street']),
            'cHausnummer' => $billing['streetNr'],
            'cAdressZusatz' => '',
            'cPLZ' => $billing['zip'],
            'cOrt' => $billing['city'],
            'cBundesland' => '',
            'cLand' => $billing['countryIso'],
            'cTel' => '',
            'cMobil' => '',
            'cFax' => '',
            'cMail' => $email,
            'cUSTID' => '',
            'cWWW' => '',
            'cSperre' => 'N',
            'fGuthaben' => 0,
            'cNewsletter' => 'N',
            'fRabatt' => 0,
            'cHerkunft' => 'everysize',
            'dErstellt' => \date('Y-m-d'),
            'dVeraendert' => \date('Y-m-d H:i:s'),
            'cAktiv' => 'Y',
            'cAbgeholt' => 'N',
            'nRegistriert' => 0,
            'nLoginversuche' => 0,
        ]);
    }

    /**
     * Address rows with the fields JTL encrypts (Adresse::encrypt()):
     * cNachname, cFirma, cZusatz, cStrasse. The billing address carries the
     * everysize customer e-mail so the Wawi sees the payer.
     *
     * @param array{firstName: string, lastName: string, street: string, streetNr: string, addInfo: string, zip: string, city: string, countryIso: string} $address
     */
    private function insertAddress(string $table, int $customerId, array $address, string $email): int
    {
        $shop = $this->shop;
        $encrypted = static function (string $value) use ($shop): string {
            return $shop->encryptXTEA($value);
        };
        $id = $this->db->insert($table, [
            'kKunde' => $customerId,
            'cAnrede' => '',
            'cTitel' => '',
            'cVorname' => $address['firstName'],
            'cNachname' => $encrypted($address['lastName']),
            'cFirma' => $encrypted(''),
            'cZusatz' => $encrypted($address['addInfo']),
            'cStrasse' => $encrypted($address['street']),
            'cHausnummer' => $address['streetNr'],
            'cAdressZusatz' => '',
            'cPLZ' => $address['zip'],
            'cOrt' => $address['city'],
            'cBundesland' => '',
            'cLand' => $address['countryIso'],
            'cTel' => '',
            'cMobil' => '',
            'cFax' => '',
            'cUSTID' => '',
            'cWWW' => '',
            'cMail' => $email,
        ]);
        if ($id <= 0) {
            throw new ImportException(sprintf('Address could not be saved to %s', $table));
        }

        return $id;
    }

    /**
     * The plugin's payment method (tpluginzahlungsartklasse) — import
     * orders are paid at everysize, the JTL row only carries the fact.
     *
     * @return array{id: int, name: string}
     */
    private function paymentMethod(): array
    {
        $row = $this->db->getSingleObject(
            "SELECT z.kZahlungsart, z.cName
             FROM tpluginzahlungsartklasse pk
             JOIN tzahlungsart z ON z.cModulId = pk.cModulId
             WHERE pk.kPlugin = :pluginId AND pk.cClassName = 'PaymentMethod'",
            ['pluginId' => $this->pluginId]
        );
        if ($row === null) {
            throw new ImportException('Plugin payment method is not installed');
        }

        return ['id' => (int) $row->kZahlungsart, 'name' => (string) $row->cName];
    }

    /**
     * A shipping method that delivers to the delivery country: the
     * configured kVersandart if it fits, otherwise the first one by sort
     * order that does. tversandart.cLaender is a space-separated ISO list;
     * empty means every country.
     *
     * @return array{id: int, name: string}
     */
    private function shippingMethodFor(string $countryIso): array
    {
        $rows = $this->db->getObjects(
            'SELECT kVersandart, cName, cLaender FROM tversandart ORDER BY nSort, kVersandart'
        );
        $configured = $this->settings->getShippingMethodId();
        $firstAllowed = null;
        foreach ($rows as $row) {
            if (!$this->shipsTo((string) $row->cLaender, $countryIso)) {
                continue;
            }
            if ($firstAllowed === null) {
                $firstAllowed = $row;
            }
            if ($configured > 0 && (int) $row->kVersandart === $configured) {
                return ['id' => $configured, 'name' => (string) $row->cName];
            }
        }
        if ($firstAllowed === null) {
            throw new ImportException(sprintf('No shipping method delivers to %s', $countryIso));
        }
        if ($configured > 0) {
            throw new ImportException(sprintf('The configured shipping method does not deliver to %s', $countryIso));
        }

        return ['id' => (int) $firstAllowed->kVersandart, 'name' => (string) $firstAllowed->cName];
    }

    private function shipsTo(string $countries, string $countryIso): bool
    {
        $countries = trim($countries);
        if ($countries === '' || $countries === '-1') {
            return true;
        }

        return in_array($countryIso, preg_split('/[\s,;]+/', $countries) ?: [], true);
    }

    /**
     * The everysize order is in the shop currency — a deviation is an error
     * before anything is written, not a silent conversion.
     */
    private function requireShopCurrency(string $currency): void
    {
        $iso = strtoupper(trim($currency));
        $shopIso = strtoupper((string) ($this->db->getScalar("SELECT cISO FROM twaehrung WHERE cStandard = 'Y' ORDER BY kWaehrung LIMIT 1") ?? ''));
        if ($shopIso === '' || $shopIso !== $iso) {
            throw new ImportException(sprintf(
                'everysize order is in %s but the shop default currency is %s',
                $iso !== '' ? $iso : '(empty)',
                $shopIso !== '' ? $shopIso : '(none)'
            ));
        }
    }

    private function shopCurrencyId(string $currency): int
    {
        $id = $this->db->getScalar('SELECT kWaehrung FROM twaehrung WHERE cISO = :iso ORDER BY kWaehrung LIMIT 1', ['iso' => strtoupper($currency)]);

        return $id !== null && (int) $id > 0 ? (int) $id : 0;
    }

    private function defaultCustomerGroupId(): int
    {
        $id = $this->db->getScalar("SELECT kKundengruppe FROM tkundengruppe WHERE cStandard = 'Y' ORDER BY kKundengruppe LIMIT 1");

        return $id !== null && (int) $id > 0 ? (int) $id : 0;
    }

    private function defaultLanguageId(): int
    {
        $id = $this->db->getScalar("SELECT kSprache FROM tsprache WHERE cShopStandard = 'Y' ORDER BY kSprache LIMIT 1");

        return $id !== null && (int) $id > 0 ? (int) $id : 1;
    }

    /**
     * @return array{firstName: string, lastName: string, street: string, streetNr: string, addInfo: string, zip: string, city: string, countryIso: string}
     */
    private function address($address, string $field): array
    {
        if (!\is_array($address)) {
            throw new ImportException(sprintf('everysize order has no %s', $field));
        }

        [$street, $streetNr] = AddressSplitter::split($this->requireString($address, 'addressLine1', $field . '.addressLine1'));
        $countryIso = strtoupper($this->requireString($address, 'countryCode', $field . '.countryCode'));
        $country = $this->db->getScalar('SELECT cISO FROM tland WHERE cISO = :iso LIMIT 1', ['iso' => $countryIso]);
        if ($country === null) {
            throw new ImportException(sprintf('Unknown country %s in %s', $countryIso, $field));
        }

        return [
            'firstName' => trim((string) ($address['firstName'] ?? '')),
            'lastName' => $this->requireString($address, 'lastName', $field . '.lastName'),
            'street' => $street,
            'streetNr' => $streetNr,
            'addInfo' => trim((string) ($address['addressLine2'] ?? '')),
            'zip' => $this->requireString($address, 'postalCode', $field . '.postalCode'),
            'city' => $this->requireString($address, 'city', $field . '.city'),
            'countryIso' => (string) $country,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requireString(array $data, string $key, ?string $label = null): string
    {
        $value = $data[$key] ?? null;
        if (!\is_scalar($value) || trim((string) $value) === '') {
            throw new ImportException(sprintf('everysize order has no %s', $label ?? $key));
        }

        return trim((string) $value);
    }

    /**
     * everysize sends ISO 8601 with offset; JTL stores local shop time.
     */
    private function localDate($value): string
    {
        $timestamp = \is_string($value) ? strtotime($value) : false;

        return \date('Y-m-d H:i:s', $timestamp !== false ? $timestamp : time());
    }
}
