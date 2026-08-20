<?php
// admin/manage-teams.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/registration_status.php';
requireRole('admin');
ensureTournamentRosterTables($pdo);
ensureRegistrationStatusHistoryTable($pdo);

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$filterCategory = $_GET['category'] ?? 'all';
$error = '';
$success = '';

// ดึงรายการทัวร์นาเมนต์ทั้งหมดมาทำตัวเลือก Dropdown พร้อมเช็ก play_mode และ gender_category ของเกม
$allTournaments = $pdo->query("
    SELECT t.tournament_id, t.name, t.status, t.gender_category, g.play_mode 
    FROM tournaments t 
    JOIN games g ON t.game_id = g.game_id 
    ORDER BY t.created_at DESC
")->fetchAll();

// หากยังไม่ได้เลือกทัวร์นาเมนต์ ให้ดึงตัวแรกรายการล่าสุดมาใช้เป็น Default (ถ้ามี)
if (!$tournamentId && count($allTournaments) > 0) {
    $tournamentId = (int) $allTournaments[0]['tournament_id'];
}

$tournament = null;
$isSolo = false;
$isOpenGame = false;
if ($tournamentId) {
    $tStmt = $pdo->prepare("
        SELECT t.*, g.play_mode, g.name AS game_name
        FROM tournaments t 
        JOIN games g ON t.game_id = g.game_id 
        WHERE t.tournament_id = :id
    ");
    $tStmt->execute(['id' => $tournamentId]);
    $tournament = $tStmt->fetch();
    if ($tournament) {
        if ($tournament['play_mode'] === 'solo') {
            $isSolo = true;
        }
        $isOpenGame = (stripos($tournament['game_name'], 'open') !== false || stripos($tournament['name'], 'open') !== false);
    }
}

// 1. Admin เพิ่มผู้เล่นเดี่ยว หรือ ทีม เข้าทัวร์นาเมนต์โดยตรง + สร้าง QR Code Token
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $tournamentId) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        if ($isSolo && $_POST['action'] === 'add_solo') {
            $playerId = (int) ($_POST['player_id'] ?? 0);
            if ($playerId <= 0) {
                $error = 'กรุณาพิมพ์ค้นหา และ "คลิกเลือกชื่อผู้เล่น" จากรายการที่ปรากฏขึ้นมาเท่านั้นครับ';
            } else {
                $check = $pdo->prepare("SELECT tournament_registration_id FROM tournament_registrations WHERE tournament_id = :tid AND player_id = :pid");
                $check->execute(['tid' => $tournamentId, 'pid' => $playerId]);

                if ($check->fetch()) {
                    $error = 'ผู้เล่นนี้อยู่ในทัวร์นาเมนต์นี้แล้ว';
                } else {
                    $qrToken = strtoupper(bin2hex(random_bytes(5)));
                    $insert = $pdo->prepare("
                        INSERT INTO tournament_registrations (tournament_id, player_id, category, status, qr_code_token)
                        VALUES (:tid, :pid, 'open', 'approved', :qr_token)
                    ");
                    $insert->execute(['tid' => $tournamentId, 'pid' => $playerId, 'qr_token' => $qrToken]);
                    snapshotTournamentRoster($pdo, (int) $pdo->lastInsertId(), null, $playerId);

                    // อัปเดต is_athlete = 1 ให้ผู้เล่น
                    $pdo->prepare("
                        UPDATE users u
                        JOIN players p ON p.user_id = u.user_id
                        SET u.is_athlete = 1
                        WHERE p.player_id = ?
                    ")->execute([$playerId]);

                    $success = 'เพิ่มผู้เล่นเดี่ยวและสร้างรหัส QR Code เช็คอินเรียบร้อยแล้ว';
                }
            }
        } elseif (!$isSolo && $_POST['action'] === 'add_team') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            if ($teamId <= 0) {
                $error = 'กรุณาพิมพ์ค้นหา และ "คลิกเลือกชื่อทีม" จากรายการที่ปรากฏขึ้นมาเท่านั้นครับ';
            } else {
                $check = $pdo->prepare("SELECT tournament_registration_id FROM tournament_registrations WHERE tournament_id = :tid AND team_id = :team_id");
                $check->execute(['tid' => $tournamentId, 'team_id' => $teamId]);

                if ($check->fetch()) {
                    $error = 'ทีมนี้อยู่ในทัวร์นาเมนต์แล้ว';
                } else {
                    $qrToken = strtoupper(bin2hex(random_bytes(5)));
                    $insert = $pdo->prepare("
                        INSERT INTO tournament_registrations (tournament_id, team_id, category, status, qr_code_token)
                        VALUES (:tid, :team_id, 'open', 'approved', :qr_token)
                    ");
                    $insert->execute(['tid' => $tournamentId, 'team_id' => $teamId, 'qr_token' => $qrToken]);
                    snapshotTournamentRoster($pdo, (int) $pdo->lastInsertId(), $teamId, null);

                    // อัปเดต is_athlete = 1 ให้กับสมาชิกในทีม
                    $pdo->prepare("
                        UPDATE users u
                        JOIN team_members tm ON tm.player_id = u.user_id OR tm.player_id IN (SELECT player_id FROM players WHERE user_id = u.user_id)
                        SET u.is_athlete = 1
                        WHERE tm.team_id = ? AND tm.is_active = 1
                    ")->execute([$teamId]);

                    $success = 'เพิ่มทีมและสร้างรหัส QR Code เช็คอินเรียบร้อยแล้ว';
                }
            }
        }
    }
}

// 2. อนุมัติคำขอสมัคร + สร้าง QR Code Token
if (isset($_GET['approve']) && $tournamentId) {
    $regId = (int) $_GET['approve'];
    
    $regQuery = $pdo->prepare("SELECT team_id, player_id, qr_code_token FROM tournament_registrations WHERE tournament_registration_id = :id");
    $regQuery->execute(['id' => $regId]);
    $regData = $regQuery->fetch();
    $targetTeamId = $regData['team_id'] ?? null;
    $targetPlayerId = $regData['player_id'] ?? null;
    $existingToken = $regData['qr_code_token'] ?? '';
    if ($regData) {
        recordRegistrationStatus($pdo, $regId, 'approved', (int) $_SESSION['user_id'], 'อนุมัติโดยผู้ดูแลระบบ');
        snapshotTournamentRoster($pdo, $regId, $targetTeamId ? (int) $targetTeamId : null, $targetPlayerId ? (int) $targetPlayerId : null);
    }

    if (empty($existingToken)) {
        $qrToken = strtoupper(bin2hex(random_bytes(5)));
        $pdo->prepare("
            UPDATE tournament_registrations 
            SET status = 'approved', qr_code_token = :qr_token 
            WHERE tournament_registration_id = :id AND tournament_id = :tid
        ")->execute(['qr_token' => $qrToken, 'id' => $regId, 'tid' => $tournamentId]);
    } else {
        $pdo->prepare("
            UPDATE tournament_registrations 
            SET status = 'approved' 
            WHERE tournament_registration_id = :id AND tournament_id = :tid
        ")->execute(['id' => $regId, 'tid' => $tournamentId]);
    }

    if ($targetTeamId) {
        $pdo->prepare("
            UPDATE users u
            JOIN team_members tm ON tm.player_id = u.user_id OR tm.player_id IN (SELECT player_id FROM players WHERE user_id = u.user_id)
            SET u.is_athlete = 1
            WHERE tm.team_id = ? AND tm.is_active = 1
        ")->execute([$targetTeamId]);
    }
    if ($targetPlayerId) {
        $pdo->prepare("
            UPDATE users u
            JOIN players p ON p.user_id = u.user_id
            SET u.is_athlete = 1
            WHERE p.player_id = ?
        ")->execute([$targetPlayerId]);
    }

    $success = 'อนุมัติและออกรหัส QR Code สำหรับเช็คอินเรียบร้อยแล้ว';
}

// 3. ปฏิเสธคำขอสมัคร
if (isset($_GET['reject']) && $tournamentId) {
    $regId = (int) $_GET['reject'];
    recordRegistrationStatus($pdo, $regId, 'rejected', (int) $_SESSION['user_id'], 'ปฏิเสธโดยผู้ดูแลระบบ');
    $pdo->prepare("UPDATE tournament_registrations SET status = 'rejected' WHERE tournament_registration_id = :id AND tournament_id = :tid")
        ->execute(['id' => $regId, 'tid' => $tournamentId]);
    $success = 'ปฏิเสธคำขอเรียบร้อยแล้ว';
}

// 4. ถอนออกจากการแข่งขันโดยเก็บประวัติ Registration ไว้
if (isset($_GET['remove']) && $tournamentId) {
    $regId = (int) $_GET['remove'];
    $pdo->prepare("UPDATE tournament_registrations
        SET participation_status = 'withdrawn', reviewed_by = :reviewed_by, reviewed_at = NOW(),
            review_note = 'ถอนออกจากการแข่งขันโดยผู้ดูแลระบบ'
        WHERE tournament_registration_id = :id AND tournament_id = :tid")
        ->execute(['reviewed_by' => $_SESSION['user_id'], 'id' => $regId, 'tid' => $tournamentId]);
    $success = 'ถอนทีมออกจากทัวร์นาเมนต์แล้ว โดยยังเก็บประวัติการสมัครไว้';
}

$pending = [];
$approved = [];
$availableItems = [];

if ($tournament) {
    $paramsPending = ['tid' => $tournamentId];
    $paramsApproved = ['tid' => $tournamentId];

    if (!$isOpenGame && $filterCategory !== 'all') {
        if (!$isSolo) {
            $paramsPending['cat'] = $filterCategory;
            $paramsApproved['cat'] = $filterCategory;
        }
    }

    if ($isSolo) {
        $pendingStmt = $pdo->prepare("
            SELECT tr.tournament_registration_id AS reg_id, COALESCE(p.display_name, u.username, 'Unknown Player') AS name, 'open' AS team_category, NOW() AS registered_at
            FROM tournament_registrations tr
            JOIN players p ON p.player_id = tr.player_id
            LEFT JOIN users u ON u.user_id = p.user_id
            WHERE tr.tournament_id = :tid AND tr.status = 'pending'
            ORDER BY reg_id DESC
        ");
        $pendingStmt->execute(['tid' => $tournamentId]);
        $pending = $pendingStmt->fetchAll();

        $approvedStmt = $pdo->prepare("
            SELECT tr.tournament_registration_id AS reg_id, p.player_id AS target_id, COALESCE(p.display_name, u.username, 'Unknown Player') AS name, 'open' AS team_category, tr.qr_code_token
            FROM tournament_registrations tr
            JOIN players p ON p.player_id = tr.player_id
            LEFT JOIN users u ON u.user_id = p.user_id
            WHERE tr.tournament_id = :tid AND tr.status = 'approved'
            ORDER BY reg_id DESC
        ");
        $approvedStmt->execute(['tid' => $tournamentId]);
        $approved = $approvedStmt->fetchAll();

        $availableStmt = $pdo->prepare("
            SELECT p.player_id AS id, COALESCE(p.display_name, u.username, 'Unknown Player') AS name 
            FROM players p
            LEFT JOIN users u ON u.user_id = p.user_id
            WHERE p.player_id NOT IN (
                SELECT player_id FROM tournament_registrations WHERE tournament_id = :tid AND player_id IS NOT NULL
            )
            ORDER BY name
        ");
        $availableStmt->execute(['tid' => $tournamentId]);
        $availableItems = $availableStmt->fetchAll();

    } else {
        // ใช้ COALESCE(tr.category, t.team_category, 'open') เพื่อดึงหมวดหมู่ที่สมัครจริงมาใช้งาน
        $pendingQuery = "
            SELECT tr.tournament_registration_id AS reg_id, t.name, COALESCE(tr.category, t.team_category, 'open') AS team_category, NOW() AS registered_at
            FROM tournament_registrations tr
            JOIN teams t ON t.team_id = tr.team_id
            WHERE tr.tournament_id = :tid AND tr.status = 'pending'
        ";
        if (!$isOpenGame && $filterCategory !== 'all') {
            $pendingQuery .= " AND COALESCE(tr.category, t.team_category, 'open') = :cat";
        }
        $pendingQuery .= " ORDER BY reg_id DESC";

        $pendingStmt = $pdo->prepare($pendingQuery);
        $pendingStmt->execute($paramsPending);
        $pending = $pendingStmt->fetchAll();

        $approvedQuery = "
            SELECT tr.tournament_registration_id AS reg_id, t.team_id AS target_id, t.name, COALESCE(tr.category, t.team_category, 'open') AS team_category, tr.qr_code_token
            FROM tournament_registrations tr
            JOIN teams t ON t.team_id = tr.team_id
            WHERE tr.tournament_id = :tid AND tr.status = 'approved'
        ";
        if (!$isOpenGame && $filterCategory !== 'all') {
            $approvedQuery .= " AND COALESCE(tr.category, t.team_category, 'open') = :cat";
        }
        $approvedQuery .= " ORDER BY reg_id DESC";

        $approvedStmt = $pdo->prepare($approvedQuery);
        $approvedStmt->execute($paramsApproved);
        $approved = $approvedStmt->fetchAll();

        $availableStmt = $pdo->prepare("
            SELECT team_id AS id, name FROM teams
            WHERE team_id NOT IN (
                SELECT team_id FROM tournament_registrations WHERE tournament_id = :tid AND team_id IS NOT NULL
            )
            ORDER BY name
        ");
        $availableStmt->execute(['tid' => $tournamentId]);
        $availableItems = $availableStmt->fetchAll();
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทีมสมัคร Tournament - Korat Esport</title>
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
            <a href="manage-teams.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-people-group w-5 text-center text-brand-orange"></i>
                <span>ทีมสมัคร Tournament</span>
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

    <div class="flex-1 ml-64 min-h-screen flex flex-col">

        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    จัดการผู้สมัคร/ทีมแข่งขัน <span class="text-brand-orange">(MANAGE REGISTRATIONS)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">อนุมัติการสมัคร เพิ่มผู้เล่น/ทีมเข้าแข่งขันโดยตรง และออกรหัส QR Code เช็คอิน</p>
            </div>
            
            <a href="manage-tournament.php" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-arrow-left"></i> กลับไปจัดการทัวร์นาเมนต์
            </a>
        </header>

        <main class="p-8 space-y-8 flex-1">

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-3">
                <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider">
                    <i class="fa-solid fa-trophy text-brand-orange mr-1"></i> เลือกรายการทัวร์นาเมนต์ที่ต้องการจัดการ
                </label>
                <form method="GET">
                    <select name="tournament_id" onchange="this.form.submit()" 
                        class="w-full md:w-1/2 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-900 font-bold focus:bg-white focus:outline-none focus:border-brand-orange transition-all cursor-pointer">
                        <?php if (count($allTournaments) == 0): ?>
                            <option value="">-- ยังไม่มีการสร้างทัวร์นาเมนต์ในระบบ --</option>
                        <?php endif; ?>

                        <?php foreach ($allTournaments as $tItem): ?>
                            <option value="<?php echo $tItem['tournament_id']; ?>" <?php echo ($tItem['tournament_id'] == $tournamentId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tItem['name']); ?> [โหมด: <?php echo ($tItem['play_mode'] === 'solo') ? 'เดี่ยว (Solo)' : 'ทีม (Team)'; ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if (!$tournament): ?>
                <div class="bg-white rounded-2xl border border-slate-200 p-12 text-center text-slate-400">
                    <i class="fa-solid fa-trophy text-4xl mb-3 block opacity-40 text-brand-orange"></i>
                    ยังไม่มีข้อมูลทัวร์นาเมนต์ โปรดเพิ่มทัวร์นาเมนต์ก่อนในหน้า <a href="manage-tournament.php" class="text-brand-orange font-bold underline">จัดการทัวร์นาเมนต์</a>
                </div>
            <?php else: ?>

                <?php if (!$isOpenGame && !$isSolo): ?>
                    <div class="flex items-center gap-2 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
                        <span class="text-xs font-bold text-slate-500 uppercase mr-2">กรองตามประเภท:</span>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=all" class="px-4 py-2 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'all') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">ทั้งหมด</a>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=male" class="px-4 py-2 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'male') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">ทีมชาย</a>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=female" class="px-4 py-2 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'female') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">ทีมหญิง</a>
                        <a href="?tournament_id=<?php echo $tournamentId; ?>&category=open" class="px-4 py-2 rounded-xl text-xs font-bold <?php echo ($filterCategory === 'open') ? 'bg-brand-orange text-white shadow-sm' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>">Open</a>
                    </div>
                <?php endif; ?>

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

                <?php if (count($pending) > 0): ?>
                    <div class="bg-white rounded-2xl border border-amber-200 shadow-sm overflow-hidden">
                        <div class="p-4 border-b border-amber-100 bg-amber-50/50 flex items-center justify-between">
                            <h2 class="text-xs font-bold uppercase tracking-wider text-amber-700 flex items-center gap-2">
                                <i class="fa-solid fa-clock text-amber-500"></i>
                                รายการสมัครที่รออนุมัติ (<?php echo count($pending); ?> รายการ)
                            </h2>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-slate-600">
                                <thead class="bg-amber-100/40 text-xs uppercase font-bold text-slate-500 border-b border-amber-200">
                                    <tr>
                                        <th class="p-4"><?php echo $isSolo ? 'ชื่อผู้เล่น' : 'ชื่อทีม'; ?></th>
                                        <?php if (!$isSolo): ?><th class="p-4 text-center">ประเภท</th><?php endif; ?>
                                        <th class="p-4">วันที่ส่งคำขอ</th>
                                        <th class="p-4 text-right">การอนุมัติ & ออก QR</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-amber-100">
                                    <?php foreach ($pending as $p): ?>
                                    <tr class="hover:bg-amber-50/30 transition-colors">
                                        <td class="p-4 font-bold text-slate-900">
                                            <i class="fa-solid <?php echo $isSolo ? 'fa-user' : 'fa-shield-halved'; ?> text-brand-orange mr-2"></i>
                                            <?php 
                                                $cleanTeamName = preg_replace('/\[.*\]/', '', $p['name']);
                                                echo htmlspecialchars(trim($cleanTeamName)); 
                                            ?>
                                        </td>
                                        <?php if (!$isSolo): ?>
                                            <td class="p-4 text-center">
                                                <?php 
                                                    $categoryVal = $p['team_category'] ?? '';
                                                    if ($categoryVal === 'female') {
                                                        echo '<span class="px-2 py-0.5 rounded text-xs bg-pink-50 text-pink-600 font-bold">ทีมหญิง</span>';
                                                    } elseif ($categoryVal === 'male') {
                                                        echo '<span class="px-2 py-0.5 rounded text-xs bg-blue-50 text-blue-600 font-bold">ทีมชาย</span>';
                                                    } else {
                                                        echo '<span class="px-2 py-0.5 rounded text-xs bg-purple-50 text-purple-600 font-bold">Open</span>';
                                                    }
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                        <td class="p-4 text-xs text-slate-500">
                                            <?php echo htmlspecialchars($p['registered_at']); ?>
                                        </td>
                                        <td class="p-4 text-right space-x-2">
                                            <a href="?tournament_id=<?php echo $tournamentId; ?>&category=<?php echo $filterCategory; ?>&approve=<?php echo $p['reg_id']; ?>"
                                               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-all shadow-sm">
                                                <i class="fa-solid fa-qrcode"></i> อนุมัติ & ออก QR
                                            </a>
                                            <a href="?tournament_id=<?php echo $tournamentId; ?>&category=<?php echo $filterCategory; ?>&reject=<?php echo $p['reg_id']; ?>"
                                               onclick="return confirm('ปฏิเสธคำขอนี้ใช่หรือไม่?')"
                                               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-all">
                                                <i class="fa-solid fa-xmark"></i> ปฏิเสธ
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($tournament['status'] == 'registration_open'): ?>
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                        <div class="border-b border-slate-100 pb-3">
                            <h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                                <i class="fa-solid fa-user-plus text-brand-orange"></i>
                                <?php echo $isSolo ? 'เพิ่มผู้เล่นเดี่ยวเข้าแข่งขันโดยตรง' : 'เพิ่มทีมเข้าทัวร์นาเมนต์โดยตรง (เฉพาะเกม ' . htmlspecialchars($tournament['game_name']) . ')'; ?>
                            </h2>
                            <p class="text-xs text-slate-500 mt-1">พิมพ์ค้นหาแล้ว "คลิกเลือกชื่อ" จากรายการที่ปรากฏขึ้นมา จากนั้นกดเพิ่มเข้าแข่งขัน</p>
                        </div>

                        <form method="POST" class="flex flex-col sm:flex-row items-center gap-4">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="<?php echo $isSolo ? 'add_solo' : 'add_team'; ?>">
                            <input type="hidden" name="<?php echo $isSolo ? 'player_id' : 'team_id'; ?>" id="selected_item_id" required>

                            <div class="relative w-full sm:w-auto flex-1">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </div>
                                <input type="text" id="item_search_input" autocomplete="off"
                                    placeholder="<?php echo $isSolo ? 'พิมพ์ชื่อผู้เล่น...' : 'พิมพ์ชื่อทีมเพื่อค้นหา...'; ?>" 
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-11 pr-4 py-3 text-sm text-slate-900 font-medium focus:bg-white focus:outline-none focus:border-brand-orange transition-all">
                                
                                <div id="item_search_results" class="hidden absolute left-0 right-0 mt-1 bg-white border border-slate-200 rounded-xl shadow-lg max-h-60 overflow-y-auto z-50 divide-y divide-slate-100">
                                </div>
                            </div>

                            <button type="submit" 
                                class="w-full sm:w-auto px-6 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs uppercase tracking-wider transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer whitespace-nowrap">
                                <i class="fa-solid fa-plus"></i>
                                <span>เพิ่ม&ออก QR</span>
                            </button>
                        </form>
                    </div>

                    <script>
                        const availableItemsData = [
                            <?php foreach ($availableItems as $item): ?>
                            { id: "<?php echo $item['id']; ?>", name: "<?php echo addslashes(htmlspecialchars($item['name'])); ?>" },
                            <?php endforeach; ?>
                        ];

                        document.addEventListener('DOMContentLoaded', () => {
                            const searchInput = document.getElementById('item_search_input');
                            const resultsContainer = document.getElementById('item_search_results');
                            const selectedItemIdInput = document.getElementById('selected_item_id');

                            searchInput.addEventListener('input', function() {
                                const keyword = this.value.trim().toLowerCase();
                                if (keyword === '') {
                                    resultsContainer.classList.add('hidden');
                                    resultsContainer.innerHTML = '';
                                    selectedItemIdInput.value = '';
                                    return;
                                }

                                const filtered = availableItemsData.filter(item => item.name.toLowerCase().includes(keyword));
                                
                                if (filtered.length === 0) {
                                    resultsContainer.innerHTML = '<div class="p-3 text-xs text-slate-400 text-center">ไม่พบข้อมูลนี้ในระบบ หรืออาจเข้าร่วมไปแล้ว</div>';
                                    resultsContainer.classList.remove('hidden');
                                    selectedItemIdInput.value = '';
                                    return;
                                }

                                let html = '';
                                filtered.forEach(item => {
                                    html += `<div class="p-3 text-sm font-medium text-slate-700 hover:bg-orange-50 hover:text-brand-orange cursor-pointer transition-colors" data-id="${item.id}" data-name="${item.name}">
                                        <i class="fa-solid <?php echo $isSolo ? 'fa-user' : 'fa-shield'; ?> text-xs mr-2 text-brand-orange"></i>${item.name}
                                    </div>`;
                                });

                                resultsContainer.innerHTML = html;
                                resultsContainer.classList.remove('hidden');
                            });

                            resultsContainer.addEventListener('click', function(e) {
                                const target = e.target.closest('div[data-id]');
                                if (target) {
                                    searchInput.value = target.getAttribute('data-name');
                                    selectedItemIdInput.value = target.getAttribute('data-id');
                                    resultsContainer.classList.add('hidden');
                                }
                            });

                            document.addEventListener('click', function(e) {
                                if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
                                    resultsContainer.classList.add('hidden');
                                }
                            });
                        });
                    </script>
                <?php endif; ?>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden space-y-4 mt-6">
                    <div class="p-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-700">
                            <?php echo $isSolo ? 'ผู้เล่นเดี่ยวที่เข้าร่วมแล้ว' : 'ทีมที่เข้าร่วมแล้ว'; ?> (<?= count($approved) ?> / <?= $tournament['max_teams'] ?>)
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-600">
                            <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                <tr>
                                    <th class="p-4">ลำดับ</th>
                                    <th class="p-4"><?php echo $isSolo ? 'ชื่อผู้เล่น' : 'ชื่อทีม'; ?></th>
                                    <?php if (!$isSolo): ?><th class="p-4 text-center">ประเภท</th><?php endif; ?>
                                    <th class="p-4 text-center">รหัส QR Token</th>
                                    <th class="p-4 text-right">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if(empty($approved)): ?>
                                    <tr>
                                        <td colspan="5" class="p-8 text-center text-xs text-slate-400">ยังไม่มีผู้เข้าร่วมการแข่งขันในประเภทนี้</td>
                                    </tr>
                                <?php endif; ?>
                                <?php $idx = 1; foreach ($approved as $rowItem): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="p-4 text-xs font-mono font-bold text-slate-400">#<?php echo $idx++; ?></td>
                                    <td class="p-4 font-bold text-slate-900">
                                        <?php 
                                            $cleanTeamName = preg_replace('/\[.*\]/', '', $rowItem['name']);
                                            echo htmlspecialchars(trim($cleanTeamName)); 
                                        ?>
                                    </td>
                                    <?php if (!$isSolo): ?>
                                        <td class="p-4 text-center">
                                            <?php 
                                                $categoryVal = $rowItem['team_category'] ?? '';
                                                if ($categoryVal === 'female') {
                                                    echo '<span class="px-2 py-0.5 rounded text-xs bg-pink-50 text-pink-600 font-bold">ทีมหญิง</span>';
                                                } elseif ($categoryVal === 'male') {
                                                    echo '<span class="px-2 py-0.5 rounded text-xs bg-blue-50 text-blue-600 font-bold">ทีมชาย</span>';
                                                } else {
                                                    echo '<span class="px-2 py-0.5 rounded text-xs bg-purple-50 text-purple-600 font-bold">Open</span>';
                                                }
                                            ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="p-4 text-center font-mono text-xs font-bold text-brand-orange">
                                        <?= htmlspecialchars($rowItem['qr_code_token']) ?>
                                    </td>
                                    <td class="p-4 text-right">
                                        <a href="?tournament_id=<?= $tournamentId ?>&category=<?php echo $filterCategory; ?>&remove=<?= $rowItem['reg_id'] ?>" 
                                           onclick="return confirm('ยืนยันที่จะลบรายการนี้ออกจากทัวร์นาเมนต์หรือไม่?')"
                                           class="text-rose-600 font-bold text-xs hover:underline flex items-center justify-end gap-1">
                                           <i class="fa-solid fa-trash"></i> เอาออก
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php endif; ?>

        </main>
    </div>

</body>
</html>
