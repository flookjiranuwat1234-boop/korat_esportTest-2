<?php
// includes/round_robin.php
// สร้างตารางแข่งขันแบบพบกันหมด (Round Robin)
// รองรับ 2 รูปแบบทัวร์นาเมนต์:
//   - format = 'round_robin'    : ทุกทีมอยู่กลุ่มเดียว พบกันหมดทุกคู่
//   - format = 'group_playoff'  : แบ่งเป็นหลายกลุ่ม พบกันหมดในกลุ่มตัวเอง (รอบ playoff ทำแยกทีหลัง)
require_once __DIR__ . '/tournament_categories.php';
require_once __DIR__ . '/bracket.php';

// ฟังก์ชันหลัก เรียกตอน admin กด "ปิดรับสมัคร" ของทัวร์นาเมนต์ที่เป็น round_robin/group_playoff
function generateRoundRobin($pdo, $tournamentId)
{
    ensureTournamentCategorySchema($pdo);
    $check = $pdo->prepare("SELECT COUNT(*) FROM tournament_groups WHERE tournament_id = :tid");
    $check->execute(['tid' => $tournamentId]);
    if ($check->fetchColumn() > 0) {
        throw new Exception("ทัวร์นาเมนต์นี้สร้างตารางแข่งขันไปแล้ว");
    }

    $tStmt = $pdo->prepare("SELECT format, group_count FROM tournaments WHERE tournament_id = :tid");
    $tStmt->execute(['tid' => $tournamentId]);
    $tournament = $tStmt->fetch();

    // ดึงทีมที่อนุมัติแล้ว เรียงตามวันที่สมัคร (ไม่ต้อง seed ตามคะแนนเหมือน bracket
    // เพราะ round robin ทุกทีมเจอกันหมดอยู่แล้ว ลำดับก่อนหลังไม่มีผล)
    $teamsStmt = $pdo->prepare("SELECT team_id, tournament_category_id, category, registered_at
        FROM tournament_registrations
        WHERE tournament_id = :tid AND status = 'approved' AND participation_status = 'qualified_for_draw'
        ORDER BY registered_at");
    $teamsStmt->execute(['tid' => $tournamentId]);
    $teamRows = $teamsStmt->fetchAll();

    if (count($teamRows) < 2) {
        throw new Exception("ต้องมีทีมที่ผ่าน Check-in และพร้อมจัดสายอย่างน้อย 2 ทีม");
    }

    // แบ่งกลุ่ม ถ้าเป็น round_robin ธรรมดาถือเป็นกลุ่มเดียว
    $groupCount = ($tournament['format'] == 'group_playoff' && $tournament['group_count'])
        ? (int) $tournament['group_count']
        : 1;

    $pdo->beginTransaction();
    try {
        $groupLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $categoryBuckets = [];
        foreach ($teamRows as $teamRow) {
            $bucketKey = (string) ($teamRow['tournament_category_id'] ?: ($teamRow['category'] ?: 'open'));
            $categoryBuckets[$bucketKey]['category_id'] = $teamRow['tournament_category_id'] ?: null;
            $categoryBuckets[$bucketKey]['category_code'] = $teamRow['category'] ?: 'open';
            $categoryBuckets[$bucketKey]['team_ids'][] = (int) $teamRow['team_id'];
        }

        $createdGroups = 0;
        foreach ($categoryBuckets as $categoryBucket) {
            $groups = splitIntoGroups($categoryBucket['team_ids'], $groupCount);
            foreach ($groups as $i => $groupTeamIds) {
                $categoryLabel = strtoupper($categoryBucket['category_code']);
                $groupName = $categoryLabel . ' ' . ($groupCount > 1 ? "Group {$groupLetters[$i]}" : 'Group A');

                $insertGroup = $pdo->prepare("INSERT INTO tournament_groups (tournament_id, tournament_category_id, name) VALUES (:tid, :category_id, :name)");
                $insertGroup->execute(['tid' => $tournamentId, 'category_id' => $categoryBucket['category_id'], 'name' => $groupName]);
                $groupId = $pdo->lastInsertId();
                $createdGroups++;

                foreach ($groupTeamIds as $teamId) {
                    $pdo->prepare("INSERT INTO group_teams (group_id, team_id) VALUES (:gid, :team_id)")
                        ->execute(['gid' => $groupId, 'team_id' => $teamId]);
                }

                $rounds = circleMethodSchedule($groupTeamIds);

                foreach ($rounds as $roundNumber => $pairs) {
                    foreach ($pairs as $index => $pair) {
                        [$team1, $team2] = $pair;
                        $insert = $pdo->prepare("INSERT INTO matches (tournament_id, tournament_category_id, group_id, round_number, match_index, team1_id, team2_id, status)
                            VALUES (:tid, :category_id, :gid, :round, :idx, :team1, :team2, 'scheduled')");
                        $insert->execute([
                            'tid' => $tournamentId,
                            'category_id' => $categoryBucket['category_id'],
                            'gid' => $groupId,
                            'round' => $roundNumber + 1,
                            'idx' => $index,
                            'team1' => $team1,
                            'team2' => $team2,
                        ]);
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
    $pending = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id
        AND group_id IS NOT NULL AND status NOT IN ('completed', 'walkover', 'cancelled')");
    $pending->execute(['tournament_id' => $tournamentId]);
    if ((int) $pending->fetchColumn() > 0) {
        throw new Exception('ยังมี Match รอบแบ่งกลุ่มที่ไม่เสร็จ');
    }

    $existingPlayoff = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id AND group_id IS NULL');
    $existingPlayoff->execute(['tournament_id' => $tournamentId]);
    if ((int) $existingPlayoff->fetchColumn() > 0) {
        throw new Exception('Tournament นี้สร้างสาย Playoff แล้ว');
    }

    $groupStmt = $pdo->prepare('SELECT tg.tournament_group_id, tg.tournament_category_id
        FROM tournament_groups tg WHERE tg.tournament_id = :tournament_id ORDER BY tg.tournament_group_id');
    $groupStmt->execute(['tournament_id' => $tournamentId]);
    $qualifiedByCategory = [];
    foreach ($groupStmt->fetchAll() as $group) {
        $advanceStmt = $pdo->prepare('SELECT COALESCE(tc.teams_advance_per_group, 1)
            FROM tournament_categories tc WHERE tc.tournament_category_id = :category_id');
        $advanceStmt->execute(['category_id' => $group['tournament_category_id']]);
        $advanceCount = max(1, (int) ($advanceStmt->fetchColumn() ?: 1));
        $standingStmt = $pdo->prepare('SELECT team_id FROM group_teams
            WHERE group_id = :group_id ORDER BY points DESC, score_diff DESC, wins DESC, team_id ASC LIMIT ' . $advanceCount);
        $standingStmt->execute(['group_id' => $group['tournament_group_id']]);
        foreach ($standingStmt->fetchAll(PDO::FETCH_COLUMN) as $teamId) {
            $qualifiedByCategory[(string) $group['tournament_category_id']][] = (int) $teamId;
        }
    }

    $tournamentStmt = $pdo->prepare('SELECT game_id, best_of FROM tournaments WHERE tournament_id = :tournament_id');
    $tournamentStmt->execute(['tournament_id' => $tournamentId]);
    $tournament = $tournamentStmt->fetch();
    $rounds = 0;
    foreach ($qualifiedByCategory as $categoryId => $teamIds) {
        if (count($teamIds) < 2) continue;
        $rounds = max($rounds, generateEliminationForCategory(
            $pdo,
            $tournamentId,
            $teamIds,
            max(1, (int) $tournament['best_of']),
            'playoff_' . $categoryId,
            (int) $categoryId
        ));
    }
    if ($rounds === 0) {
        throw new Exception('ยังไม่มีทีมผ่านเข้ารอบ Playoff อย่างน้อย 2 ทีม');
    }
    return $rounds;
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
