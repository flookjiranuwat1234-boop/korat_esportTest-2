<?php
// includes/bracket.php
// ระบบจัดการสายการแข่งขันแบบ Single Elimination, Double Elimination และ Playoff พร้อมระบบแยกประเภท (ชาย, หญิง, Open) อัตโนมัติ
require_once __DIR__ . '/tournament_categories.php';

function ensureDoubleElimSchema($pdo)
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS bracket_edges (
                match_id INT NOT NULL PRIMARY KEY,
                next_match_id INT NULL,
                next_slot VARCHAR(10) NULL,
                loser_next_match_id INT NULL,
                loser_next_slot VARCHAR(10) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $tCols = $pdo->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('best_of', $tCols)) {
            $pdo->exec("ALTER TABLE tournaments ADD COLUMN best_of TINYINT NOT NULL DEFAULT 1 AFTER format");
        }

        $mCols = $pdo->query("SHOW COLUMNS FROM matches")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('bracket_type', $mCols)) {
            $pdo->exec("ALTER TABLE matches ADD COLUMN bracket_type VARCHAR(20) NOT NULL DEFAULT 'single' AFTER group_id");
        }
        if (!in_array('best_of', $mCols)) {
            $pdo->exec("ALTER TABLE matches ADD COLUMN best_of TINYINT NOT NULL DEFAULT 1 AFTER bracket_type");
        }
        if (!in_array('reset_match_id', $mCols)) {
            $pdo->exec("ALTER TABLE matches ADD COLUMN reset_match_id INT NULL AFTER bracket_type");
        }
        try {
            $pdo->exec("ALTER TABLE matches MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'scheduled'");
        } catch (Exception $e) {}

        $eCols = $pdo->query("SHOW COLUMNS FROM bracket_edges")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('loser_next_match_id', $eCols)) {
            $pdo->exec("ALTER TABLE bracket_edges ADD COLUMN loser_next_match_id INT NULL AFTER next_slot");
        }
        if (!in_array('loser_next_slot', $eCols)) {
            $pdo->exec("ALTER TABLE bracket_edges ADD COLUMN loser_next_slot VARCHAR(10) NULL AFTER loser_next_match_id");
        }

        try {
            $pdo->exec("ALTER TABLE bracket_edges MODIFY COLUMN next_match_id INT(10) UNSIGNED NULL");
            $pdo->exec("ALTER TABLE bracket_edges MODIFY COLUMN next_slot VARCHAR(10) NULL");
        } catch (Exception $e) {}

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS match_games (
                match_game_id INT AUTO_INCREMENT PRIMARY KEY,
                match_id INT NOT NULL,
                game_number TINYINT NOT NULL,
                team1_score INT NOT NULL DEFAULT 0,
                team2_score INT NOT NULL DEFAULT 0,
                winner_team_id INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_match_game (match_id, game_number),
                FOREIGN KEY (match_id) REFERENCES matches(match_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e) {}
}

function generateSingleEliminationBracket($pdo, $tournamentId)
{
    ensureDoubleElimSchema($pdo);
    ensureTournamentCategorySchema($pdo);
    backfillRegistrationCategories($pdo, $tournamentId);

    $check = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :tid AND group_id IS NULL");
    $check->execute(['tid' => $tournamentId]);
    if ($check->fetchColumn() > 0) {
        throw new Exception("ทัวร์นาเมนต์นี้สร้างสายการแข่งขันไปแล้ว");
    }

    $tStmt = $pdo->prepare("SELECT game_id, best_of FROM tournaments WHERE tournament_id = :tid");
    $tStmt->execute(['tid' => $tournamentId]);
    $tInfo = $tStmt->fetch(PDO::FETCH_ASSOC);
    $gameId = $tInfo['game_id'] ?? null;
    $bestOf = max(1, (int) ($tInfo['best_of'] ?? 1));

    $teams = getSeededTeamsWithCategory($pdo, $tournamentId, $gameId);

    if (count($teams) < 2) {
        throw new Exception("ต้องมีผู้แข่งขันที่อนุมัติหรือเช็คอินแล้วอย่างน้อย 2 ทีม");
    }

    $groupedTeams = [
        'male' => [],
        'female' => [],
        'open' => []
    ];

    foreach ($teams as $t) {
        $cat = $t['category'] ?? 'open';
        if (!isset($groupedTeams[$cat])) {
            $cat = 'open';
        }
        $groupedTeams[$cat][] = [
            'competitor_id' => $t['competitor_id'],
            'category_id' => $t['tournament_category_id'] ?? null,
        ];
    }

    $maxRounds = 1;

    foreach ($groupedTeams as $category => $categoryTeamIds) {
        if (count($categoryTeamIds) >= 2) {
            $categoryId = $categoryTeamIds[0]['category_id'] ?? null;
            $categoryIds = array_column($categoryTeamIds, 'competitor_id');
            $rounds = generateEliminationForCategory($pdo, $tournamentId, $categoryIds, $bestOf, 'single_' . $category, $categoryId);
            if ($rounds > $maxRounds) {
                $maxRounds = $rounds;
            }
        }
    }

    return $maxRounds;
}

function generateDoubleEliminationBracket($pdo, $tournamentId)
{
    ensureDoubleElimSchema($pdo);
    ensureTournamentCategorySchema($pdo);
    backfillRegistrationCategories($pdo, $tournamentId);

    $check = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :tid AND group_id IS NULL");
    $check->execute(['tid' => $tournamentId]);
    if ($check->fetchColumn() > 0) {
        throw new Exception("ทัวร์นาเมนต์นี้สร้างสายการแข่งขันไปแล้ว");
    }

    $tStmt = $pdo->prepare("SELECT game_id, best_of FROM tournaments WHERE tournament_id = :tid");
    $tStmt->execute(['tid' => $tournamentId]);
    $tInfo = $tStmt->fetch(PDO::FETCH_ASSOC);
    $gameId = $tInfo['game_id'] ?? null;
    $bestOf = max(1, (int) ($tInfo['best_of'] ?? 1));

    $teams = getSeededTeamsWithCategory($pdo, $tournamentId, $gameId);

    if (count($teams) < 2) {
        throw new Exception("ต้องมีผู้แข่งขันที่อนุมัติหรือเช็คอินแล้วอย่างน้อย 2 ทีม");
    }

    $groupedTeams = [
        'male' => [],
        'female' => [],
        'open' => []
    ];

    foreach ($teams as $t) {
        $cat = $t['category'] ?? 'open';
        if (!isset($groupedTeams[$cat])) {
            $cat = 'open';
        }
        $groupedTeams[$cat][] = [
            'competitor_id' => $t['competitor_id'],
            'category_id' => $t['tournament_category_id'] ?? null,
        ];
    }

    $maxRounds = 1;

    foreach ($groupedTeams as $category => $categoryTeamIds) {
        if (count($categoryTeamIds) >= 2) {
            $categoryId = $categoryTeamIds[0]['category_id'] ?? null;
            $categoryIds = array_column($categoryTeamIds, 'competitor_id');
            $rounds = generateDoubleEliminationForCategory($pdo, $tournamentId, $categoryIds, $bestOf, $category, $categoryId);
            if ($rounds > $maxRounds) {
                $maxRounds = $rounds;
            }
        }
    }

    return $maxRounds;
}

function backfillRegistrationCategories(PDO $pdo, int $tournamentId): void
{
    $stmt = $pdo->prepare('SELECT tournament_registration_id, category FROM tournament_registrations
        WHERE tournament_id = :tournament_id AND (tournament_category_id IS NULL OR tournament_category_id = 0)');
    $stmt->execute(['tournament_id' => $tournamentId]);
    $update = $pdo->prepare('UPDATE tournament_registrations SET tournament_category_id = :category_id
        WHERE tournament_registration_id = :registration_id');
    foreach ($stmt->fetchAll() as $registration) {
        $categoryId = getTournamentCategoryId($pdo, $tournamentId, $registration['category'] ?: 'open');
        if ($categoryId) {
            $update->execute(['category_id' => $categoryId, 'registration_id' => $registration['tournament_registration_id']]);
        }
    }
}

function getSeededTeamsWithCategory($pdo, $tournamentId, $gameId)
{
    $tStmt = $pdo->prepare("
        SELECT g.name FROM tournaments t 
        JOIN games g ON g.game_id = t.game_id 
        WHERE t.tournament_id = :tid
    ");
    $tStmt->execute(['tid' => $tournamentId]);
    $gameName = $tStmt->fetchColumn() ?: '';

    $isIndividual = (strpos($gameName, 'Tekken') !== false || strpos($gameName, 'Street Fighter') !== false || strpos($gameName, 'Efootball') !== false || strpos($gameName, 'Roblox') !== false);

    if ($isIndividual) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT tr.player_id AS competitor_id, 'open' AS category, tr.tournament_category_id,
                tr.seed_no,
                COALESCE((SELECT pr.points FROM player_rankings pr
                    WHERE pr.player_id = tr.player_id AND pr.game_id = :game_id
                    ORDER BY pr.points DESC LIMIT 1), 0) AS ranking_points
            FROM tournament_registrations tr
            WHERE tr.tournament_id = :tid 
              AND tr.player_id IS NOT NULL
              AND tr.status = 'approved' AND tr.participation_status = 'qualified_for_draw'
            ORDER BY (tr.seed_no IS NULL), tr.seed_no ASC, ranking_points DESC, RAND()
        ");
        $stmt->execute(['tid' => $tournamentId, 'game_id' => $gameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT tr.team_id AS competitor_id, tr.category AS category, tm.name AS team_name, tr.tournament_category_id,
                tr.seed_no,
                COALESCE((SELECT trank.points FROM team_rankings trank
                    WHERE trank.team_id = tr.team_id AND trank.game_id = :game_id
                    ORDER BY trank.points DESC LIMIT 1), 0) AS ranking_points
            FROM tournament_registrations tr
            JOIN teams tm ON tm.team_id = tr.team_id
            WHERE tr.tournament_id = :tid 
              AND tr.team_id IS NOT NULL
              AND tr.status = 'approved' AND tr.participation_status = 'qualified_for_draw'
            ORDER BY (tr.seed_no IS NULL), tr.seed_no ASC, ranking_points DESC, RAND()
        ");
        $stmt->execute(['tid' => $tournamentId, 'game_id' => $gameId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $cat = $row['category'];
            $tName = $row['team_name'];
            if (empty($cat)) {
                if (stripos($tName, 'หญิง') !== false) $cat = 'female';
                elseif (stripos($tName, 'ชาย') !== false) $cat = 'male';
                else $cat = 'open';
            }
            $results[] = [
                'competitor_id' => $row['competitor_id'],
                'category' => $cat,
                'tournament_category_id' => $row['tournament_category_id']
            ];
        }
        return $results;
    }
}

function getSeededTeams($pdo, $tournamentId, $gameId)
{
    $formatted = getSeededTeamsWithCategory($pdo, $tournamentId, $gameId);
    $ids = [];
    foreach ($formatted as $f) {
        $ids[] = $f['competitor_id'];
    }
    return $ids;
}

function generateDoubleEliminationForCategory(PDO $pdo, int $tournamentId, array $seededTeamIds, int $bestOf, string $category, ?int $categoryId): int
{
    $teamCount = count($seededTeamIds);
    if ($teamCount < 2) return 0;
    $bracketSize = nextPowerOfTwo($teamCount);
    $roundCount = (int) log($bracketSize, 2);
    $seedOrder = buildSeedOrder($bracketSize);
    $insert = $pdo->prepare('INSERT INTO matches
        (tournament_id, tournament_category_id, group_id, bracket_type, best_of, round_number, match_index, team1_id, team2_id, status)
        VALUES (:tournament_id, :category_id, NULL, :bracket_type, :best_of, :round_number, :match_index, :team1_id, :team2_id, \'scheduled\')');
    $winners = [];

    for ($round = 1; $round <= $roundCount; $round++) {
        $matchCount = (int) ($bracketSize / pow(2, $round));
        $winners[$round] = [];
        for ($index = 0; $index < $matchCount; $index++) {
            $team1 = null;
            $team2 = null;
            if ($round === 1) {
                $team1 = $seededTeamIds[$seedOrder[$index * 2] - 1] ?? null;
                $team2 = $seededTeamIds[$seedOrder[$index * 2 + 1] - 1] ?? null;
            }
            $insert->execute([
                'tournament_id' => $tournamentId, 'category_id' => $categoryId,
                'bracket_type' => 'double_winners_' . $category, 'best_of' => $bestOf,
                'round_number' => $round, 'match_index' => $index,
                'team1_id' => $team1, 'team2_id' => $team2,
            ]);
            $winners[$round][$index] = (int) $pdo->lastInsertId();
        }
    }
    for ($round = 1; $round < $roundCount; $round++) {
        foreach ($winners[$round] as $index => $matchId) {
            upsertWinnerEdge($pdo, $matchId, $winners[$round + 1][intdiv($index, 2)], $index % 2 === 0 ? 'team1' : 'team2');
        }
    }

    $loserRoundCount = max(1, ($roundCount * 2) - 2);
    $losers = [];
    for ($loserRound = 1; $loserRound <= $loserRoundCount; $loserRound++) {
        $matchCount = max(1, (int) ($bracketSize / pow(2, floor(($loserRound + 1) / 2) + 1)));
        $losers[$loserRound] = [];
        for ($index = 0; $index < $matchCount; $index++) {
            $insert->execute([
                'tournament_id' => $tournamentId, 'category_id' => $categoryId,
                'bracket_type' => 'double_losers_' . $category, 'best_of' => $bestOf,
                'round_number' => $loserRound, 'match_index' => $index,
                'team1_id' => null, 'team2_id' => null,
            ]);
            $losers[$loserRound][$index] = (int) $pdo->lastInsertId();
        }
        if ($loserRound > 1) {
            $previousCount = count($losers[$loserRound - 1]);
            foreach ($losers[$loserRound - 1] as $index => $matchId) {
                $nextIndex = count($losers[$loserRound]) === $previousCount ? $index : intdiv($index, 2);
                upsertWinnerEdge($pdo, $matchId, $losers[$loserRound][$nextIndex], $index % 2 === 0 ? 'team1' : 'team2');
            }
        }
    }

    for ($round = 1; $round <= $roundCount; $round++) {
        $targetRound = $round === 1 ? 1 : ($round * 2) - 2;
        if (!isset($losers[$targetRound])) continue;
        foreach ($winners[$round] as $index => $matchId) {
            $targetIndex = $round === 1 ? intdiv($index, 2) : $index;
            $targetIndex = min($targetIndex, count($losers[$targetRound]) - 1);
            upsertLoserEdge($pdo, $matchId, $losers[$targetRound][$targetIndex], $round === 1 ? ($index % 2 === 0 ? 'team1' : 'team2') : 'team2');
        }
    }

    foreach ($winners[1] as $matchId) resolveByeIfNeeded($pdo, $matchId);

    $grandFinalType = 'double_grand_final_' . $category;
    $insert->execute([
        'tournament_id' => $tournamentId, 'category_id' => $categoryId,
        'bracket_type' => $grandFinalType, 'best_of' => $bestOf,
        'round_number' => $roundCount + 1, 'match_index' => 0,
        'team1_id' => null, 'team2_id' => null,
    ]);
    $grandFinalId = (int) $pdo->lastInsertId();
    $insert->execute([
        'tournament_id' => $tournamentId, 'category_id' => $categoryId,
        'bracket_type' => 'double_grand_final_reset_' . $category, 'best_of' => $bestOf,
        'round_number' => $roundCount + 2, 'match_index' => 0,
        'team1_id' => null, 'team2_id' => null,
    ]);
    $resetId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE matches SET reset_match_id = :reset_id WHERE match_id = :grand_final_id')
        ->execute(['reset_id' => $resetId, 'grand_final_id' => $grandFinalId]);
    upsertWinnerEdge($pdo, $winners[$roundCount][0], $grandFinalId, 'team1');
    upsertWinnerEdge($pdo, $losers[$loserRoundCount][0], $grandFinalId, 'team2');
    return $roundCount + 2;
}

function generateEliminationForCategory($pdo, $tournamentId, $seededTeamIds, $bestOf, $btype, $categoryId = null)
{
    $teamCount = count($seededTeamIds);
    if ($teamCount < 2) {
        return 0;
    }

    $bracketSize = nextPowerOfTwo($teamCount);
    $totalRounds = (int) log($bracketSize, 2);
    $seedOrder = buildSeedOrder($bracketSize);

    $matchIds = [];

    $insertMatchStmt = $pdo->prepare("
        INSERT INTO matches (tournament_id, tournament_category_id, group_id, bracket_type, best_of, round_number, match_index, team1_id, team2_id, status)
        VALUES (:tid, :category_id, NULL, :btype, :bo, :round, :idx, :team1, :team2, 'scheduled')
    ");

    for ($round = 1; $round <= $totalRounds; $round++) {
        $matchCount = $bracketSize / pow(2, $round);
        $matchIds[$round] = [];

        for ($i = 0; $i < $matchCount; $i++) {
            $team1 = null;
            $team2 = null;

            if ($round == 1) {
                $seedA = $seedOrder[$i * 2];
                $seedB = $seedOrder[$i * 2 + 1];
                $team1 = isset($seededTeamIds[$seedA - 1]) ? $seededTeamIds[$seedA - 1] : null;
                $team2 = isset($seededTeamIds[$seedB - 1]) ? $seededTeamIds[$seedB - 1] : null;
            }

            $insertMatchStmt->execute([
                'tid' => $tournamentId,
                'category_id' => $categoryId,
                'btype' => $btype,
                'bo' => $bestOf,
                'round' => $round,
                'idx' => $i,
                'team1' => $team1,
                'team2' => $team2,
            ]);

            $matchIds[$round][$i] = (int) $pdo->lastInsertId();
        }
    }

    for ($round = 1; $round < $totalRounds; $round++) {
        foreach ($matchIds[$round] as $i => $matchId) {
            $nextIndex = intdiv($i, 2);
            $nextSlot = ($i % 2 == 0) ? 'team1' : 'team2';
            $nextMatchId = $matchIds[$round + 1][$nextIndex];

            upsertWinnerEdge($pdo, $matchId, $nextMatchId, $nextSlot);
        }
    }

    foreach ($matchIds[1] as $matchId) {
        resolveByeIfNeeded($pdo, $matchId);
    }

    return $totalRounds;
}

function nextPowerOfTwo($n)
{
    if ($n < 2) {
        $n = 2;
    }
    return pow(2, ceil(log($n, 2)));
}

function buildSeedOrder($size)
{
    $rounds = log($size, 2);
    $seeds = [1, 2];

    for ($r = 1; $r < $rounds; $r++) {
        $sum = pow(2, $r + 1) + 1;
        $newSeeds = [];
        foreach ($seeds as $s) {
            $newSeeds[] = $s;
            $newSeeds[] = $sum - $s;
        }
        $seeds = $newSeeds;
    }

    return $seeds;
}

function isSlotPending($pdo, $matchId, $slot)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM matches m
        JOIN bracket_edges e ON e.match_id = m.match_id
        WHERE (
            (e.next_match_id = :mid AND e.next_slot = :slot)
            OR
            (e.loser_next_match_id = :mid AND e.loser_next_slot = :slot)
        )
        AND m.status NOT IN ('completed', 'walkover', 'cancelled')
    ");
    $stmt->execute(['mid' => $matchId, 'slot' => $slot]);
    return (int) $stmt->fetchColumn() > 0;
}

function resolveByeIfNeeded($pdo, $matchId)
{
    $stmt = $pdo->prepare("SELECT match_id, tournament_id, bracket_type, team1_id, team2_id, status FROM matches WHERE match_id = :id");
    $stmt->execute(['id' => $matchId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$m || in_array($m['status'], ['completed', 'walkover', 'cancelled'])) {
        return;
    }

    $team1 = $m['team1_id'];
    $team2 = $m['team2_id'];

    $team1Pending = isSlotPending($pdo, $matchId, 'team1');
    $team2Pending = isSlotPending($pdo, $matchId, 'team2');

    if (($team1 === null && $team1Pending) || ($team2 === null && $team2Pending)) {
        return;
    }

    if ($team1 !== null && $team2 !== null) {
        return;
    }

    if ($team1 !== null || $team2 !== null) {
        $winnerId = ($team1 !== null) ? $team1 : $team2;
        $loserId = null;

        $update = $pdo->prepare("
            UPDATE matches SET winner_team_id = :winner, status = 'walkover', result_type = 'bye', wo_reason = 'ได้รับ Bye จากการจัดสาย', completed_at = NOW()
            WHERE match_id = :id
        ");
        $update->execute(['winner' => $winnerId, 'id' => $matchId]);

        advanceMatchResult($pdo, $matchId, $winnerId, $loserId);
        return;
    }

    $update = $pdo->prepare("
        UPDATE matches SET winner_team_id = NULL, status = 'walkover', result_type = 'bye', wo_reason = 'ไม่มีคู่แข่งขันในรอบนี้', completed_at = NOW()
        WHERE match_id = :id
    ");
    $update->execute(['id' => $matchId]);

    advanceMatchResult($pdo, $matchId, null, null);
}

function advanceWinner($pdo, $matchId, $winnerTeamId)
{
    advanceMatchResult($pdo, $matchId, $winnerTeamId, null);
}

function upsertWinnerEdge($pdo, $matchId, $nextMatchId, $nextSlot)
{
    $stmt = $pdo->prepare("
        INSERT INTO bracket_edges (match_id, next_match_id, next_slot)
        VALUES (:mid, :next_id, :slot)
        ON DUPLICATE KEY UPDATE 
            next_match_id = VALUES(next_match_id),
            next_slot = VALUES(next_slot)
    ");
    $stmt->execute(['mid' => $matchId, 'next_id' => $nextMatchId, 'slot' => $nextSlot]);
}

function upsertLoserEdge(PDO $pdo, int $matchId, int $nextMatchId, string $nextSlot): void
{
    $stmt = $pdo->prepare('INSERT INTO bracket_edges (match_id, loser_next_match_id, loser_next_slot)
        VALUES (:match_id, :next_match_id, :next_slot)
        ON DUPLICATE KEY UPDATE loser_next_match_id = VALUES(loser_next_match_id), loser_next_slot = VALUES(loser_next_slot)');
    $stmt->execute(['match_id' => $matchId, 'next_match_id' => $nextMatchId, 'next_slot' => $nextSlot]);
}

function advanceMatchResult($pdo, $matchId, $winnerId, $loserId = null)
{
    $stmt = $pdo->prepare("SELECT tournament_id, bracket_type, round_number, match_index, team1_id, team2_id, reset_match_id FROM matches WHERE match_id = :id");
    $stmt->execute(['id' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!isset($match['round_number'], $match['match_index'])) return;

    $bracketType = $match['bracket_type'] ?? 'single';

    if (strpos($bracketType, 'double_grand_final_') === 0
        && strpos($bracketType, 'reset') === false
        && (int) $winnerId === (int) $match['team2_id']
        && !empty($match['reset_match_id'])) {
        $pdo->prepare("UPDATE matches SET team1_id = :team1, team2_id = :team2, status = 'scheduled',
                result_type = 'normal', team1_score = NULL, team2_score = NULL, winner_team_id = NULL
            WHERE match_id = :reset_match_id")
            ->execute([
                'team1' => $match['team1_id'],
                'team2' => $match['team2_id'],
                'reset_match_id' => $match['reset_match_id'],
            ]);
    }

    $edgeStmt = $pdo->prepare("
        SELECT next_match_id, next_slot, loser_next_match_id, loser_next_slot
        FROM bracket_edges WHERE match_id = :id
    ");
    $edgeStmt->execute(['id' => $matchId]);
    $edge = $edgeStmt->fetch(PDO::FETCH_ASSOC);

    $nextMatchId = $edge['next_match_id'] ?? null;
    $nextSlot = $edge['next_slot'] ?? null;

    if (empty($nextMatchId) && (strpos($bracketType, 'single') !== false || strpos($bracketType, 'double') !== false)) {
        $nextRound = (int)$match['round_number'] + 1;
        $currentIndex = (int)$match['match_index'];
        
        $nextIndex = intdiv($currentIndex, 2);
        $nextSlot = ($currentIndex % 2 == 0) ? 'team1' : 'team2';

        $nextStmt = $pdo->prepare("
            SELECT match_id FROM matches 
            WHERE tournament_id = :tid AND round_number = :round AND match_index = :idx AND bracket_type = :btype
            LIMIT 1
        ");
        $nextStmt->execute([
            'tid' => $match['tournament_id'],
            'round' => $nextRound,
            'idx' => $nextIndex,
            'btype' => $bracketType
        ]);
        $nextMatchId = $nextStmt->fetchColumn();
    }

    if (!empty($nextMatchId)) {
        $col = ($nextSlot == 'team1') ? 'team1_id' : 'team2_id';
        if ($winnerId !== null) {
            $pdo->prepare("UPDATE matches SET {$col} = :winner WHERE match_id = :next_id")
                ->execute(['winner' => $winnerId, 'next_id' => $nextMatchId]);
        }
        resolveByeIfNeeded($pdo, $nextMatchId);
    }

    $loserNextMatchId = $edge['loser_next_match_id'] ?? null;
    $loserNextSlot = $edge['loser_next_slot'] ?? null;
    if ($loserId !== null && !empty($loserNextMatchId) && in_array($loserNextSlot, ['team1', 'team2'], true)) {
        $col = $loserNextSlot === 'team1' ? 'team1_id' : 'team2_id';
        $pdo->prepare("UPDATE matches SET {$col} = :loser WHERE match_id = :next_id")
            ->execute(['loser' => $loserId, 'next_id' => $loserNextMatchId]);
        resolveByeIfNeeded($pdo, $loserNextMatchId);
    }
}