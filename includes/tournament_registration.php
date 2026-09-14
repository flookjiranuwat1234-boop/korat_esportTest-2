<?php

require_once __DIR__ . '/team_roles.php';
require_once __DIR__ . '/registration_status.php';

function tournamentRegistrationCategory(PDO $pdo, int $categoryId): ?array
{
    $stmt = $pdo->prepare('SELECT tc.*, t.start_date, t.name AS tournament_name
        FROM tournament_categories tc
        INNER JOIN tournaments t ON t.tournament_id = tc.tournament_id
        WHERE tc.tournament_category_id = :id
        LIMIT 1');
    $stmt->execute(['id' => $categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    return $category ?: null;
}

function tournamentPlayerAge(?string $birthDate, ?string $startDate): ?int
{
    if (!$birthDate || !$startDate) return null;
    try {
        return (new DateTime($birthDate))->diff(new DateTime($startDate))->y;
    } catch (Throwable $exception) {
        return null;
    }
}

function tournamentCategoryAllowsPlayer(array $player, array $category): ?string
{
    if (($player['account_status'] ?? '') !== 'active') return 'บัญชีผู้เล่นไม่ได้เปิดใช้งาน';
    if (($player['eligibility_status'] ?? '') !== 'verified') return 'โปรไฟล์ผู้เล่นยังไม่ผ่านการยืนยัน';

    $age = tournamentPlayerAge($player['birth_date'] ?? null, $category['start_date'] ?? null);
    $minAge = $category['min_age'] ?? $category['min_age_years'] ?? $category['minimum_age'] ?? null;
    $maxAge = $category['max_age'] ?? $category['max_age_years'] ?? $category['maximum_age'] ?? null;
    $tournamentName = strtolower((string) ($category['tournament_name'] ?? ''));
    if ($maxAge === null && (str_contains($tournamentName, 'u18') || str_contains($tournamentName, 'ต่ำกว่า 18'))) {
        $maxAge = 17;
    }
    if ($maxAge === null && (str_contains($tournamentName, 'roblox') || strtolower((string) ($category['category_code'] ?? '')) === 'junior')) {
        $minAge = 8;
        $maxAge = 12;
    }
    if ($minAge !== null && $minAge !== '' && ($age === null || $age < (int) $minAge)) return 'อายุไม่ถึงเกณฑ์ของ Category';
    if ($maxAge !== null && $maxAge !== '' && ($age === null || $age > (int) $maxAge)) return 'อายุเกินเกณฑ์ของ Category';

    $gender = strtolower(trim((string) ($category['gender'] ?? $category['eligibility_gender'] ?? $category['category_gender'] ?? '')));
    if ($gender === '' || $gender === 'open') {
        $categoryCode = strtolower(trim((string) ($category['category_code'] ?? $category['code'] ?? '')));
        $gender = in_array($categoryCode, ['male', 'female'], true) ? $categoryCode : $gender;
    }
    $playerGender = strtolower(trim((string) ($player['gender'] ?? '')));
    if ($gender && !in_array($gender, ['open', 'mixed', 'all'], true) && $playerGender !== $gender) {
        return 'เพศไม่ตรงตาม Category';
    }
    return null;
}

function searchTournamentPlayers(PDO $pdo, int $tournamentId, int $categoryId, string $term, ?int $teamId = null): array
{
    $category = tournamentRegistrationCategory($pdo, $categoryId);
    if (!$category || (int) $category['tournament_id'] !== $tournamentId) return [];

    $term = trim($term);
    if ($term === '') return [];
    $like = '%' . $term . '%';
    $stmt = $pdo->prepare('SELECT p.player_id, p.user_id, p.real_name, p.gender, p.birth_date,
            p.eligibility_status, u.username, u.status AS account_status
        FROM players p
        INNER JOIN users u ON u.user_id = p.user_id
        WHERE (u.status = "active" OR u.status IS NULL)
          AND (p.real_name LIKE :term OR u.username LIKE :term OR CAST(p.player_id AS CHAR) = :exact)
          AND NOT EXISTS (
              SELECT 1 FROM tournament_registrations conflict
              WHERE conflict.tournament_id = :tournament_id
                AND conflict.tournament_category_id = :category_id
                AND conflict.status IN ("pending", "approved")
                AND conflict.team_id IS NOT NULL
                AND (:exclude_team_id IS NULL OR conflict.team_id <> :conflict_team_id)
                AND EXISTS (
                    SELECT 1 FROM tournament_registration_members conflict_member
                    WHERE conflict_member.tournament_registration_id = conflict.tournament_registration_id
                      AND conflict_member.player_id = p.player_id
                      AND conflict_member.roster_status = "active"
                )
          )
        ORDER BY p.real_name, u.username
        LIMIT 30');
    $stmt->execute([
        'term' => $like,
        'exact' => $term,
        'tournament_id' => $tournamentId,
        'category_id' => $categoryId,
        'exclude_team_id' => $teamId ?: null,
        'conflict_team_id' => $teamId ?: null,
    ]);

    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $player) {
        $reason = tournamentCategoryAllowsPlayer($player, $category);
        $player['eligible'] = $reason === null;
        $player['eligibility_reason'] = $reason;
        $player['age_at_tournament'] = tournamentPlayerAge($player['birth_date'], $category['start_date']);
        $results[] = $player;
    }
    return $results;
}

function saveTeamTournamentRegistration(PDO $pdo, int $userId, int $tournamentId, int $categoryId, string $teamName, array $roster, string $teamTag = '', ?string $logoPath = null): int
{
    $category = tournamentRegistrationCategory($pdo, $categoryId);
    if (!$category || (int) $category['tournament_id'] !== $tournamentId) {
        throw new InvalidArgumentException('ไม่พบรุ่นการแข่งขันของ Tournament นี้');
    }
    $playerStmt = $pdo->prepare('SELECT p.player_id, p.real_name, p.gender, p.birth_date, p.eligibility_status,
            u.status AS account_status
        FROM players p INNER JOIN users u ON u.user_id = p.user_id
        WHERE p.user_id = :user_id LIMIT 1');
    $playerStmt->execute(['user_id' => $userId]);
    $captain = $playerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$captain) throw new InvalidArgumentException('บัญชีนี้ยังไม่มี Player Profile');

    $teamName = trim($teamName);
    if ($teamName === '') throw new InvalidArgumentException('กรุณากรอกชื่อทีม');
    $teamTag = strtoupper(trim($teamTag));
    if ($teamTag === '' || !preg_match('/^[A-Z0-9_-]{2,10}$/', $teamTag)) {
        throw new InvalidArgumentException('กรุณากรอกตัวย่อทีม 2-10 ตัวอักษรภาษาอังกฤษหรือตัวเลข');
    }
    if (!$roster) throw new InvalidArgumentException('กรุณาเลือกนักกีฬาอย่างน้อยหนึ่งคน');

    $normalized = [];
    foreach ($roster as $member) {
        $playerId = (int) ($member['player_id'] ?? 0);
        $roles = normalizeTeamRoles((array) ($member['roles'] ?? []));
        if ($playerId <= 0 || !$roles) throw new InvalidArgumentException('ข้อมูลสมาชิกทีมไม่ถูกต้อง');
        if (isset($normalized[$playerId])) throw new InvalidArgumentException('ห้ามเลือกผู้เล่นซ้ำ');
        $normalized[$playerId] = ['player_id' => $playerId, 'roles' => $roles];
    }
    ensureRegistrationStatusHistoryTable($pdo);
    $pdo->beginTransaction();
    try {
        $capacityStmt = $pdo->prepare('
            SELECT tc.max_participants, COUNT(tr.tournament_registration_id) AS registered_count
            FROM tournament_categories tc
            LEFT JOIN tournament_registrations tr
                ON tr.tournament_category_id = tc.tournament_category_id
                AND tr.status IN ("pending", "approved")
                AND tr.team_id IS NOT NULL
            WHERE tc.tournament_category_id = :category_id
            GROUP BY tc.tournament_category_id, tc.max_participants
            FOR UPDATE
        ');
        $capacityStmt->execute(['category_id' => $categoryId]);
        $capacity = $capacityStmt->fetch(PDO::FETCH_ASSOC);
        if (!$capacity || (int) $capacity['registered_count'] >= (int) $capacity['max_participants']) {
            throw new InvalidArgumentException('รายการนี้เต็มจำนวนแล้ว');
        }

        $teamId = 0;
        $duplicateRegistration = $pdo->prepare('SELECT tr.tournament_registration_id
            FROM tournament_registrations tr
            INNER JOIN teams existing_team ON existing_team.team_id = tr.team_id
            WHERE tr.tournament_id = :tournament_id
              AND tr.tournament_category_id = :category_id
              AND existing_team.captain_player_id = :captain
              AND tr.status IN ("pending", "approved")
            LIMIT 1 FOR UPDATE');
        $duplicateRegistration->execute([
            'tournament_id' => $tournamentId,
            'category_id' => $categoryId,
            'captain' => (int) $captain['player_id'],
        ]);
        if ($duplicateRegistration->fetchColumn()) {
            throw new InvalidArgumentException('หัวหน้าทีมนี้สมัคร Category นี้แล้ว');
        }
        $conflictMemberStmt = $pdo->prepare('SELECT conflict.tournament_registration_id
            FROM tournament_registrations conflict
            INNER JOIN tournament_registration_members conflict_member
                ON conflict_member.tournament_registration_id = conflict.tournament_registration_id
            WHERE conflict.tournament_id = :tournament_id
              AND conflict.tournament_category_id = :category_id
              AND conflict.status IN ("pending", "approved")
              AND conflict_member.player_id = :player_id
              AND conflict_member.roster_status = "active"
            LIMIT 1 FOR UPDATE');
        foreach ($normalized as $member) {
            $conflictMemberStmt->execute([
                'tournament_id' => $tournamentId,
                'category_id' => $categoryId,
                'player_id' => $member['player_id'],
            ]);
            if ($conflictMemberStmt->fetchColumn()) {
                throw new InvalidArgumentException('ผู้เล่นคนเดียวกันลงทะเบียนซ้ำใน Tournament/Category นี้ไม่ได้');
            }
        }
        $duplicateTeam = $pdo->prepare('SELECT team_id FROM teams
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) OR UPPER(TRIM(tag)) = UPPER(TRIM(:tag))
            LIMIT 1 FOR UPDATE');
        $duplicateTeam->execute(['name' => $teamName, 'tag' => $teamTag]);
        if ($duplicateTeam->fetchColumn()) {
            throw new InvalidArgumentException('ชื่อทีม หรือตัวย่อทีมนี้ถูกใช้แล้ว');
        }
        $insertTeam = $pdo->prepare('INSERT INTO teams (name, tag, logo_path, captain_player_id, game_id, is_solo_wrapper, status)
            VALUES (:name, :tag, :logo, :captain, NULL, 0, "active")');
        $insertTeam->execute(['name' => $teamName, 'tag' => $teamTag, 'logo' => $logoPath, 'captain' => $captain['player_id']]);
        $teamId = (int) $pdo->lastInsertId();

        $duplicate = $pdo->prepare('SELECT tournament_registration_id FROM tournament_registrations
            WHERE tournament_category_id = :category_id AND team_id = :team_id
              AND status IN ("pending", "approved") LIMIT 1');
        $duplicate->execute(['category_id' => $categoryId, 'team_id' => $teamId]);
        if ($duplicate->fetchColumn()) throw new InvalidArgumentException('ทีมนี้สมัคร Category นี้แล้ว');

        $starterCount = 0;
        $substituteCount = 0;
        $memberInsert = $pdo->prepare('INSERT INTO team_members
            (team_id, player_id, member_roles, in_game_role, is_active, joined_at)
            VALUES (:team_id, :player_id, :roles, :role, 1, NOW())
            ON DUPLICATE KEY UPDATE member_roles = VALUES(member_roles), in_game_role = VALUES(in_game_role), is_active = 1');
        $memberIdStmt = $pdo->prepare('SELECT team_member_id FROM team_members WHERE team_id = :team_id AND player_id = :player_id');
        if (!isset($normalized[(int) $captain['player_id']])) {
            $memberInsert->execute([
                'team_id' => $teamId, 'player_id' => (int) $captain['player_id'],
                'roles' => '', 'role' => 'player',
            ]);
        }
        foreach ($normalized as $member) {
            $playerCheck = $pdo->prepare('SELECT p.player_id, p.real_name, p.gender, p.birth_date,
                    p.eligibility_status, u.status AS account_status
                FROM players p INNER JOIN users u ON u.user_id = p.user_id
                WHERE p.player_id = :player_id LIMIT 1');
            $playerCheck->execute(['player_id' => $member['player_id']]);
            $selectedPlayer = $playerCheck->fetch(PDO::FETCH_ASSOC);
            if (!$selectedPlayer) throw new InvalidArgumentException('ไม่พบผู้เล่นที่เลือก');
            $reason = tournamentCategoryAllowsPlayer($selectedPlayer, $category);
            if ($reason !== null) throw new InvalidArgumentException($selectedPlayer['real_name'] . ': ' . $reason);

            $roles = $member['roles'];
            $primaryRole = in_array('player', $roles, true) ? 'player' : (in_array('substitute', $roles, true) ? 'substitute' : $roles[0]);
            if (in_array('player', $roles, true)) $starterCount++;
            if (in_array('substitute', $roles, true)) $substituteCount++;
            $memberInsert->execute([
                'team_id' => $teamId, 'player_id' => $member['player_id'],
                'roles' => implode(',', $roles), 'role' => $primaryRole,
            ]);
            $memberIdStmt->execute(['team_id' => $teamId, 'player_id' => $member['player_id']]);
            syncTeamMemberRoles($pdo, (int) $memberIdStmt->fetchColumn(), $roles);
        }

        $requiredRoleValue = strtolower(trim((string) ($category['checkin_required_roles'] ?? '')));
        $decodedRequiredRoles = json_decode($requiredRoleValue, true);
        $requiredRoles = is_array($decodedRequiredRoles)
            ? array_values(array_filter(array_map('trim', $decodedRequiredRoles)))
            : array_filter(array_map('trim', explode(',', $requiredRoleValue)));
        $required = static function (array $roles) use ($requiredRoles): int {
            return (int) (bool) array_intersect($requiredRoles, $roles);
        };
        $requiredStarters = (int) ($category['starters_count'] ?? $category['required_starters'] ?? 0);
        $requiredSubs = (int) ($category['substitutes_count'] ?? $category['required_substitutes'] ?? 0);
        if ($starterCount !== $requiredStarters || $substituteCount !== $requiredSubs) {
            throw new InvalidArgumentException("ต้องมีตัวจริง {$requiredStarters} คน และตัวสำรอง {$requiredSubs} คน");
        }
        $insert = $pdo->prepare('INSERT INTO tournament_registrations
            (tournament_id, tournament_category_id, team_id, player_id, category, status, participation_status)
            VALUES (:tournament_id, :category_id, :team_id, NULL, :category, "pending", "pending_admin_review")');
        $insert->execute([
            'tournament_id' => $tournamentId, 'category_id' => $categoryId, 'team_id' => $teamId,
            'category' => (string) ($category['category_code'] ?? $category['code'] ?? 'open'),
        ]);
        $registrationId = (int) $pdo->lastInsertId();

        $rosterInsert = $pdo->prepare('INSERT INTO tournament_registration_members
            (tournament_registration_id, player_id, member_roles, is_starter, is_required_for_checkin, roster_status)
            VALUES (:registration_id, :player_id, :roles, :starter, :required, "active")');
        $checkinInsert = $pdo->prepare('INSERT IGNORE INTO player_tournament_checkins
            (tournament_registration_id, player_id) VALUES (:registration_id, :player_id)');
        foreach ($normalized as $member) {
            $isStarter = (int) in_array('player', $member['roles'], true);
            $rosterInsert->execute([
                'registration_id' => $registrationId, 'player_id' => $member['player_id'],
                'roles' => implode(',', $member['roles']), 'starter' => $isStarter,
                'required' => $required($member['roles']),
            ]);
            $checkinInsert->execute(['registration_id' => $registrationId, 'player_id' => $member['player_id']]);
        }
        recordRegistrationStatus($pdo, $registrationId, 'pending', $userId, 'สมัคร Tournament แบบทีมและยืนยัน Roster');
        $pdo->commit();
        return $registrationId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
