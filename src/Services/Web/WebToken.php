<?php

declare(strict_types=1);

namespace App\Services\Web;

use App\Services\Settings;

/**
 * Zugangsmarke des eingebauten Browsers, steht im Pfad jeder Proxy-Adresse.
 *
 * Das Browserfenster ist abgeschottet (eigener, undurchsichtiger Ursprung) und schickt keine
 * Sitzungs-Cookies mit. Die Marke bindet jede Anfrage an Klausur, Aufgabe und Person (Teilnahme
 * oder Lehrkraft in der Vorschau) und läuft nach einigen Stunden ab. Geprüft wird bei jeder
 * Anfrage zusätzlich, ob die Teilnahme noch läuft.
 *
 * Bilder und Stylesheets einer freigegebenen Seite liegen oft auf anderen Adressen. Sie gehen nur
 * mit einer Signatur durch (asset()), die der Umschreiber beim Ausliefern der Seite vergibt.
 */
final class WebToken
{
    public const TTL = 6 * 3600;
    private const SETTING = 'web_proxy_key';

    public function __construct(private readonly string $key)
    {
    }

    public static function fromSettings(Settings $settings): self
    {
        $key = $settings->get(self::SETTING);
        if ($key === null || strlen($key) < 64) {
            $key = bin2hex(random_bytes(32));
            $settings->set(self::SETTING, $key);
        }

        return new self($key);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    /** @param 'p'|'u' $kind p = Teilnahme, u = Lehrkraft (Vorschau) */
    public function issue(int $examId, int $taskId, string $kind, int $subjectId, ?int $now = null): string
    {
        $payload = self::b64(json_encode(['e' => $examId, 't' => $taskId, 'k' => $kind, 'i' => $subjectId, 'x' => ($now ?? time()) + self::TTL], JSON_THROW_ON_ERROR));

        return $payload . '.' . self::b64(hash_hmac('sha256', 't|' . $payload, $this->key, true));
    }

    /** @return array{e: int, t: int, k: string, i: int, x: int}|null */
    public function verify(string $token, ?int $now = null): ?array
    {
        if (strlen($token) > 400 || preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/', $token, $m) !== 1) {
            return null;
        }
        $expected = self::b64(hash_hmac('sha256', 't|' . $m[1], $this->key, true));
        if (!hash_equals($expected, $m[2])) {
            return null;
        }
        $json = self::unb64($m[1]);
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data) || !isset($data['e'], $data['t'], $data['k'], $data['i'], $data['x'])
            || !in_array($data['k'], ['p', 'u'], true) || (int) $data['x'] < ($now ?? time())) {
            return null;
        }

        return ['e' => (int) $data['e'], 't' => (int) $data['t'], 'k' => (string) $data['k'], 'i' => (int) $data['i'], 'x' => (int) $data['x']];
    }

    /** Signatur für ein Bild, Stylesheet oder eine Schrift, die eine freigegebene Seite einbindet. */
    public function asset(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', 'a|' . $token . '|' . $url, $this->key), 0, 32);
    }

    public function assetValid(string $token, string $url, string $sig): bool
    {
        return strlen($sig) === 32 && hash_equals($this->asset($token, $url), $sig);
    }
}
