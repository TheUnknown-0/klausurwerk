<?php

declare(strict_types=1);

namespace App\Services\Web;

/**
 * Holt freigegebene Seiten für den eingebauten Browser, mit Schutz gegen Missbrauch des Servers:
 *
 *  - nur http und https auf Port 80 und 443, keine Zugangsdaten in der Adresse
 *  - nur öffentliche Ziel-Adressen: Der Host wird selbst aufgelöst, private, lokale und reservierte
 *    Netze (Schulnetz, Docker, 127.0.0.1, 169.254.x) sind gesperrt; cURL verbindet genau zu der
 *    geprüften Adresse (kein zweites DNS, kein Rebinding)
 *  - Weiterleitungen einzeln: jede wird erneut geprüft, die Freigabe entscheidet der Aufrufer
 *  - Zeit- und Größengrenzen, keine Cookies, keine Daten der Schüler:innen an das Ziel
 *  - kurzer Zwischenspeicher: 30 Schüler:innen auf derselben Seite heißt ein Abruf
 */
final class WebFetcher
{
    public const MAX_PAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_ASSET_BYTES = 8 * 1024 * 1024;
    public const MAX_REDIRECTS = 5;
    private const CACHE_TTL = 900;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; Klausurwerk-Browser/1.0)';

    /** @var (callable(string): array<string>)|null Auflöser für Tests: Host => IP-Adressen */
    private $resolver;

    public function __construct(private readonly string $cacheDir, ?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /** Öffentliche Adresse? (keine privaten, lokalen, reservierten oder Dokumentationsnetze) */
    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        // IPv4 in IPv6 (::ffff:10.0.0.1) wie IPv4 prüfen.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(($this->resolver)($host));
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
            if (isset($r['ip'])) {
                $ips[] = (string) $r['ip'];
            }
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }
        if ($ips === []) {
            $ips = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }

    /**
     * Prüft Schema, Port und Ziel-Adresse.
     *
     * @return array{host: string, port: int, ip: string}
     */
    public function target(string $url): array
    {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !isset($p['host']) || isset($p['user']) || isset($p['pass'])) {
            throw new WebError('Nur http- und https-Adressen.');
        }
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            throw new WebError('Nur die üblichen Ports 80 und 443.');
        }
        $host = strtolower(trim($p['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            throw new WebError('Die Adresse ' . $host . ' ist nicht erreichbar (DNS).');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new WebError('Adressen im eigenen Netz sind gesperrt.');
            }
        }
        // IPv4 bevorzugen (Docker-Netze haben oft kein IPv6).
        usort($ips, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Ruft eine Adresse ab. $follow entscheidet je Weiterleitung, ob ihr gefolgt wird.
     *
     * @param callable(string): bool $follow
     * @return array{status: int, url: string, type: string, charset: string, body: string}
     */
    public function fetch(string $url, callable $follow, int $maxBytes): array
    {
        $key = hash('sha256', $url);
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            // Endete der Abruf damals nach einer Weiterleitung, gilt die Freigabe auch für deren Ziel.
            if ($cached['url'] !== $url && !$follow($cached['url'])) {
                throw new WebBlocked($cached['url']);
            }

            return $cached;
        }
        $current = $url;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $r = $this->once($current, $maxBytes);
            if ($r['status'] >= 300 && $r['status'] < 400 && $r['location'] !== '') {
                $next = WebRewriter::resolve($current, $r['location']);
                $canon = WebSites::canonical($next);
                if ($canon === null) {
                    throw new WebError('Die Seite leitet auf eine ungültige Adresse weiter.');
                }
                if (!$follow($canon)) {
                    throw new WebBlocked($canon);
                }
                $current = $canon;
                continue;
            }
            $out = ['status' => $r['status'], 'url' => $current, 'type' => $r['type'], 'charset' => $r['charset'], 'body' => $r['body']];
            if ($r['status'] === 200) {
                $this->cachePut($key, $out);
            }

            return $out;
        }
        throw new WebError('Zu viele Weiterleitungen.');
    }

    /** @return array{status: int, location: string, type: string, charset: string, body: string} */
    private function once(string $url, int $maxBytes): array
    {
        $t = $this->target($url);
        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$t['host'] . ':' . $t['port'] . ':' . (str_contains($t['ip'], ':') ? '[' . $t['ip'] . ']' : $t['ip'])],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,text/css,image/*;q=0.9,*/*;q=0.5', 'Accept-Language: de,en;q=0.7'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        $error = curl_error($ch);
        curl_close($ch);
        if ($tooBig) {
            throw new WebError('Die Seite ist zu groß für den Klausur-Browser.');
        }
        if ($ok === false) {
            throw new WebError('Die Seite antwortet nicht (' . mb_substr($error, 0, 120) . ').');
        }
        $charset = '';
        if (preg_match('/charset=([\w-]+)/i', $type, $m) === 1) {
            $charset = strtolower($m[1]);
        }

        return ['status' => $status, 'location' => $location, 'type' => trim(explode(';', $type)[0]), 'charset' => $charset, 'body' => $body];
    }

    // ---------- Zwischenspeicher ----------

    private function cachePath(string $key): string
    {
        return $this->cacheDir . '/' . substr($key, 0, 2) . '/' . $key;
    }

    /** @return array{status: int, url: string, type: string, charset: string, body: string}|null */
    private function cacheGet(string $key): ?array
    {
        $path = $this->cachePath($key);
        if (!is_file($path) || filemtime($path) < time() - self::CACHE_TTL) {
            return null;
        }
        $raw = @file_get_contents($path);
        $data = $raw !== false ? @unserialize($raw, ['allowed_classes' => false]) : false;

        return is_array($data) && isset($data['status'], $data['url'], $data['type'], $data['body']) ? $data + ['charset' => ''] : null;
    }

    /** @param array<string, mixed> $data */
    private function cachePut(string $key, array $data): void
    {
        $path = $this->cachePath($key);
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, serialize($data)) !== false) {
            @rename($tmp, $path);
        }
        // Gelegentlich Altes wegräumen.
        if (random_int(1, 200) === 1) {
            foreach (glob($this->cacheDir . '/*/*') ?: [] as $f) {
                if (is_file($f) && filemtime($f) < time() - 4 * self::CACHE_TTL) {
                    @unlink($f);
                }
            }
        }
    }
}
