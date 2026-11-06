<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Core\Database;

/**
 * Freigegebene Webseiten für den eingebauten Browser (Default deny).
 *
 * Eine Freigabe ist eine Adresse mit Bezeichnung. Freigegeben ist alles, was mit dieser Adresse
 * beginnt (an Pfadgrenzen: /3/library erlaubt /3/library/os.html, aber nicht /3/libraryx), oder
 * mit exact nur genau diese Seite. http und https sowie "www." gelten als gleich.
 * Teilaufgaben erben die Freigaben ihrer Überschrift.
 */
final class WebSites
{
    public const MAX_SITES = 20;

    /**
     * Prüft und vereinheitlicht die Eingabe des Editors.
     *
     * @return list<array{url: string, label: string, exact: bool}>
     */
    public static function normalize(mixed $input): array
    {
        if ($input === null || $input === '' || $input === []) {
            return [];
        }
        if (!is_array($input)) {
            throw new \InvalidArgumentException('Die Webseiten müssen eine Liste sein.');
        }
        if (count($input) > self::MAX_SITES) {
            throw new \InvalidArgumentException('Höchstens ' . self::MAX_SITES . ' Webseiten je Aufgabe.');
        }
        $out = [];
        $seen = [];
        foreach (array_values($input) as $i => $row) {
            $row = is_array($row) ? $row : ['url' => $row];
            $raw = trim((string) ($row['url'] ?? ''));
            if ($raw === '') {
                continue;
            }
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
                $raw = 'https://' . $raw;
            }
            $url = self::canonical($raw);
            if ($url === null) {
                throw new \InvalidArgumentException('Webseite ' . ($i + 1) . ': „' . mb_substr($raw, 0, 80) . '“ ist keine gültige http- oder https-Adresse.');
            }
            $exact = filter_var($row['exact'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $key = $url . ($exact ? '#exact' : '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $label = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($row['label'] ?? '')) ?? '');
            $out[] = [
                'url' => $url,
                'label' => mb_substr($label !== '' ? $label : (string) parse_url($url, PHP_URL_HOST), 0, 80),
                'exact' => $exact,
            ];
        }

        return $out;
    }

    /**
     * Einheitliche Form einer Adresse: Schema und Host klein, Standardport weg, ohne Fragment,
     * Pfad mindestens "/". null = keine http(s)-Adresse.
     */
    public static function canonical(string $url): ?string
    {
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            return null;
        }
        $scheme = strtolower($p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $host = strtolower(rtrim($p['host'], '.'));
        if ($host === '' || preg_match('/^[a-z0-9.-]+$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
            return null;
        }
        $port = isset($p['port']) ? (int) $p['port'] : null;
        if ($port !== null && (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $port = null;
        }
        $path = $p['path'] ?? '/';
        $path = $path === '' ? '/' : $path;

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '') . $path . (isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '');
    }

    /** Host ohne "www." zum Vergleich. */
    private static function hostKey(string $host): string
    {
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Ist die Adresse durch eine der Freigaben erlaubt?
     *
     * @param list<array{url: string, label?: string, exact?: bool}> $sites
     */
    public static function allows(array $sites, string $url): bool
    {
        $target = self::canonical($url);
        if ($target === null) {
            return false;
        }
        $t = parse_url($target);
        $tHost = self::hostKey((string) $t['host']) . (isset($t['port']) ? ':' . $t['port'] : '');
        $tPath = (string) ($t['path'] ?? '/');
        $tQuery = (string) ($t['query'] ?? '');
        foreach ($sites as $site) {
            $rule = self::canonical((string) ($site['url'] ?? ''));
            if ($rule === null) {
                continue;
            }
            $r = parse_url($rule);
            if (self::hostKey((string) $r['host']) . (isset($r['port']) ? ':' . $r['port'] : '') !== $tHost) {
                continue;
            }
            $rPath = (string) ($r['path'] ?? '/');
            $rQuery = (string) ($r['query'] ?? '');
            if (!empty($site['exact'])) {
                if (rawurldecode($rPath) === rawurldecode($tPath) && ($rQuery === '' || $rQuery === $tQuery)) {
                    return true;
                }
                continue;
            }
            if ($rQuery !== '' && $rQuery !== $tQuery) {
                continue;
            }
            if ($rPath === '/' || $tPath === $rPath) {
                return true;
            }
            $prefix = str_ends_with($rPath, '/') ? $rPath : $rPath . '/';
            // Seiten wie /3/library.html gehören zu /3/library? Nein: nur an Pfadgrenzen.
            if (str_starts_with($tPath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{url: string, label: string, exact: bool}> */
    public static function decode(mixed $json): array
    {
        $v = is_string($json) ? json_decode($json, true) : $json;

        return is_array($v) ? array_values(array_filter($v, static fn ($s): bool => is_array($s) && isset($s['url']))) : [];
    }

    /**
     * Wirksame Freigaben einer Aufgabe: eigene plus die aller Überschriften darüber.
     *
     * @return list<array{url: string, label: string, exact: bool}>
     */
    public static function effective(Database $db, int $examId, int $taskId): array
    {
        $out = [];
        $seen = [];
        $id = $taskId;
        for ($depth = 0; $depth < 10 && $id > 0; $depth++) {
            $row = $db->fetchOne('SELECT parent_id, web_sites FROM tasks WHERE id = ? AND exam_id = ?', [$id, $examId]);
            if ($row === null) {
                break;
            }
            foreach (self::decode($row['web_sites']) as $s) {
                $k = $s['url'] . (!empty($s['exact']) ? '#exact' : '');
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $out[] = $s;
                }
            }
            $id = $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        }

        return $out;
    }
}
