<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions as P;

/**
 * Räume: Stammdaten (Name, Raster) und Ansicht der gespeicherten Sitzplätze.
 * Die Sitzordnung selbst wird im Pult gesetzt; hier lassen sich Plätze nur leeren.
 *
 * Plätze sind nullbasiert: col 0 bis grid_cols-1, row 0 bis grid_rows-1.
 */
final class RoomsController extends Controller
{
    public const MIN_GRID = 1;
    public const MAX_GRID = 24;

    /** GET /admin/raeume */
    public function index(array $params): string
    {
        $this->requirePermission(P::RAEUME_VERWALTEN);
        $old = $this->ctx->session->pullOldInput();

        return $this->render('pages/rooms/index', [
            'title' => 'Räume',
            'rooms' => $this->ctx->db->fetchAll(
                'SELECT r.*, (SELECT COUNT(*) FROM room_positions p WHERE p.room_id = r.id) AS seat_count,
                        (SELECT COUNT(*) FROM exams e WHERE e.room_id = r.id) AS exam_count
                 FROM rooms r ORDER BY r.name',
            ),
            'values' => $old + ['name' => '', 'grid_cols' => '8', 'grid_rows' => '6'],
            'min' => self::MIN_GRID,
            'max' => self::MAX_GRID,
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/raeume/neu */
    public function store(array $params): string
    {
        $this->requirePermission(P::RAEUME_VERWALTEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/raeume');

        $data = $this->readForm();
        $error = $this->validate($data, null);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'INSERT INTO rooms (name, grid_cols, grid_rows, created_by) VALUES (?, ?, ?, ?)',
            [$data['name'], $data['grid_cols'], $data['grid_rows'], $this->ctx->auth->id()],
        );
        $id = $this->ctx->db->lastInsertId();
        $this->ctx->audit->log('raum.anlegen', 'info', $data['name']);
        $this->flash('success', 'Der Raum wurde angelegt. Die Sitzordnung legst du im Pult einer Klausur fest.');
        $this->redirect($this->ctx->url('/admin/raeume/' . $id));
    }

    /** GET /admin/raeume/{id} */
    public function show(array $params): string
    {
        $this->requirePermission(P::RAEUME_VERWALTEN);
        $room = $this->load((int) $params['id']);
        $old = $this->ctx->session->pullOldInput();

        $positions = $this->ctx->db->fetchAll(
            'SELECT p.user_id, p.col, p.`row`, u.firstname, u.lastname, u.username
             FROM room_positions p JOIN users u ON u.id = p.user_id
             WHERE p.room_id = ? ORDER BY p.`row`, p.col',
            [(int) $room['id']],
        );

        $cols = (int) $room['grid_cols'];
        $rows = (int) $room['grid_rows'];
        $grid = [];
        $outside = [];
        foreach ($positions as $position) {
            $c = (int) $position['col'];
            $r = (int) $position['row'];
            if ($c < $cols && $r < $rows) {
                $grid[$r][$c] = $position;
            } else {
                $outside[] = $position;
            }
        }

        return $this->render('pages/rooms/show', [
            'title' => 'Raum ' . $room['name'],
            'room' => $room,
            'values' => $old + [
                'name' => $room['name'],
                'grid_cols' => (string) $room['grid_cols'],
                'grid_rows' => (string) $room['grid_rows'],
            ],
            'grid' => $grid,
            'outside' => $outside,
            'seatCount' => count($positions),
            'exams' => $this->ctx->db->fetchAll(
                'SELECT id, title, status FROM exams WHERE room_id = ? ORDER BY id DESC LIMIT 10',
                [(int) $room['id']],
            ),
            'min' => self::MIN_GRID,
            'max' => self::MAX_GRID,
            'pageStyles' => ['admin.css'],
        ]);
    }

    /** POST /admin/raeume/{id} */
    public function update(array $params): string
    {
        $this->requirePermission(P::RAEUME_VERWALTEN);
        $this->requireCsrf();
        $room = $this->load((int) $params['id']);
        $id = (int) $room['id'];
        $back = $this->ctx->url('/admin/raeume/' . $id);

        $data = $this->readForm();
        $error = $this->validate($data, $id);
        if ($error !== null) {
            $this->ctx->session->rememberInput($_POST);
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'UPDATE rooms SET name = ?, grid_cols = ?, grid_rows = ? WHERE id = ?',
            [$data['name'], $data['grid_cols'], $data['grid_rows'], $id],
        );
        $this->ctx->audit->log('raum.bearbeiten', 'info', sprintf('%s (%d x %d)', $data['name'], $data['grid_cols'], $data['grid_rows']));
        $this->flash('success', 'Der Raum wurde gespeichert.');
        $this->redirect($back);
    }

    /** POST /admin/raeume/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requirePermission(P::RAEUME_VERWALTEN);
        $this->requireCsrf();
        $room = $this->load((int) $params['id']);

        $this->ctx->db->run('DELETE FROM rooms WHERE id = ?', [(int) $room['id']]);
        $this->ctx->audit->log('raum.loeschen', 'warning', (string) $room['name']);
        $this->flash('success', 'Der Raum „' . $room['name'] . '“ wurde gelöscht. Klausuren in diesem Raum haben jetzt keinen Raum mehr.');
        $this->redirect($this->ctx->url('/admin/raeume'));
    }

    /** POST /admin/raeume/{id}/platz-leeren: einen Platz (user_id) leeren. */
    public function clearSeat(array $params): string
    {
        $this->requirePermission(P::RAEUME_VERWALTEN);
        $this->requireCsrf();
        $room = $this->load((int) $params['id']);

        $userId = (int) ($_POST['user_id'] ?? 0);
        $removed = $this->ctx->db->run(
            'DELETE FROM room_positions WHERE room_id = ? AND user_id = ?',
            [(int) $room['id'], $userId],
        )->rowCount();
        if ($removed > 0) {
            $this->ctx->audit->log('raum.platz_leeren', 'info', sprintf('%s: Platz von Benutzer %d geleert', $room['name'], $userId));
            $this->flash('success', 'Der Platz wurde geleert.');
        }
        $this->redirect($this->ctx->url('/admin/raeume/' . (int) $room['id']));
    }

    /** POST /admin/raeume/{id}/plaetze-leeren: alle Plätze des Raums leeren. */
    public function clearAll(array $params): string
    {
        $this->requirePermission(P::RAEUME_VERWALTEN);
        $this->requireCsrf();
        $room = $this->load((int) $params['id']);

        $removed = $this->ctx->db->run('DELETE FROM room_positions WHERE room_id = ?', [(int) $room['id']])->rowCount();
        $this->ctx->audit->log('raum.plaetze_leeren', 'warning', sprintf('%s: %d Plätze geleert', $room['name'], $removed));
        $this->flash('success', sprintf('%d Plätze wurden geleert.', $removed));
        $this->redirect($this->ctx->url('/admin/raeume/' . (int) $room['id']));
    }

    // ---------- Helfer ----------

    /** @return array<string, mixed> */
    private function load(int $id): array
    {
        $room = $this->ctx->db->fetchOne('SELECT * FROM rooms WHERE id = ?', [$id]);
        if ($room === null) {
            throw new HttpException(404, 'Raum nicht gefunden.');
        }

        return $room;
    }

    /** @return array{name: string, grid_cols: int, grid_rows: int} */
    private function readForm(): array
    {
        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'grid_cols' => (int) ($_POST['grid_cols'] ?? 0),
            'grid_rows' => (int) ($_POST['grid_rows'] ?? 0),
        ];
    }

    /** @param array{name: string, grid_cols: int, grid_rows: int} $data */
    private function validate(array $data, ?int $exceptId): ?string
    {
        if ($data['name'] === '' || mb_strlen($data['name']) > 100) {
            return 'Der Raumname ist Pflicht (höchstens 100 Zeichen).';
        }
        foreach (['grid_cols' => 'Spalten', 'grid_rows' => 'Zeilen'] as $key => $label) {
            if ($data[$key] < self::MIN_GRID || $data[$key] > self::MAX_GRID) {
                return sprintf('Die Anzahl der %s muss zwischen %d und %d liegen.', $label, self::MIN_GRID, self::MAX_GRID);
            }
        }
        if ($this->ctx->db->fetchValue('SELECT 1 FROM rooms WHERE name = ? AND id <> ?', [$data['name'], $exceptId ?? 0]) !== null) {
            return 'Einen Raum „' . $data['name'] . '“ gibt es schon.';
        }

        return null;
    }
}
