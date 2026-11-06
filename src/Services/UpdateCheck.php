<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Context;

/**
 * Fragt nach, ob es eine neuere Klausurwerk-Version gibt.
 *
 * Quelle ist CHANGELOG.md im Repo (Konfiguration updates.url, Umgebungsvariable
 * UPDATE_CHECK_URL, "off" schaltet ab). Die oberste Version dort ist die neueste.
 * Übertragen wird nichts außer der Anfrage selbst (Adresse des Servers, User-Agent mit Version).
 *
 * Wann geprüft wird: bei jeder Anmeldung eines Admins, einmal täglich, sobald ein Admin eine
 * Seite öffnet, und über "Nach neuen Versionen suchen" im Versionsdialog. Es gibt keinen
 * Hintergrunddienst; die Seite stößt die Prüfung per POST /api/version/pruefen an, damit die
 * Anmeldung nie auf GitHub warten muss. Das Ergebnis liegt in der settings-Tabelle und gilt für
 * alle Admins.
 *
 * Schonung der Quelle (GitHub-Grenzen): raw.githubusercontent.com statt der REST-API (die erlaubt
 * ohne Anmeldung nur 60 Anfragen je Stunde), höchstens eine Anfrage je Minute (MIN_GAP) für die
 * ganze Installation, bedingte Anfragen (If-None-Match, unveränderte Datei = 304 ohne Inhalt) und
 * nach HTTP 429/403 Pause gemäß Retry-After, mindestens BACKOFF Sekunden, auch für den Knopf.
 *
 * Ein Update einspielen kann die Weboberfläche bewusst nicht: Das läuft auf dem Server mit
 * ./update.sh, damit es nie in eine laufende Klausur fällt (KONZEPT.md Abschnitt 23).
 */
final class UpdateCheck
{
    /** Einstellung: automatisch prüfen (Standard an). */
    public const SETTING_ENABLED = 'update_check_enabled';
    /** Sitzungsmarke: Admin hat sich gerade angemeldet. */
    public const SESSION_DUE = '_update_check_due';

    public const DAILY = 86400;
    /** Gemeinsames Limit der Installation: höchstens eine Anfrage je Minute, ob Anmeldung oder Knopf. */
    public const MIN_GAP = 60;
    public const LOGIN_GAP = self::MIN_GAP;
    public const MANUAL_GAP = self::MIN_GAP;
    /** Mindestpause nach HTTP 429/403. */
    public const BACKOFF = 3600;
    /** Höchstpause, falls ein Retry-After absurd groß ist. */
    private const BACKOFF_MAX = 86400;
    private const MAX_BYTES = 262144;

    private const S_CHECKED = 'update_checked_at';
    private const S_LATEST = 'update_latest_version';
    private const S_NEWS = 'update_news';
    private const S_ERROR = 'update_error';
    private const S_ETAG = 'update_etag';
    private const S_BLOCKED = 'update_blocked_until';

    /** @var (\Closure(string, ?string): (string|array{status: int, body: string, etag: ?string, retryAfter: ?int}))|null */
    private ?\Closure $fetcher;

    /**
     * @param (\Closure(string, ?string): (string|array{status: int, body: string, etag: ?string, retryAfter: ?int}))|null $fetcher
     *        Für Tests: bekommt Adresse und ETag, liefert den Text (= HTTP 200) oder eine Antwort
     *        als Array, oder wirft \RuntimeException (keine Verbindung).
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly string $url,
        ?\Closure $fetcher = null,
        private readonly ?string $installed = null,
    ) {
        $this->fetcher = $fetcher;
    }

    public static function fromContext(Context $ctx): self
    {
        return new self($ctx->settings, (string) ($ctx->config['updates']['url'] ?? ''));
    }

    public function installedVersion(): string
    {
        return $this->installed ?? Changelog::currentVersion();
    }

    /** Ist eine Quelle eingerichtet? (UPDATE_CHECK_URL=off schaltet die Prüfung ganz ab.) */
    public function configured(): bool
    {
        return $this->url !== '' && strtolower($this->url) !== 'off';
    }

    /** Automatische Prüfung an? */
    public function enabled(): bool
    {
        return $this->configured() && $this->settings->getBool(self::SETTING_ENABLED, true);
    }

    /** Steht eine automatische Prüfung an? Nach der Anmeldung früher als sonst. */
    public function isDue(bool $afterLogin, ?int $now = null): bool
    {
        $now ??= time();
        if (!$this->enabled() || $this->blockedUntil() > $now) {
            return false;
        }
        $checked = $this->settings->getInt(self::S_CHECKED);
        $gap = $afterLogin ? self::LOGIN_GAP : self::DAILY;

        return $checked === 0 || $now - $checked >= $gap;
    }

    /** Darf "Nach neuen Versionen suchen" jetzt wirklich fragen? */
    public function manualAllowed(?int $now = null): bool
    {
        $now ??= time();
        if (!$this->configured() || $this->blockedUntil() > $now) {
            return false;
        }
        $checked = $this->settings->getInt(self::S_CHECKED);

        return $checked === 0 || $now - $checked >= self::MANUAL_GAP;
    }

    /** Bis wann (Unix-Zeit) nach einer Drosselung durch die Quelle nicht gefragt wird; 0 = frei. */
    public function blockedUntil(): int
    {
        return $this->settings->getInt(self::S_BLOCKED);
    }

    /** Fragt die Quelle ab und speichert das Ergebnis. Wirft nicht; Fehler stehen in state()['error']. */
    public function run(?int $now = null): void
    {
        if (!$this->configured()) {
            return;
        }
        $now ??= time();
        // Zuerst den Zeitpunkt setzen: Gleichzeitige Anfragen anderer Admins fragen dann nicht auch noch.
        $this->settings->set(self::S_CHECKED, (string) $now);

        $etag = $this->settings->get(self::S_ETAG);
        // Ohne gespeicherten Stand ist ein 304 nicht verwertbar: dann ohne ETag fragen.
        if ($this->settings->get(self::S_LATEST) === null) {
            $etag = null;
        }

        try {
            $response = $this->fetcher !== null ? ($this->fetcher)($this->url, $etag) : $this->download($this->url, $etag);
            if (is_string($response)) {
                $response = ['status' => 200, 'body' => $response, 'etag' => null, 'retryAfter' => null];
            }
            $status = $response['status'];
            $host = (string) (parse_url($this->url, PHP_URL_HOST) ?: $this->url);

            if ($status === 429 || $status === 403) {
                $wait = max(self::BACKOFF, min((int) ($response['retryAfter'] ?? 0), self::BACKOFF_MAX));
                $this->settings->set(self::S_BLOCKED, (string) ($now + $wait));
                throw new \RuntimeException(sprintf(
                    '%s bremst gerade Anfragen (HTTP %d). Nächster Versuch ab %s Uhr.',
                    $host,
                    $status,
                    date('d.m.Y, H:i', $now + $wait),
                ));
            }
            $this->settings->set(self::S_BLOCKED, null);

            if ($status === 304) {
                // Unverändert seit der letzten Prüfung: gespeicherter Stand gilt weiter.
                $this->settings->set(self::S_ERROR, null);

                return;
            }
            if ($status !== 200) {
                throw new \RuntimeException($host . ' antwortete mit HTTP ' . $status . '.');
            }

            $entries = Changelog::parse($response['body']);
            if ($entries === []) {
                throw new \RuntimeException('Die Antwort enthielt keine Versionsnummer.');
            }
            // Alle Einträge ab der installierten Version merken, damit nach einem Update und
            // einem späteren 304 noch die richtigen Änderungen gezeigt werden können.
            $news = Changelog::newerThan($entries, $this->installedVersion());
            $this->settings->set(self::S_LATEST, $entries[0]['version']);
            $this->settings->set(self::S_NEWS, $news === [] ? null : json_encode(array_slice($news, 0, 20), JSON_UNESCAPED_UNICODE));
            $this->settings->set(self::S_ETAG, $this->validEtag($response['etag'] ?? null));
            $this->settings->set(self::S_ERROR, null);
        } catch (\RuntimeException $e) {
            $this->settings->set(self::S_ERROR, mb_substr($e->getMessage(), 0, 300));
        }
    }

    /**
     * Stand für Anzeige und API.
     *
     * @return array{installed: string, latest: ?string, available: bool, checkedAt: ?int, error: ?string,
     *               enabled: bool, configured: bool, blockedUntil: ?int,
     *               news: list<array{version: string, date: ?string, items: list<string>}>}
     */
    public function state(?int $now = null): array
    {
        $installed = $this->installedVersion();
        $latest = $this->settings->get(self::S_LATEST);
        if ($latest !== null && !preg_match('/^\d+\.\d+\.\d+$/', $latest)) {
            $latest = null;
        }
        $available = $this->configured() && $latest !== null && version_compare($latest, $installed, '>');

        $news = [];
        if ($available) {
            $decoded = json_decode((string) $this->settings->get(self::S_NEWS), true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (is_array($entry) && is_string($entry['version'] ?? null) && is_array($entry['items'] ?? null)) {
                        $news[] = [
                            'version' => $entry['version'],
                            'date' => is_string($entry['date'] ?? null) ? $entry['date'] : null,
                            'items' => array_values(array_filter($entry['items'], 'is_string')),
                        ];
                    }
                }
            }
            // Nach einem Teil-Update nur zeigen, was noch fehlt.
            $news = Changelog::newerThan($news, $installed);
        }
        $checked = $this->settings->getInt(self::S_CHECKED);
        $blocked = $this->blockedUntil();

        return [
            'installed' => $installed,
            'latest' => $latest,
            'available' => $available,
            'checkedAt' => $checked > 0 ? $checked : null,
            'error' => $this->settings->get(self::S_ERROR),
            'enabled' => $this->enabled(),
            'configured' => $this->configured(),
            'blockedUntil' => $blocked > ($now ?? time()) ? $blocked : null,
            'news' => $news,
        ];
    }

    private function validEtag(?string $etag): ?string
    {
        $etag = trim((string) $etag);

        return $etag !== '' && strlen($etag) <= 200 && preg_match('/^(W\/)?"[\x21\x23-\x7e]*"$/', $etag) ? $etag : null;
    }

    /** @return array{status: int, body: string, etag: ?string, retryAfter: ?int} */
    private function download(string $url, ?string $etag): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
            throw new \RuntimeException('Die Adresse für die Update-Prüfung (UPDATE_CHECK_URL) ist ungültig.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP-Erweiterung curl fehlt.');
        }

        $body = '';
        $tooLarge = false;
        $headers = [];
        $requestHeaders = ['Accept: text/plain, text/markdown, */*'];
        if ($etag !== null && $this->validEtag($etag) !== null) {
            $requestHeaders[] = 'If-None-Match: ' . $etag;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'Klausurwerk/' . $this->installedVersion() . ' (Update-Prüfung)',
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) {
            throw new \RuntimeException('Die Antwort von ' . $host . ' war zu groß.');
        }
        if ($ok === false) {
            throw new \RuntimeException('Keine Verbindung zu ' . $host . ($error !== '' ? ' (' . $error . ')' : '') . '.');
        }
        $retryAfter = $headers['retry-after'] ?? null;

        return [
            'status' => $status,
            'body' => $body,
            'etag' => $headers['etag'] ?? null,
            'retryAfter' => $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
        ];
    }
}
