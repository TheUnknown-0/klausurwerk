<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * Schreibt eine fremde Seite für den eingebauten Browser um.
 *
 *  - entfernt alles Aktive: Skripte, Rahmen, Plugins, Ereignis-Attribute, javascript:-Links,
 *    Meta-Weiterleitungen, <base>; <noscript>-Inhalte bleiben (die Seite läuft ohne JavaScript)
 *  - Links (a, area, GET-Formulare) zeigen auf den Proxy: Dort wird jede Adresse gegen die
 *    Freigaben geprüft. POST-Formulare werden abgeschaltet.
 *  - Bilder, Stylesheets und Schriften zeigen auf den Proxy mit Signatur (nur so dürfen sie von
 *    anderen Adressen kommen, siehe WebToken::asset)
 *  - oben eine schlichte Leiste: Zurück und aktuelle Adresse (ohne Skript, als Link)
 */
final class WebRewriter
{
    /** Elemente, die samt Inhalt verschwinden. */
    private const DROP = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'portal', 'template', 'audio', 'video', 'track', 'dialog'];

    /**
     * @param callable(string): string $pageLink Proxy-Adresse für eine Seite (Navigation)
     * @param callable(string): string $assetLink Proxy-Adresse für ein Bild/Stylesheet (signiert)
     */
    public function __construct(
        private readonly string $baseUrl,
        private $pageLink,
        private $assetLink,
    ) {
    }

    // ---------- Adressen ----------

    /** Löst eine (relative) Adresse gegen die Basis auf (RFC 3986, vereinfacht). */
    public static function resolve(string $base, string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return $base;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref) === 1) {
            return $ref;
        }
        $b = parse_url($base);
        $scheme = (string) ($b['scheme'] ?? 'https');
        $origin = $scheme . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $scheme . ':' . $ref;
        }
        $basePath = (string) ($b['path'] ?? '/');
        if (str_starts_with($ref, '#')) {
            return $origin . $basePath . (isset($b['query']) ? '?' . $b['query'] : '') . $ref;
        }
        if (str_starts_with($ref, '?')) {
            return $origin . $basePath . $ref;
        }
        $path = str_starts_with($ref, '/') ? $ref : substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $ref;
        $tail = '';
        if (preg_match('/^([^?#]*)([?#].*)?$/', $path, $m) === 1) {
            $path = $m[1];
            $tail = $m[2] ?? '';
        }
        $out = [];
        foreach (explode('/', $path) as $i => $seg) {
            if ($seg === '..') {
                if (count($out) > 1) {
                    array_pop($out);
                }
            } elseif ($seg !== '.') {
                $out[] = $seg;
            }
        }
        $joined = implode('/', $out);
        if (str_ends_with($path, '/..') || str_ends_with($path, '/.')) {
            $joined .= '/';
        }

        return $origin . ($joined === '' || $joined[0] !== '/' ? '/' . $joined : $joined) . $tail;
    }

    private function abs(string $ref): ?string
    {
        $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($ref === '' || str_starts_with($ref, 'data:')) {
            return null;
        }
        $abs = self::resolve($this->baseUrl, $ref);

        return preg_match('#^https?://#i', $abs) === 1 ? $abs : null;
    }

    // ---------- CSS ----------

    /** url(...) und @import in CSS auf signierte Proxy-Adressen umschreiben. */
    public function css(string $css): string
    {
        $css = (string) preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', function (array $m): string {
            $ref = trim($m[2]);
            if ($ref === '' || stripos($ref, 'data:') === 0 || str_starts_with($ref, '#')) {
                return $m[0];
            }
            $abs = $this->abs($ref);

            return $abs !== null ? 'url("' . str_replace('"', '%22', ($this->assetLink)($abs)) . '")' : 'url("")';
        }, $css);
        $css = (string) preg_replace_callback('/@import\s+([\'"])(.*?)\1/i', function (array $m): string {
            $abs = $this->abs($m[2]);

            return $abs !== null ? '@import url("' . str_replace('"', '%22', ($this->assetLink)($abs)) . '")' : '';
        }, $css);

        // Alte IE-Tricks mit Skripten in CSS.
        return (string) preg_replace('/expression\s*\(|behavior\s*:|-moz-binding\s*:/i', 'x-blocked(', $css);
    }

    // ---------- HTML ----------

    public function html(string $html, string $charset, ?string $back): string
    {
        $html = self::toUtf8($html, $charset);
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        // Hilfsangabe zur Kodierung und den Doctype der Quelle entfernen (wir setzen einen eigenen).
        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node instanceof \DOMProcessingInstruction || $node instanceof \DOMDocumentType) {
                $doc->removeChild($node);
            }
        }
        $doc->encoding = 'UTF-8';

        $xp = new \DOMXPath($doc);
        foreach (self::DROP as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                $el->parentNode?->removeChild($el);
            }
        }
        // SVG-Skripte und Fremdobjekte (Namensraum beliebig)
        foreach (iterator_to_array($xp->query('//*[local-name()="script" or local-name()="foreignObject" or local-name()="handler"]') ?: []) as $el) {
            $el->parentNode?->removeChild($el);
        }
        // <noscript>: Inhalt zeigen, die Seite läuft ohne Skripte.
        foreach (iterator_to_array($doc->getElementsByTagName('noscript')) as $el) {
            while ($el->firstChild !== null) {
                $el->parentNode?->insertBefore($el->firstChild, $el);
            }
            $el->parentNode?->removeChild($el);
        }
        foreach (iterator_to_array($doc->getElementsByTagName('meta')) as $el) {
            if ($el->hasAttribute('http-equiv')) {
                $el->parentNode?->removeChild($el);
            }
        }
        foreach (iterator_to_array($doc->getElementsByTagName('link')) as $el) {
            $rel = strtolower($el->getAttribute('rel'));
            if (!preg_match('/\bstylesheet\b/', $rel) || preg_match('/\balternate\b/', $rel)) {
                $el->parentNode?->removeChild($el);
                continue;
            }
            $abs = $this->abs($el->getAttribute('href'));
            $abs !== null ? $el->setAttribute('href', ($this->assetLink)($abs)) : $el->parentNode?->removeChild($el);
        }

        foreach (iterator_to_array($xp->query('//*') ?: []) as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $this->cleanAttributes($el);
            $tag = strtolower($el->localName ?? '');
            switch ($tag) {
                case 'a':
                case 'area':
                    $this->rewriteLink($el);
                    break;
                case 'img':
                case 'source':
                case 'input':
                    if ($el->hasAttribute('src')) {
                        $abs = $this->abs($el->getAttribute('src'));
                        $abs !== null ? $el->setAttribute('src', ($this->assetLink)($abs)) : $el->removeAttribute('src');
                    }
                    if ($el->hasAttribute('srcset')) {
                        $el->setAttribute('srcset', $this->srcset($el->getAttribute('srcset')));
                    }
                    $el->removeAttribute('loading');
                    break;
                case 'image':
                case 'use':
                    foreach (['href', 'xlink:href'] as $attr) {
                        if ($el->hasAttribute($attr) && !str_starts_with(trim($el->getAttribute($attr)), '#')) {
                            $abs = $this->abs($el->getAttribute($attr));
                            $abs !== null ? $el->setAttribute($attr, ($this->assetLink)($abs)) : $el->removeAttribute($attr);
                        }
                    }
                    break;
                case 'form':
                    $this->rewriteForm($doc, $el);
                    break;
                case 'style':
                    $el->textContent = $this->css($el->textContent);
                    break;
            }
            if ($el->hasAttribute('style')) {
                $el->setAttribute('style', $this->css($el->getAttribute('style')));
            }
        }

        $this->addToolbar($doc, $back);
        $this->setCharset($doc);
        // Knotenweise ausgeben: so bleibt UTF-8 erhalten (das ganze Dokument käme als Entities).
        return '<!DOCTYPE html>' . "\n" . (string) $doc->saveHTML($doc->documentElement);
    }

    private function cleanAttributes(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = strtolower(preg_replace('/[\s\x00-\x1F]+/', '', $attr->nodeValue ?? '') ?? '');
            if (str_starts_with($name, 'on') || in_array($name, ['formaction', 'ping', 'srcdoc', 'integrity', 'crossorigin', 'nonce', 'target', 'download', 'action-xhr', 'background'], true)
                || str_starts_with($value, 'javascript:') || str_starts_with($value, 'vbscript:')
                || (str_starts_with($value, 'data:') && !in_array($name, ['src', 'srcset'], true))) {
                $el->removeAttribute($attr->nodeName);
            }
        }
    }

    private function rewriteLink(\DOMElement $el): void
    {
        if (!$el->hasAttribute('href')) {
            return;
        }
        $href = trim($el->getAttribute('href'));
        if ($href === '' || str_starts_with($href, '#')) {
            return;
        }
        $abs = $this->abs($href);
        if ($abs === null) {
            // mailto:, tel: und Co. führen nicht aus dem Fenster heraus.
            $el->removeAttribute('href');

            return;
        }
        $el->setAttribute('href', ($this->pageLink)($abs));
    }

    private function rewriteForm(\DOMDocument $doc, \DOMElement $form): void
    {
        $method = strtolower($form->getAttribute('method') ?: 'get');
        if ($method !== 'get') {
            // Nur Lesen: POST-Formulare (Anmelden, Kommentieren) sind aus.
            foreach (iterator_to_array($form->getElementsByTagName('*')) as $c) {
                if ($c instanceof \DOMElement && in_array(strtolower($c->localName ?? ''), ['input', 'button', 'select', 'textarea'], true)) {
                    $c->setAttribute('disabled', 'disabled');
                }
            }
            $form->removeAttribute('action');

            return;
        }
        $target = $this->abs($form->getAttribute('action') ?: $this->baseUrl) ?? $this->baseUrl;
        // Das Formular schickt seine Felder an den Proxy; __kw_u trägt die Zieladresse.
        $proxy = ($this->pageLink)(strtok($target, '?') ?: $target);
        $form->setAttribute('action', strtok($proxy, '?') ?: $proxy);
        $form->setAttribute('method', 'get');
        parse_str((string) parse_url($proxy, PHP_URL_QUERY), $q);
        foreach (['__kw_u' => strtok($target, '?') ?: $target, 'r' => (string) ($q['r'] ?? '')] as $name => $value) {
            if ($value === '') {
                continue;
            }
            $hidden = $doc->createElement('input');
            $hidden->setAttribute('type', 'hidden');
            $hidden->setAttribute('name', $name);
            $hidden->setAttribute('value', $value);
            $form->insertBefore($hidden, $form->firstChild);
        }
    }

    private function srcset(string $srcset): string
    {
        $parts = [];
        foreach (preg_split('/,\s+(?=\S)/', trim($srcset)) ?: [] as $candidate) {
            $bits = preg_split('/\s+/', trim($candidate), 2) ?: [];
            $abs = isset($bits[0]) ? $this->abs($bits[0]) : null;
            if ($abs !== null) {
                $parts[] = ($this->assetLink)($abs) . (isset($bits[1]) ? ' ' . $bits[1] : '');
            }
        }

        return implode(', ', $parts);
    }

    private function addToolbar(\DOMDocument $doc, ?string $back): void
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            $body = $doc->createElement('body');
            ($doc->documentElement ?? $doc)->appendChild($body);
        }
        $bar = $doc->createElement('div');
        $bar->setAttribute('style', 'all:initial;display:flex;gap:12px;align-items:center;box-sizing:border-box;width:100%;'
            . 'padding:6px 12px;margin:0 0 8px;background:#eef1f8;border-bottom:1px solid #c9d0e0;'
            . 'font:13px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1d2433;position:sticky;top:0;z-index:2147483647;');
        if ($back !== null) {
            $a = $doc->createElement('a', '← Zurück');
            $a->setAttribute('href', $back);
            $a->setAttribute('style', 'all:initial;cursor:pointer;font:600 13px system-ui,sans-serif;color:#1f3fa8;text-decoration:underline;');
            $bar->appendChild($a);
        }
        $addr = $doc->createElement('span');
        $addr->setAttribute('style', 'all:initial;font:12px ui-monospace,Consolas,monospace;color:#4a5468;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;');
        $addr->appendChild($doc->createTextNode(mb_substr($this->baseUrl, 0, 200)));
        $bar->appendChild($addr);
        $body->insertBefore($bar, $body->firstChild);
    }

    /** Genau ein <meta charset="utf-8"> vorn im Kopf (der Inhalt ist nach toUtf8 immer UTF-8). */
    private function setCharset(\DOMDocument $doc): void
    {
        foreach (iterator_to_array($doc->getElementsByTagName('meta')) as $m) {
            if ($m->hasAttribute('charset')) {
                $m->parentNode?->removeChild($m);
            }
        }
        $html = $doc->getElementsByTagName('html')->item(0);
        if ($html === null) {
            return;
        }
        $head = $doc->getElementsByTagName('head')->item(0);
        if ($head === null) {
            $head = $doc->createElement('head');
            $html->insertBefore($head, $html->firstChild);
        }
        $meta = $doc->createElement('meta');
        $meta->setAttribute('charset', 'utf-8');
        $head->insertBefore($meta, $head->firstChild);
    }

    public static function toUtf8(string $html, string $charset): string
    {
        if ($charset === '' && preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m) === 1) {
            $charset = strtolower($m[1]);
        }
        if ($charset !== '' && !in_array($charset, ['utf-8', 'utf8'], true)) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return $html;
    }

    /** Seite für gesperrte Adressen und Fehler (gleiches Aussehen wie die Leiste). */
    public static function messagePage(string $title, string $text, ?string $url, ?string $back): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;font:15px/1.5 system-ui,-apple-system,\'Segoe UI\',sans-serif;color:#1d2433;background:#fbfaf7">'
            . '<div style="max-width:560px;margin:48px auto;padding:24px 28px;border:1px solid #d8dbe4;border-left:4px solid #b4541a;border-radius:8px;background:#fff">'
            . '<h1 style="font-size:19px;margin:0 0 8px">' . $e($title) . '</h1>'
            . '<p style="margin:0 0 12px">' . $e($text) . '</p>'
            . ($url !== null ? '<p style="margin:0 0 16px;font:12px ui-monospace,Consolas,monospace;color:#5b6478;word-break:break-all">' . $e(mb_substr($url, 0, 300)) . '</p>' : '')
            . ($back !== null ? '<p style="margin:0"><a style="color:#1f3fa8;font-weight:600" href="' . $e($back) . '">← Zurück</a></p>' : '')
            . '</div></body></html>';
    }
}
