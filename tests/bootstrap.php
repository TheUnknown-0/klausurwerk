<?php

declare(strict_types=1);

/**
 * Test-Bootstrap.
 *
 * Nutzt bewusst denselben handgeschriebenen PSR-4-Autoloader wie
 * src/bootstrap.php: Die Anwendung kommt ohne Composer aus, und das soll
 * durch die Tests nicht aufgeweicht werden. PHPUnit läuft deshalb als PHAR
 * (tools/phpunit.phar), nicht über vendor/.
 */

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'App\\' => dirname(__DIR__) . '/src/',
        'Tests\\' => __DIR__ . '/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;

            return;
        }
    }
});

require dirname(__DIR__) . '/src/helpers.php';

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');
