<?php

declare(strict_types=1);

/**
 * Front-Controller: einziger Einstiegspunkt der Anwendung.
 */

use App\Core\Context;
use App\Core\HttpException;
use App\Core\Router;

/** @var Context $ctx */
$ctx = require dirname(__DIR__) . '/src/bootstrap.php';

// ---------- Security-Header ----------
// Standardwerte für jede Antwort. Ein Controller darf die CSP per
// App\Core\Csp::send([...]) gezielt erweitern (z. B. Laufzeitumgebungen).
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), display-capture=(self)');
// Cross-Origin-Isolation: nötig für SharedArrayBuffer (interaktives input()
// in Python im Browser). Alle Ressourcen kommen von der eigenen Herkunft.
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Embedder-Policy: require-corp');
header('Cross-Origin-Resource-Policy: same-origin');
\App\Core\Csp::send();

// ---------- Pfad ermitteln (BASE_URL-Präfix abstreifen) ----------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = $ctx->config['app']['base_url'];
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$path = '/' . ltrim($path, '/');
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/') . '/'; // genau ein abschließender Slash bleibt erhalten
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// HEAD wie GET behandeln (Monitoring, Link-Prüfer); Apache verwirft den Körper.
if ($method === 'HEAD') {
    $method = 'GET';
}
$isApi = str_contains($path, '/api/')
    || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

// ---------- Dispatch ----------
// Alles ab hier läuft im try-Block: Das Seitenpasswort und das Laden der Routen
// greifen bereits auf Datenbank und Dateisystem zu, liefe das davor, endete
// ein Datenbankausfall in einer ungefangenen Exception mit HTTP 200, weil die
// Header dann längst raus sind.
try {
    // ---------- Globales Seitenpasswort (optional) ----------
    // /healthz und /readyz müssen auch bei aktivem Seitenpasswort antworten , 
    // sonst meldet der Container-Healthcheck eine Weiterleitung statt Zustand.
    $exemptPrefixes = ['/zugang', '/assets/', '/favicon', '/healthz', '/readyz'];
    $exempt = false;
    foreach ($exemptPrefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $exempt = true;
            break;
        }
    }
    if (!$exempt
        && $ctx->settings->getBool('site_password_enabled')
        && $ctx->session->get('site_authenticated') !== true) {
        header('Location: ' . $ctx->url('/zugang?redirect=' . urlencode($path)), true, 303);
        exit;
    }

    // ---------- Routen laden ----------
    $router = new Router();
    foreach (glob(dirname(__DIR__) . '/src/routes/*.php') ?: [] as $routeFile) {
        $register = require $routeFile;
        $register($router);
    }

    $match = $router->match($method, $path);
    if ($match === null) {
        throw new HttpException($router->allowsOtherMethod($method, $path) ? 405 : 404);
    }

    [$class, $action] = $match['handler'];
    $controller = new $class($ctx);
    $result = $controller->$action($match['params']);

    if (is_array($result)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (is_string($result)) {
        echo $result;
    }
} catch (HttpException $e) {
    // 419 ist kein Standard-Code, ohne explizite Statuszeile macht Apache daraus ein 500.
    if ($e->status === 419) {
        header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 419 Page Expired', true, 419);
    } else {
        http_response_code($e->status);
    }
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        echo $ctx->view->render('pages/error', [
            'status' => $e->status,
            'message' => $e->getMessage(),
            'title' => 'Fehler ' . $e->status,
        ], 'minimal');
    }
} catch (Throwable $e) {
    error_log(sprintf('[Klausurwerk] %s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    $dev = ($ctx->config['app']['env'] ?? '') === 'development';
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $dev ? $e->getMessage() : 'Interner Serverfehler.',
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo $ctx->view->render('pages/error', [
            'status' => 500,
            'message' => $dev ? $e->getMessage() . "\n" . $e->getTraceAsString() : 'Interner Serverfehler.',
            'title' => 'Fehler 500',
        ], 'minimal');
    }
}
