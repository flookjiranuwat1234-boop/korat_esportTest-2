<?php
// pages/profile.php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireLogin();

// ดึงข้อมูล Player จาก user_id
$pStmt = $pdo->prepare("SELECT * FROM players WHERE user_id = :uid");
$pStmt->execute(['uid' => $_SESSION['user_id']]);
$player = $pStmt->fetch();

if (!$player) {
    header('Location: claim-profile.php');
    exit;
}

$playerId = (int) $player['player_id'];
$error = '';
$success = '';

$displayName = $player['display_name'] ?? '';
$bio = $player['bio'] ?? '';
$avatarPath = $player['avatar_path'] ?? ($player['image_path'] ?? '');

// ================= 1. แก้ไขโปรไฟล์ส่วนตัว =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $displayNameInput = trim($_POST['display_name'] ?? '');
        $bioInput = trim($_POST['bio'] ?? '');
        $newAvatarPath = $avatarPath; // ใช้ค่าเดิมสำรองไว้ก่อน

        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (in_array($ext, $allowed)) {
                $uploadDir = '../assets/uploads/players/';
                if (!is_dir($uploadDir)) { 
                    mkdir($uploadDir, 0777, true); 
                }
                
                $fileName = 'player_' . $playerId . '_' . time() . '.' . $ext;
                $targetFile = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetFile)) {
                    $newAvatarPath = 'uploads/players/' . $fileName;
                }
            }
        }

        if (empty($displayNameInput)) {
            $error = 'กรุณากรอกชื่อแสดงผล (Display Name)';
        } else {
            // บังคับบันทึก avatar_path ลงฐานข้อมูลเสมอ
            $update = $pdo->prepare("
                UPDATE players 
                SET display_name = :dn, bio = :bio, avatar_path = :img 
                WHERE player_id = :pid
            ");
            $update->execute([
                'dn'  => $displayNameInput, 
                'bio' => $bioInput, 
                'img' => $newAvatarPath, 
                'pid' => $playerId
            ]);

            // ดึงข้อมูลใหม่มาแสดงผลทันที
            $pStmt->execute(['uid' => $_SESSION['user_id']]);
            $player = $pStmt->fetch();
            $displayName = $player['display_name'] ?? '';
            $bio = $player['bio'] ?? '';
            $avatarPath = $player['avatar_path'] ?? ($player['image_path'] ?? '');
            $success = 'อัปเดตโปรไฟล์เรียบร้อยแล้ว!';
        }
    }
}

// ================= 2. ส่งคำเชิญผู้เล่นเข้าทีมหลายคนพร้อมกัน (กัปตัน) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manage_team') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง';
    } else {
        $teamId = (int) $_POST['team_id'];
        $teamNameInput = trim($_POST['team_name'] ?? '');

        $chkCap = $pdo->prepare("SELECT * FROM teams WHERE team_id = :tid AND captain_player_id = :pid");
        $chkCap->execute(['tid' => $teamId, 'pid' => $playerId]);
        $teamData = $chkCap->fetch();

        if (!$teamData) {
            $error = 'คุณไม่มีสิทธิ์จัดการทีมนี้';
        } else {
            if (!empty($teamNameInput)) {
                $pdo->prepare("UPDATE teams SET name = :name WHERE team_id = :tid")->execute(['name' => $teamNameInput, 'tid' => $teamId]);
            }

            // รองรับการรับค่า add_player_ids เป็นอาเรย์ หรือค่าเดี่ยว
            $invitedPlayerIds = $_POST['add_player_ids'] ?? [];
            if (!is_array($invitedPlayerIds) && !empty($_POST['add_player_ids'])) {
                $invitedPlayerIds = [(int)$_POST['add_player_ids']];
            }

            $role = trim($_POST['in_game_role'] ?? '');
            $inviteCount = 0;

            if (!empty($invitedPlayerIds)) {
                foreach ($invitedPlayerIds as $addPlayerId) {
                    $addPlayerId = (int) $addPlayerId;
                    if ($addPlayerId <= 0 || $addPlayerId === $playerId) continue;

                    $chkMem = $pdo->prepare("SELECT team_member_id, is_active FROM team_members WHERE team_id = :tid AND player_id = :pid");
                    $chkMem->execute(['tid' => $teamId, 'pid' => $addPlayerId]);
                    $existingMem = $chkMem->fetch();

                    if (!$existingMem) {
                        $pdo->prepare("INSERT INTO team_members (team_id, player_id, in_game_role, is_active) VALUES (:tid, :pid, :role, 0)")
                            ->execute(['tid' => $teamId, 'pid' => $addPlayerId, 'role' => $role]);
                        $inviteCount++;
                    }
                }
                if ($inviteCount > 0) {
                    $success = "ส่งคำเชิญเข้าร่วมทีมไปยังผู้เล่นจำนวน {$inviteCount} คนเรียบร้อยแล้ว!";
                }
            }

            if (isset($_FILES['team_logo']) && $_FILES['team_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['team_logo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if (in_array($ext, $allowed)) {
                    $uploadDir = '../assets/uploads/teams/';
                    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

                    $fileName = 'team_' . $teamId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['team_logo']['tmp_name'], $uploadDir . $fileName)) {
                        $pdo->prepare("UPDATE teams SET logo_path = :logo WHERE team_id = :tid")->execute(['logo' => 'uploads/teams/' . $fileName, 'tid' => $teamId]);
                    }
                }
            }
        }
    }
}

// ================= 3. ตอบรับ / ปฏิเสธ คำเชิญเข้าทีม =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accept_invite') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { $error = 'คำขอไม่ถูกต้อง'; }
    $invId = (int) ($_POST['team_member_id'] ?? 0);
    if (!$error) {
    $pdo->prepare("UPDATE team_members SET is_active = 1 WHERE team_member_id = :id AND player_id = :pid")
        ->execute(['id' => $invId, 'pid' => $playerId]);
    $success = 'ตอบรับคำเชิญเข้าร่วมทีมเรียบร้อยแล้ว!';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_invite') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { $error = 'คำขอไม่ถูกต้อง'; }
    $invId = (int) ($_POST['team_member_id'] ?? 0);
    if (!$error) {
    $pdo->prepare("UPDATE team_members SET is_active = 0, left_at = COALESCE(left_at, NOW()) WHERE team_member_id = :id AND player_id = :pid")
        ->execute(['id' => $invId, 'pid' => $playerId]);
    $success = 'ปฏิเสธคำเชิญเรียบร้อยแล้ว';
    }
}

// ================= 4. ยุบทีมแบบ Soft Delete เพื่อรักษาประวัติการแข่งขัน =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_team') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) { $error = 'คำขอไม่ถูกต้อง'; }
    $delTeamId = (int) ($_POST['team_id'] ?? 0);
    $chkCap = $pdo->prepare("SELECT team_id FROM teams WHERE team_id = :tid AND captain_player_id = :pid");
    $chkCap->execute(['tid' => $delTeamId, 'pid' => $playerId]);

    if (!$error && $chkCap->fetch()) {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE team_members SET is_active = 0, left_at = COALESCE(left_at, NOW()) WHERE team_id = :tid AND is_active = 1")
            ->execute(['tid' => $delTeamId]);
        $pdo->prepare("UPDATE teams SET status = 'disbanded', status_reason = 'ยุบทีมโดยกัปตัน',
            status_changed_at = NOW(), status_changed_by = :uid WHERE team_id = :tid")
            ->execute(['uid' => $_SESSION['user_id'], 'tid' => $delTeamId]);
        $pdo->commit();
        $success = 'ยุบทีมเรียบร้อยแล้ว และยังเก็บประวัติการแข่งขันไว้';
    }
}

// ================= 5. ดึงข้อมูลคำเชิญเข้าทีมที่ค้างอยู่ =================
$invitesStmt = $pdo->prepare("
    SELECT tm.team_member_id, t.name AS team_name, tm.in_game_role
    FROM team_members tm
    JOIN teams t ON t.team_id = tm.team_id
    WHERE tm.player_id = :pid AND tm.is_active = 0
");
$invitesStmt->execute(['pid' => $playerId]);
$myInvites = $invitesStmt->fetchAll();

// ================= 6. ดึงสถิติการแข่ง & ประวัติแมตช์ =================
$hStmt = $pdo->prepare("SELECT DISTINCT m.*, tr.team_id AS roster_team_id, tr.player_id AS roster_player_id,
    t1.name AS team1_name, t2.name AS team2_name, tour.name AS tournament_name,
    tr.tournament_category_id, trm.member_roles
    FROM tournament_registration_members trm
    JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
    JOIN matches m ON m.tournament_id = tr.tournament_id
        AND (m.team1_id = tr.team_id OR m.team2_id = tr.team_id OR m.team1_id = tr.player_id OR m.team2_id = tr.player_id)
    JOIN tournaments tour ON tour.tournament_id = m.tournament_id
    LEFT JOIN teams t1 ON t1.team_id = m.team1_id
    LEFT JOIN teams t2 ON t2.team_id = m.team2_id
    WHERE trm.player_id = :player_id AND tr.status = 'approved'
      AND m.status IN ('completed', 'walkover')
    ORDER BY m.completed_at DESC");
$hStmt->execute(['player_id' => $playerId]);
$matchHistory = $hStmt->fetchAll();
$totalMatches = count($matchHistory); $totalWins = 0; $totalLosses = 0;
foreach ($matchHistory as $mh) {
    $participantId = (int) ($mh['roster_team_id'] ?: $mh['roster_player_id']);
    $won = $participantId > 0 && (int) $mh['winner_team_id'] === $participantId;
    if ($won) $totalWins++; else $totalLosses++;
}
$winRate = $totalMatches > 0 ? round(($totalWins / $totalMatches) * 100, 1) : 0;

// ดึงรายการสมัครทัวร์นาเมนต์
$registrations = $pdo->prepare("
    SELECT DISTINCT tr.*, t.name AS tournament_name, t.venue_address, tm.name AS team_name, g.name AS game_name,
        trm.member_roles, trm.is_starter, trm.is_required_for_checkin, trm.checkin_status AS roster_checkin_status,
        trm.checkin_at AS roster_checkin_at
    FROM tournament_registrations tr
    JOIN tournaments t ON t.tournament_id = tr.tournament_id
    JOIN games g ON g.game_id = t.game_id
    LEFT JOIN teams tm ON tm.team_id = tr.team_id
    JOIN tournament_registration_members trm ON trm.tournament_registration_id = tr.tournament_registration_id
    WHERE trm.player_id = :pid AND trm.roster_status = 'active'
    ORDER BY tr.registered_at DESC
");
$registrations->execute(['pid' => $playerId]);
$myRegistrations = $registrations->fetchAll();

// ดึงทีมที่สังกัด (ใช้ LEFT JOIN กับ games เพื่อป้องกันกรณีทีมกลางที่ไม่ได้ผูกเกมถูกซ่อน)
$teamsStmt = $pdo->prepare("
    SELECT tm.*, g.name AS game_name, (tm.captain_player_id = :pid) AS is_captain
    FROM teams tm
    LEFT JOIN games g ON g.game_id = tm.game_id
    JOIN team_members tm_mb ON tm_mb.team_id = tm.team_id
    WHERE tm_mb.player_id = :pid2 AND tm_mb.is_active = 1
    ORDER BY tm.created_at DESC
");
$teamsStmt->execute(['pid' => $playerId, 'pid2' => $playerId]);
$myTeams = $teamsStmt->fetchAll();

// ดึงรายชื่อผู้เล่นทั้งหมดสำหรับค้นหา
$allPlayersStmt = $pdo->prepare("SELECT player_id, display_name FROM players WHERE player_id != :pid ORDER BY display_name");
$allPlayersStmt->execute(['pid' => $playerId]);
$allPlayers = $allPlayersStmt->fetchAll();

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ของฉัน - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { brand: { orange: '#FF5500', glow: '#FF7700', dark: '#0A0A0C' } },
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
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.60), rgba(15, 17, 23, 0.95)),
                        url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover; background-position: center; background-attachment: fixed;
        }
        .glass-nav { background: rgba(15, 17, 23, 0.85); backdrop-filter: blur(16px); border-bottom: 1px solid rgba(255, 255, 255, 0.15); }
        .glass-panel { background: rgba(255, 255, 255, 0.07); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.15); }
        .glass-card { background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(14px); border: 1px solid rgba(255, 255, 255, 0.15); }
        .grid-bg { background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0); background-size: 24px 24px; }
        
        #particles-canvas {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1;
        }
    </style>
</head>
<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>
    <canvas id="particles-canvas"></canvas>

    <div class="relative z-10 flex flex-col min-h-screen">

        <header class="sticky top-0 z-50 glass-nav">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <a href="index.php" class="flex items-center gap-3">
                        <img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto" onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl text-white">KORAT <span class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] text-gray-200 font-bold uppercase -mt-1">Official Arena & Hub</span>
                        </div>
                    </a>

                    <nav class="hidden md:flex items-center gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">หน้าแรก</a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ทัวร์นาเมนต์</a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ตารางคะแนน</a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ข่าวสาร</a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">แกลเลอรี่</a>
                    </nav>

                    <div class="flex items-center gap-3 bg-white/10 p-1.5 pl-3.5 rounded-2xl">
                        <span class="text-sm font-bold text-white"><?= htmlspecialchars($displayName) ?></span>
                        <a href="../auth/logout.php" title="ออกจากระบบ" class="w-9 h-9 rounded-xl bg-rose-500/20 text-rose-300 flex items-center justify-center hover:bg-rose-600 hover:text-white">
                            <i class="fa-solid fa-right-from-bracket"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full space-y-10">

            <?php if ($error): ?>
                <div class="p-4 rounded-2xl bg-rose-500/20 border border-rose-500/40 text-rose-200 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-xl text-rose-400"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="p-4 rounded-2xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-200 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-circle-check text-xl text-emerald-400"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            <?php endif; ?>

            <?php if (count($myInvites) > 0): ?>
                <div class="glass-panel p-6 rounded-3xl border-2 border-brand-orange/50 shadow-orange-glow space-y-4 bg-gradient-to-r from-brand-orange/10 via-transparent to-transparent">
                    <div class="flex items-center gap-3 border-b border-white/10 pb-3">
                        <i class="fa-solid fa-envelope-open-text text-brand-orange text-2xl animate-bounce"></i>
                        <div>
                            <h2 class="text-base font-bold text-white uppercase tracking-wider">คำเชิญเข้าร่วมทีมสโมสร (<?= count($myInvites) ?> คำขอ)</h2>
                            <p class="text-xs text-gray-300">มีกัปตันทีมส่งคำเชิญให้คุณเข้าร่วมทีม โปรดตอบรับหรือปฏิเสธคำขอ</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($myInvites as $inv): ?>
                            <div class="bg-black/50 p-4 rounded-2xl border border-white/15 flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-bold text-white font-display"><?= htmlspecialchars($inv['team_name']) ?></h3>
                                    <?php if ($inv['in_game_role']): ?>
                                        <p class="text-xs text-gray-400">ตำแหน่ง: <span class="text-gray-200"><?= htmlspecialchars($inv['in_game_role']) ?></span></p>
                                    <?php endif; ?>
                                </div>

                                <div class="flex items-center gap-2">
                                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="action" value="accept_invite"><input type="hidden" name="team_member_id" value="<?= (int) $inv['team_member_id'] ?>"><button type="submit" class="px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition-all shadow-md">
                                        <i class="fa-solid fa-check mr-1"></i> ตอบรับ
                                    </button></form>
                                    <form method="POST" class="inline" onsubmit="return confirm('ปฏิเสธคำเชิญนี้ใช่หรือไม่?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>"><input type="hidden" name="action" value="reject_invite"><input type="hidden" name="team_member_id" value="<?= (int) $inv['team_member_id'] ?>"><button type="submit" class="px-3.5 py-2 rounded-xl bg-white/10 hover:bg-rose-600 text-gray-300 hover:text-white text-xs font-bold transition-all">
                                       onclick="return confirm('ปฏิเสธคำเชิญนี้ใช่หรือไม่?')"
                                        <i class="fa-solid fa-xmark"></i>
                                    </button></form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <section class="glass-panel p-6 sm:p-8 rounded-3xl border border-white/20 shadow-2xl space-y-8">
                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6 border-b border-white/15 pb-6">
                    <div class="flex items-center gap-6">
                        <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl bg-slate-900 border-2 border-brand-orange overflow-hidden shadow-orange-glow shrink-0 flex items-center justify-center">
                            <?php 
                                $avatarSrc = '';
                                if (!empty($avatarPath)) {
                                    $path = trim($avatarPath);
                                    if (strpos($path, 'http') === 0) {
                                        $avatarSrc = $path;
                                    } else {
                                        $cleanPath = ltrim($path, '/');
                                        if (strpos($cleanPath, 'assets/') === 0) {
                                            $avatarSrc = '../' . $cleanPath;
                                        } else {
                                            $avatarSrc = '../assets/' . $cleanPath;
                                        }
                                    }
                                }
                            ?>
                            <?php if (!empty($avatarPath) && file_exists(__DIR__ . '/../assets/' . ltrim($avatarPath, '/'))): ?>
                                <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="<?= htmlspecialchars($displayName) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-brand-orange/20 text-brand-orange font-display font-black text-xl">
                                    Avatar
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="px-2.5 py-0.5 rounded-full bg-brand-orange/20 text-brand-orange text-[10px] font-bold uppercase border border-brand-orange/30">
                                    <i class="fa-solid fa-certificate"></i> Verified Athlete
                                </span>
                            </div>
                            <h1 class="text-3xl font-black font-display text-white">
                                <?= htmlspecialchars($displayName) ?>
                            </h1>
                            <p class="text-xs text-gray-400">
                                บัญชี: <span class="text-gray-200 font-semibold"><?= htmlspecialchars($currentUser['username'] ?? 'User') ?></span>
                            </p>
                        </div>
                    </div>

                    <button onclick="toggleModal('editProfileModal')" class="px-5 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white text-xs font-bold uppercase shadow-orange-glow flex items-center gap-2 transition-all cursor-pointer">
                        <i class="fa-solid fa-user-pen"></i>
                        <span>แก้ไขโปรไฟล์ & รูปส่วนตัว</span>
                    </button>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-black/40 p-4 rounded-2xl border border-white/10 text-center">
                        <span class="text-[10px] uppercase font-bold text-gray-400 block"><i class="fa-solid fa-gamepad text-brand-orange"></i> แข่งทั้งหมด</span>
                        <span class="text-2xl font-black font-display text-white"><?= $totalMatches ?></span>
                    </div>
                    <div class="bg-black/40 p-4 rounded-2xl border border-white/10 text-center">
                        <span class="text-[10px] uppercase font-bold text-gray-400 block"><i class="fa-solid fa-trophy text-emerald-400"></i> ชนะ (Wins)</span>
                        <span class="text-2xl font-black font-display text-emerald-400"><?= $totalWins ?></span>
                    </div>
                    <div class="bg-black/40 p-4 rounded-2xl border border-white/10 text-center">
                        <span class="text-[10px] uppercase font-bold text-gray-400 block"><i class="fa-solid fa-xmark text-rose-400"></i> แพ้ (Losses)</span>
                        <span class="text-2xl font-black font-display text-rose-400"><?= $totalLosses ?></span>
                    </div>
                    <div class="bg-black/40 p-4 rounded-2xl border border-white/10 text-center">
                        <span class="text-[10px] uppercase font-bold text-gray-400 block"><i class="fa-solid fa-chart-line text-amber-400"></i> Win Rate</span>
                        <span class="text-2xl font-black font-display text-brand-orange"><?= $winRate ?>%</span>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-brand-orange flex items-center gap-1.5">
                        <i class="fa-solid fa-award"></i> ป้ายเกียรติยศ / ถ้วยรางวัล (Achievements)
                    </h3>
                    <div class="flex flex-wrap gap-3">
                        <span class="px-3.5 py-2 rounded-2xl bg-amber-500/20 border border-amber-500/40 text-amber-300 text-xs font-bold flex items-center gap-2">
                            <i class="fa-solid fa-crown text-amber-400 text-sm"></i> สมาชิกสโมสร Korat Esport
                        </span>
                        <?php if (count($myTeams) > 0): ?>
                            <span class="px-3.5 py-2 rounded-2xl bg-indigo-500/20 border border-indigo-500/40 text-indigo-300 text-xs font-bold flex items-center gap-2">
                                <i class="fa-solid fa-shield-halved text-indigo-400 text-sm"></i> สังกัด <?= count($myTeams) ?> ทีมสโมสร
                            </span>
                        <?php endif; ?>
                        <?php if ($totalWins > 0): ?>
                            <span class="px-3.5 py-2 rounded-2xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 text-xs font-bold flex items-center gap-2">
                                <i class="fa-solid fa-fire text-emerald-400 text-sm"></i> คว้าชัยชนะ <?= $totalWins ?> แมตช์
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="space-y-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-brand-orange flex items-center gap-1.5">
                        <i class="fa-solid fa-id-card"></i> ผลงาน / ประวัติการแข่งขัน (Portfolio / Bio)
                    </h3>
                    <div class="bg-black/40 p-4 rounded-2xl border border-white/10 text-sm text-gray-200 font-normal">
                        <?= !empty($bio) ? nl2br(htmlspecialchars($bio)) : '<span class="text-gray-500 italic">ยังไม่ได้ระบุประวัติส่วนตัว กดปุ่ม "แก้ไขโปรไฟล์ & รูปส่วนตัว" เพื่อเพิ่มผลงาน</span>' ?>
                    </div>
                </div>
            </section>

            <section class="space-y-6">
                <div class="flex items-center gap-3 border-b border-white/15 pb-4">
                    <i class="fa-solid fa-clock-rotate-left text-brand-orange text-2xl"></i>
                    <div>
                        <h2 class="text-xl font-bold font-display text-white uppercase">ประวัติการแข่งขันย้อนหลัง (MATCH HISTORY)</h2>
                        <p class="text-xs text-gray-400">รายการแมตช์การแข่งขันที่เคยเข้าร่วม พร้อมผลการแข่งขัน ชนะ/แพ้</p>
                    </div>
                </div>

                <?php if (count($matchHistory) == 0): ?>
                    <div class="glass-panel p-8 text-center text-gray-400 rounded-2xl text-xs">
                        ยังไม่มีประวัติการลงแข่งขันในแมตช์อย่างเป็นทางการ
                    </div>
                <?php else: ?>
                    <div class="glass-panel rounded-2xl overflow-hidden border border-white/15 shadow-xl">
                        <table class="w-full text-left text-xs text-gray-200">
                            <thead class="bg-white/5 uppercase font-bold text-gray-400 border-b border-white/10">
                                <tr>
                                    <th class="p-3">ทัวร์นาเมนต์</th>
                                    <th class="p-3">การแข่งขัน (VS)</th>
                                    <th class="p-3 text-center">ผลการแข่ง</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php foreach ($matchHistory as $mh): ?>
                                    <?php $profileParticipantId = (int) ($mh['roster_team_id'] ?: $mh['roster_player_id']); $isWon = $profileParticipantId > 0 && (int) $mh['winner_team_id'] === $profileParticipantId; ?>
                                    <tr class="hover:bg-white/5 transition-colors">
                                        <td class="p-3 font-bold text-white"><?= htmlspecialchars($mh['tournament_name']) ?></td>
                                        <td class="p-3 text-gray-300">
                                            <?= htmlspecialchars($mh['team1_name'] ?? '-') ?> <span class="text-brand-orange font-bold">vs</span> <?= htmlspecialchars($mh['team2_name'] ?? '-') ?>
                                        </td>
                                        <td class="p-3 text-center">
                                            <?php if ($isWon): ?>
                                                <span class="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 font-bold border border-emerald-500/30">
                                                    WIN (<?= $mh['team1_score'] ?> - <?= $mh['team2_score'] ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="px-3 py-1 rounded-full bg-rose-500/20 text-rose-300 font-bold border border-rose-500/30">
                                                    LOSS (<?= $mh['team1_score'] ?> - <?= $mh['team2_score'] ?>)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="space-y-6">
                <div class="flex items-center gap-3 border-b border-white/15 pb-4">
                    <i class="fa-solid fa-qrcode text-brand-orange text-2xl"></i>
                    <div>
                        <h2 class="text-xl font-bold font-display text-white uppercase">รายการทัวร์นาเมนต์ & QR Code เช็คอิน</h2>
                        <p class="text-xs text-gray-400">ใช้ QR Code สำหรับยื่นให้เจ้าหน้าที่สแกนรายงานตัวเข้าแข่งขันหน้างาน</p>
                    </div>
                </div>

                <?php if (count($myRegistrations) == 0): ?>
                    <div class="glass-panel p-8 text-center text-gray-400 rounded-2xl text-xs">
                        ยังไม่มีรายการแข่งขันที่สมัครไว้ <a href="tournaments.php" class="text-brand-orange font-bold underline ml-1">ดูทัวร์นาเมนต์ที่เปิดรับสมัคร</a>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($myRegistrations as $reg): ?>
                            <div class="glass-card p-6 rounded-3xl border border-white/15 shadow-xl space-y-4">
                                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 border-b border-white/10 pb-4">
                                    <div>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-white/15 text-brand-orange mr-2">
                                            <?= htmlspecialchars($reg['game_name']) ?>
                                        </span>
                                        <h3 class="text-xl font-bold text-white inline-block mt-1"><?= htmlspecialchars($reg['tournament_name']) ?></h3>
                                        <div class="text-xs text-gray-300 mt-1">
                                            ทีมสโมสร: <strong class="text-brand-orange"><?= htmlspecialchars($reg['team_name']) ?></strong>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center gap-2">
                                        <?php if ($reg['status'] === 'approved'): ?>
                                            <span class="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-bold border border-emerald-500/40 flex items-center gap-1.5">
                                                <i class="fa-solid fa-circle-check"></i> ผ่านการอนุมัติ
                                            </span>
                                        <?php else: ?>
                                            <span class="px-3 py-1 rounded-full bg-amber-500/20 text-amber-300 text-xs font-bold border border-amber-500/40 flex items-center gap-1.5">
                                                <i class="fa-solid fa-clock"></i> รออนุมัติคำขอ
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($reg['status'] === 'approved' && !empty($reg['qr_code_token'])): ?>
                                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
                                        <div class="lg:col-span-4 bg-black/60 p-5 rounded-2xl text-center space-y-3 border border-white/10 shadow-inner flex flex-col justify-between">
                                            <div class="space-y-2">
                                                <p class="text-xs font-bold uppercase tracking-wider text-brand-orange flex items-center justify-center gap-1.5">
                                                    <i class="fa-solid fa-qrcode"></i> QR Code รายงานตัว
                                                </p>
                                                <div class="bg-white p-3 rounded-2xl inline-block shadow-lg mx-auto">
                                                    <img src="https://quickchart.io/qr?text=<?= urlencode($reg['qr_code_token']); ?>&size=160" alt="Check-in QR Code" class="w-36 h-36 mx-auto">
                                                </div>
                                                <div class="font-mono text-xs text-gray-300">
                                                    รหัสอ้างอิง: <span class="font-bold text-white tracking-widest bg-white/10 px-2 py-1 rounded border border-white/10">****<?= htmlspecialchars(substr((string) $reg['qr_code_token'], -4)) ?></span>
                                                </div>
                                            </div>

                                            <div class="pt-2 border-t border-white/10">
                                                <?php if (!empty($reg['checked_in'])): ?>
                                                    <span class="w-full py-2 px-3 rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-xs font-bold block">
                                                        <i class="fa-solid fa-user-check mr-1"></i> รายงานตัวเรียบร้อยแล้ว
                                                    </span>
                                                <?php else: ?>
                                                    <span class="w-full py-2 px-3 rounded-xl bg-amber-500/20 text-amber-300 border border-amber-500/40 text-xs font-bold block">
                                                        <i class="fa-solid fa-hourglass-half mr-1"></i> ยังไม่ได้รายงานตัวหน้างาน
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="lg:col-span-8 bg-white/5 p-6 rounded-2xl border border-white/10 space-y-5 flex flex-col justify-between">
                                            <div class="space-y-3">
                                                <h4 class="text-xs font-bold text-brand-orange uppercase tracking-wider flex items-center gap-1.5">
                                                    <i class="fa-solid fa-list-check"></i> ขั้นตอนการรายงานตัวเข้าแข่งขัน (Check-in Steps)
                                                </h4>
                                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                                                    <div class="bg-black/40 p-3 rounded-xl border border-white/10 space-y-1">
                                                        <span class="font-display font-black text-brand-orange text-sm">STEP 01</span>
                                                        <div class="font-bold text-white">แสดง QR Code</div>
                                                        <p class="text-[11px] text-gray-400">ยื่น QR Code หน้านี้ให้เจ้าหน้าที่สแกนจุดลงทะเบียน</p>
                                                    </div>
                                                    <div class="bg-black/40 p-3 rounded-xl border border-white/10 space-y-1">
                                                        <span class="font-display font-black text-brand-orange text-sm">STEP 02</span>
                                                        <div class="font-bold text-white">ยืนยันตัวตน</div>
                                                        <p class="text-[11px] text-gray-400">แสดงบัตรประชาชน/บัตรนักศึกษาของสมาชิกในทีม</p>
                                                    </div>
                                                    <div class="bg-black/40 p-3 rounded-xl border border-white/10 space-y-1">
                                                        <span class="font-display font-black text-brand-orange text-sm">STEP 03</span>
                                                        <div class="font-bold text-white">เข้าสู่โซนเตรียมแข่ง</div>
                                                        <p class="text-[11px] text-gray-400">รับป้ายทีมและเข้าประจำที่นั่งแข่งขันก่อน 15 นาที</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="space-y-2">
                                                <h4 class="text-xs font-bold text-gray-300 uppercase tracking-wider flex items-center gap-1.5">
                                                    <i class="fa-solid fa-shield-cat text-amber-400"></i> กฎระเบียบสำคัญประจำสนามแข่งขัน (Arena Rules)
                                                </h4>
                                                <ul class="text-[11px] text-gray-300 space-y-1.5 pl-4 list-disc marker:text-brand-orange">
                                                    <li>กัปตันทีมต้องนำนักกีฬามารายงานตัวล่วงหน้าอย่างน้อย <strong>30 นาที</strong> ก่อนเวลาการแข่งประจำรอบ</li>
                                                    <li>ไม่อนุญาตให้นำอาหารและเครื่องดื่มแบบแก้วเปิดเข้าบริเวณเครื่องแข่งขัน</li>
                                                    <li>นักกีฬาต้องแต่งกายด้วยชุดสุภาพ หรือเสื้อสโมสรประจำทีมที่ลงทะเบียนไว้</li>
                                                    <li>หากมารายงานตัวช้ากว่ากำหนดเกิน <strong>15 นาที</strong> อาจถูกปรับแพ้บาย (Walkover) ในแมตช์นั้น</li>
                                                </ul>
                                            </div>

                                            <div class="pt-3 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3">
                                                <div class="text-xs text-gray-300 flex items-center gap-1.5">
                                                    <i class="fa-solid fa-location-dot text-brand-orange text-sm"></i>
                                                    <span>สนามแข่ง: <strong><?= !empty($reg['venue_address']) ? htmlspecialchars($reg['venue_address']) : 'Korat Esport Main Arena' ?></strong></span>
                                                </div>

                                                <?php if (!empty($reg['venue_address'])): ?>
                                                    <a href="https://maps.google.com/?q=<?= urlencode($reg['venue_address']); ?>" 
                                                       target="_blank" rel="noopener"
                                                       class="w-full sm:w-auto px-4 py-2 rounded-xl bg-blue-600/30 hover:bg-blue-600 text-blue-200 hover:text-white border border-blue-500/40 text-xs font-bold transition-all flex items-center justify-center gap-1.5 shrink-0">
                                                        <i class="fa-solid fa-map-location-dot"></i>
                                                        <span>นำทางด้วย Google Maps</span>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="space-y-6">
                <div class="flex items-center justify-between border-b border-white/15 pb-4">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-people-group text-brand-orange text-2xl"></i>
                        <div>
                            <h2 class="text-xl font-bold font-display text-white uppercase">ทีมของฉัน (MY TEAMS)</h2>
                            <p class="text-xs text-gray-400">รายการทีมสโมสรที่คุณสังกัด สามารถกดดูรายชื่อสมาชิกในทีม หรือจัดการทีมได้</p>
                        </div>
                    </div>

                    <a href="create-team.php" class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/15 text-xs font-bold text-white transition-all flex items-center gap-1.5">
                        <i class="fa-solid fa-plus text-brand-orange"></i>
                        <span>สร้างทีมใหม่</span>
                    </a>
                </div>

                <?php if (count($myTeams) == 0): ?>
                    <div class="glass-panel p-8 text-center text-gray-400 rounded-2xl text-xs">
                        คุณยังไม่ได้สังกัดทีมใดๆ <a href="create-team.php" class="text-brand-orange font-bold underline ml-1">สร้างทีมแข่งขันใหม่</a>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php foreach ($myTeams as $team): ?>
                        <div class="glass-panel p-6 rounded-3xl space-y-5 border border-white/15 shadow-xl flex flex-col justify-between">
                            <div class="flex items-center justify-between gap-4 border-b border-white/10 pb-4">
                                <div class="flex items-center gap-4">
                                    <div class="w-14 h-14 rounded-2xl bg-black/50 border border-white/20 overflow-hidden flex items-center justify-center shrink-0">
                                        <?php if (!empty($team['logo_path']) && file_exists('../assets/' . $team['logo_path'])): ?>
                                            <img src="../assets/<?= htmlspecialchars($team['logo_path']) ?>" alt="<?= htmlspecialchars($team['name']) ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <i class="fa-solid fa-shield text-2xl text-brand-orange"></i>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <h3 class="text-xl font-bold text-white font-display line-clamp-1"><?= htmlspecialchars($team['name']) ?></h3>
                                        <span class="text-xs text-gray-400">สโมสรทีมกลาง (Global Team)</span>
                                    </div>
                                </div>

                                <?php if ($team['is_captain']): ?>
                                    <span class="px-2.5 py-0.5 rounded-full bg-brand-orange/20 text-brand-orange border border-brand-orange/40 text-[10px] font-bold uppercase shrink-0">
                                        <i class="fa-solid fa-crown mr-1"></i> Captain
                                    </span>
                                <?php else: ?>
                                    <span class="px-2.5 py-0.5 rounded-full bg-white/10 text-gray-300 text-[10px] font-bold uppercase shrink-0">Member</span>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center justify-between gap-2 pt-2">
                                <button onclick="viewTeamRoster(<?= $team['team_id'] ?>, '<?= htmlspecialchars(addslashes($team['name'])) ?>')" 
                                        class="flex-1 py-2.5 px-3 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold text-gray-200 border border-white/15 transition-all flex items-center justify-center gap-1.5 cursor-pointer">
                                    <i class="fa-solid fa-users text-brand-orange"></i>
                                    <span>คลิกดูรายชื่อทีม</span>
                                </button>

                                <?php if ($team['is_captain']): ?>
                                    <button onclick="openTeamModal(<?= $team['team_id'] ?>, '<?= htmlspecialchars(addslashes($team['name'])) ?>')" 
                                            class="py-2.5 px-3 rounded-xl bg-brand-orange/20 hover:bg-brand-orange text-xs font-bold text-brand-orange hover:text-white border border-brand-orange/40 transition-all flex items-center gap-1 cursor-pointer">
                                        <i class="fa-solid fa-gear"></i>
                                        <span>จัดการทีม</span>
                                    </button>
                                    <form method="POST" class="inline" onsubmit="return confirm('คุณแน่ใจหรือไม่ที่จะยุบทีมนี้?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_team">
                                        <input type="hidden" name="team_id" value="<?= (int) $team['team_id'] ?>">
                                        <button type="submit" class="py-2.5 px-3 rounded-xl bg-rose-500/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 text-xs font-bold transition-all">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

        </main>

        <div id="editProfileModal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-md hidden flex items-center justify-center p-4">
            <div class="glass-panel max-w-lg w-full rounded-3xl p-6 border border-white/20 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-white/15 pb-3">
                    <h3 class="text-lg font-bold font-display text-white uppercase flex items-center gap-2">
                        <i class="fa-solid fa-user-pen text-brand-orange"></i> แก้ไขโปรไฟล์ & รูปส่วนตัว
                    </h3>
                    <button onclick="toggleModal('editProfileModal')" class="text-gray-400 hover:text-white text-lg cursor-pointer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div>
                        <label class="block text-xs font-bold text-gray-300 mb-1">รูปโปรไฟล์ส่วนตัว (Avatar):</label>
                        <input type="file" name="avatar" accept="image/*"
                               class="w-full text-xs text-gray-300 bg-black/50 border border-white/20 rounded-xl p-2 focus:outline-none focus:border-brand-orange">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-300 mb-1">ชื่อแสดงผลในเกม (Display Name):</label>
                        <input type="text" name="display_name" value="<?= htmlspecialchars($displayName) ?>" required
                               class="w-full bg-black/50 border border-white/20 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-brand-orange">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-300 mb-1">ผลงาน / ประวัติการแข่งขัน (Bio):</label>
                        <textarea name="bio" rows="4" placeholder="ระบุประวัติการแข่ง ประสบการณ์..."
                                  class="w-full bg-black/50 border border-white/20 rounded-xl p-4 text-sm text-white focus:outline-none focus:border-brand-orange"><?= htmlspecialchars($bio) ?></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" onclick="toggleModal('editProfileModal')" class="px-4 py-2 rounded-xl bg-white/10 text-xs font-bold text-gray-300 hover:bg-white/20">ยกเลิก</button>
                        <button type="submit" class="px-6 py-2 rounded-xl bg-brand-orange hover:bg-brand-glow text-xs font-bold text-white shadow-md">บันทึกข้อมูล</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="manageTeamModal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-md hidden flex items-center justify-center p-4">
            <div class="glass-panel max-w-xl w-full rounded-3xl p-6 border border-white/20 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-white/15 pb-3">
                    <h3 class="text-lg font-bold font-display text-white uppercase flex items-center gap-2">
                        <i class="fa-solid fa-users-gear text-brand-orange"></i> จัดการทีม <span id="modalTeamName" class="text-brand-orange"></span>
                    </h3>
                    <button onclick="toggleModal('manageTeamModal')" class="text-gray-400 hover:text-white text-lg cursor-pointer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4 pt-2">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="manage_team">
                    <input type="hidden" name="team_id" id="modalTeamId" value="">

                    <div class="bg-black/40 p-4 rounded-2xl border border-white/10 space-y-3">
                        <label class="block text-xs font-bold text-brand-orange uppercase">
                            <i class="fa-solid fa-pen"></i> แก้ไขชื่อทีม & เปลี่ยนรูปโลโก้:
                        </label>
                        <input type="text" name="team_name" placeholder="ชื่อทีมใหม่..."
                               class="w-full text-xs text-white bg-black/50 border border-white/20 rounded-xl p-2.5 focus:outline-none focus:border-brand-orange">
                        <input type="file" name="team_logo" accept="image/*"
                               class="w-full text-xs text-gray-300 bg-black/50 border border-white/20 rounded-xl p-2 focus:outline-none focus:border-brand-orange">
                    </div>

                    <div class="space-y-2 relative">
                        <label class="block text-xs font-bold text-gray-300 uppercase">🔍 ค้นหาและเลือกผู้เล่นเข้าทีม (เลือกได้หลายคน):</label>
                        
                        <div class="relative">
                            <input type="text" id="liveSearchInput" oninput="onSearchInput()" placeholder="พิมพ์ชื่อ Display Name เพื่อค้นหาผู้เล่น..." autocomplete="off"
                                   class="w-full bg-black/50 border border-white/20 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-brand-orange pr-10">
                            <i class="fa-solid fa-magnifying-glass absolute right-3.5 top-3 text-xs text-gray-400"></i>
                        </div>

                        <div id="searchResultsList" class="hidden absolute left-0 right-0 top-full mt-1 bg-slate-900 border border-white/20 rounded-2xl max-h-48 overflow-y-auto z-50 shadow-2xl divide-y divide-white/10"></div>

                        <div id="selectedPlayersContainer" class="flex flex-wrap gap-2 pt-1 min-h-[36px]"></div>

                        <input type="text" name="in_game_role" placeholder="กำหนดตำแหน่งในเกม (เช่น Carry, Support)" 
                               class="w-full bg-black/50 border border-white/20 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-brand-orange mt-2">
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2 border-t border-white/10">
                        <button type="button" onclick="toggleModal('manageTeamModal')" class="px-4 py-2 rounded-xl bg-white/10 text-xs font-bold text-gray-300 hover:bg-white/20">ยกเลิก</button>
                        <button type="submit" class="px-6 py-2 rounded-xl bg-brand-orange hover:bg-brand-glow text-xs font-bold text-white shadow-md">
                            บันทึก & ส่งคำเชิญทั้งหมด
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div id="rosterModal" class="fixed inset-0 z-50 bg-black/80 backdrop-blur-md hidden flex items-center justify-center p-4">
            <div class="glass-panel max-w-lg w-full rounded-3xl p-6 border border-white/20 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-white/15 pb-3">
                    <h3 class="text-lg font-bold font-display text-white uppercase flex items-center gap-2">
                        <i class="fa-solid fa-users text-brand-orange"></i> รายชื่อสมาชิกทีม <span id="rosterTeamTitle" class="text-brand-orange"></span>
                    </h3>
                    <button onclick="toggleModal('rosterModal')" class="text-gray-400 hover:text-white text-lg cursor-pointer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <div id="rosterListContainer" class="space-y-3 max-h-80 overflow-y-auto pr-1"></div>

                <div class="text-right pt-2 border-t border-white/10">
                    <button onclick="toggleModal('rosterModal')" class="px-5 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold text-white">ปิดหน้าต่าง</button>
                </div>
            </div>
        </div>

        <footer class="border-t border-white/15 bg-slate-950/80 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
            <div class="max-w-7xl mx-auto px-4 text-center">
                <p class="text-gray-300 font-semibold">© <?= date('Y') ?> KORAT ESPORT. All rights reserved.</p>
            </div>
        </footer>

    </div>

    <script>
        const teamRostersData = {
            <?php foreach ($myTeams as $t): ?>
                <?php
                    $mStmt = $pdo->prepare("
                        SELECT p.player_id, p.display_name, tm.in_game_role
                        FROM team_members tm
                        JOIN players p ON p.player_id = tm.player_id
                        WHERE tm.team_id = :tid AND tm.is_active = 1
                    ");
                    $mStmt->execute(['tid' => $t['team_id']]);
                    $mList = $mStmt->fetchAll();
                ?>
                "<?= $t['team_id'] ?>": <?= json_encode($mList) ?>,
            <?php endforeach; ?>
        };

        const allPlayersData = <?= json_encode($allPlayers) ?>;
        let selectedPlayersMap = new Map();

        function toggleModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) { modal.classList.toggle('hidden'); }
        }

        function openTeamModal(teamId, teamName) {
            document.getElementById('modalTeamId').value = teamId;
            document.getElementById('modalTeamName').innerText = '"' + teamName + '"';
            selectedPlayersMap.clear();
            renderSelectedPlayersBadges();
            toggleModal('manageTeamModal');
        }

        function viewTeamRoster(teamId, teamName) {
            document.getElementById('rosterTeamTitle').innerText = '"' + teamName + '"';
            const container = document.getElementById('rosterListContainer');
            container.innerHTML = '';

            const members = teamRostersData[teamId] || [];

            if (members.length === 0) {
                container.innerHTML = '<p class="text-xs text-gray-400 text-center py-4">ยังไม่มีสมาชิกที่ยืนยันการเข้าร่วมในทีมนี้</p>';
            } else {
                members.forEach(m => {
                    const item = document.createElement('div');
                    item.className = 'bg-black/40 p-3 rounded-xl border border-white/10 flex items-center justify-between';
                    
                    let roleTag = m.in_game_role ? `<span class="text-[10px] text-brand-orange bg-brand-orange/10 px-2 py-0.5 rounded border border-brand-orange/20 ml-2">${escapeHtml(m.in_game_role)}</span>` : '';
                    
                    item.innerHTML = `
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-brand-orange font-bold text-xs">
                                <i class="fa-solid fa-user"></i>
                            </div>
                            <div>
                                <span class="text-xs font-bold text-white">${escapeHtml(m.display_name)}</span>
                                ${roleTag}
                            </div>
                        </div>
                    `;
                    container.appendChild(item);
                });
            }

            toggleModal('rosterModal');
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        function onSearchInput() {
            const query = document.getElementById('liveSearchInput').value.trim().toLowerCase();
            const resultsBox = document.getElementById('searchResultsList');
            resultsBox.innerHTML = '';

            if (query.length === 0) {
                resultsBox.classList.add('hidden');
                return;
            }

            const filtered = allPlayersData.filter(p => 
                p.display_name.toLowerCase().includes(query) && !selectedPlayersMap.has(p.player_id.toString())
            );

            if (filtered.length === 0) {
                resultsBox.innerHTML = '<div class="p-3 text-xs text-gray-400 text-center">ไม่พบรายชื่อผู้เล่น หรือถูกเลือกไปแล้ว</div>';
            } else {
                filtered.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'p-3 hover:bg-brand-orange/20 cursor-pointer text-xs font-bold text-white transition-colors flex items-center justify-between';
                    div.innerHTML = `<span><i class="fa-solid fa-user text-brand-orange mr-2"></i>${escapeHtml(p.display_name)}</span> <span class="text-[10px] text-brand-orange font-bold">+ เพิ่ม</span>`;
                    div.onclick = function() { addPlayerToSelection(p.player_id, p.display_name); };
                    resultsBox.appendChild(div);
                });
            }

            resultsBox.classList.remove('hidden');
        }

        function addPlayerToSelection(id, name) {
            selectedPlayersMap.set(id.toString(), name);
            renderSelectedPlayersBadges();
            document.getElementById('liveSearchInput').value = '';
            document.getElementById('searchResultsList').classList.add('hidden');
        }

        function removeSelectedPlayer(id) {
            selectedPlayersMap.delete(id.toString());
            renderSelectedPlayersBadges();
        }

        function renderSelectedPlayersBadges() {
            const container = document.getElementById('selectedPlayersContainer');
            container.innerHTML = '';

            if (selectedPlayersMap.size === 0) {
                container.innerHTML = '<span class="text-[11px] text-gray-500 italic">ยังไม่ได้เลือกผู้เล่น (สามารถค้นหาและเลือกได้หลายคน)</span>';
                return;
            }

            selectedPlayersMap.forEach((name, id) => {
                const badge = document.createElement('div');
                badge.className = 'bg-brand-orange/20 border border-brand-orange/40 px-3 py-1.5 rounded-xl flex items-center gap-2 text-xs text-white';
                badge.innerHTML = `
                    <span class="font-bold"><i class="fa-solid fa-user-check text-brand-orange mr-1"></i>${escapeHtml(name)}</span>
                    <button type="button" onclick="removeSelectedPlayer('${id}')" class="text-gray-400 hover:text-rose-400 ml-1">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <input type="hidden" name="add_player_ids[]" value="${id}">
                `;
                container.appendChild(badge);
            });
        }

        // Particles Canvas Engine
        document.addEventListener('DOMContentLoaded', () => {
            const canvas = document.getElementById('particles-canvas');
            const ctx = canvas.getContext('2d');
            
            let widthWin = canvas.width = window.innerWidth;
            let heightWin = canvas.height = window.innerHeight;

            window.addEventListener('resize', () => {
                widthWin = canvas.width = window.innerWidth;
                heightWin = canvas.height = window.innerHeight;
            });

            class Particle {
                constructor() {
                    this.reset();
                }

                reset() {
                    this.x = Math.random() * widthWin;
                    this.y = heightWin + Math.random() * 100;
                    this.size = Math.random() * 2 + 0.5;
                    this.speedY = Math.random() * 0.5 + 0.1;
                    this.speedX = (Math.random() - 0.5) * 0.2;
                    this.opacity = Math.random() * 0.4 + 0.1;
                }

                update() {
                    this.y -= this.speedY;
                    this.x += this.speedX;
                    if (this.y < -10) this.reset();
                }

                draw() {
                    ctx.fillStyle = `rgba(255, 85, 0, ${this.opacity})`;
                    ctx.beginPath();
                    ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                    ctx.fill();
                }
            }

            const particles = Array.from({ length: 35 }, () => new Particle());

            function animateParticles() {
                ctx.clearRect(0, 0, widthWin, heightWin);
                particles.forEach(p => {
                    p.update();
                    p.draw();
                });
                requestAnimationFrame(animateParticles);
            }
            animateParticles();
        });
    </script>
</body>
</html>
