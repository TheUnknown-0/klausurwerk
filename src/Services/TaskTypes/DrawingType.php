<?php

declare(strict_types=1);

namespace App\Services\TaskTypes;

use App\Services\Diagram\DiagramTaskType;

/**
 * Antworttyp "drawing" (Freihandzeichnung, optional mit Hintergrundbild und Upload).
 * Format: docs/DATENFORMATE.md, 2.7. Keine automatische Bewertung.
 */
final class DrawingType extends DiagramTaskType
{
    public const MAX_STROKES = 1500;
    public const MAX_POINTS_PER_STROKE = 4000;
    public const MAX_POINTS_TOTAL = 40000;
    public const MAX_TEXTS = 200;
    public const MAX_UPLOADS = 10;

    public function name(): string
    {
        return 'drawing';
    }

    public function label(): string
    {
        return 'Zeichnung (Freihand)';
    }

    public function defaultConfig(): array
    {
        return ['width' => 900, 'height' => 600, 'background_material_id' => null, 'allow_upload' => false];
    }

    public function normalizeConfig(array $config): array
    {
        $material = $config['background_material_id'] ?? null;

        return [
            'width' => self::intRange($config['width'] ?? 900, 200, 3000, 900),
            'height' => self::intRange($config['height'] ?? 600, 200, 3000, 600),
            'background_material_id' => is_numeric($material) && (int) $material > 0 ? (int) $material : null,
            'allow_upload' => self::bool($config['allow_upload'] ?? false),
        ];
    }

    public function normalizeSolution(array $solution, array $config): array
    {
        return [];
    }

    public function normalizeContent(array $content, array $config): array
    {
        $width = (int) ($config['width'] ?? 900);
        $height = (int) ($config['height'] ?? 600);

        $strokes = [];
        $total = 0;
        foreach (self::rows($content['strokes'] ?? [], self::MAX_STROKES) as $stroke) {
            $points = [];
            foreach (is_array($stroke['points'] ?? null) ? $stroke['points'] : [] as $p) {
                if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) {
                    continue;
                }
                $points[] = [
                    round(max(0.0, min((float) $width, (float) $p[0])), 1),
                    round(max(0.0, min((float) $height, (float) $p[1])), 1),
                ];
                if (count($points) >= self::MAX_POINTS_PER_STROKE) {
                    break;
                }
            }
            if ($points === []) {
                continue;
            }
            $total += count($points);
            if ($total > self::MAX_POINTS_TOTAL) {
                break;
            }
            $color = is_string($stroke['color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $stroke['color']) === 1
                ? strtolower($stroke['color']) : '#000000';
            $strokes[] = [
                'color' => $color,
                'width' => self::intRange($stroke['width'] ?? 2, 1, 40, 2),
                'points' => $points,
            ];
        }

        $texts = [];
        $ids = [];
        foreach (self::rows($content['texts'] ?? [], self::MAX_TEXTS) as $t) {
            $id = self::safeId($t['id'] ?? null);
            $text = self::clean($t['text'] ?? '', 500);
            if ($id === null || isset($ids[$id]) || $text === '') {
                continue;
            }
            $ids[$id] = true;
            $entry = ['id' => $id, 'x' => self::coord($t['x'] ?? 0, 0, $width), 'y' => self::coord($t['y'] ?? 0, 0, $height), 'text' => $text];
            if (is_string($t['color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $t['color']) === 1) {
                $entry['color'] = strtolower($t['color']);
            }
            $texts[] = $entry;
        }

        $uploads = [];
        foreach (is_array($content['upload_ids'] ?? null) ? $content['upload_ids'] : [] as $id) {
            if (is_numeric($id) && (int) $id > 0 && !in_array((int) $id, $uploads, true)) {
                $uploads[] = (int) $id;
                if (count($uploads) >= self::MAX_UPLOADS) {
                    break;
                }
            }
        }

        $result = ['strokes' => $strokes, 'texts' => $texts, 'upload_ids' => $uploads];
        $this->assertSize($result);

        return $result;
    }

    public function isAnswered(array $content): bool
    {
        return ($content['strokes'] ?? []) !== [] || ($content['texts'] ?? []) !== [] || ($content['upload_ids'] ?? []) !== [];
    }

    public function toText(array $content, array $config): string
    {
        $lines = [];
        $strokes = count($content['strokes'] ?? []);
        $texts = $content['texts'] ?? [];
        $uploads = count($content['upload_ids'] ?? []);
        $lines[] = "Zeichnung: {$strokes} " . ($strokes === 1 ? 'Strich' : 'Striche') . ', ' . count($texts) . ' ' . (count($texts) === 1 ? 'Text' : 'Texte')
            . ($uploads > 0 ? ", {$uploads} " . ($uploads === 1 ? 'hochgeladene Datei' : 'hochgeladene Dateien') : '');
        foreach ($texts as $t) {
            $lines[] = '  Text: ' . str_replace("\n", ' ', (string) $t['text']);
        }

        return implode("\n", $lines);
    }
}
