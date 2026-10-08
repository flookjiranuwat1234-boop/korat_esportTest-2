<?php

require_once __DIR__ . '/team_roles.php';
require_once __DIR__ . '/registration_status.php';

function tournamentRegistrationCategory(PDO $pdo, int $categoryId): ?array
{
    $stmt = $pdo->prepare('SELECT tc.*, t.start_date, t.game_id AS tournament_game_id,
            g.name AS game_name, g.slug AS game_slug, g.play_mode AS game_play_mode
        FROM tournament_categories tc
        INNER JOIN tournaments t ON t.tournament_id = tc.tournament_id
        INNER JOIN games g ON g.game_id = t.game_id
        WHERE tc.tournament_category_id = :id
        LIMIT 1');
    $stmt->execute(['id' => $categoryId]);
    $category = $stmt->fetch(PDO::FETCH_ASSOC);
    return $category ?: null;
}

function assertTournamentRegistrationCapacity(PDO $pdo, int $tournamentId, int $categoryId): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('ตรวจสอบจำนวนผู้สมัครต้องทำภายใน transaction');
    }

    $tournamentStmt = $pdo->prepare('SELECT max_teams FROM tournaments WHERE tournament_id = :tournament_id FOR UPDATE');
    $tournamentStmt->execute(['tournament_id' => $tournamentId]);
    $tournament = $tournamentStmt->fetch(PDO::FETCH_ASSOC);

    $categoryStmt = $pdo->prepare('SELECT max_participants FROM tournament_categories
        WHERE tournament_category_id = :category_id AND tournament_id = :tournament_id AND is_active = 1
        FOR UPDATE');
    $categoryStmt->execute(['category_id' => $categoryId, 'tournament_id' => $tournamentId]);
    $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tournament || !$category) {
        throw new InvalidArgumentException('ไม่พบรุ่นการแข่งขันของ Tournament นี้');
    }

    $categoryCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_registrations
        WHERE tournament_category_id = :category_id AND status IN ("pending", "approved")');
    $categoryCountStmt->execute(['category_id' => $categoryId]);
    $categoryCount = (int) $categoryCountStmt->fetchColumn();
    $categoryCapacity = (int) ($category['max_participants'] ?? 0);
    if ($categoryCapacity > 0 && $categoryCount >= $categoryCapacity) {
        throw new InvalidArgumentException('รุ่นการแข่งขันนี้เต็มจำนวนแล้ว');
    }

    $tournamentCapacity = (int) ($tournament['max_teams'] ?? 0);
    if ($tournamentCapacity > 0) {
        $tournamentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM tournament_registrations
            WHERE tournament_id = :tournament_id AND status IN ("pending", "approved")');
        $tournamentCountStmt->execute(['tournament_id' => $tournamentId]);
        if ((int) $tournamentCountStmt->fetchColumn() >= $tournamentCapacity) {
            throw new InvalidArgumentException('จำนวนผู้สมัครของรายการนี้ครบแล้ว');
        }
    }
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

function tournamentCategoryRequiredGender(array $category): string
{
    $categoryCode = strtolower(trim((string) ($category['category_code'] ?? $category['code'] ?? '')));
    $gender = $categoryCode === 'open'
        ? 'open'
        : strtolower(trim((string) ($category['gender'] ?? $category['eligibility_gender'] ?? $category['category_gender'] ?? '')));
    if (($gender === '' || $gender === 'open') && in_array($categoryCode, ['male', 'female'], true)) {
        $gender = $categoryCode;
    }

    return $gender;
}

function tournamentPlayerGender(array $player): string
{
    return strtolower(trim((string) ($player['gender'] ?? '')));
}

function tournamentCategoryAllowsPlayer(array $player, array $category): ?string
{
    if (($player['account_status'] ?? '') !== 'active') return 'บัญชีผู้เล่นไม่ได้เปิดใช้งาน';

    $age = tournamentPlayerAge($player['birth_date'] ?? null, $category['start_date'] ?? null);
    $minAge = $category['min_age'] ?? $category['min_age_years'] ?? $category['minimum_age'] ?? null;
    $maxAge = $category['max_age'] ?? $category['max_age_years'] ?? $category['maximum_age'] ?? null;
    $gameName = strtolower(trim((string) ($category['game_name'] ?? '')));
    $gameSlug = strtolower(trim((string) ($category['game_slug'] ?? '')));
    $isRovUnder18 = $gameSlug === 'rov-u18'
        || $gameName === 'arena of valor (rov) - รุ่นอายุต่ำกว่า 18 ปี';
    $isRobloxUnder13 = $gameSlug === 'roblox-u12'
        || $gameName === 'roblox - รุ่นอายุ 8-12 ปี';
    if ($isRovUnder18) {
        $maxAge = $maxAge === null || $maxAge === '' ? 17 : min(17, (int) $maxAge);
    }
    if ($maxAge === null && $isRobloxUnder13) {
        $minAge = 8;
        $maxAge = 12;
    }
    if ($minAge !== null && $minAge !== '' && ($age === null || $age < (int) $minAge)) return 'อายุต่ำกว่าเกณฑ์ของรุ่นแข่งขัน';
    if ($maxAge !== null && $maxAge !== '' && ($age === null || $age > (int) $maxAge)) return 'อายุเกินเกณฑ์ของรุ่นแข่งขัน';

    $gender = tournamentCategoryRequiredGender($category);
    $playerGender = tournamentPlayerGender($player);
    if ($gender && !in_array($gender, ['open', 'mixed', 'all'], true) && $playerGender !== $gender) {
        return 'เพศไม่ตรงตามรุ่นแข่งขัน';
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
    $requiredGender = tournamentCategoryRequiredGender($category);
    $genderFilter = match ($requiredGender) {
        'male' => " AND LOWER(TRIM(p.gender)) = 'male'",
        'female' => " AND LOWER(TRIM(p.gender)) = 'female'",
        default => '',
    };
    $stmt = $pdo->prepare('SELECT p.player_id, p.user_id, p.display_name, p.gender, p.birth_date,
            p.eligibility_status, u.username, u.status AS account_status
        FROM players p
        INNER JOIN users u ON u.user_id = p.user_id
        WHERE (u.status = "active" OR u.status IS NULL)
          AND (p.display_name LIKE :term OR u.username LIKE :term OR CAST(p.player_id AS CHAR) = :exact)
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
        ' . $genderFilter . '
        ORDER BY p.display_name, u.username
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
        $results[] = [
            'player_id' => (int) $player['player_id'],
            'display_name' => (string) ($player['display_name'] ?: $player['username']),
            'eligible' => $reason === null,
            'staff_eligible' => ($player['account_status'] ?? '') === 'active',
            'eligibility_reason' => $reason,
            'age_at_tournament' => tournamentPlayerAge($player['birth_date'], $category['start_date']),
        ];
    }
    return $results;
}

function saveTeamTournamentRegistration(PDO $pdo, int $userId, int $tournamentId, int $categoryId, string $teamName, array $roster, ?string $logoPath = null, ?int $existingTeamId = null): int
{
    $category = tournamentRegistrationCategory($pdo, $categoryId);
    if (!$category || (int) $category['tournament_id'] !== $tournamentId) {
        throw new InvalidArgumentException('ไม่พบรุ่นการแข่งขันของ Tournament นี้');
    }
    $playerStmt = $pdo->prepare('SELECT p.player_id, p.display_name, p.gender, p.birth_date, p.eligibility_status,
            u.status AS account_status
        FROM players p INNER JOIN users u ON u.user_id = p.user_id
        WHERE p.user_id = :user_id LIMIT 1');
    $playerStmt->execute(['user_id' => $userId]);
    $captain = $playerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$captain) throw new InvalidArgumentException('บัญชีนี้ยังไม่มี Player Profile');

    $useExistingTeam = $existingTeamId !== null && $existingTeamId > 0;
    $existingTeam = null;
    $existingTeamRoster = [];
    $teamName = trim($teamName);
    if ($teamName === '' && !$useExistingTeam) throw new InvalidArgumentException('กรุณากรอกชื่อทีม');
    if (!$roster) throw new InvalidArgumentException('กรุณาเลือกผู้เล่นในทีมอย่างน้อยหนึ่งคน');
    $normalized = [];
    foreach ($roster as $member) {
        $playerId = (int) ($member['player_id'] ?? 0);
        $roles = normalizeTeamRoles((array) ($member['roles'] ?? []));
        if ($playerId <= 0 || !$roles) throw new InvalidArgumentException('ข้อมูลสมาชิกทีมหรือบทบาทไม่ถูกต้อง');
        if (isset($normalized[$playerId])) throw new InvalidArgumentException('ห้ามเลือกผู้เล่นซ้ำ');
        $normalized[$playerId] = ['player_id' => $playerId, 'roles' => $roles];
    }
    if ($useExistingTeam) ensureTeamMemberRolesTable($pdo);

    ensureRegistrationStatusHistoryTable($pdo);
    $pdo->beginTransaction();
    try {
        assertTournamentRegistrationCapacity($pdo, $tournamentId, $categoryId);

        if (($category['game_play_mode'] ?? '') !== 'team') {
            throw new InvalidArgumentException('รายการนี้ไม่ใช่ประเภททีม');
        }
        $captainIsActive = false;
        if ($useExistingTeam) {
            $existingTeamStmt = $pdo->prepare('SELECT team_id, name, logo_path, game_id, captain_player_id
                FROM teams
                WHERE team_id = :team_id AND captain_player_id = :captain AND status = "active"
                LIMIT 1 FOR UPDATE');
            $existingTeamStmt->execute([
                'team_id' => $existingTeamId,
                'captain' => (int) $captain['player_id'],
            ]);
            $existingTeam = $existingTeamStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingTeam) {
                throw new InvalidArgumentException('ไม่พบทีมเดิมหรือคุณไม่มีสิทธิ์สมัครแข่งขันในนามทีมนี้');
            }
            if ($existingTeam['game_id'] !== null && (int) $existingTeam['game_id'] !== (int) $category['tournament_game_id']) {
                throw new InvalidArgumentException('ทีมนี้ผูกกับเกมอื่น ไม่สามารถสมัครรายการนี้ได้');
            }
            if ($existingTeam['game_id'] === null) {
                $pdo->prepare('UPDATE teams SET game_id = :game_id WHERE team_id = :team_id AND game_id IS NULL')
                    ->execute(['game_id' => (int) $category['tournament_game_id'], 'team_id' => $existingTeamId]);
            }
            $teamName = (string) $existingTeam['name'];
            $logoPath = $existingTeam['logo_path'] ?: null;

            $teamMembersStmt = $pdo->prepare('SELECT tm.team_member_id, tm.player_id, u.status AS account_status
                FROM team_members tm
                INNER JOIN players p ON p.player_id = tm.player_id
                INNER JOIN users u ON u.user_id = p.user_id
                WHERE tm.team_id = :team_id AND tm.is_active = 1
                ORDER BY tm.team_member_id FOR UPDATE');
            $teamMembersStmt->execute(['team_id' => $existingTeamId]);
            $teamMembers = $teamMembersStmt->fetchAll(PDO::FETCH_ASSOC);
            $existingTeamRoster = [];
            $captainIsActive = false;
            foreach ($teamMembers as $teamMember) {
                $playerId = (int) $teamMember['player_id'];
                $roles = normalizeTeamRoles(getTeamMemberRoles($pdo, (int) $teamMember['team_member_id']));
                if ($playerId === (int) $captain['player_id']) {
                    $captainIsActive = true;
                    if (($teamMember['account_status'] ?? '') !== 'active') {
                        throw new InvalidArgumentException('บัญชีหัวหน้าทีมไม่ได้เปิดใช้งาน');
                    }
                }
                $existingTeamRoster[$playerId] = [
                    'team_member_id' => (int) $teamMember['team_member_id'],
                    'roles' => $roles,
                ];
            }
            if (!$captainIsActive) {
                throw new InvalidArgumentException('หัวหน้าทีมต้องเป็นสมาชิกที่ใช้งานอยู่ของทีม');
            }
        }

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
        if ($useExistingTeam) {
            $teamId = (int) $existingTeam['team_id'];
        } else {
            $duplicateTeam = $pdo->prepare('SELECT team_id FROM teams
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
                  AND game_id = :game_id
                LIMIT 1 FOR UPDATE');
            $duplicateTeam->execute([
                'name' => $teamName,
                'game_id' => (int) $category['tournament_game_id'],
            ]);
            if ($duplicateTeam->fetchColumn()) {
                throw new InvalidArgumentException('ชื่อทีมนี้ถูกใช้แล้ว');
            }
            $insertTeam = $pdo->prepare('INSERT INTO teams (name, logo_path, captain_player_id, game_id, is_solo_wrapper, status)
                VALUES (:name, :logo, :captain, :game_id, 0, "active")');
            $insertTeam->execute([
                'name' => $teamName, 'logo' => $logoPath,
                'captain' => $captain['player_id'],
                'game_id' => (int) $category['tournament_game_id'],
            ]);
            $teamId = (int) $pdo->lastInsertId();
        }

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
        if (!$useExistingTeam && !isset($normalized[(int) $captain['player_id']])) {
            $memberInsert->execute([
                'team_id' => $teamId, 'player_id' => (int) $captain['player_id'],
                'roles' => '', 'role' => 'player',
            ]);
        }
        foreach ($normalized as $member) {
            $playerCheck = $pdo->prepare('SELECT p.player_id, p.display_name, p.gender, p.birth_date,
                    p.eligibility_status, u.status AS account_status
                FROM players p INNER JOIN users u ON u.user_id = p.user_id
                WHERE p.player_id = :player_id LIMIT 1');
            $playerCheck->execute(['player_id' => $member['player_id']]);
            $selectedPlayer = $playerCheck->fetch(PDO::FETCH_ASSOC);
            if (!$selectedPlayer) throw new InvalidArgumentException('ไม่พบผู้เล่นที่เลือก');
            $roles = $member['roles'];
            $isStaff = !in_array('player', $roles, true) && !in_array('substitute', $roles, true);
            $reason = tournamentCategoryAllowsPlayer($selectedPlayer, $category);
            if (($selectedPlayer['account_status'] ?? '') !== 'active') {
                throw new InvalidArgumentException($selectedPlayer['display_name'] . ': บัญชีผู้เล่นไม่ได้เปิดใช้งาน');
            }
            if (!$isStaff && $reason !== null) {
                throw new InvalidArgumentException($selectedPlayer['display_name'] . ': ' . $reason);
            }
            $primaryRole = in_array('player', $roles, true)
                ? 'player'
                : (in_array('substitute', $roles, true) ? 'substitute' : ($roles[0] ?? ''));
            if (in_array('player', $roles, true)) $starterCount++;
            if (in_array('substitute', $roles, true)) $substituteCount++;
            if (!$useExistingTeam) {
                $memberInsert->execute([
                    'team_id' => $teamId, 'player_id' => $member['player_id'],
                    'roles' => implode(',', $roles), 'role' => $primaryRole,
                ]);
                $memberIdStmt->execute(['team_id' => $teamId, 'player_id' => $member['player_id']]);
                syncTeamMemberRoles($pdo, (int) $memberIdStmt->fetchColumn(), $roles);
            }
        }

        $requiredRoleValue = strtolower(trim((string) ($category['checkin_required_roles'] ?? '')));
        $decodedRequiredRoles = json_decode($requiredRoleValue, true);
        $requiredRoles = is_array($decodedRequiredRoles)
            ? array_values(array_filter(array_map('trim', $decodedRequiredRoles)))
            : array_filter(array_map('trim', explode(',', $requiredRoleValue)));
        if ($useExistingTeam) {
            $teamRosterRoles = [];
            foreach ($normalized as $member) {
                $teamRosterRoles = array_merge($teamRosterRoles, $member['roles']);
            }
            $teamRosterRoles = array_unique($teamRosterRoles);
            if (in_array('captain', $requiredRoles, true)
                && !isset($normalized[(int) $captain['player_id']])) {
                throw new InvalidArgumentException('รุ่นแข่งขันนี้กำหนดให้หัวหน้าทีมต้องอยู่ในรายชื่อผู้สมัคร');
            }
            if ($captainIsActive) $teamRosterRoles[] = 'captain';
            if (array_diff($requiredRoles, $teamRosterRoles)) {
                throw new InvalidArgumentException('รายชื่อทีมยังไม่ครบตามบทบาทที่รายการกำหนด');
            }
        }
        $required = static function (array $roles, int $playerId) use ($requiredRoles, $useExistingTeam, $captain): int {
            return (int) (
                array_intersect($requiredRoles, $roles)
                || ($useExistingTeam && in_array('captain', $requiredRoles, true) && $playerId === (int) $captain['player_id'])
            );
        };
        $requiredStarters = (int) ($category['starters_count'] ?? $category['required_starters'] ?? 0);
        $requiredSubs = (int) ($category['substitutes_count'] ?? $category['required_substitutes'] ?? 0);
        if ($starterCount !== $requiredStarters || $substituteCount !== $requiredSubs) {
            throw new InvalidArgumentException("ต้องมีตัวจริง {$requiredStarters} คน และตัวสำรอง {$requiredSubs} คน");
        }
        if ($useExistingTeam) {
            $lockedRosterStmt = $pdo->prepare('SELECT tr.roster_locked_at,
                    COALESCE(tour.roster_lock_at, tour.checkin_close_at) AS roster_lock_deadline
                FROM tournament_registrations tr
                INNER JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
                WHERE tr.team_id = :team_id AND tr.status = "approved"
                ORDER BY tr.tournament_registration_id DESC
                LIMIT 1 FOR UPDATE');
            $lockedRosterStmt->execute(['team_id' => $teamId]);
            $lockedRoster = $lockedRosterStmt->fetch(PDO::FETCH_ASSOC);
            $rosterIsLocked = $lockedRoster && (
                $lockedRoster['roster_locked_at']
                || ($lockedRoster['roster_lock_deadline'] && strtotime($lockedRoster['roster_lock_deadline']) <= time())
            );
            if ($rosterIsLocked) {
                $currentRoster = [];
                foreach ($existingTeamRoster as $playerId => $member) {
                    if ($member['roles']) {
                        $roles = $member['roles'];
                        sort($roles);
                        $currentRoster[$playerId] = $roles;
                    }
                }
                $submittedRoster = [];
                foreach ($normalized as $playerId => $member) {
                    $roles = $member['roles'];
                    sort($roles);
                    $submittedRoster[$playerId] = $roles;
                }
                ksort($currentRoster);
                ksort($submittedRoster);
                if ($currentRoster !== $submittedRoster) {
                    throw new InvalidArgumentException('ทีมมีรายการแข่งขันที่ล็อกไลน์อัปแล้ว จึงแก้ไขสมาชิกหรือบทบาทไม่ได้');
                }
            } else {
                foreach ($existingTeamRoster as $playerId => $member) {
                    if ($playerId !== (int) $captain['player_id'] && !isset($normalized[$playerId])) {
                        $pdo->prepare('UPDATE team_members
                            SET is_active = 0, left_at = NOW()
                            WHERE team_member_id = :member_id AND is_active = 1')
                            ->execute(['member_id' => $member['team_member_id']]);
                    }
                }

                $memberLookup = $pdo->prepare('SELECT team_member_id, is_active FROM team_members
                    WHERE team_id = :team_id AND player_id = :player_id LIMIT 1 FOR UPDATE');
                foreach ($normalized as $playerId => $member) {
                    $memberLookup->execute(['team_id' => $teamId, 'player_id' => $playerId]);
                    $teamMember = $memberLookup->fetch(PDO::FETCH_ASSOC);
                    if ($teamMember) {
                        if (!(int) $teamMember['is_active']) {
                            $pdo->prepare('UPDATE team_members
                                SET is_active = 1, left_at = NULL, joined_at = NOW()
                                WHERE team_member_id = :member_id')
                                ->execute(['member_id' => (int) $teamMember['team_member_id']]);
                        }
                        syncTeamMemberRoles($pdo, (int) $teamMember['team_member_id'], $member['roles']);
                    } else {
                        $pdo->prepare('INSERT INTO team_members
                            (team_id, player_id, member_roles, in_game_role, is_active, joined_at)
                            VALUES (:team_id, :player_id, :roles, :primary_role, 1, NOW())')
                            ->execute([
                                'team_id' => $teamId,
                                'player_id' => $playerId,
                                'roles' => implode(',', $member['roles']),
                                'primary_role' => $member['roles'][0],
                            ]);
                        syncTeamMemberRoles($pdo, (int) $pdo->lastInsertId(), $member['roles']);
                    }
                }
            }
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
                'required' => $required($member['roles'], (int) $member['player_id']),
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
