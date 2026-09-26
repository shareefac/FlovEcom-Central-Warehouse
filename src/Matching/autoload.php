<?php

declare(strict_types=1);

/*
 * Minimal PSR-4 loader for CW\Matching so the first-match tools and the plain
 * test runner work without Composer. Mirrors composer.json ("CW\\": "src/").
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'CW\\Matching\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
