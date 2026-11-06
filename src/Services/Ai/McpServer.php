<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Model Context Protocol, Transport "Streamable HTTP" ohne Server-Push: Jede POST-Anfrage enthält
 * eine JSON-RPC-Nachricht (oder ein Array davon), die Antwort kommt als application/json.
 * Unterstützt: initialize, ping, tools/list, tools/call, notifications/*.
 * Zustandslos: Es gibt keine Sitzung, jede Anfrage trägt ihren Bearer-Schlüssel.
 */
final class McpServer
{
    /** Neueste zuerst; der Client bekommt seine Version, wenn wir sie kennen, sonst unsere neueste. */
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(
        private readonly McpTools $tools,
        private readonly string $serverVersion = '1.0.0',
    ) {
    }

    /**
     * Verarbeitet den Rumpf einer POST-Anfrage.
     *
     * @return array<mixed>|null Antwort (Objekt oder Liste); null = nur Benachrichtigungen (HTTP 202)
     */
    public function handle(mixed $payload): ?array
    {
        if (is_array($payload) && array_is_list($payload) && $payload !== []) {
            $out = [];
            foreach ($payload as $message) {
                $r = $this->message($message);
                if ($r !== null) {
                    $out[] = $r;
                }
            }

            return $out === [] ? null : $out;
        }

        return $this->message($payload);
    }

    /** @return array<string, mixed>|null */
    private function message(mixed $msg): ?array
    {
        if (!is_array($msg) || ($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'] ?? null)) {
            // Antworten des Clients (ohne method) brauchen keine Antwort.
            if (is_array($msg) && array_key_exists('id', $msg) && (array_key_exists('result', $msg) || array_key_exists('error', $msg))) {
                return null;
            }

            return self::error($msg['id'] ?? null, -32600, 'Ungültige JSON-RPC-Nachricht.');
        }
        $isRequest = array_key_exists('id', $msg) && (is_int($msg['id']) || is_string($msg['id']));
        $id = $isRequest ? $msg['id'] : null;
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        $method = $msg['method'];

        if (!$isRequest) {
            // notifications/initialized, notifications/cancelled usw.: zur Kenntnis genommen.
            return null;
        }

        try {
            return self::result($id, match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => $this->tools->definitions()],
                'tools/call' => $this->callTool($params),
                'resources/list' => ['resources' => []],
                'prompts/list' => ['prompts' => []],
                default => throw new \DomainException($method),
            });
        } catch (\DomainException $e) {
            return self::error($id, -32601, 'Methode nicht unterstützt: ' . mb_substr($e->getMessage(), 0, 80));
        } catch (McpToolNotFound $e) {
            return self::error($id, -32602, $e->getMessage());
        } catch (\JsonException) {
            return self::error($id, -32603, 'Antwort ließ sich nicht kodieren.');
        }
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function initialize(array $params): array
    {
        $wanted = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($wanted, self::PROTOCOL_VERSIONS, true) ? $wanted : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'klausurwerk', 'title' => 'Klausurwerk', 'version' => $this->serverVersion],
            'instructions' => McpTools::instructions(),
        ];
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }
        try {
            $data = $this->tools->call($name, $args);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)]],
                'structuredContent' => $data,
                'isError' => false,
            ];
        } catch (ToolError $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
    }

    /** @return array<string, mixed> */
    private static function result(int|string|null $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => is_int($id) || is_string($id) ? $id : null, 'error' => ['code' => $code, 'message' => $message]];
    }
}
