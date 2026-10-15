<?php

declare(strict_types=1);

namespace Plugin\everysize_checkout\Migrations;

use JTL\Plugin\Migration;
use JTL\Update\IMigration;

/**
 * Tables of the everysize checkout plugin (1.0.0).
 *
 * No foreign keys to tbestellung/twarenkorbpos: JTL and JTL-Wawi delete or
 * modify orders with their own code, and a cascade must never remove the
 * "already reported" markers of an order that still exists.
 */
class Migration20261014120000 extends Migration implements IMigration
{
    private const TABLES = [
        'xplugin_everysize_checkout_settings',
        'xplugin_everysize_checkout_order',
        'xplugin_everysize_checkout_item',
        'xplugin_everysize_checkout_report',
        'xplugin_everysize_checkout_tracking',
        'xplugin_everysize_checkout_protocol',
    ];

    /**
     * @inheritdoc
     */
    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `xplugin_everysize_checkout_settings` (
                `kSettings` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `shop_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(64) NOT NULL,
                `value` TEXT NOT NULL,
                PRIMARY KEY (`kSettings`),
                UNIQUE KEY `shop_name` (`shop_id`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='everysize checkout: settings incl. API credentials (secret never leaves the DB)'"
        );
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `xplugin_everysize_checkout_order` (
                `kBestellung` INT(10) UNSIGNED NOT NULL,
                `everysize_order_id` VARCHAR(64) NOT NULL,
                `paypal_capture_id` VARCHAR(64) NOT NULL DEFAULT '',
                `original_email` VARCHAR(255) NOT NULL DEFAULT '',
                `number_reported` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`kBestellung`),
                UNIQUE KEY `everysize_order_id` (`everysize_order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='everysize checkout: link JTL order <=> everysize order'"
        );
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `xplugin_everysize_checkout_item` (
                `kWarenkorbPos` INT(10) UNSIGNED NOT NULL,
                `kBestellung` INT(10) UNSIGNED NOT NULL,
                `sku` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`kWarenkorbPos`),
                KEY `kBestellung` (`kBestellung`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='everysize checkout: everysize SKU per imported order position'"
        );
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `xplugin_everysize_checkout_report` (
                `kReport` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `kBestellung` INT(10) UNSIGNED NOT NULL,
                `kind` VARCHAR(16) NOT NULL,
                `sku` VARCHAR(255) NOT NULL DEFAULT '',
                `reason` VARCHAR(32) NOT NULL DEFAULT '',
                `result` VARCHAR(16) NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`kReport`),
                UNIQUE KEY `order_kind_sku` (`kBestellung`, `kind`, `sku`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='everysize checkout: what has been reported to everysize, exactly once per key'"
        );
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `xplugin_everysize_checkout_tracking` (
                `kTracking` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `kBestellung` INT(10) UNSIGNED NOT NULL,
                `carrier` VARCHAR(31) NOT NULL DEFAULT '',
                `tracking_code` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`kTracking`),
                UNIQUE KEY `kBestellung` (`kBestellung`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='everysize checkout: manual carrier/tracking code per order'"
        );
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `xplugin_everysize_checkout_protocol` (
                `kProtocol` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `shop_id` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                `title` VARCHAR(255) NOT NULL DEFAULT '',
                `message` TEXT NOT NULL,
                `state` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = success, 0 = error',
                PRIMARY KEY (`kProtocol`),
                KEY `shop_created` (`shop_id`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT='everysize checkout: sync protocol'"
        );
    }

    /**
     * @inheritdoc
     */
    public function down(): void
    {
        // "Keep data" in the uninstall dialog: JTL does not call down() in
        // that case itself, it only flips doDeleteData() — respect it, the
        // xplugin_* tables then survive an uninstall/reinstall.
        if (!$this->doDeleteData()) {
            return;
        }

        foreach (self::TABLES as $table) {
            $this->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
