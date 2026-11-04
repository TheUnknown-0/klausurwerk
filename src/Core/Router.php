<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimaler, expliziter Router.
 *
 * Routen werden als Methode + Pfadmuster registriert. Pfadparameter in
 * geschweiften Klammern ({id}) werden extrahiert und dem Handler übergeben.
 */
final class Router
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: array{class-string, string}, params: list<string>}> */
    private array $routes = [];

    /** @param array{class-string, string} $handler [ControllerKlasse, Methode] */
    public function add(string $method, string $pattern, array $handler): void
    {
        $params = [];
        // Literale Teile escapen (z. B. „.pdf“), nur Platzhalter werden zu Gruppen
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}|[^{]+/',
            static function (array $m) use (&$params): string {
                if (isset($m[1]) && $m[1] !== '') {
                    $params[] = $m[1];

                    return '([^/]+)';
                }

                return preg_quote($m[0], '#');
            },
            $pattern,
        );

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
            'params' => $params,
        ];
    }

    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /**
     * Alle registrierten Routen — für Übersichten und den Guard-Test.
     *
     * @return list<array{method: string, pattern: string, handler: array{class-string, string}}>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $route): array => [
                'method' => $route['method'],
                'pattern' => $route['pattern'],
                'handler' => $route['handler'],
            ],
            $this->routes,
        );
    }

    /**
     * @return array{handler: array{class-string, string}, params: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = urldecode($matches[$i + 1]);
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }

        return null;
    }

    /** Prüft, ob der Pfad für eine andere HTTP-Methode registriert ist (405 statt 404). */
    public function allowsOtherMethod(string $method, string $path): bool
    {
        $method = strtoupper($method);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && preg_match($route['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
