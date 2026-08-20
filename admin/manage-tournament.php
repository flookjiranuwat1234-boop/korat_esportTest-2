<?php
// admin/manage-tournament.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/bracket.php';
requireRole('admin');

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$error = '';
$success = '';

// ==========================================
// AJAX: ดึงตารางสรุปคะแนนสำหรับ Modal
// ==========================================
if (isset($_GET['ajax_get_results'])) {
    $tid = (int)$_GET['ajax_get_results'];
    $filterCategory = $_GET['category'] ?? 'all';

    // ปรับให้ดึง category จาก tournament_registrations แทนตาราง teams
    $sql = "
        SELECT t.team_id, t.name, tr.category AS team_category 
        FROM tournament_registrations tr
        JOIN teams t ON t.team_id = tr.team_id
        WHERE tr.tournament_id = :tid AND (tr.status = 'approved' OR tr.status = 'checked_in')
    ";
    $params = ['tid' => $tid];

    if ($filterCategory !== 'all') {
        $sql .= " AND tr.category = :cat";
        $params['cat'] = $filterCategory;
    }

    $teamsStmt = $pdo->prepare($sql);
    $teamsStmt->execute($params);
    $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($teams)) {
        echo '<div class="p-8 text-center text-slate-400 text-xs">ยังไม่มีทีมที่อนุมัติเข้าร่วมในประเภทนี้</div>';
        exit;
    }

    $matchesStmt = $pdo->prepare("
        SELECT team1_id, team2_id, team1_score, team2_score, status 
        FROM matches 
        WHERE tournament_id = :tid AND status IN ('completed', 'walkover')
    ");
    $matchesStmt->execute(['tid' => $tid]);
    $matches = $matchesStmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [];
    foreach ($teams as $team) {
        $stats[$team['team_id']] = [
            'name' => $team['name'],
            'category' => $team['team_category'],
            'wins' => 0,
            'losses' => 0,
            'points' => 0
        ];
    }

    foreach ($matches as $m) {
        $t1 = $m['team1_id'];
        $t2 = $m['team2_id'];
        $s1 = (int)$m['team1_score'];
        $s2 = (int)$m['team2_score'];

        if (!isset($stats[$t1]) || !isset($stats[$t2])) continue;

        if ($m['status'] == 'walkover') {
            if ($s1 > $s2) {
                $stats[$t1]['wins']++; $stats[$t1]['points'] += 3;
                $stats[$t2]['losses']++;
            } else {
                $stats[$t2]['wins']++; $stats[$t2]['points'] += 3;
                $stats[$t1]['losses']++;
            }
        } else {
            if ($s1 > $s2) {
                $stats[$t1]['wins']++; $stats[$t1]['points'] += 3;
                $stats[$t2]['losses']++;
            } elseif ($s2 > $s1) {
                $stats[$t2]['wins']++; $stats[$t2]['points'] += 3;
                $stats[$t1]['losses']++;
            } else {
                $stats[$t1]['points'] += 1;
                $stats[$t2]['points'] += 1;
            }
        }
    }

    usort($stats, function($a, $b) {
        if ($b['points'] == $a['points']) {
            return $b['wins'] - $a['wins'];
        }
        return $b['points'] - $a['points'];
    });
    
    echo '<table class="w-full text-left text-sm text-slate-600">';
    echo '<thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">';
    echo '<tr><th class="p-3 text-center w-16">อันดับ</th><th class="p-3">ชื่อทีม</th><th class="p-3 text-center">ประเภท</th><th class="p-3 text-center">ชนะ - แพ้</th><th class="p-3 text-right">คะแนน</th></tr>';
    echo '</thead><tbody class="divide-y divide-slate-100">';
    
    $i = 1;
    foreach ($stats as $r) {
        $rankClass = ($i == 1) ? 'text-amber-500 font-black' : 'font-bold text-slate-900';
        $catBadge = '';
        if ($r['category'] == 'male') $catBadge = '<span class="px-2 py-0.5 rounded text-[10px] bg-blue-50 text-blue-600 font-bold">ชาย</span>';
        elseif ($r['category'] == 'female') $catBadge = '<span class="px-2 py-0.5 rounded text-[10px] bg-pink-50 text-pink-600 font-bold">หญิง</span>';
        else $catBadge = '<span class="px-2 py-0.5 rounded text-[10px] bg-purple-50 text-purple-600 font-bold">Open</span>';

        echo "<tr class='hover:bg-slate-50/80 transition-colors'>
                <td class='p-3 text-center {$rankClass}'>{$i}</td>
                <td class='p-3 font-bold text-slate-900'>".htmlspecialchars($r['name'])."</td>
                <td class='p-3 text-center'>{$catBadge}</td>
                <td class='p-3 text-center font-mono text-xs'><span class='text-emerald-600 font-bold'>{$r['wins']}W</span> - <span class='text-rose-500 font-bold'>{$r['losses']}L</span></td>
                <td class='p-3 text-right font-display font-black text-brand-orange'>{$r['points']} PTS</td>
              </tr>";
        $i++;
    }
    echo '</tbody></table>';
    exit;
}

// ==========================================
// AUTO SETUP
// ==========================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('prize_pool', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN prize_pool VARCHAR(255) NULL AFTER max_teams"); }
    if (!in_array('rules', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN rules TEXT NULL AFTER prize_pool"); }
    if (!in_array('description', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN description TEXT NULL AFTER rules"); }
    if (!in_array('venue_address', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN venue_address VARCHAR(255) NULL AFTER description"); }
    if (!in_array('image_path', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN image_path VARCHAR(255) NULL AFTER venue_address"); }
    if (!in_array('best_of', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN best_of TINYINT NOT NULL DEFAULT 5 AFTER format"); }
    if (!in_array('registration_start', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN registration_start DATETIME NULL AFTER description"); }
    if (!in_array('registration_end', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN registration_end DATETIME NULL AFTER registration_start"); }
    if (!in_array('start_date', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN start_date DATETIME NULL AFTER registration_end"); }

    $defaultGames = [
        'Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี',
        'Arena of Valor (RoV) - รุ่น Open',
        'Free Fire - รุ่น Open',
        'Tekken 8 - รุ่น Open',
        'Street Fighter 6 - รุ่น Open',
        'Efootball Mobile - รุ่น Open',
        'Roblox - รุ่นอายุ 8-12 ปี'
    ];
    $checkCol = $pdo->query("SHOW COLUMNS FROM games LIKE 'is_active'")->fetch();
    foreach ($defaultGames as $gName) {
        $chk = $pdo->prepare("SELECT game_id FROM games WHERE name = ?");
        $chk->execute([$gName]);
        if (!$chk->fetch()) {
            if ($checkCol) {
                $pdo->prepare("INSERT INTO games (name, is_active) VALUES (?, 1)")->execute([$gName]);
            } else {
                $pdo->prepare("INSERT INTO games (name) VALUES (?)")->execute([$gName]);
            }
        }
    }
} catch (Exception $e) { }

$games = $pdo->query("SELECT game_id, name FROM games")->fetchAll(PDO::FETCH_ASSOC);

function getGameId($games_array, $game_name) {
    foreach($games_array as $g) {
        if (trim($g['name']) == trim($game_name)) return $g['game_id'];
    }
    return '';
}

function uploadTournamentImage($file) {
    if (isset($file) && $file['error'] == UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($file['type'], $allowedTypes)) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = 'tourney_' . uniqid() . '.' . $ext;
            $uploadDir = '../assets/uploads/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $destination = $uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $destination)) { return 'uploads/' . $fileName; }
        }
    }
    return null;
}

// ==========================================
// 1. เพิ่มทัวร์นาเมนต์ใหม่
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'create') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $name = trim($_POST['name'] ?? '');
        $gameId = trim($_POST['game_id'] ?? '');
        $format = $_POST['format'] ?? 'single_elimination';
        $bestOf = (int)($_POST['best_of'] ?? 5); 
        $maxTeams = (int) ($_POST['max_teams'] ?? 8);
        $prizePool = trim($_POST['prize_pool'] ?? '');
        $venueAddress = trim($_POST['venue_address'] ?? '');
        $rules = trim($_POST['rules'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $regStart = !empty($_POST['registration_start']) ? $_POST['registration_start'] : null;
        $regEnd = !empty($_POST['registration_end']) ? $_POST['registration_end'] : null;
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;

        $imagePath = uploadTournamentImage($_FILES['tournament_image'] ?? null);

        $adminId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
        if (!$adminId) {
            $fallbackAdmin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetch();
            $adminId = $fallbackAdmin['user_id'] ?? 1;
        }

        if ($name == '' || empty($gameId) || $maxTeams < 2) {
            $error = 'กรุณากรอกชื่อทัวร์นาเมนต์ เลือกเกม และกำหนดจำนวนทีมให้ถูกต้อง';
        } elseif (!$regStart || !$regEnd || !$startDate) {
            $error = 'กรุณากำหนดวันเปิดรับสมัคร วันปิดรับสมัคร และวันเริ่มแข่งขัน';
        } elseif (strtotime($regStart) >= strtotime($regEnd)) {
            $error = 'วันปิดรับสมัครต้องอยู่หลังวันเปิดรับสมัคร';
        } elseif (strtotime($regEnd) > strtotime($startDate)) {
            $error = 'วันเริ่มแข่งขันต้องไม่อยู่ก่อนวันปิดรับสมัคร';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO tournaments (name, game_id, format, best_of, max_teams, prize_pool, venue_address, image_path, rules, description, registration_start, registration_end, start_date, status, created_by)
                    VALUES (:name, :game_id, :format, :best_of, :max_teams, :prize_pool, :venue_address, :image_path, :rules, :description, :reg_start, :reg_end, :start_date, 'registration_open', :created_by)
                ");
                $stmt->execute([
                    'name' => $name, 'game_id' => $gameId, 'format' => $format, 'best_of' => $bestOf, 'max_teams' => $maxTeams, 'prize_pool' => $prizePool,
                    'venue_address' => $venueAddress, 'image_path' => $imagePath, 'rules' => $rules, 'description' => $description, 'reg_start' => $regStart,
                    'reg_end' => $regEnd, 'start_date' => $startDate, 'created_by' => $adminId
                ]);
                $success = 'สร้างทัวร์นาเมนต์ใหม่เรียบร้อยแล้ว';
            } catch (Exception $e) {
                $error = 'เกิดข้อผิดพลาดในการสร้างทัวร์นาเมนต์: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// 2. Export ผลการแข่งขันเป็นไฟล์ CSV
// ==========================================
if (isset($_GET['export_results_csv'])) {
    $exportTid = (int) $_GET['export_results_csv'];
    $exportCategory = $_GET['category'] ?? 'all';

    $stmtT = $pdo->prepare("SELECT name FROM tournaments WHERE tournament_id = :id");
    $stmtT->execute(['id' => $exportTid]);
    $tName = $stmtT->fetchColumn() ?: 'tournament_results';

    $sql = "
        SELECT 
            m.match_id,
            m.team1_id,
            m.team2_id,
            t1.name AS team1_name,
            t2.name AS team2_name,
            m.team1_score,
            m.team2_score,
            m.status
        FROM matches m
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        WHERE m.tournament_id = :id
    ";
    
    $stmtMatches = $pdo->prepare($sql . " ORDER BY m.match_id ASC");
    $stmtMatches->execute(['id' => $exportTid]);
    $matches = $stmtMatches->fetchAll(PDO::FETCH_ASSOC);

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="match_results_' . $exportTid . '_' . $exportCategory . '.csv"');

    $output = fopen('php://output', 'w');

    if ($output !== false) {
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Match ID', 'ทีมเหย้า', 'คะแนน', 'ทีมเยือน', 'คะแนน', 'ผลการแข่งขัน']);

        foreach ($matches as $row) {
            $team1Score = is_numeric($row['team1_score']) ? (int)$row['team1_score'] : 0;
            $team2Score = is_numeric($row['team2_score']) ? (int)$row['team2_score'] : 0;
            $team1Name = !empty($row['team1_name']) ? $row['team1_name'] : 'รอระบุทีม';
            $team2Name = !empty($row['team2_name']) ? $row['team2_name'] : 'รอระบุทีม';

            if ($row['status'] === 'completed') {
                if ($team1Score > $team2Score) {
                    $resultText = $team1Name . ' ชนะ';
                } elseif ($team2Score > $team1Score) {
                    $resultText = $team2Name . ' ชนะ';
                } else {
                    $resultText = 'เสมอ';
                }
            } elseif ($row['status'] === 'walkover') {
                $resultText = 'ชนะบาย';
            } elseif ($row['status'] === 'ongoing') {
                $resultText = 'กำลังแข่งขัน';
            } else {
                $resultText = 'ยังไม่แข่ง';
            }

            fputcsv($output, [
                ' ' . ($row['match_id'] ?? '-') . ' ',
                ' ' . $team1Name . ' ',
                ' ' . $team1Score . ' ',
                ' ' . $team2Name . ' ',
                ' ' . $team2Score . ' ',
                ' ' . $resultText . ' '
            ]);
        }
        fclose($output);
    }
    exit;
}

// ==========================================
// 3. แก้ไขทัวร์นาเมนต์
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'update') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $tid = (int) $_POST['tournament_id'];
        $name = trim($_POST['name']);
        $gameId = trim($_POST['game_id'] ?? '');
        $format = $_POST['format'] ?? 'single_elimination';
        $bestOf = (int)($_POST['best_of'] ?? 5);
        $maxTeams = (int) $_POST['max_teams'];
        $prizePool = trim($_POST['prize_pool'] ?? '');
        $venueAddress = trim($_POST['venue_address'] ?? '');
        $rules = trim($_POST['rules'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $regStart = !empty($_POST['registration_start']) ? $_POST['registration_start'] : null;
        $regEnd = !empty($_POST['registration_end']) ? $_POST['registration_end'] : null;
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;

        $newImagePath = uploadTournamentImage($_FILES['tournament_image'] ?? null);

        if ($name == '' || empty($gameId) || $maxTeams < 2) {
            $error = 'กรอกชื่อทัวร์นาเมนต์ เลือกเกม และจำนวนทีมให้ถูกต้อง';
        } elseif (!$regStart || !$regEnd || !$startDate) {
            $error = 'กรุณากำหนดวันเปิดรับสมัคร วันปิดรับสมัคร และวันเริ่มแข่งขัน';
        } elseif (strtotime($regStart) >= strtotime($regEnd)) {
            $error = 'วันปิดรับสมัครต้องอยู่หลังวันเปิดรับสมัคร';
        } elseif (strtotime($regEnd) > strtotime($startDate)) {
            $error = 'วันเริ่มแข่งขันต้องไม่อยู่ก่อนวันปิดรับสมัคร';
        } else {
            if ($newImagePath) {
                $update = $pdo->prepare("
                    UPDATE tournaments 
                    SET name = :name, game_id = :game_id, format = :format, best_of = :best_of, max_teams = :max_teams, prize_pool = :prize_pool,
                        venue_address = :venue_address, image_path = :image_path, rules = :rules, description = :description,
                        registration_start = :reg_start, registration_end = :reg_end, start_date = :start_date
                    WHERE tournament_id = :id
                ");
                $update->execute([
                    'name' => $name, 'game_id' => $gameId, 'format' => $format, 'best_of' => $bestOf, 'max_teams' => $maxTeams, 'prize_pool' => $prizePool,
                    'venue_address' => $venueAddress, 'image_path' => $newImagePath, 'rules' => $rules,
                    'description' => $description, 'reg_start' => $regStart, 'reg_end' => $regEnd,
                    'start_date' => $startDate, 'id' => $tid
                ]);
            } else {
                $update = $pdo->prepare("
                    UPDATE tournaments 
                    SET name = :name, game_id = :game_id, format = :format, best_of = :best_of, max_teams = :max_teams, prize_pool = :prize_pool,
                        venue_address = :venue_address, rules = :rules, description = :description,
                        registration_start = :reg_start, registration_end = :reg_end, start_date = :start_date
                    WHERE tournament_id = :id
                ");
                $update->execute([
                    'name' => $name, 'game_id' => $gameId, 'format' => $format, 'best_of' => $bestOf, 'max_teams' => $maxTeams, 'prize_pool' => $prizePool,
                    'venue_address' => $venueAddress, 'rules' => $rules, 'description' => $description,
                    'reg_start' => $regStart, 'reg_end' => $regEnd, 'start_date' => $startDate, 'id' => $tid
                ]);
            }
            $success = 'อัปเดตข้อมูลทัวร์นาเมนต์เรียบร้อยแล้ว';
        }
    }
}

// ==========================================
// 4. ลบทัวร์นาเมนต์
// ==========================================
if (isset($_GET['delete_tournament'])) {
    $tid = (int) $_GET['delete_tournament'];
    try {
        $pdo->beginTransaction();
        
        $pdo->prepare("DELETE FROM matches WHERE tournament_id = :id")->execute(['id' => $tid]);
        $pdo->prepare("DELETE FROM tournament_registrations WHERE tournament_id = :id")->execute(['id' => $tid]);
        
        $del = $pdo->prepare("DELETE FROM tournaments WHERE tournament_id = :id");
        $del->execute(['id' => $tid]);
        
        $pdo->commit();
        $success = 'ลบทัวร์นาเมนต์เรียบร้อยแล้ว';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'ไม่สามารถลบได้: ' . $e->getMessage();
    }
}

// ==========================================
// 5. ปิดรับสมัคร & สร้างตารางแข่ง
// ==========================================
if (isset($_GET['close_registration'])) {
    $tid = (int) $_GET['close_registration'];

    $pdo->prepare("UPDATE tournament_registrations SET status = 'approved' WHERE tournament_id = :id")->execute(['id' => $tid]);

    $tStmt = $pdo->prepare("SELECT format FROM tournaments WHERE tournament_id = :id");
    $tStmt->execute(['id' => $tid]);
    $format = $tStmt->fetchColumn();

    try {
        if ($format == 'double_elimination') {
            generateDoubleEliminationBracket($pdo, $tid);
        } else {
            generateSingleEliminationBracket($pdo, $tid);
        }

        $pdo->prepare("UPDATE tournaments SET status = 'ongoing' WHERE tournament_id = :id")->execute(['id' => $tid]);
        header("Location: record-match.php?tournament_id=" . $tid);
        exit;
    } catch (Exception $e) {
        $error = 'สร้างตารางแข่งขันไม่สำเร็จ: ' . $e->getMessage();
    }
}

// ==========================================
// 6. สลับสถานะเป็น "แข่งจบแล้ว (completed)"
// ==========================================
if (isset($_GET['mark_completed'])) {
    $tid = (int) $_GET['mark_completed'];
    $pdo->prepare("UPDATE tournaments SET status = 'completed' WHERE tournament_id = :id")->execute(['id' => $tid]);
    $success = 'เปลี่ยนสถานะทัวร์นาเมนต์เป็น "แข่งจบแล้ว" เรียบร้อย';
}

$tournaments = $pdo->query("
    SELECT t.*, g.name AS game_name,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND (status = 'approved' OR status = 'checked_in')) AS team_count,
        (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.category = 'male' AND (tr.status = 'approved' OR tr.status = 'checked_in')) AS count_male,
        (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.category = 'female' AND (tr.status = 'approved' OR tr.status = 'checked_in')) AS count_female,
        (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.category = 'open' AND (tr.status = 'approved' OR tr.status = 'checked_in')) AS count_open,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id AND status IN ('completed', 'walkover')) AS completed_matches_count,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id) AS total_matches_count
    FROM tournaments t
    LEFT JOIN games g ON g.game_id = t.game_id
    ORDER BY t.created_at DESC
")->fetchAll();

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการทัวร์นาเมนต์ - Korat Esport</title>
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
        ::-webkit-scrollbar { display: none; }
        html, body {
            -ms-overflow-style: none;
            scrollbar-width: none;
            background-color: #F4F6F9;
        }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover, .nav-item.active {
            background: rgba(255, 85, 0, 0.12);
            color: #FF5500;
            border-left: 4px solid #FF5500;
        }
    </style>
    <script>
        const tournamentsList = <?php echo json_encode($tournaments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

        const gameRulesData = {
            "Tekken": `TEKKEN 8 (ประเภทบุคคล รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. ระบบการแข่งขัน : Double Elimination (สายบน Winners /สายล่าง Losers)
1.2. Best of 3 (2 ใน 3 เกม)
1.3. รอบ Top 8 (Finals) : Best of 5 (3 ใน 5 เกม)
2. การตั้งค่าในเกม: 3 Rounds (60 วินาทีต่อ Round)
2.1. กฎการเลือกตัวละครและฉาก (Character & Stage Selection)
2.2. เกมที่ 1: ตกลงเลือกตัวละคร (หรือใช้ Blind Pick หากตกลงกันไม่ได้)
2.3. เลือกฉากด้วยระบบ "Random" เท่านั้น
2.4. เกมถัดไป ผู้แพ้สามารถเลือกทำอย่างใดอย่างหนึ่งดังนี้
2.4.1. ขอเปลี่ยนตัวละคร และต้องเลือกฉากแบบ Random เท่านั้น
2.4.2. ใช้ตัวละครเดิม และต้องเลือกฉากแบบ Random เท่านั้น
2.4.3. ผู้ชนะห้ามเปลี่ยนตัวละคร
3. การตั้งค่าระบบเกม (Game Settings)
3.1. Platform: PlayStation 5 แบบปิด Bluetooth
3.2. Special Style: "อนุญาต" ให้ใช้งานได้ (ระบบช่วยกดคอมโบพื้นฐานของ Tekken 8)
3.3. ฝั่งซ้ายและขวาตัดสินจากการทอยเหรียญหัวและก้อย
4. ข้อบังคับด้านอุปกรณ์และการขัดจังหวะ
4.1. ผู้แข่งต้องเตรียมอุปกรณ์มาเอง ห้ามมีระบบ Macro/Turbo
4.2. เครื่อง PS5 จะปิด Bluetooth และต้องต่ออุปกรณ์ด้วยสายเท่านั้น
4.3. หากมีการกด Pause หรือปุ่ม Home ระหว่างสู้ ผู้ที่กดจะถูกปรับแพ้ ใน "Round" นั้นทันที`,

            "Roblox": `Roblox (ประเภทบุคคล รุ่นอายุ 8-12 ปี)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แบ่ง 10 กลุ่ม กลุ่มละ 10 คน แข่ง 5 รอบ เกมแข่งกระโดดหลบสิ่งกีดขวาง Obby, อันดับ 1 แต่ละกลุ่มเข้า Final)
1.2. รอบ Final (แข่ง 5 รอบ เกมแข่งกระโดดหลบสิ่งกีดขวาง Obby, ชิงอันดับ 1-3, อันดับ 5-10 รับใบประกาศอันดับ 4 ร่วม)
2. การนับคะแนน: อันดับ 1 = 10, อันดับ 2 = 8, อันดับ 3 = 7, อันดับ 4 = 5, อันดับ 5 = 3, อันดับ 6 = 2, อันดับ 7-10 = 1 คะแนน
3. ข้อบังคับด้านอุปกรณ์: อนุญาตให้ใช้ Tablet, iPad มือถือส่วนตัว ในแอปพลิเคชันเกม Roblox
4. การขัดจังหวะและการ Pause: หากมีการกด Pause หรือปุ่ม Home ระหว่างแข่ง ผู้ที่กดจะถูกปรับแพ้ใน "Round" นั้นทันที`,

            "Street Fighter": `STREET FIGHTER 6 (ประเภทบุคคล รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. แข่งแบบ Double Elimination
1.2. รอบคัดเลือกจนถึงก่อน Top 8 แข่ง Best of 3
1.3. รอบ Top 8 (Finals): Best of 5
1.4. การตั้งค่าในเกม: 99 Seconds, 2/3 Rounds ต่อเกม
2. กฎการเลือกตัวละครและประเภทการควบคุม
2.1. Control Types: อนุญาตให้ใช้ทั้ง Classic และ Modern
2.2. เกมที่ 1: เลือกตัวละครและประเภทการควบคุม
2.3. เลือกฉากด้วยระบบ Random หรือแล้วแต่ผู้เล่นจะตกลงกัน
2.4. ผู้ชนะห้ามเปลี่ยนตัวละคร และ ห้ามเปลี่ยนประเภทการควบคุม
2.5. ผู้แพ้ มีสิทธิเลือก เปลี่ยนตัวละคร หรือ เปลี่ยนประเภทการควบคุม
3. ข้อบังคับด้านอุปกรณ์
3.1. Leverless/Hitbox: ต้องเป็นไปตามกฎ SOCD (ขึ้น+ลง หรือ ขวา+ซ้าย ต้องหักล้างกัน ตัวละครไม่ขยับเท่านั้น)
3.2. เครื่อง PS5 จะปิด Bluetooth และต้องต่ออุปกรณ์ด้วยสายเท่านั้น`,

            "Free Fire": `FREE FIRE (ประเภททีม รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แบ่ง 2 กลุ่ม กลุ่มละ 12 ทีม แข่ง 4 รอบ แผนที่ เกาะสวรรค์, ทะเลทราย, แดนชำระบาป, นิคมรกร้าง อันดับ 1-6 เข้ารอบ Final)
1.2. รอบ Final (แข่ง 4 รอบ แผนที่เดียวกัน)
2. การนับคะแนน: อันดับ 1 = 10, อันดับ 2 = 7, อันดับ 3 = 5, อันดับ 4 = 3, อันดับ 5 = 2, อันดับ 6 = 1 คะแนน, อันดับ 7-12 = 0 คะแนน, 1 Kill = 1 คะแนน
3. กรณีคะแนนเท่ากัน: ดูจากจำนวน Booyah -> จำนวน Kill รวม -> อันดับในเกมสุดท้าย
4. การตั้งค่าเกม: เปิดใช้สถานะปืนจากสกิน, เปิดระบบชุบชีวิต, เปิดมุมกล้องหลังถูกสังหาร, ปิดชื่อตัวละคร, ปิด Kill Feed, ปิดโปรแกรมจำลองคอมพิวเตอร์`,

            "RoV": `ARENA OF VALOR (RoV)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แบ่งกลุ่ม แข่งแบบ BO2 ชนะได้ 2, เสมอ 1 คะแนน ตัดสินจาก Kill และ Time Rating อันดับ 1-2 เข้ารอบ Playoff)
1.2. รอบ Playoff (Single Elimination BO3)
2. กติกาการแข่งขัน
2.1. โหมด 5V5 ใช้ระบบ Global Ban/Pick
2.2. การเลือกฝั่ง: เกมที่ 1 ทอยเหรียญ, ตั้งแต่เกมที่ 2 ให้ทีมแพ้เลือกฝั่ง
2.3. ห้ามใช้ Hero ที่อัพเดทยังไม่ถึง 14 วัน / ห้ามใช้สกินที่มีปัญหาบั๊ก
2.4. ห้ามหยุดพักเกมระหว่าง Fight ทุกกรณี หากฝ่าฝืนเตือนหรือปรับแพ้ในเกมนั้น`,

            "Efootball": `EFOOTBALL MOBILE (ประเภทบุคคล รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แข่งแบบ Best of 1 ชนะได้ 3 คะแนน ตัดสินจากประตูได้เสีย, จำนวนประตู, Head to Head นำอันดับ 1-2 เข้ารอบ 16 ทีม)
1.2. รอบ 16 ทีมสุดท้ายถึงชิงชนะเลิศ (Single Elimination Best of 3)
2. กติกาการแข่งขัน
2.1. ใช้ทีมสโมสรลิขสิทธิ์แบบเกลี่ยพลัง (ห้ามใช้สโมสรไทยลีก)
2.2. ตั้งค่าเกม: Match Type: Standard, Match Time: 6 min, Injuries: Off, Extra Time: On, Penalties: On, Substitutions: 5
2.3. กรณีหลุด: ครึ่งแรกเริ่มใหม่ใช้สกอร์เดิม, ครึ่งหลังเริ่มแข่งใหม่ครึ่งเดียว, หลังนาทีที่ 80 ถ้านำหลุดให้แข่งใหม่ครึ่งเดียว ถ้าตามหลุดให้นับผลล่าสุดทันที`
        };

        function autoFillRules(selectElement, targetRulesId) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const gameName = selectedOption.text || '';
            const rulesTextarea = document.getElementById(targetRulesId);
            
            let matchedKey = Object.keys(gameRulesData).find(key => gameName.includes(key));
            if (matchedKey && gameRulesData[matchedKey]) {
                rulesTextarea.value = gameRulesData[matchedKey];
            } else {
                rulesTextarea.value = "";
            }
        }

        function openCreateModal() {
            document.getElementById('createModal').classList.remove('hidden');
            document.getElementById('createModal').classList.add('flex');
        }
        function closeCreateModal() {
            document.getElementById('createModal').classList.add('hidden');
            document.getElementById('createModal').classList.remove('flex');
        }

        function safeSetValue(id, value) {
            const el = document.getElementById(id);
            if (el) el.value = value;
        }

        function openEditModal(tournamentId) {
            try {
                const tournament = tournamentsList.find(t => t.tournament_id == tournamentId);
                if (!tournament) throw new Error("ไม่พบข้อมูลทัวร์นาเมนต์");

                safeSetValue('edit_tournament_id', tournament.tournament_id);
                safeSetValue('edit_name', tournament.name);
                safeSetValue('edit_game_id', tournament.game_id);
                safeSetValue('edit_format', tournament.format || 'single_elimination');
                safeSetValue('edit_best_of', tournament.best_of || '5');
                safeSetValue('edit_max_teams', tournament.max_teams);
                safeSetValue('edit_prize_pool', tournament.prize_pool || '');
                safeSetValue('edit_rules', tournament.rules || '');
                
                safeSetValue('edit_venue_address', tournament.venue_address || '');
                safeSetValue('edit_description', tournament.description || '');
                safeSetValue('edit_registration_start', tournament.registration_start ? tournament.registration_start.replace(' ', 'T') : '');
                safeSetValue('edit_registration_end', tournament.registration_end ? tournament.registration_end.replace(' ', 'T') : '');
                safeSetValue('edit_start_date', tournament.start_date ? tournament.start_date.replace(' ', 'T') : '');
                
                const previewContainer = document.getElementById('edit_image_preview');
                if (previewContainer) {
                    if (tournament.image_path) {
                        previewContainer.innerHTML = `<img src="../assets/${tournament.image_path}" class="h-20 w-auto rounded-lg border border-slate-200 object-cover mt-1">`;
                    } else {
                        previewContainer.innerHTML = `<span class="text-xs text-slate-400 italic">ยังไม่มีรูปภาพ</span>`;
                    }
                }

                const modal = document.getElementById('editModal');
                if (modal) {
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                }
            } catch (e) {
                console.error("เกิดข้อผิดพลาดในการโหลดข้อมูลทัวร์นาเมนต์: ", e);
                alert("ไม่สามารถเปิดหน้าต่างแก้ไขได้ โปรดตรวจสอบความถูกต้องของข้อมูล");
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
            document.getElementById('editModal').classList.remove('flex');
        }

        let currentResultTid = null;
        let currentResultName = '';
        let currentIsUnder18 = false;

        function openResultModal(tournamentId, tournamentName, gameName) {
            currentResultTid = tournamentId;
            currentResultName = tournamentName;
            currentIsUnder18 = (gameName.toLowerCase().includes('ต่ำกว่า 18') || tournamentName.toLowerCase().includes('ต่ำกว่า 18'));

            document.getElementById('modalTournamentTitle').innerText = 'สรุปคะแนน: ' + tournamentName;
            
            const categoryTabContainer = document.getElementById('resultCategoryTabs');
            if (!currentIsUnder18) {
                categoryTabContainer.style.display = 'none';
            } else {
                categoryTabContainer.style.display = 'flex';
            }

            loadResultData(currentIsUnder18 ? 'all' : 'open');

            document.getElementById('resultModal').classList.remove('hidden');
            document.getElementById('resultModal').classList.add('flex');
        }

        function filterResultCategory(category) {
            ['all', 'male', 'female', 'open'].forEach(cat => {
                const btn = document.getElementById('tab_res_' + cat);
                if (btn) {
                    if (cat === category) {
                        btn.className = "px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-orange text-white shadow-sm";
                    } else {
                        btn.className = "px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200";
                    }
                }
            });
            loadResultData(category);
        }

        function loadResultData(category) {
            const contentDiv = document.getElementById('resultContent');
            contentDiv.innerHTML = '<div class="p-8 text-center text-slate-400 text-xs"><i class="fa-solid fa-spinner fa-spin text-lg mb-2"></i> กำลังโหลดข้อมูล...</div>';

            fetch(`?ajax_get_results=${currentResultTid}&category=${category}`)
                .then(res => res.text())
                .then(html => {
                    contentDiv.innerHTML = html;
                });
        }

        function closeResultModal() {
            document.getElementById('resultModal').classList.add('hidden');
            document.getElementById('resultModal').classList.remove('flex');
            document.getElementById('resultContent').innerHTML = '';
        }
    </script>
</head>
<body class="text-slate-800 font-sans min-h-screen flex antialiased">

    <!-- SIDEBAR -->
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
            <a href="manage-tournament.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-trophy w-5 text-center text-brand-orange"></i>
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
            <a href="record-match.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-pen-to-square w-5 text-center"></i>
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
                    จัดการทัวร์นาเมนต์ <span class="text-brand-orange">(TOURNAMENT MANAGEMENT)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">สร้าง ปิดรับสมัคร แก้ไข และจัดตารางการแข่งขัน</p>
            </div>
            <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
            </a>
        </header>

        <main class="p-8 space-y-8 flex-1">
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

            <div class="flex items-center justify-between bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div>
                    <h2 class="text-base font-bold font-display text-slate-900">รายการทัวร์นาเมนต์ทั้งหมด</h2>
                    <p class="text-xs text-slate-500 mt-0.5">รุ่นอายุต่ำกว่า 18 ปี แสดงผลแยกชาย/หญิง | รุ่น Open แสดงผลแบบรวม</p>
                </div>
                <button onclick="openCreateModal()" 
                    class="px-6 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm uppercase tracking-wider transition-all duration-200 shadow-md flex items-center gap-2 cursor-pointer">
                    <i class="fa-solid fa-circle-plus"></i>
                    <span>สร้างทัวร์นาเมนต์ใหม่</span>
                </button>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="p-4">รูปภาพ</th>
                                <th class="p-4">ชื่อทัวร์นาเมนต์</th>
                                <th class="p-4">เกม</th>
                                <th class="p-4 text-center">จำนวนผู้สมัคร</th>
                                <th class="p-4 text-center">แมตช์แข่งขัน</th>
                                <th class="p-4 text-center">สถานะ</th>
                                <th class="p-4 text-right">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($tournaments)): ?>
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-slate-400 text-xs">ยังไม่มีรายการทัวร์นาเมนต์ในระบบ</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($tournaments as $t): 
                                $isUnder18 = (stripos($t['game_name'], 'ต่ำกว่า 18') !== false || stripos($t['name'], 'ต่ำกว่า 18') !== false);
                            ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="p-4">
                                    <?php if (!empty($t['image_path'])): ?>
                                        <img src="../assets/<?php echo htmlspecialchars($t['image_path']); ?>" alt="Banner" class="w-14 h-10 object-cover rounded-lg border border-slate-200 shadow-sm">
                                    <?php else: ?>
                                        <div class="w-14 h-10 rounded-lg bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-400 text-xs">
                                            <i class="fa-solid fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 font-bold text-slate-900">
                                    <?php echo htmlspecialchars($t['name']); ?>
                                    <?php if (!empty($t['prize_pool'])): ?>
                                        <span class="ml-2 text-[10px] text-amber-600 font-bold bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                                            🏆 <?php echo htmlspecialchars($t['prize_pool']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-xs">
                                    <span class="px-2.5 py-1 rounded-lg bg-slate-100 border border-slate-200 font-semibold text-slate-700">
                                        <?php echo htmlspecialchars($t['game_name'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center text-xs">
                                    <?php if ($isUnder18): ?>
                                        <div class="flex items-center justify-center gap-1.5">
                                            <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-bold border border-blue-100" title="ชาย">ชาย: <?php echo $t['count_male']; ?></span>
                                            <span class="px-2 py-0.5 rounded bg-pink-50 text-pink-700 font-bold border border-pink-100" title="หญิง">หญิง: <?php echo $t['count_female']; ?></span>
                                        </div>
                                        <div class="text-[10px] text-slate-400 mt-1">รวมทั้งหมด: <b class="text-slate-700"><?php echo $t['team_count']; ?></b> / <?php echo $t['max_teams']; ?></div>
                                    <?php else: ?>
                                        <div class="flex items-center justify-center">
                                            <span class="px-2.5 py-1 rounded bg-purple-50 text-purple-700 font-bold border border-purple-100">Open: <?php echo $t['team_count']; ?> / <?php echo $t['max_teams']; ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center font-bold font-display text-slate-900 text-xs">
                                    <span class="text-emerald-600"><?php echo $t['completed_matches_count']; ?></span> / <?php echo $t['total_matches_count']; ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($t['status'] == 'registration_open'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">เปิดรับสมัคร</span>
                                    <?php elseif ($t['status'] == 'ongoing'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 text-xs font-bold">กำลังแข่งขัน</span>
                                    <?php elseif ($t['status'] == 'completed'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-purple-50 text-purple-700 border border-purple-200 text-xs font-bold">แข่งจบแล้ว</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 text-xs font-bold"><?php echo htmlspecialchars($t['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right space-x-1">
                                    <a href="?export_results_csv=<?php echo $t['tournament_id']; ?>" title="CSV"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-xs font-semibold transition-all">
                                        <i class="fa-solid fa-file-csv"></i> CSV
                                    </a>
                                    
                                    <button type="button" 
                                        onclick="openEditModal(<?php echo $t['tournament_id']; ?>)" 
                                        title="แก้ไข"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 text-xs font-semibold transition-all cursor-pointer">
                                        <i class="fa-solid fa-pen-to-square"></i> แก้ไข
                                    </button>

                                    <a href="manage-teams.php?tournament_id=<?php echo $t['tournament_id']; ?>" 
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs text-slate-700 font-semibold transition-all">
                                        <i class="fa-solid fa-users"></i> ทีม
                                    </a>
                                    <?php if ($t['status'] == 'registration_open'): ?>
                                        <a href="?close_registration=<?php echo $t['tournament_id']; ?>"
                                           onclick="return confirm('ปิดรับสมัครและสร้างสายการแข่งขัน?')"
                                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition-all shadow-sm">
                                            <i class="fa-solid fa-lock"></i> ปิดสมัคร + สร้างสาย
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($t['status'] == 'ongoing' || $t['status'] == 'completed'): ?>
                                        <button onclick="openResultModal(<?php echo $t['tournament_id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>', '<?php echo htmlspecialchars(addslashes($t['game_name'])); ?>')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-xs font-semibold transition-all cursor-pointer">
                                            <i class="fa-solid fa-ranking-star"></i> ดูผล
                                        </button>
                                        <?php if ($t['status'] == 'ongoing'): ?>
                                            <a href="?mark_completed=<?php echo $t['tournament_id']; ?>"
                                               onclick="return confirm('ยืนยันจบการแข่งขัน?')"
                                               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 text-xs font-bold transition-all">
                                                <i class="fa-solid fa-flag-checkered"></i> จบการแข่งขัน
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="?delete_tournament=<?php echo $t['tournament_id']; ?>"
                                        onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบทัวร์นาเมนต์นี้?')"
                                        class="inline-flex items-center gap-1 px-2 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-semibold transition-all" title="ลบ">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- CREATE MODAL -->
    <div id="createModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <h3 class="text-lg font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-circle-plus text-brand-orange"></i> สร้างทัวร์นาเมนต์ใหม่
                </h3>
                <button type="button" onclick="closeCreateModal()" class="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ชื่อทัวร์นาเมนต์</label>
                    <input type="text" name="name" required placeholder="เช่น Korat Esport Championship"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ประเภทและเกมที่ใช้แข่ง</label>
                        <select name="game_id" id="create_game_id" onchange="autoFillRules(this, 'create_rules')" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <option value="">-- เลือกประเภท / เกมการแข่งขัน --</option>
                            <optgroup label="🏆 ประเภททีม (Team Categories)">
                                <option value="<?php echo getGameId($games, 'Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี'); ?>" data-game-name="Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี">Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี</option>
                                <option value="<?php echo getGameId($games, 'Arena of Valor (RoV) - รุ่น Open'); ?>" data-game-name="Arena of Valor (RoV) - รุ่น Open">Arena of Valor (RoV) - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Free Fire - รุ่น Open'); ?>" data-game-name="Free Fire - รุ่น Open">Free Fire - รุ่น Open</option>
                            </optgroup>
                            <optgroup label="👤 ประเภทบุคคล / เกมเดี่ยว (Individual Categories)">
                                <option value="<?php echo getGameId($games, 'Tekken 8 - รุ่น Open'); ?>" data-game-name="Tekken 8 - รุ่น Open">Tekken 8 - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Street Fighter 6 - รุ่น Open'); ?>" data-game-name="Street Fighter 6 - รุ่น Open">Street Fighter 6 - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Efootball Mobile - รุ่น Open'); ?>" data-game-name="Efootball Mobile - รุ่น Open">Efootball Mobile - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Roblox - รุ่นอายุ 8-12 ปี'); ?>" data-game-name="Roblox - รุ่นอายุ 8-12 ปี">Roblox - รุ่นอายุ 8-12 ปี</option>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปแบบการแข่งขัน</label>
                        <select name="format" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <option value="single_elimination">Single Elimination (แพ้คัดออก)</option>
                            <option value="double_elimination">Double Elimination (แพ้ได้ 1 ครั้ง)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">Best of (จำนวนเกมต่อแมตช์)</label>
                    <select name="best_of" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                        <option value="3">BO3 (ชนะ 2 ใน 3 เกม)</option>
                        <option value="5" selected>BO5 (ชนะ 3 ใน 5 เกม)</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">จำนวนทีมสูงสุด</label>
                        <input type="number" name="max_teams" min="2" value="8" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เงินรางวัลรวม</label>
                        <input type="text" name="prize_pool" placeholder="เช่น 50,000 บาท" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_start" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_end" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเริ่มแข่งขัน</label>
                        <input type="datetime-local" name="start_date" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">กฎระเบียบและกติกาการแข่งขัน</label>
                    <textarea name="rules" id="create_rules" rows="10" placeholder="เลือกเกมด้านบนเพื่อใส่กฎอัตโนมัติ หรือพิมพ์เพิ่มเติม..."
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium leading-relaxed"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปภาพทัวร์นาเมนต์</label>
                    <input type="file" name="tournament_image" accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-xs">
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeCreateModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-xs cursor-pointer">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-orange text-white font-bold text-sm uppercase cursor-pointer">สร้างทัวร์นาเมนต์</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <h3 class="text-lg font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-brand-orange"></i> แก้ไขข้อมูลทัวร์นาเมนต์
                </h3>
                <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="tournament_id" id="edit_tournament_id">

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ชื่อทัวร์นาเมนต์</label>
                    <input type="text" name="name" id="edit_name" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ประเภทและเกมที่ใช้แข่ง</label>
                        <select name="game_id" id="edit_game_id" onchange="autoFillRules(this, 'edit_rules')" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <optgroup label="🏆 ประเภททีม (Team Categories)">
                                <option value="<?php echo getGameId($games, 'Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี'); ?>" data-game-name="Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี">Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี</option>
                                <option value="<?php echo getGameId($games, 'Arena of Valor (RoV) - รุ่น Open'); ?>" data-game-name="Arena of Valor (RoV) - รุ่น Open">Arena of Valor (RoV) - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Free Fire - รุ่น Open'); ?>" data-game-name="Free Fire - รุ่น Open">Free Fire - รุ่น Open</option>
                            </optgroup>
                            <optgroup label="👤 ประเภทบุคคล / เกมเดี่ยว (Individual Categories)">
                                <option value="<?php echo getGameId($games, 'Tekken 8 - รุ่น Open'); ?>" data-game-name="Tekken 8 - รุ่น Open">Tekken 8 - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Street Fighter 6 - รุ่น Open'); ?>" data-game-name="Street Fighter 6 - รุ่น Open">Street Fighter 6 - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Efootball Mobile - รุ่น Open'); ?>" data-game-name="Efootball Mobile - รุ่น Open">Efootball Mobile - รุ่น Open</option>
                                <option value="<?php echo getGameId($games, 'Roblox - รุ่นอายุ 8-12 ปี'); ?>" data-game-name="Roblox - รุ่นอายุ 8-12 ปี">Roblox - รุ่นอายุ 8-12 ปี</option>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปแบบการแข่งขัน</label>
                        <select name="format" id="edit_format" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <option value="single_elimination">Single Elimination (แพ้คัดออก)</option>
                            <option value="double_elimination">Double Elimination (แพ้ได้ 1 ครั้ง)</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">Best of (จำนวนเกมต่อแมตช์)</label>
                    <select name="best_of" id="edit_best_of" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                        <option value="3">BO3 (ชนะ 2 ใน 3 เกม)</option>
                        <option value="5">BO5 (ชนะ 3 ใน 5 เกม)</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">จำนวนทีมสูงสุด</label>
                        <input type="number" name="max_teams" id="edit_max_teams" min="2" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เงินรางวัลรวม</label>
                        <input type="text" name="prize_pool" id="edit_prize_pool" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_start" id="edit_registration_start" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_end" id="edit_registration_end" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเริ่มแข่งขัน</label>
                        <input type="datetime-local" name="start_date" id="edit_start_date" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">กฎระเบียบและกติกาการแข่งขัน</label>
                    <textarea name="rules" id="edit_rules" rows="10" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium leading-relaxed"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปภาพทัวร์นาเมนต์ปัจจุบัน</label>
                    <div id="edit_image_preview" class="mb-2"></div>
                    <input type="file" name="tournament_image" accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-xs">
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-xs cursor-pointer">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-orange text-white font-bold text-sm uppercase cursor-pointer">บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>

    <!-- RESULT MODAL -->
    <div id="resultModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <h3 id="modalTournamentTitle" class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-ranking-star text-brand-orange"></i> สรุปคะแนน
                </h3>
                <button onclick="closeResultModal()" class="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            
            <!-- TAB กรองประเภทใน Modal ผลคะแนน -->
            <div id="resultCategoryTabs" class="flex items-center gap-2 border-b border-slate-100 pb-3">
                <span class="text-xs font-bold text-slate-500 uppercase mr-2">เลือกดูประเภท:</span>
                <button onclick="filterResultCategory('all')" id="tab_res_all" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-orange text-white shadow-sm">ทั้งหมด</button>
                <button onclick="filterResultCategory('male')" id="tab_res_male" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200">ชาย</button>
                <button onclick="filterResultCategory('female')" id="tab_res_female" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200">หญิง</button>
                <button onclick="filterResultCategory('open')" id="tab_res_open" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200">Open</button>
            </div>

            <div id="resultContent" class="overflow-x-auto min-h-[150px]"></div>

            <div class="flex justify-end pt-2 border-t border-slate-100">
                <button onclick="closeResultModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-xs cursor-pointer">ปิด</button>
            </div>
        </div>
    </div>
</body>
</html>