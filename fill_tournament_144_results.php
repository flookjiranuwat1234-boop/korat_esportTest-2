<?php
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/bracket.php';
require_once __DIR__ . '/includes/ranking.php';

$tournamentId = 144;
$pdo->beginTransaction();
try {
    $completed = 0;
    $insertGame = $pdo->prepare('INSERT INTO match_games (match_id, game_number, team1_score, team2_score, winner_team_id)
        VALUES (:match_id, :game_number, :team1_score, :team2_score, :winner_team_id)');

    while (true) {
        $stmt = $pdo->prepare("SELECT match_id, team1_id, team2_id, best_of, bracket_type
            FROM matches
            WHERE tournament_id = :tournament_id AND group_id IS NULL
              AND status = 'scheduled' AND bracket_type <> 'grand_final_reset'
              AND team1_id IS NOT NULL AND team2_id IS NOT NULL
            ORDER BY CASE bracket_type WHEN 'winners' THEN 1 WHEN 'losers' THEN 2 ELSE 3 END,
                     round_number, match_index
            LIMIT 1");
        $stmt->execute(['tournament_id' => $tournamentId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$match) break;

        $winnerId = (int) $match['team1_id'];
        $loserId = (int) $match['team2_id'];
        $bestOf = max(1, (int) $match['best_of']);
        $winsNeeded = (int) ceil($bestOf / 2);

        $pdo->prepare("UPDATE matches SET team1_score = :team1_score, team2_score = 0,
                winner_team_id = :winner_id, status = 'completed',
                result_type = 'normal', completed_at = NOW()
            WHERE match_id = :match_id")->execute([
                'team1_score' => $winsNeeded,
                'winner_id' => $winnerId,
                'match_id' => (int) $match['match_id'],
            ]);

        for ($gameNumber = 1; $gameNumber <= $winsNeeded; $gameNumber++) {
            $insertGame->execute([
                'match_id' => (int) $match['match_id'],
                'game_number' => $gameNumber,
                'team1_score' => 1,
                'team2_score' => 0,
                'winner_team_id' => $winnerId,
            ]);
        }

        updateRankingsAfterMatch($pdo, (int) $match['match_id'], false);
        advanceMatchResult($pdo, (int) $match['match_id'], $winnerId, $loserId);
        $completed++;
    }

    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM matches
        WHERE tournament_id = :tournament_id AND group_id IS NULL
          AND bracket_type <> 'grand_final_reset'
          AND status NOT IN ('completed', 'walkover', 'cancelled')");
    $pendingStmt->execute(['tournament_id' => $tournamentId]);
    $pending = (int) $pendingStmt->fetchColumn();
    if ($pending > 0) {
        throw new RuntimeException("ยังมี Match ที่ไม่สามารถกรอกผลได้ {$pending} รายการ");
    }

    $pdo->prepare("UPDATE tournaments SET status = 'completed', completed_at = NOW() WHERE tournament_id = :tournament_id")
        ->execute(['tournament_id' => $tournamentId]);
    $pdo->commit();
    echo json_encode(['completed_matches' => $completed, 'pending' => $pending], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}
