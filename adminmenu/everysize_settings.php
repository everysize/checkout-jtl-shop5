<?php

declare(strict_types=1);

/**
 * Admin-Tab „Einstellungen“: everysize-API-Zugangsdaten (mit Verbindungstest
 * und „entfernen“), Carrier, Import-Bestellstatus, Abgleich-Schalter,
 * Auto-Erstattung, Bestellbestätigung, Carrier-Zuordnung und Zeitsteuerung
 * (Webcron-URL mit Token und Header-Hinweis, CLI-Befehl).
 *
 * Wird vom PluginController mit $oPlugin und $smarty im Scope geladen.
 * Das Client-Secret wird nie aus der Datenbank zurückgegeben: das Feld zeigt
 * nur einen Platzhalter, gespeichert wird nur ein nicht-leerer Wert.
 */

// Nur im Shop-Kontext laden (JTL definiert PFAD_ROOT; die Tab-Dateien
// werden per require eingebunden).
if (!\defined('PFAD_ROOT')) {
    exit;
}

use JTL\Alert\Alert;
use JTL\Helpers\Form;
use JTL\Shop;
use Plugin\everysize_checkout\Api\ApiUrl;
use Plugin\everysize_checkout\Api\Client;
use Plugin\everysize_checkout\Api\CurlTransport;
use Plugin\everysize_checkout\Bootstrap;
use Plugin\everysize_checkout\Db\JtlConnection;
use Plugin\everysize_checkout\PluginInfo;
use Plugin\everysize_checkout\Settings;

require_once __DIR__ . '/../src/autoload.php';

/** @var \JTL\Plugin\PluginInterface $oPlugin */
/** @var \JTL\Smarty\JTLSmarty $smarty */

$db = new JtlConnection(Shop::Container()->getDB());
$settings = new Settings($db);
$alertService = Shop::Container()->getAlertService();
$gettext = Shop::Container()->getGettext();

/** Plugin-Texte: msgid ist der deutsche Originaltext (siehe locale/<lang>/base.po). */
$t = static fn (string $text): string => $gettext->translatePluginOrCoreMessage(PluginInfo::ID, $text);

$testResult = null;
if (Form::validateToken()) {
    $action = (string) ($_POST['everysize_action'] ?? '');
    switch ($action) {
        case 'save':
            $clientId = trim((string) ($_POST['everysize_client_id'] ?? ''));
            $clientSecret = trim((string) ($_POST['everysize_client_secret'] ?? ''));
            if ($clientId !== '') {
                $settings->set(Settings::CLIENT_ID, $clientId);
            }
            if ($clientSecret !== '') {
                $settings->set(Settings::CLIENT_SECRET, $clientSecret);
            }
            $carrier = trim((string) ($_POST['everysize_carrier'] ?? ''));
            if ($carrier !== '') {
                $settings->set(Settings::CARRIER, $carrier);
            }
            $settings->setAutoRefundDecline(isset($_POST['everysize_auto_refund']));
            $settings->set(Settings::SEND_ORDER_CONFIRMATION, isset($_POST['everysize_send_confirmation']) ? '1' : '0');
            $settings->set(Settings::SHIPPING_METHOD, (string) (int) ($_POST['everysize_shipping_method'] ?? ''));
            $settings->set(Settings::SYNC_ENABLED, isset($_POST['everysize_sync_enabled']) ? '1' : '0');
            $settings->set(Settings::IMPORT_ORDER_STATUS, (string) max(1, (int) ($_POST['everysize_import_status'] ?? Settings::DEFAULT_IMPORT_ORDER_STATUS)));
            $mapping = trim((string) ($_POST['everysize_carrier_mapping'] ?? ''));
            if ($mapping === '') {
                $settings->set(Settings::CARRIER_MAPPING, '');
            } else {
                $decoded = json_decode($mapping, true);
                if (is_array($decoded)) {
                    $settings->set(Settings::CARRIER_MAPPING, (string) json_encode($decoded, JSON_UNESCAPED_UNICODE));
                    $mapping = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                } else {
                    // Ungültiges JSON: nur die Fehlermeldung, kein „gespeichert“.
                    $alertService->addAlert(Alert::TYPE_ERROR, $t('Carrier-Zuordnung ist kein gültiges JSON — der bisherige Wert bleibt gespeichert.'), 'everysizeMappingError');
                    $mappingValid = false;
                }
            }
            if (($mappingValid ?? true) === true) {
                $alertService->addAlert(Alert::TYPE_SUCCESS, $t('Einstellungen gespeichert.'), 'everysizeSaved');
            }
            break;

        case 'test_connection':
            // Leeres/Platzhalter-Secret: der gespeicherte Wert gilt weiter.
            $clientId = trim((string) ($_POST['everysize_client_id'] ?: $settings->getClientId()));
            $clientSecret = trim((string) ($_POST['everysize_client_secret'] ?: $settings->getClientSecret()));
            if ($clientId === '' || $clientSecret === '') {
                $alertService->addAlert(Alert::TYPE_ERROR, $t('Bitte zuerst Client-ID und Client-Secret eintragen.'), 'everysizeTestNoCredentials');
            } else {
                try {
                    // Das Backend antwortet dem Browser: kurze Timeouts (3/5 s).
                    $client = new Client($clientId, $clientSecret, '', APPLICATION_VERSION, PluginInfo::VERSION, new CurlTransport(3, 5));
                    $tokenResponse = $client->requestToken($clientId, $clientSecret);
                    $testResult = [
                        'ok' => true,
                        'text' => sprintf($t('Verbindung OK — everysize-Shop-ID: %d (API: %s)'), $tokenResponse['shop_id'], $client->getApiUrl()),
                    ];
                } catch (Throwable $e) {
                    $testResult = ['ok' => false, 'text' => $t('Verbindung fehlgeschlagen: ') . $e->getMessage()];
                }
            }
            break;

        case 'remove_credentials':
            $settings->removeCredentials();
            $alertService->addAlert(Alert::TYPE_SUCCESS, $t('Zugangsdaten entfernt — der Abgleich ruht, bis neue eingetragen sind.'), 'everysizeCredentialsRemoved');
            break;
    }
}

$env = ApiUrl::describe();
$envBadge = match ($env['mode']) {
    'production' => '<span class="badge bg-success">' . $t('Produktumgebung') . '</span>',
    'test' => '<span class="badge bg-warning text-dark">' . $t('Testumgebung') . ': ' . htmlspecialchars($env['url'], ENT_QUOTES) . '</span>',
    default => '<span class="badge bg-danger">' . $t('Ungültige API-URL') . ': ' . htmlspecialchars($env['url'], ENT_QUOTES) . '</span>',
};

$carrier = $settings->getCarrier();
$carriers = Settings::CARRIERS;
if (!in_array($carrier, $carriers, true) && $carrier !== '') {
    $carriers[] = $carrier;
}
$mappingJson = $settings->get(Settings::CARRIER_MAPPING);
$decoded = json_decode($mappingJson, true);
if (is_array($decoded) && $decoded !== []) {
    $mappingJson = (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$shippingMethods = $db->getObjects('SELECT kVersandart, cName FROM tversandart ORDER BY nSort, cName');
$token = (string) ($_SESSION['jtl_token'] ?? '');

/** Import-Bestellstatus: die JTL-Statuswerte wie im Bestell-Tab. */
$importStatuses = [
    1 => $t('Offen'),
    2 => $t('In Bearbeitung'),
    3 => $t('Bezahlt'),
    4 => $t('Versandt'),
    5 => $t('Teilversandt'),
];
$importStatus = $settings->getImportOrderStatus();

/** Zeitsteuerung: Webcron-URL (Token als Query-Parameter oder im Header) + CLI. */
$webcronUrl = Shop::getURL() . Bootstrap::WEBCRON_PATH . '?token=' . $settings->get(Settings::WEBCRON_TOKEN);
$cliCommand = 'php ' . \PFAD_ROOT . 'cli ' . PluginInfo::ID . ':sync';

$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES);
?>
<form method="post" action="">
    <input type="hidden" name="jtl_token" value="<?= $e($token) ?>">
    <input type="hidden" name="everysize_action" value="save">
    <div class="card">
        <div class="card-header"><h5 class="mb-0"><?= $t('everysize Checkout — Einstellungen') ?></h5></div>
        <div class="card-body">
            <p><?= $t('API-Umgebung') ?>: <?= $envBadge ?></p>

            <h6 class="mt-3"><?= $t('Zugangsdaten') ?></h6>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="everysize_client_id">Client-ID</label>
                <div class="col-sm-8">
                    <input class="form-control" type="text" id="everysize_client_id" name="everysize_client_id"
                           value="<?= $e($settings->getClientId()) ?>" autocomplete="off">
                </div>
            </div>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="everysize_client_secret">Client-Secret</label>
                <div class="col-sm-8">
                    <input class="form-control" type="password" id="everysize_client_secret" name="everysize_client_secret"
                           value="" placeholder="<?= $settings->getClientSecret() !== '' ? $e($t('(unverändert — gespeichert)')) : $e($t('(noch nicht gesetzt)')) ?>"
                           autocomplete="new-password">
                </div>
            </div>
            <div class="mb-3 row">
                <div class="offset-sm-3 col-sm-8">
                    <button type="submit" class="btn btn-outline-secondary" formnovalidate
                            onclick="this.form.everysize_action.value='test_connection'"><?= $t('Verbindung testen') ?></button>
                    <?php if ($settings->getClientSecret() !== '') : ?>
                        <button type="submit" class="btn btn-outline-danger" formnovalidate
                                onclick="if (!confirm('<?= $t('Zugangsdaten wirklich entfernen? Der Abgleich ruht dann, bis neue eingetragen sind.') ?>')) { return false; } this.form.everysize_action.value='remove_credentials';"><?= $t('Zugangsdaten entfernen') ?></button>
                    <?php endif; ?>
                </div>
            </div>

            <h6 class="mt-4"><?= $t('Abgleich') ?></h6>
            <div class="mb-3 form-check">
                <input class="form-check-input" type="checkbox" id="everysize_sync_enabled" name="everysize_sync_enabled" <?= $settings->isSyncEnabled() ? 'checked' : '' ?>>
                <label class="form-check-label" for="everysize_sync_enabled">
                    <?= $t('Abgleich aktiv (Import und Rückmeldungen über Cron und CLI)') ?>
                </label>
            </div>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="everysize_import_status"><?= $t('Import-Bestellstatus') ?></label>
                <div class="col-sm-8">
                    <select class="form-select" id="everysize_import_status" name="everysize_import_status">
                        <?php foreach ($importStatuses as $value => $label) : ?>
                            <option value="<?= $value ?>" <?= $importStatus === $value ? 'selected' : '' ?>><?= $e($label) ?> (<?= $value ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?= $t('Diesen Status bekommt eine importierte Bestellung im Shop.') ?></small>
                </div>
            </div>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="everysize_carrier"><?= $t('Carrier für Versandmeldung') ?></label>
                <div class="col-sm-8">
                    <select class="form-select" id="everysize_carrier" name="everysize_carrier">
                        <?php foreach ($carriers as $c) : ?>
                            <option value="<?= $e($c) ?>" <?= $c === $carrier ? 'selected' : '' ?>><?= $e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted"><?= $t('Carrier ohne Sendungsnummer wird trotzdem gemeldet; die everysize-API nimmt den Code nur zusammen mit einem Carrier an.') ?></small>
                </div>
            </div>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="everysize_shipping_method"><?= $t('Versandart für importierte Bestellungen') ?></label>
                <div class="col-sm-8">
                    <?php $current = $settings->get(Settings::SHIPPING_METHOD); ?>
                    <select class="form-select" id="everysize_shipping_method" name="everysize_shipping_method">
                        <option value=""><?= $t('— erste aktive Versandart des Shops —') ?></option>
                        <?php foreach ($shippingMethods as $m) : ?>
                            <option value="<?= (int) $m->kVersandart ?>" <?= (string) (int) $m->kVersandart === $current ? 'selected' : '' ?>><?= $e((string) $m->cName) ?> (<?= (int) $m->kVersandart ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3 form-check">
                <input class="form-check-input" type="checkbox" id="everysize_auto_refund" name="everysize_auto_refund" <?= $settings->isAutoRefundDecline() ? 'checked' : '' ?>>
                <label class="form-check-label" for="everysize_auto_refund">
                    <?= $t('Bei Storno vor Versand automatisch voll erstatten (Empfehlung)') ?>
                </label>
            </div>
            <div class="mb-3 form-check">
                <input class="form-check-input" type="checkbox" id="everysize_send_confirmation" name="everysize_send_confirmation" <?= $settings->shouldSendOrderConfirmation() ? 'checked' : '' ?>>
                <label class="form-check-label" for="everysize_send_confirmation">
                    <?= $t('Bestellbestätigung des Shops nach dem Import senden (Default: nein — der Kunde hat seine Bestätigung von everysize)') ?>
                </label>
            </div>
            <div class="mb-3 row">
                <label class="col-sm-3 col-form-label" for="everysize_carrier_mapping"><?= $t('Carrier-Zuordnung (JSON)') ?></label>
                <div class="col-sm-8">
                    <textarea class="form-control font-monospace" id="everysize_carrier_mapping" name="everysize_carrier_mapping" rows="4"
                              placeholder='{"DHL Paket Deutschland": "DHL", "Deutsche Post": "Deutsche Post"}'><?= $e($mappingJson) ?></textarea>
                    <small class="text-muted"><?= $t('Mappt die Carrier-Schreibweise der Wawi (tversand.cLogistik) auf everysize-Carrier.') ?></small>
                </div>
            </div>

            <h6 class="mt-4"><?= $t('Zeitsteuerung') ?></h6>
            <p class="mb-1"><?= $t('Der Plugin-Cron (everysize checkout sync) läuft über den Shop-Cron; zusätzlich kann der Abgleich ausgelöst werden über:') ?></p>
            <ul class="mb-2">
                <li>
                    <strong>Webcron (GET/POST):</strong> <code><?= $e($webcronUrl) ?></code><br>
                    <small class="text-muted"><?= $t('Der Token kann als Query-Parameter (wie oben) oder — bevorzugt, damit er nicht in Zugriffs-Logs landet — im Header X-Everysize-Token gesendet werden. Throttle: ein Abgleich höchstens alle 5 Minuten.') ?></small>
                </li>
                <li>
                    <strong>CLI:</strong> <code><?= $e($cliCommand) ?></code><br>
                    <small class="text-muted"><?= $t('Nur Import ohne Rückmeldungen:') ?> <code><?= $e(str_replace(':sync', ':order:import', $cliCommand)) ?></code> — <?= $t('nur Rückmeldungen:') ?> <code><?= $e(str_replace(':sync', ':order:export', $cliCommand)) ?></code></small>
                </li>
            </ul>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary"><?= $t('Speichern') ?></button>
        </div>
    </div>
</form>
<?php if ($testResult !== null) : ?>
    <div class="alert <?= $testResult['ok'] ? 'alert-success' : 'alert-danger' ?> mt-3"><?= $e($testResult['text']) ?></div>
<?php endif; ?>
