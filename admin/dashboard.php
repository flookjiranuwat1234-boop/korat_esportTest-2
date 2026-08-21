<?php
// admin/dashboard.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_categories.php';
requireRole('admin');
ensureTournamentCategorySchema($pdo);

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

// ================= แยกประเภทข้อมูล "นักกีฬา" ให้ตรงกับความเป็นจริง =================
$importedPlayerCount = $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
$claimedPlayerCount = $pdo->query("SELECT COUNT(*) FROM players WHERE user_id IS NOT NULL")->fetchColumn();
$unclaimedPlayerCount = $pdo->query("SELECT COUNT(*) FROM players WHERE user_id IS NULL")->fetchColumn();

// 3) นักกีฬาตัวจริง: มีบัญชีผู้ใช้และเช็คอินผ่าน Tournament Roster แล้ว
$confirmedAthleteCount = $pdo->query("
    SELECT COUNT(DISTINCT p.player_id)
    FROM players p
    JOIN tournament_registration_members trm ON trm.player_id = p.player_id
    WHERE p.user_id IS NOT NULL
      AND trm.checkin_status IN ('checked_in', 'waived')
")->fetchColumn();

// 4) บัญชีผู้ใช้ทั้งหมด (ไม่นับ admin)
$memberCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();

// 5) มีบัญชี + มีโปรไฟล์นักกีฬาแล้ว แต่ยังไม่เคยเช็คอินเข้าแข่งขัน
$profileOnlyNoTournamentCount = $pdo->query("
    SELECT COUNT(DISTINCT u.user_id)
    FROM users u
    JOIN players p ON p.user_id = u.user_id
    WHERE u.role != 'admin'
      AND NOT EXISTS (
          SELECT 1 FROM tournament_registration_members trm
          WHERE trm.player_id = p.player_id AND trm.checkin_status IN ('checked_in', 'waived')
      )
")->fetchColumn();

$noProfileCount = max(0, (int) $memberCount - (int) $claimedPlayerCount);

// คำนวณเปอร์เซ็นต์สำหรับ Progress Bars
$memberPieTotal = max(1, $memberCount);
$pctAthlete = round(($confirmedAthleteCount / $memberPieTotal) * 100);
$pctProfileOnly = round(($profileOnlyNoTournamentCount / $memberPieTotal) * 100);
$pctPending = max(0, 100 - $pctAthlete - $pctProfileOnly);

// สถิติภาพรวมอื่นๆ
$teamCount = $pdo->query("
    SELECT COUNT(*) FROM teams t
    JOIN players p ON p.player_id = t.captain_player_id
    WHERE p.user_id IS NOT NULL
")->fetchColumn();
$tournamentCount = $pdo->query("SELECT COUNT(*) FROM tournaments")->fetchColumn();
$pendingMatches = $pdo->query("
    SELECT COUNT(*) FROM matches
    WHERE status = 'scheduled' AND team1_id IS NOT NULL AND team2_id IS NOT NULL
")->fetchColumn();
$openTournamentCount = $pdo->query("SELECT COUNT(*) FROM tournaments WHERE status = 'registration_open'")->fetchColumn();
$checkinTournamentCount = $pdo->query("SELECT COUNT(*) FROM tournaments WHERE status = 'checkin_open'
    OR (checkin_open_at IS NOT NULL AND checkin_open_at <= NOW() AND (checkin_close_at IS NULL OR checkin_close_at >= NOW()))")->fetchColumn();
$pendingRegistrationCount = $pdo->query("SELECT COUNT(*) FROM tournament_registrations WHERE status = 'pending'")->fetchColumn();
$incompleteCheckinCount = $pdo->query("SELECT COUNT(*) FROM tournament_registrations tr
    WHERE tr.status = 'approved'
      AND EXISTS (SELECT 1 FROM tournament_registration_members req WHERE req.tournament_registration_id = tr.tournament_registration_id AND req.is_required_for_checkin = 1)
      AND EXISTS (SELECT 1 FROM tournament_registration_members waiting WHERE waiting.tournament_registration_id = tr.tournament_registration_id AND waiting.is_required_for_checkin = 1 AND waiting.checkin_status NOT IN ('checked_in', 'waived'))")->fetchColumn();
$readyForDrawCount = $pdo->query("SELECT COUNT(*) FROM tournaments t
        WHERE t.status NOT IN ('completed', 'cancelled', 'archived')
            AND EXISTS (SELECT 1 FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.participation_status = 'qualified_for_draw')
            AND NOT EXISTS (SELECT 1 FROM matches m WHERE m.tournament_id = t.tournament_id)")->fetchColumn();
$completedTournamentCount = $pdo->query("SELECT COUNT(*) FROM tournaments WHERE status = 'completed'")->fetchColumn();
$upcomingTournamentCount = $pdo->query("SELECT COUNT(*) FROM tournaments
        WHERE status NOT IN ('completed', 'cancelled', 'archived')
            AND start_date IS NOT NULL AND start_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$urgentTaskCount = (int) $incompleteCheckinCount + (int) $pendingMatches + (int) $pendingRegistrationCount + (int) $readyForDrawCount + (int) $upcomingTournamentCount;

// ================= ดูทัวร์นาเมนต์แยกเป็นรายปี =================
$availableYears = $pdo->query("
    SELECT DISTINCT YEAR(created_at) AS y FROM tournaments ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (empty($availableYears)) {
    $availableYears = [date('Y')];
}

$selectedYear = (int) ($_GET['year'] ?? date('Y'));
if (!in_array($selectedYear, $availableYears)) {
    $selectedYear = $availableYears[0];
}

$dashboardSearch = trim((string) ($_GET['search'] ?? ''));
$dashboardGame = (int) ($_GET['game_id'] ?? 0);
$dashboardCategory = trim((string) ($_GET['category'] ?? ''));
$dashboardStatus = trim((string) ($_GET['status'] ?? ''));
$dashboardMonth = (int) ($_GET['month'] ?? 0);
$dashboardNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));

$tournamentCountYear = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE YEAR(created_at) = :y");
$tournamentCountYear->execute(['y' => $selectedYear]);
$tournamentCountYear = $tournamentCountYear->fetchColumn();

$ongoingCountYear = $pdo->prepare("SELECT COUNT(*) FROM tournaments WHERE YEAR(created_at) = :y AND status = 'ongoing'");
$ongoingCountYear->execute(['y' => $selectedYear]);
$ongoingCountYear = $ongoingCountYear->fetchColumn();

$monthlyTournamentCounts = array_fill(1, 12, 0);
$monthlyStmt = $pdo->prepare("SELECT MONTH(start_date) AS month_number, COUNT(*) AS tournament_count
    FROM tournaments WHERE YEAR(start_date) = :year AND start_date IS NOT NULL
    GROUP BY MONTH(start_date) ORDER BY month_number");
$monthlyStmt->execute(['year' => $selectedYear]);
foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $monthRow) {
    $monthlyTournamentCounts[(int) $monthRow['month_number']] = (int) $monthRow['tournament_count'];
}

$memberChartData = [
    ['label' => 'นักกีฬาตัวจริง', 'value' => (int) $confirmedAthleteCount, 'color' => '#10B981', 'url' => 'manage-members.php?profile=confirmed'],
    ['label' => 'มีโปรไฟล์แต่ยังไม่แข่งขัน', 'value' => (int) $profileOnlyNoTournamentCount, 'color' => '#3B82F6', 'url' => 'manage-members.php'],
    ['label' => 'ยังไม่มีโปรไฟล์', 'value' => (int) $noProfileCount, 'color' => '#94A3B8', 'url' => 'manage-members.php'],
];
$workflowChartData = [
    ['label' => 'เปิดรับสมัคร', 'value' => (int) $openTournamentCount, 'color' => '#10B981', 'icon' => 'fa-door-open', 'description' => 'Tournament ที่เปิดให้สมัคร'],
    ['label' => 'รออนุมัติ', 'value' => (int) $pendingRegistrationCount, 'color' => '#F59E0B', 'icon' => 'fa-user-clock', 'description' => 'ใบสมัครที่รอ Admin ตรวจสอบ'],
    ['label' => 'กำลัง Check-in', 'value' => (int) $checkinTournamentCount, 'color' => '#3B82F6', 'icon' => 'fa-user-check', 'description' => 'Tournament ที่อยู่ในช่วง Check-in'],
    ['label' => 'Check-in ไม่ครบ', 'value' => (int) $incompleteCheckinCount, 'color' => '#F43F5E', 'icon' => 'fa-triangle-exclamation', 'description' => 'Registration ที่ยังเช็กอินไม่ครบ'],
    ['label' => 'พร้อมจัดสาย', 'value' => (int) $readyForDrawCount, 'color' => '#0EA5E9', 'icon' => 'fa-sitemap', 'description' => 'Tournament ที่พร้อมสร้าง Bracket'],
    ['label' => 'กำลังแข่งขัน', 'value' => (int) $ongoingCountYear, 'color' => '#8B5CF6', 'icon' => 'fa-gamepad', 'description' => 'Tournament ที่กำลังแข่งขัน'],
    ['label' => 'Match รอผล', 'value' => (int) $pendingMatches, 'color' => '#F97316', 'icon' => 'fa-clock', 'description' => 'Match ที่รอบันทึกผล'],
    ['label' => 'แข่งขันจบแล้ว', 'value' => (int) $completedTournamentCount, 'color' => '#64748B', 'icon' => 'fa-flag-checkered', 'description' => 'Tournament ที่จบการแข่งขันแล้ว'],
];
$dashboardGames = $pdo->query("SELECT game_id, name FROM games WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$dashboardCategories = $pdo->query("SELECT DISTINCT category_code, label FROM tournament_categories WHERE is_active = 1 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

$tournamentsByYear = $pdo->prepare("
    SELECT t.tournament_id, t.name, t.status, t.created_at, t.image_path, t.start_date, g.name AS game_name,
        (SELECT GROUP_CONCAT(DISTINCT category_code ORDER BY tournament_category_id SEPARATOR ', ') FROM tournament_categories WHERE tournament_id = t.tournament_id AND is_active = 1) AS category_labels,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id) AS registered_count,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND status = 'approved') AS approved_count,
        (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.status = 'approved'
            AND EXISTS (SELECT 1 FROM tournament_registration_members req WHERE req.tournament_registration_id = tr.tournament_registration_id AND req.is_required_for_checkin = 1)
            AND NOT EXISTS (SELECT 1 FROM tournament_registration_members waiting WHERE waiting.tournament_registration_id = tr.tournament_registration_id AND waiting.is_required_for_checkin = 1 AND waiting.checkin_status NOT IN ('checked_in', 'waived'))) AS checkin_complete_count,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id) AS total_match_count,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id AND status IN ('completed', 'walkover')) AS completed_match_count
    FROM tournaments t
    JOIN games g ON g.game_id = t.game_id
    WHERE YEAR(t.created_at) = :y
      AND (:search = '' OR t.name LIKE :search_like)
      AND (:game_id = 0 OR t.game_id = :game_id)
      AND (:status = '' OR t.status = :status)
      AND (:month = 0 OR MONTH(t.start_date) = :month)
      AND (:category = '' OR EXISTS (SELECT 1 FROM tournament_categories fc WHERE fc.tournament_id = t.tournament_id AND fc.is_active = 1 AND (fc.category_code = :category OR fc.label = :category)))
    ORDER BY t.created_at DESC
");
$tournamentsByYear->execute(['y' => $selectedYear, 'search' => $dashboardSearch, 'search_like' => '%' . $dashboardSearch . '%', 'game_id' => $dashboardGame, 'status' => $dashboardStatus, 'month' => $dashboardMonth, 'category' => $dashboardCategory]);
$tournamentsByYear = $tournamentsByYear->fetchAll();

$pendingRegs = $pdo->query("
    SELECT tr.tournament_registration_id AS reg_id, COALESCE(t.name, u.username, 'ผู้สมัครเดี่ยว') AS team_name, tour.tournament_id AS tournament_id, tour.name AS tournament_name, tr.registered_at
    FROM tournament_registrations tr
    LEFT JOIN teams t ON t.team_id = tr.team_id
    LEFT JOIN players p ON p.player_id = tr.player_id
    LEFT JOIN users u ON u.user_id = p.user_id
    JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
    WHERE tr.status = 'pending'
    ORDER BY tr.registered_at
    LIMIT 10
")->fetchAll();

$readyForPlayoff = $pdo->query("
    SELECT t.tournament_id, t.name,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id AND group_id IS NOT NULL AND status = 'scheduled') AS pending_group_matches,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id AND group_id IS NULL) AS playoff_match_count
    FROM tournaments t
    WHERE t.format = 'group_playoff' AND t.status = 'ongoing'
    HAVING pending_group_matches = 0 AND playoff_match_count = 0
")->fetchAll();

$recentMatches = $pdo->query("
    SELECT m.completed_at, m.team1_score, m.team2_score, m.status,
        t1.name AS team1_name, t2.name AS team2_name, tour.name AS tournament_name
    FROM matches m
    JOIN tournaments tour ON tour.tournament_id = m.tournament_id
    LEFT JOIN teams t1 ON t1.team_id = m.team1_id
    LEFT JOIN teams t2 ON t2.team_id = m.team2_id
    WHERE m.status IN ('completed', 'walkover')
    ORDER BY m.completed_at DESC
    LIMIT 8
")->fetchAll();

$openTournaments = $pdo->query("
    SELECT t.tournament_id, t.name, g.name AS game_name,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND status = 'approved') AS team_count
    FROM tournaments t
    JOIN games g ON g.game_id = t.game_id
    WHERE t.status = 'registration_open'
    ORDER BY t.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS CDN -->
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
                            card: '#FFFFFF',
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

        .stat-card-light {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            transition: all 0.25s ease;
        }
        .stat-card-light:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(255, 85, 0, 0.12);
            border-color: #FF5500;
        }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover, .nav-item.active {
            background: rgba(255, 85, 0, 0.12);
            color: #FF5500;
            border-left: 4px solid #FF5500;
        }
        .progress-bar-fill {
            width: 0%;
            transition: width 1.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .workflow-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }
        .workflow-stage {
            min-width: 0;
            background: #fff;
            border: 1px solid var(--workflow-border);
            border-top: 4px solid var(--workflow-accent);
            border-radius: 0.875rem;
            padding: 0.875rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
            cursor: default;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
        .workflow-panel__header { flex-wrap: wrap; }
        @media (hover: hover) and (pointer: fine) {
            .workflow-stage--registration:hover { border-color: rgba(16, 185, 129, 0.85); box-shadow: 0 8px 24px rgba(16, 185, 129, 0.18), 0 0 12px rgba(16, 185, 129, 0.14); }
            .workflow-stage--checkin:hover { border-color: rgba(37, 99, 235, 0.85); box-shadow: 0 8px 24px rgba(37, 99, 235, 0.18), 0 0 12px rgba(37, 99, 235, 0.14); }
            .workflow-stage--competition:hover { border-color: rgba(124, 58, 237, 0.85); box-shadow: 0 8px 24px rgba(124, 58, 237, 0.18), 0 0 12px rgba(124, 58, 237, 0.14); }
            .workflow-stage--completed:hover { border-color: rgba(51, 65, 85, 0.85); box-shadow: 0 8px 24px rgba(51, 65, 85, 0.18), 0 0 12px rgba(51, 65, 85, 0.14); }
        }
        @media (hover: hover) and (pointer: fine) {
            .workflow-stage:hover { transform: translateY(-3px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .workflow-stage { transition: none; }
            .workflow-stage:hover { transform: none; }
        }
        .workflow-stage__header {
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
        }
        .workflow-stage__number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 999px;
            background: var(--workflow-soft);
            color: var(--workflow-accent);
            font: 700 0.75rem/1 'Orbitron', sans-serif;
        }
        .workflow-stage__icon { align-self: center; color: var(--workflow-accent); font-size: 1.1rem; }
        .workflow-stage__total { margin-left: auto; color: var(--workflow-accent); font: 700 1.35rem/1 'Orbitron', sans-serif; }
        .workflow-status-list { display: grid; gap: 0.35rem; margin-top: 0.65rem; }
        .workflow-status-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            min-height: 2.1rem;
            padding: 0.35rem 0.55rem;
            border: 1px solid transparent;
            border-radius: 0.55rem;
            color: #475569;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .workflow-status-badge { min-width: 1.55rem; padding: 0.15rem 0.35rem; border-radius: 999px; background: var(--workflow-soft); color: var(--workflow-accent); text-align: center; font: 700 0.7rem/1.2 'Orbitron', sans-serif; }
        .workflow-status-badge.is-zero { background: #f1f5f9; color: #94a3b8; }
        .workflow-manage-link { display: inline-flex; align-items: center; gap: 0.4rem; min-height: 2.25rem; padding: 0.45rem 0.75rem; border-radius: 0.5rem; background: #fff7ed; color: #ea580c; font-size: 0.72rem; font-weight: 700; }
        .workflow-manage-link:hover { background: #ffedd5; }
        @media (max-width: 1023px) {
            .workflow-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 639px) {
            .workflow-grid { grid-template-columns: minmax(0, 1fr); }
        }
    </style>
</head>
<body class="text-slate-800 font-sans min-h-screen flex antialiased">

    <!-- ================= 1. SIDEBAR ด้านข้าง ================= -->
    <aside class="w-64 bg-brand-sidebar text-slate-300 flex flex-col fixed inset-y-0 left-0 z-50 shadow-xl">
        <div class="p-6 border-b border-slate-800 flex items-center gap-3">
            <img src="../assets/img/logo.png" alt="Korat Esport" class="h-10 w-auto filter drop-shadow" onError="this.src='https://placehold.co/80x80/0F172A/FF5500?text=KE';">
            <div>
                <h1 class="font-display font-black text-lg text-white tracking-wider">KORAT <span class="text-brand-orange">ESPORT</span></h1>
                <p class="text-[10px] tracking-widest text-slate-400 uppercase font-semibold">Admin Command Center</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-1 text-sm font-medium">
            <a href="dashboard.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-chart-pie w-5 text-center text-brand-orange"></i>
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

    <!-- ================= 2. MAIN CONTENT AREA ================= -->
    <div class="flex-1 ml-64 min-h-screen flex flex-col">

        <!-- Top Header Panel -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    ภาพรวมระบบ <span class="text-brand-orange">(ADMIN DASHBOARD)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">ศูนย์ควบคุมและสรุปสถิติระบบ Korat Esport</p>
            </div>
            
            <div class="flex items-center gap-3">
                <form method="GET" class="flex items-center gap-1.5 bg-slate-100 px-3 py-1.5 rounded-lg">
                    <i class="fa-solid fa-calendar-days text-brand-orange text-xs"></i>
                    <select name="year" onchange="this.form.submit()"
                            class="bg-transparent text-xs font-bold text-slate-700 focus:outline-none cursor-pointer">
                        <?php foreach ($availableYears as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>>
                                ปี <?php echo $y; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                    <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
                </a>
            </div>
        </header>

        <!-- Main Body Content -->
        <main class="p-8 space-y-8 flex-1">

            <!-- STAT CARDS -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                
                <!-- Card 1: สมาชิกทั้งหมด -->
                <a href="manage-members.php" class="stat-card-light p-5 rounded-2xl relative block group">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">สมาชิกทั้งหมด</span>
                        <div class="w-9 h-9 rounded-xl bg-orange-50 text-brand-orange flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fa-solid fa-people-group"></i>
                        </div>
                    </div>
                    <div class="flex items-end justify-between">
                        <div>
                            <h3 class="text-3xl font-black font-display text-slate-900" data-countup="<?php echo $memberCount; ?>">0</h3>
                            <p class="text-[11px] text-slate-400 mt-1">บัญชีสมาชิกทั้งหมดในระบบ</p>
                        </div>
                        <span class="text-xs font-bold text-brand-orange opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 mb-1">
                            จัดการสมาชิก <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </span>
                    </div>
                </a>

                <!-- Card 2: นักกีฬาตัวจริง -->
                <a href="manage-members.php?profile=confirmed" class="stat-card-light p-5 rounded-2xl relative block group">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">นักกีฬาตัวจริง</span>
                        <div class="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fa-solid fa-gamepad"></i>
                        </div>
                    </div>
                    <div class="flex items-end justify-between">
                        <div>
                            <h3 class="text-3xl font-black font-display text-slate-900" data-countup="<?php echo $confirmedAthleteCount; ?>">0</h3>
                            <p class="text-[11px] text-slate-400 mt-1">มีบัญชี + เคยเช็คอินเข้าแข่งขันแล้ว</p>
                        </div>
                        <span class="text-xs font-bold text-amber-600 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 mb-1">
                            ดูรายชื่อ <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </span>
                    </div>
                </a>

                <!-- Card 3: ทีมที่ใช้งานอยู่ -->
                <a href="manage-teams.php" class="stat-card-light p-5 rounded-2xl relative block group">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">ทีมที่ใช้งานอยู่</span>
                        <div class="w-9 h-9 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fa-solid fa-users"></i>
                        </div>
                    </div>
                    <div class="flex items-end justify-between">
                        <div>
                            <h3 class="text-3xl font-black font-display text-slate-900" data-countup="<?php echo $teamCount; ?>">0</h3>
                            <p class="text-[11px] text-slate-400 mt-1">ทีมที่มี Captain และพร้อมใช้งาน</p>
                        </div>
                        <span class="text-xs font-bold text-indigo-600 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 mb-1">
                            จัดการทีม <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </span>
                    </div>
                </a>

                <!-- Card 4: ทัวร์นาเมนต์ -->
                <a href="manage-tournament.php" class="stat-card-light p-5 rounded-2xl relative block group">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">ทัวร์นาเมนต์ปี <?php echo $selectedYear; ?></span>
                        <div class="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fa-solid fa-trophy"></i>
                        </div>
                    </div>
                    <div class="flex items-end justify-between">
                        <div>
                            <h3 class="text-3xl font-black font-display text-slate-900">
                                <span class="text-brand-orange"><?php echo $ongoingCountYear; ?></span> <span class="text-sm font-normal text-slate-400">/ <?php echo $tournamentCountYear; ?></span>
                            </h3>
                            <p class="text-[11px] text-slate-400 mt-1">กำลังแข่ง / ทั้งหมดในปีนี้</p>
                        </div>
                        <span class="text-xs font-bold text-purple-600 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 mb-1">
                            จัดการ <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </span>
                    </div>
                </a>

                <!-- Card 5: แมตช์รอผล -->
                <a href="record-match.php" class="stat-card-light p-5 rounded-2xl relative border-orange-200 block group">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">แมตช์รอผล</span>
                        <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                            <i class="fa-solid fa-clock"></i>
                        </div>
                    </div>
                    <div class="flex items-end justify-between">
                        <div>
                            <h3 class="text-3xl font-black font-display text-rose-600" data-countup="<?php echo $pendingMatches; ?>">0</h3>
                            <p class="text-[11px] text-slate-400 mt-1">รอบันทึกผลการแข่ง</p>
                        </div>
                        <span class="text-xs font-bold text-rose-600 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1 mb-1">
                            บันทึกผล <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </span>
                    </div>
                </a>

                <!-- Card 6: ทีม Check-in ไม่ครบ -->
                <a href="checkin-teams.php" class="stat-card-light p-5 rounded-2xl relative block group border-rose-200">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-500">ทีม Check-in ไม่ครบ</span>
                            <div class="w-9 h-9 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center text-lg group-hover:scale-110 transition-transform">
                                <i class="fa-solid fa-user-clock"></i>
                            </div>
                        </div>

                        <div class="flex items-end justify-between">
                            <div><h3 class="text-3xl font-black font-display text-rose-600" data-countup="<?php echo $incompleteCheckinCount; ?>">0</h3><p class="text-[11px] text-slate-400 mt-1">ต้องตรวจสอบก่อนปิด Check-in</p></div>
                            <span class="text-xs font-bold text-rose-600 opacity-0 group-hover:opacity-100 transition-opacity">ตรวจสอบ <i class="fa-solid fa-arrow-right"></i></span>
                        </div>
                    </div>
                </a>

            </div>

            <section class="workflow-panel bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="workflow-panel__header flex items-center justify-between border-b border-slate-100 pb-3 mb-4 gap-3">
                    <div class="min-w-0"><h2 class="font-bold font-display text-slate-900">สถานะ Tournament Workflow</h2><p class="text-xs text-slate-500 mt-1">สรุปจำนวน Tournament, ใบสมัคร, Check-in และ Match ตามสถานะปัจจุบัน</p></div>
                    <a href="manage-tournament.php" aria-label="เปิดศูนย์จัดการ Tournament" class="workflow-manage-link shrink-0"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i><span>เปิดศูนย์จัดการ</span></a>
                </div>
                <div class="workflow-grid">
                    <div class="workflow-stage workflow-stage--registration" style="--workflow-accent:#059669;--workflow-border:#a7f3d0;--workflow-soft:#ecfdf5;">
                        <div class="workflow-stage__header"><span class="workflow-stage__number">1</span><i class="workflow-stage__icon fa-solid fa-door-open"></i><strong class="text-slate-900">รับสมัคร</strong><span class="workflow-stage__total"><?php echo (int) $workflowChartData[0]['value'] + (int) $workflowChartData[1]['value']; ?></span></div>
                        <div class="workflow-status-list">
                            <div class="workflow-status-item" title="Tournament ที่อยู่ในช่วงเปิดรับสมัคร"><span>เปิดรับสมัคร</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[0]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[0]['value']; ?></span></div>
                            <div class="workflow-status-item" title="ใบสมัครที่ Admin ยังไม่ได้ตรวจสอบ"><span>รออนุมัติ</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[1]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[1]['value']; ?></span></div>
                        </div>
                    </div>
                    <div class="workflow-stage workflow-stage--checkin" style="--workflow-accent:#2563eb;--workflow-border:#bfdbfe;--workflow-soft:#eff6ff;">
                        <div class="workflow-stage__header"><span class="workflow-stage__number">2</span><i class="workflow-stage__icon fa-solid fa-user-check"></i><strong class="text-slate-900">Check-in</strong><span class="workflow-stage__total"><?php echo (int) $workflowChartData[2]['value'] + (int) $workflowChartData[3]['value']; ?></span></div>
                        <div class="workflow-status-list">
                            <div class="workflow-status-item" title="Tournament ที่อยู่ในช่วงรายงานตัว"><span>กำลัง Check-in</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[2]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[2]['value']; ?></span></div>
                            <div class="workflow-status-item" title="ทีมที่ยังรายงานตัวไม่ครบตามเงื่อนไข"><span>Check-in ไม่ครบ</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[3]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[3]['value']; ?></span></div>
                        </div>
                    </div>
                    <div class="workflow-stage workflow-stage--competition" style="--workflow-accent:#7c3aed;--workflow-border:#ddd6fe;--workflow-soft:#f5f3ff;">
                        <div class="workflow-stage__header"><span class="workflow-stage__number">3</span><i class="workflow-stage__icon fa-solid fa-sitemap"></i><strong class="text-slate-900">จัดการแข่งขัน</strong><span class="workflow-stage__total"><?php echo (int) $workflowChartData[4]['value'] + (int) $workflowChartData[5]['value'] + (int) $workflowChartData[6]['value']; ?></span></div>
                        <div class="workflow-status-list">
                            <div class="workflow-status-item" title="Tournament ที่มีผู้สมัครพร้อมสำหรับจัดสาย"><span>พร้อมจัดสาย</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[4]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[4]['value']; ?></span></div>
                            <div class="workflow-status-item" title="Tournament ที่เริ่มการแข่งขันแล้ว"><span>กำลังแข่งขัน</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[5]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[5]['value']; ?></span></div>
                            <div class="workflow-status-item" title="คู่แข่งขันที่ยังไม่ได้บันทึกผล"><span>Match รอผล</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[6]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[6]['value']; ?></span></div>
                        </div>
                    </div>
                    <div class="workflow-stage workflow-stage--completed" style="--workflow-accent:#475569;--workflow-border:#cbd5e1;--workflow-soft:#f8fafc;">
                        <div class="workflow-stage__header"><span class="workflow-stage__number">4</span><i class="workflow-stage__icon fa-solid fa-flag-checkered"></i><strong class="text-slate-900">เสร็จสิ้น</strong><span class="workflow-stage__total"><?php echo (int) $workflowChartData[7]['value']; ?></span></div>
                        <div class="workflow-status-list"><div class="workflow-status-item" title="Tournament ที่ดำเนินการแข่งขันเสร็จแล้ว"><span>แข่งขันจบแล้ว</span><span class="workflow-status-badge<?php echo (int) $workflowChartData[7]['value'] === 0 ? ' is-zero' : ''; ?>"><?php echo (int) $workflowChartData[7]['value']; ?></span></div></div>
                    </div>
                </div>
            </section>

            <section class="stat-card-light p-5 rounded-2xl">
                <div class="flex items-center justify-between mb-3"><h2 class="text-sm font-bold font-display text-slate-900">สัดส่วนสมาชิกในระบบ (รวม <?php echo $memberCount; ?> คน)</h2><i class="fa-solid fa-chart-bar text-slate-500"></i></div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php foreach ([['นักกีฬาตัวจริง', $confirmedAthleteCount, $pctAthlete, 'emerald'], ['มีโปรไฟล์แต่ยังไม่แข่งขัน', $profileOnlyNoTournamentCount, $pctProfileOnly, 'blue'], ['ยังไม่มีโปรไฟล์', $noProfileCount, round(($noProfileCount / $memberPieTotal) * 100), 'rose']] as $memberStat): ?>
                        <div><div class="flex justify-between text-[11px] font-bold mb-1"><span class="text-slate-600"><?php echo $memberStat[0]; ?></span><span><?php echo (int) $memberStat[1]; ?> คน · <?php echo (int) $memberStat[2]; ?>%</span></div><div class="w-full bg-slate-100 h-2 rounded-full overflow-hidden"><div class="bg-<?php echo $memberStat[3]; ?>-500 h-full rounded-full progress-bar-fill" data-width="<?php echo (int) $memberStat[2]; ?>"></div></div></div>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- ทัวร์นาเมนต์แยกรายปี -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-calendar-days text-brand-orange"></i>
                        ทัวร์นาเมนต์ทั้งหมดของปี <?php echo $selectedYear; ?>
                    </h2>
                    <a href="manage-tournament.php" class="text-xs text-brand-orange hover:underline font-semibold flex items-center gap-1">
                        <i class="fa-solid fa-plus"></i> สร้างใหม่
                    </a>
                </div>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <?php if (count($tournamentsByYear) == 0): ?>
                        <div class="p-8 text-center text-slate-400 text-sm">
                            <i class="fa-solid fa-calendar-xmark text-3xl mb-2 block opacity-40"></i>
                            ไม่มีทัวร์นาเมนต์ในปี <?php echo $selectedYear; ?>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-slate-600">
                                <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                    <tr>
                                        <th class="p-4">Tournament</th>
                                        <th class="p-4">เกม</th>
                                        <th class="p-4">Category</th>
                                        <th class="p-4 text-center">สมัคร / อนุมัติ</th>
                                        <th class="p-4 text-center">Check-in</th>
                                        <th class="p-4 text-center">Match</th>
                                        <th class="p-4">วันแข่งขัน</th>
                                        <th class="p-4 text-center">สถานะ</th>
                                        <th class="p-4 text-right">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($tournamentsByYear as $t): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="p-4 font-bold text-slate-900 min-w-[180px]"><?php if (!empty($t['image_path'])): ?><img src="../assets/<?php echo htmlspecialchars($t['image_path']); ?>" class="w-12 h-8 object-cover rounded inline-block mr-2" alt=""><?php endif; ?><?php echo htmlspecialchars($t['name']); ?></td>
                                        <td class="p-4 text-xs text-slate-500">
                                            <span class="px-2 py-0.5 rounded bg-slate-100 border border-slate-200 font-semibold"><?php echo htmlspecialchars($t['game_name']); ?></span>
                                        </td>
                                        <td class="p-4 text-xs"><span class="px-2 py-1 rounded bg-blue-50 text-blue-700 border border-blue-100"><?php echo htmlspecialchars($t['category_labels'] ?: 'ยังไม่กำหนด'); ?></span></td>
                                        <td class="p-4 text-center text-xs font-bold"><?php echo (int) $t['registered_count']; ?> / <?php echo (int) $t['approved_count']; ?></td>
                                        <td class="p-4 text-center text-xs font-bold text-emerald-700"><?php echo (int) $t['checkin_complete_count']; ?></td>
                                        <td class="p-4 text-center text-xs font-bold"><span class="text-emerald-600"><?php echo (int) $t['completed_match_count']; ?></span> / <?php echo (int) $t['total_match_count']; ?></td>
                                        <td class="p-4 text-xs text-slate-500"><?php echo !empty($t['start_date']) ? date('d/m/Y', strtotime($t['start_date'])) : '-'; ?></td>
                                        <td class="p-4 text-center">
                                            <?php
                                                $statusLabels = [
                                                    'registration_open' => ['เปิดรับสมัคร', 'bg-emerald-100 text-emerald-700 border-emerald-200'],
                                                    'ongoing' => ['กำลังแข่ง', 'bg-violet-100 text-violet-700 border-violet-200'],
                                                    'bracket_generated' => ['จัดสายแล้ว', 'bg-sky-100 text-sky-700 border-sky-200'],
                                                    'checkin_open' => ['กำลัง Check-in', 'bg-blue-100 text-blue-700 border-blue-200'],
                                                    'completed' => ['จบแล้ว', 'bg-slate-100 text-slate-600 border-slate-200'],
                                                ];
                                                $label = $statusLabels[$t['status']] ?? [$t['status'], 'bg-slate-100 text-slate-600 border-slate-200'];
                                            ?>
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase border <?php echo $label[1]; ?>"><?php echo htmlspecialchars($label[0]); ?></span>
                                        </td>
                                        <td class="p-4 text-right">
                                            <div class="flex justify-end gap-1"><a href="manage-tournament.php" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-semibold">รายละเอียด</a><a href="manage-teams.php?tournament_id=<?php echo (int) $t['tournament_id']; ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold">ทีม</a><a href="checkin-teams.php?tournament_id=<?php echo (int) $t['tournament_id']; ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold">Check-in</a></div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TASKS -->
            <?php if ($urgentTaskCount > 0 || count($readyForPlayoff) > 0): ?>
                <div class="space-y-4">
                    <h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-brand-orange"></i>
                        งานที่ต้องดำเนินการ <span class="text-xs px-2 py-0.5 rounded-full bg-orange-100 text-brand-orange font-sans"><?= $urgentTaskCount + count($readyForPlayoff) ?> งาน</span>
                    </h2>

                    <?php if (count($readyForPlayoff) > 0): ?>
                        <div class="space-y-2">
                            <?php foreach ($readyForPlayoff as $t): ?>
                                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-emerald-800 shadow-sm">
                                    <div class="flex items-center gap-3">
                                        <i class="fa-solid fa-circle-check text-xl shrink-0 text-emerald-600"></i>
                                        <div>
                                            <strong class="text-slate-900"><?php echo htmlspecialchars($t['name']); ?></strong> 
                                            <span class="text-sm">แข่งรอบกลุ่มจบครบแล้ว พร้อมสร้างรอบ Playoff</span>
                                        </div>
                                    </div>
                                    <a href="manage-tournament.php" class="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs uppercase tracking-wider transition-all shrink-0 shadow-sm">
                                        <span>ไปสร้างรอบ Playoff</span>
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($incompleteCheckinCount > 0): ?>
                        <a href="checkin-teams.php" class="p-4 rounded-xl bg-rose-50 border border-rose-200 flex items-center justify-between gap-3 text-rose-800 shadow-sm"><span><i class="fa-solid fa-user-clock text-rose-600 mr-2"></i><strong><?php echo (int) $incompleteCheckinCount; ?> ทีม</strong> Check-in ไม่ครบ ต้องตรวจสอบก่อนหมดเวลา</span><i class="fa-solid fa-arrow-right"></i></a>
                    <?php endif; ?>
                    <?php if ($pendingMatches > 0): ?>
                        <a href="record-match.php" class="p-4 rounded-xl bg-orange-50 border border-orange-200 flex items-center justify-between gap-3 text-orange-800 shadow-sm"><span><i class="fa-solid fa-clock text-orange-600 mr-2"></i><strong><?php echo (int) $pendingMatches; ?> Match</strong> รอบันทึกผล</span><i class="fa-solid fa-arrow-right"></i></a>
                    <?php endif; ?>
                    <?php if ($upcomingTournamentCount > 0): ?>
                        <a href="manage-tournament.php" class="p-4 rounded-xl bg-violet-50 border border-violet-200 flex items-center justify-between gap-3 text-violet-800 shadow-sm"><span><i class="fa-solid fa-calendar-check text-violet-600 mr-2"></i><strong><?php echo (int) $upcomingTournamentCount; ?> Tournament</strong> ใกล้เริ่มภายใน 7 วัน</span><i class="fa-solid fa-arrow-right"></i></a>
                    <?php endif; ?>

                    <?php if (count($pendingRegs) > 0): ?>
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                            <div class="p-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-amber-600 flex items-center gap-2">
                                    <i class="fa-solid fa-user-clock"></i>
                                    คำขอสมัครทีมที่รออนุมัติ (<?php echo count($pendingRegs); ?>)
                                </h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-slate-600">
                                    <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                        <tr>
                                            <th class="p-4">ทีม</th>
                                            <th class="p-4">ทัวร์นาเมนต์</th>
                                            <th class="p-4">วันที่สมัคร</th>
                                            <th class="p-4 text-right">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($pendingRegs as $p): ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors">
                                            <td class="p-4 font-bold text-slate-900">
                                                <i class="fa-solid fa-shield-halved text-brand-orange mr-2"></i>
                                                <?php echo htmlspecialchars($p['team_name']); ?>
                                            </td>
                                            <td class="p-4 text-slate-600"><?php echo htmlspecialchars($p['tournament_name']); ?></td>
                                            <td class="p-4 text-xs text-slate-400"><?php echo htmlspecialchars($p['registered_at']); ?></td>
                                            <td class="p-4 text-right">
                                                <a href="manage-teams.php?tournament_id=<?php echo $p['tournament_id']; ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-orange-50 hover:bg-brand-orange border border-orange-200 text-brand-orange hover:text-white text-xs font-semibold transition-all">
                                                    <span>ไปอนุมัติ</span>
                                                    <i class="fa-solid fa-chevron-right text-[10px]"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($urgentTaskCount === 0 && count($readyForPlayoff) === 0): ?>
                        <div class="p-6 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm"><i class="fa-solid fa-circle-check mr-2"></i>ไม่มีงานเร่งด่วนในขณะนี้</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- OPEN TOURNAMENTS & RECENT MATCHES GRID -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                
                <!-- Open Tournaments -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                            <i class="fa-solid fa-door-open text-brand-orange"></i>
                            ทัวร์นาเมนต์ที่เปิดรับสมัคร
                        </h2>
                        <a href="manage-tournament.php" class="text-xs text-brand-orange hover:underline font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-plus"></i> สร้างใหม่
                        </a>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <?php if (count($openTournaments) == 0): ?>
                            <div class="p-8 text-center text-slate-400 text-sm">
                                <i class="fa-solid fa-calendar-xmark text-3xl mb-2 block opacity-40"></i>
                                ไม่มีทัวร์นาเมนต์ที่เปิดรับสมัครอยู่ตอนนี้
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-slate-600">
                                    <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                        <tr>
                                            <th class="p-4">ชื่อ</th>
                                            <th class="p-4">เกม</th>
                                            <th class="p-4 text-center">ทีมอนุมัติแล้ว</th>
                                            <th class="p-4 text-right">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($openTournaments as $t): ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors">
                                            <td class="p-4 font-bold text-slate-900"><?php echo htmlspecialchars($t['name']); ?></td>
                                            <td class="p-4 text-xs text-slate-500">
                                                <span class="px-2 py-0.5 rounded bg-slate-100 border border-slate-200 font-semibold"><?php echo htmlspecialchars($t['game_name']); ?></span>
                                            </td>
                                            <td class="p-4 text-center font-bold font-display text-brand-orange text-base"><?php echo $t['team_count']; ?></td>
                                            <td class="p-4 text-right">
                                                <a href="manage-teams.php?tournament_id=<?php echo $t['tournament_id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs text-slate-700 font-semibold transition-all">
                                                    <span>จัดการทีม</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Matches -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left text-brand-orange"></i>
                            แมตช์เพิ่งบันทึกผลล่าสุด
                        </h2>
                    </div>

                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                        <?php if (count($recentMatches) == 0): ?>
                            <div class="p-8 text-center text-slate-400 text-sm">
                                <i class="fa-solid fa-gamepad text-3xl mb-2 block opacity-40"></i>
                                ยังไม่มีแมตช์ที่บันทึกผลแล้ว
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm text-slate-600">
                                    <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                        <tr>
                                            <th class="p-4">รายการ</th>
                                            <th class="p-4">คู่แข่งขัน</th>
                                            <th class="p-4 text-center">ผลการแข่ง</th>
                                            <th class="p-4 text-right">เวลา</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($recentMatches as $m): ?>
                                        <tr class="hover:bg-slate-50/80 transition-colors">
                                            <td class="p-4 text-xs text-slate-400 max-w-[120px] truncate"><?php echo htmlspecialchars($m['tournament_name']); ?></td>
                                            <td class="p-4 font-bold text-xs">
                                                <span class="text-brand-orange"><?php echo htmlspecialchars($m['team1_name'] ?? '(bye)'); ?></span>
                                                <span class="text-slate-400 font-normal mx-1">vs</span>
                                                <span class="text-blue-600"><?php echo htmlspecialchars($m['team2_name'] ?? '(bye)'); ?></span>
                                            </td>
                                            <td class="p-4 text-center">
                                                <?php if ($m['status'] == 'walkover'): ?>
                                                    <span class="px-2 py-0.5 rounded bg-rose-100 text-rose-700 text-[10px] font-bold uppercase">Walkover</span>
                                                <?php else: ?>
                                                    <span class="font-display font-bold text-slate-900 px-2 py-0.5 rounded bg-slate-100 border border-slate-200">
                                                        <?php echo $m['team1_score']; ?> - <?php echo $m['team2_score']; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4 text-right text-[11px] text-slate-400 whitespace-nowrap">
                                                <?php echo htmlspecialchars($m['completed_at']); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </main>
    </div>

    <!-- CountUp Animation Script & Progress Bars Runner -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // CountUp สำหรับตัวเลขสถิติ
            const counters = document.querySelectorAll('[data-countup]');
            counters.forEach(counter => {
                const target = +counter.getAttribute('data-countup');
                if (isNaN(target)) return;
                
                let count = 0;
                const speed = 25;
                const increment = Math.max(1, Math.ceil(target / speed));

                const updateCount = () => {
                    count += increment;
                    if (count < target) {
                        counter.innerText = count;
                        setTimeout(updateCount, 20);
                    } else {
                        counter.innerText = target;
                    }
                };
                updateCount();
            });

            // Animate Progress Bars สไตล์ Esports
            setTimeout(() => {
                const bars = document.querySelectorAll('.progress-bar-fill');
                bars.forEach(bar => {
                    const targetWidth = bar.getAttribute('data-width');
                    bar.style.width = targetWidth + '%';
                });
            }, 300);
        });
    </script>
</body>
</html>