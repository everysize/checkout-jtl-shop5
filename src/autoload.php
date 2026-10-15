<?php

/**
 * PSR-4-Autoloader für die Plugin-Klassen.
 *
 * JTL-Shop lädt `Plugin\<PluginID>\<Klasse>` per Composer-Autoloader aus dem
 * Plugin-Root — die everysize-Klassen liegen aber unter `src/` (damit im
 * Plugin-Root nur die von JTL geforderten Dateien und Ordner stehen). Dieser
 * Autoloader bildet `Plugin\everysize_checkout\<Sub>…` zuerst auf `src/<Sub>…`
 * ab, danach auf das Plugin-Root (Bootstrap.php, Migrations/, Commands/).
 *
 * Idempotent einbindbar (Bootstrap::boot() und die CLI-Commands binden es ein).
 */

declare(strict_types=1);

(function (): void {
    $root = \dirname(__DIR__);

    \spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Plugin\\everysize_checkout\\';
        if (!\str_starts_with($class, $prefix)) {
            return;
        }
        $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
        foreach ([$root . '/src/', $root . '/'] as $base) {
            $file = $base . $relative . '.php';
            if (\is_file($file)) {
                require $file;

                return;
            }
        }
    });
})();
