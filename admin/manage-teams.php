<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/registration_status.php';
requireRole('admin');

ensureTournamentRosterTables($pdo);
ensureTournamentCategorySchema($pdo);
ensureRegistrationStatusHistoryTable($pdo);

$currentUser = [
    'username' => $_SESSION['username'] ?? 'Admin',
    'role' => $_SESSION['role'] ?? 'Administrator',
];

function statusBadge(string $status, string $type = 'approval'): string
{
    $approvedMap = [
        'pending' => ['label' => 'รอตรวจสอบ', 'class' => 'bg-yellow-100 text-yellow-700'],
        'approved' => ['label' => 'อนุมัติแล้ว', 'class' => 'bg-emerald-100 text-emerald-700'],
        'revision_required' => ['label' => 'ส่งกลับแก้ไข', 'class' => 'bg-orange-100 text-orange-700'],
        'rejected' => ['label' => 'ปฏิเสธ', 'class' => 'bg-red-100 text-red-700'],
        'withdrawn' => ['label' => 'ถอนตัว', 'class' => 'bg-slate-200 text-slate-700'],
        'disqualified' => ['label' => 'ตัดสิทธิ์', 'class' => 'bg-red-100 text-red-700'],
        'walkover' => ['label' => 'WO', 'class' => 'bg-red-700 text-white'],
    ];

    $checkinMap = [
        'not_checked_in' => ['label' => 'ยังไม่ Check-in', 'class' => 'bg-slate-200 text-slate-700'],
        'partial' => ['label' => 'Check-in บางส่วน', 'class' => 'bg-yellow-100 text-yellow-700'],
        'checked_in' => ['label' => 'Check-in ครบ', 'class' => 'bg-emerald-100 text-emerald-700'],
        'expired' => ['label' => 'หมดเวลา', 'class' => 'bg-red-100 text-red-700'],
        'waived' => ['label' => 'อนุโลม', 'class' => 'bg-sky-100 text-sky-700'],
    ];

    $map = $type === 'checkin' ? $checkinMap : $approvedMap;
    $info = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'bg-slate-100 text-slate-700'];
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-bold ' . $info['class'] . '">' . $info['label'] . '</span>';
}

function getCheckinCompletion(PDO $pdo, int $registrationId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_required,
            SUM(CASE WHEN checkin_status IN ('checked_in', 'waived') THEN 1 ELSE 0 END) AS checked_done
        FROM tournament_registration_members
        WHERE tournament_registration_id = :registration_id AND is_required_for_checkin = 1
    ");
    $stmt->execute(['registration_id' => $registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_required' => 0, 'checked_done' => 0];
    $total = (int) ($row['total_required'] ?? 0);
    $done = (int) ($row['checked_done'] ?? 0);
    if ($total === 0) {
        return ['status' => 'not_checked_in', 'done' => 0, 'total' => 0];
    }
    if ($done >= $total) {
        return ['status' => 'checked_in', 'done' => $done, 'total' => $total];
    }
    if ($done > 0) {
        return ['status' => 'partial', 'done' => $done, 'total' => $total];
    }
    return ['status' => 'not_checked_in', 'done' => 0, 'total' => $total];
}

function getRegistrationDetail(PDO $pdo, int $registrationId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            tr.tournament_registration_id,
            tr.tournament_id,
            tr.status,
            tr.participation_status,
            tr.seed_no,
            tr.roster_locked_at,
            tr.registered_at,
            tr.tournament_category_id,
            tc.category_code,
            tc.label AS category_label,
            COALESCE(team.name, p.display_name, u.username, 'ผู้สมัครเดี่ยว') AS display_name,
            COALESCE(captain_u.username, '-') AS captain_name,
            tour.name AS tournament_name,
            tour.game_id,
            g.name AS game_name
        FROM tournament_registrations tr
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
        LEFT JOIN teams team ON team.team_id = tr.team_id
        LEFT JOIN players p ON p.player_id = tr.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        LEFT JOIN players captain_p ON captain_p.player_id = team.captain_player_id
        LEFT JOIN users captain_u ON captain_u.user_id = captain_p.user_id
        LEFT JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
        LEFT JOIN games g ON g.game_id = tour.game_id
        WHERE tr.tournament_registration_id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $progress = getCheckinCompletion($pdo, $registrationId);
    $row['progress'] = $progress;
    $row['roster_count'] = (int) $pdo->query("SELECT COUNT(*) FROM tournament_registration_members WHERE tournament_registration_id = " . (int) $registrationId)->fetchColumn();
    return $row;
}

function getRegistrationMembers(PDO $pdo, int $registrationId): array
{
    $stmt = $pdo->prepare("
        SELECT
            trm.id,
            trm.player_id,
            trm.member_roles,
            trm.is_starter,
            trm.is_required_for_checkin,
            trm.checkin_status,
            u.username,
            p.display_name
        FROM tournament_registration_members trm
        LEFT JOIN players p ON p.player_id = trm.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE trm.tournament_registration_id = :registration_id
        ORDER BY trm.is_starter DESC, u.username ASC, p.display_name ASC
    ");
    $stmt->execute(['registration_id' => $registrationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$selectedCategoryId = (int) ($_GET['category_id'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$approvalStatus = trim((string) ($_GET['approval_status'] ?? 'all'));
$checkinStatus = trim((string) ($_GET['checkin_status'] ?? 'all'));
$drawStatus = trim((string) ($_GET['draw_status'] ?? 'all'));

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'approve_registration') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $approveStmt = $pdo->prepare('SELECT tr.*, tc.is_active AS category_active, tour.status AS tournament_status
            FROM tournament_registrations tr
            LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
                AND tc.tournament_id = tr.tournament_id
            JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
            WHERE tr.tournament_registration_id = :registration_id LIMIT 1');
        $approveStmt->execute(['registration_id' => $registrationId]);
        $registration = $approveStmt->fetch(PDO::FETCH_ASSOC);
        $validParticipant = false;
        if ($registration) {
            $participantStmt = $registration['team_id']
                ? $pdo->prepare('SELECT COUNT(*) FROM teams WHERE team_id = :id')
                : $pdo->prepare('SELECT COUNT(*) FROM players WHERE player_id = :id');
            $participantStmt->execute(['id' => (int) ($registration['team_id'] ?: $registration['player_id'])]);
            $validParticipant = (int) $participantStmt->fetchColumn() > 0;
        }
        if (!$registration || $registration['status'] !== 'pending') {
            $error = 'ใบสมัครนี้ไม่อยู่ในสถานะรอตรวจสอบ';
        } elseif ($registration['tournament_status'] === 'completed') {
            $error = 'Tournament จบการแข่งขันแล้ว ไม่สามารถอนุมัติผู้สมัครเพิ่มได้';
        } elseif ((int) ($registration['category_active'] ?? 0) !== 1) {
            $error = 'Category ของใบสมัครนี้ไม่พร้อมใช้งาน';
        } elseif (!$validParticipant) {
            $error = 'ไม่พบทีม/ผู้เล่นของใบสมัครนี้';
        } else {
            try {
                $pdo->beginTransaction();
                $token = (string) ($registration['qr_code_token'] ?? '');
                if ($token === '') $token = bin2hex(random_bytes(24));
                $update = $pdo->prepare("UPDATE tournament_registrations
                    SET status = 'approved', qr_code_token = :token, reviewed_by = :reviewed_by,
                        reviewed_at = NOW(), participation_status = 'registered'
                    WHERE tournament_registration_id = :registration_id AND status = 'pending'");
                $update->execute(['token' => $token, 'reviewed_by' => (int) $_SESSION['user_id'], 'registration_id' => $registrationId]);
                if ($update->rowCount() !== 1) throw new RuntimeException('ไม่สามารถเปลี่ยนสถานะใบสมัครได้');
                snapshotTournamentRoster($pdo, $registrationId, $registration['team_id'] ? (int) $registration['team_id'] : null, $registration['player_id'] ? (int) $registration['player_id'] : null);
                recordRegistrationStatus($pdo, $registrationId, 'approved', (int) $_SESSION['user_id'], 'อนุมัติใบสมัครจากหน้าจัดการผู้สมัคร', (string) $registration['status']);
                $pdo->commit();
                $success = 'อนุมัติใบสมัครและสร้าง QR Check-in เรียบร้อยแล้ว';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'อนุมัติใบสมัครไม่สำเร็จ';
            }
        }
    }
}

$allTournaments = $pdo->query(
    "SELECT t.tournament_id, t.name, t.status, t.start_date, t.end_date, t.checkin_open_at, t.checkin_close_at, g.name AS game_name, g.play_mode
     FROM tournaments t
     JOIN games g ON g.game_id = t.game_id
     ORDER BY t.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$tournamentId && !empty($allTournaments)) {
    $tournamentId = (int) $allTournaments[0]['tournament_id'];
}

$tournament = null;
if ($tournamentId) {
    $tStmt = $pdo->prepare("
        SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t
        JOIN games g ON g.game_id = t.game_id
        WHERE t.tournament_id = :id
        LIMIT 1
    ");
    $tStmt->execute(['id' => $tournamentId]);
    $tournament = $tStmt->fetch(PDO::FETCH_ASSOC);
}

$activeCategories = [];
if ($tournament) {
    $categoryStmt = $pdo->prepare("
        SELECT tournament_category_id, category_code, label, max_participants, format, is_active
        FROM tournament_categories
        WHERE tournament_id = :tid AND is_active = 1
        ORDER BY tournament_category_id ASC
    ");
    $categoryStmt->execute(['tid' => $tournamentId]);
    $activeCategories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($selectedCategoryId && !empty($activeCategories)) {
    $allowedIds = array_map('intval', array_column($activeCategories, 'tournament_category_id'));
    if (!in_array($selectedCategoryId, $allowedIds, true)) {
        $selectedCategoryId = 0;
    }
}

if (!$selectedCategoryId && !empty($activeCategories)) {
    $selectedCategoryId = (int) ($activeCategories[0]['tournament_category_id'] ?? 0);
}

$autoOpenRegistrationId = (int) ($_GET['registration_id'] ?? 0);
$autoOpenRegistration = $autoOpenRegistrationId ? getRegistrationDetail($pdo, $autoOpenRegistrationId) : null;
if ($autoOpenRegistrationId && (!$autoOpenRegistration || (int) $autoOpenRegistration['tournament_id'] !== $tournamentId)) {
    $autoOpenRegistrationId = 0;
    $autoOpenRegistration = null;
}
if ($autoOpenRegistration) {
    $selectedCategoryId = (int) $autoOpenRegistration['tournament_category_id'];
}
$autoOpenRegistrationMembers = $autoOpenRegistrationId ? getRegistrationMembers($pdo, $autoOpenRegistrationId) : [];

$summary = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'revision_required' => 0,
    'rejected' => 0,
    'withdrawn' => 0,
    'checkin_complete' => 0,
    'checkin_incomplete' => 0,
    'qualified' => 0,
    'disqualified' => 0,
];

if ($tournament) {
    $summarySql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN tr.status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN tr.status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN tr.status = 'revision_required' THEN 1 ELSE 0 END) AS revision_required,
            SUM(CASE WHEN tr.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN tr.status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn,
            SUM(CASE WHEN tr.status = 'approved' AND EXISTS (
                SELECT 1
                FROM tournament_registration_members trm
                WHERE trm.tournament_registration_id = tr.tournament_registration_id
                  AND trm.is_required_for_checkin = 1
                  AND trm.checkin_status IN ('checked_in', 'waived')
            ) AND NOT EXISTS (
                SELECT 1
                FROM tournament_registration_members trm2
                WHERE trm2.tournament_registration_id = tr.tournament_registration_id
                  AND trm2.is_required_for_checkin = 1
                  AND trm2.checkin_status NOT IN ('checked_in', 'waived')
            ) THEN 1 ELSE 0 END) AS checkin_complete,
            SUM(CASE WHEN tr.status = 'approved' AND EXISTS (
                SELECT 1
                FROM tournament_registration_members trm3
                WHERE trm3.tournament_registration_id = tr.tournament_registration_id
                  AND trm3.is_required_for_checkin = 1
                  AND trm3.checkin_status NOT IN ('checked_in', 'waived')
            ) THEN 1 ELSE 0 END) AS checkin_incomplete,
            SUM(CASE WHEN tr.participation_status = 'qualified_for_draw' THEN 1 ELSE 0 END) AS qualified,
            SUM(CASE WHEN tr.participation_status IN ('disqualified', 'walkover') THEN 1 ELSE 0 END) AS disqualified
        FROM tournament_registrations tr
        WHERE tr.tournament_id = :tid";

    if ($selectedCategoryId) {
        $summarySql .= " AND tr.tournament_category_id = :category_id";
    }

    $summaryStmt = $pdo->prepare($summarySql);
    $params = ['tid' => $tournamentId];
    if ($selectedCategoryId) {
        $params['category_id'] = $selectedCategoryId;
    }
    $summaryStmt->execute($params);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($summary as $key => $value) {
        $summary[$key] = (int) ($summaryRow[$key] ?? 0);
    }
}

$rows = [];
if ($tournament) {
    $query = "
        SELECT
            tr.tournament_registration_id,
            tr.tournament_id,
            tr.status,
            tr.checkin_status,
            tr.participation_status,
            tr.registered_at,
            tr.seed_no,
            tr.tournament_category_id,
            tc.category_code,
            tc.label AS category_label,
            tr.team_id,
            tr.player_id,
            CASE
                WHEN tr.team_id IS NOT NULL THEN team.name
                WHEN p.display_name IS NOT NULL AND TRIM(p.display_name) <> '' THEN p.display_name
                ELSE u.username
            END AS display_name,
            CASE
                WHEN tr.team_id IS NOT NULL THEN team.name
                ELSE COALESCE(p.display_name, u.username)
            END AS display_name_raw,
            COALESCE(team.name, COALESCE(p.display_name, u.username, 'ผู้เล่น')) AS raw_name,
            u.username,
            u.email
        FROM tournament_registrations tr
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
        LEFT JOIN teams team ON team.team_id = tr.team_id
        LEFT JOIN players p ON p.player_id = tr.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE tr.tournament_id = :tid";

    $bind = ['tid' => $tournamentId];

    if ($selectedCategoryId) {
        $query .= " AND tr.tournament_category_id = :category_id";
        $bind['category_id'] = $selectedCategoryId;
    }

    if ($search !== '') {
        $query .= " AND (
            team.name LIKE :search OR
            p.display_name LIKE :search OR
            u.username LIKE :search OR
            u.email LIKE :search
        )";
        $bind['search'] = '%' . $search . '%';
    }

    if ($approvalStatus !== 'all') {
        $query .= " AND tr.status = :approval_status";
        $bind['approval_status'] = $approvalStatus;
    }

    if ($drawStatus !== 'all') {
        $query .= " AND tr.participation_status = :draw_status";
        $bind['draw_status'] = $drawStatus;
    }

    $query .= " ORDER BY tr.registered_at DESC, tr.tournament_registration_id DESC";

    $rowsStmt = $pdo->prepare($query);
    $rowsStmt->execute($bind);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$isSolo = ($tournament['play_mode'] ?? 'team') === 'solo';
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้สมัคร/ทีมแข่งขัน</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
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
                            sidebar: '#0F172A'
                        }
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                        display: ['Orbitron', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style>
        body {
            background: #F4F6F9;
        }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover, .nav-item.active {
            background: rgba(255, 85, 0, 0.12);
            color: #FF5500;
            border-left: 4px solid #FF5500;
        }
        .admin-action-menu { width: 14rem; padding: 0.5rem; }
        .admin-action-item { min-height: 2.5rem; width: 100%; display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem 0.75rem; border-radius: 0.5rem; text-align: left; font-size: 0.75rem; font-weight: 600; line-height: 1.25rem; }
        .admin-action-item i { width: 1rem; text-align: center; flex: 0 0 1rem; }
        .admin-action-group { padding: 0.35rem 0.75rem 0.25rem; color: #94a3b8; font-size: 0.625rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
        .scrollbar-thin::-webkit-scrollbar { height: 6px; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
    </style>
</head>
<body class="text-slate-800 font-sans min-h-screen flex antialiased">
    <div id="adminSidebarBackdrop" class="fixed inset-0 z-40 hidden bg-slate-950/50 lg:hidden" aria-hidden="true"></div>
    <aside id="adminSidebar" class="w-64 bg-brand-sidebar text-slate-300 flex flex-col fixed inset-y-0 left-0 z-50 shadow-xl -translate-x-full transition-transform duration-200 lg:translate-x-0">
        <div class="p-6 border-b border-slate-800 flex items-center gap-3">
            <img src="../assets/img/logo.png" alt="Korat Esport" class="h-10 w-auto filter drop-shadow" onError="this.src='https://placehold.co/80x80/0F172A/FF5500?text=KE';">
            <div>
                <h1 class="font-display font-black text-lg text-white tracking-wider">KORAT <span class="text-brand-orange">ESPORT</span></h1>
                <p class="text-[10px] tracking-widest text-slate-400 uppercase font-semibold">Admin Command Center</p>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-1 text-sm font-medium">
            <a href="dashboard.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-chart-pie w-5 text-center"></i><span>หน้าหลัก (Dashboard)</span></a>
            <a href="manage-tournament.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-trophy w-5 text-center"></i><span>จัดการทัวร์นาเมนต์</span></a>
            <a href="manage-teams.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white"><i class="fa-solid fa-people-group w-5 text-center text-brand-orange"></i><span>จัดการผู้สมัคร/ทีมแข่งขัน</span></a>
            <a href="manage-members.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-users-gear w-5 text-center"></i><span>จัดการสมาชิก</span></a>
            <a href="manage-news.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-newspaper w-5 text-center"></i><span>จัดการข่าวสาร</span></a>
            <a href="manage-gallery.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-images w-5 text-center"></i><span>จัดการแกลเลอรี่</span></a>
            <a href="recommended-lodging.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-hotel w-5 text-center"></i><span>ที่พักแนะนำ</span></a>
            <a href="record-match.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-pen-to-square w-5 text-center"></i><span>บันทึกผลแมตช์</span></a>
            <a href="checkin-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-user-check w-5 text-center"></i><span>เช็คอินทีม</span></a>
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

    <div class="flex-1 ml-0 lg:ml-64 min-h-screen flex flex-col">
        <header class="bg-white border-b border-slate-200 px-4 sm:px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div class="flex items-center gap-3">
                <button type="button" id="adminMenuToggle" class="lg:hidden inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="เปิดเมนู Admin" aria-expanded="false">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h1 class="text-lg sm:text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                        <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                        จัดการผู้สมัคร/ทีมแข่งขัน <span class="hidden md:inline text-brand-orange">(REGISTRATION MANAGEMENT)</span>
                    </h1>
                    <p class="text-xs text-slate-500 mt-0.5">ตรวจใบสมัคร Tournament Roster การอนุมัติ และ Check-in</p>
                </div>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="manage-tournament.php?tournament_id=<?= (int) $tournamentId ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                    <i class="fa-solid fa-arrow-left"></i><span class="hidden sm:inline">กลับไปหน้าจัดการ Tournament</span><span class="sm:hidden">Tournament</span>
                </a>
                <a href="../pages/index.php" target="_blank" class="hidden md:inline-flex text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-2 rounded-lg">
                    <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
                </a>
            </div>
        </header>

        <main class="p-8 space-y-8">
            <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div class="w-full lg:w-72">
                        <label class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">Tournament</label>
                        <form method="GET" class="mt-2">
                            <select name="tournament_id" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium focus:border-brand-orange focus:bg-white focus:outline-none">
                                <?php foreach ($allTournaments as $item): ?>
                                    <option value="<?= (int) $item['tournament_id'] ?>" <?= ((int) $item['tournament_id'] === $tournamentId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($item['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php if ($tournament): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500">เกม</div>
                            <div class="mt-1 text-sm font-bold text-slate-800"><?= htmlspecialchars($tournament['game_name'] ?? '-') ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500">รูปแบบ</div>
                            <div class="mt-1 text-sm font-bold text-slate-800"><?= ($tournament['play_mode'] ?? 'team') === 'solo' ? 'Solo' : 'Team' ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500">Check-in</div>
                            <div class="mt-1 text-sm font-bold text-slate-800"><?= !empty($tournament['checkin_open_at']) ? date('d/m/Y', strtotime($tournament['checkin_open_at'])) : '-' ?> - <?= !empty($tournament['checkin_close_at']) ? date('d/m/Y', strtotime($tournament['checkin_close_at'])) : '-' ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($tournament): ?>
                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 overflow-x-auto scrollbar-thin">
                    <div class="flex flex-nowrap items-center gap-2 min-w-max">
                        <a href="?tournament_id=<?= $tournamentId ?>&category_id=0" class="px-4 py-2 rounded-xl text-xs font-bold <?= !$selectedCategoryId ? 'bg-brand-orange text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                            ทั้งหมด
                        </a>
                        <?php foreach ($activeCategories as $category): ?>
                            <a href="?tournament_id=<?= $tournamentId ?>&category_id=<?= (int) $category['tournament_category_id'] ?>" class="px-4 py-2 rounded-xl text-xs font-bold <?= ((int) $category['tournament_category_id'] === $selectedCategoryId) ? 'bg-brand-orange text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                                <?= htmlspecialchars($category['label'] ?: $category['category_code']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-4">
                    <?php
                    $summaryCards = [
                        ['label' => 'สมัครทั้งหมด', 'value' => $summary['total'], 'style' => 'bg-slate-100 text-slate-700 border-slate-200'],
                        ['label' => 'รอตรวจสอบ', 'value' => $summary['pending'], 'style' => 'bg-yellow-100 text-yellow-700 border-yellow-200'],
                        ['label' => 'อนุมัติแล้ว', 'value' => $summary['approved'], 'style' => 'bg-emerald-100 text-emerald-700 border-emerald-200'],
                        ['label' => 'ส่งกลับแก้ไข', 'value' => $summary['revision_required'], 'style' => 'bg-orange-100 text-orange-700 border-orange-200'],
                        ['label' => 'ปฏิเสธ', 'value' => $summary['rejected'], 'style' => 'bg-red-100 text-red-700 border-red-200'],
                        ['label' => 'ถอนตัว', 'value' => $summary['withdrawn'], 'style' => 'bg-slate-200 text-slate-700 border-slate-300'],
                    ];
                    ?>
                    <?php foreach ($summaryCards as $card): ?>
                        <div class="rounded-2xl border p-4 <?= $card['style'] ?> shadow-sm">
                            <div class="text-[10px] uppercase tracking-[0.2em] font-bold"><?= $card['label'] ?></div>
                            <div class="mt-2 text-2xl font-black"><?= (int) $card['value'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </section>

                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                        <input type="hidden" name="category_id" value="<?= $selectedCategoryId ?>">
                        <div class="md:col-span-2 xl:col-span-2">
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">ค้นหา</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ชื่อทีม / ผู้เล่น / Username / Email" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">สถานะการอนุมัติ</label>
                            <select name="approval_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                                <option value="all" <?= $approvalStatus === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                                <option value="pending" <?= $approvalStatus === 'pending' ? 'selected' : '' ?>>รอตรวจสอบ</option>
                                <option value="approved" <?= $approvalStatus === 'approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                                <option value="revision_required" <?= $approvalStatus === 'revision_required' ? 'selected' : '' ?>>ส่งกลับแก้ไข</option>
                                <option value="rejected" <?= $approvalStatus === 'rejected' ? 'selected' : '' ?>>ปฏิเสธ</option>
                                <option value="withdrawn" <?= $approvalStatus === 'withdrawn' ? 'selected' : '' ?>>ถอนตัว</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">Check-in</label>
                            <select name="checkin_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                                <option value="all" <?= $checkinStatus === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                                <option value="not_checked_in" <?= $checkinStatus === 'not_checked_in' ? 'selected' : '' ?>>ยังไม่ Check-in</option>
                                <option value="partial" <?= $checkinStatus === 'partial' ? 'selected' : '' ?>>Check-in บางส่วน</option>
                                <option value="checked_in" <?= $checkinStatus === 'checked_in' ? 'selected' : '' ?>>Check-in ครบ</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">สิทธิ์จัดสาย</label>
                            <select name="draw_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                                <option value="all" <?= $drawStatus === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                                <option value="registered" <?= $drawStatus === 'registered' ? 'selected' : '' ?>>ยังไม่พร้อม</option>
                                <option value="qualified_for_draw" <?= $drawStatus === 'qualified_for_draw' ? 'selected' : '' ?>>พร้อมจัดสาย</option>
                                <option value="disqualified" <?= $drawStatus === 'disqualified' ? 'selected' : '' ?>>ตัดสิทธิ์</option>
                                <option value="walkover" <?= $drawStatus === 'walkover' ? 'selected' : '' ?>>WO</option>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="flex-1 rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow">กรอง</button>
                            <a href="?tournament_id=<?= $tournamentId ?>" class="flex-1 rounded-xl bg-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 text-center hover:bg-slate-300">ล้าง</a>
                        </div>
                    </form>
                </section>

                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-visible">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 p-4 border-b border-slate-200 bg-slate-50/60">
                        <h2 class="text-sm font-bold uppercase tracking-[0.2em] text-slate-700">รายการสมัคร</h2>
                        <button type="button" class="inline-flex items-center gap-2 rounded-xl bg-brand-orange px-4 py-2 text-sm font-bold text-white hover:bg-brand-glow">
                            <i class="fa-solid fa-plus"></i> + เพิ่มทีม/ผู้แข่งขัน
                        </button>
                    </div>

                    <?php if (empty($rows)): ?>
                        <div class="p-12 text-center text-slate-500">
                            <div class="text-4xl text-brand-orange mb-3"><i class="fa-solid fa-box-open"></i></div>
                            <p class="text-lg font-bold text-slate-700">ยังไม่มีทีมสมัครใน Category นี้</p>
                            <p class="text-sm mt-1">เพิ่มทีม/ผู้แข่งขันเพื่อเริ่มจัดการ Tournament</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto overflow-y-visible">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-100 text-slate-600 text-[10px] uppercase tracking-[0.2em]">
                                    <tr>
                                        <th class="px-4 py-3">ทีม/ผู้แข่งขัน</th>
                                        <th class="px-4 py-3">Category</th>
                                        <th class="px-4 py-3">สมัครเมื่อ</th>
                                        <th class="px-4 py-3">Roster</th>
                                        <th class="px-4 py-3">การอนุมัติ</th>
                                        <th class="px-4 py-3">Check-in</th>
                                        <th class="px-4 py-3">สิทธิ์จัดสาย</th>
                                        <th class="px-4 py-3">Group/Seed</th>
                                        <th class="px-4 py-3 text-right">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <?php foreach ($rows as $row): ?>
                                        <?php $progress = getCheckinCompletion($pdo, (int) $row['tournament_registration_id']); ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-10 w-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-700 font-bold">
                                                        <?= htmlspecialchars(strtoupper(substr($row['display_name_raw'] ?? 'U', 0, 1))) ?>
                                                    </div>
                                                    <div>
                                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($row['display_name_raw'] ?? '-') ?></div>
                                                        <div class="text-[11px] text-slate-500"><?= htmlspecialchars($row['username'] ?? 'User') ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-700">
                                                    <?= htmlspecialchars($row['category_label'] ?: $row['category_code'] ?: 'Open') ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-slate-600"><?= !empty($row['registered_at']) ? date('d/m/Y H:i', strtotime($row['registered_at'])) : '-' ?></td>
                                            <td class="px-4 py-3 text-slate-700">
                                                <?php
                                                $rosterStmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registration_members WHERE tournament_registration_id = :id");
                                                $rosterStmt->execute(['id' => (int) $row['tournament_registration_id']]);
                                                $rosterCount = (int) $rosterStmt->fetchColumn();
                                                ?>
                                                <?= $rosterCount ?> คน
                                            </td>
                                            <td class="px-4 py-3"><?= statusBadge((string) ($row['status'] ?? 'pending'), 'approval') ?></td>
                                            <td class="px-4 py-3"><?= statusBadge($progress['status'], 'checkin') ?></td>
                                            <td class="px-4 py-3"><?= statusBadge((string) ($row['participation_status'] ?: 'registered')) ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= $row['seed_no'] ? '#' . (int) $row['seed_no'] : '-' ?></td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <a href="#" class="inline-flex h-9 items-center rounded-lg bg-slate-100 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-200">รายละเอียด</a>
                                                    <?php if (($row['status'] ?? '') === 'pending'): ?>
                                                        <form method="POST" class="inline-flex" onsubmit="return confirm('ยืนยันอนุมัติใบสมัครนี้?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="approve_registration">
                                                            <input type="hidden" name="registration_id" value="<?= (int) $row['tournament_registration_id'] ?>">
                                                            <button type="submit" class="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700">อนุมัติ</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <div class="relative">
                                                        <?php $registrationId = (int) $row['tournament_registration_id']; ?>
                                                        <button type="button" class="admin-action-toggle registration-action-toggle relative z-30 pointer-events-auto inline-flex h-9 items-center rounded-lg bg-brand-orange px-3 text-xs font-semibold text-white hover:bg-brand-glow" data-registration-id="<?= $registrationId ?>" data-menu-target="registration-menu-<?= $registrationId ?>" data-action-menu="registration-menu-<?= $registrationId ?>" aria-expanded="false" aria-controls="registration-menu-<?= $registrationId ?>">จัดการ</button>
                                                        <div id="registration-menu-<?= $registrationId ?>" class="admin-action-menu registration-action-menu fixed hidden z-[70] rounded-xl border border-slate-200 bg-white shadow-xl" data-registration-id="<?= $registrationId ?>" role="menu">
                                                            <div class="admin-action-group">การตรวจสอบ</div>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-clipboard-check text-slate-400"></i>ตรวจ Tournament Roster</a>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-pen-to-square text-slate-400"></i>เปลี่ยนสถานะใบสมัคร</a>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-user-check text-slate-400"></i>ดูสถานะ Check-in</a>
                                                            <div class="my-1 border-t border-slate-100"></div>
                                                            <div class="admin-action-group">การแข่งขัน</div>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-qrcode text-slate-400"></i>แสดง QR</a>
                                                            <?php if (($row['participation_status'] ?? '') === 'qualified_for_draw' || !empty($row['seed_no'])): ?>
                                                                <a href="manage-tournament.php?tournament_id=<?= (int) $tournamentId ?>" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-sitemap text-slate-400"></i>ดู Group/Bracket</a>
                                                            <?php endif; ?>
                                                            <div class="my-1 border-t border-slate-100"></div>
                                                            <div class="admin-action-group">คำสั่งที่มีผลกระทบ</div>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>" class="admin-action-item text-red-600 hover:bg-red-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-arrow-right-from-bracket"></i>ถอนออกจากการแข่งขัน</a>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>" class="admin-action-item text-red-600 hover:bg-red-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-ban"></i>ตัดสิทธิ์</a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($autoOpenRegistrationId): ?>
        <div id="registrationDetailModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 p-4" data-registration-id="<?= (int) $autoOpenRegistrationId ?>" data-tournament-id="<?= (int) $tournamentId ?>" data-category-id="<?= (int) $selectedCategoryId ?>">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4 bg-slate-50">
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500">รายละเอียดใบสมัคร</p>
                        <h3 class="text-lg font-black text-slate-900">
                            <?= htmlspecialchars($autoOpenRegistration['display_name'] ?? 'ผู้สมัคร') ?>
                        </h3>
                    </div>
                    <button type="button" id="registrationDetailClose" class="text-slate-400 hover:text-slate-600 p-1" aria-label="ปิดรายละเอียดใบสมัคร"><i class="fa-solid fa-xmark text-xl"></i></button>
                </div>
                <div class="overflow-y-auto p-6 space-y-5">
                    <?php if (!$autoOpenRegistration): ?>
                        <div class="p-8 text-center text-slate-500">ไม่พบข้อมูล Registration ที่เลือก</div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">ประเภทการแข่งขัน</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= htmlspecialchars($autoOpenRegistration['category_label'] ?: $autoOpenRegistration['category_code'] ?: 'Open') ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">กัปตันทีม</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= htmlspecialchars($autoOpenRegistration['captain_name'] ?? '-') ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">Tournament Roster</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= (int) $autoOpenRegistration['roster_count'] ?> คน</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">สรุปการเช็กอิน</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= (int) $autoOpenRegistration['progress']['done'] ?>/<?= (int) $autoOpenRegistration['progress']['total'] ?></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-2">ข้อมูลสมัคร</div>
                                <div class="space-y-2 text-sm text-slate-700">
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Tournament</span><span class="font-bold text-slate-900"><?= htmlspecialchars($autoOpenRegistration['tournament_name'] ?? '-') ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">สถานะการอนุมัติ</span><span><?= statusBadge((string) ($autoOpenRegistration['status'] ?? 'pending'), 'approval') ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">สถานะพร้อมจัดสาย</span><span class="font-bold"><?= htmlspecialchars(($autoOpenRegistration['participation_status'] ?: 'registered') === 'registered' ? 'รอ Check-in' : ($autoOpenRegistration['participation_status'] ?: 'registered')) ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Group/Seed</span><span class="font-bold"><?= $autoOpenRegistration['seed_no'] ? '#' . (int) $autoOpenRegistration['seed_no'] : '-' ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">สมัครเมื่อ</span><span class="font-bold"><?= !empty($autoOpenRegistration['registered_at']) ? date('d/m/Y H:i', strtotime($autoOpenRegistration['registered_at'])) : '-' ?></span></div>
                                </div>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-2">สรุปการเช็กอิน</div>
                                <div class="space-y-2 text-sm text-slate-700">
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">รวมที่ต้อง Check-in</span><span class="font-bold"><?= (int) $autoOpenRegistration['progress']['total'] ?> คน</span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Check-in ครบแล้ว</span><span class="font-bold text-emerald-700"><?= (int) $autoOpenRegistration['progress']['done'] ?> คน</span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Check-in ไม่ครบ</span><span class="font-bold text-amber-700"><?= max(0, (int) $autoOpenRegistration['progress']['total'] - (int) $autoOpenRegistration['progress']['done']) ?> คน</span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Roster Locked</span><span class="font-bold"><?= !empty($autoOpenRegistration['roster_locked_at']) ? date('d/m/Y H:i', strtotime($autoOpenRegistration['roster_locked_at'])) : '—' ?></span></div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 overflow-hidden">
                            <div class="bg-slate-50 px-4 py-3 border-b border-slate-200 text-sm font-bold text-slate-700">สมาชิกที่ลงแข่งขัน</div>
                            <?php if (empty($autoOpenRegistrationMembers)): ?>
                                <div class="p-6 text-center text-slate-500">ยังไม่มีสมาชิกใน Roster</div>
                            <?php else: ?>
                                <div class="divide-y divide-slate-200">
                                    <?php foreach ($autoOpenRegistrationMembers as $member): ?>
                                        <?php $memberStatus = in_array($member['checkin_status'] ?? 'not_checked_in', ['checked_in', 'waived'], true) ? 'checked_in' : 'not_checked_in'; ?>
                                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 px-4 py-3">
                                            <div>
                                                <div class="font-bold text-slate-800"><?= htmlspecialchars($member['display_name'] ?: $member['username'] ?: 'Player') ?></div>
                                                <div class="text-[11px] text-slate-500"><?= htmlspecialchars($member['member_roles'] ?: 'player') ?> • <?= $member['is_starter'] ? 'ตัวจริง' : 'สำรอง' ?></div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-bold <?= $memberStatus === 'checked_in' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' ?>">
                                                    <?= $memberStatus === 'checked_in' ? (strtolower((string) ($member['checkin_status'] ?? '')) === 'waived' ? 'อนุโลม' : 'Check-in') : 'ยังไม่ Check-in' ?>
                                                </span>
                                                <?php if ($member['is_required_for_checkin']): ?>
                                                    <span class="inline-flex items-center rounded-full bg-orange-100 text-orange-700 px-2.5 py-1 text-[10px] font-bold">ต้องเช็กอิน</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="border-t border-slate-200 bg-slate-50 px-6 py-4 flex justify-end gap-2">
                    <button type="button" id="registrationDetailCloseFooter" class="inline-flex items-center rounded-xl bg-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-300">ปิด</button>
                    <button type="button" id="registrationManageAction" data-registration-id="<?= (int) $autoOpenRegistrationId ?>" class="inline-flex items-center rounded-xl bg-brand-orange px-4 py-2 text-xs font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-list-check mr-1"></i>จัดการใบสมัคร</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const adminSidebar = document.getElementById('adminSidebar');
            const adminSidebarBackdrop = document.getElementById('adminSidebarBackdrop');
            const adminMenuToggle = document.getElementById('adminMenuToggle');
            const actionToggles = document.querySelectorAll('.admin-action-toggle, .registration-action-toggle');
            const actionMenus = document.querySelectorAll('.admin-action-menu, .registration-action-menu');
            const registrationDetailModal = document.getElementById('registrationDetailModal');
            const registrationDetailClose = document.getElementById('registrationDetailClose');
            const registrationDetailCloseFooter = document.getElementById('registrationDetailCloseFooter');
            const registrationManageAction = document.getElementById('registrationManageAction');
            let registrationDetailDirty = false;

            function setAdminSidebarOpen(isOpen) {
                if (!adminSidebar || !adminSidebarBackdrop || !adminMenuToggle) return;
                adminSidebar.classList.toggle('-translate-x-full', !isOpen);
                adminSidebarBackdrop.classList.toggle('hidden', !isOpen);
                adminMenuToggle.setAttribute('aria-expanded', String(isOpen));
            }

            function closeRegistrationMenus() {
                actionMenus.forEach(menu => {
                    menu.classList.add('hidden');
                    const toggle = document.querySelector(`[data-menu-target="${menu.id}"], [data-action-menu="${menu.id}"]`);
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                });
            }

            function positionRegistrationMenu(toggle, menu) {
                const buttonRect = toggle.getBoundingClientRect();
                const menuWidth = menu.offsetWidth || 192;
                const menuHeight = menu.offsetHeight || 260;
                const gap = 8;
                const left = Math.max(8, Math.min(buttonRect.right - menuWidth, window.innerWidth - menuWidth - 8));
                const openBelow = buttonRect.bottom + gap + menuHeight <= window.innerHeight - 8;
                const top = openBelow ? buttonRect.bottom + gap : Math.max(8, buttonRect.top - menuHeight - gap);
                menu.style.left = `${left}px`;
                menu.style.top = `${top}px`;
            }

            function toggleRegistrationMenu(toggle) {
                const menuId = toggle.dataset.menuTarget || toggle.dataset.actionMenu;
                const menu = document.getElementById(menuId);
                if (!menu) return;
                const shouldOpen = menu.classList.contains('hidden');
                closeRegistrationMenus();
                if (!shouldOpen) return;
                document.body.appendChild(menu);
                menu.classList.remove('hidden');
                positionRegistrationMenu(toggle, menu);
                toggle.setAttribute('aria-expanded', 'true');
            }

            function closeRegistrationDetailModal() {
                if (!registrationDetailModal) return;
                if (registrationDetailDirty && !window.confirm('มีข้อมูลที่แก้ไขแล้วยังไม่ได้บันทึก ต้องการปิดหน้าต่างหรือไม่?')) return;
                registrationDetailModal.classList.add('hidden');
                registrationDetailModal.classList.remove('flex');
                registrationDetailModal.style.pointerEvents = 'none';
                const url = new URL(window.location.href);
                url.searchParams.delete('registration_id');
                url.searchParams.set('tournament_id', registrationDetailModal.dataset.tournamentId || '<?= (int) $tournamentId ?>');
                url.searchParams.set('category_id', registrationDetailModal.dataset.categoryId || '<?= (int) $selectedCategoryId ?>');
                window.history.replaceState({}, '', url);
            }

            window.closeRegistrationDetailModal = closeRegistrationDetailModal;

            if (registrationDetailModal) {
                registrationDetailModal.querySelectorAll('input, textarea, select').forEach(field => {
                    field.addEventListener('change', () => { registrationDetailDirty = true; });
                });
                registrationDetailModal.addEventListener('click', event => {
                    if (event.target === registrationDetailModal) closeRegistrationDetailModal();
                });
            }
            if (registrationDetailClose) registrationDetailClose.addEventListener('click', closeRegistrationDetailModal);
            if (registrationDetailCloseFooter) registrationDetailCloseFooter.addEventListener('click', closeRegistrationDetailModal);
            if (registrationManageAction) {
                registrationManageAction.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    const registrationId = registrationManageAction.dataset.registrationId;
                    closeRegistrationDetailModal();
                    if (registrationDetailModal && !registrationDetailModal.classList.contains('hidden')) return;
                    const toggle = document.querySelector(`.registration-action-toggle[data-registration-id="${registrationId}"]`);
                    if (toggle) toggleRegistrationMenu(toggle);
                });
            }

            actionToggles.forEach(toggle => {
                toggle.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleRegistrationMenu(toggle);
                });
                toggle.addEventListener('keydown', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    event.stopPropagation();
                    toggleRegistrationMenu(toggle);
                });
            });

            actionMenus.forEach(menu => {
                menu.addEventListener('click', event => event.stopPropagation());
            });

            document.addEventListener('click', () => closeRegistrationMenus());
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    closeRegistrationMenus();
                    if (registrationDetailModal && !registrationDetailModal.classList.contains('hidden')) closeRegistrationDetailModal();
                }
            });
            window.addEventListener('resize', closeRegistrationMenus);
            window.addEventListener('scroll', closeRegistrationMenus, true);

            if (adminMenuToggle) {
                adminMenuToggle.addEventListener('click', () => {
                    setAdminSidebarOpen(adminSidebar.classList.contains('-translate-x-full'));
                });
            }
            if (adminSidebarBackdrop) {
                adminSidebarBackdrop.addEventListener('click', () => setAdminSidebarOpen(false));
            }
            window.addEventListener('resize', () => {
                if (window.innerWidth >= 1024) setAdminSidebarOpen(false);
            });
        });
    </script>
</body>
</html>
