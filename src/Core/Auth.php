<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authentifizierung & Autorisierung.
 *
 * Rollenlogik:
 *  - admin:        hat alle Rechte.
 *  - teacher:      Rechte ausschließlich über granulare Berechtigungen
 *                  (user_permissions + Gruppen + Rollen-Defaults).
 *  - student:      keine Admin-Rechte, Zugriff über rollen-spezifische Seiten.
 */
final class Auth
{
    /** @var array<string, mixed>|null|false false = noch nicht geladen */
    private array|null|false $user = false;

    /** @var list<string>|null Cache der granularen Berechtigungen. */
    private ?array $permissions = null;

    public function __construct(
        private readonly Session $session,
        private readonly Database $db,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->user === false) {
            $id = $this->session->get('user_id');
            $this->user = is_int($id) || is_string($id)
                ? $this->db->fetchOne('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $id])
                : null;
        }

        return $this->user;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return $user === null ? null : (int) $user['id'];
    }

    public function role(): ?string
    {
        return $this->user()['role'] ?? null;
    }

    public function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }

    public function isStudent(): bool
    {
        return $this->role() === 'student';
    }

    public function isTeacher(): bool
    {
        return $this->role() === 'teacher';
    }

    /** Startet eine Session für den übergebenen Benutzer (nach erfolgreichem Login). */
    public function loginAs(array $user): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->user = false;
        $this->permissions = null;
    }

    /**
     * Anfrage im Namen eines Benutzers ohne Sitzung (KI-Zugang mit Bearer-Schlüssel, siehe
     * McpController). Die Sitzung bleibt unberührt; Rechte gelten wie bei einer Anmeldung.
     *
     * @param array<string, mixed> $user Zeile aus users (aktiv)
     */
    public function actAs(array $user): void
    {
        $this->user = $user;
        $this->permissions = null;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = false;
        $this->permissions = null;
    }

    /**
     * Prüft eine granulare Berechtigung. admin: immer wahr. Sonst muss die
     * Berechtigung explizit vorliegen (direkt, per Gruppe oder als Rollen-Default).
     */
    public function can(string $permission): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if ($user['role'] === 'admin') {
            return true;
        }

        return in_array($permission, $this->permissions(), true);
    }

    /** @return list<string> Effektive granulare Berechtigungen des Nutzers. */
    public function permissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $user = $this->user();
        if ($user === null) {
            return $this->permissions = [];
        }

        // Nur Lehrkräfte tragen granulare Rechte. Wer die Rolle wechselt,
        // verliert sie damit sofort, Zuweisungen, die beim Rollenwechsel in
        // der Datenbank zurückblieben, wirken nicht mehr.
        if (!Permissions::allowsGranular((string) $user['role'])) {
            return $this->permissions = [];
        }

        $direct = $this->db->fetchAll(
            'SELECT permission FROM user_permissions WHERE user_id = ?',
            [(int) $user['id']],
        );
        $viaGroups = $this->db->fetchAll(
            'SELECT pgi.permission
             FROM user_permission_groups upg
             JOIN permission_group_items pgi ON pgi.group_id = upg.group_id
             WHERE upg.user_id = ?',
            [(int) $user['id']],
        );

        $keys = array_merge(
            array_column($direct, 'permission'),
            array_column($viaGroups, 'permission'),
            Permissions::defaultsForRole((string) $user['role']),
        );

        // Nur noch gültige Keys berücksichtigen (Altlasten ignorieren)
        return $this->permissions = array_values(array_unique(
            array_filter($keys, Permissions::exists(...)),
        ));
    }

    /** Lehrkraft oder Admin (darf Lehrkräfte-Bereiche betreten). */
    public function isStaff(): bool
    {
        return in_array($this->role(), ['admin', 'teacher'], true);
    }
}
