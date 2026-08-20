<?php
// admin/record-match.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/ranking.php';
require_once '../includes/bracket.php';
require_once '../includes/tournament_categories.php';
requireRole('admin');

$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$filterCategory = $_GET['category'] ?? 'open';
$teamSearch = trim($_GET['team_search'] ?? '');
$error = '';
$success = '';

ensureDoubleElimSchema($pdo);
ensureTournamentCategorySchema($pdo);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'save_score') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $matchId = (int) $_POST['match_id'];

        $checkStmt = $pdo->prepare("SELECT status, tournament_id FROM matches WHERE match_id = :id");
        $checkStmt->execute(['id' => $matchId]);
        $matchOwnership = $checkStmt->fetch();
        $currentMatchStatus = $matchOwnership['status'] ?? false;

        if (!$matchOwnership || (int) $matchOwnership['tournament_id'] !== $tournamentId) {
            $error = 'Match นี้ไม่อยู่ใน Tournament ที่กำลังจัดการ';
        } elseif ($currentMatchStatus == 'completed' || $currentMatchStatus == 'walkover') {
            $error = 'แมตช์นี้ถูกบันทึกผลการแข่งขันไปแล้ว ไม่สามารถบันทึกซ้ำได้';
        } else {
            $fmtStmt = $pdo->prepare("
                SELECT t.format, m.team1_id, m.team2_id, m.tournament_id, m.bracket_type, m.best_of
                FROM matches m JOIN tournaments t ON t.tournament_id = m.tournament_id
                WHERE m.match_id = :id
            ");
            $fmtStmt->execute(['id' => $matchId]);
            $matchInfo = $fmtStmt->fetch();

            $bestOf = max(1, (int) ($matchInfo['best_of'] ?? 1));

            if ($bestOf <= 1) {
                $score1 = (int) ($_POST['score1'] ?? 0);
                $score2 = (int) ($_POST['score2'] ?? 0);

                if ($score1 == $score2) {
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
                        $pdo->prepare("UPDATE tournaments SET status = 'ongoing' WHERE tournament_id = :tournament_id AND status = 'bracket_generated'")
                            ->execute(['tournament_id' => $tournamentId]);

                        if (function_exists('updateRankingsAfterMatch')) {
                            try { @updateRankingsAfterMatch($pdo, $matchId); } catch (Exception $ex) {}
                        }
                        if ($winnerId) {
                            try { @advanceMatchResult($pdo, $matchId, $winnerId, $loserId); } catch (Exception $ex) {}
                        }

                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                        $success = 'บันทึกผลการแข่งขันเรียบร้อยแล้ว';
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error = 'บันทึกผลไม่สำเร็จ: ' . $e->getMessage();
                    }
                }
            } else {
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

                    if ($gs1 == $gs2) {
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
                        $pdo->prepare("UPDATE tournaments SET status = 'ongoing' WHERE tournament_id = :tournament_id AND status = 'bracket_generated'")
                            ->execute(['tournament_id' => $tournamentId]);

                        if (function_exists('updateRankingsAfterMatch')) {
                            try { @updateRankingsAfterMatch($pdo, $matchId); } catch (Exception $ex) {}
                        }
                        try { @advanceMatchResult($pdo, $matchId, $winnerId, $loserId); } catch (Exception $ex) {}

                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                        $success = "บันทึกผล Best of {$bestOf} เรียบร้อยแล้ว ({$team1GamesWon}-{$team2GamesWon})";
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_schedule') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $matchId = (int) ($_POST['match_id'] ?? 0);
        $scheduleCheck = $pdo->prepare('SELECT tournament_id FROM matches WHERE match_id = :match_id');
        $scheduleCheck->execute(['match_id' => $matchId]);
        if ((int) $scheduleCheck->fetchColumn() !== $tournamentId) {
            $error = 'Match นี้ไม่อยู่ใน Tournament ที่กำลังจัดการ';
        } else {
            $pdo->prepare('UPDATE matches SET scheduled_at = :scheduled_at, venue_name = :venue_name, venue_area = :venue_area
                WHERE match_id = :match_id')->execute([
                'scheduled_at' => $_POST['scheduled_at'] !== '' ? str_replace('T', ' ', $_POST['scheduled_at']) : null,
                'venue_name' => trim($_POST['venue_name'] ?? '') ?: null,
                'venue_area' => trim($_POST['venue_area'] ?? '') ?: null,
                'match_id' => $matchId,
            ]);
            $success = 'บันทึกวัน เวลา และสนามของ Match แล้ว';
        }
    }
}

$tournaments = $pdo->query("
    SELECT tournament_id, name, format 
    FROM tournaments 
    WHERE status IN ('ongoing', 'bracket_generated')
    ORDER BY name
")->fetchAll();

$matches = [];
$groupedMatches = [];
$currentFormat = '';

if ($tournamentId) {
    $fStmt = $pdo->prepare("SELECT format FROM tournaments WHERE tournament_id = :id");
    $fStmt->execute(['id' => $tournamentId]);
    $currentFormat = $fStmt->fetchColumn();

    // ดึงข้อมูลแมตช์พร้อมผูก category จากตาราง tournament_registrations หรือ teams โดยตรงอย่างถูกต้อง
    $sql = "
        SELECT m.*, 
               COALESCE(t1.name, u1.username, 'รอผู้ชนะรอบก่อน') AS team1_name, 
               COALESCE(t2.name, u2.username, 'รอผู้ชนะรอบก่อน') AS team2_name, 
               COALESCE(tr1.category, t1.team_category, 'open') AS team1_cat,
               COALESCE(tr2.category, t2.team_category, 'open') AS team2_cat,
               tg.name AS group_name,
               m.tournament_category_id AS match_category_id
        FROM matches m
        JOIN tournaments t ON t.tournament_id = m.tournament_id
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN players p1 ON p1.player_id = m.team1_id
        LEFT JOIN users u1 ON u1.user_id = p1.user_id
        LEFT JOIN tournament_registrations tr1 ON tr1.tournament_id = m.tournament_id AND tr1.team_id = m.team1_id
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        LEFT JOIN players p2 ON p2.player_id = m.team2_id
        LEFT JOIN users u2 ON u2.user_id = p2.user_id
        LEFT JOIN tournament_registrations tr2 ON tr2.tournament_id = m.tournament_id AND tr2.team_id = m.team2_id
        LEFT JOIN tournament_groups tg ON tg.tournament_group_id = m.group_id
        WHERE m.tournament_id = :tid AND m.status != 'cancelled'
    ";
    $params = ['tid' => $tournamentId];
    $selectedCategoryId = $filterCategory !== 'all' ? getTournamentCategoryId($pdo, $tournamentId, $filterCategory) : null;

    if ($teamSearch !== '') {
        $sql .= " AND (t1.name LIKE :search OR u1.username LIKE :search OR t2.name LIKE :search OR u2.username LIKE :search)";
        $params['search'] = "%{$teamSearch}%";
    }

    $sql .= " ORDER BY m.status ASC, m.round_number ASC, m.match_index ASC";

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

    // หากกรองแล้วไม่พบผลลัพธ์แต่ไม่มีการค้นหา ให้ดึงทั้งหมดมาแสดงป้องกันหน้าจอว่างเปล่า
    if (empty($matches) && empty($teamSearch) && $filterCategory !== 'all') {
        $matches = $rawMatches;
    }

    foreach ($matches as $m) {
        $bt = $m['bracket_type'] ?? 'single';
        $catLabel = '';
        $c1 = strtolower($m['team1_cat'] ?? 'open');
        
        if (strpos($bt, 'male') !== false || $c1 === 'male') $catLabel = ' [ประเภททีมชาย]';
        elseif (strpos($bt, 'female') !== false || $c1 === 'female') $catLabel = ' [ประเภททีมหญิง]';
        else $catLabel = ' [ประเภท Open]';

        $groupKey = 'รอบการแข่งขันนัดที่ ' . $m['round_number'] . $catLabel;
        $groupedMatches[$groupKey][] = $m;
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกผลแมตช์ - Korat Esport</title>
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
                    บันทึกผลแมตช์ <span class="text-brand-orange">(RECORD MATCH SCORE)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">เลือกรายการแข่งขัน แยกประเภท กรองชื่อทีม และบันทึกผลการแข่งขัน</p>
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
                    <?php endif; ?>
                </form>

                <?php if ($tournamentId): ?>
                    <div class="flex items-center gap-2 pt-2 border-t border-slate-100">
                        <span class="text-xs font-bold text-slate-500 uppercase mr-2"><i class="fa-solid fa-layer-group text-brand-orange mr-1"></i> กรองประเภท:</span>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=all" class="px-4 py-1.5 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'all') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">ทั้งหมด</a>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=male" class="px-4 py-1.5 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'male') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">ทีมชาย</a>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=female" class="px-4 py-1.5 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'female') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">ทีมหญิง</a>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=open" class="px-4 py-1.5 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'open') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">Open</a>
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
                                                <th class="p-4 text-center">ผลการแข่งขัน / บันทึกผล</th>
                                                <th class="p-4 text-center">สถานะ</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($roundMatches as $m): ?>
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
                                                    <?php elseif ($m['team1_id'] && $m['team2_id']): ?>
                                                        <?php $mBestOf = max(1, (int) ($m['best_of'] ?? 1)); ?>
                                                        <?php if ($mBestOf <= 1): ?>
                                                            <form method="POST" class="inline-flex items-center gap-2">
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
                                                            <form method="POST" class="inline-flex flex-col items-center gap-1.5">
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
                                                    <form method="POST" class="mt-2 space-y-1 text-left">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="save_schedule">
                                                        <input type="hidden" name="match_id" value="<?php echo (int) $m['match_id']; ?>">
                                                        <input type="datetime-local" name="scheduled_at" value="<?php echo !empty($m['scheduled_at']) ? htmlspecialchars(str_replace(' ', 'T', substr($m['scheduled_at'], 0, 16))) : ''; ?>" class="w-full rounded border border-slate-200 px-1 py-1 text-[10px]">
                                                        <input type="text" name="venue_name" value="<?php echo htmlspecialchars($m['venue_name'] ?? ''); ?>" placeholder="สนาม" class="w-full rounded border border-slate-200 px-1 py-1 text-[10px]">
                                                        <input type="text" name="venue_area" value="<?php echo htmlspecialchars($m['venue_area'] ?? ''); ?>" placeholder="พื้นที่" class="w-full rounded border border-slate-200 px-1 py-1 text-[10px]">
                                                        <button type="submit" class="w-full rounded bg-slate-100 px-1 py-1 text-[10px] font-bold text-slate-700">บันทึก Schedule</button>
                                                    </form>
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
</body>
</html>     