<?php
// pages/ranking.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/ranking.php';

$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];
ensureRankingHistoryTable($pdo);
$rankingHistoryColumns = $pdo->query('SHOW COLUMNS FROM ranking_history')->fetchAll(PDO::FETCH_COLUMN);
$rankingResultColumn = in_array('result_code', $rankingHistoryColumns, true)
    ? 'rh.result_code'
    : (in_array('reason', $rankingHistoryColumns, true) ? 'rh.reason' : "''");

function rankingCategoryLabel(string $category): string
{
    return match (strtolower(trim($category))) {
        'male' => 'ชาย',
        'female' => 'หญิง',
        'open' => 'ทั่วไป',
        default => $category,
    };
}

// ดึงรายการเกมจากตาราง games แบบไม่ให้แสดงชื่อซ้ำกัน และใช้ play_mode เป็นแหล่งข้อมูลจริง
$rawGames = $pdo->query("SELECT game_id, name, play_mode FROM games WHERE is_active = 1 ORDER BY game_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$games = [];
$seenNames = [];
foreach ($rawGames as $g) {
    $cleanName = trim($g['name']);
    $lowerName = mb_strtolower($cleanName, 'UTF-8');
    if (!isset($seenNames[$lowerName])) {
        $seenNames[$lowerName] = true;
        $games[] = [
            'game_id' => $g['game_id'],
            'name' => $cleanName,
            'play_mode' => in_array(strtolower((string) ($g['play_mode'] ?? '')), ['team', 'solo'], true) ? strtolower((string) $g['play_mode']) : 'team'
        ];
    }
}

$topTeams = $pdo->query("
    SELECT tr.points, tr.wins, tr.losses, t.team_id, t.name AS team_name, g.name AS game_name
    FROM team_rankings tr
    JOIN teams t ON t.team_id = tr.team_id
    JOIN games g ON g.game_id = tr.game_id
    ORDER BY tr.points DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$topPlayers = $pdo->query("
    SELECT pr.points, pr.wins, pr.losses, p.player_id, p.display_name, g.name AS game_name
    FROM player_rankings pr
    JOIN players p ON p.player_id = pr.player_id
    JOIN games g ON g.game_id = pr.game_id
    ORDER BY pr.points DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$gameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : (!empty($games) ? $games[0]['game_id'] : 0);
$selectedGameName = '';
$selectedGameStmt = $pdo->prepare('SELECT name FROM games WHERE game_id = :game_id LIMIT 1');
$selectedGameStmt->execute(['game_id' => $gameId]);
$selectedGameName = trim((string) $selectedGameStmt->fetchColumn());
$gameFamilyName = trim((string) preg_replace('/\s*-\s*รุ่น.*$/u', '', $selectedGameName));
$rankingGameIds = [$gameId];
if ($gameFamilyName !== '') {
    $familyStmt = $pdo->prepare('SELECT game_id FROM games WHERE name LIKE :family_name');
    $familyStmt->execute(['family_name' => $gameFamilyName . '%']);
    $rankingGameIds = array_values(array_unique(array_map('intval', $familyStmt->fetchAll(PDO::FETCH_COLUMN))));
}
$rankingGameIdList = implode(', ', array_filter($rankingGameIds, static fn (int $id): bool => $id > 0));
$gamePlayMode = 'team';
if ($gameId > 0) {
    $gameModeStmt = $pdo->prepare('SELECT play_mode FROM games WHERE game_id = :game_id LIMIT 1');
    $gameModeStmt->execute(['game_id' => $gameId]);
    $gameModeValue = strtolower(trim((string) $gameModeStmt->fetchColumn()));
    $gamePlayMode = in_array($gameModeValue, ['team', 'solo'], true) ? $gameModeValue : 'team';
}

$requestedType = isset($_GET['type']) ? strtolower((string) $_GET['type']) : '';
$type = ($gamePlayMode === 'solo') ? 'player' : (in_array($requestedType, ['team', 'player'], true) ? $requestedType : 'team');
if ($gamePlayMode === 'solo' && $type !== 'player') {
    $type = 'player';
}

$category = isset($_GET['category']) ? trim((string) $_GET['category']) : 'all';
$isOpenGame = stripos($selectedGameName, 'รุ่น Open') !== false;
if ($isOpenGame) {
    // รุ่น Open ไม่แยกประเภท และไม่ใช้คะแนนจากเกมรุ่นชาย/หญิงมาปนกัน
    $category = 'all';
} elseif ($category === 'open') {
    $category = 'all';
}
$search = trim($_GET['search'] ?? '');
$categoryOptions = $isOpenGame ? null : $pdo->prepare($type === 'player'
    ? "SELECT DISTINCT CASE WHEN LOWER(TRIM(category)) IN ('male', 'female') THEN LOWER(TRIM(category)) ELSE 'open' END AS category
       FROM player_rankings WHERE game_id IN ($rankingGameIdList) AND LOWER(TRIM(category)) <> 'open' ORDER BY category"
    : "SELECT DISTINCT CASE WHEN LOWER(TRIM(category)) IN ('male', 'female') THEN LOWER(TRIM(category)) ELSE 'open' END AS category
       FROM team_rankings WHERE game_id IN ($rankingGameIdList) AND LOWER(TRIM(category)) <> 'open' ORDER BY category");
$categoryOptions = $categoryOptions ? (function ($stmt) {
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
})($categoryOptions) : [];

$rankings = [];

if ($type === 'team') {
    if ($gameId > 0) {
        // ตารางนี้เก็บคะแนนสะสมแยกตามเกมและประเภทไว้แล้ว จึงไม่ควรรวมจากประวัติแมตช์
        if ($isOpenGame) {
            $sql = "
                SELECT t.team_id, t.name AS team_name, t.logo_path, 'open' AS team_category,
                       COALESCE(SUM(rh.points), 0) AS total_points,
                       SUM(rh.matches_played) AS matches_played,
                       SUM(rh.wins) AS wins, SUM(rh.losses) AS losses
                FROM team_rankings rh
                JOIN teams t ON t.team_id = rh.team_id
                WHERE rh.game_id IN ($rankingGameIdList)
                  AND LOWER(TRIM(rh.category)) = 'open'
            ";
            $params = [];
            if ($search !== '') {
                $sql .= " AND t.name LIKE :search";
                $params['search'] = "%{$search}%";
            }
            $sql .= " GROUP BY t.team_id, t.name, t.logo_path ORDER BY total_points DESC, wins DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            goto rankings_loaded;
        }
        $teamCategorySql = $isOpenGame
            ? "'open'"
            : "CASE WHEN LOWER(TRIM(tr.category)) IN ('male', 'female') THEN LOWER(TRIM(tr.category)) ELSE 'open' END";
        $sql = "
            SELECT t.team_id, t.name AS team_name, t.logo_path,
                   $teamCategorySql AS team_category,
                   SUM(tr.points) AS total_points, SUM(tr.matches_played) AS matches_played,
                   SUM(tr.wins) AS wins, SUM(tr.losses) AS losses
            FROM team_rankings tr
            JOIN teams t ON t.team_id = tr.team_id
            WHERE tr.game_id IN ($rankingGameIdList)
        ";
        $params = [];

        if ($category !== 'all' && !empty($category)) {
            $sql .= " AND CASE WHEN LOWER(TRIM(tr.category)) IN ('male', 'female') THEN LOWER(TRIM(tr.category)) ELSE 'open' END = :category";
            $params['category'] = $category;
        }
        if (!$isOpenGame) {
            $sql .= " AND LOWER(TRIM(tr.category)) <> 'open'";
        }

        if ($search !== '') {
            $sql .= " AND t.name LIKE :search";
            $params['search'] = "%{$search}%";
        }

        $sql .= " GROUP BY t.team_id, t.name, t.logo_path, $teamCategorySql";
        $sql .= " ORDER BY total_points DESC, wins DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        rankings_loaded:
    }
} else {
    // โหมดอันดับผู้เล่น ใช้คะแนนสะสมที่แยกตาม Category เช่นเดียวกับอันดับทีม
    if ($gameId > 0) {
        if ($isOpenGame) {
            $sql = "
                SELECT p.player_id, p.display_name, p.avatar_path, 'open' AS category,
                       COALESCE(SUM(rh.points), 0) AS total_points,
                       SUM(rh.matches_played) AS matches_played,
                       SUM(rh.wins) AS wins, SUM(rh.losses) AS losses
                FROM player_rankings rh
                JOIN players p ON p.player_id = rh.player_id
                WHERE rh.game_id IN ($rankingGameIdList)
                  AND LOWER(TRIM(rh.category)) = 'open'
            ";
            $params = [];
            if ($search !== '') {
                $sql .= " AND p.display_name LIKE :search";
                $params['search'] = "%{$search}%";
            }
            $sql .= " GROUP BY p.player_id, p.display_name, p.avatar_path ORDER BY total_points DESC, wins DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            goto rankings_loaded_players;
        }
        $playerCategorySql = $isOpenGame
            ? "'open'"
            : "CASE WHEN LOWER(TRIM(pr.category)) IN ('male', 'female') THEN LOWER(TRIM(pr.category)) ELSE 'open' END";
        $sql = "
            SELECT p.player_id, p.display_name, p.avatar_path,
                   $playerCategorySql AS category,
                   SUM(pr.points) AS total_points, SUM(pr.matches_played) AS matches_played,
                   SUM(pr.wins) AS wins, SUM(pr.losses) AS losses
            FROM player_rankings pr
            JOIN players p ON p.player_id = pr.player_id
            WHERE pr.game_id IN ($rankingGameIdList)
        ";
        $params = [];

        if ($search !== '') {
            $sql .= " AND p.display_name LIKE :search";
            $params['search'] = "%{$search}%";
        }

        if ($category !== 'all' && $category !== '') {
            $sql .= " AND CASE WHEN LOWER(TRIM(pr.category)) IN ('male', 'female') THEN LOWER(TRIM(pr.category)) ELSE 'open' END = :category";
            $params['category'] = $category;
        }
        if (!$isOpenGame) {
            $sql .= " AND LOWER(TRIM(pr.category)) <> 'open'";
        }

        $sql .= " GROUP BY p.player_id, p.display_name, p.avatar_path, $playerCategorySql";
        $sql .= " ORDER BY total_points DESC, wins DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rankings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        rankings_loaded_players:
    }
}

$rankingRowsPerPage = 10;
$rankingPage = max(1, (int) ($_GET['ranking_page'] ?? 1));
$rankingListCount = max(0, count($rankings) - 3);
$rankingPageCount = max(1, (int) ceil($rankingListCount / $rankingRowsPerPage));
$rankingPage = min($rankingPage, $rankingPageCount);
$rankingRows = array_slice($rankings, 3 + (($rankingPage - 1) * $rankingRowsPerPage), $rankingRowsPerPage);
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตารางคะแนนและอันดับ - Korat Esport</title>
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
                            glow: '#FF7700',
                            dark: '#0A0A0C',
                            panel: '#121318'
                        }
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                        display: ['Orbitron', 'sans-serif']
                    },
                    boxShadow: {
                        'orange-glow': '0 0 35px rgba(255, 85, 0, 0.5)',
                        'gold-glow': '0 0 30px rgba(245, 158, 11, 0.6)',
                        'silver-glow': '0 0 25px rgba(226, 232, 240, 0.4)',
                        'bronze-glow': '0 0 25px rgba(217, 119, 6, 0.4)'
                    }
                }
            }
        }
    </script>

    <style>
        ::-webkit-scrollbar { display: none; }
        html, body { -ms-overflow-style: none; scrollbar-width: none; }
        body { background-color: #0F1117; color: #f3f4f6; }
        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.45), rgba(15, 17, 23, 0.85)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover; background-position: center; background-attachment: fixed;
        }
        .glass-nav {
            background: rgba(15, 17, 23, 0.88);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 85, 0, 0.3);
        }
        .glass-panel {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }
        .grid-bg {
            background-image: radial-gradient(rgba(255, 85, 0, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes floatCrown {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-5px) rotate(3deg); }
        }
        .animate-fade-down { animation: fadeInDown 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .animate-fade-up { animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards; opacity: 0; }
        .crown-float { animation: floatCrown 3s ease-in-out infinite; }

        @keyframes podiumEntrance {
            0% { opacity: 0; transform: translateY(40px) scale(0.95); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        .podium-animate-3 { animation: podiumEntrance 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.1s forwards; opacity: 0; }
        .podium-animate-2 { animation: podiumEntrance 0.7s cubic-bezier(0.16, 1, 0.3, 1) 0.25s forwards; opacity: 0; }
        .podium-animate-1 { animation: podiumEntrance 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.4s forwards; opacity: 0; }

        @keyframes sparkleSweep {
            0% { transform: translateX(-100%) rotate(30deg); opacity: 0; }
            20% { opacity: 0.6; }
            40% { transform: translateX(200%) rotate(30deg); opacity: 0; }
            100% { transform: translateX(200%) rotate(30deg); opacity: 0; }
        }
        .champion-sparkle {
            position: absolute; top: 0; left: 0; width: 50%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
            pointer-events: none; animation: sparkleSweep 5s infinite ease-in-out;
        }

        .podium-card {
            background: linear-gradient(135deg, rgba(20, 21, 28, 0.9), rgba(10, 10, 12, 0.95));
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative; overflow: hidden; text-decoration: none; display: block;
        }
        .podium-card:hover {
            transform: translateY(-8px) scale(1.02);
            border-color: #FF5500;
            box-shadow: 0 15px 35px -10px rgba(255, 85, 0, 0.5);
        }
        .podium-1 { border-color: rgba(245, 158, 11, 0.8); box-shadow: 0 0 35px rgba(245, 158, 11, 0.3); }
        .podium-2 { border-color: rgba(226, 232, 240, 0.6); box-shadow: 0 0 25px rgba(226, 232, 240, 0.2); }
        .podium-3 { border-color: rgba(217, 119, 6, 0.6); box-shadow: 0 0 25px rgba(217, 119, 6, 0.2); }

        @keyframes rowFadeIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .ranking-row {
            animation: rowFadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0; transition: all 0.3s ease;
        }
        .ranking-row:hover {
            transform: translateX(6px);
            background: rgba(255, 85, 0, 0.08);
            border-left: 4px solid #FF5500;
        }
        .win-rate-bar { width: 0%; transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1); }

        @media (max-width: 1023px) {
            .podium-card,
            .podium-card h3,
            .glass-panel,
            section,
            section > div {
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }

            .podium-card {
                width: 100%;
            }

            .glass-panel select {
                width: 100%;
                min-width: 0;
            }
        }

        @media (max-width: 767px) {
            .glass-panel table {
                table-layout: fixed;
                width: 100%;
            }

            .glass-panel table th,
            .glass-panel table td {
                padding: 0.75rem 0.35rem;
                overflow: hidden;
            }

            .glass-panel table th:first-child,
            .glass-panel table td:first-child {
                width: 15%;
            }

            .glass-panel table th:nth-child(2),
            .glass-panel table td:nth-child(2) {
                width: 43%;
            }

            .glass-panel table th:nth-child(3),
            .glass-panel table td:nth-child(3) {
                width: 20%;
            }

            .glass-panel table th:nth-child(4),
            .glass-panel table td:nth-child(4) {
                width: 22%;
            }

            .glass-panel table td:nth-child(2) > div {
                min-width: 0;
            }

            .glass-panel table td:last-child .font-display {
                font-size: 0.95rem;
                white-space: nowrap;
            }

            #rankingTable th:nth-child(3),
            #rankingTable td:nth-child(3),
            #rankingTable th:nth-child(4),
            #rankingTable td:nth-child(4),
            #rankingTable th:nth-child(6),
            #rankingTable td:nth-child(6) {
                display: none;
            }

            #rankingTable th:nth-child(1),
            #rankingTable td:nth-child(1) {
                width: 17%;
            }

            #rankingTable th:nth-child(2),
            #rankingTable td:nth-child(2) {
                width: 48%;
            }

            #rankingTable th:nth-child(5),
            #rankingTable td:nth-child(5) {
                width: 17%;
            }

            #rankingTable th:nth-child(7),
            #rankingTable td:nth-child(7) {
                width: 18%;
            }
        }
    </style>
</head>

<body class="font-sans min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>

    <div class="relative z-10 flex flex-col min-h-screen">

        <!-- NAVBAR -->
        <header class="sticky top-0 z-50 glass-nav transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">

                    <a href="index.php" class="flex items-center gap-3 group">
                        <img src="../assets/img/logo.png" alt="Korat Esport"
                            class="h-11 w-auto filter drop-shadow-[0_2px_8px_rgba(255,85,0,0.4)] group-hover:scale-105 transition-transform"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors drop-shadow">KORAT <span class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] tracking-widest text-gray-400 font-bold uppercase -mt-1">Official Arena & Hub</span>
                        </div>
                    </a>

                    <nav class="hidden md:flex items-center gap-1 lg:gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-house text-xs mr-1.5"></i> หน้าแรก</a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-trophy text-xs mr-1.5"></i> ทัวร์นาเมนต์</a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange transition-all shadow-orange-glow"><i class="fa-solid fa-ranking-star text-xs mr-1.5"></i> ตารางคะแนน</a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-newspaper text-xs mr-1.5"></i> ข่าวสาร</a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-images text-xs mr-1.5"></i> แกลเลอรี่</a>
                    </nav>

                    <div class="flex items-center gap-4 text-base font-bold">
                        <?php if ($isLoggedIn): ?>
                            <div class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md">
                                <div class="flex flex-col text-right">
                                    <span class="text-sm font-bold text-white leading-tight"><?= htmlspecialchars($currentUser['username'] ?? 'User') ?></span>
                                    <span class="text-[10px] font-semibold text-brand-orange uppercase tracking-wider"><?= htmlspecialchars($currentUser['role'] ?? 'Player') ?></span>
                                </div>
                                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                    <a href="../admin/dashboard.php" title="ระบบหลังบ้าน Admin" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                        <i class="fa-solid fa-user-shield text-sm"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="profile.php" title="จัดการโปรไฟล์/ทีม" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                        <i class="fa-solid fa-user-gear text-sm"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="../auth/logout.php" class="w-9 h-9 rounded-xl bg-rose-500/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 flex items-center justify-center transition-all">
                                    <i class="fa-solid fa-right-from-bracket text-sm"></i>
                                </a>
                            </div>
                        <?php else: ?>
                            <a href="../auth/login.php" class="text-brand-orange hover:text-brand-glow transition-colors">เข้าสู่ระบบ</a>
                            <a href="../auth/register.php" class="text-white hover:text-brand-orange transition-colors">สมัครสมาชิก</a>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </header>

        <!-- PAGE HEADER -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-6 w-full text-center space-y-4">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-amber-500/20 border border-amber-400/40 text-amber-300 text-xs font-bold uppercase tracking-widest backdrop-blur-md animate-fade-down shadow-gold-glow">
                <i class="fa-solid fa-crown text-amber-400 crown-float"></i> Hall of Fame & Leaderboards
            </div>
            <h1 class="text-4xl sm:text-6xl font-black font-display text-white tracking-wider uppercase leading-none drop-shadow-[0_0_35px_rgba(255,85,0,0.8)] animate-fade-down">
                อันดับตารางคะแนน <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-300 to-white">(RANKING)</span>
            </h1>
            <p class="text-sm sm:text-base text-gray-300 max-w-xl mx-auto font-normal animate-fade-up">
                สรุปอันดับคะแนนสะสม สถิติการแข่งขัน และอัตราการชนะของสโมสรและนักกีฬาประจำจังหวัดนครราชสีมา
            </p>
        </section>

        <!-- FILTER CONTROLS & SEARCH BAR -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 w-full space-y-4">
            <div class="glass-panel p-6 rounded-2xl flex flex-col md:flex-row items-center justify-between gap-6 shadow-xl">

                <!-- Games Dropdown -->
                <div class="flex items-center gap-3 w-full md:w-auto">
                    <span class="text-xs font-bold uppercase text-gray-400 shrink-0"><i class="fa-solid fa-trophy mr-1"></i> ประเภทการแข่งขัน:</span>
                    <select onchange="location = this.value;" class="bg-black/60 border border-white/25 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange cursor-pointer">
                        <?php foreach ($games as $g): ?>
                            <?php $optionType = ($g['play_mode'] === 'solo') ? 'player' : (($type === 'player') ? 'player' : 'team'); ?>
                            <option value="ranking.php?game_id=<?php echo $g['game_id']; ?>&type=<?php echo $optionType; ?>&category=<?php echo $category; ?>" <?php echo $gameId == $g['game_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-4 w-full md:w-auto justify-end">
                    <!-- Real-time Search Box -->
                    <div class="relative w-full sm:w-64">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400 text-xs">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </span>
                        <input type="text" id="rankingSearchInput" onkeyup="filterRankingTable()"
                            placeholder="ค้นหาชื่อทีม หรือผู้เล่น..."
                            value="<?php echo htmlspecialchars($search); ?>"
                            class="w-full pl-10 pr-4 py-2.5 rounded-xl bg-black/50 border border-white/15 text-white placeholder-gray-500 text-xs focus:outline-none focus:border-brand-orange transition-all shadow-inner">
                    </div>

                    <!-- Type Selector -->
                    <?php if ($gamePlayMode === 'solo'): ?>
                        <div class="flex items-center bg-black/40 p-1.5 rounded-2xl border border-white/10 shrink-0 justify-center w-full sm:w-auto">
                            <span class="px-5 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all duration-300 flex items-center gap-2 bg-white/20 text-white border border-white/30 shadow">
                                <i class="fa-solid fa-user text-amber-400"></i> อันดับผู้เล่น
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="flex items-center bg-black/40 p-1.5 rounded-2xl border border-white/10 shrink-0 justify-center w-full sm:w-auto">
                            <a href="ranking.php?game_id=<?php echo $gameId; ?>&type=team&category=<?php echo $category; ?>"
                                class="px-5 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all duration-300 flex items-center gap-2 <?php echo $type == 'team' ? 'bg-white/20 text-white border border-white/30 shadow' : 'text-gray-400 hover:text-white'; ?>">
                                <i class="fa-solid fa-shield-halved text-brand-orange"></i> อันดับทีม
                            </a>
                            <a href="ranking.php?game_id=<?php echo $gameId; ?>&type=player&category=<?php echo $category; ?>"
                                class="px-5 py-2 rounded-xl text-xs font-bold uppercase tracking-wider transition-all duration-300 flex items-center gap-2 <?php echo $type == 'player' ? 'bg-white/20 text-white border border-white/30 shadow' : 'text-gray-400 hover:text-white'; ?>">
                                <i class="fa-solid fa-user text-amber-400"></i> อันดับผู้เล่น
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Category Filter Bar -->
            <?php if ($categoryOptions): ?>
                <div class="glass-panel px-6 py-3 rounded-2xl flex flex-wrap items-center gap-2 border border-white/10">
                    <span class="text-xs font-bold text-gray-400 uppercase mr-3"><i class="fa-solid fa-filter text-brand-orange mr-1"></i> กรองประเภท:</span>
                    <a href="?game_id=<?= $gameId ?>&type=<?= $type ?>&category=all" class="px-4 py-1.5 rounded-xl text-xs font-bold transition-all <?= ($category === 'all') ? 'bg-brand-orange text-white shadow-md' : 'bg-white/10 text-gray-300 hover:bg-white/20' ?>">ทั้งหมด</a>
                    <?php foreach ($categoryOptions as $categoryOption): ?>
                        <a href="?game_id=<?= $gameId ?>&type=<?= $type ?>&category=<?= urlencode($categoryOption) ?>" class="px-4 py-1.5 rounded-xl text-xs font-bold transition-all <?= ($category === $categoryOption) ? 'bg-brand-orange text-white shadow-md' : 'bg-white/10 text-gray-300 hover:bg-white/20' ?>">                        <?= htmlspecialchars(rankingCategoryLabel((string) $categoryOption)) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- HALL OF FAME -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-8 w-full">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between border-b border-white/20 pb-4 gap-4">
                <div>
                    <span class="text-amber-400 font-bold text-xs uppercase tracking-widest block mb-1">HALL OF FAME</span>
                    <h2 class="text-3xl font-black font-display text-white uppercase tracking-wide flex items-center gap-3 drop-shadow">
                        <i class="fa-solid fa-crown text-amber-400"></i> ทำเนียบเกียรติยศ (อันดับสูงสุด)
                    </h2>
                </div>
                <span class="text-xs text-gray-400">อันดับรวมทุกเกม</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <?php foreach ([
                    ['title' => 'สโมสร / ทีมยอดเยี่ยม', 'icon' => 'fa-shield-halved', 'color' => 'brand-orange', 'rows' => $topTeams, 'name' => 'team_name', 'id' => 'team_id', 'url' => 'team-profile.php?id=', 'score' => 'points'],
                    ['title' => 'นักกีฬา / ผู้เล่นยอดเยี่ยม', 'icon' => 'fa-user-ninja', 'color' => 'amber-400', 'rows' => $topPlayers, 'name' => 'display_name', 'id' => 'player_id', 'url' => 'player-profile.php?id=', 'score' => 'points'],
                ] as $hall): ?>
                    <div class="space-y-4">
                        <h3 class="text-base font-bold font-display text-<?= $hall['color'] ?> uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid <?= $hall['icon'] ?>"></i> <?= $hall['title'] ?>
                        </h3>
                        <div class="glass-panel rounded-2xl overflow-hidden shadow-2xl">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-gray-200">
                                    <thead class="bg-black/40 text-xs uppercase font-bold text-gray-300 border-b border-white/15 font-display">
                                        <tr><th class="p-4 text-center w-16">อันดับ</th><th class="p-4"><?= $hall['name'] === 'team_name' ? 'ทีม' : 'ผู้เล่น' ?></th><th class="p-4 text-center">W - L</th><th class="p-4 text-right">คะแนน</th></tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/10 font-medium">
                                        <?php if (empty($hall['rows'])): ?><tr><td colspan="4" class="p-6 text-center text-gray-400 text-xs">ยังไม่มีข้อมูลอันดับ</td></tr><?php endif; ?>
                                        <?php foreach ($hall['rows'] as $i => $row): ?>
                                            <tr onclick="window.location.href='<?= $hall['url'] . (int) $row[$hall['id']] ?>'" class="cursor-pointer hover:bg-white/10 transition-all duration-300 <?= $i === 0 ? 'bg-amber-400/10' : '' ?>">
                                                <td class="p-4 text-center font-display font-black text-sm"><?= $i + 1 ?></td>
                                                <td class="p-4 font-bold text-white text-sm truncate max-w-[180px]"><?= htmlspecialchars($row[$hall['name']]) ?><span class="block text-[10px] text-gray-400 font-normal"><?= htmlspecialchars($row['game_name']) ?></span></td>
                                                <td class="p-4 text-center font-mono text-xs"><span class="text-emerald-400 font-bold"><?= (int) $row['wins'] ?>W</span>-<span class="text-rose-400 font-bold"><?= (int) $row['losses'] ?>L</span></td>
                                                <td class="p-4 text-right font-display font-black text-<?= $hall['color'] ?> text-base"><?= number_format((int) $row[$hall['score']]) ?> <span class="text-[10px] text-gray-300 font-normal">PTS</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- TOP 3 CYBER PODIUMS -->
        <?php if (count($rankings) > 0): ?>
            <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 w-full">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end mb-12">

                    <!-- อันดับที่ 2 (Silver) -->
                    <?php if (isset($rankings[1])):
                        $r2 = $rankings[1];
                        $name2 = ($type == 'team') ? $r2['team_name'] : $r2['display_name'];
                        $wr2 = $r2['matches_played'] > 0 ? round(($r2['wins'] / $r2['matches_played']) * 100, 1) : 0;
                        $link2 = ($type == 'team') ? 'team-profile.php?id=' . $r2['team_id'] : 'player-profile.php?id=' . $r2['player_id'];
                    ?>
                        <a href="<?php echo $link2; ?>" class="podium-card podium-2 p-6 rounded-3xl order-2 md:order-1 space-y-4 podium-animate-2">
                            <div class="flex items-center justify-between">
                                <span class="w-10 h-10 rounded-2xl bg-slate-200/20 text-slate-200 flex items-center justify-center font-display font-black text-lg border border-slate-300/40">#2</span>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold font-display text-white truncate"><?php echo htmlspecialchars($name2); ?></h3>
                                <p class="text-xs text-gray-400 mt-0.5">แข่ง <?php echo $r2['matches_played']; ?> นัด (Win Rate <?php echo $wr2; ?>%)</p>
                            </div>
                            <div class="pt-2 border-t border-white/10 flex items-center justify-between">
                                <span class="text-xs text-gray-400 uppercase font-bold">คะแนนสะสม</span>
                                <span class="font-display font-black text-slate-200 text-xl"><span class="podium-counter" data-target="<?php echo $r2['total_points']; ?>">0</span> <span class="text-xs font-normal">PTS</span></span>
                            </div>
                        </a>
                    <?php endif; ?>

                    <!-- อันดับที่ 1 (Gold Champion) -->
                    <?php if (isset($rankings[0])):
                        $r1 = $rankings[0];
                        $name1 = ($type == 'team') ? $r1['team_name'] : $r1['display_name'];
                        $wr1 = $r1['matches_played'] > 0 ? round(($r1['wins'] / $r1['matches_played']) * 100, 1) : 0;
                        $link1 = ($type == 'team') ? 'team-profile.php?id=' . $r1['team_id'] : 'player-profile.php?id=' . $r1['player_id'];
                    ?>
                        <a href="<?php echo $link1; ?>" class="podium-card podium-1 p-8 rounded-3xl order-1 md:order-2 space-y-4 md:-translate-y-4 bg-gradient-to-b from-amber-500/15 via-transparent to-transparent podium-animate-1">
                            <div class="champion-sparkle"></div>
                            <div class="flex items-center justify-between relative z-10">
                                <span class="w-12 h-12 rounded-2xl bg-amber-400/30 text-amber-300 flex items-center justify-center font-display font-black text-xl border border-amber-400/80 shadow-gold-glow animate-pulse">
                                    <i class="fa-solid fa-crown"></i>
                                </span>
                                <span class="text-xs uppercase font-bold text-amber-300 tracking-widest px-3 py-1 rounded-full bg-amber-500/20 border border-amber-400/30">Champion</span>
                            </div>
                            <div class="relative z-10">
                                <h3 class="text-2xl sm:text-3xl font-black font-display text-white truncate"><?php echo htmlspecialchars($name1); ?></h3>
                                <p class="text-xs text-gray-300 mt-1">แข่ง <?php echo $r1['matches_played']; ?> นัด (Win Rate <?php echo $wr1; ?>%)</p>
                            </div>
                            <div class="pt-3 border-t border-white/15 flex items-center justify-between relative z-10">
                                <span class="text-xs text-amber-300 uppercase font-bold tracking-wider">คะแนนสะสมสูงสุด</span>
                                <span class="font-display font-black text-amber-400 text-2xl sm:text-3xl"><span class="podium-counter" data-target="<?php echo $r1['total_points']; ?>">0</span> <span class="text-xs font-normal">PTS</span></span>
                            </div>
                        </a>
                    <?php endif; ?>

                    <!-- อันดับที่ 3 (Bronze) -->
                    <?php if (isset($rankings[2])):
                        $r3 = $rankings[2];
                        $name3 = ($type == 'team') ? $r3['team_name'] : $r3['display_name'];
                        $wr3 = $r3['matches_played'] > 0 ? round(($r3['wins'] / $r3['matches_played']) * 100, 1) : 0;
                        $link3 = ($type == 'team') ? 'team-profile.php?id=' . $r3['team_id'] : 'player-profile.php?id=' . $r3['player_id'];
                    ?>
                        <a href="<?php echo $link3; ?>" class="podium-card podium-3 p-6 rounded-3xl order-3 space-y-4 podium-animate-3">
                            <div class="flex items-center justify-between">
                                <span class="w-10 h-10 rounded-2xl bg-amber-700/30 text-amber-400 flex items-center justify-center font-display font-black text-lg border border-amber-600/40">#3</span>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold font-display text-white truncate"><?php echo htmlspecialchars($name3); ?></h3>
                                <p class="text-xs text-gray-400 mt-0.5">แข่ง <?php echo $r3['matches_played']; ?> นัด (Win Rate <?php echo $wr3; ?>%)</p>
                            </div>
                            <div class="pt-2 border-t border-white/10 flex items-center justify-between">
                                <span class="text-xs text-gray-400 uppercase font-bold">คะแนนสะสม</span>
                                <span class="font-display font-black text-amber-500 text-xl"><span class="podium-counter" data-target="<?php echo $r3['total_points']; ?>">0</span> <span class="text-xs font-normal">PTS</span></span>
                            </div>
                        </a>
                    <?php endif; ?>

                </div>
            </section>
        <?php endif; ?>

        <!-- FULL RANKINGS TABLE -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 mb-20 w-full">
            <div class="glass-panel rounded-2xl overflow-hidden shadow-2xl border border-white/15">
                <div class="overflow-x-auto">
                    <table id="rankingTable" class="w-full text-left text-sm text-gray-200">
                        <thead class="bg-black/70 text-xs uppercase font-bold text-gray-300 border-b border-white/15 font-display tracking-wider">
                            <tr>
                                <th class="p-5 text-center w-20">อันดับ</th>
                                <th class="p-5"><?php echo $type == 'player' ? 'ผู้เล่น (Player)' : 'สโมสร / ทีม (Team)'; ?></th>
                                <?php if ($type === 'team'): ?>
                                    <th class="p-5 text-center">ประเภท</th>
                                <?php endif; ?>
                                <th class="p-5 text-center">แข่งแล้ว</th>
                                <th class="p-5 text-center">ชนะ - แพ้</th>
                                <th class="p-5 text-center">Win Rate</th>
                                <th class="p-5 text-right w-36">คะแนนสะสม</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10 font-medium">
                            <?php if (empty($rankings)): ?>
                                <tr>
                                    <td colspan="7" class="p-10 text-center text-gray-400 font-normal">
                                        ยังไม่มีข้อมูลตารางคะแนนในเกมหรือหมวดหมู่นี้
                                    </td>
                                </tr>
                            <?php elseif (count($rankings) <= 3): ?>
                                <tr id="noMoreRankingRow">
                                    <td colspan="7" class="p-10 text-center text-gray-400 font-normal">
                                        แสดงอันดับครบถ้วนในโซน Podium ด้านบนแล้ว
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rankingRows as $index => $r):
                                    $actualRank = (($rankingPage - 1) * $rankingRowsPerPage) + $index + 4;
                                    $totalMatches = (int) $r['matches_played'];
                                    $wins = (int) $r['wins'];
                                    $winRate = $totalMatches > 0 ? round(($wins / $totalMatches) * 100, 1) : 0;
                                    $name = ($type == 'team') ? $r['team_name'] : $r['display_name'];
                                    $rowLink = ($type == 'team') ? 'team-profile.php?id=' . $r['team_id'] : 'player-profile.php?id=' . $r['player_id'];
                                    $staggerDelay = min($index * 30, 900);
                                ?>
                                    <tr class="ranking-row cursor-pointer"
                                        style="animation-delay: <?php echo $staggerDelay; ?>ms;"
                                        onclick="window.location='<?php echo $rowLink; ?>'"
                                        data-search-name="<?php echo strtolower(htmlspecialchars($name)); ?>">
                                        <td class="p-5 text-center font-display font-bold text-gray-400 text-sm">#<?php echo $actualRank; ?></td>
                                        <td class="p-5 font-bold text-white text-base">
                                            <div class="flex items-center gap-3">
                                                <?php if ($type == 'team'): ?>
                                                    <div class="w-8 h-8 rounded-lg bg-white/10 border border-white/20 flex items-center justify-center text-brand-orange shrink-0">
                                                        <i class="fa-solid fa-shield-halved text-xs"></i>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="w-8 h-8 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 shrink-0">
                                                        <i class="fa-solid fa-user text-xs"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="hover:text-brand-orange transition-colors"><?php echo htmlspecialchars($name); ?></span>
                                            </div>
                                        </td>
                                        <?php if ($type === 'team'): ?>
                                            <td class="p-5 text-center">
                                                <?php 
                                                    $cat = $r['team_category'] ?? 'open';
                                                    if ($cat === 'male') echo '<span class="px-2.5 py-0.5 rounded-full text-[10px] bg-blue-500/20 text-blue-300 font-bold border border-blue-500/30">ชาย</span>';
                                                    elseif ($cat === 'female') echo '<span class="px-2.5 py-0.5 rounded-full text-[10px] bg-pink-500/20 text-pink-300 font-bold border border-pink-500/30">หญิง</span>';
                                                    else echo '<span class="px-2.5 py-0.5 rounded-full text-[10px] bg-purple-500/20 text-purple-300 font-bold border border-purple-500/30">ทั่วไป</span>';
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="p-5 text-center font-mono font-bold text-gray-300"><?php echo $r['matches_played']; ?></td>
                                        <td class="p-5 text-center font-mono text-sm">
                                            <span class="text-emerald-400 font-bold"><?php echo $r['wins']; ?>W</span>
                                            <span class="text-gray-500 mx-1">-</span>
                                            <span class="text-rose-400 font-bold"><?php echo $r['losses']; ?>L</span>
                                        </td>
                                        <td class="p-5 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <div class="w-20 bg-white/10 rounded-full h-2 overflow-hidden hidden sm:block">
                                                    <div class="bg-brand-orange h-full rounded-full win-rate-bar" data-width="<?php echo $winRate; ?>"></div>
                                                </div>
                                                <span class="font-mono text-xs font-bold text-gray-300"><?php echo $winRate; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="p-5 text-right font-display font-black text-brand-orange text-xl">
                                            <?php echo number_format($r['total_points']); ?> <span class="text-xs text-gray-400 font-normal font-sans">PTS</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <tr id="noSearchResultRow" class="hidden">
                                <td colspan="7" class="p-10 text-center text-gray-400 font-normal">ไม่พบผลการค้นหาที่ตรงกัน</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php if ($rankingPageCount > 1): ?>
                    <?php
                    $paginationParams = [
                        'game_id' => $gameId,
                        'type' => $type,
                        'category' => $category,
                    ];
                    if ($search !== '') {
                        $paginationParams['search'] = $search;
                    }
                    ?>
                    <nav class="flex items-center justify-center gap-2 p-5 border-t border-white/10" aria-label="แบ่งหน้ารายชื่ออันดับ">
                        <?php if ($rankingPage > 1): ?>
                            <a href="?<?= http_build_query($paginationParams + ['ranking_page' => $rankingPage - 1]) ?>"
                                class="px-4 py-2 rounded-xl bg-white/10 hover:bg-brand-orange text-white text-xs font-bold transition-colors">
                                <i class="fa-solid fa-chevron-left mr-1"></i> ก่อนหน้า
                            </a>
                        <?php endif; ?>
                        <span class="px-4 py-2 text-xs font-bold text-gray-300">
                            หน้า <?= $rankingPage ?> / <?= $rankingPageCount ?>
                        </span>
                        <?php if ($rankingPage < $rankingPageCount): ?>
                            <a href="?<?= http_build_query($paginationParams + ['ranking_page' => $rankingPage + 1]) ?>"
                                class="px-4 py-2 rounded-xl bg-white/10 hover:bg-brand-orange text-white text-xs font-bold transition-colors">
                                ถัดไป <i class="fa-solid fa-chevron-right ml-1"></i>
                            </a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>
        </section>

        <!-- FOOTER -->
        <footer class="border-t border-white/15 bg-slate-950/90 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4 text-center md:text-left">
                <div>
                    <p class="text-gray-300 font-semibold">&copy; <?= date('Y') ?> KORAT ESPORT. All rights reserved.</p>
                    <p class="text-[11px] text-gray-400 mt-1">ศูนย์กลางข้อมูลข่าวสารและการแข่งขันอีสปอร์ตจังหวัดนครราชสีมา</p>
                </div>
                <div class="flex items-center gap-4 text-gray-300">
                    <a href="https://www.facebook.com/koratesport/" target="_blank" rel="noopener noreferrer" class="hover:text-brand-orange transition-colors"><i class="fa-brands fa-facebook text-lg"></i></a>
                    <a href="https://www.youtube.com/@koratesport" target="_blank" rel="noopener noreferrer" class="hover:text-brand-orange transition-colors"><i class="fa-brands fa-youtube text-lg"></i></a>
                </div>
            </div>
        </footer>

    </div>

    <!-- Script สำหรับ CountUp, Animate Progress Bar และระบบค้นหา -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const counters = document.querySelectorAll('.podium-counter');
            counters.forEach(counter => {
                const target = +counter.getAttribute('data-target');
                let count = 0;
                const increment = Math.max(1, Math.ceil(target / 30));
                const updateCount = () => {
                    count += increment;
                    if (count < target) {
                        counter.innerText = count.toLocaleString();
                        setTimeout(updateCount, 25);
                    } else {
                        counter.innerText = target.toLocaleString();
                    }
                };
                setTimeout(updateCount, 400);
            });

            setTimeout(() => {
                const bars = document.querySelectorAll('.win-rate-bar');
                bars.forEach(bar => {
                    bar.style.width = bar.getAttribute('data-width') + '%';
                });
            }, 300);
        });

        function filterRankingTable() {
            const input = document.getElementById('rankingSearchInput');
            const filter = input.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#rankingTable tbody tr.ranking-row');
            const noResultRow = document.getElementById('noSearchResultRow');
            let visibleCount = 0;

            rows.forEach(row => {
                const searchName = row.getAttribute('data-search-name');
                if (searchName && searchName.includes(filter)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });

            if (noResultRow) {
                noResultRow.classList.toggle('hidden', visibleCount !== 0 || filter === '');
            }
        }
    </script>
</body>
</html>