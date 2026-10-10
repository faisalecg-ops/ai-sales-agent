<?php
declare(strict_types=1);

/**
 * Who may open which exhibition, on which platform.
 *
 * One login serves two platforms: the AI Sales Agent (/admin) and the AI
 * Social Media Expert Agent (/social). Both ask this class the same
 * questions, so both apply exactly the same rules.
 *
 *  - A super admin (admin_users.role = 'admin') may open everything on both
 *    platforms. Every account that existed before 10 October 2026 is one.
 *  - Staff (role = 'staff') may open only what their rows in
 *    admin_user_access allow: one row per platform and exhibition, with a
 *    role on that exhibition.
 *
 * Read-only by design: every method only SELECTs. It does not depend on the
 * admin page code, so /social can require it on its own (after
 * app/bootstrap.php). Rows are written only by the sales agent's Users
 * screen.
 */
final class Access
{
    public const PLATFORMS = ['sales', 'social'];

    /** Roles on the AI Sales Agent, interpreted by the sales agent. */
    public const SALES_ROLES = [
        'manager' => 'Manager — everything for this exhibition',
        'viewer'  => 'Viewer — reads conversations, leads and analytics',
    ];

    /** Roles on the AI Social Media Expert Agent: stored here, interpreted by /social. */
    public const SOCIAL_ROLES = [
        'admin'    => 'Admin',
        'approver' => 'Approver',
        'editor'   => 'Editor',
        'viewer'   => 'Viewer',
    ];

    /** @var array<int, array|false> per-request cache of user rows */
    private static array $users = [];

    /** @var array<string, array> per-request cache of access rows */
    private static array $rows = [];

    /** Is this user an active super admin? */
    public static function isSuper(int $userId): bool
    {
        $user = self::user($userId);
        return $user !== null && (string)$user['role'] === 'admin';
    }

    /**
     * Which platforms this user may use at all.
     *
     * @return array{sales: bool, social: bool}  Super admins get both true.
     */
    public static function platforms(int $userId): array
    {
        if (self::isSuper($userId)) {
            return ['sales' => true, 'social' => true];
        }
        return [
            'sales'  => self::agentIds($userId, 'sales') !== [],
            'social' => self::agentIds($userId, 'social') !== [],
        ];
    }

    /**
     * Exhibitions (agents.id) this user may open on one platform, in name
     * order. Super admins get every exhibition that is not deleted.
     *
     * @return list<int>
     */
    public static function agentIds(int $userId, string $platform): array
    {
        if (!in_array($platform, self::PLATFORMS, true) || self::user($userId) === null) {
            return [];
        }
        if (self::isSuper($userId)) {
            return array_map('intval', array_column(
                db()->all("SELECT id FROM agents WHERE status <> 'deleted' ORDER BY name ASC, id ASC"),
                'id'
            ));
        }
        return array_map('intval', array_keys(self::rows($userId, $platform)));
    }

    /**
     * This user's role on one exhibition for one platform: 'admin' for super
     * admins, the stored role for staff, or null when they have no access
     * (including when the exhibition is deleted).
     */
    public static function role(int $userId, string $platform, int $agentId): ?string
    {
        if (!in_array($platform, self::PLATFORMS, true) || self::user($userId) === null) {
            return null;
        }
        if (self::isSuper($userId)) {
            $live = db()->value("SELECT COUNT(*) FROM agents WHERE id = ? AND status <> 'deleted'", [$agentId]);
            return (int)$live > 0 ? 'admin' : null;
        }
        return self::rows($userId, $platform)[$agentId] ?? null;
    }

    /** Forget cached answers. For tests and for the Users screen after it saves. */
    public static function forget(): void
    {
        self::$users = [];
        self::$rows  = [];
    }

    // -----------------------------------------------------------------

    /** The active user row, or null for an unknown or deactivated account. */
    private static function user(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        if (!array_key_exists($userId, self::$users)) {
            $row = db()->one('SELECT id, role, active FROM admin_users WHERE id = ? LIMIT 1', [$userId]);
            self::$users[$userId] = ($row && (int)$row['active'] === 1) ? $row : false;
        }
        return self::$users[$userId] === false ? null : self::$users[$userId];
    }

    /** @return array<int, string> agent id => role, live exhibitions only, in name order */
    private static function rows(int $userId, string $platform): array
    {
        $key = $userId . ':' . $platform;
        if (!isset(self::$rows[$key])) {
            self::$rows[$key] = [];
            // Before the upgrade has run there are no staff, so no rows.
            if (self::tableReady()) {
                foreach (db()->all(
                    "SELECT x.agent_id, x.role
                       FROM admin_user_access x
                       JOIN agents a ON a.id = x.agent_id AND a.status <> 'deleted'
                      WHERE x.user_id = ? AND x.platform = ?
                      ORDER BY a.name ASC, a.id ASC",
                    [$userId, $platform]
                ) as $r) {
                    self::$rows[$key][(int)$r['agent_id']] = (string)$r['role'];
                }
            }
        }
        return self::$rows[$key];
    }

    private static function tableReady(): bool
    {
        static $ready = null;
        if ($ready !== true) {
            $ready = (int)db()->value(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_user_access'"
            ) > 0;
        }
        return $ready;
    }
}
