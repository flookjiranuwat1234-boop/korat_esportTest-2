<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/registration_status.php';
require_once '../includes/tournament_registration.php';
require_once '../includes/tournament_demo.php';

$isLoggedIn = isLoggedIn();
if (!$isLoggedIn && ($_SERVER['REQUEST_METHOD'] === 'POST' || in_array($_GET['action'] ?? '', ['search_players', 'team_roster_eligibility'], true))) {
    requireLogin();
}

date_default_timezone_set('Asia/Bangkok');
$currentUser = [
    'username' => $_SESSION['username'] ?? 'ผู้ใช้งาน',
    'role' => $_SESSION['role'] ?? 'Player',
];
$error = '';
$success = '';
$csrfToken = generateCsrfToken();

if (($_GET['action'] ?? '') === 'search_players') {
    header('Content-Type: application/json; charset=utf-8');
    $tournamentId = filter_input(INPUT_GET, 'tournament_id', FILTER_VALIDATE_INT);
    $categoryId = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);
    $term = trim((string) ($_GET['q'] ?? ''));
    $searchTeamId = (int) ($_GET['team_id'] ?? 0);
    if ($searchTeamId > 0) {
        $category = $categoryId ? tournamentRegistrationCategory($pdo, $categoryId) : null;
        $currentPlayerStmt = $pdo->prepare('SELECT player_id FROM players WHERE user_id = :user_id LIMIT 1');
        $currentPlayerStmt->execute(['user_id' => (int) $_SESSION['user_id']]);
        $searchTeamStmt = $pdo->prepare('SELECT team_id FROM teams
            WHERE team_id = :team_id AND captain_player_id = :captain AND status = "active"
              AND (game_id IS NULL OR game_id = :game_id)
            LIMIT 1');
        $searchTeamStmt->execute([
            'team_id' => $searchTeamId,
            'captain' => (int) $currentPlayerStmt->fetchColumn(),
            'game_id' => (int) ($category['tournament_game_id'] ?? 0),
        ]);
        if (!$category || (int) $category['tournament_id'] !== (int) $tournamentId
            || ($category['game_play_mode'] ?? '') !== 'team' || !$searchTeamStmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'ไม่สามารถค้นหาผู้เล่นสำหรับทีมนี้ได้'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    echo json_encode(
        $tournamentId && $categoryId
            ? searchTournamentPlayers($pdo, $tournamentId, $categoryId, $term, $searchTeamId)
            : [],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if (($_GET['action'] ?? '') === 'team_roster_eligibility') {
    header('Content-Type: application/json; charset=utf-8');
    $tournamentId = filter_input(INPUT_GET, 'tournament_id', FILTER_VALIDATE_INT);
    $categoryId = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);
    $teamId = filter_input(INPUT_GET, 'team_id', FILTER_VALIDATE_INT);
    $category = $categoryId ? tournamentRegistrationCategory($pdo, $categoryId) : null;
    $currentPlayerStmt = $pdo->prepare('SELECT player_id FROM players WHERE user_id = :user_id LIMIT 1');
    $currentPlayerStmt->execute(['user_id' => (int) $_SESSION['user_id']]);
    $currentPlayerId = (int) $currentPlayerStmt->fetchColumn();
    $teamStmt = $pdo->prepare('SELECT team_id FROM teams
        WHERE team_id = :team_id AND captain_player_id = :captain AND status = "active"
          AND (game_id IS NULL OR game_id = :game_id)
        LIMIT 1');
    $teamStmt->execute([
        'team_id' => (int) $teamId,
        'captain' => $currentPlayerId,
        'game_id' => (int) ($category['tournament_game_id'] ?? 0),
    ]);
    if (!$category || (int) $category['tournament_id'] !== (int) $tournamentId
        || ($category['game_play_mode'] ?? '') !== 'team' || !$teamStmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['error' => 'ไม่สามารถตรวจสอบทีมนี้ได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $teamMembersStmt = $pdo->prepare('SELECT tm.team_member_id, tm.player_id, p.display_name,
            p.gender, p.birth_date, u.status AS account_status
        FROM team_members tm
        INNER JOIN players p ON p.player_id = tm.player_id
        INNER JOIN users u ON u.user_id = p.user_id
        WHERE tm.team_id = :team_id AND tm.is_active = 1
        ORDER BY tm.team_member_id');
    $teamMembersStmt->execute(['team_id' => (int) $teamId]);
    $teamRoster = [];
    $eligibilityPlayers = [];
    foreach ($teamMembersStmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
        $playerId = (int) $member['player_id'];
        $member['roles'] = normalizeTeamRoles(getTeamMemberRoles($pdo, (int) $member['team_member_id']));
        $member['name'] = (string) ($member['display_name'] ?: 'สมาชิกทีม');
        $member['is_captain'] = $playerId === $currentPlayerId;
        $teamRoster[] = $member;
        $eligibilityPlayers[] = $playerId;
    }
    $playerIds = array_values(array_unique(array_filter(array_merge(
        $eligibilityPlayers,
        array_map(static fn($playerId): int => is_scalar($playerId) ? (int) $playerId : 0, (array) ($_GET['player_ids'] ?? []))
    ),
        static fn(int $playerId): bool => $playerId > 0
    )));
    $eligibility = [];
    if ($playerIds) {
        $placeholders = [];
        $params = [];
        foreach ($playerIds as $index => $playerId) {
            $placeholder = ':player_' . $index;
            $placeholders[] = $placeholder;
            $params['player_' . $index] = $playerId;
        }
        $playerQuery = $pdo->prepare('SELECT p.player_id, p.gender, p.birth_date,
                u.status AS account_status
            FROM players p INNER JOIN users u ON u.user_id = p.user_id
            WHERE p.player_id IN (' . implode(', ', $placeholders) . ')');
        $playerQuery->execute($params);
        foreach ($playerQuery->fetchAll(PDO::FETCH_ASSOC) as $player) {
            $reason = tournamentCategoryAllowsPlayer($player, $category);
            $eligibility[(int) $player['player_id']] = [
                'eligible' => $reason === null,
                'reason' => $reason,
                'account_active' => ($player['account_status'] ?? '') === 'active',
            ];
        }
    }
    echo json_encode(['roster' => $teamRoster, 'eligibility' => $eligibility], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $mode = strtolower((string) ($_POST['mode'] ?? 'team'));
        $categoryId = (int) ($_POST['tournament_category_id'] ?? 0);
        $tournamentStmt = $pdo->prepare('SELECT t.*, g.play_mode FROM tournaments t INNER JOIN games g ON g.game_id = t.game_id WHERE t.tournament_id = :id LIMIT 1');
        $tournamentStmt->execute(['id' => $tournamentId]);
        $tournament = $tournamentStmt->fetch(PDO::FETCH_ASSOC);
        $window = $tournament ? getTournamentRegistrationState($tournament, new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'))) : ['allowed' => false, 'message' => 'ไม่พบ Tournament'];
        if (!$tournament || !$window['allowed']) {
            $error = $window['message'];
        } elseif ($mode === 'solo') {
            if ($tournament['play_mode'] !== 'solo') {
                $error = 'รายการนี้ไม่ใช่ประเภทเดี่ยว';
            } else {
                $categoryStmt = $pdo->prepare('SELECT * FROM tournament_categories WHERE tournament_category_id = :category AND tournament_id = :tournament AND is_active = 1 LIMIT 1');
                $categoryStmt->execute(['category' => $categoryId, 'tournament' => $tournamentId]);
                $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
                if (!$category) {
                    $error = 'ไม่พบรุ่นการแข่งขันของ Tournament นี้';
                } else {
                    $playerStmt = $pdo->prepare('SELECT p.*, u.status AS account_status FROM players p INNER JOIN users u ON u.user_id = p.user_id WHERE p.user_id = :user LIMIT 1');
                    $playerStmt->execute(['user' => (int) $_SESSION['user_id']]);
                    $player = $playerStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$player) {
                        $error = 'ไม่พบ Player Profile ของบัญชีนี้';
                    } elseif (($eligibilityError = tournamentCategoryAllowsPlayer($player, $category)) !== null) {
                        $error = $eligibilityError;
                    } else {
                        $duplicate = $pdo->prepare('SELECT 1 FROM tournament_registrations WHERE tournament_id = :tournament AND tournament_category_id = :category AND player_id = :player AND status IN ("pending","approved") LIMIT 1');
                        $duplicate->execute(['tournament' => $tournamentId, 'category' => $categoryId, 'player' => (int) $player['player_id']]);
                        if ($duplicate->fetchColumn()) {
                            $error = 'ผู้เล่นนี้สมัคร Category นี้แล้ว';
                        } else {
                            $categoryCode = (string) ($category['category_code'] ?: 'open');
                            try {
                                $pdo->beginTransaction();
                                assertTournamentRegistrationCapacity($pdo, $tournamentId, $categoryId);
                                    ensureRegistrationStatusHistoryTable($pdo);
                                    $insert = $pdo->prepare('INSERT INTO tournament_registrations (tournament_id,tournament_category_id,player_id,team_id,category,status,participation_status) VALUES (:tournament,:category,:player,NULL,:code,"pending","pending_admin_review")');
                                    $insert->execute(['tournament' => $tournamentId, 'category' => $categoryId, 'player' => (int) $player['player_id'], 'code' => $categoryCode]);
                                    $registrationId = (int) $pdo->lastInsertId();
                                    snapshotTournamentRoster($pdo, $registrationId, null, (int) $player['player_id']);
                                    recordRegistrationStatus($pdo, $registrationId, 'pending', (int) $_SESSION['user_id'], 'สมัครแข่งขันแบบเดี่ยว');
                                    $pdo->commit();
                                    $success = 'สมัครเข้าร่วมรายการเรียบร้อยแล้ว';
                                } catch (Throwable $exception) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'สมัครแข่งขันไม่สำเร็จ';
                                }
                        }
                    }
                }
            }
        } elseif ($mode === 'team') {
            $existingTeamId = (int) ($_POST['existing_team_id'] ?? 0);
            $roster = [];
            foreach ((array) ($_POST['roster'] ?? []) as $member) {
                $roster[] = ['player_id' => (int) ($member['player_id'] ?? 0), 'roles' => (array) ($member['roles'] ?? [])];
            }
            $logoPath = null;
            $uploadedLogo = null;
            try {
                if ($existingTeamId <= 0 && !empty($_FILES['team_logo']['tmp_name'])) {
                    if ($_FILES['team_logo']['error'] !== UPLOAD_ERR_OK || (int) $_FILES['team_logo']['size'] > 2 * 1024 * 1024) {
                        throw new InvalidArgumentException('ไฟล์โลโก้ไม่ถูกต้องหรือมีขนาดเกิน 2MB');
                    }
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['team_logo']['tmp_name']);
                    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!isset($extensions[$mime]) || @getimagesize($_FILES['team_logo']['tmp_name']) === false) {
                        throw new InvalidArgumentException('รองรับโลโก้เฉพาะ JPG, PNG หรือ WebP');
                    }
                    $uploadDir = dirname(__DIR__) . '/assets/uploads';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) throw new RuntimeException('ไม่สามารถเตรียมพื้นที่เก็บโลโก้');
                    $filename = 'team_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
                    $uploadedLogo = $uploadDir . '/' . $filename;
                    if (!move_uploaded_file($_FILES['team_logo']['tmp_name'], $uploadedLogo)) throw new RuntimeException('ไม่สามารถบันทึกโลโก้');
                    $logoPath = 'assets/uploads/' . $filename;
                }
                saveTeamTournamentRegistration(
                    $pdo,
                    (int) $_SESSION['user_id'],
                    $tournamentId,
                    $categoryId,
                    (string) ($_POST['team_name'] ?? ''),
                    $roster,
                    $logoPath,
                    $existingTeamId > 0 ? $existingTeamId : null
                );
                $success = 'สมัครเข้าร่วมรายการเรียบร้อยแล้ว';
            } catch (Throwable $exception) {
                if ($uploadedLogo && is_file($uploadedLogo)) unlink($uploadedLogo);
                error_log(sprintf(
                    'Team tournament registration failed: user_id=%d tournament_id=%d category_id=%d error=%s',
                    (int) ($_SESSION['user_id'] ?? 0),
                    $tournamentId,
                    $categoryId,
                    $exception->getMessage()
                ));
                if ($exception instanceof InvalidArgumentException && $exception->getMessage() === 'หัวหน้าทีมนี้สมัคร Category นี้แล้ว') {
                    $success = 'สมัครเข้าร่วมรายการเรียบร้อยแล้ว';
                } else {
                    $error = $exception instanceof InvalidArgumentException
                        ? $exception->getMessage()
                        : 'ส่งใบสมัครไม่สำเร็จ กรุณาตรวจสอบข้อมูลทีมและรายชื่ออีกครั้ง';
                }
            }
        } else {
            $error = 'รูปแบบรายการแข่งขันไม่ถูกต้อง';
        }
    }
}

function getTournamentRegistrationState(array $tournament, DateTimeImmutable $now): array
{
    if (isDemoTournament($tournament)) return ['allowed' => true, 'message' => ''];
    if (($tournament['status'] ?? '') !== 'registration_open') return ['allowed' => false, 'message' => 'รายการนี้ยังไม่เปิดรับสมัคร'];
    if (!empty($tournament['registration_start']) && $now < new DateTimeImmutable($tournament['registration_start'])) return ['allowed' => false, 'message' => 'ยังไม่ถึงเวลาเปิดรับสมัคร'];
    if (!empty($tournament['registration_end']) && $now > new DateTimeImmutable($tournament['registration_end'])) return ['allowed' => false, 'message' => 'ปิดรับสมัครแล้ว'];
    return ['allowed' => true, 'message' => ''];
}

$requestedTournamentId = (int) ($_GET['id'] ?? $_POST['tournament_id'] ?? 0);
$requestedCategoryId = (int) ($_GET['category_id'] ?? $_POST['tournament_category_id'] ?? 0);
$tournaments = [];
if ($requestedTournamentId > 0) {
    $tournamentListStmt = $pdo->prepare("SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t
        INNER JOIN games g ON g.game_id = t.game_id
        WHERE t.tournament_id = :tournament_id
        LIMIT 1");
    $tournamentListStmt->execute(['tournament_id' => $requestedTournamentId]);
    $selectedTournament = $tournamentListStmt->fetch(PDO::FETCH_ASSOC);
    if ($selectedTournament) {
        $tournaments[] = $selectedTournament;
    }
}
$categories = [];
$categoryStmt = $pdo->prepare('SELECT * FROM tournament_categories WHERE tournament_id = :tournament AND is_active = 1 ORDER BY tournament_category_id');
foreach ($tournaments as $tournament) {
    $categoryStmt->execute(['tournament' => $tournament['tournament_id']]);
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($requestedCategoryId > 0) {
        $availableCategories = array_values(array_filter(
            $availableCategories,
            static fn(array $category): bool => (int) $category['tournament_category_id'] === $requestedCategoryId
        ));
    }
    $categories[(int) $tournament['tournament_id']] = $availableCategories;
}
if ($requestedTournamentId > 0 && !$tournaments) {
    $error = 'ไม่พบรายการแข่งขันที่เลือก';
} elseif ($requestedCategoryId > 0 && $tournaments && !$categories[$requestedTournamentId]) {
    $error = 'ไม่พบหมวดการแข่งขันของรายการที่เลือก';
}
$teamsByTournament = [];
if ($isLoggedIn && $tournaments) {
    $playerStmt = $pdo->prepare('SELECT player_id FROM players WHERE user_id = :user_id LIMIT 1');
    $playerStmt->execute(['user_id' => (int) $_SESSION['user_id']]);
    $currentPlayerId = (int) $playerStmt->fetchColumn();
    if ($currentPlayerId > 0) {
        foreach ($tournaments as $tournament) {
            if (($tournament['play_mode'] ?? '') !== 'team') continue;
            $teamStmt = $pdo->prepare('SELECT team_id, name, logo_path, game_id, captain_player_id
                FROM teams
                WHERE captain_player_id = :captain AND status = "active"
                  AND (game_id IS NULL OR game_id = :game_id)
                  AND EXISTS (
                    SELECT 1 FROM team_members captain_member
                    WHERE captain_member.team_id = teams.team_id
                      AND captain_member.player_id = :active_captain
                      AND captain_member.is_active = 1
                  )
                ORDER BY name');
            $teamStmt->execute([
                'captain' => $currentPlayerId,
                'game_id' => (int) $tournament['game_id'],
                'active_captain' => $currentPlayerId,
            ]);
            $availableTeams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
            $memberStmt = $pdo->prepare('SELECT tm.team_member_id, tm.player_id,
                    p.display_name, u.status AS account_status
                FROM team_members tm
                INNER JOIN players p ON p.player_id = tm.player_id
                INNER JOIN users u ON u.user_id = p.user_id
                WHERE tm.team_id = :team_id AND tm.is_active = 1
                ORDER BY tm.team_member_id');
            foreach ($availableTeams as &$team) {
                $memberStmt->execute(['team_id' => (int) $team['team_id']]);
                $team['members'] = [];
                foreach ($memberStmt->fetchAll(PDO::FETCH_ASSOC) as $member) {
                    $member['player_id'] = (int) $member['player_id'];
                    $member['roles'] = normalizeTeamRoles(getTeamMemberRoles($pdo, (int) $member['team_member_id']));
                    $member['name'] = (string) ($member['display_name'] ?: 'สมาชิกทีม');
                    $member['is_captain'] = (int) $member['player_id'] === $currentPlayerId;
                    $member['account_active'] = ($member['account_status'] ?? '') === 'active';
                    $team['members'][] = $member;
                }
            }
            unset($team);
            $teamsByTournament[(int) $tournament['tournament_id']] = $availableTeams;
        }
    }
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สมัครแข่งขัน - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=Orbitron:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <script>tailwind.config={theme:{extend:{colors:{brand:{orange:'#FF5500',glow:'#FF7700',dark:'#0A0A0C'}},fontFamily:{sans:['Kanit','sans-serif'],display:['Orbitron','sans-serif']},boxShadow:{'orange-glow':'0 0 25px rgba(255,85,0,.45)'}}}};</script>
    <style>
        body{background:#0f1117}.bg-arena{background:linear-gradient(to bottom,rgba(15,17,23,.65),rgba(15,17,23,.96)),url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');background-size:cover;background-position:center;background-attachment:fixed}.glass-nav{background:rgba(15,17,23,.88);backdrop-filter:blur(16px);border-bottom:1px solid rgba(255,255,255,.15)}.glass-panel{background:rgba(255,255,255,.07);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.15)}.grid-bg{background-image:radial-gradient(rgba(255,255,255,.15) 1px,transparent 0);background-size:24px 24px}
    </style>
    <link rel="stylesheet" href="../assets/css/mobile-nav.css">
</head>
<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">
<div class="fixed inset-0 bg-arena z-0 pointer-events-none"></div><div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>
<div class="relative z-10 min-h-screen">
<header class="sticky top-0 z-50 glass-nav"><div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8"><div class="flex items-center justify-between h-20">
    <a href="index.php?intro=1" class="flex items-center gap-3"><img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto" onerror="this.src='https://placehold.co/100x100/121318/FF5500?text=KE'"><div><span class="font-display font-black text-xl text-white">KORAT <span class="text-brand-orange">ESPORT</span></span><span class="block text-[10px] text-gray-300 font-bold uppercase -mt-1">Official Arena &amp; Hub</span></div></a>
    <nav class="hidden md:flex items-center gap-1"><a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">หน้าแรก</a><a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-brand-orange bg-white/10">ทัวร์นาเมนต์</a><a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ตารางคะแนน</a><a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ข่าวสาร</a><a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">แกลเลอรี่</a></nav>
    <?php if ($isLoggedIn): ?>
        <div class="flex items-center gap-3 bg-white/10 p-1.5 pl-3.5 rounded-2xl"><span class="hidden sm:block text-sm font-bold"><?= htmlspecialchars($currentUser['username']) ?></span><a href="profile.php" class="w-9 h-9 rounded-xl bg-brand-orange text-white flex items-center justify-center" aria-label="โปรไฟล์"><i class="fa-solid fa-user"></i></a><a href="../auth/logout.php" class="w-9 h-9 rounded-xl bg-rose-500/20 text-rose-300 flex items-center justify-center" aria-label="ออกจากระบบ"><i class="fa-solid fa-right-from-bracket"></i></a></div>
    <?php else: ?>
        <a href="../auth/login.php" class="text-brand-orange transition-colors hover:text-brand-glow">เข้าสู่ระบบ</a>
    <?php endif; ?>
    </div>
    </div></header>
<main class="mx-auto max-w-5xl px-4 sm:px-6 py-12">
    <div class="mb-8 text-center"><div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest"><i class="fa-solid fa-trophy"></i> เปิดรับสมัคร</div><h1 class="mt-4 text-3xl sm:text-4xl font-black font-display">สมัครเข้าร่วมการแข่งขัน</h1><p class="mt-2 text-sm text-gray-400">กรอกข้อมูลทีมและเลือกผู้เล่นสำหรับรายการนี้</p></div>
    <?php if ($error): ?><div class="mb-5 rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-red-200"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-5 rounded-lg border border-green-500/40 bg-green-500/10 p-4 text-green-200"><?= htmlspecialchars($success); ?></div><?php endif; ?>
    <div class="space-y-8">
    <?php foreach ($tournaments as $tournament): ?>
        <?php $id = (int) $tournament['tournament_id']; ?>
        <?php $tournamentCategories = $categories[$id] ?? []; $fixedCategoryId = count($tournamentCategories) === 1 ? (int) $tournamentCategories[0]['tournament_category_id'] : 0; ?>
        <?php $availableTeams = $teamsByTournament[$id] ?? []; ?>
        <?php $hasDescription = trim((string) ($tournament['description'] ?? '')) !== ''; ?>
        <?php $hasRules = trim((string) ($tournament['rules'] ?? '')) !== ''; ?>
        <section class="glass-panel rounded-3xl p-6 sm:p-8 shadow-2xl">
            <h2 class="text-2xl font-bold"><?= htmlspecialchars($tournament['name']); ?> <span class="text-orange-400">(<?= htmlspecialchars($tournament['game_name']); ?>)</span></h2>
            <div class="mt-5 grid items-start gap-4 lg:grid-cols-2">
                <div class="<?= $hasRules ? '' : 'lg:col-span-2'; ?> space-y-4">
                    <?php if ($hasDescription): ?>
                        <div class="h-fit min-w-0 rounded-2xl border border-white/10 bg-slate-950/40 p-5">
                            <h3 class="flex items-center gap-2 text-lg font-bold text-orange-300">
                                <i class="fa-solid fa-circle-info"></i> รายละเอียดการแข่งขัน
                            </h3>
                            <p class="mt-3 whitespace-pre-line text-sm leading-7 text-gray-300"><?= htmlspecialchars($tournament['description']); ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="h-fit min-w-0 rounded-2xl border border-white/10 bg-slate-950/40 p-5">
                        <h3 class="flex items-center gap-2 text-lg font-bold text-orange-300">
                            <i class="fa-solid fa-calendar-days"></i> ข้อมูลรายการ
                        </h3>
                        <div class="mt-3 grid gap-2 text-sm text-gray-300 sm:grid-cols-2">
                            <?php if (!empty($tournament['format'])): ?><p><span class="text-gray-500">รูปแบบ:</span> <?= htmlspecialchars($tournament['format']); ?></p><?php endif; ?>
                            <?php if (!empty($tournament['best_of'])): ?><p><span class="text-gray-500">การแข่งขัน:</span> Best of <?= (int) $tournament['best_of']; ?></p><?php endif; ?>
                            <?php if (!empty($tournament['prize_pool'])): ?><p><span class="text-gray-500">เงินรางวัล:</span> <?= htmlspecialchars($tournament['prize_pool']); ?></p><?php endif; ?>
                            <?php if (!empty($tournament['venue_address'])): ?><p><span class="text-gray-500">สถานที่:</span> <?= htmlspecialchars($tournament['venue_address']); ?></p><?php endif; ?>
                            <?php if (!empty($tournament['start_date'])): ?><p><span class="text-gray-500">เริ่มแข่งขัน:</span> <?= htmlspecialchars(date('d/m/Y H:i', strtotime($tournament['start_date']))); ?></p><?php endif; ?>
                            <?php if (!empty($tournament['registration_end'])): ?><p><span class="text-gray-500">ปิดรับสมัคร:</span> <?= htmlspecialchars(date('d/m/Y H:i', strtotime($tournament['registration_end']))); ?></p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php if ($hasRules): ?>
                    <div class="h-fit min-w-0 rounded-2xl border border-orange-400/30 bg-orange-500/10 p-5">
                        <h3 class="flex items-center gap-2 text-lg font-bold text-orange-300">
                            <i class="fa-solid fa-scroll"></i> กติกาการแข่งขัน
                        </h3>
                        <p class="mt-3 whitespace-pre-line text-sm leading-7 text-gray-200"><?= htmlspecialchars($tournament['rules']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($tournament['play_mode'] === 'solo'): ?>
                <?php if ($isLoggedIn): ?>
                    <form method="post" class="mt-5 flex flex-wrap items-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>"><input type="hidden" name="tournament_id" value="<?= $id; ?>"><input type="hidden" name="mode" value="solo">
                        <?php if ($fixedCategoryId): ?><input type="hidden" name="tournament_category_id" value="<?= $fixedCategoryId; ?>"><?php endif; ?>
                        <label class="text-sm">รุ่นการแข่งขัน<select <?= $fixedCategoryId ? 'disabled' : ''; ?> name="<?= $fixedCategoryId ? '' : 'tournament_category_id'; ?>" required class="mt-1 block rounded-lg bg-slate-900 p-3"><?php foreach ($tournamentCategories as $category): ?><option value="<?= (int) $category['tournament_category_id']; ?>" <?= $fixedCategoryId === (int) $category['tournament_category_id'] ? 'selected' : ''; ?>><?= htmlspecialchars(strtolower((string) ($category['category_code'] ?? '')) === 'open' ? 'โอเพ่น' : ($category['label'] ?: $category['name'])); ?></option><?php endforeach; ?></select></label>
                        <button class="rounded-lg bg-orange-500 px-5 py-3 font-bold">สมัคร Solo</button>
                    </form>
                <?php else: ?>
                    <a href="../auth/register.php" class="mt-5 inline-flex rounded-lg bg-orange-500 px-5 py-3 font-bold">สมัครสมาชิกเพื่อเข้าแข่งขัน</a>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!$isLoggedIn): ?>
                    <a href="../auth/register.php" class="mt-5 inline-flex rounded-lg bg-orange-500 px-5 py-3 font-bold">สมัครสมาชิกเพื่อเข้าแข่งขัน</a>
                <?php else: ?>
                <form method="post" enctype="multipart/form-data" class="team-form mt-5 space-y-5" data-tournament="<?= $id; ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>"><input type="hidden" name="tournament_id" value="<?= $id; ?>"><input type="hidden" name="mode" value="team">
                    <label>เลือกทีมของฉัน<select name="existing_team_id" class="existing-team-select mt-1 w-full rounded-lg bg-slate-900 p-3"><option value="">สร้างทีมใหม่</option><?php foreach ($availableTeams as $team): ?><?php $teamRosterData = array_map(static fn(array $member): array => ['player_id' => (int) $member['player_id'], 'name' => $member['name'], 'roles' => $member['roles'], 'is_captain' => $member['is_captain'], 'account_active' => $member['account_active']], $team['members']); ?><option value="<?= (int) $team['team_id']; ?>" data-name="<?= htmlspecialchars($team['name'], ENT_QUOTES); ?>" data-roster="<?= htmlspecialchars(json_encode($teamRosterData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES); ?>" data-captain-active="<?= in_array((int) $team['captain_player_id'], array_column($team['members'], 'player_id'), true) ? '1' : '0'; ?>"><?= htmlspecialchars($team['name']); ?><?= $team['game_id'] === null ? ' (ยังไม่ผูกเกม)' : ''; ?></option><?php endforeach; ?></select></label>
                    <p class="existing-team-note hidden text-xs text-slate-400">แก้ไขรายชื่อและบทบาทได้ที่นี่ โดยจะบันทึกกลับทีมเดิมเมื่อสมัครสำเร็จเท่านั้น</p>
                    <div class="new-team-fields grid gap-4 sm:grid-cols-2">
                        <label>ชื่อทีม<input name="team_name" required class="mt-1 w-full rounded-lg bg-slate-900 p-3"></label>
                        <label>โลโก้ทีม (ถ้ามี)<input type="file" name="team_logo" accept="image/jpeg,image/png,image/webp" class="mt-1 w-full rounded-lg bg-slate-900 p-3"></label>
                    </div>
                    <?php if ($fixedCategoryId): ?><input type="hidden" name="tournament_category_id" value="<?= $fixedCategoryId; ?>"><?php endif; ?>
                    <label>รุ่นการแข่งขัน<select class="category mt-1 w-full rounded-lg bg-slate-900 p-3" <?= $fixedCategoryId ? 'disabled' : ''; ?> name="<?= $fixedCategoryId ? '' : 'tournament_category_id'; ?>" required><option value="" <?= $fixedCategoryId ? '' : 'selected'; ?>>เลือกรุ่นการแข่งขัน</option><?php foreach ($tournamentCategories as $category): ?><option value="<?= (int) $category['tournament_category_id']; ?>" <?= $fixedCategoryId === (int) $category['tournament_category_id'] ? 'selected' : ''; ?> data-starters="<?= (int) $category['starters_count']; ?>" data-subs="<?= (int) $category['substitutes_count']; ?>" data-required="<?= htmlspecialchars((string) $category['checkin_required_roles']); ?>"><?= htmlspecialchars(strtolower((string) ($category['category_code'] ?? '')) === 'open' ? 'โอเพ่น' : ($category['label'] ?: $category['name'])); ?></option><?php endforeach; ?></select></label>
                    <div class="category-info text-sm text-slate-400"></div><div class="roster-warning text-xs text-amber-300"></div>
                    <div class="new-team-roster space-y-5">
                    <input type="search" class="search w-full rounded-lg bg-slate-900 p-3" placeholder="ค้นหาผู้เล่นด้วยชื่อ Username หรือ Player ID" disabled>
                    <div class="search-results space-y-2"></div>
                    <div class="selected grid gap-4 sm:grid-cols-2"><div><h3>ผู้เล่นตัวจริง <span class="starter-count">0</span></h3><div class="starters space-y-2"></div></div><div><h3>ตัวสำรอง <span class="sub-count">0</span></h3><div class="subs space-y-2"></div></div></div>
                    <div><h3>ทีมงาน</h3><div class="staff space-y-2"></div></div>
                    </div>
                    <button type="submit" disabled class="submit-team rounded-lg bg-orange-500 px-5 py-3 font-bold disabled:cursor-not-allowed disabled:opacity-40">ยืนยันการสมัคร</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
</main></div>
<script>
document.querySelectorAll('.team-form').forEach((form) => {
    const category = form.querySelector('.category'), search = form.querySelector('.search'), results = form.querySelector('.search-results');
    const starters = form.querySelector('.starters'), subs = form.querySelector('.subs'), staff = form.querySelector('.staff');
    const existingTeamSelect = form.querySelector('.existing-team-select');
    const newTeamFields = form.querySelector('.new-team-fields'), newTeamRoster = form.querySelector('.new-team-roster');
    const existingTeamNote = form.querySelector('.existing-team-note');
    let selected = new Map(), config = { starters: 0, subs: 0, required: [] };
    let eligibilityPending = false, eligibilityFailed = false, eligibilityRequest = 0, nextIndex = 0, rosterLoadedTeamId = '';
    const roleLabels = { manager: 'ผู้จัดการ', coach: 'โค้ช', player: 'ตัวจริง', substitute: 'สำรอง' };
    const uiRoleToTeamRole = { starter: 'player', substitute: 'substitute', coach: 'coach', manager: 'manager' };
    const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const hidden = (name, value) => { const i=document.createElement('input'); i.type='hidden'; i.name=name; i.value=value; i.dataset.generated='1'; return i; };
    const updateConfig = () => {
        const option = category.selectedOptions[0], requiredValue = option?.dataset.required || '';
        let required;
        try {
            const parsed = JSON.parse(requiredValue);
            required = Array.isArray(parsed)
                ? parsed.map((role) => String(role).trim()).filter(Boolean)
                : requiredValue.split(',').map((role) => role.trim()).filter(Boolean);
        } catch {
            required = requiredValue.split(',').map((role) => role.trim()).filter(Boolean);
        }
        config = {
            starters: Number(option?.dataset.starters || 0),
            subs: Number(option?.dataset.subs || 0),
            required
        };
        form.querySelector('.category-info').textContent = category.value
            ? `ผู้เล่นตัวจริง ${config.starters} คน · ตัวสำรอง ${config.subs} คน · โค้ช/ผู้จัดการทีมเป็นตัวเลือกเสริม`
            : '';
    };
    const refreshEligibility = async (reloadTeamRoster = false) => {
        if (!existingTeamSelect.value || !category.value) {
            eligibilityPending = false;
            eligibilityFailed = false;
            render();
            return;
        }
        const request = ++eligibilityRequest;
        eligibilityPending = true;
        eligibilityFailed = false;
        search.disabled = true;
        render();
        const params = new URLSearchParams({
            action: 'team_roster_eligibility',
            tournament_id: form.dataset.tournament,
            category_id: category.value,
            team_id: existingTeamSelect.value
        });
        for (const playerId of selected.keys()) params.append('player_ids[]', String(playerId));
        try {
            const response = await fetch(`register-tournament.php?${params}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`Roster eligibility check failed with status ${response.status}`);
            const result = await response.json();
            if (request !== eligibilityRequest) return;
            if (reloadTeamRoster) {
                selected.clear();
                nextIndex = 0;
                for (const member of result.roster || []) {
                    const roles = member.roles || [];
                    const role = roles.includes('player') ? 'starter'
                        : (roles.includes('substitute') ? 'substitute'
                            : (roles.includes('coach') ? 'coach' : (roles.includes('manager') ? 'manager' : '')));
                    selected.set(Number(member.player_id), {
                        role,
                        name: member.name || 'สมาชิกทีม',
                        roles,
                        index: nextIndex++,
                        isCaptain: member.is_captain === true
                    });
                }
                rosterLoadedTeamId = existingTeamSelect.value;
            }
            for (const [playerId, member] of selected) {
                const status = result.eligibility?.[playerId];
                member.accountActive = status?.account_active === true;
                member.eligible = status?.eligible === true;
                member.eligibilityReason = status?.reason || '';
            }
            eligibilityPending = false;
            search.disabled = !category.value;
            render();
        } catch (error) {
            if (request !== eligibilityRequest) return;
            console.error('Team roster eligibility check failed', error);
            eligibilityPending = false;
            eligibilityFailed = true;
            search.disabled = !category.value;
            render();
        }
    };
    const applyExistingTeam = () => {
        eligibilityRequest++;
        eligibilityPending = false;
        eligibilityFailed = false;
        results.innerHTML = '';
        const option = existingTeamSelect.selectedOptions[0];
        const usingTeam = Boolean(existingTeamSelect.value);
        newTeamFields.hidden = usingTeam;
        newTeamRoster.hidden = false;
        existingTeamNote.classList.toggle('hidden', !usingTeam);
        form.querySelector('[name="team_name"]').required = !usingTeam;
        form.querySelector('[name="team_logo"]').disabled = usingTeam;
        form.querySelector('[name="team_name"]').value = usingTeam ? (option.dataset.name || '') : '';
        search.disabled = !category.value;
        selected.clear();
        nextIndex = 0;
        rosterLoadedTeamId = '';
        if (usingTeam) {
            JSON.parse(option.dataset.roster || '[]').forEach((member) => {
                const roles = member.roles || [];
                const role = roles.includes('player') ? 'starter'
                    : (roles.includes('substitute') ? 'substitute'
                        : (roles.includes('coach') ? 'coach' : (roles.includes('manager') ? 'manager' : '')));
                selected.set(Number(member.player_id), {
                    role,
                    name: member.name || 'สมาชิกทีม',
                    roles,
                    index: nextIndex++,
                    isCaptain: member.is_captain === true,
                    accountActive: member.account_active === true,
                    eligible: true,
                    eligibilityReason: ''
                });
            });
        }
        render();
        if (usingTeam && category.value) refreshEligibility(true);
    };
    const render = () => {
        form.querySelectorAll('[data-generated]').forEach((e) => e.remove()); starters.innerHTML=''; subs.innerHTML=''; staff.innerHTML='';
        const usingTeam = Boolean(existingTeamSelect.value);
        let si=0, ui=0;
        const validationMessages = [];
        selected.forEach((member, id) => {
            const roles = usingTeam ? member.roles : [uiRoleToTeamRole[member.role]];
            if (roles.includes('player')) si++;
            if (roles.includes('substitute')) ui++;
            const destination = roles.includes('player') ? starters : (roles.includes('substitute') ? subs : staff);
            const row = document.createElement('div');
            row.className = 'rounded-lg bg-slate-900 p-3 flex flex-wrap justify-between items-center gap-2';
            const roleControls = usingTeam
                ? Object.entries(roleLabels).map(([role, label]) =>
                    `<label class="mr-2 text-xs"><input type="checkbox" class="team-role" data-id="${id}" value="${role}" ${roles.includes(role) ? 'checked' : ''}> ${label}</label>`
                ).join('')
                : `<select class="change-role rounded bg-slate-800 p-1" data-id="${id}"><option value="starter" ${member.role==='starter'?'selected':''}>ตัวจริง</option><option value="substitute" ${member.role==='substitute'?'selected':''}>สำรอง</option><option value="coach" ${member.role==='coach'?'selected':''}>โค้ช</option><option value="manager" ${member.role==='manager'?'selected':''}>ผู้จัดการ</option></select>`;
            row.innerHTML = `<div><span>${esc(member.name)}${member.isCaptain ? ' (กัปตัน)' : ''}</span><div class="mt-2">${roleControls}</div></div><button type="button" class="remove text-red-300" data-id="${id}">นำออก</button>`;
            destination.appendChild(row);
            form.appendChild(hidden(`roster[${member.index}][player_id]`, id));
            const submittedRoles = usingTeam ? roles : [uiRoleToTeamRole[member.role]];
            submittedRoles.forEach((role) => form.appendChild(hidden(`roster[${member.index}][roles][]`, role)));
            if (!submittedRoles.length) validationMessages.push(`${member.name} ยังไม่ได้เลือกบทบาท`);
            if (usingTeam) {
                const playerRole = roles.includes('player') || roles.includes('substitute');
                if (!member.accountActive) validationMessages.push(`${member.name}: บัญชีผู้เล่นไม่ได้เปิดใช้งาน`);
                else if (playerRole && !member.eligible) validationMessages.push(`${member.name}: ${member.eligibilityReason || 'ไม่ผ่านคุณสมบัติของรุ่นแข่งขัน'}`);
            } else if (member.accountActive === false) {
                validationMessages.push(`${member.name}: บัญชีผู้เล่นไม่ได้เปิดใช้งาน`);
            }
        });
        form.querySelector('.starter-count').textContent=`${si}/${config.starters}`; form.querySelector('.sub-count').textContent=`${ui}/${config.subs}`;
        const selectedRoles = [...selected.values()].flatMap((member) => usingTeam ? member.roles : [uiRoleToTeamRole[member.role]]);
        const captainSelected = [...selected.values()].some((member) => member.isCaptain);
        const requiredOk = config.required.every((role) => role === 'captain' && usingTeam
            ? captainSelected
            : selectedRoles.includes(role));
        if (category.value && si !== config.starters) validationMessages.push(`ต้องมีผู้เล่นตัวจริง ${config.starters} คน`);
        if (category.value && ui !== config.subs) validationMessages.push(`ต้องมีผู้เล่นสำรอง ${config.subs} คน`);
        if (category.value && !requiredOk) validationMessages.push('บทบาทสมาชิกยังไม่ครบตามรุ่นแข่งขัน');
        if (eligibilityPending) validationMessages.push('กำลังตรวจสอบคุณสมบัติสมาชิกกับรุ่นแข่งขัน...');
        if (eligibilityFailed) validationMessages.push('ตรวจสอบคุณสมบัติทีมไม่สำเร็จ กรุณาลองเปลี่ยนรุ่นหรือเลือกทีมใหม่');
        form.querySelector('.roster-warning').textContent = validationMessages.join(' · ');
        form.querySelector('.submit-team').disabled = !category.value || si !== config.starters || ui !== config.subs
            || !requiredOk || validationMessages.length > 0 || eligibilityPending || eligibilityFailed;
    };
    category.addEventListener('change', () => {
        updateConfig();
        search.disabled = !category.value;
        results.innerHTML = '';
        if (existingTeamSelect.value) refreshEligibility(rosterLoadedTeamId !== existingTeamSelect.value);
        else {
            search.disabled = !category.value;
            selected.clear();
            render();
        }
    });
    existingTeamSelect.addEventListener('change', applyExistingTeam);
    search.addEventListener('input', async () => {
        const term = search.value.trim();
        if (term.length < 1 || !category.value) {
            results.innerHTML = '';
            return;
        }
        const params = new URLSearchParams({
            action: 'search_players',
            tournament_id: form.dataset.tournament,
            category_id: category.value,
            q: term
        });
        if (existingTeamSelect.value) params.set('team_id', existingTeamSelect.value);
        try {
            const response = await fetch(`register-tournament.php?${params}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`Search failed with status ${response.status}`);
            const players = await response.json();
            results.innerHTML = players.length ? players.map(x => {
                const playerDisabled = x.eligible ? '' : 'disabled title="' + esc(x.eligibility_reason || 'ผู้เล่นคนนี้ไม่ผ่านเงื่อนไขของรายการ') + '"';
                const staffDisabled = x.staff_eligible ? '' : 'disabled title="บัญชีผู้จัดการหรือโค้ชไม่ได้เปิดใช้งาน"';
                const name = x.display_name || x.username || 'ผู้เล่น';
                const eligibilityData = `data-account-active="${x.staff_eligible ? '1' : '0'}" data-eligible="${x.eligible ? '1' : '0'}" data-eligibility-reason="${esc(x.eligibility_reason || '')}"`;
                return `<div class="flex justify-between rounded-lg border border-white/10 p-3"><span>${esc(name)} ${x.age_at_tournament === null ? '' : 'อายุ ' + x.age_at_tournament + ' ปี'}<small class="ml-2 text-red-300">${x.eligible ? '' : esc(x.eligibility_reason || 'ผู้เล่นคนนี้ไม่ผ่านเงื่อนไขของรายการ')}</small></span><span><button ${playerDisabled} ${eligibilityData} type="button" data-role="starter" data-id="${x.player_id}" data-name="${esc(name)}" class="add mr-2 text-green-300">ตัวจริง</button><button ${playerDisabled} ${eligibilityData} type="button" data-role="substitute" data-id="${x.player_id}" data-name="${esc(name)}" class="add mr-2 text-cyan-300">สำรอง</button><button ${staffDisabled} ${eligibilityData} type="button" data-role="coach" data-id="${x.player_id}" data-name="${esc(name)}" class="add mr-2 text-purple-300">โค้ช</button><button ${staffDisabled} ${eligibilityData} type="button" data-role="manager" data-id="${x.player_id}" data-name="${esc(name)}" class="add text-purple-300">ผู้จัดการ</button></span></div>`;
            }).join('') : '<div class="rounded-lg border border-white/10 p-3 text-sm text-slate-400">ยังไม่พบนักกีฬาที่ตรงกับคำค้น</div>';
        } catch (error) {
            console.error('Player search failed', error);
            results.innerHTML = '<div class="rounded-lg border border-red-400/30 bg-red-500/10 p-3 text-sm text-red-200">ไม่สามารถค้นหาผู้เล่นได้ กรุณาลองใหม่</div>';
        }
    });
    form.addEventListener('change', (event) => {
        const checkbox = event.target.closest('.team-role');
        if (checkbox) {
            const member = selected.get(Number(checkbox.dataset.id));
            if (!member) return;
            const row = checkbox.closest('.rounded-lg');
            member.roles = [...row.querySelectorAll('.team-role:checked')].map((input) => input.value);
            if (!member.roles.length) selected.delete(Number(checkbox.dataset.id));
            render();
            return;
        }
        const select = event.target.closest('.change-role');
        if (select) {
            const member = selected.get(Number(select.dataset.id));
            if (member) {
                member.role = select.value;
                render();
            }
        }
    });
    form.addEventListener('click', (event) => {
        const button = event.target.closest('.add,.remove');
        if (!button) return;
        if (button.classList.contains('remove')) {
            selected.delete(Number(button.dataset.id));
        } else {
            const playerId = Number(button.dataset.id), role = button.dataset.role;
            if (selected.has(playerId)) return;
            selected.set(playerId, {
                role,
                name: button.dataset.name,
                roles: [uiRoleToTeamRole[role]],
                index: nextIndex++,
                accountActive: button.dataset.accountActive === '1',
                eligible: button.dataset.eligible === '1',
                eligibilityReason: button.dataset.eligibilityReason || ''
            });
        }
        render();
    });
    if (category.value) {
        updateConfig();
        search.disabled = !category.value;
        render();
    }
});
</script>
<script src="../assets/js/mobile-nav.js" defer></script>
</body>
</html>
