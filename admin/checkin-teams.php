<?php
// admin/checkin-teams.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
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
$error = '';
$success = '';

// ==========================================
// อัปเกรดฐานข้อมูลอัตโนมัติ: สร้างตารางเก็บประวัตินักกีฬาตัวจริงถาวร (ไม่หายแม้ลบทัวร์นาเมนต์)
// ==========================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS player_checkin_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id INT NOT NULL,
            tournament_id INT NULL,
            checked_in_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // ข้ามหากสร้างแล้ว
}

// ตรวจสอบโหมดของทัวร์นาเมนต์ที่เลือก (solo หรือ team)
$tournament = null;
$isSolo = false;
if ($tournamentId) {
    $tQuery = $pdo->prepare("
        SELECT t.*, g.play_mode 
        FROM tournaments t 
        JOIN games g ON t.game_id = g.game_id 
        WHERE t.tournament_id = :id
    ");
    $tQuery->execute(['id' => $tournamentId]);
    $tournament = $tQuery->fetch();
    if ($tournament && $tournament['play_mode'] === 'solo') {
        $isSolo = true;
    }
}

// เช็คอินสมาชิกจาก Tournament Roster ทีละคน
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'player_checkin') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $playerId = (int) ($_POST['player_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT tr.tournament_registration_id, tr.tournament_id, tr.status, p.display_name,
                u.username, t.name AS team_name
            FROM tournament_registration_members trm
            JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
            JOIN players p ON p.player_id = trm.player_id
            LEFT JOIN users u ON u.user_id = p.user_id
            LEFT JOIN teams t ON t.team_id = tr.team_id
            WHERE tr.tournament_registration_id = :registration_id AND trm.player_id = :player_id
              AND tr.tournament_id = :tournament_id');
        $stmt->execute(['registration_id' => $registrationId, 'player_id' => $playerId, 'tournament_id' => $tournamentId]);
        $member = $stmt->fetch();
        $windowStmt = $pdo->prepare('SELECT checkin_open_at, checkin_close_at FROM tournaments WHERE tournament_id = :tournament_id');
        $windowStmt->execute(['tournament_id' => $tournamentId]);
        $window = $windowStmt->fetch();
        $now = time();
        $windowOpen = (!$window['checkin_open_at'] || strtotime($window['checkin_open_at']) <= $now)
            && (!$window['checkin_close_at'] || strtotime($window['checkin_close_at']) >= $now);

        if (!$member || $member['status'] !== 'approved') {
            $error = 'ไม่พบสมาชิกใน Tournament Roster ที่ได้รับอนุมัติ';
        } elseif (!$windowOpen) {
            $error = 'อยู่นอกช่วงเวลา Check-in ของ Tournament นี้';
        } else {
            markRosterPlayerCheckedIn($pdo, $registrationId, $playerId, (int) $_SESSION['user_id']);
            $success = 'เช็คอิน ' . ($member['display_name'] ?: $member['username']) . ' รายบุคคลเรียบร้อยแล้ว';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'checkin') {
    $token = trim($_POST['token'] ?? '');
    $lookupStmt = $pdo->prepare('SELECT COALESCE(t.name, u.username, \'ผู้สมัครเดี่ยว\') AS participant_name
        FROM tournament_registrations tr
        LEFT JOIN teams t ON t.team_id = tr.team_id
        LEFT JOIN players p ON p.player_id = tr.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE tr.tournament_id = :tournament_id AND tr.qr_code_token = :token AND tr.status = \'approved\'');
    $lookupStmt->execute(['tournament_id' => $tournamentId, 'token' => $token]);
    $lookupName = $lookupStmt->fetchColumn();
    if ($lookupName) {
        header('Location: checkin-teams.php?tournament_id=' . $tournamentId . '&team_search=' . urlencode($lookupName));
        exit;
    }
    $error = 'ไม่พบ QR Token ของ Registration ที่ได้รับอนุมัติใน Tournament นี้';
}

// ดึงทัวร์นาเมนต์ที่กำลังแข่งหรือเปิดรับสมัคร พร้อม gender_category และ play_mode
$tournaments = $pdo->query("
    SELECT t.tournament_id, t.name, t.gender_category, g.play_mode 
    FROM tournaments t 
    JOIN games g ON t.game_id = g.game_id
    WHERE t.status IN ('ongoing', 'registration_closed', 'registration_open') 
    ORDER BY t.created_at DESC
")->fetchAll();

$registrations = [];
$checkedInCount = 0;
$totalCount = 0;

if ($tournamentId) {
    if ($isSolo) {
        $sql = "
            SELECT tr.*, u.username AS participant_name
            FROM tournament_registrations tr
            JOIN players p ON p.player_id = tr.player_id
            JOIN users u ON u.user_id = p.user_id
            WHERE tr.tournament_id = :tid AND tr.status = 'approved'
        ";
        $params = ['tid' => $tournamentId];

        if ($teamSearch !== '') {
            $sql .= " AND u.username LIKE :search";
            $params['search'] = "%{$teamSearch}%";
        }
        $sql .= " ORDER BY u.username ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $registrations = $stmt->fetchAll();

        $allStmt = $pdo->prepare("SELECT checkin_status FROM tournament_registrations WHERE tournament_id = :tid AND status = 'approved'");
        $allStmt->execute(['tid' => $tournamentId]);
        $allRows = $allStmt->fetchAll();

        $totalCount = count($allRows);
        foreach ($allRows as $at) {
            if ($at['checkin_status'] === 'checked_in') $checkedInCount++;
        }
    } else {
        $sql = "
            SELECT tr.*, t.name AS participant_name
            FROM tournament_registrations tr
            JOIN teams t ON t.team_id = tr.team_id
            WHERE tr.tournament_id = :tid AND tr.status = 'approved'
        ";
        $params = ['tid' => $tournamentId];

        if ($teamSearch !== '') {
            $sql .= " AND t.name LIKE :search";
            $params['search'] = "%{$teamSearch}%";
        }
        $sql .= " ORDER BY t.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $registrations = $stmt->fetchAll();

        $allStmt = $pdo->prepare("SELECT checkin_status FROM tournament_registrations WHERE tournament_id = :tid AND status = 'approved'");
        $allStmt->execute(['tid' => $tournamentId]);
        $allRows = $allStmt->fetchAll();

        $totalCount = count($allRows);
        foreach ($allRows as $at) {
            if ($at['checkin_status'] === 'checked_in') $checkedInCount++;
        }
    }
}

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
                            <span>จำนวนทั้งหมด: <?php echo $totalCount; ?> รายการ</span>
                        </div>
                    </div>

                    <form method="POST" class="flex flex-col sm:flex-row gap-3 pt-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="checkin">
                        
                        <div class="relative flex-1">
                            <span class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <i class="fa-solid fa-barcode text-lg"></i>
                            </span>
                            <input type="text" name="token" autofocus required
                                class="w-full bg-slate-50 border-2 border-slate-200 rounded-xl pl-12 pr-4 py-3.5 text-base sm:text-lg font-mono font-bold text-slate-900 tracking-widest uppercase focus:bg-white focus:outline-none focus:border-brand-orange transition-all placeholder-slate-400"
                                placeholder="สแกน หรือพิมพ์รหัสเช็คอินที่นี่...">
                        </div>

                        <button type="submit" 
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
                        if ($r['checkin_status'] == 'checked_in') {
                            $checkedInList[] = $r;
                        } else {
                            $pendingList[] = $r;
                        }
                    }
                ?>

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
                                        <th class="p-3.5 text-center">รหัส Token</th>
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
                                        <td class="p-3.5 text-center font-mono text-[11px] text-slate-500 tracking-wider">
                                            <?php echo htmlspecialchars($r['qr_code_token'] ?? '-'); ?>
                                        </td>
                                        <td class="p-3.5 text-center text-[11px] text-emerald-700 font-semibold">
                                            <?php echo !empty($r['checkin_at']) ? date('H:i:s d/m/Y', strtotime($r['checkin_at'])) : '-'; ?>
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
                                        <th class="p-3.5 text-center">รหัส Token</th>
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
                                        <td class="p-3.5 text-center font-mono text-[11px] text-slate-500 tracking-wider">
                                            <?php echo htmlspecialchars($r['qr_code_token'] ?? '-'); ?>
                                        </td>
                                        <td class="p-3.5 text-center">
                                            <?php $progress = getRegistrationCheckinProgress($pdo, (int) $r['tournament_registration_id']); ?>
                                            <span class="inline-block px-2.5 py-0.5 rounded-full <?php echo $progress['checked_in'] > 0 ? 'bg-amber-50 text-amber-700 border-amber-200' : 'bg-slate-50 text-slate-600 border-slate-200'; ?> border text-[10px] font-bold">
                                                <?php echo $progress['checked_in']; ?>/<?php echo $progress['required']; ?> คน
                                            </span>
                                        </td>
                                    </tr>
                                    <?php
                                        $memberStmt = $pdo->prepare('SELECT trm.player_id, trm.is_required_for_checkin, trm.member_roles,
                                                trm.checkin_status, p.display_name, u.username
                                            FROM tournament_registration_members trm
                                            JOIN players p ON p.player_id = trm.player_id
                                            LEFT JOIN users u ON u.user_id = p.user_id
                                            WHERE trm.tournament_registration_id = :registration_id
                                            ORDER BY trm.is_required_for_checkin DESC, trm.is_starter DESC, u.username');
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
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                                <input type="hidden" name="action" value="player_checkin">
                                                                <input type="hidden" name="registration_id" value="<?php echo (int) $r['tournament_registration_id']; ?>">
                                                                <input type="hidden" name="player_id" value="<?php echo (int) $member['player_id']; ?>">
                                                                <button type="submit" class="font-bold underline">เช็กอิน</button>
                                                            </form>
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

    <!-- Script สำหรับทำ Real-time Search -->
    <script>
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
        });
    </script>
</body>
</html>