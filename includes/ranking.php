<?php
// includes/ranking.php
// ไฟล์นี้เก็บฟังก์ชันคำนวณคะแนนสะสมของทีมและผู้เล่น แยกตามเกมและประเภทการแข่งขัน (category)
// กติกาการให้คะแนน: ชนะ = 3, เสมอ = 1, แพ้ = 0

define('POINTS_WIN', 3);
define('POINTS_DRAW', 1);
define('POINTS_LOSS', 0);

// ฟังก์ชันหลัก เรียกหลังจากบันทึกผลแมตช์เสร็จ (รองรับทีมสโมสรและผู้เล่นเดี่ยว Solo พร้อมแยก category)
function updateRankingsAfterMatch($pdo, $matchId)
{
    $stmt = $pdo->prepare("
        SELECT m.tournament_id, m.team1_id, m.team2_id, m.team1_score, m.team2_score, m.winner_team_id, m.status, t.game_id
        FROM matches m
        JOIN tournaments t ON t.tournament_id = m.tournament_id
        WHERE m.match_id = :match_id
    ");
    $stmt->execute(['match_id' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        throw new Exception("ไม่พบแมตช์นี้");
    }
    if ($match['status'] != 'completed' && $match['status'] != 'walkover') {
        throw new Exception("แมตช์ยังไม่จบ ยังคำนวณคะแนนไม่ได้");
    }

    $gameId = $match['game_id'];
    $tournamentId = $match['tournament_id'];
    $isDraw = empty($match['winner_team_id']) && $match['team1_score'] !== null;

    if (empty($match['winner_team_id']) && !$isDraw) {
        throw new Exception("แมตช์นี้ยังไม่มีผู้ชนะหรือผลเสมอ");
    }

    // ฟังก์ชันค้นหา category ที่ทีมหรือผู้เล่นใช้สมัครในทัวร์นาเมนต์นี้
    $getCategoryForTeam = function($teamId) use ($pdo, $tournamentId) {
        if (empty($teamId)) return 'open';
        $stmt = $pdo->prepare("SELECT category FROM tournament_registrations WHERE tournament_id = :tid AND team_id = :team_id LIMIT 1");
        $stmt->execute(['tid' => $tournamentId, 'team_id' => $teamId]);
        $cat = $stmt->fetchColumn();
        if ($cat) return strtolower(trim($cat));
        
        $stmt2 = $pdo->prepare("SELECT team_category FROM teams WHERE team_id = :team_id LIMIT 1");
        $stmt2->execute(['team_id' => $teamId]);
        $cat2 = $stmt2->fetchColumn();
        return $cat2 ? strtolower(trim($cat2)) : 'open';
    };

    $getCategoryForPlayer = function($playerId) use ($pdo, $tournamentId) {
        if (empty($playerId)) return 'open';
        $stmt = $pdo->prepare("SELECT category FROM tournament_registrations WHERE tournament_id = :tid AND player_id = :player_id LIMIT 1");
        $stmt->execute(['tid' => $tournamentId, 'player_id' => $playerId]);
        $cat = $stmt->fetchColumn();
        if ($cat) return strtolower(trim($cat));
        
        $stmt2 = $pdo->prepare("SELECT category FROM players WHERE player_id = :player_id LIMIT 1");
        $stmt2->execute(['player_id' => $playerId]);
        $cat2 = $stmt2->fetchColumn();
        return $cat2 ? strtolower(trim($cat2)) : 'open';
    };

    // ฟังก์ชันตรวจสอบว่า ID นี้เป็นทีมสโมสรจริงหรือไม่
    $isTeam = function($id) use ($pdo) {
        if (empty($id)) return false;
        $chk = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE team_id = :id");
        $chk->execute(['id' => $id]);
        return $chk->fetchColumn() > 0;
    };

    $t1IsTeam = $isTeam($match['team1_id']);
    $t2IsTeam = $isTeam($match['team2_id']);

    if ($isDraw) {
        $cat1 = $t1IsTeam ? $getCategoryForTeam($match['team1_id']) : $getCategoryForPlayer($match['team1_id']);
        $cat2 = $t2IsTeam ? $getCategoryForTeam($match['team2_id']) : $getCategoryForPlayer($match['team2_id']);

        if ($t1IsTeam) bumpTeamRanking($pdo, $gameId, $match['team1_id'], $cat1, 'draw');
        else bumpSinglePlayerRanking($pdo, $gameId, $match['team1_id'], $cat1, 'draw');

        if ($t2IsTeam) bumpTeamRanking($pdo, $gameId, $match['team2_id'], $cat2, 'draw');
        else bumpSinglePlayerRanking($pdo, $gameId, $match['team2_id'], $cat2, 'draw');

        if ($t1IsTeam) bumpPlayerRankings($pdo, $gameId, $match['team1_id'], $cat1, 'draw');
        if ($t2IsTeam) bumpPlayerRankings($pdo, $gameId, $match['team2_id'], $cat2, 'draw');
    } else {
        $winnerId = $match['winner_team_id'];
        $loserId = ($winnerId == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];

        $winnerIsTeam = $isTeam($winnerId);
        $loserIsTeam = $isTeam($loserId);

        $winnerCat = $winnerIsTeam ? $getCategoryForTeam($winnerId) : $getCategoryForPlayer($winnerId);
        $loserCat = !empty($loserId) ? ($loserIsTeam ? $getCategoryForTeam($loserId) : $getCategoryForPlayer($loserId)) : 'open';

        if ($winnerIsTeam) {
            bumpTeamRanking($pdo, $gameId, $winnerId, $winnerCat, 'win');
            bumpPlayerRankings($pdo, $gameId, $winnerId, $winnerCat, 'win');
        } else {
            bumpSinglePlayerRanking($pdo, $gameId, $winnerId, $winnerCat, 'win');
        }

        if (!empty($loserId)) {
            if ($loserIsTeam) {
                bumpTeamRanking($pdo, $gameId, $loserId, $loserCat, 'loss');
                bumpPlayerRankings($pdo, $gameId, $loserId, $loserCat, 'loss');
            } else {
                bumpSinglePlayerRanking($pdo, $gameId, $loserId, $loserCat, 'loss');
            }
        }
    }

    // อัปเดตตารางคะแนนกลุ่ม (ถ้ามี)
    updateGroupStandingsAfterMatch($pdo, $matchId);
}

// เพิ่มคะแนนให้ทีมสโมสร (แยกตาม category)
function bumpTeamRanking($pdo, $gameId, $teamId, $category, $result)
{
    [$points, $win, $draw, $loss] = resultToStats($result);
    $category = !empty($category) ? strtolower(trim($category)) : 'open';

    $stmt = $pdo->prepare("
        INSERT INTO team_rankings (game_id, team_id, category, points, matches_played, wins, losses)
        VALUES (:game_id, :team_id, :category, :points, 1, :win, :loss)
        ON DUPLICATE KEY UPDATE
            points = points + VALUES(points),
            matches_played = matches_played + 1,
            wins = wins + VALUES(wins),
            losses = losses + VALUES(losses)
    ");
    $stmt->execute([
        'game_id' => $gameId, 'team_id' => $teamId, 'category' => $category,
        'points' => $points, 'win' => $win, 'loss' => $loss,
    ]);
}

// เพิ่มคะแนนให้ผู้เล่นเดี่ยว Solo (แยกตาม category)
function bumpSinglePlayerRanking($pdo, $gameId, $playerId, $category, $result)
{
    [$points, $win, $draw, $loss] = resultToStats($result);
    $category = !empty($category) ? strtolower(trim($category)) : 'open';

    $stmt = $pdo->prepare("
        INSERT INTO player_rankings (game_id, player_id, category, points, matches_played, wins, losses)
        VALUES (:game_id, :player_id, :category, :points, 1, :win, :loss)
        ON DUPLICATE KEY UPDATE
            points = points + VALUES(points),
            matches_played = matches_played + 1,
            wins = wins + VALUES(wins),
            losses = losses + VALUES(losses)
    ");
    $stmt->execute([
        'game_id' => $gameId, 'player_id' => $playerId, 'category' => $category,
        'points' => $points, 'win' => $win, 'loss' => $loss,
    ]);
}

// แจกคะแนนให้ผู้เล่นทุกคนในทีมสโมสร (แยกตาม category)
function bumpPlayerRankings($pdo, $gameId, $teamId, $category, $result)
{
    [$points, $win, $draw, $loss] = resultToStats($result);
    $category = !empty($category) ? strtolower(trim($category)) : 'open';

    $stmt = $pdo->prepare("
        SELECT player_id FROM team_members WHERE team_id = :team_id AND is_active = 1
    ");
    $stmt->execute(['team_id' => $teamId]);
    $players = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($players as $playerId) {
        $upsert = $pdo->prepare("
            INSERT INTO player_rankings (game_id, player_id, category, points, matches_played, wins, losses)
            VALUES (:game_id, :player_id, :category, :points, 1, :win, :loss)
            ON DUPLICATE KEY UPDATE
                points = points + VALUES(points),
                matches_played = matches_played + 1,
                wins = wins + VALUES(wins),
                losses = losses + VALUES(losses)
        ");
        $upsert->execute([
            'game_id' => $gameId, 'player_id' => $playerId, 'category' => $category,
            'points' => $points, 'win' => $win, 'loss' => $loss,
        ]);
    }
}

// แปลงผล win/draw/loss เป็น [คะแนน, ชนะ, เสมอ, แพ้]
function resultToStats($result)
{
    if ($result == 'win') return [POINTS_WIN, 1, 0, 0];
    if ($result == 'draw') return [POINTS_DRAW, 0, 1, 0];
    return [POINTS_LOSS, 0, 0, 1];
}

// ตารางคะแนนกลุ่ม (group_teams)
function updateGroupStandingsAfterMatch($pdo, $matchId)
{
    $stmt = $pdo->prepare("
        SELECT group_id, team1_id, team2_id, team1_score, team2_score, winner_team_id
        FROM matches WHERE match_id = :id
    ");
    $stmt->execute(['id' => $matchId]);
    $match = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$match || empty($match['group_id'])) {
        return;
    }

    $isDraw = ($match['team1_score'] == $match['team2_score']);
    $groupId = $match['group_id'];

    bumpGroupTeam($pdo, $groupId, $match['team1_id'], $match['team1_score'], $match['team2_score'], $isDraw);
    bumpGroupTeam($pdo, $groupId, $match['team2_id'], $match['team2_score'], $match['team1_score'], $isDraw);
}

function bumpGroupTeam($pdo, $groupId, $teamId, $ownScore, $oppScore, $isDraw)
{
    if ($isDraw) {
        $points = 1; $win = 0; $draw = 1; $loss = 0;
    } elseif ($ownScore > $oppScore) {
        $points = 3; $win = 1; $draw = 0; $loss = 0;
    } else {
        $points = 0; $win = 0; $draw = 0; $loss = 1;
    }
    $scoreDiff = $ownScore - $oppScore;

    $update = $pdo->prepare("
        UPDATE group_teams
        SET played = played + 1,
            wins = wins + :win,
            draws = draws + :draw,
            losses = losses + :loss,
            points = points + :points,
            score_diff = score_diff + :diff
        WHERE group_id = :gid AND team_id = :team_id
    ");
    $update->execute([
        'win' => $win, 'draw' => $draw, 'loss' => $loss,
        'points' => $points, 'diff' => $scoreDiff,
        'gid' => $groupId, 'team_id' => $teamId,
    ]);
}