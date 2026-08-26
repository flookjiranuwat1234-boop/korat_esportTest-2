<?php
// pages/register-tournament.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/registration_status.php';
requireLogin();
ensureTournamentRosterTables($pdo);
ensureTournamentCategorySchema($pdo);
ensureRegistrationStatusHistoryTable($pdo);

date_default_timezone_set('Asia/Bangkok');

function getTournamentRegistrationState(array $tournament, DateTimeImmutable $now): array
{
    $status = strtolower((string) ($tournament['status'] ?? 'draft'));
    $start = !empty($tournament['registration_start'])
        ? new DateTimeImmutable((string) $tournament['registration_start'], new DateTimeZone('Asia/Bangkok'))
        : null;
    $end = !empty($tournament['registration_end'])
        ? new DateTimeImmutable((string) $tournament['registration_end'], new DateTimeZone('Asia/Bangkok'))
        : null;

    if ($status === 'draft') {
        return ['allowed' => false, 'message' => 'ทัวร์นาเมนต์นี้ยังไม่เปิดรับสมัคร'];
    }
    if ($status === 'registration_closed') {
        return ['allowed' => false, 'message' => 'ปิดรับสมัครแล้ว'];
    }
    if ($status === 'ongoing') {
        return ['allowed' => false, 'message' => 'การแข่งขันเริ่มแล้ว ไม่สามารถสมัครได้'];
    }
    if ($status === 'completed') {
        return ['allowed' => false, 'message' => 'การแข่งขันสิ้นสุดแล้ว'];
    }
    if ($status === 'cancelled') {
        return ['allowed' => false, 'message' => 'ทัวร์นาเมนต์นี้ถูกยกเลิก'];
    }
    if ($start && $now < $start) {
        return ['allowed' => false, 'message' => 'ยังไม่ถึงวันเปิดรับสมัคร'];
    }
    if ($end && $now > $end) {
        return ['allowed' => false, 'message' => 'ปิดรับสมัครแล้ว'];
    }
    if ($status === 'registration_open' && (!$start || $now >= $start) && (!$end || $now <= $end)) {
        return ['allowed' => true, 'message' => ''];
    }

    return ['allowed' => false, 'message' => 'ทัวร์นาเมนต์นี้ยังไม่เปิดรับสมัคร'];
}

function getEligibleCategories(PDO $pdo, int $tournamentId): array
{
    $sql = "
        SELECT tc.tournament_category_id, tc.category_code, tc.label, tc.max_participants, tc.starters_count,
               (SELECT COUNT(*)
                FROM tournament_registrations tr
                WHERE tr.tournament_category_id = tc.tournament_category_id
                  AND tr.status IN ('pending', 'approved')) AS registered_count
        FROM tournament_categories tc
        WHERE tc.tournament_id = :tournament_id
          AND tc.is_active = 1
        ORDER BY tc.tournament_category_id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['tournament_id' => $tournamentId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $eligible = [];
    foreach ($categories as $category) {
        $registered = (int) ($category['registered_count'] ?? 0);
        $max = (int) ($category['max_participants'] ?? 0);
        if ($max > 0 && $registered >= $max) {
            continue;
        }
        $eligible[] = $category;
    }

    return $eligible;
}

function getTeamRegistrationContext(PDO $pdo, int $teamId, int $playerId): array
{
    $teamStmt = $pdo->prepare('SELECT t.team_id, t.name, t.game_id, t.captain_player_id
        FROM teams t
        WHERE t.team_id = :team_id LIMIT 1');
    $teamStmt->execute(['team_id' => $teamId]);
    $team = $teamStmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) {
        return ['allowed' => false, 'message' => 'คุณยังไม่มีทีมสำหรับเกมนี้'];
    }

    $roleStmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = :team_id AND player_id = :player_id AND is_active = 1 AND (
        LOWER(TRIM(in_game_role)) IN (\'captain\', \'manager\', \'leader\') OR :captain_player_id = :player_id
    )');
    $roleStmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
        'captain_player_id' => (int) ($team['captain_player_id'] ?? 0),
    ]);
    if ((int) $roleStmt->fetchColumn() === 0) {
        return ['allowed' => false, 'message' => 'เฉพาะกัปตันหรือผู้จัดการทีมเท่านั้นที่สมัครได้'];
    }

    return ['allowed' => true, 'team' => $team];
}

$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];
$stmt = $pdo->prepare('SELECT player_id FROM players WHERE user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $_SESSION['user_id'] ?? 0]);
$myPlayerId = (int) $stmt->fetchColumn();

if (!$myPlayerId) {
    header('Location: claim-profile.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $mode = strtolower((string) ($_POST['mode'] ?? 'team'));
        $mode = in_array($mode, ['solo', 'team'], true) ? $mode : 'team';

        $tournamentStmt = $pdo->prepare("
            SELECT t.tournament_id, t.name, t.game_id, t.max_teams, t.status, t.registration_start, t.registration_end,
                   g.play_mode, g.name AS game_name,
                   (SELECT COUNT(*)
                    FROM tournament_registrations tr
                    WHERE tr.tournament_id = t.tournament_id
                      AND tr.status IN ('pending', 'approved')) AS registered_count
            FROM tournaments t
            JOIN games g ON g.game_id = t.game_id
            WHERE t.tournament_id = :tournament_id
            LIMIT 1
        ");
        $tournamentStmt->execute(['tournament_id' => $tournamentId]);
        $tournament = $tournamentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$tournament) {
            $error = 'ไม่พบทัวร์นาเมนต์นี้';
        } else {
            $windowState = getTournamentRegistrationState($tournament, new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')));
            if (!$windowState['allowed']) {
                $error = $windowState['message'];
            } elseif ((int) ($tournament['registered_count'] ?? 0) >= (int) ($tournament['max_teams'] ?? 0)) {
                $error = 'ประเภทการแข่งขันนี้มีผู้สมัครครบแล้ว';
            } elseif ($mode === 'solo') {
                if (($tournament['play_mode'] ?? '') !== 'solo') {
                    $error = 'ทัวร์นาเมนต์นี้ไม่ได้เปิดให้แข่งขันแบบเดี่ยว';
                } else {
                    $categoryId = (int) ($_POST['tournament_category_id'] ?? 0);
                    $eligibleCategories = getEligibleCategories($pdo, $tournamentId);
                    $selectedCategory = null;
                    foreach ($eligibleCategories as $category) {
                        if ((int) $category['tournament_category_id'] === $categoryId) {
                            $selectedCategory = $category;
                            break;
                        }
                    }
                    if (!$selectedCategory) {
                        $error = 'กรุณาเลือก Category ที่เปิดรับสมัคร';
                    } else {
                        $existingSolo = $pdo->prepare('SELECT tournament_registration_id FROM tournament_registrations WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id AND player_id = :player_id AND status IN (\'pending\', \'approved\') LIMIT 1');
                        $existingSolo->execute(['tournament_id' => $tournamentId, 'category_id' => $categoryId, 'player_id' => $myPlayerId]);
                        if ($existingSolo->fetchColumn()) {
                            $error = 'ผู้เล่นนี้สมัคร Category นี้แล้ว';
                        } else {
                            $categoryCode = (string) $selectedCategory['category_code'];
                            try {
                                $pdo->beginTransaction();
                                $insert = $pdo->prepare('INSERT INTO tournament_registrations (tournament_id, tournament_category_id, player_id, team_id, category, status, participation_status)
                                    VALUES (:tournament_id, :category_id, :player_id, NULL, :category, :status, :participation_status)');
                                $insert->execute([
                                    'tournament_id' => $tournamentId,
                                    'category_id' => $categoryId,
                                    'player_id' => $myPlayerId,
                                    'category' => $categoryCode,
                                    'status' => 'pending',
                                    'participation_status' => 'registered',
                                ]);

                                $registrationId = (int) $pdo->lastInsertId();
                                snapshotTournamentRoster($pdo, $registrationId, null, $myPlayerId);
                                recordRegistrationStatus($pdo, $registrationId, 'pending', (int) ($_SESSION['user_id'] ?? 0), 'สมัคร Tournament', null);
                                $pdo->commit();
                                $success = 'ส่งใบสมัครเข้าร่วมการแข่งขันเรียบร้อยแล้ว';
                            } catch (Throwable $exception) {
                                if ($pdo->inTransaction()) $pdo->rollBack();
                                $error = 'ส่งใบสมัครไม่สำเร็จ';
                            }
                        }
                    }
                }
            } else {
                $teamId = (int) ($_POST['team_id'] ?? 0);
                $categoryId = (int) ($_POST['tournament_category_id'] ?? 0);

                if ($teamId <= 0) {
                    $error = 'คุณยังไม่มีทีมสำหรับเกมนี้';
                } else {
                    $teamContext = getTeamRegistrationContext($pdo, $teamId, $myPlayerId);
                    if (!$teamContext['allowed']) {
                        $error = $teamContext['message'];
                    } else {
                        $teamData = $teamContext['team'];
                        if ((int) ($teamData['game_id'] ?? 0) !== (int) ($tournament['game_id'] ?? 0)) {
                            $error = 'คุณยังไม่มีทีมสำหรับเกมนี้';
                        } else {
                            $eligibleCategories = getEligibleCategories($pdo, $tournamentId);
                            $selectedCategory = null;
                            foreach ($eligibleCategories as $category) {
                                if ((int) $category['tournament_category_id'] === $categoryId) {
                                    $selectedCategory = $category;
                                    break;
                                }
                            }

                            if (!$selectedCategory) {
                                $error = 'ยังไม่มีประเภทการแข่งขันที่เปิดรับสมัคร';
                            } else {
                                $requiredRoster = (int) ($selectedCategory['starters_count'] ?? 0);
                                $memberCountStmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = :team_id AND is_active = 1');
                                $memberCountStmt->execute(['team_id' => $teamId]);
                                $memberCount = (int) $memberCountStmt->fetchColumn();
                                if ($requiredRoster > 0 && $memberCount < $requiredRoster) {
                                    $error = 'จำนวนสมาชิกยังไม่ครบตามกติกา';
                                }

                                if (empty($error)) {
                                    $duplicateStmt = $pdo->prepare('SELECT tournament_registration_id FROM tournament_registrations WHERE tournament_id = :tournament_id AND team_id = :team_id AND status IN (\'pending\', \'approved\') LIMIT 1');
                                    $duplicateStmt->execute(['tournament_id' => $tournamentId, 'team_id' => $teamId]);
                                    if ($duplicateStmt->fetchColumn()) {
                                        $error = 'ทีม/ผู้เล่นนี้สมัครประเภทการแข่งขันนี้แล้ว';
                                    }
                                }

                                if (empty($error)) {
                                    try {
                                        $pdo->beginTransaction();
                                        $insert = $pdo->prepare('INSERT INTO tournament_registrations (tournament_id, tournament_category_id, team_id, player_id, category, status, participation_status)
                                            VALUES (:tournament_id, :category_id, :team_id, NULL, :category, \'pending\', \'registered\')');
                                        $insert->execute([
                                            'tournament_id' => $tournamentId,
                                            'category_id' => $categoryId,
                                            'team_id' => $teamId,
                                            'category' => (string) ($selectedCategory['category_code'] ?? 'open'),
                                        ]);

                                        $registrationId = (int) $pdo->lastInsertId();
                                        snapshotTournamentRoster($pdo, $registrationId, $teamId, null);
                                        recordRegistrationStatus($pdo, $registrationId, 'pending', (int) ($_SESSION['user_id'] ?? 0), 'สมัคร Tournament ในนามทีม', null);
                                        $pdo->commit();
                                        $success = 'ส่งใบสมัครเข้าร่วมการแข่งขันเรียบร้อยแล้ว';
                                    } catch (Throwable $exception) {
                                        if ($pdo->inTransaction()) $pdo->rollBack();
                                        $error = 'ส่งใบสมัครไม่สำเร็จ';
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$selectedTournamentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($selectedTournamentId) {
    $tStmt = $pdo->prepare("
        SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t
        JOIN games g ON g.game_id = t.game_id
        WHERE t.tournament_id = :tournament_id
    ");
    $tStmt->execute(['tournament_id' => $selectedTournamentId]);
    $tournaments = $tStmt->fetchAll();
} else {
    $tStmt = $pdo->prepare("
        SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t
        JOIN games g ON g.game_id = t.game_id
        WHERE t.status = 'registration_open'
        ORDER BY t.start_date ASC
    ");
    $tStmt->execute();
    $tournaments = $tStmt->fetchAll();
}

$existingTeamMap = [];
$existingSoloMap = [];
$tournamentCategories = [];
if (!empty($tournaments)) {
    $categoryStmt = $pdo->prepare('SELECT tournament_category_id, category_code, label, starters_count, substitutes_count, checkin_required_roles
        FROM tournament_categories WHERE tournament_id = :tournament_id AND is_active = 1 ORDER BY tournament_category_id');
    foreach ($tournaments as $availableTournament) {
        $categoryStmt->execute(['tournament_id' => $availableTournament['tournament_id']]);
        $tournamentCategories[(int) $availableTournament['tournament_id']] = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
function registrationStatusLabel($registration): array
{
    if (is_string($registration)) {
        $registration = ['status' => $registration];
    }
    if (($registration['status'] ?? '') === 'rejected') {
        return ['ไม่ผ่านการอนุมัติ', 'bg-rose-500/20 border-rose-500/40 text-rose-200', 'fa-circle-xmark'];
    }
    if (($registration['status'] ?? '') === 'pending') {
        return ['รออนุมัติ', 'bg-amber-500/20 border-amber-500/40 text-amber-200', 'fa-clock'];
    }
    if (($registration['checkin_status'] ?? '') === 'checked_in') {
        return ['อนุมัติและเช็กอินแล้ว', 'bg-emerald-500/20 border-emerald-500/40 text-emerald-300', 'fa-user-check'];
    }
    return ['อนุมัติ รอเช็กอิน', 'bg-sky-500/20 border-sky-500/40 text-sky-200', 'fa-circle-check'];
}
if (!empty($tournaments)) {
    $tIds = array_column($tournaments, 'tournament_id');
    if (!empty($tIds)) {
        $inT = implode(',', $tIds);

        $regTeams = $pdo->query("
            SELECT tr.tournament_id, tr.team_id, tr.status, tr.checkin_status
            FROM tournament_registrations tr
            JOIN teams tm ON tm.team_id = tr.team_id
            WHERE tr.tournament_id IN ($inT) AND tm.captain_player_id = $myPlayerId
        ")->fetchAll();
        foreach ($regTeams as $rt) {
            $existingTeamMap[$rt['tournament_id'] . '-' . $rt['team_id']] = [
                'status' => $rt['status'],
                'checkin_status' => $rt['checkin_status'],
            ];
        }

        $regSolos = $pdo->query("
            SELECT tournament_id, status, checkin_status
            FROM tournament_registrations
            WHERE tournament_id IN ($inT) AND player_id = $myPlayerId
        ")->fetchAll();
        foreach ($regSolos as $rs) {
            $existingSoloMap[$rs['tournament_id']] = [
                'status' => $rs['status'],
                'checkin_status' => $rs['checkin_status'],
            ];
        }
    }
}

$csrfToken = generateCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: ($success ?? 'ส่งใบสมัครเรียบร้อยแล้ว'));
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'register-tournament.php'), true, 303);
    exit;
}
$flash = consumeFlashMessage();
if ($flash) $error = $flash['type'] === 'error' ? $flash['message'] : ($success = $flash['message']);
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครเข้าร่วมทัวร์นาเมนต์ - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { orange: '#FF5500', glow: '#FF7700', dark: '#0A0A0C', panel: '#121318' }
                    },
                    fontFamily: { sans: ['Kanit', 'sans-serif'], display: ['Orbitron', 'sans-serif'] },
                    boxShadow: { 'orange-glow': '0 0 25px rgba(255, 85, 0, 0.45)' }
                }
            }
        }
    </script>

    <style>
        ::-webkit-scrollbar { display: none; }
        html, body { -ms-overflow-style: none; scrollbar-width: none; }
        body { background-color: #0F1117; }
        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.55), rgba(15, 17, 23, 0.90)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover; background-position: center; background-attachment: fixed;
        }
        .glass-nav {
            background: rgba(15, 17, 23, 0.85); backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }
        .glass-panel {
            background: rgba(255, 255, 255, 0.07); backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }
    </style>
</head>

<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>

    <div class="relative z-10 flex flex-col min-h-screen">

        <!-- NAVBAR -->
        <header class="sticky top-0 z-50 glass-nav">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <a href="index.php" class="flex items-center gap-3">
                        <img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl text-white">KORAT <span class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] text-gray-200 font-bold uppercase -mt-1">Official Arena & Hub</span>
                        </div>
                    </a>

                    <nav class="hidden md:flex items-center gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">หน้าแรก</a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange">ทัวร์นาเมนต์</a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ตารางคะแนน</a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ข่าวสาร</a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">แกลเลอรี่</a>
                    </nav>

                    <div class="flex items-center gap-3">
                        <?php if ($isLoggedIn): ?>
                            <div class="flex items-center gap-3 bg-white/10 p-1.5 pl-3.5 rounded-2xl">
                                <span class="text-sm font-bold text-white"><?= htmlspecialchars($currentUser['username']) ?></span>
                                <a href="profile.php" class="w-9 h-9 rounded-xl bg-brand-orange text-white flex items-center justify-center">
                                    <i class="fa-solid fa-user-gear text-sm"></i>
                                </a>
                                <a href="../auth/logout.php" class="w-9 h-9 rounded-xl bg-rose-500/20 text-rose-300 flex items-center justify-center hover:bg-rose-600 hover:text-white">
                                    <i class="fa-solid fa-right-from-bracket text-sm"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- PAGE HEADER -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-6 text-center space-y-4">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest">
                <i class="fa-solid fa-file-pen"></i> Tournament Entry Portal
            </div>
            <h1 class="text-4xl sm:text-6xl font-black font-display text-white uppercase tracking-wider">
                สมัครเข้าร่วมแข่งขัน <span class="text-brand-orange">(REGISTRATION)</span>
            </h1>
            <p class="text-sm sm:text-base text-gray-300 max-w-xl mx-auto">
                เลือกทีมสโมสรกลางของคุณและระบุประเภทการแข่งขัน (Open / ชาย / หญิง) สำหรับทัวร์นาเมนต์นี้
            </p>
        </section>

        <!-- MAIN CONTENT -->
        <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 mb-16 w-full space-y-6">

            <div class="flex items-center justify-between">
                <a href="tournaments.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/15 text-xs font-bold text-gray-300 transition-all">
                    <i class="fa-solid fa-arrow-left text-brand-orange"></i> กลับไปหน้ารายการทัวร์นาเมนต์
                </a>
            </div>

            <?php if ($error): ?>
                <div class="p-4 rounded-2xl bg-rose-500/20 border border-rose-500/40 text-rose-200 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-xl text-rose-400"></i>
                    <span><?= htmlspecialchars($error); ?></span>
                </div>
            <?php elseif ($success): ?>
                <div class="p-4 rounded-2xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-200 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-circle-check text-xl text-emerald-400"></i>
                    <span><?= htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if (count($tournaments) == 0): ?>
                <div class="glass-panel p-12 text-center text-gray-300 rounded-3xl space-y-3">
                    <i class="fa-solid fa-calendar-xmark text-5xl text-brand-orange opacity-60 block mx-auto"></i>
                    <h3 class="text-xl font-bold text-white">ไม่มีทัวร์นาเมนต์ที่เปิดรับสมัครในขณะนี้</h3>
                </div>
            <?php endif; ?>

            <div class="space-y-6">
                <?php foreach ($tournaments as $t): 
                    // ดึงทีมกลางทั้งหมดที่ผู้ใช้นี้เป็นกัปตัน (ไม่ต้องกรอง game_id แล้ว เพราะเป็นทีมกลาง)
                    $myTeamsStmt = $pdo->prepare("
                        SELECT t.team_id, t.name 
                        FROM teams t
                        WHERE t.captain_player_id = :pid
                    ");
                    $myTeamsStmt->execute(['pid' => $myPlayerId]);
                    $filteredTeams = $myTeamsStmt->fetchAll();
                ?>
                    <div class="glass-panel p-6 sm:p-8 rounded-3xl border border-white/20 shadow-2xl space-y-6">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-white/15 pb-4 gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase bg-brand-orange text-white">
                                        <i class="fa-solid fa-gamepad mr-1"></i> <?= htmlspecialchars($t['game_name']); ?>
                                    </span>
                                    <span class="px-3 py-1 rounded-full text-[10px] font-black uppercase bg-purple-500/20 border border-purple-500/40 text-purple-300">
                                        <?= ($t['play_mode'] === 'solo') ? 'ประเภทเดี่ยว (Solo)' : 'ประเภททีม (Team)'; ?>
                                    </span>
                                </div>
                                <h3 class="text-2xl font-black font-display text-white mt-2"><?= htmlspecialchars($t['name']); ?></h3>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <?php if ($t['play_mode'] === 'solo'): ?>
                                <div class="bg-black/40 p-4 rounded-2xl border border-white/10 flex items-center justify-between">
                                    <div>
                                        <h5 class="text-base font-bold text-white"><?= htmlspecialchars($currentUser['username']); ?></h5>
                                        <span class="text-[11px] text-gray-400">สมัครแข่งขันเดี่ยว</span>
                                    </div>
                                    <div>
                                        <?php if (isset($existingSoloMap[$t['tournament_id']])): ?>
                                            <?php [$label, $badgeClass, $icon] = registrationStatusLabel($existingSoloMap[$t['tournament_id']]); ?>
                                            <span class="px-4 py-2 rounded-xl border text-xs font-bold <?= $badgeClass; ?>"><i class="fa-solid <?= $icon; ?> mr-1"></i><?= $label; ?></span>
                                        <?php else: ?>
                                            <form method="POST" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                                                <input type="hidden" name="tournament_id" value="<?= $t['tournament_id']; ?>">
                                                <input type="hidden" name="mode" value="solo">
                                                <select name="tournament_category_id" required class="bg-black/60 border border-white/20 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-orange">
                                                    <option value="">เลือก Category</option>
                                                    <?php foreach (($tournamentCategories[(int) $t['tournament_id']] ?? []) as $category): ?>
                                                        <option value="<?= (int) $category['tournament_category_id']; ?>"><?= htmlspecialchars($category['label'] ?: $category['category_code']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="px-6 py-2.5 rounded-xl bg-brand-orange text-white font-bold text-xs uppercase">สมัครแข่งเดี่ยว</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <h4 class="text-xs font-bold uppercase tracking-wider text-gray-400">
                                    <i class="fa-solid fa-shield-halved text-brand-orange"></i> เลือกทีมสโมสรของคุณและระบุประเภทการแข่งขัน:
                                </h4>

                                <?php if (empty($filteredTeams)): ?>
                                    <div class="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-xs text-rose-100 space-y-2">
                                        <div><i class="fa-solid fa-circle-info mr-1.5"></i> คุณยังไม่มีทีมสโมสรกลาง กรุณาสร้างทีมก่อนสมัครแข่งขัน</div>
                                        <a href="create-team.php" class="inline-block px-3 py-1.5 rounded-lg bg-brand-orange text-white font-bold uppercase text-[10px]">สร้างทีมใหม่</a>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($filteredTeams as $team): ?>
                                        <?php $key = $t['tournament_id'] . '-' . $team['team_id']; ?>
                                        <div class="bg-black/40 p-4 rounded-2xl border border-white/10 space-y-3">
                                            <div class="flex items-center gap-3">
                                                <div class="w-10 h-10 rounded-xl bg-white/10 text-brand-orange flex items-center justify-center font-bold">
                                                    <i class="fa-solid fa-shield"></i>
                                                </div>
                                                <div>
                                                    <h5 class="text-base font-bold text-white"><?= htmlspecialchars($team['name']); ?></h5>
                                                    <span class="text-[11px] text-gray-400">ทีมสโมสรกลาง (Global Team)</span>
                                                </div>
                                            </div>

                                            <?php if (isset($existingTeamMap[$key])): ?>
                                                <?php [$label, $badgeClass, $icon] = registrationStatusLabel($existingTeamMap[$key]); ?>
                                                <div class="pt-2">
                                                    <span class="px-4 py-2 rounded-xl text-xs font-bold border inline-block <?= $badgeClass; ?>">
                                                        <i class="fa-solid <?= $icon; ?>"></i> <?= $label; ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken; ?>">
                                                    <input type="hidden" name="tournament_id" value="<?= $t['tournament_id']; ?>">
                                                    <input type="hidden" name="team_id" value="<?= $team['team_id']; ?>">
                                                    <input type="hidden" name="mode" value="team">

                                                    <div class="sm:col-span-2">
                                                        <label class="block text-[10px] font-bold text-gray-400 uppercase mb-1">เลือกประเภทการแข่งขันสำหรับทีมนี้:</label>
                                                        <select name="tournament_category_id" required class="w-full bg-black/60 border border-white/20 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-orange">
                                                            <option value="">เลือก Category</option>
                                                            <?php foreach (($tournamentCategories[(int) $t['tournament_id']] ?? []) as $category): ?>
                                                                <option value="<?= (int) $category['tournament_category_id'] ?>"><?= htmlspecialchars($category['label'] ?: $category['category_code']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>

                                                    <div class="flex items-end">
                                                        <button type="submit" class="w-full px-4 py-2 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs uppercase tracking-wider transition-all shadow-md cursor-pointer">
                                                            <i class="fa-solid fa-paper-plane mr-1"></i> ยืนยันการสมัคร
                                                        </button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        </main>

        <footer class="border-t border-white/15 bg-slate-950/80 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
            <div class="max-w-7xl mx-auto px-4 text-center">
                <p class="text-gray-300 font-semibold">&copy; <?= date('Y') ?> KORAT ESPORT. All rights reserved.</p>
            </div>
        </footer>

    </div>

</body>

</html>