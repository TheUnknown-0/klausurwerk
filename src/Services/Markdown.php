<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Markdown → sicheres HTML (Aufgabentexte, Erwartungshorizont, Hilfsmittel).
 *
 * - Parsedown im Safe-Mode: rohes HTML wird escaped, javascript:-Links entfernt.
 * - Formeln: $…$ und $$…$$ werden vor dem Parsen geschützt und als
 *   <span class="math-inline"> / <div class="math-block"> mit Roh-TeX
 *   ausgegeben; gesetzt werden sie im Browser (frontend/src/lib/rich-text.js).
 * - Bilder nur aus Klausur-Materialien: ![Beschriftung](material:12) wird zu
 *   /klausur/{examId}/material/12. Externe Bilder werden entfernt (Datenschutz,
 *   CSP erlaubt sie ohnehin nicht).
 * - Externe Links werden zu reinem Text (in der Klausur würde ein Klick die
 *   Seite verlassen und als Verstoß zählen).
 * - Code-Blöcke behalten ihre Sprache als class="language-…".
 */
final class Markdown
{
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly ?int $examId = null,
    ) {
        require_once dirname(__DIR__, 2) . '/lib/Parsedown.php';
    }

    public function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        [$protected, $maths] = $this->protectMath($markdown);

        $parser = new \Parsedown();
        $parser->setSafeMode(true);
        $parser->setBreaksEnabled(true);
        $html = (string) $parser->text($protected);

        $html = $this->restoreMath($html, $maths);

        return $this->postProcess($html);
    }

    /** Kurzform für eine Zeile (ohne umschließendes <p>). */
    public function inline(string $markdown): string
    {
        $html = $this->render($markdown);
        if (preg_match('#^<p>(.*)</p>$#s', $html, $m) === 1 && !str_contains($m[1], '<p>')) {
            return $m[1];
        }

        return $html;
    }

    // ---------- Formeln ----------

    /**
     * Ersetzt Formeln außerhalb von Code durch Platzhalter.
     *
     * @return array{0: string, 1: list<array{block: bool, tex: string}>}
     */
    private function protectMath(string $text): array
    {
        $maths = [];
        $out = '';
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $inFence = false;
        $buffer = [];

        $flush = function () use (&$buffer, &$out, &$maths): void {
            if ($buffer === []) {
                return;
            }
            $chunk = implode("\n", $buffer);
            $buffer = [];
            // Inline-Code `…` schützen, damit $ darin nicht als Formel zählt.
            $codeSpans = [];
            $chunk = preg_replace_callback('/(`+)(.+?)\1/s', static function (array $m) use (&$codeSpans): string {
                $codeSpans[] = $m[0];

                return 'KWCODE' . (count($codeSpans) - 1) . 'X';
            }, $chunk) ?? $chunk;
            // Blockformeln $$…$$
            $chunk = preg_replace_callback('/\$\$(.+?)\$\$/s', static function (array $m) use (&$maths): string {
                $maths[] = ['block' => true, 'tex' => trim($m[1])];

                return "\n\nKWMATH" . (count($maths) - 1) . "X\n\n";
            }, $chunk) ?? $chunk;
            // Inline-Formeln $…$ (nicht \$, kein Leerzeichen direkt innen)
            $chunk = preg_replace_callback('/(?<![\\\\$])\$(?!\s)([^\n$]+?)(?<!\s)\$(?!\d)/', static function (array $m) use (&$maths): string {
                $maths[] = ['block' => false, 'tex' => $m[1]];

                return 'KWMATH' . (count($maths) - 1) . 'X';
            }, $chunk) ?? $chunk;
            $chunk = preg_replace_callback('/KWCODE(\d+)X/', static fn (array $m): string => $codeSpans[(int) $m[1]], $chunk) ?? $chunk;
            $out .= $chunk . "\n";
        };

        foreach ($lines as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                if (!$inFence) {
                    $flush();
                }
                $inFence = !$inFence;
                $out .= $line . "\n";
                continue;
            }
            if ($inFence) {
                $out .= $line . "\n";
            } else {
                $buffer[] = $line;
            }
        }
        $flush();

        return [$out, $maths];
    }

    /** @param list<array{block: bool, tex: string}> $maths */
    private function restoreMath(string $html, array $maths): string
    {
        if ($maths === []) {
            return $html;
        }

        // Blockformel steht allein in einem Absatz: <p>KWMATH0X</p>
        $html = preg_replace_callback('#<p>KWMATH(\d+)X</p>#', static function (array $m) use ($maths): string {
            $math = $maths[(int) $m[1]] ?? null;
            if ($math === null) {
                return '';
            }

            return $math['block']
                ? '<div class="math-block">' . htmlspecialchars($math['tex'], ENT_QUOTES, 'UTF-8') . '</div>'
                : '<p><span class="math-inline">' . htmlspecialchars($math['tex'], ENT_QUOTES, 'UTF-8') . '</span></p>';
        }, $html) ?? $html;

        return preg_replace_callback('/KWMATH(\d+)X/', static function (array $m) use ($maths): string {
            $math = $maths[(int) $m[1]] ?? null;
            if ($math === null) {
                return '';
            }
            $class = $math['block'] ? 'math-block' : 'math-inline';
            $tag = $math['block'] ? 'div' : 'span';

            return "<{$tag} class=\"{$class}\">" . htmlspecialchars($math['tex'], ENT_QUOTES, 'UTF-8') . "</{$tag}>";
        }, $html) ?? $html;
    }

    // ---------- Bilder & Links ----------

    private function postProcess(string $html): string
    {
        if (!str_contains($html, '<img') && !str_contains($html, '<a ')) {
            return $html;
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="kw-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($doc->getElementsByTagName('img')) as $img) {
            /** @var \DOMElement $img */
            $src = $img->getAttribute('src');
            if (preg_match('/^material:(\d+)$/', $src, $m) === 1 && $this->examId !== null) {
                $img->setAttribute('src', $this->baseUrl . '/klausur/' . $this->examId . '/material/' . $m[1]);
                $img->setAttribute('loading', 'lazy');
                $img->setAttribute('class', 'task-image');
                continue;
            }
            if (preg_match('#^/klausur/\d+/material/\d+$#', $src) === 1) {
                $img->setAttribute('src', $this->baseUrl . $src);
                continue;
            }
            $alt = $img->getAttribute('alt');
            $replacement = $doc->createElement('span', $alt !== '' ? '[Bild: ' . $alt . ']' : '[Bild]');
            $replacement->setAttribute('class', 'text-faint');
            $img->parentNode?->replaceChild($replacement, $img);
        }

        foreach (iterator_to_array($doc->getElementsByTagName('a')) as $a) {
            /** @var \DOMElement $a */
            $href = $a->getAttribute('href');
            if (str_starts_with($href, '#')) {
                continue;
            }
            $span = $doc->createElement('span');
            $span->setAttribute('class', 'md-link');
            while ($a->firstChild !== null) {
                $span->appendChild($a->firstChild);
            }
            $a->parentNode?->replaceChild($span, $a);
        }

        $root = $doc->getElementById('kw-root');
        $out = '';
        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }
}
