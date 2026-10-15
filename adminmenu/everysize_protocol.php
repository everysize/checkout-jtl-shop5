<?php

declare(strict_types=1);

/**
 * Admin-Tab „Protokoll“: Sync-Läufe, die etwas bewegt haben oder fehlgeschlagen sind.
 */

use JTL\Shop;
use Plugin\everysize_checkout\Db\JtlConnection;
use Plugin\everysize_checkout\PluginInfo;
use Plugin\everysize_checkout\Protocol;

// Nur im Shop-Kontext laden (JTL definiert PFAD_ROOT; die Tab-Dateien
// werden per require eingebunden).
if (!\defined('PFAD_ROOT')) {
    exit;
}

require_once __DIR__ . '/../src/autoload.php';

/** @var \JTL\Plugin\PluginInterface $oPlugin */
/** @var \JTL\Smarty\JTLSmarty $smarty */

$db = new JtlConnection(Shop::Container()->getDB());
$protocol = new Protocol($db);
$gettext = Shop::Container()->getGettext();

/** Plugin-Texte: msgid ist der deutsche Originaltext (siehe locale/<lang>/base.po). */
$t = static fn (string $text): string => $gettext->translatePluginOrCoreMessage(PluginInfo::ID, $text);

$filter = $_GET['everysize_filter'] ?? 'all';
$success = match ($filter) {
    'ok' => true,
    'error' => false,
    default => null,
};
$page = max(1, (int) ($_GET['everysize_page'] ?? 1));
$perPage = 50;
$total = $protocol->count($success);
$entries = $protocol->getEntries(($page - 1) * $perPage, $perPage, $success);
$pages = (int) ceil(max(1, $total) / $perPage);

?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><?= $t('everysize-Protokoll') ?> (<?= $total ?> <?= $t('Einträge') ?>)</h5>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?= $success === null ? 'active' : '' ?>"
               href="?kPlugin=<?= (int) $oPlugin->getID() ?>&everysize_filter=all"><?= $t('Alle') ?></a>
            <a class="btn btn-outline-secondary <?= $success === true ? 'active' : '' ?>"
               href="?kPlugin=<?= (int) $oPlugin->getID() ?>&everysize_filter=ok"><?= $t('OK') ?></a>
            <a class="btn btn-outline-secondary <?= $success === false ? 'active' : '' ?>"
               href="?kPlugin=<?= (int) $oPlugin->getID() ?>&everysize_filter=error"><?= $t('Fehler') ?></a>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($entries === []) : ?>
            <p class="p-3 mb-0 text-muted"><?= $t('Keine Einträge. Das Protokoll führt nur Läufe, die etwas bewegt oder Fehler erzeugt haben.') ?></p>
        <?php else : ?>
            <table class="table table-striped mb-0">
                <thead>
                <tr>
                    <th style="width: 9em"><?= $t('Zeit') ?></th>
                    <th style="width: 2em"></th>
                    <th><?= $t('Titel') ?></th>
                    <th><?= $t('Nachricht') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry) : ?>
                    <tr>
                        <td><small><?= htmlspecialchars((string) $entry->created_at, ENT_QUOTES) ?></small></td>
                        <td><?= (int) $entry->state === 1 ? '<span class="text-success">✔</span>' : '<span class="text-danger">✘</span>' ?></td>
                        <td><?= htmlspecialchars((string) $entry->title, ENT_QUOTES) ?></td>
                        <td><small style="white-space: pre-wrap"><?= htmlspecialchars((string) $entry->message, ENT_QUOTES) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1) : ?>
        <div class="card-footer">
            <nav aria-label="<?= $t('Seiten') ?>">
                <ul class="pagination pagination-sm mb-0">
                    <?php for ($p = 1; $p <= $pages; $p++) : ?>
                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?kPlugin=<?= (int) $oPlugin->getID() ?>&everysize_filter=<?= htmlspecialchars((string) $filter, ENT_QUOTES) ?>&everysize_page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>
