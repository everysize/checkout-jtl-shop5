<?php

declare(strict_types=1);

/**
 * Admin-Tab „Bestellungen“: alle mit everysize verknüpften JTL-Bestellungen —
 * 50 pro Seite, Suche (everysize- oder JTL-Nummer) und Filter (alle /
 * versandt gemeldet / offen / storniert). Die aufgeklappte Detailansicht
 * zeigt je Bestellung die drei Abschnitte aus dem Magento-Reiter:
 * everysize-Bestellung (Infotabelle), Erstattung über everysize (Kacheln,
 * Erstattungen, Formular — live von der API für die geöffnete Bestellung)
 * und Rückmeldungen mit übersetzten Werten. Versand und Storno bucht der
 * Händler in der JTL-Wawi (Hinweis oben); Sendungsnummer und Retoure von Hand
 * stehen eingeklappt als „Korrektur, falls nötig“ am Ende.
 * Alle Aktionen sind POST mit dem Session-Token (jtl_token) und einem
 * JavaScript-Confirm; danach folgt ein Redirect auf die eigene Seite (PRG).
 */

// Nur im Shop-Kontext laden (JTL definiert PFAD_ROOT; die Tab-Dateien
// werden per require eingebunden).
if (!\defined('PFAD_ROOT')) {
    exit;
}

use JTL\Helpers\Form;
use JTL\Shop;
use Plugin\everysize_checkout\Api\ApiException;
use Plugin\everysize_checkout\Api\Client;
use Plugin\everysize_checkout\Db\JtlConnection;
use Plugin\everysize_checkout\OrderLinks;
use Plugin\everysize_checkout\PluginInfo;
use Plugin\everysize_checkout\RefundAmount;
use Plugin\everysize_checkout\Sync;

require_once __DIR__ . '/../src/autoload.php';

/** @var \JTL\Plugin\PluginInterface $oPlugin */
/** @var \JTL\Smarty\JTLSmarty $smarty */

$db = new JtlConnection(Shop::Container()->getDB());
$links = new OrderLinks($db);
$token = (string) ($_SESSION['jtl_token'] ?? '');
$gettext = Shop::Container()->getGettext();

/** Plugin-Texte: msgid ist der deutsche Originaltext (siehe locale/<lang>/base.po). */
$t = static fn (string $text): string => $gettext->translatePluginOrCoreMessage(PluginInfo::ID, $text);

$pluginId = (int) ($db->getScalar(
    "SELECT kPlugin FROM tplugin WHERE cPluginID = '" . PluginInfo::ID . "'"
) ?? 0);

/**
 * PRG: eine Aktion verändern, dann zurück auf die eigene Seite. Die Meldung
 * geht über die Session — ein Reload darf nie eine Aktion erneut auslösen.
 *
 * @param array<string, mixed> $params additional query parameters
 */
$redirect = static function (array $params = []) use ($pluginId): never {
    $_SESSION[PluginInfo::ID . '_admin_msg'] = $params['msg'] ?? '';
    unset($params['msg']);
    $url = Shop::getAdminURL() . '/plugin.php?kPlugin=' . $pluginId
        . ($params !== [] ? '&' . http_build_query($params) : '');
    header('Location: ' . $url);

    exit;
};

$action = (string) ($_POST['everysize_orders_action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action !== '') {
    // JTL bindet bei jedem Request ALLE Tab-Dateien ein (Bestellungen zuerst):
    // dieser Block darf nur auf eigene POSTs reagieren — sonst würde er die
    // Formulare der anderen Tabs (jtl_token) mit 403 abwürgen. Eigene POSTs
    // tragen everysize_orders_action und den Session-Token (jtl_token).
    if (!Form::validateToken()) {
        http_response_code(403);

        exit('invalid token');
    }

    $orderId = (int) ($_POST['kBestellung'] ?? 0);
    $link = $orderId > 0 ? $links->getLink($orderId) : null;
    if ($link === null) {
        $redirect(['msg' => $t('Bestellung nicht gefunden oder nicht mit everysize verknüpft.')]);
    }
    $everysizeOrderId = (string) $link->everysize_order_id;

    switch ($action) {
        case 'save_tracking':
            $carrier = trim((string) ($_POST['carrier'] ?? ''));
            $code = trim((string) ($_POST['tracking_code'] ?? ''));
            if ($carrier === '' || $code === '') {
                $redirect(['msg' => $t('Sendungsnummer: Versanddienstleister und Sendungsnummer angeben.'), 'kBestellung' => $orderId]);
            }
            $links->setManualTracking($orderId, $carrier, $code);
            // Der Export-Runner meldet den Code beim nächsten Lauf (Prio:
            // manuelle Eingabe vor Wawi-Lieferschein).
            $redirect([
                'msg' => sprintf($t('Sendungsnummer %s (%s) gespeichert — gemeldet beim nächsten Abgleich.'), $code, $carrier),
                'kBestellung' => $orderId,
            ]);

            // no default

        case 'send_retour':
            // Serverseitig geprüft, nicht nur im Formular versteckt: der
            // Versand muss gemeldet sein (cStatus kann durch ein späteres
            // Storno überschrieben worden sein) und die Bestellung darf an
            // everysize nicht schon widerrufen sein.
            if (!$links->hasSentFulfillment($orderId)) {
                $redirect(['msg' => $t('Retoure: der Versand wurde an everysize noch nicht gemeldet.'), 'kBestellung' => $orderId]);
            }
            if ($links->wasRevoked($orderId)) {
                $redirect(['msg' => $t('Retoure: die Bestellung ist an everysize bereits widerrufen.'), 'kBestellung' => $orderId]);
            }
            $known = $links->getSkus($orderId);
            $skus = array_values(array_filter(
                (array) ($_POST['skus'] ?? []),
                static fn ($sku): bool => \is_string($sku) && \in_array($sku, $known, true)
            ));
            if ($skus === []) {
                $redirect(['msg' => $t('Retoure: keine gültige Position angegeben.'), 'kBestellung' => $orderId]);
            }
            $skus = array_values(array_diff($skus, $links->getReportedSkus($orderId, OrderLinks::KIND_REVOCATION, Client::REASON_RETOUR)));
            if ($skus === []) {
                $redirect(['msg' => $t('Retoure wurde bereits gemeldet (alle gewählten Positionen).'), 'kBestellung' => $orderId]);
            }
            $client = Sync::createClient(Shop::Container()->getDB(), $pluginId);
            $ok = 0;
            $errors = [];
            foreach ($skus as $sku) {
                try {
                    $client->sendRevocation($everysizeOrderId, $sku, Client::REASON_RETOUR);
                    $links->addReport($orderId, OrderLinks::KIND_REVOCATION, $sku, Client::REASON_RETOUR, OrderLinks::RESULT_SENT);
                    ++$ok;
                } catch (ApiException $e) {
                    // Kein Report im Fehlerfall: eine Zeile mit „error: …“
                    // im (Bestellung, Art, SKU)-Schlüssel würde den Retry
                    // blockieren bzw. eine gemeldete Zeile überschreiben.
                    $errors[] = $sku . ': ' . $e->getMessage();
                }
            }
            $msg = match (true) {
                $errors === [] => sprintf($t('Retoure für %d Position(en) an everysize gemeldet.'), $ok),
                $ok > 0 => sprintf($t('Retoure: %d gemeldet, Fehler: %s'), $ok, implode(' | ', $errors)),
                default => $t('Retoure fehlgeschlagen: ') . implode(' | ', $errors),
            };
            $redirect(['msg' => $msg, 'kBestellung' => $orderId]);

            // no default

        case 'refund':
            $amount = RefundAmount::normalize((string) ($_POST['amount'] ?? ''));
            if ($amount === null) {
                $redirect(['msg' => $t('Erstattung: Betrag ungültig (z. B. „24,89“ oder „24.89“).'), 'kBestellung' => $orderId]);
            }
            // Das Formular trägt einen frischen Idempotency-Key je Seitenaufruf:
            // Doppelklick oder erneut gesendetes Formular erreicht denselben
            // Key zweimal — der zweite Lauf wird vor dem API-Aufruf erkannt
            // (gespeicherte Referenz) oder an der API (dieselbe Erstattung).
            $key = (string) ($_POST['everysize_refund_key'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key)) {
                $redirect(['msg' => $t('Erstattung: Formular-Schlüssel ungültig — Seite neu laden und erneut senden.'), 'kBestellung' => $orderId]);
            }
            if (in_array($key, array_column($links->getReports($orderId), 'sku'), true)) {
                $redirect(['msg' => $t('Erstattung wurde bereits ausgelöst (gleicher Vorgang).'), 'kBestellung' => $orderId]);
            }
            $client = Sync::createClient(Shop::Container()->getDB(), $pluginId);
            try {
                $refund = $client->refund($everysizeOrderId, $amount, $key);
            } catch (ApiException $e) {
                $redirect(['msg' => $t('Erstattung fehlgeschlagen: ') . $e->getMessage(), 'kBestellung' => $orderId]);
            }
            $status = strtoupper((string) ($refund['status'] ?? ''));
            // everysize answers a capture that is refunded completely already
            // with 201 and this status, no refund ID (like JTL does).
            if ($status === 'ALREADY_REFUNDED') {
                $redirect(['msg' => $t('Diese Bestellung ist bereits vollständig erstattet.'), 'kBestellung' => $orderId]);
            }
            $refundId = (string) ($refund['refundId'] ?? '');
            $reference = $refundId !== '' ? $refundId : $key;
            // The same idempotency key again (form sent twice) returns the
            // same refund: record and announce it once (like JTL does).
            if (in_array($reference, array_column($links->getReports($orderId), 'sku'), true)) {
                $redirect(['msg' => $t('Erstattung wurde bereits gemeldet (gleicher Vorgang).'), 'kBestellung' => $orderId]);
            }
            $links->addReport($orderId, OrderLinks::KIND_REFUND, $reference, (string) $amount, OrderLinks::RESULT_SENT);
            $redirect([
                'msg' => sprintf($t('Erstattung über %s an everysize gemeldet (Status %s).'), number_format((float) $amount, 2, ',', '.') . ' €', $status !== '' ? $status : $t('offen')),
                'kBestellung' => $orderId,
            ]);

            // no default

        case 'sync':
            $result = Sync::create(Shop::Container()->getDB(), $pluginId)->run();
            $msg = implode(' — ', array_merge($result['messages'], array_map(
                static fn (string $error): string => 'FEHLER: ' . $error,
                $result['errors']
            )));
            $redirect(['msg' => $msg !== '' ? $msg : $t('Abgleich abgeschlossen, nichts zu tun.')]);

            // no default

        default:
            $redirect(['msg' => $t('Unbekannte Aktion.')]);
    }
}

$message = (string) ($_SESSION[PluginInfo::ID . '_admin_msg'] ?? '');
unset($_SESSION[PluginInfo::ID . '_admin_msg']);

// ---------------------------------------------------------------------------
// Liste: 50 pro Seite, Suche, Filter — die Detaildaten laden nur für die
// angezeigten Bestellungen (früher: ganze Tabellen).
// ---------------------------------------------------------------------------
const EVERYSIZE_PER_PAGE = 50;

$page = max(1, (int) ($_GET['p'] ?? 1));
$search = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['f'] ?? 'all');

$where = [];
$queryParams = [];
if ($search !== '') {
    $where[] = '(l.everysize_order_id LIKE :search OR b.cBestellNr LIKE :search'
        . (ctype_digit($search) ? ' OR l.kBestellung = :searchId' : '') . ')';
    $queryParams['search'] = '%' . $search . '%';
    if (ctype_digit($search)) {
        $queryParams['searchId'] = (int) $search;
    }
}
$fulfillmentExists = "EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report ff
    WHERE ff.kBestellung = l.kBestellung AND ff.kind = '" . OrderLinks::KIND_FULFILLMENT . "' AND ff.result = '" . OrderLinks::RESULT_SENT . "')";
switch ($filter) {
    case 'sent':
        $where[] = $fulfillmentExists;
        break;
    case 'open':
        $where[] = 'NOT ' . $fulfillmentExists;
        break;
    case 'storno':
        $where[] = 'b.cStatus = ' . OrderLinks::STATUS_STORNO;
        break;
}
$whereSql = $where === [] ? '1' : implode(' AND ', $where);

$total = (int) ($db->getScalar(
    "SELECT COUNT(*) FROM xplugin_everysize_checkout_order l
     JOIN tbestellung b ON b.kBestellung = l.kBestellung
     WHERE $whereSql",
    $queryParams
) ?? 0);
$pages = max(1, (int) ceil($total / EVERYSIZE_PER_PAGE));
$page = min($page, $pages);
$offset = ($page - 1) * EVERYSIZE_PER_PAGE;

$orders = $db->getObjects(
    "SELECT l.kBestellung, l.everysize_order_id, l.paypal_capture_id, l.original_email,
            l.number_reported, l.created_at AS linked_at,
            b.cBestellNr, b.fGesamtsumme, w.cISO AS cWaehrung, b.cStatus, b.dVersandDatum, TRIM(b.cTracking) AS cTracking,
            EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report ff
                    WHERE ff.kBestellung = l.kBestellung AND ff.kind = '" . OrderLinks::KIND_FULFILLMENT . "' AND ff.result = '" . OrderLinks::RESULT_SENT . "') AS fulfillment_sent,
            EXISTS (SELECT 1 FROM xplugin_everysize_checkout_report rv
                    WHERE rv.kBestellung = l.kBestellung AND rv.kind = '" . OrderLinks::KIND_REVOCATION . "'
                      AND rv.reason <> '" . Client::REASON_RETOUR . "' AND rv.result = '" . OrderLinks::RESULT_SENT . "') AS revoked
     FROM xplugin_everysize_checkout_order l
     JOIN tbestellung b ON b.kBestellung = l.kBestellung
     LEFT JOIN twaehrung w ON w.kWaehrung = b.kWaehrung
     WHERE $whereSql
     ORDER BY l.kBestellung DESC
     LIMIT " . EVERYSIZE_PER_PAGE . ' OFFSET ' . $offset,
    $queryParams
);

$displayedIds = array_map(static fn (object $order): int => (int) $order->kBestellung, $orders);
$idsSql = $displayedIds === [] ? '0' : implode(',', $displayedIds);

/** @var array<int, array<int, object>> order id => positions */
$positionsByOrder = [];
foreach ($db->getObjects(
    "SELECT i.kBestellung, i.sku AS item_sku, p.kWarenkorbpos, p.cArtNr, p.cName, p.nAnzahl, p.fPreis, p.fMwSt, p.nPosTyp
     FROM xplugin_everysize_checkout_item i
     JOIN twarenkorbpos p ON p.kWarenkorbpos = i.kWarenkorbPos
     WHERE i.kBestellung IN ($idsSql)
     ORDER BY p.kWarenkorbpos"
) as $row) {
    $positionsByOrder[(int) $row->kBestellung][] = $row;
}

/** @var array<int, array<int, object>> order id => reports */
$reportsByOrder = [];
foreach ($db->getObjects(
    "SELECT kBestellung, kind, sku, reason, result, created_at FROM xplugin_everysize_checkout_report
     WHERE kBestellung IN ($idsSql) ORDER BY kReport"
) as $row) {
    $reportsByOrder[(int) $row->kBestellung][] = $row;
}

/** @var array<int, object{carrier: string, tracking_code: string}> order id => manual tracking */
$trackingByOrder = [];
foreach ($db->getObjects(
    "SELECT kBestellung, carrier, tracking_code FROM xplugin_everysize_checkout_tracking
     WHERE kBestellung IN ($idsSql)"
) as $row) {
    $trackingByOrder[(int) $row->kBestellung] = $row;
}

// ---------------------------------------------------------------------------
// Die geöffnete Bestellung (kBestellung) bekommt die Erstattungsdaten live
// von der API — für die übrigen bleibt es beim lokalen Stand (keine
// API-Aufrufe pro Listenzeile).
// ---------------------------------------------------------------------------
$selected = (int) ($_GET['kBestellung'] ?? 0);
if (!in_array($selected, $displayedIds, true)) {
    $selected = 0;
}
/** PayPal answers with its own zone (2026-10-15T05:28:27-07:00): show it in the shop's time zone like everything else. */
$localTime = static function (string $value): string {
    try {
        return $value !== ''
            ? (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s')
            : '';
    } catch (\Exception) {
        return $value;
    }
};
$refundInfo = null;
if ($selected > 0 && (string) $links->getLink($selected)->paypal_capture_id !== '') {
    $selectedLink = $links->getLink($selected);
    $selectedOrder = $db->getSingleObject(
        'SELECT b.fGesamtsumme, w.cISO AS cWaehrung FROM tbestellung b LEFT JOIN twaehrung w ON w.kWaehrung = b.kWaehrung WHERE b.kBestellung = :id',
        ['id' => $selected]
    );
    $paid = (float) ($selectedOrder->fGesamtsumme ?? 0);
    $currency = (string) ($selectedOrder->cWaehrung ?? 'EUR');
    try {
        $refunds = [];
        $refunded = 0.0;
        foreach (Sync::createClient(Shop::Container()->getDB(), $pluginId)
            ->getRefunds((string) $selectedLink->everysize_order_id) as $refund) {
            $amount = (float) str_replace(',', '', (string) ($refund['amount'] ?? '0'));
            $status = strtoupper((string) ($refund['status'] ?? ''));
            if (in_array($status, ['COMPLETED', 'PENDING'], true)) {
                $refunded += $amount;
            }
            $refunds[] = [
                'created' => $localTime((string) ($refund['created'] ?? '')),
                'amount' => $amount,
                'status' => $status,
                'refundId' => (string) ($refund['refundId'] ?? ''),
            ];
        }
        $refundInfo = [
            'error' => '',
            'refunds' => $refunds,
            'paid' => $paid,
            'refunded' => $refunded,
            'open' => max(0.0, round($paid - $refunded, 2)),
            'currency' => $currency,
        ];
    } catch (ApiException $e) {
        $refundInfo = ['error' => $e->getMessage()];
    }
}

$statusText = static fn ($status): string => match ((string) $status) {
    '-1' => $t('Storno'),
    '1' => $t('Offen'),
    '2' => $t('In Bearbeitung'),
    '3' => $t('Bezahlt'),
    '4' => $t('Versandt'),
    '5' => $t('Teilversandt'),
    default => (string) $status,
};

$adminURL = Shop::getAdminURL();

/** Money with the order currency: „1.234,50 €“ (other currencies with their ISO code). */
$money = static fn (float $amount, string $currency): string => number_format($amount, 2, ',', '.')
    . ' ' . (\in_array(strtoupper($currency), ['', 'EUR'], true) ? '€' : strtoupper($currency));

/**
 * Rückmeldungen als Tabellenzeilen mit übersetzten Werten — keine rohen
 * Codes (fulfillment/sent), nichts abgeschnitten, Leeres als „–“.
 *
 * @param object $report {kind, sku, reason, result, created_at}
 * @param string $currency order currency for refund amounts
 * @return array{time: string, kind: string, subject: string, detail: string, result: string}
 */
$reportRow = static function (object $report, string $currency) use ($t, $money): array {
    $kind = (string) $report->kind;
    $sku = (string) $report->sku;
    $reason = (string) $report->reason;
    $result = (string) $report->result;

    $label = match ($kind) {
        OrderLinks::KIND_FULFILLMENT => $t('Versand'),
        OrderLinks::KIND_TRACKING => $t('Sendungsnummer'),
        OrderLinks::KIND_REVOCATION => $reason === Client::REASON_RETOUR ? $t('Retoure') : $t('Storno'),
        OrderLinks::KIND_REFUND => $sku === OrderLinks::AUTO_REFUND ? $t('Automatische Erstattung') : $t('Erstattung'),
        default => $kind,
    };
    // subject = Artikel/Sendungsnummer/Erstattung; detail = Grund/Betrag.
    if ($kind === OrderLinks::KIND_REFUND) {
        if ($sku === OrderLinks::AUTO_REFUND && $result === OrderLinks::RESULT_SENT) {
            // automatic refund: reason holds the refund ID, the amount is the full remainder
            $subject = $reason !== '' ? $reason : '–';
            $detail = $t('voller Betrag');
        } elseif ($sku === OrderLinks::AUTO_REFUND) {
            // not sent: reason holds the status (ALREADY_REFUNDED) or the refusal
            $subject = $t('automatisch');
            $detail = $reason !== '' ? $reason : '–';
        } else {
            $subject = $sku;
            $detail = is_numeric($reason) ? $money((float) $reason, $currency) : ($reason !== '' ? $reason : '–');
        }
    } else {
        $subject = $sku !== '' ? $sku : '–';
        $detail = match ($reason) {
            Client::REASON_MERCHANT_DECLINE => $t('Vom Händler abgelehnt'),
            Client::REASON_CUSTOMER_REVOKE => $t('Vom Kunden widerrufen'),
            Client::REASON_RETOUR => $t('Retoure'),
            '' => '–',
            default => $reason,
        };
    }
    $resultText = match ($result) {
        OrderLinks::RESULT_SENT => $t('gemeldet'),
        OrderLinks::RESULT_SKIPPED => $t('übersprungen'),
        OrderLinks::RESULT_KNOWN => $t('bekannt'),
        '' => '–',
        default => $result,
    };

    return [
        'time' => (string) $report->created_at,
        'kind' => $label,
        'subject' => $subject,
        'detail' => $detail,
        'result' => $resultText,
        'resultRaw' => $result,
    ];
};

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
?>
<?php if ($message !== '') : ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= $e($message) ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Schließen"><span>&times;</span></button>
    </div>
<?php endif; ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
        <h5 class="mb-0"><?= $t('everysize-Bestellungen') ?> <small class="text-muted">(<?= $total ?>)</small></h5>
        <form method="post" action="" class="form-inline" onsubmit="return confirm('<?= $t('Abgleich jetzt ausführen?') ?>')">
            <input type="hidden" name="jtl_token" value="<?= $e($token) ?>">
            <input type="hidden" name="everysize_orders_action" value="sync">
            <button type="submit" class="btn btn-sm btn-outline-primary"><?= $t('Jetzt abgleichen') ?></button>
        </form>
    </div>
    <div class="card-header bg-white py-2">
        <form method="get" action="" class="form-inline">
            <input type="search" name="q" class="form-control form-control-sm mr-2" style="min-width: 14rem" placeholder="<?= $t('everysize- oder JTL-Nummer suchen') ?>" value="<?= $e($search) ?>">
            <select name="f" class="custom-select custom-select-sm mr-2" onchange="this.form.submit()">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>><?= $t('alle') ?></option>
                <option value="sent" <?= $filter === 'sent' ? 'selected' : '' ?>><?= $t('versandt gemeldet') ?></option>
                <option value="open" <?= $filter === 'open' ? 'selected' : '' ?>><?= $t('Versand offen') ?></option>
                <option value="storno" <?= $filter === 'storno' ? 'selected' : '' ?>><?= $t('storniert') ?></option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary mr-2"><?= $t('Suchen') ?></button>
            <?php if ($search !== '' || $filter !== 'all') : ?>
                <a class="btn btn-sm btn-link" href="<?= $e($adminURL . '/plugin.php?kPlugin=' . $pluginId) ?>"><?= $t('zurücksetzen') ?></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if ($orders === []) : ?>
            <p class="p-3 mb-0 text-muted"><?= $t('Keine everysize-Bestellungen gefunden.') ?></p>
        <?php else : ?>
            <table class="table table-striped table-hover mb-0">
                <thead>
                <tr>
                    <th><?= $t('JTL-Bestellung') ?></th>
                    <th><?= $t('everysize-Bestellung') ?></th>
                    <th><?= $t('Summe') ?></th>
                    <th><?= $t('Status') ?></th>
                    <th><?= $t('Versand') ?></th>
                    <th><?= $t('Bestellnummer') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order) :
                    $id = (int) $order->kBestellung;
                    $currency = (string) $order->cWaehrung;
                    $open = $id === $selected; ?>
                    <tr class="<?= $open ? 'table-primary' : '' ?>">
                        <td>
                            <a href="<?= $e($adminURL . '/plugin.php?kPlugin=' . $pluginId . '&kBestellung=' . $id . '&p=' . $page . '&f=' . urlencode($filter) . '&q=' . urlencode($search)) ?>">
                                <strong><?= $e($order->cBestellNr) ?></strong>
                            </a>
                            <small class="text-muted d-block">#<?= $id ?></small>
                        </td>
                        <td><code><?= $e($order->everysize_order_id) ?></code></td>
                        <td><?= $e($money((float) $order->fGesamtsumme, $currency)) ?></td>
                        <td><?= $e($statusText($order->cStatus)) ?></td>
                        <td>
                            <?php if ((int) $order->fulfillment_sent === 1) : ?>
                                <span class="text-success"><?= $t('gemeldet') ?></span>
                            <?php else : ?>
                                <span class="text-muted"><?= $t('offen') ?></span>
                            <?php endif; ?>
                            <?php if ($order->dVersandDatum !== null) : ?>
                                <small class="text-muted d-block"><?= $e($order->dVersandDatum) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $order->number_reported !== null
                            ? '<span class="text-success">' . $e($t('ja')) . '</span>'
                            : '<span class="text-danger">' . $e($t('nein')) . '</span>' ?></td>
                    </tr>
                    <?php if ($open) : ?>
                    <tr class="detail-row">
                        <td colspan="6" class="bg-light">
                                <?php
                                /** @var array<int, object{item_sku: string, cArtNr: string, cName: string, nAnzahl: string|int, fPreis: float, fMwSt: float}> $positions */
                                $positions = $positionsByOrder[$id] ?? [];
                                /** @var array<int, object{kind: string, sku: string, reason: string, result: string, created_at: string}> $reports */
                                $reports = $reportsByOrder[$id] ?? [];
                                $manual = $trackingByOrder[$id] ?? null;
                                ?>
                                <!-- (1) everysize-Bestellung: eine Infotabelle -->
                                <h6 class="mt-2 mb-2"><?= $t('everysize-Bestellung') ?></h6>
                                <table class="table table-sm w-auto mb-3" style="min-width: 34rem">
                                    <tbody>
                                    <tr>
                                        <th class="font-weight-bold" style="width: 14rem"><?= $t('everysize-Bestellnummer') ?></th>
                                        <td><strong><code><?= $e($order->everysize_order_id) ?></code></strong></td>
                                    </tr>
                                    <tr>
                                        <th><?= $t('Importiert') ?></th>
                                        <td><?= $e($order->linked_at) ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= $t('Bestellnummer gemeldet') ?></th>
                                        <td><?= $order->number_reported !== null
                                            ? $e($t('ja') . ', ' . $order->number_reported)
                                            : '<span class="text-muted">' . $e($t('noch nicht')) . '</span>' ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= $t('PayPal-Transaktion') ?></th>
                                        <td><?= (string) $order->paypal_capture_id !== ''
                                            ? '<code>' . $e($order->paypal_capture_id) . '</code>'
                                            : '<span class="text-muted">–</span>' ?></td>
                                    </tr>
                                    <tr>
                                        <th><?= $t('Original-E-Mail') ?></th>
                                        <td><?= (string) $order->original_email !== ''
                                            ? $e($order->original_email) . ' <small class="text-muted">(' . $e($t('vom Checkout übermittelt, informativ')) . ')</small>'
                                            : '<span class="text-muted">–</span>' ?></td>
                                    </tr>
                                    </tbody>
                                </table>

                                <!-- (2) Versand und Storno: Normalfall Wawi -->
                                <div class="alert alert-info small py-2 mb-3" style="max-width: 48rem">
                                    <strong><?= $t('Versand, Sendungsnummer und Storno buchen Sie wie gewohnt in der JTL-Wawi.') ?></strong>
                                    <?= $t('Sobald die Wawi mit dem Shop abgeglichen hat, meldet das Plugin es automatisch an everysize (alle 5 Minuten): Versand mit Sendungsnummer, Storno vor dem Versand als Händler-Storno, Storno nach dem Versand als Retoure.') ?>
                                    <?= $t('Nur die Erstattung lösen Sie hier aus — Gutschriften in der Wawi bewegen kein Geld.') ?>
                                </div>

                                <!-- (3) Erstattung über everysize -->
                                <h6 class="mt-3 mb-2"><?= $t('Erstattung über everysize') ?></h6>
                                <?php if ((string) $order->paypal_capture_id === '') : ?>
                                    <p class="small text-muted mb-2"><?= $t('Keine PayPal-Capture-ID — diese Bestellung kann nicht über everysize erstattet werden.') ?></p>
                                <?php else : ?>
                                    <?php if ($refundInfo === null || ($refundInfo['error'] ?? '') !== '') : ?>
                                        <div class="alert alert-light border small py-2">
                                            <?= $refundInfo !== null
                                                ? $e($t('Erstattungsstatus gerade nicht abrufbar') . ': ' . ($refundInfo['error'] ?? ''))
                                                : $e($t('Erstattungsstatus wird beim Öffnen der Bestellung geladen.')) ?>
                                        </div>
                                    <?php else : ?>
                                        <?php
                                        $ri = $refundInfo;
                                        ?>
                                        <div class="d-flex flex-wrap mb-2">
                                            <div class="border bg-white p-2 mr-3 mb-2" style="min-width: 9rem">
                                                <small class="text-muted d-block"><?= $t('Bezahlt') ?></small>
                                                <strong><?= $e($money($ri['paid'], $ri['currency'])) ?></strong>
                                            </div>
                                            <div class="border bg-white p-2 mr-3 mb-2" style="min-width: 9rem">
                                                <small class="text-muted d-block"><?= $t('erstattet') ?></small>
                                                <strong><?= $e($money($ri['refunded'], $ri['currency'])) ?></strong>
                                            </div>
                                            <div class="border <?= $ri['open'] > 0 ? 'bg-white' : 'bg-light text-muted' ?> p-2 mb-2" style="min-width: 9rem">
                                                <small class="text-muted d-block"><?= $t('noch offen') ?></small>
                                                <strong><?= $e($money($ri['open'], $ri['currency'])) ?></strong>
                                            </div>
                                        </div>
                                        <?php if ($ri['refunds'] !== []) : ?>
                                            <table class="table table-sm w-auto mb-2" style="min-width: 30rem">
                                                <thead><tr><th><?= $t('Datum') ?></th><th><?= $t('Betrag') ?></th><th><?= $t('Status') ?></th><th><?= $t('Erstattungs-ID') ?></th></tr></thead>
                                                <tbody>
                                                <?php foreach ($ri['refunds'] as $refund) : ?>
                                                    <tr>
                                                        <td><?= $e($refund['created']) ?></td>
                                                        <td><?= $e($money((float) $refund['amount'], $ri['currency'])) ?></td>
                                                        <td><?= $e($refund['status'] !== '' ? $refund['status'] : '–') ?></td>
                                                        <td><code><?= $e($refund['refundId'] !== '' ? $refund['refundId'] : '–') ?></code></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php else : ?>
                                            <p class="small text-muted mb-2"><?= $t('Noch keine Erstattungen.') ?></p>
                                        <?php endif; ?>
                                        <form method="post" action="" class="form-inline" onsubmit="return confirm('<?= $t('Erstattung über everysize (PayPal) auslösen?') ?>')">
                                            <input type="hidden" name="jtl_token" value="<?= $e($token) ?>">
                                            <input type="hidden" name="everysize_orders_action" value="refund">
                                            <input type="hidden" name="kBestellung" value="<?= $id ?>">
                                            <input type="hidden" name="everysize_refund_key" value="<?= $e(bin2hex(random_bytes(16))) ?>">
                                            <div class="form-group mb-1">
                                                <label class="small mb-0 mr-2" for="amount-<?= $id ?>"><?= $t('Betrag') ?></label>
                                                <input type="text" id="amount-<?= $id ?>" name="amount" class="form-control form-control-sm" value="<?= $ri['open'] > 0 ? $e(number_format($ri['open'], 2, ',', '.')) : '' ?>" placeholder="<?= $ri['open'] > 0 ? $e($t('Betrag in Euro')) : '' ?>">
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary mb-1 ml-2" <?= $ri['open'] > 0 ? '' : 'disabled' ?>><?= $t('Erstatten') ?></button>
                                        </form>
                                        <p class="small text-muted mb-2 mt-1"><?= $t('Die Erstattung läuft über everysize (PayPal) — Gutschriften in JTL-Wawi bewegen kein Geld. Der Restbetrag ist vorbelegt. Ein Storno vor dem Versand ohne bisherige Erstattung wird automatisch voll erstattet (Einstellung „Automatische Erstattung“).') ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- (4) Rückmeldungen an everysize -->
                                <h6 class="mt-3 mb-2"><?= $t('Rückmeldungen an everysize') ?></h6>
                                <table class="table table-sm w-auto mb-3" style="min-width: 40rem">
                                    <thead>
                                    <tr>
                                        <th><?= $t('Zeit') ?></th>
                                        <th><?= $t('Meldung') ?></th>
                                        <th><?= $t('Artikel/Sendungsnummer/Erstattung') ?></th>
                                        <th><?= $t('Grund/Betrag') ?></th>
                                        <th><?= $t('Ergebnis') ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($reports as $report) :
                                        $row = $reportRow($report, $currency); ?>
                                        <tr>
                                            <td><small><?= $e($row['time']) ?></small></td>
                                            <td><?= $e($row['kind']) ?></td>
                                            <td><small><code><?= $e($row['subject']) ?></code></small></td>
                                            <td><small><?= $e($row['detail']) ?></small></td>
                                            <td><small class="<?= ($row['resultRaw'] !== '' && $row['resultRaw'] !== OrderLinks::RESULT_SENT) ? 'text-muted' : '' ?>"><?= $e($row['result']) ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($reports === []) : ?>
                                        <tr><td colspan="5" class="text-muted"><?= $t('noch nichts gemeldet') ?></td></tr>
                                    <?php endif; ?>
                                    </tbody>
                                </table>

                                <!-- (5) Korrekturen: nur nötig, wenn die Wawi etwas nicht liefert -->
                                <?php
                                $retourSkus = [];
                                foreach ($reports as $report) {
                                    if ($report->kind === OrderLinks::KIND_REVOCATION && $report->reason === Client::REASON_RETOUR
                                        && $report->result === OrderLinks::RESULT_SENT) {
                                        $retourSkus[] = $report->sku;
                                    }
                                }
                                $retourOpen = array_values(array_filter(
                                    $positions,
                                    static fn (object $position): bool => !\in_array($position->item_sku, $retourSkus, true)
                                ));
                                ?>
                                <details class="mb-3" style="max-width: 48rem"<?= $manual !== null ? ' open' : '' ?>>
                                    <summary class="small font-weight-bold"><?= $t('Korrektur, falls nötig: Sendungsnummer oder Retoure von Hand melden') ?></summary>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <h6 class="mb-1"><?= $t('Sendungsnummer von Hand') ?></h6>
                                            <p class="small text-muted mb-2"><?= $t('Nur wenn die Wawi keine oder eine falsche Sendungsnummer liefert. Eine hier gespeicherte Nummer hat Vorrang vor der aus dem Wawi-Lieferschein und gilt als Versand.') ?></p>
                                            <form method="post" action="" onsubmit="return confirm('<?= $t('Sendungsnummer speichern und beim nächsten Abgleich melden?') ?>')">
                                                <input type="hidden" name="jtl_token" value="<?= $e($token) ?>">
                                                <input type="hidden" name="everysize_orders_action" value="save_tracking">
                                                <input type="hidden" name="kBestellung" value="<?= $id ?>">
                                                <div class="form-row align-items-end">
                                                    <div class="form-group col-5 mb-1">
                                                        <label class="small mb-0" for="carrier-<?= $id ?>"><?= $t('Versanddienstleister') ?></label>
                                                        <input type="text" id="carrier-<?= $id ?>" name="carrier" class="form-control form-control-sm" placeholder="DHL" value="<?= $e($manual->carrier ?? '') ?>" maxlength="31" required>
                                                    </div>
                                                    <div class="form-group col-7 mb-1">
                                                        <label class="small mb-0" for="code-<?= $id ?>"><?= $t('Sendungsnummer') ?></label>
                                                        <input type="text" id="code-<?= $id ?>" name="tracking_code" class="form-control form-control-sm" value="<?= $e($manual->tracking_code ?? '') ?>" maxlength="255" required>
                                                    </div>
                                                    <div class="form-group col-12 mb-1">
                                                        <button type="submit" class="btn btn-sm btn-primary"><?= $t('Speichern') ?></button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="mb-1"><?= $t('Retoure von Hand') ?></h6>
                                            <p class="small text-muted mb-2"><?= $t('Nur wenn eine Rücksendung in der Wawi nicht als Storno gebucht wird — ein Storno nach dem Versand meldet das Plugin selbst als Retoure.') ?></p>
                                            <?php if ((int) $order->revoked === 1) : ?>
                                                <p class="small text-muted mb-2"><?= $t('Die Bestellung ist an everysize bereits widerrufen — eine Retoure ist nicht mehr möglich.') ?></p>
                                            <?php elseif ((int) $order->fulfillment_sent !== 1) : ?>
                                                <p class="small text-muted mb-2"><?= $t('Erst verfügbar, wenn der Versand an everysize gemeldet wurde — vorher ist es ein Storno über die Wawi.') ?></p>
                                            <?php elseif ($retourOpen === []) : ?>
                                                <p class="small text-muted mb-2"><?= $t('Die Retoure ist für alle Positionen bereits gemeldet.') ?></p>
                                            <?php else : ?>
                                                <form method="post" action="" onsubmit="return confirm('<?= $t('Retoure für die gewählten Positionen an everysize melden?') ?>')">
                                                    <input type="hidden" name="jtl_token" value="<?= $e($token) ?>">
                                                    <input type="hidden" name="everysize_orders_action" value="send_retour">
                                                    <input type="hidden" name="kBestellung" value="<?= $id ?>">
                                                    <?php foreach ($retourOpen as $position) : ?>
                                                        <div class="form-check">
                                                            <label class="form-check-label">
                                                                <input type="checkbox" name="skus[]" class="form-check-input" value="<?= $e($position->item_sku) ?>" checked>
                                                                <small><?= $e($position->cArtNr !== '' ? $position->cArtNr : $position->item_sku) ?> — <?= $e($position->cName) ?></small>
                                                            </label>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-warning mt-1"><?= $t('Retoure melden (RETOUR)') ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </details>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1) : ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <small class="text-muted"><?= sprintf($t('Seite %1$d von %2$d — %3$d Bestellungen'), $page, $pages, $total) ?></small>
            <div>
                <?php
                $linkFor = static fn (int $p): string => $adminURL . '/plugin.php?kPlugin=' . $pluginId
                    . '&p=' . $p . '&f=' . urlencode($filter) . '&q=' . urlencode($search)
                    . ($selected > 0 ? '&kBestellung=' . $selected : ''); ?>
                <?php if ($page > 1) : ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $e($linkFor($page - 1)) ?>">&laquo; <?= $t('zurück') ?></a>
                <?php endif; ?>
                <?php if ($page < $pages) : ?>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $e($linkFor($page + 1)) ?>"><?= $t('weiter') ?> &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
