<?php
// Shared team-role helpers. The legacy columns remain synchronized during migration.

function ensureTeamMemberRolesTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS team_member_roles (
        team_member_role_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        team_member_id INT UNSIGNED NOT NULL,
        role_code VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (team_member_role_id),
        UNIQUE KEY team_member_roles_unique (team_member_id, role_code),
        KEY team_member_roles_member_idx (team_member_id),
        CONSTRAINT team_member_roles_member_fk
            FOREIGN KEY (team_member_id) REFERENCES team_members (team_member_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT IGNORE INTO team_member_roles (team_member_id, role_code)
        SELECT team_member_id, 'manager' FROM team_members WHERE FIND_IN_SET('manager', member_roles) > 0
        UNION ALL SELECT team_member_id, 'coach' FROM team_members WHERE FIND_IN_SET('coach', member_roles) > 0
        UNION ALL SELECT team_member_id, 'player' FROM team_members WHERE FIND_IN_SET('player', member_roles) > 0
        UNION ALL SELECT team_member_id, 'substitute' FROM team_members WHERE FIND_IN_SET('substitute', member_roles) > 0
        UNION ALL SELECT team_member_id, 'manager' FROM team_members
            WHERE LOWER(TRIM(in_game_role)) IN ('manager', 'leader')
        UNION ALL SELECT team_member_id, 'coach' FROM team_members
            WHERE LOWER(TRIM(in_game_role)) = 'coach'
        UNION ALL SELECT team_member_id, 'player' FROM team_members
            WHERE LOWER(TRIM(in_game_role)) IN ('player', 'member')
        UNION ALL SELECT team_member_id, 'substitute' FROM team_members
            WHERE LOWER(TRIM(in_game_role)) = 'substitute'");

    $ready = true;
}

function allowedTeamRoles(): array
{
    return ['manager', 'coach', 'player', 'substitute'];
}

function normalizeTeamRoles(array $roles): array
{
    $roles = array_map(static fn($role) => strtolower(trim((string) $role)), $roles);
    return array_values(array_unique(array_intersect(allowedTeamRoles(), $roles)));
}

function getTeamMemberRoles(PDO $pdo, int $teamMemberId): array
{
    ensureTeamMemberRolesTable($pdo);
    $stmt = $pdo->prepare('SELECT role_code FROM team_member_roles WHERE team_member_id = :id ORDER BY team_member_role_id');
    $stmt->execute(['id' => $teamMemberId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function syncTeamMemberRoles(PDO $pdo, int $teamMemberId, array $roles): void
{
    ensureTeamMemberRolesTable($pdo);
    $roles = normalizeTeamRoles($roles);
    if (!$roles) throw new InvalidArgumentException('ต้องเลือกบทบาทสมาชิกอย่างน้อย 1 บทบาท');

    $memberStmt = $pdo->prepare('SELECT team_id FROM team_members WHERE team_member_id = :id');
    $memberStmt->execute(['id' => $teamMemberId]);
    if (!$memberStmt->fetchColumn()) throw new InvalidArgumentException('ไม่พบสมาชิกทีมนี้');

    $pdo->prepare('DELETE FROM team_member_roles WHERE team_member_id = :id')->execute(['id' => $teamMemberId]);
    $insert = $pdo->prepare('INSERT INTO team_member_roles (team_member_id, role_code) VALUES (:id, :role)');
    foreach ($roles as $role) $insert->execute(['id' => $teamMemberId, 'role' => $role]);

    // Keep existing pages compatible while the normalized table becomes canonical.
    $legacyRoles = implode(',', $roles);
    $primaryRole = $roles[0];
    $pdo->prepare('UPDATE team_members SET member_roles = :roles, in_game_role = :primary WHERE team_member_id = :id')
        ->execute(['roles' => $legacyRoles, 'primary' => $primaryRole, 'id' => $teamMemberId]);
}
