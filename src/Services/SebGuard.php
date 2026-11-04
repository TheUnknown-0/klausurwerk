<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Safe-Exam-Browser-Prüfung. Verlangt die Klausur SEB (settings.require_seb), muss jede Anfrage den
 * Header X-SafeExamBrowser-ConfigKeyHash mitbringen. Geprüft wird gegen exams.seb_config_key mit der
 * absoluten URL, wie der Browser sie sieht (Herkunft aus public_base_url bzw. Request-Host plus REQUEST_URI).
 *
 * Der Config Key ist aus der .seb-Datei berechenbar und beweist deshalb nicht, dass wirklich SEB läuft
 * (er schützt nur gegen Versehen). Hinterlegt die Lehrkraft zusätzlich den Browser Exam Key (BEK,
 * exams.seb_browser_exam_key), wird auch X-SafeExamBrowser-RequestHash = SHA-256(absolute URL . BEK)
 * verlangt; den BEK kann nur ein echter SEB mit dieser Konfiguration berechnen.
 * Bei Fehler: 403 und, wenn die Teilnahme bekannt ist, das Ereignis seb_missing.
 */
final class SebGuard
{
    public const HEADER = 'HTTP_X_SAFEEXAMBROWSER_CONFIGKEYHASH';
    /** Header mit SHA-256(absolute URL . Browser Exam Key); nur geprüft, wenn die Klausur einen BEK hinterlegt hat. */
    public const REQUEST_HASH_HEADER = 'HTTP_X_SAFEEXAMBROWSER_REQUESTHASH';

    /** @var callable(string, string, ?string): bool */
    private $verifier;

    /** @param (callable(string, string, ?string): bool)|null $verifier Prüffunktion (Standard: SebConfig::verifyRequest) */
    public function __construct(
        private readonly ?string $configuredPublicBase,
        private readonly EventLog $events,
        ?callable $verifier = null,
    ) {
        $this->verifier = $verifier ?? SebConfig::verifyRequest(...);
    }

    public static function requiresSeb(array $exam): bool
    {
        return !empty(ExamAccess::settingsOf($exam)['require_seb']);
    }

    /** Hat die Anfrage den SEB-Header (unabhängig von der Gültigkeit)? */
    public static function headerPresent(): bool
    {
        $value = $_SERVER[self::HEADER] ?? '';

        return is_string($value) && $value !== '';
    }

    /** Ist die Anfrage gültig (oder SEB nicht verlangt)? */
    public function passes(array $exam): bool
    {
        if (!self::requiresSeb($exam)) {
            return true;
        }
        $key = (string) ($exam['seb_config_key'] ?? '');
        $header = $_SERVER[self::HEADER] ?? null;
        $url = $this->absoluteUrl();
        if ($key === '' || !($this->verifier)($key, $url, is_string($header) ? $header : null)) {
            return false;
        }
        $bek = strtolower(trim((string) ($exam['seb_browser_exam_key'] ?? '')));
        if ($bek === '') {
            return true;
        }
        $requestHash = $_SERVER[self::REQUEST_HASH_HEADER] ?? null;

        return ($this->verifier)($bek, $url, is_string($requestHash) ? $requestHash : null);
    }

    /**
     * Wirft 403, wenn SEB verlangt und die Anfrage ungültig ist.
     *
     * @param array<string, mixed> $exam
     * @param array<string, mixed>|null $participation für das Ereignis seb_missing
     */
    public function enforce(array $exam, ?array $participation = null): void
    {
        if ($this->passes($exam)) {
            return;
        }
        if ($participation !== null) {
            $this->events->log(
                (int) $exam['id'],
                (int) $participation['id'],
                'seb_missing',
                'alarm',
                ['seb_header' => self::headerPresent()],
            );
        }

        throw new HttpException(403, 'Diese Klausur muss im Safe Exam Browser mit der Klausur-Konfiguration geöffnet werden.');
    }

    /** Absolute URL der aktuellen Anfrage, wie der Browser sie sieht. */
    public function absoluteUrl(): string
    {
        return self::origin($this->configuredPublicBase) . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    /** Herkunft (Schema + Host [+ Port]) aus der Einstellung public_base_url, sonst aus der Anfrage. */
    public static function origin(?string $configured): string
    {
        if ($configured !== null) {
            $parts = parse_url($configured);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }

        $https = (($_SERVER['HTTPS'] ?? 'off') !== 'off' && ($_SERVER['HTTPS'] ?? '') !== '')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
