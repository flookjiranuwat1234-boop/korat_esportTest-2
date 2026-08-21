<?php
// Tournament roster and per-player check-in helpers.

require_once __DIR__ . '/team_roles.php';

function ensureTournamentRosterTables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    ensureTeamMemberRolesTable($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS tournament_registration_members (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tournament_registration_id INT UNSIGNED NOT NULL,
        player_id INT UNSIGNED NOT NULL,
        member_roles VARCHAR(255) NULL,
        is_starter TINYINT(1) NOT NULL DEFAULT 1,
        is_required_for_checkin TINYINT(1) NOT NULL DEFAULT 1,
        checkin_status VARCHAR(30) NOT NULL DEFAULT 'not_checked_in',
        checkin_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY registration_member_unique (tournament_registration_id, player_id),
        KEY registration_member_player_idx (player_id),
        CONSTRAINT registration_member_registration_fk FOREIGN KEY (tournament_registration_id)
            REFERENCES tournament_registrations (tournament_registration_id) ON DELETE CASCADE,
        CONSTRAINT registration_member_player_fk FOREIGN KEY (player_id)
            REFERENCES players (player_id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS player_tournament_checkins (
        player_tournament_checkin_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tournament_registration_id INT UNSIGNED NOT NULL,
        player_id INT UNSIGNED NOT NULL,
        checkin_status VARCHAR(30) NOT NULL DEFAULT 'not_checked_in',
        checked_in_at DATETIME NULL,
        checked_in_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (player_tournament_checkin_id),
        UNIQUE KEY player_registration_checkin_unique (tournament_registration_id, player_id),
        KEY player_checkin_player_idx (player_id),
        CONSTRAINT player_checkin_registration_fk FOREIGN KEY (tournament_registration_id)
            REFERENCES tournament_registrations (tournament_registration_id) ON DELETE CASCADE,
        CONSTRAINT player_checkin_player_fk FOREIGN KEY (player_id)
            REFERENCES players (player_id) ON DELETE RESTRICT,
        CONSTRAINT player_checkin_user_fk FOREIGN KEY (checked_in_by)
            REFERENCES users (user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function snapshotTournamentRoster(PDO $pdo, int $registrationId, ?int $teamId, ?int $playerId): void
{
    ensureTournamentRosterTables($pdo);
    $rows = [];
    $requiredRoles = null;
    try {
        $categoryStmt = $pdo->prepare('SELECT tc.checkin_required_roles
            FROM tournament_registrations tr
            LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
            WHERE tr.tournament_registration_id = :registration_id');
        $categoryStmt->execute(['registration_id' => $registrationId]);
        $configuredRoles = $categoryStmt->fetchColumn();
        if ($configuredRoles !== false && trim((string) $configuredRoles) !== '') {
            $requiredRoles = array_values(array_filter(array_map('trim', explode(',', strtolower($configuredRoles)))));
        }
    } catch (PDOException $exception) {
        $requiredRoles = null;
    }
    if ($playerId) {
        $rows[] = ['player_id' => $playerId, 'roles' => ['player'], 'starter' => 1, 'required' => 1];
    } elseif ($teamId) {
        $stmt = $pdo->prepare('SELECT team_member_id, player_id FROM team_members WHERE team_id = :team_id AND is_active = 1');
        $stmt->execute(['team_id' => $teamId]);
        foreach ($stmt->fetchAll() as $member) {
            $roles = getTeamMemberRoles($pdo, (int) $member['team_member_id']);
            $isSubstitute = in_array('substitute', $roles, true) && !in_array('player', $roles, true);
            $isRequired = $requiredRoles !== null
                ? (bool) array_intersect($requiredRoles, $roles)
                : (in_array('player', $roles, true) || (!$roles && !$isSubstitute));
            $rows[] = [
                'player_id' => (int) $member['player_id'],
                'roles' => $roles,
                'starter' => $isSubstitute ? 0 : 1,
                'required' => $isRequired ? 1 : 0,
            ];
        }
    }

    $insert = $pdo->prepare('INSERT INTO tournament_registration_members
        (tournament_registration_id, player_id, member_roles, is_starter, is_required_for_checkin)
        VALUES (:registration_id, :player_id, :roles, :starter, :required)
        ON DUPLICATE KEY UPDATE member_roles = VALUES(member_roles),
            is_starter = VALUES(is_starter), is_required_for_checkin = VALUES(is_required_for_checkin)');
    $checkin = $pdo->prepare('INSERT IGNORE INTO player_tournament_checkins
        (tournament_registration_id, player_id) VALUES (:registration_id, :player_id)');
    foreach ($rows as $row) {
        $insert->execute([
            'registration_id' => $registrationId,
            'player_id' => $row['player_id'],
            'roles' => implode(',', $row['roles']),
            'starter' => $row['starter'],
            'required' => $row['required'],
        ]);
        $checkin->execute(['registration_id' => $registrationId, 'player_id' => $row['player_id']]);
    }
}

function markRosterPlayerCheckedIn(PDO $pdo, int $registrationId, int $playerId, ?int $adminId = null): void
{
    ensureTournamentRosterTables($pdo);
    $params = ['registration_id' => $registrationId, 'player_id' => $playerId, 'admin_id' => $adminId];
    $pdo->prepare('UPDATE tournament_registration_members SET checkin_status = \'checked_in\', checkin_at = NOW()
        WHERE tournament_registration_id = :registration_id AND player_id = :player_id')
        ->execute($params);
    $pdo->prepare('UPDATE player_tournament_checkins SET checkin_status = \'checked_in\', checked_in_at = NOW(), checked_in_by = :admin_id
        WHERE tournament_registration_id = :registration_id AND player_id = :player_id')
        ->execute($params);

    $remaining = $pdo->prepare('SELECT COUNT(*) FROM tournament_registration_members
        WHERE tournament_registration_id = :registration_id
          AND is_required_for_checkin = 1
          AND checkin_status NOT IN (\'checked_in\', \'waived\')');
    $remaining->execute(['registration_id' => $registrationId]);
    if ((int) $remaining->fetchColumn() === 0) {
        $pdo->prepare('UPDATE tournament_registrations SET checkin_status = \'checked_in\', checkin_at = NOW()
            WHERE tournament_registration_id = :registration_id AND status = \'approved\'')
            ->execute(['registration_id' => $registrationId]);
    }
}
