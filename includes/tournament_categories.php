<?php
// Multi-category tournament config, participation status, and WO/DQ helpers.

require_once __DIR__ . '/tournament_roster.php';

function ensureTournamentCategorySchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    ensureTournamentRosterTables($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS tournament_days (
        tournament_day_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tournament_id INT UNSIGNED NOT NULL,
        day_number INT UNSIGNED NOT NULL,
        event_date DATE NOT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        venue_name VARCHAR(255) NULL,
        notes VARCHAR(500) NULL,
        PRIMARY KEY (tournament_day_id),
        UNIQUE KEY tournament_day_unique (tournament_id, day_number),
        CONSTRAINT tournament_days_tournament_fk FOREIGN KEY (tournament_id)
            REFERENCES tournaments (tournament_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tournament_categories (
        tournament_category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tournament_id INT UNSIGNED NOT NULL,
        category_code VARCHAR(30) NOT NULL,
        label VARCHAR(100) NOT NULL,
        max_participants INT UNSIGNED NULL,
        format VARCHAR(30) NOT NULL DEFAULT 'single_elimination',
        group_size INT UNSIGNED NULL,
        teams_advance_per_group INT UNSIGNED NULL,
        starters_count INT UNSIGNED NULL,
        substitutes_count INT UNSIGNED NULL,
        checkin_required_roles VARCHAR(255) NULL,
        seed_method VARCHAR(30) NOT NULL DEFAULT 'ranking',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (tournament_category_id),
        UNIQUE KEY tournament_category_unique (tournament_id, category_code),
        CONSTRAINT tournament_categories_tournament_fk FOREIGN KEY (tournament_id)
            REFERENCES tournaments (tournament_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $categoryCols = $pdo->query('SHOW COLUMNS FROM tournament_categories')->fetchAll(PDO::FETCH_COLUMN);
    $categoryAdditions = [
        'category_code' => "ALTER TABLE tournament_categories ADD COLUMN category_code VARCHAR(30) NULL",
        'label' => "ALTER TABLE tournament_categories ADD COLUMN label VARCHAR(100) NULL",
        'max_participants' => "ALTER TABLE tournament_categories ADD COLUMN max_participants INT UNSIGNED NULL",
        'format' => "ALTER TABLE tournament_categories ADD COLUMN format VARCHAR(30) NULL",
        'group_size' => "ALTER TABLE tournament_categories ADD COLUMN group_size INT UNSIGNED NULL",
        'starters_count' => "ALTER TABLE tournament_categories ADD COLUMN starters_count INT UNSIGNED NULL",
        'substitutes_count' => "ALTER TABLE tournament_categories ADD COLUMN substitutes_count INT UNSIGNED NULL",
        'checkin_required_roles' => "ALTER TABLE tournament_categories ADD COLUMN checkin_required_roles VARCHAR(255) NULL",
        'seed_method' => "ALTER TABLE tournament_categories ADD COLUMN seed_method VARCHAR(30) NULL",
        'is_active' => "ALTER TABLE tournament_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($categoryAdditions as $column => $statement) {
        if (!in_array($column, $categoryCols, true)) $pdo->exec($statement);
    }
    if (in_array('code', $categoryCols, true)) $pdo->exec("UPDATE tournament_categories SET category_code = COALESCE(category_code, code)");
    if (in_array('name', $categoryCols, true)) $pdo->exec("UPDATE tournament_categories SET label = COALESCE(label, name)");
    if (in_array('competition_format', $categoryCols, true)) $pdo->exec("UPDATE tournament_categories SET format = COALESCE(format, competition_format)");
    if (in_array('teams_per_group', $categoryCols, true)) $pdo->exec("UPDATE tournament_categories SET group_size = COALESCE(group_size, teams_per_group)");
    if (in_array('required_starters', $categoryCols, true)) $pdo->exec("UPDATE tournament_categories SET starters_count = COALESCE(starters_count, required_starters)");
    if (in_array('maximum_roster_size', $categoryCols, true)) $pdo->exec("UPDATE tournament_categories SET max_participants = COALESCE(max_participants, maximum_roster_size)");

    $tCols = $pdo->query('SHOW COLUMNS FROM tournaments')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('checkin_open_at', $tCols, true)) {
        $pdo->exec('ALTER TABLE tournaments ADD COLUMN checkin_open_at DATETIME NULL');
    }
    if (!in_array('checkin_close_at', $tCols, true)) {
        $pdo->exec('ALTER TABLE tournaments ADD COLUMN checkin_close_at DATETIME NULL');
    }
    if (!in_array('roster_lock_at', $tCols, true)) {
        $pdo->exec('ALTER TABLE tournaments ADD COLUMN roster_lock_at DATETIME NULL');
    }

    $rCols = $pdo->query('SHOW COLUMNS FROM tournament_registrations')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tournament_category_id', $rCols, true)) {
        $pdo->exec('ALTER TABLE tournament_registrations ADD COLUMN tournament_category_id INT UNSIGNED NULL');
    }
    if (!in_array('participation_status', $rCols, true)) {
        $pdo->exec("ALTER TABLE tournament_registrations ADD COLUMN participation_status VARCHAR(30) NOT NULL DEFAULT 'registered'");
    }
    if (!in_array('roster_locked_at', $rCols, true)) {
        $pdo->exec('ALTER TABLE tournament_registrations ADD COLUMN roster_locked_at DATETIME NULL');
    }
    if (!in_array('seed_no', $rCols, true)) {
        $pdo->exec('ALTER TABLE tournament_registrations ADD COLUMN seed_no INT UNSIGNED NULL');
    }

    $mCols = $pdo->query('SHOW COLUMNS FROM tournament_registration_members')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('checkin_waived_reason', $mCols, true)) {
        $pdo->exec('ALTER TABLE tournament_registration_members ADD COLUMN checkin_waived_reason VARCHAR(500) NULL');
    }
    if (!in_array('checkin_waived_by', $mCols, true)) {
        $pdo->exec('ALTER TABLE tournament_registration_members ADD COLUMN checkin_waived_by INT UNSIGNED NULL');
    }
    if (!in_array('checkin_waived_at', $mCols, true)) {
        $pdo->exec('ALTER TABLE tournament_registration_members ADD COLUMN checkin_waived_at DATETIME NULL');
    }

    $matchCols = $pdo->query('SHOW COLUMNS FROM matches')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('result_type', $matchCols, true)) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN result_type VARCHAR(20) NOT NULL DEFAULT 'normal'");
    }
    if (!in_array('wo_reason', $matchCols, true)) {
        $pdo->exec('ALTER TABLE matches ADD COLUMN wo_reason VARCHAR(500) NULL');
    }
    if (!in_array('tournament_category_id', $matchCols, true)) {
        $pdo->exec('ALTER TABLE matches ADD COLUMN tournament_category_id INT UNSIGNED NULL');
    }
    if (!in_array('scheduled_at', $matchCols, true)) {
        $pdo->exec('ALTER TABLE matches ADD COLUMN scheduled_at DATETIME NULL');
    }
    if (!in_array('venue_name', $matchCols, true)) {
        $pdo->exec('ALTER TABLE matches ADD COLUMN venue_name VARCHAR(255) NULL');
    }
    if (!in_array('venue_area', $matchCols, true)) {
        $pdo->exec('ALTER TABLE matches ADD COLUMN venue_area VARCHAR(100) NULL');
    }

    $groupCols = $pdo->query('SHOW COLUMNS FROM tournament_groups')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('tournament_category_id', $groupCols, true)) {
        $pdo->exec('ALTER TABLE tournament_groups ADD COLUMN tournament_category_id INT UNSIGNED NULL');
    }

    $pdo->exec("UPDATE tournament_registrations tr
        JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
        SET tr.roster_locked_at = COALESCE(tr.roster_locked_at, COALESCE(tour.roster_lock_at, tour.checkin_close_at))
        WHERE tr.roster_locked_at IS NULL AND COALESCE(tour.roster_lock_at, tour.checkin_close_at) IS NOT NULL AND COALESCE(tour.roster_lock_at, tour.checkin_close_at) <= NOW()");

    $ready = true;
}

/**
 * Required-member check-in completion for one registration.
 * Returns ['required' => int, 'checked_in' => int, 'complete' => bool]
 */
function getRegistrationCheckinProgress(PDO $pdo, int $registrationId): array
{
    ensureTournamentCategorySchema($pdo);
    $stmt = $pdo->prepare('SELECT
            COUNT(*) AS required,
            SUM(CASE WHEN checkin_status IN (\'checked_in\', \'waived\') THEN 1 ELSE 0 END) AS done
        FROM tournament_registration_members
        WHERE tournament_registration_id = :registration_id AND is_required_for_checkin = 1');
    $stmt->execute(['registration_id' => $registrationId]);
    $row = $stmt->fetch();
    $required = (int) ($row['required'] ?? 0);
    $done = (int) ($row['done'] ?? 0);
    return ['required' => $required, 'checked_in' => $done, 'complete' => $required > 0 && $done >= $required];
}

function ensureDefaultTournamentCategories(PDO $pdo, int $tournamentId): void
{
    ensureTournamentCategorySchema($pdo);
}

function getTournamentCategoryId(PDO $pdo, int $tournamentId, string $categoryCode): ?int
{
    ensureDefaultTournamentCategories($pdo, $tournamentId);
    $stmt = $pdo->prepare('SELECT tournament_category_id FROM tournament_categories
        WHERE tournament_id = :tournament_id AND category_code = :category_code');
    $stmt->execute(['tournament_id' => $tournamentId, 'category_code' => $categoryCode]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        $pdo->prepare('UPDATE tournament_categories SET is_active = 1 WHERE tournament_category_id = :category_id AND tournament_id = :tournament_id')
            ->execute(['category_id' => (int) $id, 'tournament_id' => $tournamentId]);
        return (int) $id;
    }

    $label = ['male' => 'ชาย', 'female' => 'หญิง', 'open' => 'Open'][$categoryCode] ?? $categoryCode;
    $tournamentStmt = $pdo->prepare('SELECT max_teams, format FROM tournaments WHERE tournament_id = :tournament_id');
    $tournamentStmt->execute(['tournament_id' => $tournamentId]);
    $tournament = $tournamentStmt->fetch();
    if (!$tournament) return null;
    $categoryFields = $pdo->query('SHOW COLUMNS FROM tournament_categories')->fetchAll(PDO::FETCH_COLUMN);
    $insert = in_array('code', $categoryFields, true)
        ? $pdo->prepare('INSERT INTO tournament_categories
            (tournament_id, category_code, code, label, max_participants, format)
            VALUES (:tournament_id, :category_code, :legacy_code, :label, :max_participants, :format)')
        : $pdo->prepare('INSERT INTO tournament_categories
            (tournament_id, category_code, label, max_participants, format)
            VALUES (:tournament_id, :category_code, :label, :max_participants, :format)');
    $insertParams = [
        'tournament_id' => $tournamentId,
        'category_code' => $categoryCode,
        'label' => $label,
        'max_participants' => $tournament['max_teams'],
        'format' => $tournament['format'] ?: 'single_elimination',
    ];
    if (in_array('code', $categoryFields, true)) $insertParams['legacy_code'] = $categoryCode;
    $insert->execute($insertParams);
    return (int) $pdo->lastInsertId();
}

/** Waive check-in for one roster member (admin override, audited). */
function waiveRosterMemberCheckin(PDO $pdo, int $registrationId, int $playerId, string $reason, int $adminId): void
{
    ensureTournamentCategorySchema($pdo);
    $pdo->prepare('UPDATE tournament_registration_members
        SET checkin_status = \'waived\', checkin_waived_reason = :reason, checkin_waived_by = :admin_id, checkin_waived_at = NOW()
        WHERE tournament_registration_id = :registration_id AND player_id = :player_id')
        ->execute(['reason' => $reason, 'admin_id' => $adminId, 'registration_id' => $registrationId, 'player_id' => $playerId]);
    $pdo->prepare('UPDATE player_tournament_checkins SET checkin_status = \'waived\', checked_in_by = :admin_id
        WHERE tournament_registration_id = :registration_id AND player_id = :player_id')
        ->execute(['admin_id' => $adminId, 'registration_id' => $registrationId, 'player_id' => $playerId]);
}

/**
 * Disqualify registrations that failed to complete check-in before the deadline
 * and have not yet been placed into a bracket match. Safe to call repeatedly.
 */
function disqualifyIncompleteCheckins(PDO $pdo, int $tournamentId): int
{
    ensureTournamentCategorySchema($pdo);
    $tStmt = $pdo->prepare('SELECT checkin_close_at FROM tournaments WHERE tournament_id = :tid');
    $tStmt->execute(['tid' => $tournamentId]);
    $checkinCloseAt = $tStmt->fetchColumn();
    if (!$checkinCloseAt || strtotime($checkinCloseAt) > time()) {
        return 0;
    }

    $regStmt = $pdo->prepare("SELECT tournament_registration_id, team_id FROM tournament_registrations
        WHERE tournament_id = :tid AND status = 'approved' AND participation_status = 'registered'");
    $regStmt->execute(['tid' => $tournamentId]);

    $disqualified = 0;
    $matchExistsStmt = $pdo->prepare('SELECT COUNT(*) FROM matches
        WHERE tournament_id = :tid AND (team1_id = :team_id OR team2_id = :team_id)');
    $markStmt = $pdo->prepare("UPDATE tournament_registrations SET participation_status = 'disqualified'
        WHERE tournament_registration_id = :registration_id");
    $qualifyStmt = $pdo->prepare("UPDATE tournament_registrations SET participation_status = 'qualified_for_draw'
        WHERE tournament_registration_id = :registration_id");

    foreach ($regStmt->fetchAll() as $registration) {
        $progress = getRegistrationCheckinProgress($pdo, (int) $registration['tournament_registration_id']);
        if ($progress['complete']) {
            $qualifyStmt->execute(['registration_id' => $registration['tournament_registration_id']]);
            continue;
        }

        $matchExistsStmt->execute(['tid' => $tournamentId, 'team_id' => $registration['team_id']]);
        if ((int) $matchExistsStmt->fetchColumn() === 0) {
            $markStmt->execute(['registration_id' => $registration['tournament_registration_id']]);
            $disqualified++;
        }
    }

    return $disqualified;
}

function qualifyCompletedCheckins(PDO $pdo, int $tournamentId): int
{
    ensureTournamentCategorySchema($pdo);
    $stmt = $pdo->prepare("SELECT tournament_registration_id FROM tournament_registrations
        WHERE tournament_id = :tid AND status = 'approved' AND participation_status = 'registered'");
    $stmt->execute(['tid' => $tournamentId]);
    $update = $pdo->prepare("UPDATE tournament_registrations SET participation_status = 'qualified_for_draw'
        WHERE tournament_registration_id = :registration_id");
    $qualified = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $registrationId) {
        $progress = getRegistrationCheckinProgress($pdo, (int) $registrationId);
        if ($progress['complete']) {
            $update->execute(['registration_id' => $registrationId]);
            $qualified++;
        }
    }
    return $qualified;
}

/**
 * Apply a walkover to a match whose opponent failed check-in after being drawn.
 * Only acts when exactly one side is incomplete; leaves both-incomplete matches
 * for admin review (per spec, no automatic winner in that case).
 */
function applyCheckinWalkovers(PDO $pdo, int $tournamentId): int
{
    ensureTournamentCategorySchema($pdo);
    $tStmt = $pdo->prepare('SELECT checkin_close_at FROM tournaments WHERE tournament_id = :tid');
    $tStmt->execute(['tid' => $tournamentId]);
    $checkinCloseAt = $tStmt->fetchColumn();
    if (!$checkinCloseAt || strtotime($checkinCloseAt) > time()) {
        return 0;
    }

    $matchStmt = $pdo->prepare("SELECT match_id, team1_id, team2_id FROM matches
        WHERE tournament_id = :tid AND status = 'scheduled' AND team1_id IS NOT NULL AND team2_id IS NOT NULL");
    $matchStmt->execute(['tid' => $tournamentId]);

    $regStmt = $pdo->prepare('SELECT tournament_registration_id FROM tournament_registrations
        WHERE tournament_id = :tid AND team_id = :team_id ORDER BY tournament_registration_id DESC LIMIT 1');
    $applied = 0;

    foreach ($matchStmt->fetchAll() as $match) {
        $regStmt->execute(['tid' => $tournamentId, 'team_id' => $match['team1_id']]);
        $reg1 = $regStmt->fetchColumn();
        $regStmt->execute(['tid' => $tournamentId, 'team_id' => $match['team2_id']]);
        $reg2 = $regStmt->fetchColumn();
        if (!$reg1 || !$reg2) continue;

        $p1 = getRegistrationCheckinProgress($pdo, (int) $reg1);
        $p2 = getRegistrationCheckinProgress($pdo, (int) $reg2);

        if ($p1['complete'] && $p2['complete']) continue;
        if (!$p1['complete'] && !$p2['complete']) continue; // both incomplete: leave for admin review

        $winnerId = $p1['complete'] ? $match['team1_id'] : $match['team2_id'];
        $loserId = $p1['complete'] ? $match['team2_id'] : $match['team1_id'];
        $reason = 'Check-in ไม่ครบภายในเวลาที่กำหนด';

        $pdo->prepare("UPDATE matches SET status = 'walkover', result_type = 'walkover', wo_reason = :reason,
                winner_team_id = :winner, completed_at = NOW()
            WHERE match_id = :match_id")
            ->execute(['reason' => $reason, 'winner' => $winnerId, 'match_id' => $match['match_id']]);

        if (function_exists('advanceMatchResult')) {
            try { @advanceMatchResult($pdo, $match['match_id'], $winnerId, $loserId); } catch (Exception $e) {}
        }
        $applied++;
    }

    return $applied;
}
