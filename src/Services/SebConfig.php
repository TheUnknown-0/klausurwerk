<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Safe Exam Browser: .seb-Datei (unverschlüsselte XML-plist) und Config Key.
 *
 * Config Key nach https://safeexambrowser.org/developer/seb-config-key.html :
 *  1. originatorVersion und leere Dictionaries entfernen,
 *  2. Schlüssel auf jeder Ebene alphabetisch sortieren (ohne Beachtung der Groß-/
 *     Kleinschreibung; bei Gleichheit Kleinbuchstabe vor Großbuchstabe),
 *  3. als JSON ohne Leerraum und ohne zusätzliches Escaping (Schrägstriche und
 *     Unicode bleiben unverändert) serialisieren,
 *  4. SHA-256 über die UTF-8-Bytes, als kleingeschriebener Hex-String (64 Zeichen).
 *
 * Header-Prüfung einer Anfrage: SHA-256(absolute URL ohne Fragment . Config Key)
 * muss dem Wert von X-SafeExamBrowser-ConfigKeyHash entsprechen.
 *
 * Hinweis: SEB berechnet den Key aus der geladenen Konfiguration inklusive seiner
 * eigenen Standardwerte. Weicht der Key im Browser ab, lässt sich der angezeigte
 * Key in der Oberfläche manuell hinterlegen.
 */
final class SebConfig
{
    public const HEADER = 'X-SafeExamBrowser-ConfigKeyHash';

    /**
     * @param array<string, mixed> $exam Klausurzeile (id, title)
     * @return array{xml: string, config_key: string}
     */
    public static function build(array $exam, string $publicBase, string $quitPassword): array
    {
        $publicBase = rtrim($publicBase, '/');
        $parts = parse_url($publicBase);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? 'localhost');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        $settings = [
            'startURL' => $publicBase . '/klausur/' . (int) ($exam['id'] ?? 0),
            'quitURL' => $publicBase . '/seb-beenden',
            'sendBrowserExamKey' => true,
            'allowQuit' => true,
            'ignoreExitKeys' => true,
            'URLFilterEnable' => true,
            'URLFilterEnableContentFilter' => false,
            'urlFilterRules' => [[
                'action' => 1,
                'active' => true,
                'expression' => $scheme . '://' . $host . $port . $path . '/*',
                'regex' => false,
            ]],
            'newBrowserWindowByLinkPolicy' => 2,
            'newBrowserWindowByScriptPolicy' => 2,
            'browserViewMode' => 1,
            'mainBrowserWindowWidth' => '100%',
            'mainBrowserWindowHeight' => '100%',
            'enableBrowserWindowToolbar' => false,
            'hideBrowserWindowToolbar' => true,
            'showTaskBar' => true,
            'showTime' => true,
            'showInputLanguage' => false,
            'showReloadButton' => true,
            'allowBrowsingBackForward' => false,
            'browserWindowAllowReload' => true,
            'enableRightMouse' => false,
            'enablePrintScreen' => false,
            'enablePrint' => false,
            'allowSpellCheck' => false,
            'allowDictionaryLookup' => false,
            'allowDeveloperConsole' => false,
            'allowDownUploads' => false,
            'allowPDFReaderToolbar' => false,
            'allowCustomDownloadLocation' => false,
            'allowScreenSharing' => false,
            'allowVirtualMachine' => false,
            'allowPreferencesWindow' => false,
            'enableAltTab' => false,
            'enableAltEsc' => false,
            'enableAltF4' => false,
            'enableCtrlEsc' => false,
            'enableEsc' => false,
            'enableF1' => false,
            'enableF2' => false,
            'enableF3' => false,
            'enableF4' => false,
            'enableF5' => false,
            'enableF6' => false,
            'enableF7' => false,
            'enableF8' => false,
            'enableF9' => false,
            'enableF10' => false,
            'enableF11' => false,
            'enableF12' => false,
            'examSessionClearCookiesOnStart' => true,
            'examSessionClearCookiesOnEnd' => true,
            'enableLogging' => true,
            'originatorVersion' => 'Klausurwerk',
        ];
        if ($quitPassword !== '') {
            $settings['hashedQuitPassword'] = hash('sha256', $quitPassword);
        }

        return [
            'xml' => self::toPlist($settings),
            'config_key' => self::configKey($settings),
        ];
    }

    /** Header-Prüfung (siehe Klassenkommentar). */
    public static function verifyRequest(string $configKeyHex, string $absoluteUrl, ?string $headerHash): bool
    {
        if ($headerHash === null || $headerHash === '' || $configKeyHex === '') {
            return false;
        }
        $hashPos = strpos($absoluteUrl, '#');
        if ($hashPos !== false) {
            $absoluteUrl = substr($absoluteUrl, 0, $hashPos);
        }
        $expected = hash('sha256', $absoluteUrl . $configKeyHex);

        return hash_equals($expected, strtolower(trim($headerHash)));
    }

    /** @param array<string, mixed> $settings */
    public static function configKey(array $settings): string
    {
        return hash('sha256', self::sebJson($settings));
    }

    /** SEB-JSON: sortiert, ohne Leerraum, ohne zusätzliches Escaping. @param array<string, mixed> $settings */
    public static function sebJson(array $settings): string
    {
        unset($settings['originatorVersion']);

        return self::encode(self::prune($settings));
    }

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** Entfernt leere Dictionaries (rekursiv). */
    private static function prune(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
            if ($value === []) {
                return $value;
            }
        }
        if (!is_array($value)) {
            return $value;
        }
        if (self::isList($value)) {
            return array_map(self::prune(...), $value);
        }
        $out = [];
        $orig = $value;
        foreach ($value as $k => $v) {
            $v = self::prune($v);
            if ($v === [] && $orig[$k] instanceof \stdClass) {
                continue;
            }
            $out[(string) $k] = $v;
        }

        return $out;
    }

    private static function encode(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
        }
        if (is_array($value)) {
            if (self::isList($value)) {
                return '[' . implode(',', array_map(self::encode(...), $value)) . ']';
            }
            $keys = array_keys($value);
            usort($keys, static function (string|int $a, string|int $b): int {
                $a = (string) $a;
                $b = (string) $b;
                $c = strcmp(mb_strtolower($a), mb_strtolower($b));

                // Gleiche Schreibweise bis auf Groß/Klein: Kleinbuchstabe zuerst.
                return $c !== 0 ? $c : -strcmp($a, $b);
            });
            $parts = [];
            foreach ($keys as $k) {
                $parts[] = json_encode((string) $k, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':' . self::encode($value[$k]);
            }

            return '{' . implode(',', $parts) . '}';
        }

        return 'null';
    }

    // ---------- plist ----------

    /** @param array<string, mixed> $settings */
    public static function toPlist(array $settings): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
            . '<plist version="1.0">' . "\n"
            . self::plistValue($settings, 0)
            . '</plist>' . "\n";

        return $xml;
    }

    private static function plistValue(mixed $value, int $depth): string
    {
        $pad = str_repeat("\t", $depth);
        if (is_bool($value)) {
            return $pad . ($value ? '<true/>' : '<false/>') . "\n";
        }
        if (is_int($value)) {
            return $pad . '<integer>' . $value . '</integer>' . "\n";
        }
        if (is_float($value)) {
            return $pad . '<real>' . $value . '</real>' . "\n";
        }
        if (is_string($value)) {
            return $pad . '<string>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string>' . "\n";
        }
        if (is_array($value) && self::isList($value)) {
            $out = $pad . "<array>\n";
            foreach ($value as $item) {
                $out .= self::plistValue($item, $depth + 1);
            }

            return $out . $pad . "</array>\n";
        }
        $out = $pad . "<dict>\n";
        $keys = array_keys((array) $value);
        sort($keys, SORT_STRING | SORT_FLAG_CASE);
        foreach ($keys as $k) {
            $out .= $pad . "\t<key>" . htmlspecialchars((string) $k, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</key>\n";
            $out .= self::plistValue($value[$k], $depth + 1);
        }

        return $out . $pad . "</dict>\n";
    }
}
