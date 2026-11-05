<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\Audit;
use App\Services\ExamAccess;
use App\Services\Settings;

/**
 * Anwendungs-Kontext: hält alle Kern-Dienste.
 */
final class Context
{
    /** Rechte an einzelnen Klausuren (Hauptlehrkraft / Aufsicht). */
    public readonly ExamAccess $exams;

    public function __construct(
        public readonly array $config,
        public readonly Database $db,
        public readonly Session $session,
        public readonly Csrf $csrf,
        public readonly Auth $auth,
        public readonly View $view,
        public readonly Settings $settings,
        public readonly Audit $audit,
    ) {
        $this->exams = new ExamAccess($db, $auth);
    }

    /** Basis-URL-bewusste absolute URL (Pfad muss mit / beginnen). */
    public function url(string $path = '/'): string
    {
        return ($this->config['app']['base_url'] ?? '') . $path;
    }

    /** Konfigurierte öffentliche Basis-Adresse (Schema + Host [+ Pfad]) oder null. */
    public function configuredPublicBase(): ?string
    {
        $candidate = $this->settings->get('public_base_url');
        if (is_string($candidate) && trim($candidate) !== '') {
            return rtrim(trim($candidate), '/');
        }

        return null;
    }

    /**
     * Öffentliche Adresse der Anwendungswurzel. Ein hinterlegter Wert gilt
     * vollständig; nur der Rückfall auf den Request-Host ergänzt BASE_URL.
     */
    public function publicBase(): string
    {
        $base = $this->configuredPublicBase();
        if ($base !== null) {
            return $base;
        }

        return Request::origin() . ($this->config['app']['base_url'] ?? '');
    }

    public function baseIsGuessed(): bool
    {
        return $this->configuredPublicBase() === null;
    }

    public function publicUrl(string $path = '/'): string
    {
        return $this->publicBase() . $path;
    }
}
