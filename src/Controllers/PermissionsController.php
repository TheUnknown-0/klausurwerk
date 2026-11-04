<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\PermissionService;
use PDOException;

/**
 * Rechtevergabe: Berechtigungs-Matrix je Benutzer und Verwaltung der
 * Berechtigungsgruppen.
 *
 * Delegationsregel: Admins dürfen alles. Alle anderen (orga, teacher mit
 * BERECHTIGUNGEN_VERGEBEN) dürfen ausschließlich Rechte bewegen, die sie
 * selbst besitzen — weder vergeben noch entziehen.
 */
final class PermissionsController extends Controller
{
    private ?PermissionService $permissions = null;

    // ---------- Matrix ----------

    /** GET /admin/berechtigungen[?benutzer=ID] */
    public function index(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGEN_SEHEN);
        $service = $this->service();

        $candidates = $this->candidates();

        $selected = null;
        $requested = (int) ($_GET['benutzer'] ?? 0);
        if ($requested > 0) {
            $selected = $this->loadTarget($requested);
        }

        $direct = [];
        $byGroup = [];
        $roleDefaults = [];
        $assignedGroups = [];
        if ($selected !== null) {
            $userId = (int) $selected['id'];
            $direct = $service->directPermissions($userId);
            $byGroup = $service->permissionsByGroup($userId);
            $roleDefaults = Permissions::defaultsForRole((string) $selected['role']);
            $assignedGroups = $service->userGroupIds($userId);
        }

        return $this->render('pages/permissions/index', [
            'title' => 'Berechtigungen',
            'candidates' => $candidates,
            'selected' => $selected,
            'direct' => $direct,
            'byGroup' => $byGroup,
            'roleDefaults' => $roleDefaults,
            'assignedGroups' => $assignedGroups,
            'groups' => $service->groups(),
            'catalog' => Permissions::catalog(),
            'dependencies' => Permissions::dependencies(),
            'allowed' => $this->allowedPermissions(),
            'isSelf' => $selected !== null && $this->ctx->auth->id() === (int) $selected['id'],
            'roleLabels' => UsersController::ROLE_LABELS,
            'canGrant' => $this->ctx->auth->can(Permissions::BERECHTIGUNGEN_VERGEBEN),
            'canManageGroups' => $this->ctx->auth->can(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN),
            'pageScripts' => ['permissions.js'],
        ]);
    }

    /** POST /admin/berechtigungen/speichern */
    public function save(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGEN_VERGEBEN);
        $this->requireCsrf();

        $target = $this->loadTarget((int) ($_POST['benutzer'] ?? 0));
        $userId = (int) $target['id'];
        $back = $this->ctx->url('/admin/berechtigungen?benutzer=' . $userId);

        if ($this->ctx->auth->id() === $userId) {
            $this->flash('error', 'Du kannst deine eigenen Berechtigungen nicht ändern.');
            $this->redirect($back);
        }

        $service = $this->service();
        $checked = PermissionService::sanitize(is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : []);
        $allowed = $this->allowedPermissions();

        $before = $service->directPermissions($userId);
        $result = $service->applySelection($userId, $checked, $this->ctx->auth->id(), $allowed);

        // Bewusst angeklickt vs. über die Abhängigkeitslogik mitgezogen
        $requestedGrants = array_values(array_diff($checked, $before));
        $requestedRevokes = array_values(array_diff($before, $checked));
        $extraGranted = array_values(array_diff($result['granted'], $requestedGrants));
        $extraRevoked = array_values(array_diff($result['revoked'], $requestedRevokes));

        if ($result['granted'] === [] && $result['revoked'] === [] && $result['denied'] === []) {
            $this->flash('info', 'Es gab nichts zu ändern.');
            $this->redirect($back);
        }

        $parts = [];
        if ($result['granted'] !== []) {
            $parts[] = 'Erteilt: ' . PermissionService::labelList($result['granted']);
        }
        if ($result['revoked'] !== []) {
            $parts[] = 'Entzogen: ' . PermissionService::labelList($result['revoked']);
        }
        if ($parts !== []) {
            $this->flash('success', 'Berechtigungen gespeichert. ' . implode(' · ', $parts));
        }
        if ($extraGranted !== []) {
            $this->flash('info', 'Als Voraussetzung automatisch mit erteilt: ' . PermissionService::labelList($extraGranted));
        }
        if ($extraRevoked !== []) {
            $this->flash('warning', 'Weil sie darauf aufbauen, automatisch mit entzogen: ' . PermissionService::labelList($extraRevoked));
        }
        if ($result['denied'] !== []) {
            $this->flash('warning', 'Nicht geändert, weil du diese Rechte selbst nicht besitzt: ' . PermissionService::labelList($result['denied']));
        }

        if ($result['granted'] !== [] || $result['revoked'] !== []) {
            $this->ctx->audit->log(
                'permissions.change',
                'warning',
                sprintf(
                    'Benutzer #%d "%s" — erteilt: %s | entzogen: %s',
                    $userId,
                    (string) $target['username'],
                    $result['granted'] === [] ? '—' : implode(', ', $result['granted']),
                    $result['revoked'] === [] ? '—' : implode(', ', $result['revoked']),
                ),
            );
        }

        $this->redirect($back);
    }

    /** POST /admin/berechtigungen/gruppen-zuweisen */
    public function assignGroups(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGEN_VERGEBEN);
        $this->requireCsrf();

        $target = $this->loadTarget((int) ($_POST['benutzer'] ?? 0));
        $userId = (int) $target['id'];
        $back = $this->ctx->url('/admin/berechtigungen?benutzer=' . $userId);

        if ($this->ctx->auth->id() === $userId) {
            $this->flash('error', 'Du kannst deine eigenen Berechtigungen nicht ändern.');
            $this->redirect($back);
        }

        $service = $this->service();
        $allowed = $this->allowedPermissions();

        // Nur existierende Gruppen sind gültig.
        $valid = [];
        foreach ($service->groups() as $group) {
            $valid[(int) $group['id']] = (string) $group['name'];
        }

        $wanted = [];
        foreach (is_array($_POST['gruppen'] ?? null) ? $_POST['gruppen'] : [] as $raw) {
            $id = (int) $raw;
            if (isset($valid[$id])) {
                $wanted[] = $id;
            }
        }
        $wanted = array_values(array_unique($wanted));
        $current = $service->userGroupIds($userId);

        $added = [];
        $removed = [];
        $denied = [];

        foreach (array_diff($wanted, $current) as $groupId) {
            // Eine Gruppe zuzuweisen ist eine Rechtevergabe: der Handelnde muss
            // alle Rechte der Gruppe selbst besitzen.
            if ($allowed !== null && array_diff($service->groupItems((int) $groupId), $allowed) !== []) {
                $denied[] = $valid[(int) $groupId];
                continue;
            }
            $service->assignGroup($userId, (int) $groupId);
            $added[] = $valid[(int) $groupId];
        }
        foreach (array_diff($current, $wanted) as $groupId) {
            if (!isset($valid[(int) $groupId])) {
                continue;
            }
            if ($allowed !== null && array_diff($service->groupItems((int) $groupId), $allowed) !== []) {
                $denied[] = $valid[(int) $groupId];
                continue;
            }
            $service->unassignGroup($userId, (int) $groupId);
            $removed[] = $valid[(int) $groupId];
        }

        if ($added === [] && $removed === [] && $denied === []) {
            $this->flash('info', 'Es gab nichts zu ändern.');
            $this->redirect($back);
        }

        $parts = [];
        if ($added !== []) {
            $parts[] = 'zugewiesen: ' . implode(', ', $added);
        }
        if ($removed !== []) {
            $parts[] = 'entfernt: ' . implode(', ', $removed);
        }
        if ($parts !== []) {
            $this->flash('success', 'Gruppen aktualisiert — ' . implode(' · ', $parts) . '.');
            $this->ctx->audit->log(
                'permissions.groups_assign',
                'warning',
                sprintf('Benutzer #%d "%s" — %s', $userId, (string) $target['username'], implode(' | ', $parts)),
            );
        }
        if ($denied !== []) {
            $this->flash('warning', 'Nicht geändert, weil du nicht alle Rechte dieser Gruppen besitzt: ' . implode(', ', array_unique($denied)));
        }

        $this->redirect($back);
    }

    // ---------- Berechtigungsgruppen ----------

    /** GET /admin/berechtigungen/gruppen */
    public function groups(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGEN_SEHEN);
        $service = $this->service();

        $groups = $service->groups();
        $items = [];
        foreach ($groups as $group) {
            $items[(int) $group['id']] = $service->groupItems((int) $group['id']);
        }

        return $this->render('pages/permissions/groups', [
            'title' => 'Berechtigungsgruppen',
            'groups' => $groups,
            'items' => $items,
            'canManageGroups' => $this->ctx->auth->can(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN),
        ]);
    }

    /** GET /admin/berechtigungen/gruppen/neu */
    public function createGroup(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN);

        return $this->render('pages/permissions/group-form', [
            'title' => 'Neue Berechtigungsgruppe',
            'group' => null,
            'items' => [],
            'old' => $this->ctx->session->pullOldInput(),
            'catalog' => Permissions::catalog(),
            'dependencies' => Permissions::dependencies(),
            'allowed' => $this->allowedPermissions(),
            'action' => $this->ctx->url('/admin/berechtigungen/gruppen/neu'),
            'pageScripts' => ['permissions.js'],
        ]);
    }

    /** POST /admin/berechtigungen/gruppen/neu */
    public function storeGroup(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN);
        $this->requireCsrf();
        $back = $this->ctx->url('/admin/berechtigungen/gruppen/neu');

        [$name, $description] = $this->readGroupForm();
        $this->ctx->session->rememberInput($_POST);

        if ($name === '') {
            $this->flash('error', 'Bitte einen Namen für die Gruppe angeben.');
            $this->redirect($back);
        }

        $selected = $this->selectedGroupItems([]);
        if ($selected === []) {
            $this->flash('error', 'Bitte mindestens eine Berechtigung auswählen.');
            $this->redirect($back);
        }

        try {
            $this->ctx->db->run(
                'INSERT INTO permission_groups (name, description) VALUES (?, ?)',
                [$name, $description],
            );
        } catch (PDOException $e) {
            // Nur der Verstoss gegen den UNIQUE-Index bedeutet "Name vergeben".
            // Jeder andere Datenbankfehler wurde vorher genauso gemeldet und
            // damit als harmlose Eingabemeldung getarnt.
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $this->flash('error', 'Es gibt bereits eine Gruppe mit diesem Namen.');
            $this->redirect($back);
        }

        $groupId = $this->ctx->db->lastInsertId();
        $applied = $this->service()->setGroupItems($groupId, $selected);

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'permissions.group_create',
            'info',
            sprintf('Gruppe #%d "%s" mit %d Rechten: %s', $groupId, $name, count($applied['items']), implode(', ', $applied['items'])),
        );
        $this->flash('success', 'Die Berechtigungsgruppe wurde angelegt.');
        $this->reportClosure($applied);
        $this->redirect($this->ctx->url('/admin/berechtigungen/gruppen'));
    }

    /** GET /admin/berechtigungen/gruppen/{id}/bearbeiten */
    public function editGroup(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN);
        $group = $this->loadGroup((int) $params['id']);

        return $this->render('pages/permissions/group-form', [
            'title' => 'Berechtigungsgruppe bearbeiten',
            'group' => $group,
            'items' => $this->service()->groupItems((int) $group['id']),
            'old' => $this->ctx->session->pullOldInput(),
            'catalog' => Permissions::catalog(),
            'dependencies' => Permissions::dependencies(),
            'allowed' => $this->allowedPermissions(),
            'action' => $this->ctx->url('/admin/berechtigungen/gruppen/' . (int) $group['id'] . '/bearbeiten'),
            'pageScripts' => ['permissions.js'],
        ]);
    }

    /** POST /admin/berechtigungen/gruppen/{id}/bearbeiten */
    public function updateGroup(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN);
        $this->requireCsrf();

        $group = $this->loadGroup((int) $params['id']);
        $groupId = (int) $group['id'];
        $back = $this->ctx->url('/admin/berechtigungen/gruppen/' . $groupId . '/bearbeiten');

        [$name, $description] = $this->readGroupForm();
        $this->ctx->session->rememberInput($_POST);

        if ($name === '') {
            $this->flash('error', 'Bitte einen Namen für die Gruppe angeben.');
            $this->redirect($back);
        }

        $service = $this->service();
        $selected = $this->selectedGroupItems($service->groupItems($groupId));
        if ($selected === []) {
            $this->flash('error', 'Bitte mindestens eine Berechtigung auswählen.');
            $this->redirect($back);
        }

        try {
            $this->ctx->db->run(
                'UPDATE permission_groups SET name = ?, description = ? WHERE id = ?',
                [$name, $description, $groupId],
            );
        } catch (PDOException $e) {
            // Nur der Verstoss gegen den UNIQUE-Index bedeutet "Name vergeben".
            // Jeder andere Datenbankfehler wurde vorher genauso gemeldet und
            // damit als harmlose Eingabemeldung getarnt.
            if (($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            $this->flash('error', 'Es gibt bereits eine Gruppe mit diesem Namen.');
            $this->redirect($back);
        }

        $applied = $service->setGroupItems($groupId, $selected);

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'permissions.group_update',
            'warning',
            sprintf(
                'Gruppe #%d "%s" — hinzugefügt: %s | entfernt: %s',
                $groupId,
                $name,
                $applied['added'] === [] ? '—' : implode(', ', $applied['added']),
                $applied['removed'] === [] ? '—' : implode(', ', $applied['removed']),
            ),
        );
        $this->flash('success', 'Die Berechtigungsgruppe wurde gespeichert.');
        $this->reportClosure($applied);
        $this->redirect($this->ctx->url('/admin/berechtigungen/gruppen'));
    }

    /** POST /admin/berechtigungen/gruppen/{id}/loeschen */
    public function deleteGroup(array $params): string
    {
        $this->requirePermission(Permissions::BERECHTIGUNGSGRUPPEN_VERWALTEN);
        $this->requireCsrf();

        $group = $this->loadGroup((int) $params['id']);
        $groupId = (int) $group['id'];

        // Items und Zuweisungen hängen per FK ON DELETE CASCADE an der Gruppe.
        $this->ctx->db->run(
            'DELETE FROM permission_groups WHERE id = ?',
            [$groupId],
        );

        $this->ctx->audit->log(
            'permissions.group_delete',
            'warning',
            sprintf('Gruppe #%d "%s"', $groupId, (string) $group['name']),
        );
        $this->flash('success', 'Die Berechtigungsgruppe wurde gelöscht.');
        $this->redirect($this->ctx->url('/admin/berechtigungen/gruppen'));
    }

    // ---------- Helfer ----------

    private function service(): PermissionService
    {
        return $this->permissions ??= new PermissionService($this->ctx->db);
    }

    /**
     * Rechte, die der Handelnde bewegen darf.
     * null = keine Einschränkung (Admin).
     *
     * @return list<string>|null
     */
    private function allowedPermissions(): ?array
    {
        if ($this->ctx->auth->isAdmin()) {
            return null;
        }

        return PermissionService::sanitize($this->ctx->auth->permissions());
    }

    /**
     * Benutzer, die granulare Rechte erhalten können (orga/teacher).
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT u.id, u.username, u.firstname, u.lastname, u.role,
                    (SELECT COUNT(*) FROM user_permissions p WHERE p.user_id = u.id) AS direct_count,
                    (SELECT COUNT(*) FROM user_permission_groups g WHERE g.user_id = u.id) AS group_count
             FROM users u
             WHERE u.role IN (' . self::granularRolePlaceholders() . ')
             ORDER BY u.role, u.lastname, u.firstname, u.username',
            [...Permissions::GRANULAR_ROLES],
        );
    }

    /** Lädt den Zielbenutzer, begrenzt auf rechtefähige Rollen. */
    private function loadTarget(int $id): array
    {
        if ($id <= 0) {
            throw new HttpException(404, 'Kein Benutzer ausgewählt.');
        }

        $user = $this->ctx->db->fetchOne(
            'SELECT * FROM users WHERE id = ? AND role IN ('
                . self::granularRolePlaceholders() . ')',
            [$id, ...Permissions::GRANULAR_ROLES],
        );
        if ($user === null) {
            throw new HttpException(
                404,
                'Für diesen Benutzer können keine granularen Berechtigungen vergeben werden.'
                . ' Möglich ist das nur für die Rollen ' . implode(' und ', Permissions::GRANULAR_ROLES) . '.',
            );
        }

        return $user;
    }

    /** Platzhalterliste für `role IN (…)` aus Permissions::GRANULAR_ROLES. */
    private static function granularRolePlaceholders(): string
    {
        return implode(', ', array_fill(0, count(Permissions::GRANULAR_ROLES), '?'));
    }

    /** Lädt eine Gruppe oder wirft 404. */
    private function loadGroup(int $id): array
    {
        $group = $this->service()->findGroup($id);
        if ($group === null) {
            throw new HttpException(404, 'Diese Berechtigungsgruppe existiert nicht.');
        }

        return $group;
    }

    /** @return array{0: string, 1: ?string} Name und Beschreibung aus dem Formular. */
    private function readGroupForm(): array
    {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
        $description = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 500);

        return [$name, $description !== '' ? $description : null];
    }

    /**
     * Rechte-Auswahl aus dem Gruppenformular. Ein Nicht-Admin kann nur Rechte
     * setzen oder entfernen, die er selbst besitzt — bereits enthaltene andere
     * Rechte bleiben unangetastet.
     *
     * @param list<string> $existing Bisherige Items der Gruppe
     * @return list<string>
     */
    private function selectedGroupItems(array $existing): array
    {
        $selected = PermissionService::sanitize(
            is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [],
        );
        $allowed = $this->allowedPermissions();
        if ($allowed === null) {
            return $selected;
        }

        return array_values(array_unique([
            ...array_intersect($selected, $allowed),
            ...array_diff($existing, $allowed),
        ]));
    }

    /** Meldet Rechte, die über die Abhängigkeitslogik ergänzt wurden. */
    private function reportClosure(array $applied): void
    {
        $extra = array_values(array_diff(
            $applied['items'],
            PermissionService::sanitize(is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : []),
        ));
        if ($extra !== []) {
            $this->flash('info', 'Als Voraussetzung automatisch ergänzt: ' . PermissionService::labelList($extra));
        }
    }
}
