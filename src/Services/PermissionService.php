<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Permissions;

/**
 * Fachlogik der Rechtevergabe: Abhängigkeitsauflösung, Berechtigungsgruppen
 * und Delegationsprüfung.
 *
 * Kernregeln:
 *  - ERTEILEN zieht alle Voraussetzungen (Permissions::requiredFor) rekursiv mit.
 *  - ENTZIEHEN zieht alle abhängigen Rechte (Permissions::dependentsOf) rekursiv mit.
 *  - Gespeichert werden ausschließlich gültige Keys aus Permissions::all().
 *
 */
final class PermissionService
{
    public function __construct(private readonly Database $db)
    {
    }

    // ---------- Lesen ----------

    /**
     * Direkt (in user_permissions) hinterlegte Rechte eines Benutzers.
     *
     * @return list<string>
     */
    public function directPermissions(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT permission FROM user_permissions WHERE user_id = ? ORDER BY permission',
            [$userId],
        );

        return self::sanitize(array_column($rows, 'permission'));
    }

    /**
     * Rechte, die der Benutzer über zugewiesene Gruppen erhält.
     *
     * @return array<string, list<string>> Berechtigung => Namen der Gruppen
     */
    public function permissionsByGroup(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT pg.name AS group_name, pgi.permission
             FROM user_permission_groups upg
             JOIN permission_groups pg ON pg.id = upg.group_id
             JOIN permission_group_items pgi ON pgi.group_id = upg.group_id
             WHERE upg.user_id = ?
             ORDER BY pg.name, pgi.permission',
            [$userId],
        );

        $map = [];
        foreach ($rows as $row) {
            $key = (string) $row['permission'];
            if (!Permissions::exists($key)) {
                continue;
            }
            $name = (string) $row['group_name'];
            if (!isset($map[$key]) || !in_array($name, $map[$key], true)) {
                $map[$key][] = $name;
            }
        }

        return $map;
    }

    /**
     * Effektive Rechte: direkt + über Gruppen + Rollen-Standards.
     * (admin hat laut Auth implizit alle Rechte — das wird
     * hier bewusst NICHT aufgelöst, die Anzeige weist gesondert darauf hin.)
     *
     * @return list<string>
     */
    public function effectivePermissions(int $userId, string $role): array
    {
        return self::sanitize([
            ...$this->directPermissions($userId),
            ...array_keys($this->permissionsByGroup($userId)),
            ...Permissions::defaultsForRole($role),
        ]);
    }

    // ---------- Erteilen & Entziehen ----------

    /**
     * Erteilt eine Berechtigung inkl. aller Voraussetzungen.
     *
     * @return list<string> Tatsächlich neu erteilte Keys (leer = nichts geändert)
     */
    public function grant(int $userId, string $permission, ?int $grantedBy): array
    {
        $add = $this->plannedGrant($userId, $permission);
        foreach ($add as $key) {
            $this->db->run(
                'INSERT INTO user_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by)',
                [$userId, $key, $grantedBy],
            );
        }

        return $add;
    }

    /**
     * Entzieht eine Berechtigung inkl. aller davon abhängigen Rechte.
     *
     * @return list<string> Tatsächlich entzogene Keys
     */
    public function revoke(int $userId, string $permission): array
    {
        $remove = $this->plannedRevoke($userId, $permission);
        foreach ($remove as $key) {
            $this->db->run(
                'DELETE FROM user_permissions WHERE user_id = ? AND permission = ?',
                [$userId, $key],
            );
        }

        return $remove;
    }

    /**
     * Was würde ein grant() konkret hinzufügen?
     *
     * @return list<string>
     */
    public function plannedGrant(int $userId, string $permission): array
    {
        if (!Permissions::exists($permission)) {
            return [];
        }

        return array_values(array_diff(
            self::withRequirements([$permission]),
            $this->directPermissions($userId),
        ));
    }

    /**
     * Was würde ein revoke() konkret entfernen?
     *
     * @return list<string>
     */
    public function plannedRevoke(int $userId, string $permission): array
    {
        if (!Permissions::exists($permission)) {
            return [];
        }

        return array_values(array_intersect(
            self::withDependents([$permission]),
            $this->directPermissions($userId),
        ));
    }

    /**
     * Wendet eine komplette Rechte-Auswahl (Matrix-Formular) an.
     *
     * Ablauf: Diff gegen die direkten Rechte bilden, zuerst alle Erteilungen
     * (die Voraussetzungen mitziehen), danach alle Entzüge (die abhängigen
     * Rechte mitnehmen). Diese Reihenfolge stellt sicher, dass ein bewusst
     * abgewähltes Recht sich auch dann durchsetzt, wenn ein anderes Häkchen
     * es als Voraussetzung wieder hereinziehen würde.
     *
     * @param list<string>      $checked  Angehakte Berechtigungen (direkte Rechte)
     * @param list<string>|null $allowed  Delegationsfilter: nur diese Keys darf der
     *                                    Handelnde bewegen (null = keine Einschränkung)
     * @return array{granted: list<string>, revoked: list<string>, denied: list<string>}
     */
    public function applySelection(int $userId, array $checked, ?int $grantedBy, ?array $allowed = null): array
    {
        $checked = self::sanitize($checked);
        $current = $this->directPermissions($userId);

        $toGrant = array_values(array_diff($checked, $current));
        $toRevoke = array_values(array_diff($current, $checked));

        return $this->db->transaction(function () use ($userId, $toGrant, $toRevoke, $grantedBy, $allowed): array {
            $granted = [];
            $revoked = [];
            $denied = [];

            foreach ($toGrant as $permission) {
                $planned = $this->plannedGrant($userId, $permission);
                if ($allowed !== null && array_diff($planned, $allowed) !== []) {
                    $denied[] = $permission;
                    continue;
                }
                $granted = [...$granted, ...$this->grant($userId, $permission, $grantedBy)];
            }

            foreach ($toRevoke as $permission) {
                $planned = $this->plannedRevoke($userId, $permission);
                if ($allowed !== null && array_diff($planned, $allowed) !== []) {
                    $denied[] = $permission;
                    continue;
                }
                $revoked = [...$revoked, ...$this->revoke($userId, $permission)];
            }

            return [
                'granted' => array_values(array_unique($granted)),
                'revoked' => array_values(array_unique($revoked)),
                'denied' => array_values(array_unique($denied)),
            ];
        });
    }

    /** Entfernt alle direkten Rechte eines Benutzers (z. B. bei Rollenwechsel). */
    public function clearDirect(int $userId): int
    {
        $count = count($this->directPermissions($userId));
        $this->db->run('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);

        return $count;
    }

    // ---------- Berechtigungsgruppen ----------

    /**
     * Alle Gruppen inkl. Zähler.
     *
     * @return list<array<string, mixed>>
     */
    public function groups(): array
    {
        return $this->db->fetchAll(
            'SELECT pg.*,
                    (SELECT COUNT(*) FROM permission_group_items i WHERE i.group_id = pg.id) AS item_count,
                    (SELECT COUNT(*) FROM user_permission_groups u WHERE u.group_id = pg.id) AS user_count
             FROM permission_groups pg
             ORDER BY pg.name',
        );
    }

    public function findGroup(int $groupId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM permission_groups WHERE id = ?', [$groupId]);
    }

    /** @return list<string> */
    public function groupItems(int $groupId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT permission FROM permission_group_items WHERE group_id = ? ORDER BY permission',
            [$groupId],
        );

        return self::sanitize(array_column($rows, 'permission'));
    }

    /**
     * Setzt die Rechte einer Gruppe. Die Auswahl wird vorher über die
     * Abhängigkeitslogik geschlossen (Voraussetzungen kommen mit hinein).
     *
     * @param list<string> $permissions
     * @return array{items: list<string>, added: list<string>, removed: list<string>}
     */
    public function setGroupItems(int $groupId, array $permissions): array
    {
        $target = self::withRequirements(self::sanitize($permissions));
        sort($target);

        return $this->db->transaction(function () use ($groupId, $target): array {
            $current = $this->groupItems($groupId);
            $added = array_values(array_diff($target, $current));
            $removed = array_values(array_diff($current, $target));

            foreach ($added as $permission) {
                $this->db->run(
                    'INSERT INTO permission_group_items (group_id, permission) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE permission = VALUES(permission)',
                    [$groupId, $permission],
                );
            }
            foreach ($removed as $permission) {
                $this->db->run(
                    'DELETE FROM permission_group_items WHERE group_id = ? AND permission = ?',
                    [$groupId, $permission],
                );
            }

            return ['items' => $target, 'added' => $added, 'removed' => $removed];
        });
    }

    /** @return list<int> IDs der dem Benutzer zugewiesenen Gruppen. */
    public function userGroupIds(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT group_id FROM user_permission_groups WHERE user_id = ?',
            [$userId],
        );

        return array_map(intval(...), array_column($rows, 'group_id'));
    }

    public function assignGroup(int $userId, int $groupId): void
    {
        $this->db->run(
            'INSERT INTO user_permission_groups (user_id, group_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)',
            [$userId, $groupId],
        );
    }

    public function unassignGroup(int $userId, int $groupId): void
    {
        $this->db->run(
            'DELETE FROM user_permission_groups WHERE user_id = ? AND group_id = ?',
            [$userId, $groupId],
        );
    }

    // ---------- Statische Helfer ----------

    /**
     * Filtert auf gültige, eindeutige Berechtigungs-Keys.
     *
     * @param array<int|string, mixed> $permissions
     * @return list<string>
     */
    public static function sanitize(array $permissions): array
    {
        $result = [];
        foreach ($permissions as $permission) {
            if (!is_string($permission) || !Permissions::exists($permission)) {
                continue;
            }
            if (!in_array($permission, $result, true)) {
                $result[] = $permission;
            }
        }

        return $result;
    }

    /**
     * Auswahl + alle rekursiven Voraussetzungen.
     *
     * @param list<string> $permissions
     * @return list<string>
     */
    public static function withRequirements(array $permissions): array
    {
        $result = [];
        foreach (self::sanitize($permissions) as $permission) {
            foreach ([$permission, ...Permissions::requiredFor($permission)] as $key) {
                if (Permissions::exists($key) && !in_array($key, $result, true)) {
                    $result[] = $key;
                }
            }
        }

        return $result;
    }

    /**
     * Auswahl + alle rekursiv davon abhängigen Rechte.
     *
     * @param list<string> $permissions
     * @return list<string>
     */
    public static function withDependents(array $permissions): array
    {
        $result = [];
        foreach (self::sanitize($permissions) as $permission) {
            foreach ([$permission, ...Permissions::dependentsOf($permission)] as $key) {
                if (Permissions::exists($key) && !in_array($key, $result, true)) {
                    $result[] = $key;
                }
            }
        }

        return $result;
    }

    /**
     * Menschenlesbare Bezeichnung eines Berechtigungs-Keys.
     */
    public static function label(string $permission): string
    {
        foreach (Permissions::catalog() as $group) {
            if (isset($group[$permission])) {
                return $group[$permission];
            }
        }

        return $permission;
    }

    /**
     * Kommagetrennte Labelliste für Flash-Meldungen und Audit-Details.
     *
     * @param list<string> $permissions
     */
    public static function labelList(array $permissions): string
    {
        return implode(', ', array_map(self::label(...), $permissions));
    }
}
