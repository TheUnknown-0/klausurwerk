<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Datei-Uploads: Validierung (Extension + echter MIME-Typ + Bildprüfung),
 * Zufallsdateinamen, Ablage AUSSERHALB des Webroots (uploads/).
 * Auslieferung ausschließlich über kontrollierte Endpunkte.
 */
final class Uploads
{
    /** @var array<string, list<string>> Erlaubte Extensions => erlaubte MIME-Typen. */
    private const ALLOWED = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml'],
    ];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(private readonly string $baseDir)
    {
    }

    /**
     * Validiert und speichert eine hochgeladene Datei.
     *
     * @param array $file Eintrag aus $_FILES
     * @param 'logos'|'branding' $subdir
     * @param list<string>|null $allowedExtensions Einschränkung (z. B. nur Bilder für Logos)
     * @return array{filename: string, original_name: string, mime: string, size: int}
     */
    public function store(array $file, string $subdir, ?array $allowedExtensions = null, int $maxBytes = 10485760): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'Der Upload ist fehlgeschlagen. Bitte versuche es erneut.');
        }
        if (($file['size'] ?? 0) > $maxBytes) {
            throw new HttpException(400, 'Die Datei ist zu groß (max. ' . round($maxBytes / 1048576) . ' MB).');
        }

        $original = (string) ($file['name'] ?? 'datei');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        $allowed = $allowedExtensions ?? array_keys(self::ALLOWED);
        if (!isset(self::ALLOWED[$ext]) || !in_array($ext, $allowed, true)) {
            throw new HttpException(400, 'Dieser Dateityp ist nicht erlaubt.');
        }

        // Echten MIME-Typ prüfen (nicht dem Client vertrauen)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED[$ext], true)) {
            throw new HttpException(400, 'Der Dateiinhalt passt nicht zum Dateityp.');
        }
        if (in_array($ext, self::IMAGE_EXTENSIONS, true) && @getimagesize($file['tmp_name']) === false) {
            throw new HttpException(400, 'Die Bilddatei ist beschädigt.');
        }

        $dir = $this->baseDir . '/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new HttpException(500, 'Upload-Verzeichnis konnte nicht angelegt werden.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
            throw new HttpException(500, 'Die Datei konnte nicht gespeichert werden.');
        }

        return [
            'filename' => $filename,
            'original_name' => $original,
            'mime' => $mime,
            'size' => (int) $file['size'],
        ];
    }

    /**
     * Absoluter Pfad zu einer gespeicherten Datei — mit Schutz gegen
     * Path-Traversal (nur einfacher Dateiname erlaubt).
     */
    public function path(string $subdir, string $filename): string
    {
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            throw new HttpException(400);
        }

        return $this->baseDir . '/' . $subdir . '/' . $filename;
    }

    public function delete(string $subdir, string $filename): void
    {
        $path = $this->path($subdir, $filename);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** Streamt eine Datei mit korrektem Content-Type an den Browser. */
    public function stream(string $subdir, string $filename, ?string $downloadName = null): never
    {
        $path = $this->path($subdir, $filename);
        if (!is_file($path)) {
            throw new HttpException(404);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
        // SVG nie inline ausliefern (Script-Risiko)
        $inlineSafe = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true);

        header('Content-Type: ' . ($inlineSafe ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        if ($downloadName !== null || !$inlineSafe) {
            $name = $downloadName ?? $filename;
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        }
        readfile($path);
        exit;
    }
}
