<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Statische Analyse ohne Datenbank: Jede registrierte Route muss auf eine
 * existierende Controller-Methode zeigen (a); jede öffentliche Controller-
 * Aktion muss durch einen Login-/Rechte-Guard geschützt sein (b); jede als
 * POST registrierte Route muss requireCsrf() aufrufen (c).
 *
 * Arbeitet bewusst per Regex/Token-Analyse der Quelltexte statt die
 * Anwendung auszuführen — Routen-Dateien referenzieren Controller nur über
 * `Klasse::class`, was auch für (noch) nicht existierende Klassen keinen
 * Fehler wirft. Fehlende Controller/Methoden werden hier stattdessen als
 * klare Testfehler sichtbar, während parallel noch an Modulen gearbeitet
 * wird (siehe ARCHITECTURE.md „Sicherheits-Pflichten“ Punkt 4).
 */
final class RouteGuardsTest extends TestCase
{
    private const ROUTES_DIR = __DIR__ . '/../../src/routes';
    private const CONTROLLERS_DIR = __DIR__ . '/../../src/Controllers';
    private const CONTROLLERS_NS = 'App\\Controllers\\';

    /**
     * Controller, deren öffentliche Aktionen bewusst OHNE
     * requireLogin/requirePermission/requireAdmin auskommen — mit Begründung.
     * Wird ein neuer Controller mit denselben Merkmalen ergänzt, gehört er
     * hier explizit (und begründet) hinein, nicht implizit übersprungen.
     *
     * @var array<string, string>
     */
    private const GUARD_EXEMPT_CONTROLLERS = [
        'AuthController' => 'Login/Logout/Seitenpasswort/Passwortwechsel sind die Anmeldung selbst; '
            . 'changePassword prüft den Benutzer manuell statt über requireLogin().',
        'SetupController' => 'Nur erreichbar, solange keine Benutzer existieren (assertSetupNeeded()); '
            . 'vor dem ersten Admin-Konto kann es keinen Login-Guard geben.',
        'HealthController' => 'Bewusst ohne Anmeldung für Container-Healthcheck/Monitoring (siehe Klassenkommentar).',
        'FileController' => 'Liefert öffentliche Medien (Logo/Branding) aus, die bereits auf der Login-Seite '
            . 'erscheinen; Dateinamen sind 128-Bit-Zufallswerte (siehe Klassenkommentar).',
        'HomeController' => 'index() ist der rollenabhängige Einstieg und behandelt „nicht angemeldet“ selbst '
            . '(Weiterleitung zu /setup bzw. /login) statt über requireLogin() abzubrechen.',
    ];

    /**
     * Guard-Aufrufe, die eine Aktion als geschützt gelten lassen.
     */
    private const GUARD_PATTERN = '/\$this->(?:requireLogin|requirePermission|requireAdmin|requireAny|requireStaff|requireStudent)\s*\(/';

    private const CSRF_PATTERN = '/\$this->requireCsrf\s*\(/';

    /**
     * Routen, deren POST-Handler bewusst kein requireCsrf() aufrufen —
     * aktuell keine bekannt (Login/Setup/Zugang/Passwortwechsel rufen es
     * trotz fehlendem Login-Guard auf). Format: "METHOD /pfad".
     *
     * @var list<string>
     */
    private const CSRF_EXEMPT_ROUTES = [];

    /**
     * @return list<array{method: string, pattern: string, class: string, action: string, file: string}>
     */
    private function parseRoutes(): array
    {
        $routes = [];
        $files = glob(self::ROUTES_DIR . '/*.php') ?: [];
        self::assertNotEmpty($files, 'Keine Routen-Dateien unter src/routes gefunden.');
        sort($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, "Routen-Datei nicht lesbar: {$file}");

            // Alias-Tabelle aus den `use`-Importen dieser Datei (Kurzname => FQCN).
            $aliases = [];
            if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $useMatches)) {
                foreach ($useMatches[1] as $fqcn) {
                    $short = substr((string) strrchr($fqcn, '\\'), 1) ?: $fqcn;
                    $aliases[$short] = ltrim($fqcn, '\\');
                }
            }

            $pattern = '/\$r->(get|post)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\[\s*(\w+)::class\s*,\s*\'(\w+)\'\s*\]\s*\)/';
            if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
                self::fail("Routen-Datei konnte nicht geparst werden: {$file}");
            }
            self::assertNotEmpty(
                $matches,
                "Keine Routen in {$file} gefunden — Regex und Router-Syntax abgleichen (\$r->get/post([...], [Klasse::class, 'aktion'])).",
            );

            foreach ($matches as $m) {
                $short = $m[3];
                $class = $aliases[$short] ?? (self::CONTROLLERS_NS . $short);
                $routes[] = [
                    'method' => strtoupper($m[1]),
                    'pattern' => $m[2],
                    'class' => $class,
                    'action' => $m[4],
                    'file' => basename($file),
                ];
            }
        }

        return $routes;
    }

    /** Lädt eine Controller-Klasse falls nötig und liefert ihre Reflection, oder null bei fehlender Datei. */
    private function reflectController(string $fqcn): ?ReflectionClass
    {
        if (!class_exists($fqcn)) {
            $path = self::CONTROLLERS_DIR . '/' . substr($fqcn, strlen(self::CONTROLLERS_NS)) . '.php';
            if (!is_file($path)) {
                return null;
            }
            require_once $path;
            if (!class_exists($fqcn)) {
                return null;
            }
        }

        return new ReflectionClass($fqcn);
    }

    // ---------- (a) Routen -> existierende Controller-Methoden ----------

    public function testJedeRouteZeigtAufEineExistierendeControllerMethode(): void
    {
        foreach ($this->parseRoutes() as $route) {
            $class = $this->reflectController($route['class']);
            self::assertNotNull(
                $class,
                sprintf(
                    "Route %s %s (%s): Controller-Datei fehlt für %s.",
                    $route['method'],
                    $route['pattern'],
                    $route['file'],
                    $route['class'],
                ),
            );

            self::assertTrue(
                $class->hasMethod($route['action']),
                sprintf(
                    "Route %s %s (%s): %s::%s() existiert nicht.",
                    $route['method'],
                    $route['pattern'],
                    $route['file'],
                    $route['class'],
                    $route['action'],
                ),
            );

            $method = $class->getMethod($route['action']);
            self::assertTrue(
                $method->isPublic(),
                sprintf(
                    "Route %s %s (%s): %s::%s() muss public sein.",
                    $route['method'],
                    $route['pattern'],
                    $route['file'],
                    $route['class'],
                    $route['action'],
                ),
            );
        }
    }

    // ---------- (b) jede Controller-Aktion ist per Guard geschützt ----------

    /** @return list<ReflectionClass> */
    private function allControllerClasses(): array
    {
        $classes = [];
        foreach (glob(self::CONTROLLERS_DIR . '/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            if ($short === 'Controller') {
                continue; // Basisklasse
            }
            $fqcn = self::CONTROLLERS_NS . $short;
            $class = $this->reflectController($fqcn);
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** Öffentliche, in dieser Klasse selbst deklarierte Aktionen (keine geerbten). */
    private function publicActions(ReflectionClass $class): array
    {
        $actions = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            if ($method->isConstructor() || $method->isStatic() || $method->isAbstract()) {
                continue;
            }
            $actions[] = $method;
        }

        return $actions;
    }

    /** Quelltext einer Methode (inkl. Signatur/Body) als String. */
    private function methodSource(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($file === false || $start === false || $end === false) {
            return '';
        }
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /** Namen privater/protected Methoden derselben Klasse, die im Quelltext per $this->name( aufgerufen werden. */
    private function calledOwnMethods(string $source, ReflectionClass $class): array
    {
        if (preg_match_all('/\$this->(\w+)\s*\(/', $source, $matches) === false) {
            return [];
        }
        $called = [];
        foreach (array_unique($matches[1]) as $name) {
            if ($class->hasMethod($name)) {
                $called[] = $name;
            }
        }

        return $called;
    }

    /**
     * Prüft, ob eine Methode direkt einen Guard/CSRF-Aufruf enthält oder
     * (rekursiv, mit Zyklenschutz) über aufgerufene eigene Methoden einen
     * enthält — deckt Muster wie `requireStudent()`/`requireStaff()` ab,
     * die intern requireLogin() aufrufen.
     */
    private function bodyOrCalleesMatch(ReflectionClass $class, string $methodName, string $regex, array &$visited = []): bool
    {
        if (isset($visited[$methodName]) || !$class->hasMethod($methodName)) {
            return false;
        }
        $visited[$methodName] = true;

        $method = $class->getMethod($methodName);
        $source = $this->methodSource($method);
        if (preg_match($regex, $source) === 1) {
            return true;
        }

        foreach ($this->calledOwnMethods($source, $class) as $calleeName) {
            if ($this->bodyOrCalleesMatch($class, $calleeName, $regex, $visited)) {
                return true;
            }
        }

        return false;
    }

    /** Guard direkt im Konstruktor der Klasse (nicht der Basisklasse)? */
    private function constructorGuards(ReflectionClass $class): bool
    {
        if (!$class->hasMethod('__construct')) {
            return false;
        }
        $ctor = $class->getMethod('__construct');
        if ($ctor->getDeclaringClass()->getName() !== $class->getName()) {
            return false;
        }

        return preg_match(self::GUARD_PATTERN, $this->methodSource($ctor)) === 1;
    }

    public function testJedeOeffentlicheAktionHatEinenLoginOderRechteGuard(): void
    {
        foreach ($this->allControllerClasses() as $class) {
            $short = $class->getShortName();
            if (array_key_exists($short, self::GUARD_EXEMPT_CONTROLLERS)) {
                continue;
            }

            $classGuardedViaConstructor = $this->constructorGuards($class);

            foreach ($this->publicActions($class) as $method) {
                if ($classGuardedViaConstructor) {
                    continue;
                }
                $visited = [];
                $guarded = $this->bodyOrCalleesMatch($class, $method->getName(), self::GUARD_PATTERN, $visited);

                self::assertTrue(
                    $guarded,
                    sprintf(
                        "%s::%s() hat weder direkt noch über eine intern aufgerufene Methode einen "
                        . 'requireLogin/requirePermission/requireAdmin/requireAny-Aufruf. '
                        . 'Falls das gewollt ist (Auth/Setup/Health-ähnlich), den Controller mit Begründung '
                        . 'in RouteGuardsTest::GUARD_EXEMPT_CONTROLLERS eintragen.',
                        $short,
                        $method->getName(),
                    ),
                );
            }
        }
    }

    // ---------- (c) jede POST-Route ruft requireCsrf() auf ----------

    public function testJedePostRouteRuftRequireCsrfAuf(): void
    {
        foreach ($this->parseRoutes() as $route) {
            if ($route['method'] !== 'POST') {
                continue;
            }
            $routeKey = 'POST ' . $route['pattern'];
            if (in_array($routeKey, self::CSRF_EXEMPT_ROUTES, true)) {
                continue;
            }

            $class = $this->reflectController($route['class']);
            if ($class === null || !$class->hasMethod($route['action'])) {
                // Bereits durch testJedeRouteZeigtAufEineExistierendeControllerMethode gemeldet.
                continue;
            }

            $visited = [];
            $hasCsrf = $this->bodyOrCalleesMatch($class, $route['action'], self::CSRF_PATTERN, $visited);

            self::assertTrue(
                $hasCsrf,
                sprintf(
                    "POST-Route %s (%s, %s::%s()) ruft requireCsrf() nicht auf — weder direkt noch über eine "
                    . 'intern aufgerufene Methode. Falls gewollt, in RouteGuardsTest::CSRF_EXEMPT_ROUTES eintragen.',
                    $route['pattern'],
                    $route['file'],
                    $class->getShortName(),
                    $route['action'],
                ),
            );
        }
    }
}
