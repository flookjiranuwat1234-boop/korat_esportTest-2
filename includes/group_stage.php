<?php
// includes/group_stage.php
// สร้างรอบแบ่งกลุ่มและรอบ Playoff สำหรับ Group Stage + Knockout
require_once __DIR__ . '/tournament_categories.php';
require_once __DIR__ . '/bracket.php';

function calculatePointsRoundScore(string $gameName, int $placement, int $kills = 0): int
{
    $gameName = strtolower(trim($gameName));
    if (str_contains($gameName, 'free fire')) {
        $placementPoints = [1 => 10, 2 => 7, 3 => 5, 4 => 3, 5 => 2, 6 => 1];
        return ($placementPoints[$placement] ?? 0) + $kills;
    }
    if (str_contains($gameName, 'roblox')) {
        $placementPoints = [1 => 10, 2 => 8, 3 => 7, 4 => 5, 5 => 3, 6 => 2, 7 => 1, 8 => 1, 9 => 1, 10 => 1];
        return $placementPoints[$placement] ?? 0;
    }
    throw new RuntimeException('ไม่พบสูตรคะแนน Points System ของเกมนี้');
}

// ฟังก์ชันจัดการรอบแบ่งกลุ่มของ Group Stage + Knockout
function resetTournamentGroupStage(PDO $pdo, int $tournamentId): void
{
    $pdo->prepare('DELETE FROM bracket_edges WHERE match_id IN (SELECT match_id FROM matches WHERE tournament_id = :tid)')->execute(['tid' => $tournamentId]);
    $pdo->prepare('DELETE FROM matches WHERE tournament_id = :tid AND group_id IS NOT NULL')->execute(['tid' => $tournamentId]);
    $pdo->prepare('DELETE FROM group_teams WHERE group_id IN (SELECT tournament_group_id FROM tournament_groups WHERE tournament_id = :tid)')->execute(['tid' => $tournamentId]);
    $pdo->prepare('DELETE FROM group_participants WHERE group_id IN (SELECT tournament_group_id FROM tournament_groups WHERE tournament_id = :tid)')->execute(['tid' => $tournamentId]);
    $pdo->prepare('DELETE FROM tournament_groups WHERE tournament_id = :tid')->execute(['tid' => $tournamentId]);
}

function generateGroupStage(PDO $pdo, int $tournamentId): int
{
    ensureTournamentCategorySchema($pdo);
    $check = $pdo->prepare("SELECT COUNT(*) FROM tournament_groups WHERE tournament_id = :tid");
    $check->execute(['tid' => $tournamentId]);
    if ($check->fetchColumn() > 0) {
        resetTournamentGroupStage($pdo, (int) $tournamentId);
    }

    $legacyMatchCheck = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :tid");
    $legacyMatchCheck->execute(['tid' => $tournamentId]);
    if ((int) $legacyMatchCheck->fetchColumn() > 0) {
        $pdo->prepare('DELETE FROM bracket_edges WHERE match_id IN (SELECT match_id FROM matches WHERE tournament_id = :tid)')->execute(['tid' => $tournamentId]);
        $pdo->prepare('DELETE FROM matches WHERE tournament_id = :tid')->execute(['tid' => $tournamentId]);
    }

    $tStmt = $pdo->prepare("SELECT t.game_id, t.format, t.group_count, t.best_of, t.group_best_of, t.scoring_mode,
            t.points_rounds, g.play_mode
        FROM tournaments t JOIN games g ON g.game_id = t.game_id WHERE t.tournament_id = :tid");
    $tStmt->execute(['tid' => $tournamentId]);
    $tournament = $tStmt->fetch();
    $groupBestOf = max(1, (int) ($tournament['group_best_of'] ?? $tournament['best_of'] ?? 1));
    $teamRows = getSeededTeamsWithCategory($pdo, $tournamentId, $tournament['game_id'] ?? null);

    if (count($teamRows) < 2) {
        throw new Exception("ต้องมีทีมที่ผ่านการเช็กอินและพร้อมจัดสายอย่างน้อย 2 ทีม");
    }

    // แบ่งกลุ่มตามค่า “ทีมต่อกลุ่ม” ของ category
    // ไม่ใช้ group_count ของ tournament เพราะมันเป็นจำนวนกลุ่มรวมทั้งหมด ไม่ใช่ทีมต่อกลุ่ม
    $categoryStmt = $pdo->prepare("SELECT tournament_category_id, category_code, group_size, teams_advance_per_group
        FROM tournament_categories
        WHERE tournament_id = :tid AND is_active = 1");
    $categoryStmt->execute(['tid' => $tournamentId]);
    $categoryMaps = [];
    foreach ($categoryStmt->fetchAll() as $cat) {
        $categoryMaps[(string) ($cat['tournament_category_id'] ?: $cat['category_code'])] = [
            'category_id' => (int) ($cat['tournament_category_id'] ?? 0),
            'category_code' => (string) ($cat['category_code'] ?? 'open'),
            'group_size' => (int) ($cat['group_size'] ?? 0),
            'advance' => (int) ($cat['teams_advance_per_group'] ?? 1),
        ];
    }

    $pdo->beginTransaction();
    try {
        $groupLetters = range('A', 'Z');
        $categoryBuckets = [];
        foreach ($teamRows as $teamRow) {
            $bucketKey = (string) ($teamRow['tournament_category_id'] ?: ($teamRow['category'] ?: 'open'));
            $categoryBuckets[$bucketKey]['category_id'] = $teamRow['tournament_category_id'] ?: null;
            $categoryBuckets[$bucketKey]['category_code'] = $teamRow['category'] ?: 'open';
            $categoryBuckets[$bucketKey]['group_size'] = (int) (($categoryMaps[$bucketKey]['group_size'] ?? 0) ?: 0);
            $categoryBuckets[$bucketKey]['advance'] = (int) ($categoryMaps[$bucketKey]['advance'] ?? 1);
            $categoryBuckets[$bucketKey]['participant_ids'][] = (int) $teamRow['competitor_id'];
        }

        $createdGroups = 0;
        foreach ($categoryBuckets as $categoryBucket) {
            $groupSize = (int) ($categoryBucket['group_size'] ?? 0);
            $groupCount = $groupSize > 1
                ? max(1, (int) ceil(count($categoryBucket['participant_ids']) / $groupSize))
                : 1;
            $groups = splitIntoGroups($categoryBucket['participant_ids'], $groupCount);
            foreach ($groups as $i => $groupTeamIds) {
                $categoryLabel = strtoupper($categoryBucket['category_code']);
                $groupName = $categoryLabel . ' ' . ($groupCount > 1 ? "Group {$groupLetters[$i]}" : 'Group A');

                $insertGroup = $pdo->prepare("INSERT INTO tournament_groups (tournament_id, tournament_category_id, name, stage_type) VALUES (:tid, :category_id, :name, 'group')");
                $insertGroup->execute(['tid' => $tournamentId, 'category_id' => $categoryBucket['category_id'], 'name' => $groupName]);
                $groupId = $pdo->lastInsertId();
                $createdGroups++;

                if (($tournament['scoring_mode'] ?? '') === 'points') {
                    foreach ($groupTeamIds as $teamId) {
                        $pdo->prepare('INSERT INTO group_participants (group_id, participant_id) VALUES (:group_id, :participant_id)')
                            ->execute(['group_id' => $groupId, 'participant_id' => $teamId]);
                    }
                    for ($round = 1; $round <= max(1, (int) $tournament['points_rounds']); $round++) {
                        $pdo->prepare("INSERT INTO matches (tournament_id, tournament_category_id, group_id, result_type, best_of, round_number, match_index, status)
                            VALUES (:tid, :category_id, :group_id, 'points_round', 1, :round_number, 0, 'scheduled')")
                            ->execute([
                                'tid' => $tournamentId,
                                'category_id' => $categoryBucket['category_id'],
                                'group_id' => $groupId,
                                'round_number' => $round,
                            ]);
                    }
                } else {
                    if (($tournament['play_mode'] ?? 'team') !== 'solo') {
                        foreach ($groupTeamIds as $teamId) {
                            $pdo->prepare("INSERT INTO group_teams (group_id, team_id) VALUES (:gid, :team_id)")
                                ->execute(['gid' => $groupId, 'team_id' => $teamId]);
                        }
                    }

                    $rounds = circleMethodSchedule($groupTeamIds);
                    foreach ($rounds as $roundNumber => $pairs) {
                        foreach ($pairs as $index => $pair) {
                            [$team1, $team2] = $pair;
                            $insert = $pdo->prepare("INSERT INTO matches (tournament_id, tournament_category_id, group_id, best_of, round_number, match_index, team1_id, team2_id, status)
                                VALUES (:tid, :category_id, :gid, :best_of, :round, :idx, :team1, :team2, 'scheduled')");
                            $insert->execute([
                                'tid' => $tournamentId,
                                'category_id' => $categoryBucket['category_id'],
                                'gid' => $groupId,
                                'best_of' => $groupBestOf,
                                'round' => $roundNumber + 1,
                                'idx' => $index,
                                'team1' => $team1,
                                'team2' => $team2,
                            ]);
                        }
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $createdGroups;
}

function generateGroupPlayoff(PDO $pdo, int $tournamentId): int
{
    ensureTournamentCategorySchema($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $lockTournament = $pdo->prepare('SELECT tournament_id FROM tournaments WHERE tournament_id = :tournament_id FOR UPDATE');
        $lockTournament->execute(['tournament_id' => $tournamentId]);
        if (!$lockTournament->fetchColumn()) {
            throw new RuntimeException('ไม่พบ Tournament สำหรับสร้างสาย Playoff');
        }
        $rounds = generateGroupPlayoffMatches($pdo, $tournamentId);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $rounds;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function generateGroupPlayoffMatches(PDO $pdo, int $tournamentId): int
{
    $playModeStmt = $pdo->prepare('SELECT g.play_mode FROM tournaments t JOIN games g ON g.game_id = t.game_id WHERE t.tournament_id = :tournament_id');
    $playModeStmt->execute(['tournament_id' => $tournamentId]);
    $playMode = $playModeStmt->fetchColumn();

    $groupStmt = $pdo->prepare("SELECT tg.tournament_group_id, tg.tournament_category_id, tg.name
        FROM tournament_groups tg
        WHERE tg.tournament_id = :tournament_id AND tg.stage_type = 'group'
        ORDER BY tg.tournament_category_id, tg.tournament_group_id
        FOR UPDATE");
    $groupStmt->execute(['tournament_id' => $tournamentId]);
    $tournamentStmt = $pdo->prepare('SELECT game_id, best_of, group_best_of, playoff_best_of, top8_best_of, scoring_mode, points_rounds, points_advance FROM tournaments WHERE tournament_id = :tournament_id');
    $tournamentStmt->execute(['tournament_id' => $tournamentId]);
    $tournament = $tournamentStmt->fetch();
    if (!$tournament) {
        throw new RuntimeException('ไม่พบ Tournament สำหรับสร้างสาย Playoff');
    }

    $groupsByCategory = [];
    foreach ($groupStmt->fetchAll(PDO::FETCH_ASSOC) as $group) {
        $categoryId = (int) ($group['tournament_category_id'] ?? 0);
        $groupsByCategory[$categoryId][] = $group;
    }

    $rounds = 0;
    if (($tournament['scoring_mode'] ?? '') === 'points') {
        $pending = $pdo->prepare("SELECT COUNT(*) FROM matches m
            JOIN tournament_groups tg ON tg.tournament_group_id = m.group_id
            WHERE tg.tournament_id = :tournament_id AND tg.stage_type = 'group'
              AND m.status NOT IN ('completed', 'walkover', 'cancelled')");
        $pending->execute(['tournament_id' => $tournamentId]);
        if ((int) $pending->fetchColumn() > 0) return 0;

        foreach ($groupsByCategory as $categoryId => $groups) {
            $advanceStmt = $pdo->prepare('SELECT COALESCE(teams_advance_per_group, 1)
                FROM tournament_categories WHERE tournament_category_id = :category_id');
            $advanceStmt->execute(['category_id' => $categoryId]);
            $advanceCount = max(1, (int) ($advanceStmt->fetchColumn() ?: $tournament['points_advance'] ?: 1));
            $finalExists = $pdo->prepare("SELECT tournament_group_id FROM tournament_groups
                WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id AND stage_type = 'final'
                LIMIT 1 FOR UPDATE");
            $finalExists->execute(['tournament_id' => $tournamentId, 'category_id' => $categoryId]);
            if ($finalExists->fetchColumn() !== false) continue;

            $qualifiers = [];
            foreach ($groups as $group) {
                $qualifiers = array_merge($qualifiers, getGroupQualifiers($pdo, (int) $group['tournament_group_id'], false, $advanceCount));
            }
            if (!$qualifiers) continue;

            $categoryNameStmt = $pdo->prepare('SELECT COALESCE(category_code, :fallback)
                FROM tournament_categories WHERE tournament_category_id = :category_id');
            $categoryNameStmt->execute(['fallback' => 'open', 'category_id' => $categoryId]);
            $finalCategoryCode = trim((string) ($categoryNameStmt->fetchColumn() ?: 'open'));
            $finalName = strtoupper($finalCategoryCode) . ' Final';
            $insertFinal = $pdo->prepare("INSERT INTO tournament_groups (tournament_id, tournament_category_id, name, stage_type)
                VALUES (:tournament_id, :category_id, :name, 'final')");
            $insertFinal->execute(['tournament_id' => $tournamentId, 'category_id' => $categoryId ?: null, 'name' => $finalName]);
            $finalGroupId = (int) $pdo->lastInsertId();
            $insertParticipant = $pdo->prepare('INSERT INTO group_participants (group_id, participant_id) VALUES (:group_id, :participant_id)');
            foreach ($qualifiers as $participantId) {
                $insertParticipant->execute(['group_id' => $finalGroupId, 'participant_id' => $participantId]);
            }
            for ($round = 1; $round <= max(1, (int) $tournament['points_rounds']); $round++) {
                $pdo->prepare("INSERT INTO matches (tournament_id, tournament_category_id, group_id, result_type, best_of, round_number, match_index, status)
                    VALUES (:tournament_id, :category_id, :group_id, 'points_round', 1, :round_number, 0, 'scheduled')")
                    ->execute([
                        'tournament_id' => $tournamentId,
                        'category_id' => $categoryId ?: null,
                        'group_id' => $finalGroupId,
                        'round_number' => $round,
                    ]);
            }
            $rounds = max($rounds, (int) $tournament['points_rounds']);
        }
        return $rounds;
    }

    foreach ($groupsByCategory as $categoryId => $groups) {
        $advanceStmt = $pdo->prepare('SELECT COALESCE(teams_advance_per_group, 1)
            FROM tournament_categories WHERE tournament_category_id = :category_id');
        $advanceStmt->execute(['category_id' => $categoryId]);
        $advanceCount = max(1, (int) ($advanceStmt->fetchColumn() ?: 1));
        $qualifierCount = count($groups) * $advanceCount;
        if ($qualifierCount < 2) {
            continue;
        }

        $categoryMatchesStmt = $pdo->prepare('SELECT match_id FROM matches
            WHERE tournament_id = :tournament_id AND group_id IS NULL
              AND COALESCE(tournament_category_id, 0) = :category_id
            LIMIT 1 FOR UPDATE');
        $categoryMatchesStmt->execute(['tournament_id' => $tournamentId, 'category_id' => $categoryId]);
        if ($categoryMatchesStmt->fetchColumn() === false) {
            $bestOfConfig = [
                'best_of' => max(1, (int) $tournament['best_of']),
                'group_best_of' => $tournament['group_best_of'] !== null ? (int) $tournament['group_best_of'] : null,
                'playoff_best_of' => $tournament['playoff_best_of'] !== null ? (int) $tournament['playoff_best_of'] : null,
                'top8_best_of' => $tournament['top8_best_of'] !== null ? (int) $tournament['top8_best_of'] : null,
                'scoring_mode' => $tournament['scoring_mode'],
            ];
            $rounds = max($rounds, generateEliminationForCategory(
                $pdo,
                $tournamentId,
                array_fill(0, $qualifierCount, null),
                resolveTournamentStageBestOf($bestOfConfig, 'playoff', 1, 1),
                'single',
                $categoryId ?: null,
                false,
                false,
                $bestOfConfig,
                'playoff'
            ));
        }

        $roundCountStmt = $pdo->prepare('SELECT COALESCE(MAX(round_number), 0) FROM matches
            WHERE tournament_id = :tournament_id AND group_id IS NULL
              AND COALESCE(tournament_category_id, 0) = :category_id
              AND bracket_type = \'single\'');
        $roundCountStmt->execute(['tournament_id' => $tournamentId, 'category_id' => $categoryId]);
        $rounds = max($rounds, (int) $roundCountStmt->fetchColumn());

        $bracketSize = nextPowerOfTwo($qualifierCount);
        $seedOrder = buildSeedOrder($bracketSize);
        $seedSlots = [];
        $seedOpponents = [];
        $firstRoundStmt = $pdo->prepare('SELECT match_id, match_index FROM matches
            WHERE tournament_id = :tournament_id AND group_id IS NULL
              AND COALESCE(tournament_category_id, 0) = :category_id
              AND bracket_type = \'single\' AND round_number = 1
            ORDER BY match_index FOR UPDATE');
        $firstRoundStmt->execute(['tournament_id' => $tournamentId, 'category_id' => $categoryId]);
        foreach ($firstRoundStmt->fetchAll(PDO::FETCH_ASSOC) as $match) {
            $matchIndex = (int) $match['match_index'];
            $team1Seed = $seedOrder[$matchIndex * 2];
            $team2Seed = $seedOrder[$matchIndex * 2 + 1];
            $seedSlots[$team1Seed] = ['match_id' => (int) $match['match_id'], 'slot' => 'team1'];
            $seedSlots[$team2Seed] = ['match_id' => (int) $match['match_id'], 'slot' => 'team2'];
            $seedOpponents[$team1Seed] = $team2Seed;
            $seedOpponents[$team2Seed] = $team1Seed;
        }

        foreach ($groups as $groupIndex => $group) {
            $groupId = (int) $group['tournament_group_id'];
            $pendingStmt = $pdo->prepare("SELECT match_id FROM matches
                WHERE group_id = :group_id
                  AND status NOT IN ('completed', 'walkover', 'cancelled')
                LIMIT 1 FOR UPDATE");
            $pendingStmt->execute(['group_id' => $groupId]);
            if ($pendingStmt->fetchColumn() !== false) {
                continue;
            }

            $qualifiers = getGroupQualifiers($pdo, $groupId, $playMode === 'solo', $advanceCount);
            foreach ($qualifiers as $rank => $teamId) {
                $seed = ($groupIndex * $advanceCount) + $rank + 1;
                $target = $seedSlots[$seed] ?? null;
                if (!$target) {
                    throw new RuntimeException('ไม่พบช่องของผู้ผ่านเข้ารอบในสาย Playoff');
                }

                $slotColumn = $target['slot'] === 'team1' ? 'team1_id' : 'team2_id';
                $targetStmt = $pdo->prepare("SELECT {$slotColumn} FROM matches WHERE match_id = :match_id FOR UPDATE");
                $targetStmt->execute(['match_id' => $target['match_id']]);
                $existingTeamId = $targetStmt->fetchColumn();
                if ($existingTeamId === false) {
                    throw new RuntimeException('ไม่พบ Match ปลายทางในสาย Playoff');
                }
                if ($existingTeamId !== null && (int) $existingTeamId !== $teamId) {
                    throw new RuntimeException('ช่องผู้ผ่านเข้ารอบมีทีมอื่นอยู่แล้ว');
                }
                if ($existingTeamId === null) {
                    $pdo->prepare("UPDATE matches SET {$slotColumn} = :team_id WHERE match_id = :match_id")
                        ->execute(['team_id' => $teamId, 'match_id' => $target['match_id']]);
                }

                $opponentSeed = $seedOpponents[$seed] ?? 0;
                if ($opponentSeed > $qualifierCount) {
                    resolveByeIfNeeded($pdo, $target['match_id']);
                }
            }
        }
    }

    return $rounds;
}

function getGroupQualifiers(PDO $pdo, int $groupId, bool $solo, int $advanceCount): array
{
    $pointsStmt = $pdo->prepare("SELECT t.scoring_mode, g.name AS game_name
        FROM tournament_groups tg
        JOIN tournaments t ON t.tournament_id = tg.tournament_id
        JOIN games g ON g.game_id = t.game_id
        WHERE tg.tournament_group_id = :group_id");
    $pointsStmt->execute(['group_id' => $groupId]);
    $pointsTournament = $pointsStmt->fetch(PDO::FETCH_ASSOC);
    if (($pointsTournament['scoring_mode'] ?? '') === 'points') {
        $gameName = strtolower((string) ($pointsTournament['game_name'] ?? ''));
        $tieBreak = str_contains($gameName, 'free fire') ? 'booyahs DESC, kills DESC, last_place ASC,' : 'place_total ASC,';
        $pointsStandingStmt = $pdo->prepare("SELECT gp.participant_id,
                COALESCE(SUM(pr.points), 0) AS points,
                COALESCE(SUM(pr.kills), 0) AS kills,
                SUM(CASE WHEN pr.placement = 1 THEN 1 ELSE 0 END) AS booyahs,
                COALESCE(SUM(pr.placement), 0) AS place_total,
                COALESCE(MAX(CASE WHEN pr.round_number = (
                    SELECT MAX(pr2.round_number) FROM points_round_results pr2 WHERE pr2.group_id = gp.group_id
                ) THEN pr.placement END), 255) AS last_place
            FROM group_participants gp
            LEFT JOIN points_round_results pr ON pr.group_id = gp.group_id AND pr.participant_id = gp.participant_id
            WHERE gp.group_id = :group_id
            GROUP BY gp.participant_id
            ORDER BY points DESC, {$tieBreak} gp.participant_id ASC
            LIMIT " . max(1, (int) $advanceCount));
        $pointsStandingStmt->execute(['group_id' => $groupId]);
        return array_map('intval', $pointsStandingStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($solo) {
        $standingStmt = $pdo->prepare('SELECT participant_id
            FROM (
                SELECT participant_id, SUM(points) AS points, SUM(score_diff) AS score_diff,
                       SUM(wins) AS wins, SUM(draws) AS draws, SUM(losses) AS losses
                FROM (
                    SELECT m.team1_id AS participant_id,
                           CASE WHEN m.winner_team_id = m.team1_id THEN 3
                                WHEN m.winner_team_id IS NULL AND m.team1_score = m.team2_score THEN 1
                                ELSE 0 END AS points,
                           COALESCE(m.team1_score, 0) - COALESCE(m.team2_score, 0) AS score_diff,
                           CASE WHEN m.winner_team_id = m.team1_id THEN 1 ELSE 0 END AS wins,
                           CASE WHEN m.winner_team_id IS NULL AND m.team1_score = m.team2_score THEN 1 ELSE 0 END AS draws,
                           CASE WHEN m.winner_team_id IS NOT NULL AND m.winner_team_id <> m.team1_id THEN 1 ELSE 0 END AS losses
                    FROM matches m
                    WHERE m.group_id = :team1_group_id AND m.status IN ("completed", "walkover")
                    UNION ALL
                    SELECT m.team2_id AS participant_id,
                           CASE WHEN m.winner_team_id = m.team2_id THEN 3
                                WHEN m.winner_team_id IS NULL AND m.team1_score = m.team2_score THEN 1
                                ELSE 0 END,
                           COALESCE(m.team2_score, 0) - COALESCE(m.team1_score, 0),
                           CASE WHEN m.winner_team_id = m.team2_id THEN 1 ELSE 0 END,
                           CASE WHEN m.winner_team_id IS NULL AND m.team1_score = m.team2_score THEN 1 ELSE 0 END,
                           CASE WHEN m.winner_team_id IS NOT NULL AND m.winner_team_id <> m.team2_id THEN 1 ELSE 0 END
                    FROM matches m
                    WHERE m.group_id = :team2_group_id AND m.status IN ("completed", "walkover")
                ) results
                WHERE participant_id IS NOT NULL
                GROUP BY participant_id
            ) ranked
            ORDER BY points DESC, score_diff DESC, wins DESC, draws DESC, losses ASC, participant_id ASC
            LIMIT ' . $advanceCount);
    } else {
        $standingStmt = $pdo->prepare('SELECT team_id
            FROM (
                SELECT gt.team_id,
                       MAX(gt.points) AS points,
                       MAX(gt.score_diff) AS score_diff,
                       MAX(gt.wins) AS wins,
                       MAX(gt.draws) AS draws,
                       MIN(gt.losses) AS losses
                FROM group_teams gt
                WHERE gt.group_id = :group_id
                GROUP BY gt.team_id
            ) ranked
            ORDER BY points DESC, score_diff DESC, wins DESC, draws DESC, losses ASC, team_id ASC
            LIMIT ' . $advanceCount);
    }
    if ($solo) {
        $standingStmt->execute(['team1_group_id' => $groupId, 'team2_group_id' => $groupId]);
    } else {
        $standingStmt->execute(['group_id' => $groupId]);
    }
    return array_map('intval', $standingStmt->fetchAll(PDO::FETCH_COLUMN));
}

// แบ่งทีมออกเป็น N กลุ่มให้จำนวนใกล้เคียงกันที่สุด (วนแจกทีละคนแบบ round-robin การแบ่ง)
function splitIntoGroups($teamIds, $groupCount)
{
    $groups = array_fill(0, $groupCount, []);
    foreach ($teamIds as $i => $teamId) {
        $groups[$i % $groupCount][] = $teamId;
    }
    // ตัดกลุ่มที่ไม่มีทีมพอ (เผื่อทีมน้อยกว่าจำนวนกลุ่มที่ตั้งไว้)
    return array_values(array_filter($groups, function ($g) {
        return count($g) >= 2;
    }));
}

// อัลกอริทึม circle method — วิธีมาตรฐานจัดให้ทุกทีมในกลุ่มพบกันครบทุกคู่โดยไม่ซ้ำ
// และแบ่งเป็น "รอบ" (round) ที่แต่ละทีมแข่งแค่นัดเดียวต่อรอบ
// หลักการ: ตรึงทีมแรกไว้ ที่เหลือหมุนตำแหน่งไปเรื่อยๆ ทีละรอบ
// ถ้าจำนวนทีมเป็นเลขคี่ ให้เติมทีม "bye" (null) เข้าไป ทีมที่จับคู่กับ bye จะไม่มีแข่งในรอบนั้น
function circleMethodSchedule($teamIds)
{
    $teams = $teamIds;
    if (count($teams) % 2 != 0) {
        $teams[] = null; // เติม bye ให้ครบคู่
    }

    $n = count($teams);
    $totalRounds = $n - 1;
    $rounds = [];

    for ($r = 0; $r < $totalRounds; $r++) {
        $pairs = [];
        for ($i = 0; $i < $n / 2; $i++) {
            $a = $teams[$i];
            $b = $teams[$n - 1 - $i];
            if ($a !== null && $b !== null) {
                $pairs[] = [$a, $b];
            }
        }
        $rounds[] = $pairs;

        // หมุนตำแหน่ง: ตรึงตัวแรกไว้ ตัวสุดท้ายเลื่อนมาอยู่ตำแหน่งที่ 2 ที่เหลือเลื่อนขวาไปหนึ่งช่อง
        $fixed = $teams[0];
        $last = array_pop($teams);
        array_shift($teams);
        array_unshift($teams, $last);
        array_unshift($teams, $fixed);
    }

    return $rounds;
}
