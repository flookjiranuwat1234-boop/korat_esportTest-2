<?php
// admin/checkin-teams.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/tournament_workflow.php';
requireRole('admin');
ensureTournamentRosterTables($pdo);
ensureTournamentCategorySchema($pdo);

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$teamSearch = trim($_GET['team_search'] ?? '');
$selectedCategoryId = (int) ($_GET['category_id'] ?? 0);
$selectedRegistrationId = (int) ($_GET['registration_id'] ?? 0);
$error = '';
$success = '';

// ตรวจสอบโหมดของทัวร์นาเมนต์ที่เลือก (solo หรือ team)
$tournament = null;
$isSolo = false;
$gameMissing = false;
$categories = [];
if ($tournamentId) {
    $tQuery = $pdo->prepare("
        SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t 
        LEFT JOIN games g ON t.game_id = g.game_id
        WHERE t.tournament_id = :id
    ");
    $tQuery->execute(['id' => $tournamentId]);
    $tournament = $tQuery->fetch();
    $gameMissing = $tournament && empty($tournament['game_name']);
    if ($tournament && !$gameMissing && $tournament['play_mode'] === 'solo') {
        $isSolo = true;
    }
    $categoryStmt = $pdo->prepare('SELECT tournament_category_id,
            COALESCE(NULLIF(category_code, \'\'), NULLIF(code, \'\')) AS category_code,
            COALESCE(NULLIF(label, \'\'), NULLIF(name, \'\')) AS label,
            checkin_open_at, checkin_deadline, checkin_required_roles
        FROM tournament_categories
        WHERE tournament_id = :id AND is_active = 1
        ORDER BY tournament_category_id');
    $categoryStmt->execute(['id' => $tournamentId]);
    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    $selectedCategory = null;
    foreach ($categories as $category) {
        if ((int) $category['tournament_category_id'] === $selectedCategoryId) {
            $selectedCategory = $category;
            break;
        }
    }
}

// เช็คอินสมาชิกจาก Tournament Roster ทีละคน
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'player_checkin') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $playerId = (int) ($_POST['player_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT tr.tournament_registration_id, tr.tournament_id, tr.status, p.display_name,
                u.username, t.name AS team_name, trm.member_roles, trm.is_starter, trm.is_required_for_checkin, trm.checkin_status, trm.roster_status
            FROM tournament_registration_members trm
            JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
            JOIN players p ON p.player_id = trm.player_id
            LEFT JOIN users u ON u.user_id = p.user_id
            LEFT JOIN teams t ON t.team_id = tr.team_id
            LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
            WHERE tr.tournament_registration_id = :registration_id AND trm.player_id = :player_id
              AND trm.roster_status = 'active' AND tr.status = 'approved'
              AND tr.participation_status NOT IN ('withdrawn', 'disqualified')
              AND tr.tournament_id = :tournament_id");
        $stmt->execute(['registration_id' => $registrationId, 'player_id' => $playerId, 'tournament_id' => $tournamentId]);
        $member = $stmt->fetch();
        $windowStmt = $pdo->prepare('SELECT tour.checkin_open_at AS tournament_open, tour.checkin_close_at AS tournament_close,
                tc.checkin_open_at AS category_open, tc.checkin_deadline AS category_close
            FROM tournaments tour
            LEFT JOIN tournament_registrations tr ON tr.tournament_id = tour.tournament_id AND tr.tournament_registration_id = :registration_id
            LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
            WHERE tour.tournament_id = :tournament_id');
        $windowStmt->execute(['registration_id' => $registrationId, 'tournament_id' => $tournamentId]);
        $window = $windowStmt->fetch();
        $openAt = $window['category_open'] ?: $window['tournament_open'];
        $closeAt = $window['category_close'] ?: $window['tournament_close'];
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
        $windowOpen = $openAt && $closeAt
            && $now >= new DateTimeImmutable($openAt, new DateTimeZone('Asia/Bangkok'))
            && $now <= new DateTimeImmutable($closeAt, new DateTimeZone('Asia/Bangkok'));

        if (!$member) {
            $error = 'ไม่พบสมาชิกใน Tournament Roster ที่ได้รับอนุมัติ';
        } elseif (!$openAt || !$closeAt) {
            $error = 'ยังไม่ได้กำหนดเวลา Check-in';
        } elseif (!$windowOpen || !canCheckinRegistration($pdo, $registrationId, $now)) {
            $error = $now < new DateTimeImmutable($openAt, new DateTimeZone('Asia/Bangkok')) ? 'ยังไม่เปิด Check-in' : 'ขณะนี้อยู่นอกช่วงเวลา Check-in';
        } elseif (in_array($member['checkin_status'], ['checked_in', 'waived'], true)) {
            $error = 'สมาชิกคนนี้ Check-in แล้ว';
        } else {
            try {
                $pdo->beginTransaction();
                markRosterPlayerCheckedIn($pdo, $registrationId, $playerId, (int) $_SESSION['user_id']);
                $pdo->commit();
                $success = 'Check-in สำเร็จ: ' . ($member['display_name'] ?: $member['username']) . ' — ' . ($member['team_name'] ?: 'ผู้สมัครเดี่ยว');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'บันทึก Check-in ไม่สำเร็จ: ' . $exception->getMessage();
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'checkin') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    }
    if ($error) {
        $token = '';
    } else {
        $token = trim($_POST['token'] ?? '');
    }
    $lookupStmt = $pdo->prepare('SELECT tr.tournament_registration_id, tr.tournament_category_id
        FROM tournament_registrations tr
        WHERE tr.tournament_id = :tournament_id AND tr.qr_code_token = :token AND tr.status = \'approved\'');
    $lookupStmt->execute(['tournament_id' => $tournamentId, 'token' => $token]);
    $registration = $lookupStmt->fetch(PDO::FETCH_ASSOC);
    if (!$error && $registration) {
        header('Location: checkin-teams.php?tournament_id=' . $tournamentId . '&category_id=' . (int) $registration['tournament_category_id'] . '&registration_id=' . (int) $registration['tournament_registration_id']);
        exit;
    }
    if (!$error) {
        $otherTournamentStmt = $pdo->prepare('SELECT tournament_id FROM tournament_registrations WHERE qr_code_token = :token LIMIT 1');
        $otherTournamentStmt->execute(['token' => $token]);
        $otherTournamentId = $otherTournamentStmt->fetchColumn();
        $error = $otherTournamentId ? 'รหัสนี้เป็นของทัวร์นาเมนต์อื่น' : 'ไม่พบรหัสเช็กอิน';
    }
}

// ดึงทัวร์นาเมนต์ที่กำลังแข่งหรือเปิดรับสมัคร พร้อม gender_category และ play_mode
$tournaments = $pdo->query("
    SELECT t.tournament_id, t.name, t.gender_category, t.status, t.start_date, t.end_date, t.checkin_open_at, t.checkin_close_at, g.name AS game_name, g.play_mode
    FROM tournaments t 
    LEFT JOIN games g ON t.game_id = g.game_id
    WHERE t.status IN ('ongoing', 'registration_closed', 'registration_open') 
    ORDER BY t.created_at DESC
")->fetchAll();

$registrations = [];
$checkedInCount = 0;
$totalCount = 0;
$completeCount = 0;

if ($tournamentId) {
    $sql = "SELECT tr.*, COALESCE(t.name, p.display_name, u.username) AS participant_name,
            t.name AS team_name, t.logo_path, p.display_name, u.username,
            COALESCE(NULLIF(tc.label, ''), NULLIF(tc.name, ''), NULLIF(tr.category, '')) AS category_label,
            tc.category_code, tc.checkin_required_roles,
            (SELECT COUNT(*) FROM tournament_registration_members m WHERE m.tournament_registration_id = tr.tournament_registration_id AND m.roster_status = 'active' AND m.is_required_for_checkin = 1) AS required_count,
            (SELECT COUNT(*) FROM tournament_registration_members m WHERE m.tournament_registration_id = tr.tournament_registration_id AND m.roster_status = 'active' AND m.is_required_for_checkin = 1 AND m.checkin_status IN ('checked_in', 'waived')) AS checked_count,
            (SELECT MAX(m.checkin_at) FROM tournament_registration_members m WHERE m.tournament_registration_id = tr.tournament_registration_id AND m.roster_status = 'active') AS latest_checkin_at
        FROM tournament_registrations tr
        JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id AND tc.tournament_id = tr.tournament_id AND tc.is_active = 1
        LEFT JOIN teams t ON t.team_id = tr.team_id
        LEFT JOIN players p ON p.player_id = tr.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE tr.tournament_id = :tid AND tr.status = 'approved'
          AND tr.participation_status NOT IN ('withdrawn', 'disqualified')
          AND ((:is_solo = 1 AND p.player_id IS NOT NULL) OR (:is_solo = 0 AND t.team_id IS NOT NULL))";
    $params = ['tid' => $tournamentId, 'is_solo' => $isSolo ? 1 : 0];
    if ($selectedCategoryId > 0) { $sql .= ' AND tr.tournament_category_id = :category_id'; $params['category_id'] = $selectedCategoryId; }
    if ($selectedRegistrationId > 0) { $sql .= ' AND tr.tournament_registration_id = :registration_id'; $params['registration_id'] = $selectedRegistrationId; }
    if ($teamSearch !== '') {
        $sql .= " AND (t.name LIKE :search OR t.tag LIKE :search OR p.display_name LIKE :search OR u.username LIKE :search OR CAST(tr.tournament_registration_id AS CHAR) LIKE :search OR tr.qr_code_token LIKE :search)";
        $params['search'] = "%{$teamSearch}%";
    }
    $sql .= $isSolo ? ' ORDER BY COALESCE(p.display_name, u.username) ASC' : ' ORDER BY t.name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
    $totalCount = count($registrations);
}

foreach ($registrations as &$registration) {
    $registration['progress'] = getRegistrationCheckinProgress($pdo, (int) $registration['tournament_registration_id']);
    $registration['is_checkin_complete'] = $registration['progress']['complete'];
    if ($registration['is_checkin_complete']) $completeCount++;
}
unset($registration);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เช็คอินทีม/ผู้สมัคร - Korat Esport</title>
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
            <a href="manage-tournament.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-trophy w-5 text-center"></i>
                <span>จัดการทัวร์นาเมนต์</span>
            </a>
            <a href="manage-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-people-group w-5 text-center"></i>
                <span>จัดการทีม/ผู้สมัคร</span>
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
            <a href="checkin-teams.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-user-check w-5 text-center text-brand-orange"></i>
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
                            <?= htmlspecialchars($currentUser['username'] ?? 'Admin User') ?>
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

    <!-- MAIN CONTENT AREA -->
    <div class="flex-1 ml-64 min-h-screen flex flex-col">

        <!-- Header Panel -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    ระบบเช็คอินหน้าสนาม <span class="text-brand-orange">(CHECK-IN)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">สแกน QR Code หรือกรอกรหัสเช็คอินเพื่อรายงานตัวเข้าสนามแข่งขัน (รองรับแยกประเภท ชาย/หญิง)</p>
            </div>
            
            <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
            </a>
        </header>

        <main class="p-8 space-y-8 flex-1">

            <!-- SELECT TOURNAMENT CARD -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider">
                    <i class="fa-solid fa-trophy text-brand-orange mr-1"></i> เลือกทัวร์นาเมนต์ที่ต้องการดำเนินการเช็คอิน
                </label>
                <form method="GET" action="checkin-teams.php">
                    <select name="tournament_id" onchange="this.form.submit()" 
                        class="w-full md:w-1/2 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-900 font-medium focus:bg-white focus:outline-none focus:border-brand-orange transition-all cursor-pointer">
                        <option value="">-- กรุณาเลือกรายการแข่งขัน --</option>
                        <?php foreach ($tournaments as $t): ?>
                            <?php 
                                $genderLabel = '';
                                if ($t['gender_category'] == 'male') $genderLabel = ' [รุ่นชาย]';
                                elseif ($t['gender_category'] == 'female') $genderLabel = ' [รุ่นหญิง]';
                                else $genderLabel = ' [ทั่วไป]';
                            ?>
                            <option value="<?php echo $t['tournament_id']; ?>" <?php echo ($t['tournament_id'] == $tournamentId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name'] . $genderLabel); ?> [<?php echo ($t['play_mode'] === 'solo') ? 'เดี่ยว' : 'ทีม'; ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($tournamentId): ?>

                <?php
                    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
                    $checkinOpenAt = $selectedCategory['checkin_open_at'] ?? null;
                    $checkinCloseAt = $selectedCategory['checkin_deadline'] ?? null;
                    $checkinOpenAt = $checkinOpenAt ?: ($tournament['checkin_open_at'] ?? null);
                    $checkinCloseAt = $checkinCloseAt ?: ($tournament['checkin_close_at'] ?? null);
                    $checkinOpen = $checkinOpenAt && $checkinCloseAt
                        && $now >= new DateTimeImmutable($checkinOpenAt, new DateTimeZone('Asia/Bangkok'))
                        && $now <= new DateTimeImmutable($checkinCloseAt, new DateTimeZone('Asia/Bangkok'));
                    $checkinNotStarted = $checkinOpenAt && $now < new DateTimeImmutable($checkinOpenAt, new DateTimeZone('Asia/Bangkok'));
                    $checkinClosed = $checkinCloseAt && $now > new DateTimeImmutable($checkinCloseAt, new DateTimeZone('Asia/Bangkok'));
                    $checkinWindowLabel = (!$checkinOpenAt || !$checkinCloseAt) ? 'ยังไม่ได้กำหนดเวลา Check-in' : ($checkinNotStarted ? 'ยังไม่เปิด Check-in' : ($checkinClosed ? 'ปิด Check-in แล้ว' : 'เปิด Check-in'));
                ?>
                <?php if ($gameMissing): ?>
                    <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-center gap-3 shadow-sm">
                        <i class="fa-solid fa-triangle-exclamation text-xl shrink-0"></i>
                        <span>ไม่พบข้อมูลเกมของทัวร์นาเมนต์นี้</span>
                    </div>
                <?php endif; ?>
                <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">เกม / รูปแบบ</div><div class="mt-2 font-bold text-slate-900"><?= $gameMissing ? 'ไม่พบข้อมูลเกมของทัวร์นาเมนต์นี้' : htmlspecialchars($tournament['game_name']) . ' · ' . ($isSolo ? 'Solo' : 'Team') ?></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">สถานะ Check-in</div><div class="mt-2 font-bold <?= $checkinClosed ? 'text-rose-600' : ($checkinOpen ? 'text-emerald-600' : 'text-amber-600') ?>"><?= $checkinWindowLabel ?></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">เปิด Check-in</div><div class="mt-2 text-sm font-bold text-slate-900"><?= !empty($tournament['checkin_open_at']) ? date('d/m/Y H:i:s', strtotime($tournament['checkin_open_at'])) : 'ไม่กำหนด' ?></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">ปิด Check-in</div><div class="mt-2 text-sm font-bold text-slate-900"><?= $checkinCloseAt ? date('d/m/Y H:i:s', strtotime($checkinCloseAt)) : 'ไม่กำหนด' ?></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-wider text-slate-500">จำนวน Check-in</div><div class="mt-2 text-sm font-bold text-slate-900"><?= (int) $completeCount ?> / <?= (int) $totalCount ?> ครบ</div><div class="text-[10px] text-slate-500">ยังไม่ครบ <?= max(0, (int) $totalCount - (int) $completeCount) ?> รายการ</div></div>
                </section>

                <div class="flex gap-2 overflow-x-auto border-b border-slate-200 pb-1"><a href="?tournament_id=<?= $tournamentId ?>&category_id=0" class="min-w-max rounded-t-xl px-4 py-2 text-xs font-bold <?= !$selectedCategoryId ? 'border-b-2 border-brand-orange bg-orange-50 text-brand-orange' : 'text-slate-500' ?>">ทั้งหมด</a><?php foreach ($categories as $category): ?><a href="?tournament_id=<?= $tournamentId ?>&category_id=<?= (int) $category['tournament_category_id'] ?>" class="min-w-max rounded-t-xl px-4 py-2 text-xs font-bold <?= $selectedCategoryId === (int) $category['tournament_category_id'] ? 'border-b-2 border-brand-orange bg-orange-50 text-brand-orange' : 'text-slate-500' ?>"><?= htmlspecialchars($category['label'] ?: $category['category_code']) ?></a><?php endforeach; ?></div>

                <!-- Alert Messages -->
                <?php if ($error): ?>
                    <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-center gap-3 shadow-sm">
                        <i class="fa-solid fa-circle-xmark text-xl shrink-0 text-rose-500"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-3 shadow-sm">
                        <i class="fa-solid fa-circle-check text-xl shrink-0 text-emerald-500"></i>
                        <span class="font-bold"><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <!-- SCANNER & INPUT SECTION -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-100 pb-4 gap-2">
                        <div>
                            <h2 class="text-lg font-bold font-display text-slate-900 flex items-center gap-2">
                                <i class="fa-solid fa-qrcode text-brand-orange text-xl"></i>
                                สแกน QR Code หรือกรอกรหัสเช็คอิน
                            </h2>
                            <p class="text-xs text-slate-500 mt-1">QR ใช้ค้นหา Registration ส่วนการ Check-in ต้องกดให้สมาชิกใน Tournament Roster ทีละคน</p>
                        </div>

                        <div class="px-3 py-1.5 rounded-full bg-slate-100 text-slate-900 text-xs font-bold flex items-center gap-2">
                            <i class="fa-solid fa-users text-sm"></i>
                            <span>อนุมัติแล้ว: <?php echo $totalCount; ?> รายการ</span>
                        </div>
                    </div>

                    <form method="POST" class="flex flex-col sm:flex-row gap-3 pt-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="checkin">
                        
                        <div class="relative flex-1">
                            <span class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <i class="fa-solid fa-barcode text-lg"></i>
                            </span>
                            <input type="text" name="token" autofocus required <?= $checkinOpen ? '' : 'disabled' ?>
                                class="w-full bg-slate-50 border-2 border-slate-200 rounded-xl pl-12 pr-4 py-3.5 text-base sm:text-lg font-mono font-bold text-slate-900 tracking-widest uppercase focus:bg-white focus:outline-none focus:border-brand-orange transition-all placeholder-slate-400"
                                placeholder="สแกน หรือพิมพ์รหัสเช็คอินที่นี่...">
                        </div>

                        <button type="submit" <?= $checkinOpen ? '' : 'disabled' ?>
                            class="px-8 py-3.5 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm uppercase tracking-wider transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer shrink-0">
                            <i class="fa-solid fa-user-check"></i>
                            <span>ยืนยันเช็คอิน</span>
                        </button>
                    </form>
                </div>

                <!-- REAL-TIME SEARCH BAR -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                    <form method="GET" action="checkin-teams.php" id="searchFilterForm" class="relative">
                        <input type="hidden" name="tournament_id" value="<?php echo $tournamentId; ?>">
                        <input type="hidden" name="category_id" value="<?php echo $selectedCategoryId; ?>">
                        <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400">
                            <i class="fa-solid fa-magnifying-glass text-sm"></i>
                        </span>
                        <input type="text" name="team_search" id="teamSearchInput" value="<?php echo htmlspecialchars($teamSearch); ?>" placeholder="พิมพ์ค้นหาชื่อ (หลายตัวอักษรเพื่อกรองทั้ง 2 ช่อง)..."
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-11 pr-10 py-3 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium"
                            autocomplete="off">
                        <?php if ($teamSearch !== ''): ?>
                            <a href="checkin-teams.php?tournament_id=<?php echo $tournamentId; ?>" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-rose-500">
                                <i class="fa-solid fa-xmark"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <?php
                    $checkedInList = [];
                    $pendingList = [];
                    foreach ($registrations as $r) {
                        if (!empty($r['is_checkin_complete'])) {
                            $checkedInList[] = $r;
                        } else {
                            $pendingList[] = $r;
                        }
                    }
                ?>

                <?php if ($totalCount === 0): ?>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-800">
                        <div class="font-bold">ยังไม่มีทีม/ผู้เล่นที่ได้รับอนุมัติสำหรับทัวร์นาเมนต์นี้</div>
                        <p class="mt-1 text-xs">ระบบพบใบสมัครที่ยังไม่ผ่านสถานะ approved หรือยังไม่มีใบสมัครที่เชื่อมกับ Category และ Team/Player ที่ใช้งานได้</p>
                    </div>
                <?php endif; ?>

                <!-- 2-COLUMN SIDE-BY-SIDE LAYOUT -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                    
                    <!-- ฝั่งซ้าย: เช็คอินแล้ว -->
                    <div class="bg-white rounded-2xl border border-emerald-200 shadow-sm overflow-hidden">
                        <div class="p-4 border-b border-emerald-100 bg-emerald-50/70 flex items-center justify-between">
                            <h2 class="text-xs font-bold uppercase tracking-wider text-emerald-800 flex items-center gap-2">
                                <i class="fa-solid fa-circle-check text-emerald-600 text-sm"></i>
                                <?php echo $isSolo ? 'ผู้เล่นที่เช็คอินเข้าสนามแล้ว' : 'ทีมที่เช็คอินเข้าสนามแล้ว'; ?>
                            </h2>
                            <span class="px-2.5 py-0.5 rounded-full bg-emerald-200/60 text-emerald-800 text-xs font-bold">
                                <?php echo count($checkedInList); ?> รายการ
                            </span>
                        </div>

                        <div class="overflow-x-auto max-h-[550px] overflow-y-auto">
                            <table class="w-full text-left text-sm text-slate-600">
                                <thead class="bg-slate-50 text-[11px] uppercase font-bold text-slate-500 border-b border-slate-200 sticky top-0">
                                    <tr>
                                        <th class="p-3.5"><?php echo $isSolo ? 'ชื่อผู้เล่น' : 'ชื่อทีม / สโมสร'; ?></th>
                                        <th class="p-3.5 text-center">Category</th>
                                        <th class="p-3.5 text-center">เวลาเช็คอิน</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (count($checkedInList) == 0): ?>
                                        <tr>
                                            <td colspan="3" class="p-8 text-center text-slate-400 text-xs">
                                                ยังไม่มีรายการที่เช็คอิน
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach ($checkedInList as $r): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors bg-emerald-50/5">
                                        <td class="p-3.5 font-bold text-slate-900 text-xs">
                                            <i class="fa-solid <?php echo $isSolo ? 'fa-user' : 'fa-shield-halved'; ?> text-emerald-600 mr-1.5"></i>
                                            <?php echo htmlspecialchars($r['participant_name']); ?>
                                        </td>
                                        <td class="p-3.5 text-center text-[11px] text-slate-600">
                                            <?= htmlspecialchars($r['category_label'] ?: $r['category_code'] ?: $r['category'] ?: 'ไม่ระบุ') ?>
                                        </td>
                                        <td class="p-3.5 text-center text-[11px] text-emerald-700 font-semibold">
                                            <?php echo !empty($r['latest_checkin_at']) ? date('H:i:s d/m/Y', strtotime($r['latest_checkin_at'])) : '-'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ฝั่งขวา: ยังไม่เช็คอิน -->
                    <div class="bg-white rounded-2xl border border-amber-200 shadow-sm overflow-hidden">
                        <div class="p-4 border-b border-amber-100 bg-amber-50/70 flex items-center justify-between">
                            <h2 class="text-xs font-bold uppercase tracking-wider text-amber-800 flex items-center gap-2">
                                <i class="fa-solid fa-clock text-amber-600 text-sm"></i>
                                <?php echo $isSolo ? 'ผู้เล่นที่ยังไม่มารายงานตัว' : 'ทีมที่ยังไม่มารายงานตัว'; ?>
                            </h2>
                            <span class="px-2.5 py-0.5 rounded-full bg-amber-200/60 text-amber-800 text-xs font-bold">
                                <?php echo count($pendingList); ?> รายการ
                            </span>
                        </div>

                        <div class="overflow-x-auto max-h-[550px] overflow-y-auto">
                            <table class="w-full text-left text-sm text-slate-600">
                                <thead class="bg-slate-50 text-[11px] uppercase font-bold text-slate-500 border-b border-slate-200 sticky top-0">
                                    <tr>
                                        <th class="p-3.5"><?php echo $isSolo ? 'ชื่อผู้เล่น' : 'ชื่อทีม / สโมสร'; ?></th>
                                        <th class="p-3.5 text-center">Progress</th>
                                        <th class="p-3.5 text-center">สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php if (count($pendingList) == 0): ?>
                                        <tr>
                                            <td colspan="3" class="p-8 text-center text-slate-400 text-xs">
                                                ครบทุกรายการแล้ว! ไม่มีตกค้าง
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach ($pendingList as $r): ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="p-3.5 font-bold text-slate-900 text-xs">
                                            <i class="fa-solid <?php echo $isSolo ? 'fa-user' : 'fa-shield-halved'; ?> text-brand-orange mr-1.5"></i>
                                            <?php echo htmlspecialchars($r['participant_name']); ?>
                                        </td>
                                        <td class="p-3.5 text-center text-[11px] text-slate-600">
                                            <?= (int) $r['progress']['checked_in'] ?>/<?= (int) $r['progress']['required'] ?> คน
                                        </td>
                                        <td class="p-3.5 text-center">
                                            <?php $progress = getRegistrationCheckinProgress($pdo, (int) $r['tournament_registration_id']); ?>
                                            <span class="inline-block px-2.5 py-0.5 rounded-full <?php echo $progress['checked_in'] > 0 ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-slate-50 text-slate-600 border-slate-200'; ?> border text-[10px] font-bold">
                                                <?php echo $progress['checked_in']; ?>/<?php echo $progress['required']; ?> คน
                                            </span>
                                        </td>
                                    </tr>
                                    <?php
                                        $memberStmt = $pdo->prepare("SELECT trm.id AS roster_member_id, trm.player_id, trm.is_required_for_checkin, trm.member_roles,
                                            trm.is_starter, trm.checkin_status, trm.checkin_at, p.display_name, p.real_name, p.avatar_path, u.username
                                            FROM tournament_registration_members trm
                                            JOIN players p ON p.player_id = trm.player_id
                                            LEFT JOIN users u ON u.user_id = p.user_id
                                            WHERE trm.tournament_registration_id = :registration_id AND trm.roster_status = 'active'
                                            ORDER BY trm.is_required_for_checkin DESC, trm.is_starter DESC, u.username");
                                        $memberStmt->execute(['registration_id' => $r['tournament_registration_id']]);
                                        $rosterMembers = $memberStmt->fetchAll();
                                    ?>
                                    <tr class="bg-slate-50/70">
                                        <td colspan="3" class="p-3">
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach ($rosterMembers as $member): ?>
                                                    <?php $memberChecked = in_array($member['checkin_status'], ['checked_in', 'waived'], true); ?>
                                                            <span class="inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[10px] <?php echo $memberChecked ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'; ?>">
                                                        <?php echo htmlspecialchars($member['display_name'] ?: $member['username']); ?><?php echo $member['is_required_for_checkin'] ? ' *' : ''; ?>
                                                        <?php if (!$memberChecked): ?>
                                                            <button type="button" class="font-bold underline" onclick="openCheckinConfirm(<?= (int) $r['tournament_registration_id'] ?>, <?= (int) $member['player_id'] ?>, '<?= htmlspecialchars($member['real_name'] ?: $member['display_name'] ?: $member['username'], ENT_QUOTES) ?>')">เช็กอิน</button>
                                                        <?php else: ?>✓<?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                            <p class="mt-1 text-[10px] text-slate-400">* สมาชิกที่ต้อง Check-in ตาม Tournament Roster</p>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

            <?php endif; ?>

        </main>
    </div>

    <div id="checkinConfirmModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="w-full max-w-md rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 class="font-bold text-slate-900">ยืนยัน Check-in รายบุคคล</h3><button type="button" onclick="closeCheckinConfirm()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><div class="space-y-3 p-6 text-sm"><div class="rounded-xl bg-slate-50 p-4"><div class="text-xs text-slate-500">ผู้เล่น</div><div id="confirmPlayerName" class="font-bold text-slate-900"></div><div class="mt-2 text-xs text-slate-500">ระบบจะ Check-in เฉพาะสมาชิกคนนี้ ไม่ใช่ทั้งทีม</div></div><form method="POST" id="checkinConfirmForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="player_checkin"><input type="hidden" name="registration_id" id="confirmRegistrationId"><input type="hidden" name="player_id" id="confirmPlayerId"><div class="flex justify-end gap-2 pt-3"><button type="button" onclick="closeCheckinConfirm()" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold text-slate-700">ยกเลิก</button><button type="submit" class="rounded-lg bg-brand-orange px-4 py-2 text-xs font-bold text-white">ยืนยัน Check-in</button></div></form></div></div></div>

    <!-- Script สำหรับทำ Real-time Search -->
    <script>
        function openCheckinConfirm(registrationId, playerId, playerName) {
            document.getElementById('confirmRegistrationId').value = registrationId;
            document.getElementById('confirmPlayerId').value = playerId;
            document.getElementById('confirmPlayerName').textContent = playerName;
            document.getElementById('checkinConfirmModal').classList.remove('hidden');
            document.getElementById('checkinConfirmModal').classList.add('flex');
        }
        function closeCheckinConfirm() {
            document.getElementById('checkinConfirmModal').classList.add('hidden');
            document.getElementById('checkinConfirmModal').classList.remove('flex');
        }
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('teamSearchInput');
            if (searchInput) {
                searchInput.focus();
                const val = searchInput.value;
                searchInput.value = '';
                searchInput.value = val;

                let timeout = null;
                searchInput.addEventListener('input', () => {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => {
                        document.getElementById('searchFilterForm').submit();
                    }, 350);
                });
            }
            document.getElementById('checkinConfirmModal')?.addEventListener('click', event => {
                if (event.target.id === 'checkinConfirmModal') closeCheckinConfirm();
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') closeCheckinConfirm();
            });
        });
    </script>
</body>
</html>