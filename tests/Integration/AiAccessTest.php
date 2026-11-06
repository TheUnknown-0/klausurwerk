<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Ai\McpServer;
use App\Services\Ai\McpTools;
use App\Services\Ai\OAuthError;
use App\Services\Ai\OAuthServer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\LiveFixtures;

/** KI-Zugang: OAuth-Ablauf mit PKCE und die MCP-Werkzeuge mit den Rechten der Lehrkraft. */
final class AiAccessTest extends DatabaseTestCase
{
    use LiveFixtures;

    private const MCP = 'https://klausur.example/mcp';

    private OAuthServer $oauth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oauth = new OAuthServer($this->db);
    }

    /** @return array{0: string, 1: string} client_id, verifier */
    private function client(string $redirect = 'https://claude.ai/api/mcp/auth_callback'): array
    {
        $c = $this->oauth->registerClient(['client_name' => 'Claude', 'redirect_uris' => [$redirect], 'token_endpoint_auth_method' => 'none']);

        return [$c['client_id'], str_repeat('v', 20) . bin2hex(random_bytes(16))];
    }

    // ---------- OAuth ----------

    public function testRedirectAdressen(): void
    {
        self::assertTrue(OAuthServer::validRedirectUri('https://claude.ai/api/mcp/auth_callback'));
        self::assertTrue(OAuthServer::validRedirectUri('http://localhost:33418/callback'));
        self::assertTrue(OAuthServer::validRedirectUri('http://127.0.0.1/cb'));
        self::assertTrue(OAuthServer::validRedirectUri('cursor://anysphere.cursor-retrieval/oauth/callback'));
        self::assertFalse(OAuthServer::validRedirectUri('http://evil.example/cb'));
        self::assertFalse(OAuthServer::validRedirectUri('javascript:alert(1)'));
        self::assertFalse(OAuthServer::validRedirectUri('https://claude.ai/cb#frag'));
        self::assertFalse(OAuthServer::validRedirectUri('data:text/html,x'));
        // Loopback: Port frei, Pfad muss passen.
        self::assertTrue(OAuthServer::redirectMatches(['http://127.0.0.1/callback'], 'http://127.0.0.1:51234/callback'));
        self::assertFalse(OAuthServer::redirectMatches(['http://127.0.0.1/callback'], 'http://127.0.0.1:51234/other'));
        self::assertFalse(OAuthServer::redirectMatches(['https://claude.ai/cb'], 'https://claude.ai/cb2'));
    }

    public function testRegistrierungLehntUnsichereClientsAb(): void
    {
        $this->expectException(OAuthError::class);
        $this->oauth->registerClient(['redirect_uris' => ['http://evil.example/cb']]);
    }

    public function testCodeMitPkceGegenSchluesselUndErneuerung(): void
    {
        $teacher = $this->makeUser('teacher', 'Lena');
        [$cid, $verifier] = $this->client();
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $code = $this->oauth->createCode($cid, $teacher, $redirect, OAuthServer::challengeOf($verifier), 'klausurwerk', self::MCP);

        $tokens = $this->oauth->exchangeCode($cid, $code, $redirect, $verifier, self::MCP, self::MCP);
        self::assertSame('Bearer', $tokens['token_type']);
        $grant = $this->oauth->authenticate($tokens['access_token'], self::MCP);
        self::assertNotNull($grant);
        self::assertSame($teacher, (int) $grant['user_id']);
        // Fremdes Ziel: Schlüssel gilt nicht.
        self::assertNull($this->oauth->authenticate($tokens['access_token'], 'https://andere.example/mcp'));

        // Code nur einmal.
        try {
            $this->oauth->exchangeCode($cid, $code, $redirect, $verifier, null, self::MCP);
            self::fail('Code zweimal eingelöst');
        } catch (OAuthError $e) {
            self::assertSame('invalid_grant', $e->error);
        }

        // Erneuerung rotiert: alter Erneuerungsschlüssel und alter Zugriffsschlüssel sind danach ungültig.
        $next = $this->oauth->refresh($cid, $tokens['refresh_token'], null, self::MCP);
        self::assertNotSame($tokens['access_token'], $next['access_token']);
        self::assertNull($this->oauth->authenticate($tokens['access_token'], self::MCP));
        self::assertNotNull($this->oauth->authenticate($next['access_token'], self::MCP));
        try {
            $this->oauth->refresh($cid, $tokens['refresh_token'], null, self::MCP);
            self::fail('Alter Erneuerungsschlüssel akzeptiert');
        } catch (OAuthError $e) {
            self::assertSame('invalid_grant', $e->error);
        }

        // Widerruf beendet den Zugang.
        self::assertTrue($this->oauth->revokeGrant((int) $grant['id'], $teacher));
        self::assertNull($this->oauth->authenticate($next['access_token'], self::MCP));
    }

    public function testFalscherVerifierVerbrauchtDenCode(): void
    {
        $teacher = $this->makeUser('teacher', 'Lena');
        [$cid, $verifier] = $this->client();
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $code = $this->oauth->createCode($cid, $teacher, $redirect, OAuthServer::challengeOf($verifier), 'klausurwerk', self::MCP);
        try {
            $this->oauth->exchangeCode($cid, $code, $redirect, str_repeat('x', 50), null, self::MCP);
            self::fail('Falscher Verifier akzeptiert');
        } catch (OAuthError $e) {
            self::assertSame('invalid_grant', $e->error);
        }
        $this->expectException(OAuthError::class);
        $this->oauth->exchangeCode($cid, $code, $redirect, $verifier, null, self::MCP);
    }

    public function testFremderWiderrufGehtNicht(): void
    {
        $a = $this->makeUser('teacher', 'Lena');
        $b = $this->makeUser('teacher', 'Ben');
        [$cid, $verifier] = $this->client();
        $redirect = 'https://claude.ai/api/mcp/auth_callback';
        $code = $this->oauth->createCode($cid, $a, $redirect, OAuthServer::challengeOf($verifier), 'klausurwerk', self::MCP);
        $tokens = $this->oauth->exchangeCode($cid, $code, $redirect, $verifier, null, self::MCP);
        $grant = $this->oauth->authenticate($tokens['access_token'], self::MCP);
        self::assertFalse($this->oauth->revokeGrant((int) $grant['id'], $b));
        self::assertNotNull($this->oauth->authenticate($tokens['access_token'], self::MCP));
    }

    // ---------- MCP ----------

    private function mcp(int $userId): McpServer
    {
        $ctx = $this->makeContext(null);
        $ctx->auth->actAs($this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]));

        return new McpServer(new McpTools($ctx, 'Testprogramm'));
    }

    /** @return array<string, mixed> */
    private function call(McpServer $s, string $tool, array $args = []): array
    {
        $r = $s->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
        self::assertArrayHasKey('result', $r, json_encode($r, JSON_UNESCAPED_UNICODE));

        return $r['result'];
    }

    /** @return array<string, mixed> */
    private function ok(McpServer $s, string $tool, array $args = []): array
    {
        $r = $this->call($s, $tool, $args);
        self::assertFalse($r['isError'], $r['content'][0]['text']);

        return $r['structuredContent'];
    }

    private function fails(McpServer $s, string $tool, array $args, string $needle): void
    {
        $r = $this->call($s, $tool, $args);
        self::assertTrue($r['isError'], 'Fehler erwartet: ' . $tool);
        self::assertStringContainsString($needle, $r['content'][0]['text']);
    }

    public function testProtokollGrundlagen(): void
    {
        $s = $this->mcp($this->makeUser('teacher', 'Lena'));
        $init = $s->handle(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 't', 'version' => '1']]]);
        self::assertSame('2025-06-18', $init['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $init['result']['capabilities']);
        self::assertNull($s->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));

        $list = $s->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $names = array_column($list['result']['tools'], 'name');
        self::assertContains('create_task', $names);
        self::assertContains('upload_material', $names);
        // Kein Werkzeug für Schülerdaten.
        foreach ($names as $n) {
            self::assertDoesNotMatchRegularExpression('/student|answer|grad|result|participa|card/i', $n);
        }
        $unknown = $s->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'drop_database']]);
        self::assertSame(-32602, $unknown['error']['code']);
        $nomethod = $s->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'sampling/createMessage']);
        self::assertSame(-32601, $nomethod['error']['code']);
    }

    public function testKlausurUndAufgabenAnlegenAendernLoeschen(): void
    {
        $teacher = $this->makeUser('teacher', 'Lena');
        $this->db->run("INSERT INTO user_permissions (user_id, permission) VALUES (?, 'klausuren_erstellen')", [$teacher]);
        $s = $this->mcp($teacher);

        $exam = $this->ok($s, 'create_exam', ['title' => 'Schleifen 11b', 'mode' => 'practice', 'profile' => 'locker']);
        $eid = $exam['exam_id'];
        self::assertSame('draft', $exam['status']);

        $types = $this->ok($s, 'get_task_types', ['type' => 'choice']);
        self::assertStringContainsString('correct', $types['format']);

        $choice = $this->ok($s, 'create_task', [
            'exam_id' => $eid, 'title' => 'Schleifenart', 'answer_type' => 'choice', 'points' => 2,
            'body_md' => 'Welche Schleife prüft die Bedingung **nach** dem Durchlauf?',
            'config' => ['multiple' => false, 'options' => [['id' => 'a', 'text_md' => 'while'], ['id' => 'b', 'text_md' => 'do-while']]],
            'solution' => ['correct' => ['b']],
            'expectation_md' => 'do-while ist fußgesteuert.',
        ]);
        $tid = $choice['task']['id'];
        self::assertSame('1', $choice['task']['number']);
        self::assertSame(['b'], $choice['task']['solution']['correct']);
        self::assertStringContainsString('/vorschau?aufgabe=' . $tid, $choice['links']['preview']);

        // Teiländerung: nur Punkte, Rest bleibt.
        $upd = $this->ok($s, 'update_task', ['exam_id' => $eid, 'task_id' => $tid, 'points' => 3]);
        self::assertSame(3.0, $upd['task']['points']);
        self::assertSame(['b'], $upd['task']['solution']['correct']);
        self::assertSame('Schleifenart', $upd['task']['title']);

        // Ungültige Angaben: Fehler für das Modell, keine halbe Aufgabe.
        $this->fails($s, 'create_task', ['exam_id' => $eid, 'title' => 'Kaputt', 'answer_type' => 'text', 'points' => 1.3], '0,5');
        self::assertCount(1, $this->ok($s, 'get_exam', ['exam_id' => $eid])['tasks']);

        $code = $this->ok($s, 'create_task', [
            'exam_id' => $eid, 'title' => 'Summe', 'answer_type' => 'code', 'points' => 4,
            'config' => ['language' => 'python'], 'body_md' => 'Schreibe summe(n).',
            'model_answer' => 'def summe(n):\n    return n*(n+1)//2',
            'tests' => [['name' => 'klein', 'type' => 'python_assert', 'code' => 'assert summe(3) == 6', 'points' => 4]],
        ]);
        self::assertSame('python', $code['task']['config']['language']);
        self::assertCount(1, $code['task']['tests']);

        $moved = $this->ok($s, 'move_task', ['exam_id' => $eid, 'task_id' => $code['task']['id'], 'direction' => 'up']);
        self::assertSame('Summe', $moved['order'][0]['title']);

        $check = $this->ok($s, 'check_exam', ['exam_id' => $eid]);
        self::assertSame([], $check['errors']);

        $del = $this->ok($s, 'delete_task', ['exam_id' => $eid, 'task_id' => $tid]);
        self::assertCount(1, $del['remaining']);

        $settings = $this->ok($s, 'update_exam', ['exam_id' => $eid, 'settings' => ['aids' => ['calculator' => true]], 'duration_minutes' => 45]);
        self::assertTrue($settings['settings']['aids']['calculator']);
        self::assertSame(45, $settings['duration_minutes']);

        $logs = $this->db->fetchAll("SELECT action FROM audit_logs WHERE action LIKE 'ki.%'");
        self::assertContains('ki.aufgabe_angelegt', array_column($logs, 'action'));
    }

    public function testRechteUndSperren(): void
    {
        $owner = $this->makeUser('teacher', 'Lena');
        $other = $this->makeUser('teacher', 'Ben');
        $this->db->run("INSERT INTO user_permissions (user_id, permission) VALUES (?, 'klausuren_erstellen')", [$owner]);
        $eid = $this->ok($this->mcp($owner), 'create_exam', ['title' => 'Q1', 'mode' => 'exam', 'duration_minutes' => 90])['exam_id'];

        // Fremde Lehrkraft: weder lesen noch schreiben, und in ihrer Liste taucht die Klausur nicht auf.
        $s = $this->mcp($other);
        $this->fails($s, 'get_exam', ['exam_id' => $eid], 'Berechtigung');
        $this->fails($s, 'create_task', ['exam_id' => $eid, 'title' => 'x', 'answer_type' => 'text'], 'Berechtigung');
        $this->fails($s, 'upload_material', ['exam_id' => $eid, 'filename' => 'a.png', 'content_base64' => 'AAAA'], 'Berechtigung');
        self::assertSame([], $this->ok($s, 'list_exams')['exams']);
        // Ohne das Recht "Klausuren erstellen" (hier: Schülerkonto als Gegenprobe) geht create_exam nicht.
        $this->fails($this->mcp($this->makeUser('student', 'Mia')), 'create_exam', ['title' => 'Neu', 'mode' => 'practice'], 'Klausuren erstellen');

        // Gestartet: Struktur gesperrt, Erwartungshorizont noch änderbar.
        $mine = $this->mcp($owner);
        $tid = $this->ok($mine, 'create_task', ['exam_id' => $eid, 'title' => 'Text', 'answer_type' => 'text', 'points' => 2])['task']['id'];
        $this->db->run("UPDATE exams SET status = 'running' WHERE id = ?", [$eid]);
        $mine = $this->mcp($owner); // neue Anfrage, frischer Rechte-Cache
        $this->fails($mine, 'create_task', ['exam_id' => $eid, 'title' => 'y', 'answer_type' => 'text'], 'gestartet');
        $this->fails($mine, 'delete_task', ['exam_id' => $eid, 'task_id' => $tid], 'gestartet');
        $this->fails($mine, 'update_task', ['exam_id' => $eid, 'task_id' => $tid, 'title' => 'neu'], 'gestartet');
        $ok = $this->ok($mine, 'update_task', ['exam_id' => $eid, 'task_id' => $tid, 'expectation_md' => 'Nachträglich']);
        self::assertSame('Nachträglich', $ok['task']['expectation_md']);
    }

    public function testMaterialHochladenUndEinbinden(): void
    {
        $teacher = $this->makeUser('teacher', 'Lena');
        $this->db->run("INSERT INTO user_permissions (user_id, permission) VALUES (?, 'klausuren_erstellen')", [$teacher]);
        $s = $this->mcp($teacher);
        $eid = $this->ok($s, 'create_exam', ['title' => 'Netze', 'mode' => 'practice'])['exam_id'];
        // 1x1-PNG
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $up = $this->ok($s, 'upload_material', ['exam_id' => $eid, 'filename' => 'punkt.png', 'content_base64' => $png]);
        self::assertSame('![punkt.png](material:' . $up['material_id'] . ')', $up['markdown']);
        self::assertCount(1, $this->ok($s, 'list_materials', ['exam_id' => $eid])['materials']);
        $this->fails($s, 'upload_material', ['exam_id' => $eid, 'filename' => 'boese.php', 'content_base64' => base64_encode('<?php echo 1;')], '');
        $this->fails($s, 'upload_material', ['exam_id' => $eid, 'filename' => 'x.png', 'content_base64' => '%%%'], 'Base64');
        self::assertSame($up['material_id'], $this->ok($s, 'delete_material', ['exam_id' => $eid, 'material_id' => $up['material_id']])['deleted']);
    }
}
