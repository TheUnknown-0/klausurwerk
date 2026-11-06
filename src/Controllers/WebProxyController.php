<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\EventLog;
use App\Services\ExamAccess;
use App\Services\Web\WebBlocked;
use App\Services\Web\WebError;
use App\Services\Web\WebFetcher;
use App\Services\Web\WebRewriter;
use App\Services\Web\WebSites;
use App\Services\Web\WebToken;

/**
 * Eingebauter Browser: liefert freigegebene Webseiten in das abgeschottete Browserfenster der
 * Schüleransicht (Default deny, siehe WebSites).
 *
 * Kein Login-Guard: Das Fenster hat einen undurchsichtigen Ursprung und schickt keine Cookies.
 * Stattdessen trägt jede Adresse eine signierte Zugangsmarke (WebToken) für Klausur, Aufgabe und
 * Person; bei jeder Anfrage wird zusätzlich geprüft, ob die Teilnahme noch läuft bzw. die Lehrkraft
 * die Klausur sehen darf. Seiten laufen ohne JavaScript (CSP sandbox, Skripte entfernt).
 */
final class WebProxyController extends Controller
{
    /** Zustände, in denen Schüler:innen arbeiten und also auch nachschlagen dürfen. */
    private const WORKING_STATES = ['active', 'submit_requested'];

    private function tokens(): WebToken
    {
        return WebToken::fromSettings($this->ctx->settings);
    }

    private function send(string $body, string $type, int $status = 200, bool $document = true): string
    {
        http_response_code($status);
        header('Content-Type: ' . $type, true);
        header('Cache-Control: private, ' . ($document ? 'no-store' : 'max-age=600'), true);
        header('Referrer-Policy: no-referrer', true);
        // Eigene Herkunft ausdrücklich nennen: Im sandbox-Dokument ist der Ursprung undurchsichtig.
        $self = Request::origin();
        header('Content-Security-Policy: sandbox allow-forms; default-src \'none\'; img-src \'self\' ' . $self . ' data:; '
            . 'style-src \'self\' ' . $self . ' \'unsafe-inline\'; font-src \'self\' ' . $self . ' data:; '
            . 'form-action \'self\' ' . $self . '; frame-ancestors \'self\'; base-uri \'none\'', true);
        header('X-Frame-Options: SAMEORIGIN', true);
        // Das Fenster hat einen fremden (undurchsichtigen) Ursprung: Bilder und CSS brauchen das.
        header('Cross-Origin-Resource-Policy: cross-origin', true);

        return $body;
    }

    private function message(string $title, string $text, ?string $url = null, ?string $back = null, int $status = 200): string
    {
        return $this->send(WebRewriter::messagePage($title, $text, $url, $back), 'text/html; charset=utf-8', $status);
    }

    /**
     * Gilt die Marke noch? Rückgabe: Teilnahme-ID (Schüler:in), 0 (Lehrkraft) oder null.
     *
     * @param array{e: int, t: int, k: string, i: int, x: int} $t
     */
    private function subjectActive(array $t): ?int
    {
        if ($t['k'] === 'p') {
            $row = $this->ctx->db->fetchOne(
                'SELECT p.id, p.state, e.status FROM participations p JOIN exams e ON e.id = p.exam_id WHERE p.id = ? AND p.exam_id = ?',
                [$t['i'], $t['e']],
            );
            if ($row === null || $row['status'] !== 'running' || !in_array((string) $row['state'], self::WORKING_STATES, true)) {
                return null;
            }

            return (int) $row['id'];
        }
        $user = $this->ctx->db->fetchOne("SELECT * FROM users WHERE id = ? AND is_active = 1 AND role IN ('admin', 'teacher')", [$t['i']]);
        if ($user === null) {
            return null;
        }
        $this->ctx->auth->actAs($user);

        return $this->ctx->exams->can($t['e'], ExamAccess::CAP_VIEW) ? 0 : null;
    }

    /** Gesperrte Adresse im Pult melden (Hinweis, kein Verstoß), höchstens einmal je Minute und Adresse. */
    private function reportBlocked(int $examId, int $participationId, int $taskId, string $url): void
    {
        $url = mb_substr($url, 0, 500);
        $recent = $this->ctx->db->fetchValue(
            "SELECT id FROM participation_events WHERE participation_id = ? AND type = 'web_blocked'
               AND created_at > NOW() - INTERVAL 1 MINUTE AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.url')) = ? LIMIT 1",
            [$participationId, $url],
        );
        if ($recent === null) {
            (new EventLog($this->ctx->db))->log($examId, $participationId, 'web_blocked', 'warning', ['url' => $url, 'task_id' => $taskId]);
        }
    }

    /** GET /web/{token}?u=Adresse[&s=Signatur][&r=Zurück] */
    public function show(array $params): string
    {
        $tokenStr = (string) ($params['token'] ?? '');
        $tokens = $this->tokens();
        $t = $tokens->verify($tokenStr);
        if ($t === null) {
            return $this->message('Browser abgelaufen', 'Bitte das Browserfenster in der Klausur schließen und neu öffnen.', null, null, 403);
        }
        $participationId = $this->subjectActive($t);
        if ($participationId === null) {
            return $this->message('Browser nicht verfügbar', 'Der Klausur-Browser ist nur während der Bearbeitung geöffnet.', null, null, 403);
        }

        // Ziel: ?u=… oder aus einem Suchformular (__kw_u plus dessen Felder).
        $raw = (string) ($_GET['u'] ?? '');
        if (isset($_GET['__kw_u']) && is_string($_GET['__kw_u'])) {
            $fields = $_GET;
            unset($fields['__kw_u'], $fields['u'], $fields['r'], $fields['s']);
            $raw = $_GET['__kw_u'] . ($fields !== [] ? '?' . http_build_query($fields) : '');
        }
        $target = WebSites::canonical($raw);
        $back = isset($_GET['r']) && is_string($_GET['r']) && WebSites::canonical($_GET['r']) !== null
            ? $this->pageLink($tokenStr, $_GET['r'], null) : null;
        if ($target === null) {
            return $this->message('Ungültige Adresse', 'Diese Adresse kann der Klausur-Browser nicht öffnen.', mb_substr($raw, 0, 300), $back, 400);
        }

        $sites = WebSites::effective($this->ctx->db, $t['e'], $t['t']);
        $sig = isset($_GET['s']) && is_string($_GET['s']) ? $_GET['s'] : '';
        $asset = $sig !== '' && $tokens->assetValid($tokenStr, $target, $sig);
        if (!$asset && !WebSites::allows($sites, $target)) {
            if ($participationId > 0) {
                $this->reportBlocked($t['e'], $participationId, $t['t'], $target);
            }

            return $this->message('Seite nicht freigegeben', 'Für diese Aufgabe sind nur bestimmte Webseiten freigegeben. Diese gehört nicht dazu.', $target, $back, 403);
        }

        $fetcher = new WebFetcher(sys_get_temp_dir() . '/klausurwerk-web');
        try {
            $r = $fetcher->fetch(
                $target,
                static fn (string $next): bool => $asset || WebSites::allows($sites, $next),
                $asset ? WebFetcher::MAX_ASSET_BYTES : WebFetcher::MAX_PAGE_BYTES,
            );
        } catch (WebBlocked $e) {
            if ($participationId > 0) {
                $this->reportBlocked($t['e'], $participationId, $t['t'], $e->url);
            }

            return $this->message('Seite nicht freigegeben', 'Die Seite leitet auf eine Adresse weiter, die für diese Aufgabe nicht freigegeben ist.', $e->url, $back, 403);
        } catch (WebError $e) {
            return $asset ? $this->send('', 'text/plain', 502, false) : $this->message('Seite nicht erreichbar', $e->getMessage(), $target, $back, 502);
        }

        if ($r['status'] >= 400) {
            return $asset ? $this->send('', 'text/plain', $r['status'], false)
                : $this->message('Seite nicht erreichbar', 'Die Webseite antwortet mit Fehler ' . $r['status'] . '.', $r['url'], $back, 502);
        }
        $type = $r['type'];
        $rewriter = new WebRewriter(
            $r['url'],
            fn (string $u): string => $this->pageLink($tokenStr, $u, $r['url']),
            fn (string $u): string => $this->assetLink($tokens, $tokenStr, $u),
        );

        if (in_array($type, ['text/html', 'application/xhtml+xml'], true) || ($type === '' && !$asset)) {
            if ($asset) {
                return $this->send('', 'text/plain', 415, false);
            }

            return $this->send($rewriter->html($r['body'], $r['charset'], $back), 'text/html; charset=utf-8');
        }
        if ($type === 'text/css') {
            return $this->send($rewriter->css(WebRewriter::toUtf8($r['body'], $r['charset'])), 'text/css; charset=utf-8', 200, false);
        }
        if (preg_match('#^(image/(png|jpe?g|gif|webp|avif|svg\+xml|x-icon|vnd\.microsoft\.icon)|font/[a-z0-9]+|application/(font-woff2?|x-font-[a-z]+|vnd\.ms-fontobject))$#', $type) === 1) {
            return $this->send($r['body'], $type, 200, !$asset);
        }
        if ($type === 'text/plain' && !$asset) {
            return $this->send($r['body'], 'text/plain; charset=utf-8');
        }

        return $asset ? $this->send('', 'text/plain', 415, false)
            : $this->message('Dateityp nicht unterstützt', 'Der Klausur-Browser zeigt Webseiten, Bilder und Text, aber keine Dateien dieses Typs (' . ($type !== '' ? $type : 'unbekannt') . ').', $r['url'], $back, 415);
    }

    private function pageLink(string $token, string $url, ?string $from): string
    {
        return $this->ctx->url('/web/' . $token) . '?u=' . rawurlencode($url) . ($from !== null ? '&r=' . rawurlencode($from) : '');
    }

    private function assetLink(WebToken $tokens, string $token, string $url): string
    {
        $canon = WebSites::canonical($url) ?? $url;

        return $this->ctx->url('/web/' . $token) . '?u=' . rawurlencode($canon) . '&s=' . $tokens->asset($token, $canon);
    }
}
