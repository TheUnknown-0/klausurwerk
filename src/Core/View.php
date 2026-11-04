<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Template-Renderer: rendert PHP-Templates aus templates/ innerhalb eines Layouts.
 *
 * Templates sind einfache PHP-Dateien; Variablen werden per extract()
 * bereitgestellt. Für die Ausgabe steht die globale Helferfunktion e()
 * (HTML-Escaping) zur Verfügung.
 */
final class View
{
    /** @var array<string, mixed> Variablen, die jedem Template zur Verfügung stehen. */
    private array $shared = [];

    public function __construct(private readonly string $templateDir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** Rendert ein Seiten-Template innerhalb eines Layouts und gibt das HTML zurück. */
    public function render(string $template, array $data = [], string $layout = 'app'): string
    {
        $content = $this->renderPartial($template, $data);

        return $this->renderPartial('layouts/' . $layout, $data + ['content' => $content]);
    }

    /** Rendert ein Template ohne Layout (auch für Partials und PDFs nutzbar). */
    public function renderPartial(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template nicht gefunden: {$template}");
        }

        extract($this->shared, EXTR_SKIP);
        extract($data, EXTR_SKIP);
        $view = $this;

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
