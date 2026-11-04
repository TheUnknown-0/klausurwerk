<?php

declare(strict_types=1);

/**
 * Bootstrap: Autoloader, Konfiguration, Kern-Dienste.
 * Gibt den fertig aufgebauten App-Kontext zurück.
 */

use App\Core\Auth;
use App\Core\Context;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Services\Audit;
use App\Services\Settings;

// PSR-4-Autoloader für App\ → src/ (bewusst ohne Composer-Abhängigkeit;
// composer.json dient Metadaten und optionalem Tooling).
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/helpers.php';

$config = require dirname(__DIR__) . '/config/config.php';

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

if (($config['app']['env'] ?? 'production') === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

$db = new Database(
    $config['db']['host'],
    $config['db']['name'],
    $config['db']['user'],
    $config['db']['pass'],
);

$session = new Session();
$session->start((bool) $config['app']['secure_cookies']);

$csrf = new Csrf($session);
$auth = new Auth($session, $db);
$view = new View(dirname(__DIR__) . '/templates');
$settings = new Settings($db);
$audit = new Audit($db, $auth);

$ctx = new Context($config, $db, $session, $csrf, $auth, $view, $settings, $audit);

// Gemeinsame Template-Variablen
$view->share('ctx', $ctx);
$view->share('auth', $auth);
$view->share('csrf', $csrf);

return $ctx;
