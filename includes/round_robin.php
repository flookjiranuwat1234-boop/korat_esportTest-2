<?php
// includes/round_robin.php
// สร้างตารางแข่งขันแบบพบกันหมด (Round Robin)
// รองรับ 2 รูปแบบทัวร์นาเมนต์:
//   - format = 'round_robin'    : ทุกทีมอยู่กลุ่มเดียว พบกันหมดทุกคู่
//   - format = 'group_playoff'  : แบ่งเป็นหลายกลุ่ม พบกันหมดในกลุ่มตัวเอง (รอบ playoff ทำแยกทีหลัง)

// ฟังก์ชันหลัก เรียกตอน admin กด "ปิดรับสมัคร" ของทัวร์นาเมนต์ที่เป็น round_robin/group_playoff
function generateRoundRobin($pdo, $tournamentId)
{
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
    $teamsStmt = $pdo->prepare("
        SELECT team_id FROM tournament_registrations
        WHERE tournament_id = :tid AND status = 'approved'
        ORDER BY registered_at
    ");
    $teamsStmt->execute(['tid' => $tournamentId]);
    $teamIds = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($teamIds) < 2) {
        throw new Exception("ต้องมีทีมที่อนุมัติแล้วอย่างน้อย 2 ทีม");
    }

    // แบ่งกลุ่ม ถ้าเป็น round_robin ธรรมดาถือเป็นกลุ่มเดียว
    $groupCount = ($tournament['format'] == 'group_playoff' && $tournament['group_count'])
        ? (int) $tournament['group_count']
        : 1;

    $groups = splitIntoGroups($teamIds, $groupCount);

    $pdo->beginTransaction();
    try {
        $groupLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

        foreach ($groups as $i => $groupTeamIds) {
            $groupName = $groupCount > 1 ? "Group {$groupLetters[$i]}" : "Group A";

            $insertGroup = $pdo->prepare("INSERT INTO tournament_groups (tournament_id, name) VALUES (:tid, :name)");
            $insertGroup->execute(['tid' => $tournamentId, 'name' => $groupName]);
            $groupId = $pdo->lastInsertId();

            // สร้างแถวตารางคะแนนของทุกทีมในกลุ่มนี้ เริ่มที่ 0 หมด
            foreach ($groupTeamIds as $teamId) {
                $pdo->prepare("
                    INSERT INTO group_teams (group_id, team_id) VALUES (:gid, :team_id)
                ")->execute(['gid' => $groupId, 'team_id' => $teamId]);
            }

            // จัดคู่แข่งขันด้วย circle method ให้ทุกทีมเจอกันครบคนละครั้ง
            $rounds = circleMethodSchedule($groupTeamIds);

            foreach ($rounds as $roundNumber => $pairs) {
                foreach ($pairs as $index => $pair) {
                    [$team1, $team2] = $pair;
                    $insert = $pdo->prepare("
                        INSERT INTO matches (tournament_id, group_id, round_number, match_index, team1_id, team2_id, status)
                        VALUES (:tid, :gid, :round, :idx, :team1, :team2, 'scheduled')
                    ");
                    $insert->execute([
                        'tid' => $tournamentId,
                        'gid' => $groupId,
                        'round' => $roundNumber + 1,
                        'idx' => $index,
                        'team1' => $team1,
                        'team2' => $team2,
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    return count($groups);
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
