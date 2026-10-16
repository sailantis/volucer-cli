<?php

declare(strict_types=1);

/**
 * A dependency-free PSR-4 autoloader for the Volucer\Cli\ namespace.
 *
 * The downloader is deliberately stdlib-only: requiring this package must not
 * pull a dependency tree into a project that asked only for a server binary.
 * An application that manages its dependencies with Composer should require
 * this package instead and let its own autoloader do the work; this file exists
 * so the tool runs from a plain checkout with no `composer install` at all.
 *
 * `composer.json` maps the same namespace to `src/`, so both paths resolve to
 * the same classes.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Volucer\\Cli\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});
