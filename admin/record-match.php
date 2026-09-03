<?php
// admin/record-match.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/ranking.php';
require_once '../includes/bracket.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/tournament_workflow.php';
requireRole('admin');

$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$filterCategory = $_GET['category'] ?? 'all';
$teamSearch = trim($_GET['team_search'] ?? '');
$stageFilter = trim((string) ($_GET['stage'] ?? ''));
$groupFilter = (int) ($_GET['group_id'] ?? 0);
$roundFilter = (int) ($_GET['round'] ?? 0);
$statusFilter = trim((string) ($_GET['match_status'] ?? ''));
$error = '';
$success = '';

ensureDoubleElimSchema($pdo);
ensureTournamentCategorySchema($pdo);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'save_score') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $matchId = (int) $_POST['match_id'];

        $checkStmt = $pdo->prepare("SELECT status, tournament_id, tournament_category_id FROM matches WHERE match_id = :id");
        $checkStmt->execute(['id' => $matchId]);
        $matchOwnership = $checkStmt->fetch();
        $currentMatchStatus = $matchOwnership['status'] ?? false;

        if (!$matchOwnership || (int) $matchOwnership['tournament_id'] !== $tournamentId) {
            $error = 'Match นี้ไม่อยู่ใน Tournament ที่กำลังจัดการ';
        } elseif (!empty($_POST['category_id']) && (int) $_POST['category_id'] !== (int) ($matchOwnership['tournament_category_id'] ?? 0)) {
            $error = 'Match นี้ไม่อยู่ใน Category ที่เลือก';
        } elseif (!canRecordMatch($pdo, $tournamentId, $matchId)) {
            $error = 'Tournament หรือ Match นี้ไม่อนุญาตให้บันทึกผลในสถานะปัจจุบัน';
        } elseif ($currentMatchStatus == 'completed' || $currentMatchStatus == 'walkover') {
            $error = 'แมตช์นี้ถูกบันทึกผลการแข่งขันไปแล้ว ไม่สามารถบันทึกซ้ำได้';
        } else {
            $fmtStmt = $pdo->prepare("
                SELECT t.format, t.start_date, t.end_date, m.team1_id, m.team2_id, m.tournament_id, m.tournament_category_id, m.bracket_type, m.best_of
                FROM matches m JOIN tournaments t ON t.tournament_id = m.tournament_id
                WHERE m.match_id = :id
            ");
            $fmtStmt->execute(['id' => $matchId]);
            $matchInfo = $fmtStmt->fetch();

            $withdrawnParticipantStmt = $pdo->prepare("SELECT COUNT(*)
                FROM tournament_registrations tr
                WHERE tr.tournament_id = :tournament_id
                  AND tr.tournament_category_id = :category_id
                   AND ((tr.team_id IS NOT NULL AND tr.team_id IN (:team1_id, :team2_id))
                       OR (tr.player_id IS NOT NULL AND tr.player_id IN (:team1_id_player, :team2_id_player)))
                  AND tr.participation_status IN ('withdrawn', 'disqualified')");
            $withdrawnParticipantStmt->execute([
                'tournament_id' => $tournamentId,
                'category_id' => (int) ($matchInfo['tournament_category_id'] ?? 0),
                'team1_id' => (int) $matchInfo['team1_id'],
                'team2_id' => (int) $matchInfo['team2_id'],
                'team1_id_player' => (int) $matchInfo['team1_id'],
                'team2_id_player' => (int) $matchInfo['team2_id'],
            ]);
            if ((int) $withdrawnParticipantStmt->fetchColumn() > 0) {
                $error = 'ไม่สามารถกรอกคะแนนได้ เนื่องจากผู้เข้าแข่งขันถอนตัวหรือถูกตัดสิทธิ์แล้ว';
            }

            $bestOf = max(1, (int) ($matchInfo['best_of'] ?? 1));

            if ($error === '' && $bestOf <= 1) {
                    $score1raw = $_POST['score1'] ?? '';
                    $score2raw = $_POST['score2'] ?? '';
                    $score1 = (int) $score1raw;
                    $score2 = (int) $score2raw;

                    if ($score1raw === '' || $score2raw === '' || $score1 < 0 || $score2 < 0) {
                        $error = 'กรุณากรอกคะแนนเป็นตัวเลขตั้งแต่ 0 ขึ้นไป';
                    } elseif (empty($matchInfo['team1_id']) || empty($matchInfo['team2_id'])) {
                        $error = 'ยังไม่ทราบคู่แข่งขัน จึงยังบันทึกผลไม่ได้';
                    } elseif ($score1 == $score2) {
                    $error = 'คะแนนเสมอกันไม่ได้ รอบแพ้คัดออกต้องมีผู้ชนะ';
                } else {
                    $winnerId = ($score1 > $score2) ? $matchInfo['team1_id'] : $matchInfo['team2_id'];
                    $loserId = ($score1 > $score2) ? $matchInfo['team2_id'] : $matchInfo['team1_id'];

                    try {
                        if (!$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                        }

                        $pdo->prepare("
                            UPDATE matches
                            SET team1_score = :s1, team2_score = :s2, winner_team_id = :winner,
                                status = 'completed', completed_at = NOW()
                            WHERE match_id = :id
                        ")->execute(['s1' => $score1, 's2' => $score2, 'winner' => $winnerId, 'id' => $matchId]);
                        if (function_exists('updateRankingsAfterMatch')) {
                                    try { updateRankingsAfterMatch($pdo, $matchId, false); } catch (Exception $ex) { throw new RuntimeException('บันทึก Ranking ไม่สำเร็จ: ' . $ex->getMessage(), 0, $ex); }
                        }
                        $advanceAlreadySaved = false;
                        try {
                            if ($winnerId) {
                                advanceMatchResult($pdo, $matchId, $winnerId, $loserId);
                            }
                        } catch (Exception $e) {
                            $verify = $pdo->prepare("SELECT winner_team_id, status FROM matches WHERE match_id = :id");
                            $verify->execute(['id' => $matchId]);
                            $savedMatch = $verify->fetch(PDO::FETCH_ASSOC);
                            if (($savedMatch['winner_team_id'] ?? null) !== null && in_array(($savedMatch['status'] ?? ''), ['completed', 'walkover'], true)) {
                                $advanceAlreadySaved = true;
                            } else {
                                throw $e;
                            }
                        }

                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                        $success = 'บันทึกผลการแข่งขันเรียบร้อยแล้ว';
                        if ($advanceAlreadySaved) {
                            $success = 'บันทึกผลการแข่งขันเรียบร้อยแล้ว';
                        }
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'บันทึกผลไม่สำเร็จ: ' . $e->getMessage();
                    }
                }
            } elseif ($error === '') {
                $gameScores1 = $_POST['game_s1'] ?? [];
                $gameScores2 = $_POST['game_s2'] ?? [];

                $team1GamesWon = 0;
                $team2GamesWon = 0;
                $gamesToInsert = [];
                $gameValidationError = '';

                for ($i = 0; $i < $bestOf; $i++) {
                    $gs1raw = $gameScores1[$i] ?? '';
                    $gs2raw = $gameScores2[$i] ?? '';

                    if ($gs1raw === '' && $gs2raw === '') {
                        continue;
                    }

                    $gs1 = (int) $gs1raw;
                    $gs2 = (int) $gs2raw;

                    if ($gs1raw === '' || $gs2raw === '' || $gs1 < 0 || $gs2 < 0 || $gs1 == $gs2) {
                        $gameValidationError = "เกมที่ " . ($i + 1) . " คะแนนเสมอกันไม่ได้ ต้องมีผู้ชนะในแต่ละเกม";
                        break;
                    }

                    $gameWinnerId = ($gs1 > $gs2) ? $matchInfo['team1_id'] : $matchInfo['team2_id'];
                    if ($gs1 > $gs2) {
                        $team1GamesWon++;
                    } else {
                        $team2GamesWon++;
                    }

                    $gamesToInsert[] = [
                        'game_number' => $i + 1,
                        's1' => $gs1,
                        's2' => $gs2,
                        'winner' => $gameWinnerId,
                    ];
                }

                $winsNeeded = (int) ceil($bestOf / 2);

                if ($gameValidationError !== '') {
                    $error = $gameValidationError;
                } elseif ($team1GamesWon < $winsNeeded && $team2GamesWon < $winsNeeded) {
                    $error = "กรอกผลไม่ครบ ต้องมีทีมใดทีมหนึ่งชนะอย่างน้อย {$winsNeeded} เกม (Best of {$bestOf})";
                } else {
                    $winnerId = ($team1GamesWon > $team2GamesWon) ? $matchInfo['team1_id'] : $matchInfo['team2_id'];
                    $loserId = ($team1GamesWon > $team2GamesWon) ? $matchInfo['team2_id'] : $matchInfo['team1_id'];

                    try {
                        if (!$pdo->inTransaction()) {
                            $pdo->beginTransaction();
                        }

                        foreach ($gamesToInsert as $g) {
                            $pdo->prepare("
                                INSERT INTO match_games (match_id, game_number, team1_score, team2_score, winner_team_id)
                                VALUES (:mid, :gn, :s1, :s2, :winner)
                            ")->execute([
                                'mid' => $matchId, 'gn' => $g['game_number'],
                                's1' => $g['s1'], 's2' => $g['s2'], 'winner' => $g['winner'],
                            ]);
                        }

                        $pdo->prepare("
                            UPDATE matches
                            SET team1_score = :s1, team2_score = :s2, winner_team_id = :winner,
                                status = 'completed', completed_at = NOW()
                            WHERE match_id = :id
                        ")->execute([
                            's1' => $team1GamesWon, 's2' => $team2GamesWon,
                            'winner' => $winnerId, 'id' => $matchId,
                        ]);
                        if (function_exists('updateRankingsAfterMatch')) updateRankingsAfterMatch($pdo, $matchId);
                        $advanceAlreadySaved = false;
                        try {
                            advanceMatchResult($pdo, $matchId, $winnerId, $loserId);
                        } catch (Exception $e) {
                            $verify = $pdo->prepare("SELECT winner_team_id, status FROM matches WHERE match_id = :id");
                            $verify->execute(['id' => $matchId]);
                            $savedMatch = $verify->fetch(PDO::FETCH_ASSOC);
                            if (($savedMatch['winner_team_id'] ?? null) !== null && in_array(($savedMatch['status'] ?? ''), ['completed', 'walkover'], true)) {
                                $advanceAlreadySaved = true;
                            } else {
                                throw $e;
                            }
                        }

                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                        $success = "บันทึกผล Best of {$bestOf} เรียบร้อยแล้ว ({$team1GamesWon}-{$team2GamesWon})";
                        if ($advanceAlreadySaved) {
                            $success = "บันทึกผล Best of {$bestOf} เรียบร้อยแล้ว ({$team1GamesWon}-{$team2GamesWon})";
                        }
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'บันทึกผลไม่สำเร็จ: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_player_performance') {
   if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
       $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
   } else {
       $matchId = (int) ($_POST['match_id'] ?? 0);
       $matchStmt = $pdo->prepare('SELECT m.*, t.game_id FROM matches m JOIN tournaments t ON t.tournament_id = m.tournament_id
           WHERE m.match_id = :match_id AND m.tournament_id = :tournament_id');
       $matchStmt->execute(['match_id' => $matchId, 'tournament_id' => $tournamentId]);
       $performanceMatch = $matchStmt->fetch(PDO::FETCH_ASSOC);
       $ratings = $_POST['performance'] ?? [];
       $played = array_map('intval', $_POST['played_player_ids'] ?? []);
       $mvpId = (int) ($_POST['mvp_player_id'] ?? 0);
       $winnerTeamId = 0;
       $levels = ['outstanding' => 5, 'normal' => 3, 'participation' => 1, 'absent' => 0];
       if (!$performanceMatch || $performanceMatch['status'] !== 'completed') {
           $error = 'ต้องบันทึกผล Match ให้เสร็จก่อนบันทึกผลงานผู้เล่น';
       } elseif (!$played || !$mvpId || !in_array($mvpId, $played, true)) {
           $error = 'กรุณาเลือกผู้เล่นที่ลงแข่งและ MVP 1 คน';
       } else {
           $winnerTeamId = (int) $performanceMatch['winner_team_id'];
           $rosterStmt = $pdo->prepare('SELECT DISTINCT trm.player_id, tr.team_id, COALESCE(tr.category, \'open\') AS category
               FROM tournament_registration_members trm
               JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
               WHERE tr.tournament_id = :tournament_id AND tr.tournament_category_id = :category_id
                 AND tr.team_id IN (:team1_id, :team2_id) AND tr.status = \'approved\' AND trm.roster_status = \'active\'
                 AND FIND_IN_SET(\'player\', REPLACE(trm.member_roles, \' \', \'\')) > 0');
           $rosterStmt->execute([
               'tournament_id' => $tournamentId,
               'category_id' => (int) $performanceMatch['tournament_category_id'],
               'team1_id' => (int) $performanceMatch['team1_id'],
               'team2_id' => (int) $performanceMatch['team2_id'],
           ]);
           $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);
           $rosterMap = [];
           foreach ($roster as $member) $rosterMap[(int) $member['player_id']] = $member;
           $historyColumns = $pdo->query('SHOW COLUMNS FROM ranking_history')->fetchAll(PDO::FETCH_COLUMN);
           $hasMatchId = in_array('match_id', $historyColumns, true);
           $alreadyScored = false;
           if ($hasMatchId) {
               $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM ranking_history WHERE match_id = :match_id AND player_id IS NOT NULL');
               $duplicateStmt->execute(['match_id' => $matchId]);
               $alreadyScored = (int) $duplicateStmt->fetchColumn() > 0;
           }
           if (!$roster || array_diff($played, array_keys($rosterMap)) || !isset($rosterMap[$mvpId])) {
               $error = 'รายชื่อผู้เล่นไม่ตรงกับ Tournament Roster';
           } elseif ($alreadyScored) {
               $error = 'Match นี้ประเมินคะแนนผู้เล่นไปแล้ว';
           } else {
               try {
                   $pdo->beginTransaction();
                   $rankingStmt = $pdo->prepare('INSERT INTO player_rankings (game_id, player_id, category, points, matches_played, wins, losses)
                       VALUES (:game_id, :player_id, :category, :points, 1, :wins, :losses)
                       ON DUPLICATE KEY UPDATE points = points + VALUES(points), matches_played = matches_played + 1,
                       wins = wins + VALUES(wins), losses = losses + VALUES(losses)');
                   $historyFields = ['game_id', 'tournament_id', 'tournament_category_id', 'player_id', 'team_id', 'points'];
                   $historyValues = [':game_id', ':tournament_id', ':category_id', ':player_id', ':team_id', ':points'];
                   if ($hasMatchId) { $historyFields[] = 'match_id'; $historyValues[] = ':match_id'; }
                   if (in_array('result_code', $historyColumns, true)) { $historyFields[] = 'result_code'; $historyValues[] = ':reason'; }
                   elseif (in_array('reason', $historyColumns, true)) { $historyFields[] = 'reason'; $historyValues[] = ':reason'; }
                   if (in_array('created_by', $historyColumns, true)) { $historyFields[] = 'created_by'; $historyValues[] = ':created_by'; }
                   $historyStmt = $pdo->prepare('INSERT INTO ranking_history (' . implode(', ', $historyFields) . ') VALUES (' . implode(', ', $historyValues) . ')');
                   $winnerTeamId = (int) $performanceMatch['winner_team_id'];
                   foreach ($played as $playerId) {
                       $member = $rosterMap[$playerId];
                       $basePoints = $levels[$ratings[$playerId] ?? 'participation'] ?? 1;
                       $points = $basePoints + ($playerId === $mvpId ? 5 : 0);
                       $isWinner = (int) $member['team_id'] === $winnerTeamId;
                       $rankingStmt->execute([
                           'game_id' => (int) $performanceMatch['game_id'], 'player_id' => $playerId,
                           'category' => strtolower(trim($member['category'] ?: 'open')), 'points' => $points,
                           'wins' => $isWinner ? 1 : 0, 'losses' => $isWinner ? 0 : 1,
                       ]);
                       $historyParams = [
                           'game_id' => (int) $performanceMatch['game_id'], 'tournament_id' => $tournamentId,
                           'category_id' => (int) $performanceMatch['tournament_category_id'], 'player_id' => $playerId,
                           'team_id' => (int) $member['team_id'], 'points' => $points,
                       ];
                       if ($hasMatchId) $historyParams['match_id'] = $matchId;
                       if (in_array('result_code', $historyColumns, true) || in_array('reason', $historyColumns, true)) {
                           $historyParams['reason'] = $playerId === $mvpId ? 'mvp' : ($ratings[$playerId] ?? 'participation');
                       }
                       if (in_array('created_by', $historyColumns, true)) $historyParams['created_by'] = (int) ($_SESSION['user_id'] ?? 1);
                       $historyStmt->execute($historyParams);
                   }
                   $pdo->commit();
                   $success = 'บันทึกคะแนนผู้เล่นและ MVP เรียบร้อยแล้ว';
               } catch (Throwable $ex) {
                   if ($pdo->inTransaction()) $pdo->rollBack();
                   $error = 'บันทึกคะแนนผู้เล่นไม่สำเร็จ: ' . $ex->getMessage();
               }
           }
       }
   }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        $scheduleCheck = $pdo->prepare('SELECT tournament_id, tournament_category_id FROM matches WHERE match_id = :match_id');
        $scheduleCheck->execute(['match_id' => $matchId]);
        $scheduleMatch = $scheduleCheck->fetch(PDO::FETCH_ASSOC);
        if (!$scheduleMatch || (int) $scheduleMatch['tournament_id'] !== $tournamentId || (!empty($_POST['category_id']) && (int) $_POST['category_id'] !== (int) $scheduleMatch['tournament_category_id'])) {
            $error = 'Match นี้ไม่อยู่ใน Tournament ที่กำลังจัดการ';
        } else {
            $scheduledAt = trim((string) ($_POST['scheduled_at'] ?? ''));
            $dateCheck = $pdo->prepare('SELECT start_date, end_date FROM tournaments WHERE tournament_id = :id');
            $dateCheck->execute(['id' => $tournamentId]);
            $tournamentDates = $dateCheck->fetch(PDO::FETCH_ASSOC) ?: [];
            $scheduledSql = $scheduledAt !== '' ? str_replace('T', ' ', $scheduledAt) . ':00' : null;
            if ($scheduledAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $scheduledAt)) {
                $error = 'รูปแบบวันเวลาแข่งขันไม่ถูกต้อง';
            } elseif ($scheduledSql && (($tournamentDates['start_date'] && $scheduledSql < $tournamentDates['start_date']) || ($tournamentDates['end_date'] && $scheduledSql > $tournamentDates['end_date']))) {
                $error = 'วันเวลา Match ต้องอยู่ภายในช่วง Tournament';
            } else {
            $pdo->prepare('UPDATE matches SET scheduled_at = :scheduled_at, venue_name = :venue_name, venue_area = :venue_area
                WHERE match_id = :match_id')->execute([
                'scheduled_at' => $scheduledSql,
                'venue_name' => trim($_POST['venue_name'] ?? '') ?: null,
                'venue_area' => trim($_POST['venue_area'] ?? '') ?: null,
                'match_id' => $matchId,
            ]);
            $success = 'บันทึกวัน เวลา และสนามของ Match แล้ว';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_bracket_winners') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $syncTournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $syncCategoryId = (int) ($_POST['category_id'] ?? 0);
        if ($syncTournamentId !== $tournamentId || $syncTournamentId <= 0) {
            $error = 'Tournament ไม่ถูกต้อง';
        } else {
            try {
                $pdo->beginTransaction();
                $edgeStmt = $pdo->prepare('SELECT source.match_id AS source_match_id, source.winner_team_id,
                        source.tournament_category_id AS source_category_id, target.match_id AS target_match_id,
                        target.tournament_id AS target_tournament_id, target.tournament_category_id AS target_category_id,
                        e.next_slot, target.team1_id, target.team2_id
                    FROM bracket_edges e
                    JOIN matches source ON source.match_id = e.match_id
                    JOIN matches target ON target.match_id = e.next_match_id
                    WHERE source.tournament_id = :tournament_id AND source.status = \'completed\'
                        AND source.winner_team_id IS NOT NULL');
                $edgeStmt->execute(['tournament_id' => $syncTournamentId]);
                $synced = 0;
                $conflicts = [];
                foreach ($edgeStmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
                    if ($syncCategoryId > 0 && (int) $edge['source_category_id'] !== $syncCategoryId) continue;
                    if ((int) $edge['source_category_id'] !== (int) $edge['target_category_id'] || (int) $edge['target_tournament_id'] !== $syncTournamentId) {
                        $conflicts[] = '#' . $edge['source_match_id'] . ' → #' . $edge['target_match_id'] . ' (Tournament/Category ไม่ตรงกัน)';
                        continue;
                    }
                    $slot = $edge['next_slot'] === 'team1_id' ? 'team1_id' : ($edge['next_slot'] === 'team1' ? 'team1_id' : 'team2_id');
                    $existing = $edge[$slot];
                    if ($existing !== null && (int) $existing !== (int) $edge['winner_team_id']) {
                        $conflicts[] = '#' . $edge['source_match_id'] . ' → #' . $edge['target_match_id'] . ' (Slot มีข้อมูลขัดแย้ง)';
                        continue;
                    }
                    if ($existing === null) {
                        $pdo->prepare("UPDATE matches SET {$slot} = :winner WHERE match_id = :target_id AND tournament_id = :tournament_id")
                            ->execute(['winner' => $edge['winner_team_id'], 'target_id' => $edge['target_match_id'], 'tournament_id' => $syncTournamentId]);
                        $synced++;
                    }
                }
                $pdo->commit();
                $success = $synced > 0 ? "ซิงก์ผู้ชนะเข้าสู่รอบถัดไป {$synced} รายการแล้ว" : 'ไม่พบ Slot ที่ต้องซิงก์';
                if ($conflicts) $error = 'พบความขัดแย้ง: ' . implode(', ', $conflicts);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'ซิงก์สายการแข่งขันไม่สำเร็จ: ' . $exception->getMessage();
            }
        }
    }
}

// POST action: promote groups into next-stage knockout matches (manual trigger)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'promote_groups')) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $promoteTournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $promoteCategoryId = (int) ($_POST['category_id'] ?? 0);
        $advancePerGroup = max(1, min(4, (int) ($_POST['advance_per_group'] ?? 2)));
        if ($promoteTournamentId !== $tournamentId || $promoteTournamentId <= 0) {
            $error = 'Tournament ไม่ถูกต้อง';
        } else {
            try {
                // ดึงกลุ่มตามทัวร์นาเมนต์และ (ถ้ามี) ตาม Category
                $gSql = 'SELECT tournament_group_id, name FROM tournament_groups WHERE tournament_id = :tid';
                $gParams = ['tid' => $promoteTournamentId];
                if ($promoteCategoryId > 0) { $gSql .= ' AND tournament_category_id = :cat'; $gParams['cat'] = $promoteCategoryId; }
                $gSql .= ' ORDER BY tournament_group_id ASC';
                $gStmt = $pdo->prepare($gSql);
                $gStmt->execute($gParams);
                $groups = $gStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!$groups) {
                    $error = 'ไม่พบกลุ่มสำหรับทัวร์นาเมนต์นี้';
                } else {
                    $advancing = [];
                    foreach ($groups as $g) {
                        $gid = (int) $g['tournament_group_id'];
                        // คำนวณตารางคะแนนภายในกลุ่มโดยใช้ผลแมตช์ที่บันทึกแล้ว
                        $mStmt = $pdo->prepare("SELECT team1_id, team2_id, winner_team_id, status FROM matches WHERE tournament_id = :tid AND group_id = :gid AND status IN ('completed','walkover')");
                        $mStmt->execute(['tid' => $promoteTournamentId, 'gid' => $gid]);
                        $scores = [];
                        foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $mm) {
                            $t1 = (int) ($mm['team1_id'] ?? 0);
                            $t2 = (int) ($mm['team2_id'] ?? 0);
                            if ($t1 > 0) { if (!isset($scores[$t1])) $scores[$t1] = ['id'=>$t1,'points'=>0,'wins'=>0]; }
                            if ($t2 > 0) { if (!isset($scores[$t2])) $scores[$t2] = ['id'=>$t2,'points'=>0,'wins'=>0]; }
                            $w = (int) ($mm['winner_team_id'] ?? 0);
                            if ($w && isset($scores[$w])) { $scores[$w]['points'] += 3; $scores[$w]['wins'] += 1; }
                        }

                        // ถ้าไม่มีคะแนนเลย ให้พยายามดึงรายชื่อทีมจาก match records (รอผู้ชนะ ฯลฯ)
                        if (empty($scores)) {
                            $teamsInGroupStmt = $pdo->prepare('SELECT DISTINCT COALESCE(m.team1_id, m.team2_id) AS team_id FROM matches m WHERE m.tournament_id = :tid AND m.group_id = :gid');
                            $teamsInGroupStmt->execute(['tid'=>$promoteTournamentId,'gid'=>$gid]);
                            foreach ($teamsInGroupStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                                $tid = (int) ($r['team_id'] ?? 0);
                                if ($tid > 0) $scores[$tid] = ['id'=>$tid,'points'=>0,'wins'=>0];
                            }
                        }

                        // เรียงลำดับคะแนนและเลือก top N
                        $rows = array_values($scores);
                        usort($rows, static fn($a,$b) => [$b['points'],$b['wins'],$a['id']] <=> [$a['points'],$a['wins'],$b['id']]);
                        $picked = array_slice($rows, 0, $advancePerGroup);
                        // ถ้าจำนวนไม่พอ ให้เติมด้วย null (จะไม่สร้างคู่สำหรับที่ว่าง)
                        $advancing[] = ['group_id'=>$gid,'name'=>$g['name'],'teams'=>$picked];
                    }

                    // สร้างคู่ตามกฎ A1 vs B2 (สำหรับแต่ละ group i ให้ชนะของ i พบรองแชมป์ของ i+1)
                    $pairs = [];
                    $G = count($advancing);
                    for ($i=0;$i<$G;$i++) {
                        $winner = $advancing[$i]['teams'][0]['id'] ?? null;
                        $runner = $advancing[($i+1)%$G]['teams'][1]['id'] ?? null; // take runner-up of next group
                        if ($winner && $runner) {
                            $pairs[] = ['team1'=>$winner,'team2'=>$runner];
                        }
                    }

                    if (empty($pairs)) {
                        $error = 'ไม่พบคู่ที่สร้างได้จากข้อมูลรอบแบ่งกลุ่ม (ทีมไม่พอหรือยังไม่มีผลแข่งขัน)';
                    } else {
                        // หา round ถัดไป (สำหรับแมตช์ knockout ที่อยู่ภายนอก group)
                        $rStmt = $pdo->prepare('SELECT COALESCE(MAX(round_number),0) AS maxr FROM matches WHERE tournament_id = :tid AND group_id IS NULL');
                        $rStmt->execute(['tid'=>$promoteTournamentId]);
                        $maxRound = (int) $rStmt->fetchColumn();
                        $nextRound = $maxRound + 1;

                        try {
                            $pdo->beginTransaction();
                            $matchIndex = 0;
                            foreach ($pairs as $p) {
                                $ins = $pdo->prepare('INSERT INTO matches (tournament_id, tournament_category_id, group_id, bracket_type, round_number, match_index, team1_id, team2_id, status) VALUES (:tid,:cat,NULL,:bracket,:rnd,:idx,:t1,:t2,:st)');
                                $ins->execute([
                                    'tid'=>$promoteTournamentId,
                                    'cat'=> $promoteCategoryId > 0 ? $promoteCategoryId : null,
                                    'bracket'=>'single',
                                    'rnd'=>$nextRound,
                                    'idx'=>$matchIndex++,
                                    't1'=>$p['team1'],
                                    't2'=>$p['team2'],
                                    'st'=>'scheduled'
                                ]);
                            }
                            $pdo->commit();
                            $success = 'สร้างคู่รอบถัดไปเรียบร้อยแล้ว (' . count($pairs) . ' คู่)';
                        } catch (Throwable $ex) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $error = 'ไม่สามารถสร้างแมตช์รอบถัดไปได้: ' . $ex->getMessage();
                        }
                    }
                }
            } catch (Throwable $ex) {
                $error = 'เกิดข้อผิดพลาดขณะประมวลผล: ' . $ex->getMessage();
            }
        }
    }
}

$tournaments = $pdo->query("
    SELECT tournament_id, name, format, game_id, start_date, end_date
    FROM tournaments 
    WHERE status NOT IN ('completed', 'cancelled')
    ORDER BY name
")->fetchAll();

$matches = [];
$groupedMatches = [];
$currentFormat = '';
$availableCategories = [];
$summary = ['total' => 0, 'scheduled' => 0, 'ongoing' => 0, 'completed' => 0, 'walkover' => 0];
$bracketIssues = [];

if ($tournamentId) {
    $fStmt = $pdo->prepare("SELECT format FROM tournaments WHERE tournament_id = :id");
    $fStmt->execute(['id' => $tournamentId]);
    $currentFormat = $fStmt->fetchColumn();
    $categoryStmt = $pdo->prepare('SELECT tournament_category_id, category_code, label FROM tournament_categories WHERE tournament_id = :id AND is_active = 1 ORDER BY tournament_category_id');
    $categoryStmt->execute(['id' => $tournamentId]);
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    $categoryMap = [];
    foreach ($availableCategories as $category) $categoryMap[$category['category_code']] = (int) $category['tournament_category_id'];
    $summaryStmt = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(status = 'scheduled') AS scheduled, SUM(status = 'ongoing') AS ongoing,
        SUM(status = 'completed') AS completed, SUM(status = 'walkover') AS walkover
        FROM matches WHERE tournament_id = :id AND status <> 'cancelled'");
    $summaryStmt->execute(['id' => $tournamentId]);
    $summary = array_merge($summary, array_map('intval', $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: []));
    $issueStmt = $pdo->prepare('SELECT source.match_id AS source_match_id, source.winner_team_id,
            target.match_id AS target_match_id, e.next_slot, target.team1_id, target.team2_id
        FROM bracket_edges e
        JOIN matches source ON source.match_id = e.match_id
        LEFT JOIN matches target ON target.match_id = e.next_match_id
        WHERE source.tournament_id = :tournament_id AND source.status = \'completed\'
            AND source.winner_team_id IS NOT NULL AND e.next_match_id IS NOT NULL');
    $issueStmt->execute(['tournament_id' => $tournamentId]);
    foreach ($issueStmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
        $slot = $edge['next_slot'] === 'team1' ? 'team1_id' : 'team2_id';
        if ($edge['target_match_id'] === null || ($edge[$slot] !== null && (int) $edge[$slot] !== (int) $edge['winner_team_id'])) {
            $bracketIssues[] = $edge;
        } elseif ($edge[$slot] === null) {
            $bracketIssues[] = $edge;
        }
    }

    // ดึงข้อมูลแมตช์พร้อมผูก category จากตาราง tournament_registrations หรือ teams โดยตรงอย่างถูกต้อง
    $sql = "
        SELECT m.*, 
               COALESCE(t1.name, u1.username, 'รอคู่แข่งขัน') AS team1_name,
               COALESCE(t2.name, u2.username, 'รอคู่แข่งขัน') AS team2_name,
               COALESCE(tr1.category, t1.team_category, 'open') AS team1_cat,
               COALESCE(tr2.category, t2.team_category, 'open') AS team2_cat,
               tr1.participation_status AS team1_participation_status,
               tr2.participation_status AS team2_participation_status,
               tg.name AS group_name,
               m.tournament_category_id AS match_category_id
        FROM matches m
        JOIN tournaments t ON t.tournament_id = m.tournament_id
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN players p1 ON p1.player_id = m.team1_id
        LEFT JOIN users u1 ON u1.user_id = p1.user_id
        LEFT JOIN tournament_registrations tr1 ON tr1.tournament_id = m.tournament_id AND tr1.tournament_category_id = m.tournament_category_id AND (tr1.team_id = m.team1_id OR tr1.player_id = m.team1_id)
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        LEFT JOIN players p2 ON p2.player_id = m.team2_id
        LEFT JOIN users u2 ON u2.user_id = p2.user_id
        LEFT JOIN tournament_registrations tr2 ON tr2.tournament_id = m.tournament_id AND tr2.tournament_category_id = m.tournament_category_id AND (tr2.team_id = m.team2_id OR tr2.player_id = m.team2_id)
        LEFT JOIN tournament_groups tg ON tg.tournament_group_id = m.group_id
        WHERE m.tournament_id = :tid AND m.status != 'cancelled'
    ";
    $params = ['tid' => $tournamentId];
    $selectedCategoryId = $filterCategory !== 'all' ? getTournamentCategoryId($pdo, $tournamentId, $filterCategory) : null;

    if ($teamSearch !== '') {
        $sql .= " AND (t1.name LIKE :search OR u1.username LIKE :search OR t2.name LIKE :search OR u2.username LIKE :search)";
        $params['search'] = "%{$teamSearch}%";
    }

    if ($selectedCategoryId) {
        $sql .= ' AND m.tournament_category_id = :category_id';
        $params['category_id'] = $selectedCategoryId;
    }
    if ($groupFilter > 0) { $sql .= ' AND m.group_id = :group_id'; $params['group_id'] = $groupFilter; }
    if ($roundFilter > 0) { $sql .= ' AND m.round_number = :round_number'; $params['round_number'] = $roundFilter; }
    if ($statusFilter !== '') { $sql .= ' AND m.status = :match_status'; $params['match_status'] = $statusFilter; }

    $sql .= " ORDER BY m.group_id IS NOT NULL DESC, m.group_id ASC, m.round_number ASC, COALESCE(m.scheduled_at, '1970-01-01 00:00:00') DESC, m.match_index ASC";

    $mStmt = $pdo->prepare($sql);
    $mStmt->execute($params);
    $rawMatches = $mStmt->fetchAll();

    // จัดหมวดหมู่แมตช์ตาม Category ที่เลือก (Male, Female, Open) โดยไม่ทำให้ทีมตกหาย
    foreach ($rawMatches as $m) {
        if ($selectedCategoryId && !empty($m['match_category_id']) && (int) $m['match_category_id'] !== $selectedCategoryId) {
            continue;
        }
        $bt = strtolower($m['bracket_type'] ?? '');
        $c1 = strtolower($m['team1_cat'] ?? 'open');
        $c2 = strtolower($m['team2_cat'] ?? 'open');

        $matchCat = 'open';
        if (strpos($bt, 'male') !== false || $c1 === 'male' || $c2 === 'male') {
            $matchCat = 'male';
        } elseif (strpos($bt, 'female') !== false || $c1 === 'female' || $c2 === 'female') {
            $matchCat = 'female';
        }

        // หากตรงกับหมวดที่เลือก (หรือถ้าเลือก all ให้แสดงทั้งหมด)
        if ($filterCategory === 'all' || $matchCat === $filterCategory || ($filterCategory === 'open' && $matchCat === 'open')) {
            $matches[] = $m;
        }
    }

    foreach ($matches as $matchIndex => $match) {
        $matches[$matchIndex]['roster'] = [];
        $teamIds = array_values(array_filter([(int) ($match['team1_id'] ?? 0), (int) ($match['team2_id'] ?? 0)]));
        if ($teamIds) {
            $rosterStmt = $pdo->prepare('SELECT DISTINCT trm.player_id, p.display_name, tr.team_id, COALESCE(tr.category, \'open\') AS category,
                    COALESCE(trm.member_roles, \'player\') AS member_roles, trm.is_starter, trm.roster_status
                FROM tournament_registration_members trm
                JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
                JOIN players p ON p.player_id = trm.player_id
                WHERE tr.tournament_id = :tournament_id AND tr.tournament_category_id = :category_id
                  AND tr.team_id IN (' . implode(',', $teamIds) . ') AND tr.status = \'approved\' AND trm.roster_status = \'active\'
                  AND FIND_IN_SET(\'player\', REPLACE(trm.member_roles, \' \', \'\')) > 0
                ORDER BY tr.team_id, trm.is_starter DESC, trm.member_roles, p.display_name');
            $rosterStmt->execute([
                'tournament_id' => $tournamentId,
                'category_id' => (int) ($match['match_category_id'] ?? 0),
            ]);
            $matches[$matchIndex]['roster'] = $rosterStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // หากกรองแล้วไม่พบผลลัพธ์แต่ไม่มีการค้นหา ให้ดึงทั้งหมดมาแสดงป้องกันหน้าจอว่างเปล่า
    foreach ($matches as $m) {
        foreach (['team1' => 'team1_name', 'team2' => 'team2_name'] as $slot => $nameField) {
            if (empty($m[$slot . '_id'])) {
                $sourceStmt = $pdo->prepare('SELECT e.match_id FROM bracket_edges e JOIN matches source ON source.match_id = e.match_id WHERE e.next_match_id = :next_match_id AND e.next_slot = :next_slot AND source.tournament_id = :tournament_id LIMIT 1');
                $sourceStmt->execute(['next_match_id' => $m['match_id'], 'next_slot' => $slot, 'tournament_id' => $tournamentId]);
                $sourceMatchId = $sourceStmt->fetchColumn();
                if ($sourceMatchId) $m[$nameField] = 'รอผู้ชนะจาก Match #' . (int) $sourceMatchId;
            }
        }
        $bt = $m['bracket_type'] ?? 'single';
        $catLabel = '';
        $c1 = strtolower($m['team1_cat'] ?? 'open');
        
        if (strpos($bt, 'male') !== false || $c1 === 'male') $catLabel = ' [ประเภททีมชาย]';
        elseif (strpos($bt, 'female') !== false || $c1 === 'female') $catLabel = ' [ประเภททีมหญิง]';
        else $catLabel = ' [ประเภท Open]';

        $stageName = $m['group_id'] ? 'Group Stage' : ($m['bracket_type'] ?? 'Knockout');
        if ($stageFilter !== '' && strtolower((string) $stageName) !== strtolower($stageFilter)) continue;

        // สำหรับ Group Stage ให้แยกแสดงตามชื่อกลุ่ม (tournament_groups.name)
        if (!empty($m['group_id'])) {
            $groupLabel = 'Group: ' . ($m['group_name'] ?? ('Group ' . $m['group_id']));
            $groupKey = $groupLabel . ' | รอบที่ ' . $m['round_number'] . $catLabel;
        } else {
            $groupKey = $stageName . ' | รอบที่ ' . $m['round_number'] . $catLabel;
        }

        $groupedMatches[$groupKey][] = $m;
    }

    uksort($groupedMatches, function ($groupA, $groupB) {
        preg_match('/รอบที่\s*(\d+)/u', $groupA, $matchA);
        preg_match('/รอบที่\s*(\d+)/u', $groupB, $matchB);
        $roundA = isset($matchA[1]) ? (int) $matchA[1] : PHP_INT_MAX;
        $roundB = isset($matchB[1]) ? (int) $matchB[1] : PHP_INT_MAX;

        $groupAIsGroupStage = stripos($groupA, 'group') !== false || stripos($groupA, 'กลุ่ม') !== false;
        $groupBIsGroupStage = stripos($groupB, 'group') !== false || stripos($groupB, 'กลุ่ม') !== false;

        if ($groupAIsGroupStage !== $groupBIsGroupStage) {
            return $groupAIsGroupStage ? 1 : -1;
        }

        if ($roundA !== $roundB) {
            return $groupAIsGroupStage ? $roundA <=> $roundB : $roundB <=> $roundA;
        }

        return strcmp($groupA, $groupB);
    });

    foreach ($groupedMatches as $groupKey => $roundMatches) {
        usort($groupedMatches[$groupKey], function ($a, $b) {
            $statusPriority = ['scheduled' => 0, 'ongoing' => 1, 'walkover' => 2, 'completed' => 3];
            $priorityA = $statusPriority[$a['status']] ?? 4;
            $priorityB = $statusPriority[$b['status']] ?? 4;

            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            $timeA = trim((string) ($a['scheduled_at'] ?? '')) ?: trim((string) ($a['completed_at'] ?? '')) ?: '1970-01-01 00:00:00';
            $timeB = trim((string) ($b['scheduled_at'] ?? '')) ?: trim((string) ($b['completed_at'] ?? '')) ?: '1970-01-01 00:00:00';
            $cmp = strcmp($timeB, $timeA);
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int) ($a['match_index'] ?? 0)) <=> ((int) ($b['match_index'] ?? 0));
        });
    }
}

// ดึงรายชื่อกลุ่มของทัวร์นาเมนต์ (ใช้ในการกรองในฟอร์ม)
$groupList = [];
if ($tournamentId) {
    $gStmt = $pdo->prepare('SELECT tournament_group_id, name, tournament_category_id FROM tournament_groups WHERE tournament_id = :tid ORDER BY name ASC');
    $gStmt->execute(['tid' => $tournamentId]);
    $groupList = $gStmt->fetchAll(PDO::FETCH_ASSOC);
}

$csrfToken = generateCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: $success);
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'record-match.php'), true, 303);
    exit;
}
$flash = consumeFlashMessage();
if ($flash) {
    if ($flash['type'] === 'error') $error = $flash['message'];
    else $success = $flash['message'];
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดตารางและบันทึกผลการแข่งขัน - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            orange: '#FF5500',
                            glow: '#FF6600',
                            lightbg: '#F4F6F9',
                            sidebar: '#0F172A',
                        }
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                        display: ['Orbitron', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #F4F6F9; }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover, .nav-item.active {
            background: rgba(255, 85, 0, 0.12);
            color: #FF5500;
            border-left: 4px solid #FF5500;
        }
        .match-action-item { min-height: 2.5rem; width: 100%; display: flex; align-items: center; gap: .625rem; padding: .625rem .75rem; border-radius: .5rem; text-align: left; font-size: .75rem; font-weight: 600; color: #334155; }
        .match-action-item:hover { background: #f8fafc; }
        .match-action-menu { display: none; width: 14rem; padding: .5rem; }
        .match-action-menu.is-open { display: block; }
        .match-action-item:hover { background: #f8fafc; }
    </style>
</head>
<body class="text-slate-800 font-sans min-h-screen flex antialiased">

    <aside class="w-64 bg-brand-sidebar text-slate-300 flex flex-col fixed inset-y-0 left-0 z-50 shadow-xl">
        <div class="p-6 border-b border-slate-800 flex items-center gap-3">
            <img src="../assets/img/logo.png" alt="Korat Esport" class="h-10 w-auto filter drop-shadow" onError="this.src='https://placehold.co/80x80/0F172A/FF5500?text=KE';">
            <div>
                <h1 class="font-display font-black text-lg text-white tracking-wider">KORAT <span class="text-brand-orange">ESPORT</span></h1>
                <p class="text-[10px] tracking-widest text-slate-400 uppercase font-semibold">Admin Command Center</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-1 text-sm font-medium">
            <a href="dashboard.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                <span>หน้าหลัก (Dashboard)</span>
            </a>
            <a href="manage-tournament.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-trophy w-5 text-center"></i>
                <span>จัดการทัวร์นาเมนต์</span>
            </a>
            <a href="manage-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-people-group w-5 text-center"></i>
                <span>จัดการทีมสมัคร</span>
            </a>
            <a href="manage-members.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-users-gear w-5 text-center"></i>
                <span>จัดการสมาชิก</span>
            </a>
            <a href="manage-news.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-newspaper w-5 text-center"></i>
                <span>จัดการข่าวสาร</span>
            </a>
            <a href="manage-gallery.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-images w-5 text-center"></i>
                <span>จัดการแกลเลอรี่</span>
            </a>
            <a href="recommended-lodging.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-hotel w-5 text-center"></i>
                <span>ที่พักแนะนำ</span>
            </a>
            <a href="record-match.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-pen-to-square w-5 text-center text-brand-orange"></i>
                <span>บันทึกผลแมตช์</span>
            </a>
            <a href="checkin-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-user-check w-5 text-center"></i>
                <span>เช็คอินทีม</span>
            </a>
        </nav>

        <div class="p-4 border-t border-slate-800 bg-slate-950/50">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3 overflow-hidden">
                    <div class="w-9 h-9 rounded-full bg-brand-orange text-white flex items-center justify-center font-bold text-sm shrink-0">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div class="truncate">
                        <div class="text-sm font-bold text-white truncate">
                            <?= htmlspecialchars($currentUser['username'] ?? $currentUser['name'] ?? 'Admin User') ?>
                        </div>
                        <span class="inline-block text-[10px] font-semibold text-brand-orange bg-brand-orange/10 px-2 py-0.2 rounded uppercase">
                            <?= htmlspecialchars($currentUser['role'] ?? 'Administrator') ?>
                        </span>
                    </div>
                </div>
                <a href="../auth/logout.php" title="ออกจากระบบ" class="text-slate-400 hover:text-rose-400 transition-colors p-2 text-base">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </aside>

    <div class="flex-1 ml-64 min-h-screen flex flex-col">

        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    จัดตารางและบันทึกผลการแข่งขัน <span class="text-brand-orange">(MATCH MANAGEMENT)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">จัดวันเวลา สนาม และบันทึกผล Match แยกตาม Tournament และ Category</p>
            </div>
            
            <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
            </a>
        </header>

        <main class="p-8 space-y-6 flex-1">

            <?php if ($error): ?>
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-lg shrink-0 text-rose-500"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-circle-check text-lg shrink-0 text-emerald-500"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($tournamentId && $bracketIssues): ?>
                <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div><h2 class="text-sm font-bold"><i class="fa-solid fa-triangle-exclamation mr-2"></i>ตรวจพบ Winner ที่ยังไม่ต่อเข้าสายถัดไป</h2><p class="mt-1 text-xs">ระบบใช้ bracket_edges เดิมและจะไม่เขียนทับ Slot ที่มีข้อมูลขัดแย้ง</p></div>
                                    <div class="flex items-center gap-2">
                                        <form method="POST" class="inline-block mr-2">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="sync_bracket_winners">
                                            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                            <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                                            <button type="submit" class="rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white hover:bg-brand-glow">ซิงก์ผู้ชนะเข้าสู่รอบถัดไป</button>
                                        </form>

                                        <!-- ปุ่ม Promote top N จากกลุ่มไปสู่รอบถัดไป (manual trigger) -->
                                        <form method="POST" class="inline-block">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="promote_groups">
                                            <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                            <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                                            <input type="hidden" name="advance_per_group" value="2">
                                            <button type="submit" onclick="return confirm('ต้องการสร้างแมตช์รอบถัดไปจากผลรอบแบ่งกลุ่มหรือไม่? (จับคู่แบบ A1 vs B2)')" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-emerald-500">Promote top 2 จากทุกกลุ่ม</button>
                                        </form>
                                    </div>
                                </div>
                                <div class="mt-3 space-y-1 text-xs"><?php foreach ($bracketIssues as $issue): ?><div>Match #<?= (int) $issue['source_match_id'] ?> → Match #<?= (int) $issue['target_match_id'] ?> (<?= htmlspecialchars($issue['next_slot'] ?: 'ไม่ระบุ Slot') ?>)</div><?php endforeach; ?></div>
                            </section>
                        <?php endif; ?>

            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm space-y-4">
                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                    <div class="md:col-span-6 space-y-2">
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider">
                            <i class="fa-solid fa-trophy text-brand-orange mr-1"></i> เลือกทัวร์นาเมนต์ที่กำลังแข่ง:
                        </label>
                        <select name="tournament_id" onchange="this.form.submit()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-semibold cursor-pointer">
                            <option value="">-- กรุณาเลือกรายการแข่งขัน --</option>
                            <?php foreach ($tournaments as $t): ?>
                                <option value="<?php echo $t['tournament_id']; ?>" <?php echo ($t['tournament_id'] == $tournamentId) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['name']); ?> (<?php echo htmlspecialchars($t['format']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($tournamentId): ?>
                        <div class="md:col-span-6 space-y-2 relative">
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider">
                                <i class="fa-solid fa-magnifying-glass text-brand-orange mr-1"></i> ค้นหาชื่อทีม/ผู้เล่น:
                            </label>
                            <div class="relative">
                                <input type="text" name="team_search" id="teamSearchInput" value="<?php echo htmlspecialchars($teamSearch); ?>" placeholder="พิมพ์ชื่อเพื่อกรองข้อมูลแบบ Real-time..."
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-10 py-3 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium"
                                    autocomplete="off">
                                <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                                    <i class="fa-solid fa-filter text-xs"></i>
                                </span>
                            </div>
                        </div>
                        <input type="hidden" name="category" value="<?= htmlspecialchars($filterCategory) ?>">
                        <div class="md:col-span-3 space-y-2"><label class="block text-xs font-bold text-slate-700">Stage</label><select name="stage" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm"><option value="">ทุก Stage</option><option value="Group Stage" <?= $stageFilter === 'Group Stage' ? 'selected' : '' ?>>Group Stage</option><option value="single" <?= $stageFilter === 'single' ? 'selected' : '' ?>>Knockout</option></select></div>
                                                <div class="md:col-span-3 space-y-2"><label class="block text-xs font-bold text-slate-700">Group</label>
                                                    <select name="group_id" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm">
                                                        <option value="">-- ทุกกลุ่ม --</option>
                                                        <?php foreach ($groupList as $g): ?>
                                                            <option value="<?= (int) $g['tournament_group_id'] ?>" <?= ($groupFilter > 0 && (int) $groupFilter === (int) $g['tournament_group_id']) ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="md:col-span-3 space-y-2"><label class="block text-xs font-bold text-slate-700">Round</label><input type="number" min="1" name="round" value="<?= $roundFilter ?: '' ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm"></div>
                                                <div class="md:col-span-3 space-y-2"><label class="block text-xs font-bold text-slate-700">สถานะ Match</label><select name="match_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm"><option value="">ทุกสถานะ</option><option value="scheduled" <?= $statusFilter === 'scheduled' ? 'selected' : '' ?>>รอแข่งขัน</option><option value="ongoing" <?= $statusFilter === 'ongoing' ? 'selected' : '' ?>>กำลังแข่งขัน</option><option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>แข่งขันจบแล้ว</option><option value="walkover" <?= $statusFilter === 'walkover' ? 'selected' : '' ?>>WO</option></select></div>
                                                <div class="md:col-span-3 flex gap-2"><button type="submit" class="flex-1 rounded-xl bg-brand-orange px-4 py-3 text-sm font-bold text-white">กรอง</button><a href="?tournament_id=<?= $tournamentId ?>" class="flex-1 rounded-xl bg-slate-100 px-4 py-3 text-center text-sm font-bold text-slate-600">ล้างตัวกรอง</a></div>
                    <?php endif; ?>
                </form>

                <?php if ($tournamentId): ?>
                    <div class="grid grid-cols-2 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-5">
                        <?php foreach ([['ทั้งหมด', $summary['total'], 'bg-slate-100 text-slate-700'], ['รอแข่งขัน', $summary['scheduled'], 'bg-amber-50 text-amber-700'], ['กำลังแข่งขัน', $summary['ongoing'], 'bg-blue-50 text-blue-700'], ['แข่งขันจบแล้ว', $summary['completed'], 'bg-emerald-50 text-emerald-700'], ['WO', $summary['walkover'], 'bg-rose-50 text-rose-700']] as $summaryCard): ?>
                            <div class="rounded-xl border border-slate-200 p-3 <?= $summaryCard[2] ?>"><div class="text-[10px] font-bold uppercase tracking-wider"><?= $summaryCard[0] ?></div><div class="mt-1 text-xl font-black"><?= (int) $summaryCard[1] ?></div></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Promote panel: แยกการเลื่อนทีมจาก Group Stage ไปรอบถัดไป -->
                    <?php if (!empty($groupList)): ?>
                        <div class="mt-4 bg-white rounded-2xl border border-slate-200 p-4 shadow-sm">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-bold text-slate-800">Promote กลุ่มไปสู่รอบต่อไป</div>
                                    <div class="text-xs text-slate-500">ระบบจะเลื่อนทีมอันดับ 1-2 จากทุกกลุ่มไปปรับจับคู่แบบ A1 vs B2</div>
                                </div>
                                <form method="POST" class="inline-block">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="promote_groups">
                                    <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                                    <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                                    <input type="hidden" name="advance_per_group" value="2">
                                    <button type="submit" onclick="return confirm('ต้องการสร้างแมตช์รอบถัดไปจากผลรอบแบ่งกลุ่มหรือไม่? (จับคู่แบบ A1 vs B2)')" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-500">สร้างรอบต่อไปจาก Group</button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="flex items-center gap-2 pt-2 border-t border-slate-100">
                        <span class="text-xs font-bold text-slate-500 uppercase mr-2"><i class="fa-solid fa-layer-group text-brand-orange mr-1"></i> กรองประเภท:</span>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=all" class="px-4 py-1.5 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'all') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">ทั้งหมด</a>
                        <?php foreach ($availableCategories as $category): ?><a href="?tournament_id=<?= $tournamentId ?>&category=<?= urlencode($category['category_code']) ?>" class="px-4 py-1.5 rounded-xl text-xs font-bold <?= $filterCategory === $category['category_code'] ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>"><?= htmlspecialchars($category['label'] ?: $category['category_code']) ?></a><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($tournamentId): ?>
                <?php if (count($matches) == 0): ?>
                    <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center text-slate-400">
                        <i class="fa-solid fa-gamepad text-4xl mb-3 block opacity-40 text-brand-orange"></i>
                        ไม่พบรายการแมตช์การแข่งขันในประเภทนี้
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($groupedMatches as $roundTitle => $roundMatches): ?>
                            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                                <div class="p-4 border-b border-slate-100 bg-slate-50/80 flex items-center justify-between">
                                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-800 flex items-center gap-2">
                                        <i class="fa-solid fa-layer-group text-brand-orange"></i>
                                        <?php echo htmlspecialchars($roundTitle); ?> 
                                        <span class="text-slate-400 font-normal">(<?php echo count($roundMatches); ?> แมตช์)</span>
                                    </h2>
                                </div>

                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-sm text-slate-600">
                                        <thead class="bg-slate-100/50 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                            <tr>
                                                <th class="p-4 text-center w-16">คู่ที่</th>
                                                <th class="p-4 text-right">ผู้แข่งขัน 1</th>
                                                <th class="p-4 text-center w-16">VS</th>
                                                <th class="p-4">ผู้แข่งขัน 2</th>
                                                <th class="p-4 text-center">ผลการแข่งขัน</th>
                                                <th class="p-4 text-center">สถานะ</th>
                                                <th class="p-4 text-center">กำหนดการ</th>
                                                <th class="p-4 text-center">จัดการ</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($roundMatches as $m): ?>
                                            <?php $participantWithdrawn = in_array($m['team1_participation_status'] ?? '', ['withdrawn', 'disqualified'], true) || in_array($m['team2_participation_status'] ?? '', ['withdrawn', 'disqualified'], true); ?>
                                            <tr class="hover:bg-slate-50/80 transition-colors">
                                                <td class="p-4 text-center font-mono text-xs font-bold text-slate-400">
                                                    #<?php echo $m['match_index'] + 1; ?>
                                                </td>

                                                <td class="p-4 text-right font-bold text-slate-900">
                                                    <?php if (!empty($m['team1_id'])): ?>
                                                        <?php echo htmlspecialchars(trim($m['team1_name'])); ?>
                                                        <?php if ($m['team1_cat'] == 'female'): ?>
                                                            <span class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] bg-pink-50 text-pink-600 font-bold">หญิง</span>
                                                        <?php elseif ($m['team1_cat'] == 'male'): ?>
                                                            <span class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] bg-blue-50 text-blue-600 font-bold">ชาย</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-slate-400 italic">รอผู้ชนะรอบก่อน</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="p-4 text-center text-xs font-black text-slate-300">VS</td>

                                                <td class="p-4 font-bold text-slate-900">
                                                    <?php if (!empty($m['team2_id'])): ?>
                                                        <?php echo htmlspecialchars(trim($m['team2_name'])); ?>
                                                        <?php if ($m['team2_cat'] == 'female'): ?>
                                                            <span class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] bg-pink-50 text-pink-600 font-bold">หญิง</span>
                                                        <?php elseif ($m['team2_cat'] == 'male'): ?>
                                                            <span class="ml-1.5 px-1.5 py-0.5 rounded text-[10px] bg-blue-50 text-blue-600 font-bold">ชาย</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-slate-400 italic">รอผู้ชนะรอบก่อน</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="p-4 text-center">
                                                    <?php if ($m['status'] == 'completed' || $m['status'] == 'walkover'): ?>
                                                        <span class="font-display font-bold text-slate-900 bg-slate-100 border border-slate-200 px-4 py-1.5 rounded-lg inline-block text-sm">
                                                            <?php echo $m['team1_score']; ?> - <?php echo $m['team2_score']; ?>
                                                        </span>
                                                    <?php elseif ($m['team1_id'] && $m['team2_id'] && !$participantWithdrawn): ?>
                                                        <?php $mBestOf = max(1, (int) ($m['best_of'] ?? 1)); ?>
                                                        <?php if ($mBestOf <= 1): ?>
                                                            <form method="POST" class="hidden inline-flex items-center gap-2">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                                <input type="hidden" name="action" value="save_score">
                                                                <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">

                                                                <input type="number" name="score1" min="0" required
                                                                    class="w-14 text-center bg-slate-50 border border-slate-300 rounded-lg py-1.5 px-1 text-sm font-bold text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange" placeholder="0">
                                                                <span class="font-bold text-slate-400">-</span>
                                                                <input type="number" name="score2" min="0" required
                                                                    class="w-14 text-center bg-slate-50 border border-slate-300 rounded-lg py-1.5 px-1 text-sm font-bold text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange" placeholder="0">

                                                                <button type="submit"
                                                                    class="px-3.5 py-1.5 bg-brand-orange hover:bg-brand-glow text-white text-xs font-bold rounded-lg transition-all shadow-sm cursor-pointer ml-1">
                                                                    บันทึกผล
                                                                </button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form method="POST" class="hidden inline-flex flex-col items-center gap-1.5">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                                <input type="hidden" name="action" value="save_score">
                                                                <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">

                                                                <div class="text-[10px] font-bold text-brand-orange uppercase mb-0.5">Best of <?php echo $mBestOf; ?></div>

                                                                <?php for ($gi = 0; $gi < $mBestOf; $gi++): ?>
                                                                <div class="flex items-center gap-1.5">
                                                                    <span class="text-[10px] text-slate-400 w-10 text-right">เกม <?php echo $gi + 1; ?></span>
                                                                    <input type="number" name="game_s1[]" min="0"
                                                                        class="w-12 text-center bg-slate-50 border border-slate-300 rounded-lg py-1 px-1 text-xs font-bold text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange" placeholder="-">
                                                                    <span class="font-bold text-slate-400 text-xs">-</span>
                                                                    <input type="number" name="game_s2[]" min="0"
                                                                        class="w-12 text-center bg-slate-50 border border-slate-300 rounded-lg py-1 px-1 text-xs font-bold text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange" placeholder="-">
                                                                </div>
                                                                <?php endfor; ?>

                                                                <button type="submit"
                                                                    class="mt-1 px-3.5 py-1.5 bg-brand-orange hover:bg-brand-glow text-white text-xs font-bold rounded-lg transition-all shadow-sm cursor-pointer">
                                                                    บันทึกผล BO<?php echo $mBestOf; ?>
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    <?php elseif ($participantWithdrawn): ?>
                                                        <span class="text-xs font-bold text-rose-600">ไม่สามารถกรอกคะแนนได้: ถอนตัว/ตัดสิทธิ์</span>
                                                    <?php else: ?>
                                                        <span class="text-xs text-slate-400 italic">รอยืนยันคู่แข่งขัน</span>
                                                    <?php endif; ?>
                                                </td>

                                                <td class="p-4 text-center">
                                                    <?php if ($m['status'] == 'completed'): ?>
                                                        <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">แข่งเสร็จแล้ว</span>
                                                    <?php elseif ($m['status'] == 'walkover'): ?>
                                                        <span class="px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold">ชนะบาย</span>
                                                    <?php else: ?>
                                                        <span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 text-xs font-bold">รอแข่ง</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 text-center align-top">
                                                    <div class="text-xs font-semibold text-slate-700"><?= !empty($m['scheduled_at']) ? date('d/m/Y H:i', strtotime($m['scheduled_at'])) : (in_array($m['status'], ['completed', 'walkover'], true) ? 'ไม่ได้บันทึกกำหนดการ' : 'ยังไม่กำหนด') ?></div>
                                                    <div class="mt-1 text-[11px] text-slate-400"><?= htmlspecialchars($m['venue_area'] ?: $m['venue_name'] ?: '-') ?></div>
                                                </td>
                                                <td class="p-4 text-center align-top">
                                                    <button type="button" class="match-action-toggle inline-flex h-9 items-center gap-2 rounded-lg bg-brand-orange px-3 text-xs font-semibold text-white hover:bg-brand-glow" data-match-id="<?= (int) $m['match_id'] ?>" data-menu-id="match-action-menu-<?= (int) $m['match_id'] ?>" aria-haspopup="menu" aria-expanded="false"><i class="fa-solid fa-ellipsis"></i>จัดการ</button>
                                                    <div id="match-action-menu-<?= (int) $m['match_id'] ?>" class="match-action-menu fixed z-[70] rounded-xl border border-slate-200 bg-white text-left shadow-xl" role="menu">
                                                        <button type="button" data-match-action="detail" class="match-action-item">รายละเอียด</button>
                                                        <?php if (!in_array($m['status'], ['completed', 'walkover', 'cancelled'], true) && $m['team1_id'] && $m['team2_id'] && !$participantWithdrawn): ?><button type="button" data-match-action="score" class="match-action-item">บันทึกผลการแข่งขัน</button><?php endif; ?>
                                                        <?php if ($m['status'] === 'completed' && $m['team1_id'] && $m['team2_id'] && !empty($m['roster'])): ?><button type="button" data-match-action="performance" class="match-action-item">บันทึกผลงานผู้เล่น</button><?php endif; ?>
                                                        <?php if ($m['status'] === 'completed'): ?><button type="button" data-match-action="score" class="match-action-item">แก้ไขผลการแข่งขัน</button><?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </main>
    </div>
    <div id="matchDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 id="matchDetailTitle" class="font-bold text-slate-900">รายละเอียด Match</h3><button type="button" onclick="closeMatchModal('matchDetailModal')" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><div id="matchDetailContent" class="space-y-3 p-6 text-sm"></div><div class="flex justify-end border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" onclick="closeMatchModal('matchDetailModal')" class="rounded-lg bg-slate-200 px-4 py-2 text-xs font-bold">ปิด</button></div></div></div>
    <div id="matchScheduleModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 class="font-bold text-slate-900">จัดตารางการแข่งขัน</h3><button type="button" onclick="closeMatchModal('matchScheduleModal')" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" class="space-y-4 p-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="save_schedule"><input type="hidden" name="match_id" id="scheduleMatchId"><div id="scheduleMatchLabel" class="rounded-xl bg-slate-50 p-3 text-xs text-slate-600"></div><label class="block text-xs font-bold">วันและเวลาแข่งขัน<input type="datetime-local" name="scheduled_at" id="scheduleDate" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label><label class="block text-xs font-bold">สนาม/สถานที่<input name="venue_name" id="scheduleVenue" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label><label class="block text-xs font-bold">พื้นที่/เครื่อง/โต๊ะ<input name="venue_area" id="scheduleArea" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></label><div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onclick="closeMatchModal('matchScheduleModal')" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold">ยกเลิก</button><button type="submit" class="rounded-lg bg-brand-orange px-4 py-2 text-xs font-bold text-white">บันทึกกำหนดการ</button></div></form></div></div>
    <div id="matchScoreModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 class="font-bold text-slate-900">บันทึกผลการแข่งขัน</h3><button type="button" onclick="closeMatchModal('matchScoreModal')" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" id="matchScoreForm" class="space-y-4 p-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="save_score"><input type="hidden" name="match_id" id="scoreMatchId"><div id="scoreMatchLabel" class="rounded-xl bg-slate-50 p-3 text-xs text-slate-600"></div><div id="scoreFields"></div><div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onclick="closeMatchModal('matchScoreModal')" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold">ยกเลิก</button><button type="submit" class="rounded-lg bg-brand-orange px-4 py-2 text-xs font-bold text-white">ยืนยันผลการแข่งขัน</button></div></form></div></div>
    <div id="playerPerformanceModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 class="font-bold text-slate-900">บันทึกผลงานผู้เล่น</h3><button type="button" onclick="closeMatchModal('playerPerformanceModal')" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" id="playerPerformanceForm" class="space-y-4 p-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="save_player_performance"><input type="hidden" name="match_id" id="performanceMatchId"><div id="performanceMatchLabel" class="rounded-xl bg-slate-50 p-3 text-xs text-slate-600"></div><p class="text-xs text-slate-500">เลือกผู้เล่นที่ลงแข่ง, MVP 1 คน และระดับผลงาน ระบบจะบวก MVP เพิ่มอีก 5 คะแนน</p><div id="performanceFields" class="space-y-2"></div><div class="rounded-xl bg-orange-50 p-3 text-sm font-bold text-orange-800">คะแนนรวมตัวอย่าง: <span id="performanceTotal">0</span> คะแนน</div><div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onclick="closeMatchModal('playerPerformanceModal')" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold">ยกเลิก</button><button type="submit" class="rounded-lg bg-brand-orange px-4 py-2 text-xs font-bold text-white">บันทึกคะแนนผู้เล่น</button></div></form></div></div>
    <script>
        const matchData = <?= json_encode(array_column($matches, null, 'match_id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        function openMatchModal(id) { const element = document.getElementById(id); element.classList.remove('hidden'); element.classList.add('flex'); }
        function closeMatchModal(id) { const element = document.getElementById(id); element.classList.add('hidden'); element.classList.remove('flex'); }
        function matchLabel(match) { return '#' + (Number(match.match_index || 0) + 1) + ' | ' + (match.team1_name || 'รอผู้ชนะ') + ' vs ' + (match.team2_name || 'รอผู้ชนะ'); }
        function openMatchDetail(match) { document.getElementById('matchDetailTitle').textContent = 'รายละเอียด Match ' + match.match_id; document.getElementById('matchDetailContent').innerHTML = `<div><b>คู่แข่งขัน</b><div>${matchLabel(match)}</div></div><div><b>Category</b><div>${match.match_category_id || 'ไม่ระบุ'}</div></div><div><b>รอบ/Group</b><div>${match.bracket_type || '-'} / ${match.group_name || '-'} / รอบที่ ${match.round_number || '-'}</div></div><div><b>คะแนน</b><div>${match.team1_score ?? '-'} - ${match.team2_score ?? '-'}</div></div><div><b>สถานะ</b><div>${match.status}</div></div><div><b>กำหนดการ</b><div>${match.scheduled_at || 'ยังไม่กำหนด'} | ${match.venue_name || '-'} ${match.venue_area || ''}</div></div>`; openMatchModal('matchDetailModal'); }
        function openMatchSchedule(match) { document.getElementById('scheduleMatchId').value = match.match_id; document.getElementById('scheduleMatchLabel').textContent = matchLabel(match); document.getElementById('scheduleDate').value = match.scheduled_at ? match.scheduled_at.replace(' ', 'T').slice(0, 16) : ''; document.getElementById('scheduleVenue').value = match.venue_name || ''; document.getElementById('scheduleArea').value = match.venue_area || ''; openMatchModal('matchScheduleModal'); }
        function openMatchScore(match) { document.getElementById('scoreMatchId').value = match.match_id; document.getElementById('scoreMatchLabel').textContent = matchLabel(match) + ' | Best of ' + (match.best_of || 1); const bestOf = Math.max(1, Number(match.best_of || 1)); const fields = document.getElementById('scoreFields'); fields.innerHTML = bestOf === 1 ? '<div class="grid grid-cols-2 gap-3"><label class="text-xs font-bold">ฝั่ง A<input type="number" name="score1" min="0" required class="mt-1 w-full rounded-xl border px-3 py-2.5"></label><label class="text-xs font-bold">ฝั่ง B<input type="number" name="score2" min="0" required class="mt-1 w-full rounded-xl border px-3 py-2.5"></label></div>' : Array.from({length: bestOf}, (_, index) => `<div class="grid grid-cols-[auto_1fr_auto_1fr] items-center gap-2"><span class="text-xs">เกม ${index + 1}</span><input type="number" name="game_s1[]" min="0" class="w-full rounded-xl border px-3 py-2.5"><span>-</span><input type="number" name="game_s2[]" min="0" class="w-full rounded-xl border px-3 py-2.5"></div>`).join(''); openMatchModal('matchScoreModal'); }
        function openPlayerPerformance(match) {
            document.getElementById('performanceMatchId').value = match.match_id;
            document.getElementById('performanceMatchLabel').textContent = matchLabel(match) + ' | ผู้ชนะ: ' + (Number(match.winner_team_id) === Number(match.team1_id) ? match.team1_name : match.team2_name);
            const fields = document.getElementById('performanceFields');
            const teamNames = {[match.team1_id]: match.team1_name, [match.team2_id]: match.team2_name};
            const rosterByTeam = (match.roster || []).reduce((groups, player) => {
                const teamId = String(player.team_id);
                if (!groups[teamId]) groups[teamId] = [];
                groups[teamId].push(player);
                return groups;
            }, {});
            const teamTabs = Object.entries(rosterByTeam).map(([teamId, players], index) => `<button type="button" class="performance-team-tab rounded-lg px-3 py-2 text-xs font-bold ${index === 0 ? 'bg-brand-orange text-white' : 'bg-slate-100 text-slate-600'}" data-team-id="${teamId}">${escapePerformanceText(teamNames[teamId] || 'ทีมแข่งขัน')}</button>`).join('');
            const teamPanels = Object.entries(rosterByTeam).map(([teamId, players], index) => `<section class="performance-team-panel space-y-2 ${index === 0 ? '' : 'hidden'}" data-team-id="${teamId}"><h4 class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">${escapePerformanceText(teamNames[teamId] || 'ทีมแข่งขัน')}</h4>${players.map(player => {
                const roles = String(player.member_roles || 'player').split(',').map(role => ({coach: 'Coach', manager: 'Manager', player: player.is_starter == 1 ? 'Starter' : 'Player', substitute: 'Substitute'}[role.trim()] || role.trim())).join(', ');
                return `<label class="grid grid-cols-[auto_1fr_10rem] items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm"><input type="checkbox" name="played_player_ids[]" value="${player.player_id}" data-performance-played class="h-4 w-4"><span><span class="font-semibold">${escapePerformanceText(player.display_name)}</span><span class="ml-2 text-[10px] text-slate-500">${escapePerformanceText(roles)}</span><select name="performance[${player.player_id}]" data-performance-level class="mt-1 w-full rounded-lg border border-slate-200 px-2 py-1 text-xs"><option value="outstanding">โดดเด่น +5</option><option value="normal" selected>ปกติ +3</option><option value="participation">มีส่วนร่วม +1</option><option value="absent">ไม่ได้ลงแข่ง 0</option></select></span><label class="text-xs text-slate-600"><input type="radio" name="mvp_player_id" value="${player.player_id}" data-performance-mvp> MVP</label></label>`;
            }).join('')}</section>`).join('');
            fields.innerHTML = `<div class="flex gap-2 border-b border-slate-200 pb-2">${teamTabs}</div>${teamPanels}`;
            fields.querySelectorAll('.performance-team-tab').forEach(tab => tab.addEventListener('click', () => {
                fields.querySelectorAll('.performance-team-tab').forEach(item => item.classList.remove('bg-brand-orange', 'text-white'));
                fields.querySelectorAll('.performance-team-tab').forEach(item => item.classList.add('bg-slate-100', 'text-slate-600'));
                tab.classList.remove('bg-slate-100', 'text-slate-600');
                tab.classList.add('bg-brand-orange', 'text-white');
                fields.querySelectorAll('.performance-team-panel').forEach(panel => panel.classList.toggle('hidden', panel.dataset.teamId !== tab.dataset.teamId));
            }));
            fields.querySelectorAll('[data-performance-played]').forEach(input => input.addEventListener('change', updatePerformancePreview));
            fields.querySelectorAll('[data-performance-level]').forEach(input => input.addEventListener('change', updatePerformancePreview));
            fields.querySelectorAll('[data-performance-mvp]').forEach(input => input.addEventListener('change', updatePerformancePreview));
            updatePerformancePreview();
            openMatchModal('playerPerformanceModal');
        }
        function escapePerformanceText(value) { return String(value || '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character])); }
        function updatePerformancePreview() {
            const points = {outstanding: 5, normal: 3, participation: 1, absent: 0};
            let total = 0;
            document.querySelectorAll('#performanceFields [data-performance-played]:checked').forEach(input => {
                const level = document.querySelector(`#performanceFields select[name="performance[${input.value}]"]`);
                total += (points[level ? level.value : 'participation'] || 0) + (document.querySelector(`#performanceFields input[data-performance-mvp][value="${input.value}"]:checked`) ? 5 : 0);
            });
            document.getElementById('performanceTotal').textContent = total;
        }
        document.addEventListener('DOMContentLoaded', () => {
            const toggles = document.querySelectorAll('.match-action-toggle');
            const menus = document.querySelectorAll('.match-action-menu');
            function closeMenus(returnFocus = false) {
                menus.forEach(menu => menu.classList.remove('is-open'));
                toggles.forEach(toggle => {
                    toggle.setAttribute('aria-expanded', 'false');
                    if (returnFocus && toggle.dataset.keyboardOpen === 'true') toggle.focus();
                    delete toggle.dataset.keyboardOpen;
                });
            }
            toggles.forEach(toggle => {
                toggle.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    const menu = document.getElementById(toggle.dataset.menuId);
                    if (!menu) return;
                    const isOpen = menu.classList.contains('is-open');
                    closeMenus();
                    if (isOpen) return;
                    document.body.appendChild(menu);
                    menu.classList.add('is-open');
                    const rect = toggle.getBoundingClientRect();
                    const width = menu.offsetWidth || 224;
                    const height = menu.offsetHeight || 220;
                    menu.style.left = `${Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8))}px`;
                    menu.style.top = `${rect.bottom + height + 8 <= window.innerHeight - 8 ? rect.bottom + 8 : Math.max(8, rect.top - height - 8)}px`;
                    toggle.setAttribute('aria-expanded', 'true');
                    const match = matchData[toggle.dataset.matchId];
                    menu.querySelectorAll('[data-match-action]').forEach(item => {
                        item.onclick = () => {
                            closeMenus();
                            if (item.dataset.matchAction === 'detail') openMatchDetail(match);
                            if (item.dataset.matchAction === 'schedule') openMatchSchedule(match);
                            if (item.dataset.matchAction === 'score') openMatchScore(match);
                            if (item.dataset.matchAction === 'performance') openPlayerPerformance(match);
                        };
                    });
                });
                toggle.addEventListener('keydown', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    event.stopPropagation();
                    toggle.dataset.keyboardOpen = 'true';
                    toggle.click();
                });
            });
            menus.forEach(menu => menu.addEventListener('click', event => event.stopPropagation()));
            document.addEventListener('click', () => closeMenus());
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    closeMenus(true);
                    document.querySelectorAll('[id$="Modal"]').forEach(modal => closeMatchModal(modal.id));
                }
            });
            window.addEventListener('resize', () => closeMenus());
            window.addEventListener('scroll', () => closeMenus(), true);
            document.querySelectorAll('[id$="Modal"]').forEach(modal => modal.addEventListener('click', event => {
                if (event.target === modal) closeMatchModal(modal.id);
            }));
        });
    </script>
</body>
</html>     