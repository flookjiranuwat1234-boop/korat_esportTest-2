<?php
// pages/player-profile.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
ensureTournamentCategorySchema($pdo);

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$playerId = (int) ($_GET['id'] ?? 0);
$error = '';
$success = '';

$pStmt = $pdo->prepare("SELECT * FROM players WHERE player_id = :id");
$pStmt->execute(['id' => $playerId]);
$player = $pStmt->fetch();

if (!$player) {
    die('
    <div style="min-height:100vh; background-color:#0F1117; color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif; padding:2rem; text-align:center;">
        <h1 style="font-size:2rem; color:#FF5500; font-weight:bold;">ไม่พบโปรไฟล์นักกีฬานี้</h1>
        <p style="color:#aaa; margin-top:0.5rem;">ข้อมูลผู้เล่นอาจถูกลบหรือระบุรหัสไม่ถูกต้อง</p>
        <a href="index.php" style="margin-top:2rem; padding:0.8rem 1.5rem; background-color:#FF5500; color:#fff; text-decoration:none; border-radius:12px; font-weight:bold;">&larr; กลับไปหน้าแรก</a>
    </div>
    ');
}

// เจ้าของโปรไฟล์เท่านั้นที่แก้ไขได้
$isOwner = $isLoggedIn && isset($_SESSION['user_id']) && $player['user_id'] == $_SESSION['user_id'];

// บันทึกการแก้ไขโปรไฟล์
if ($isOwner && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $displayName = trim($_POST['display_name']);
        $realName = trim($_POST['real_name']);
        $bio = trim($_POST['bio']);
        $showRealName = isset($_POST['show_real_name']) ? 1 : 0;

        if ($displayName == '') {
            $error = 'ชื่อในเกมห้ามว่าง';
        } else {
            try {
                $avatarPath = null;
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $avatarPath = handleImageUpload($_FILES['avatar'], 'avatars');
                }

                if ($avatarPath) {
                    deleteUploadedImage($player['avatar_path']);
                    $update = $pdo->prepare("
                        UPDATE players
                        SET display_name = :display_name, real_name = :real_name,
                            bio = :bio, show_real_name = :show_real_name, avatar_path = :avatar_path
                        WHERE player_id = :id
                    ");
                    $update->execute([
                        'display_name' => $displayName,
                        'real_name' => $realName ?: null,
                        'bio' => $bio,
                        'show_real_name' => $showRealName,
                        'avatar_path' => $avatarPath,
                        'id' => $playerId,
                    ]);
                } else {
                    $update = $pdo->prepare("
                        UPDATE players
                        SET display_name = :display_name, real_name = :real_name,
                            bio = :bio, show_real_name = :show_real_name
                        WHERE player_id = :id
                    ");
                    $update->execute([
                        'display_name' => $displayName,
                        'real_name' => $realName ?: null,
                        'bio' => $bio,
                        'show_real_name' => $showRealName,
                        'id' => $playerId,
                    ]);
                }

                $success = 'บันทึกโปรไฟล์เรียบร้อยแล้ว';

                $pStmt->execute(['id' => $playerId]);
                $player = $pStmt->fetch();
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// ทีมที่ผู้เล่นนี้สังกัดอยู่ (ทุกเกม) ผ่านตาราง team_members และ teams
$teams = $pdo->prepare("
    SELECT t.team_id, t.name, g.name AS game_name, t.team_category
    FROM team_members tm
    JOIN teams t ON t.team_id = tm.team_id
    LEFT JOIN games g ON g.game_id = t.game_id
    WHERE tm.player_id = :player_id AND tm.is_active = 1
");
$teams->execute(['player_id' => $playerId]);
$teams = $teams->fetchAll();

// Match history follows the Tournament Roster snapshot, not current team membership.
$mStmt = $pdo->prepare("SELECT DISTINCT m.*, COALESCE(t1.name, u1.username, 'รอผู้ชนะรอบก่อน') AS t1_name,
        COALESCE(t2.name, u2.username, 'รอผู้ชนะรอบก่อน') AS t2_name,
        tour.name AS tour_name, tr.category AS tournament_category
    FROM tournament_registration_members trm
    JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
    JOIN matches m ON m.tournament_id = tr.tournament_id
        AND (m.team1_id = tr.team_id OR m.team2_id = tr.team_id OR m.team1_id = tr.player_id OR m.team2_id = tr.player_id)
    JOIN tournaments tour ON tour.tournament_id = m.tournament_id
    LEFT JOIN teams t1 ON t1.team_id = m.team1_id
    LEFT JOIN players p1 ON p1.player_id = m.team1_id
    LEFT JOIN users u1 ON u1.user_id = p1.user_id
    LEFT JOIN teams t2 ON t2.team_id = m.team2_id
    LEFT JOIN players p2 ON p2.player_id = m.team2_id
    LEFT JOIN users u2 ON u2.user_id = p2.user_id
    WHERE trm.player_id = :player_id AND trm.roster_status = 'active' AND tr.status = 'approved'
    ORDER BY m.scheduled_at IS NULL, m.scheduled_at, m.match_id");
$mStmt->execute(['player_id' => $playerId]);
$myMatches = $mStmt->fetchAll();

// อันดับคะแนนของผู้เล่นนี้ (ทุกเกมที่เคยเล่น)
$rankings = $pdo->prepare("
    SELECT pr.*, g.name AS game_name
    FROM player_rankings pr
    JOIN games g ON g.game_id = pr.game_id
    WHERE pr.player_id = :player_id
");
$rankings->execute(['player_id' => $playerId]);
$rankings = $rankings->fetchAll();

$tournamentHistoryStmt = $pdo->prepare('SELECT tr.tournament_registration_id, tr.tournament_id, tr.category,
        tr.status, tr.participation_status, tour.name AS tournament_name, g.name AS game_name,
        COALESCE(t.name, \'การแข่งขันเดี่ยว\') AS registered_team,
        trm.checkin_status AS own_checkin_status,
        (SELECT COUNT(*) FROM tournament_registration_members req WHERE req.tournament_registration_id = tr.tournament_registration_id AND req.is_required_for_checkin = 1) AS required_count,
        (SELECT COUNT(*) FROM tournament_registration_members req WHERE req.tournament_registration_id = tr.tournament_registration_id AND req.is_required_for_checkin = 1 AND req.checkin_status IN (\'checked_in\', \'waived\')) AS checked_count
    FROM tournament_registration_members trm
    JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
    JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
    JOIN games g ON g.game_id = tour.game_id
    LEFT JOIN teams t ON t.team_id = tr.team_id
    WHERE trm.player_id = :player_id AND trm.roster_status = \'active\'
    ORDER BY tour.start_date DESC, tr.tournament_registration_id DESC');
$tournamentHistoryStmt->execute(['player_id' => $playerId]);
$tournamentHistory = $tournamentHistoryStmt->fetchAll();

// ตรวจสอบว่าผู้เล่นคนนี้ติด Top Player (ติดอันดับ Top 3 ใน ranking ใดเกมหนึ่ง)
$isTopPlayer = false;
foreach ($rankings as $rk) {
    if (isset($rk['points']) && $rk['points'] > 0) {
        $chkTop = $pdo->prepare("SELECT COUNT(*) FROM player_rankings WHERE game_id = :gid AND points > :pts");
        $chkTop->execute(['gid' => $rk['game_id'], 'pts' => $rk['points']]);
        if ($chkTop->fetchColumn() < 3) {
            $isTopPlayer = true;
            break;
        }
    }
}

$csrfToken = $isOwner ? generateCsrfToken() : '';
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($player['display_name']); ?> - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />

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
                        'orange-glow': '0 0 25px rgba(255, 85, 0, 0.45)',
                        'gold-glow': '0 0 25px rgba(245, 158, 11, 0.6)'
                    }
                }
            }
        }
    </script>

    <style>
        ::-webkit-scrollbar {
            display: none;
        }
        html,
        body {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        body {
            background-color: #0F1117;
            color: #f3f4f6;
        }
        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.45), rgba(15, 17, 23, 0.85)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .glass-nav {
            background: rgba(15, 17, 23, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }
        .glass-panel {
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .glass-card:hover {
            transform: translateY(-6px);
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 85, 0, 0.6);
            box-shadow: 0 15px 35px -5px rgba(255, 85, 0, 0.35);
        }
        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }
        #particles-canvas {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: 1;
        }
        @keyframes avatarPulseGlow {
            0%, 100% { box-shadow: 0 0 15px rgba(255, 85, 0, 0.4); border-color: #FF5500; }
            50% { box-shadow: 0 0 35px rgba(255, 85, 0, 0.8); border-color: #ff8844; }
        }
        .avatar-pulse-glow {
            animation: avatarPulseGlow 3s infinite;
        }
        @keyframes shimmerAnim {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .shimmer-badge {
            background: linear-gradient(90deg, rgba(245, 158, 11, 0.2) 0%, rgba(255, 255, 255, 0.4) 50%, rgba(245, 158, 11, 0.2) 100%);
            background-size: 200% 100%;
            animation: shimmerAnim 3s infinite linear;
            border: 1px solid rgba(245, 158, 11, 0.6);
        }
        @keyframes rowFadeIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .stat-row {
            animation: rowFadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0; transition: all 0.3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-animate {
            animation: slideDown 0.4s ease forwards;
        }
    </style>
</head>

<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>
    <canvas id="particles-canvas"></canvas>

    <div class="relative z-10 flex flex-col min-h-screen">

        <header class="sticky top-0 z-50 glass-nav transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <a href="index.php" class="flex items-center gap-3 group">
                        <img src="../assets/img/logo.png" alt="Korat Esport"
                            class="h-11 w-auto filter drop-shadow group-hover:scale-105 transition-transform"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors">KORAT <span class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] tracking-widest text-gray-200 font-bold uppercase -mt-1">Official Arena & Hub</span>
                        </div>
                    </a>

                    <nav class="hidden md:flex items-center gap-1 lg:gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">หน้าแรก</a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ทัวร์นาเมนต์</a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ตารางคะแนน</a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ข่าวสาร</a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">แกลเลอรี่</a>
                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ที่พักแนะนำ</a>
                        <?php endif; ?>
                    </nav>

                    <div class="flex items-center gap-4 text-base font-bold">
                        <?php if ($isLoggedIn): ?>
                            <div class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md">
                                <div class="flex flex-col text-right">
                                    <span class="text-sm font-bold text-white leading-tight"><?= htmlspecialchars($currentUser['username'] ?? 'User') ?></span>
                                    <span class="text-[10px] font-semibold text-brand-orange uppercase tracking-wider"><?= htmlspecialchars($currentUser['role'] ?? 'Player') ?></span>
                                </div>
                                <a href="profile.php" title="จัดการโปรไฟล์" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                    <i class="fa-solid fa-user-gear text-sm"></i>
                                </a>
                                <a href="../auth/logout.php" title="ออกจากระบบ" class="w-9 h-9 rounded-xl bg-rose-500/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 flex items-center justify-center transition-all">
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

        <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full space-y-12">

            <div class="glass-panel p-6 sm:p-10 rounded-3xl border border-white/20 shadow-2xl flex flex-col md:flex-row items-center gap-8"
                data-aos="zoom-in" data-aos-duration="800">
                <?php
                $avatarSrc = 'https://placehold.co/150x150/121318/FF5500?text=Avatar';
                if (!empty($player['avatar_path'])) {
                    $path = trim($player['avatar_path']);
                    if (strpos($path, 'http') === 0) {
                        $avatarSrc = $path;
                    } else {
                        $cleanPath = ltrim($path, '/');
                        $avatarSrc = (strpos($cleanPath, 'assets/') === 0) ? '../' . $cleanPath : '../assets/' . $cleanPath;
                    }
                }
                ?>
                <img id="profile-avatar-preview" src="<?= htmlspecialchars($avatarSrc) ?>"
                    alt="<?php echo htmlspecialchars($player['display_name']); ?>"
                    class="w-32 h-32 sm:w-40 sm:h-40 rounded-2xl object-cover border-2 border-brand-orange avatar-pulse-glow shrink-0 bg-black/40 p-1"
                    onError="this.src='https://placehold.co/150x150/121318/FF5500?text=Avatar';">

                <div class="space-y-3 text-center md:text-left flex-1">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-orange/20 border border-brand-orange/40 text-brand-orange text-xs font-bold uppercase tracking-widest">
                        <i class="fa-solid fa-gamepad"></i> Athlete Profile
                    </div>

                    <div class="flex flex-col md:flex-row md:items-center gap-3">
                        <h1 class="text-3xl sm:text-4xl font-black font-display text-white uppercase tracking-wide">
                            <?php echo htmlspecialchars($player['display_name']); ?>
                        </h1>
                        <?php if ($isTopPlayer): ?>
                            <span class="px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider shimmer-badge text-amber-300 inline-flex items-center gap-1.5 w-fit mx-auto md:mx-0 shadow-gold-glow">
                                <i class="fa-solid fa-crown"></i> Top Player
                            </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($player['show_real_name'] && $player['real_name']): ?>
                        <p class="text-sm text-gray-300 font-medium">ชื่อจริง: <?php echo htmlspecialchars($player['real_name']); ?></p>
                    <?php endif; ?>

                    <?php if ($player['bio']): ?>
                        <p class="text-xs sm:text-sm text-gray-300 leading-relaxed pt-2 font-normal">
                            <?php echo nl2br(htmlspecialchars($player['bio'])); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider flex items-center gap-2 border-b border-white/15 pb-3" data-aos="fade-right">
                    <i class="fa-solid fa-trophy text-brand-orange"></i> เส้นทางการแข่งขันของนักกีฬา
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php if (empty($tournamentHistory)): ?>
                        <div class="glass-panel p-8 text-center text-gray-400 rounded-2xl md:col-span-2">ยังไม่มีประวัติการสมัคร Tournament</div>
                    <?php endif; ?>
                    <?php foreach ($tournamentHistory as $history): ?>
                        <?php $teamCheckinComplete = (int) $history['required_count'] > 0 && (int) $history['checked_count'] >= (int) $history['required_count']; ?>
                        <div class="glass-panel p-5 rounded-2xl border border-white/15 space-y-2">
                            <div class="flex items-start justify-between gap-2"><h3 class="font-bold text-white"><?php echo htmlspecialchars($history['tournament_name']); ?></h3><span class="text-[10px] text-brand-orange font-bold uppercase"><?php echo htmlspecialchars($history['category'] ?: 'open'); ?></span></div>
                            <p class="text-xs text-gray-400"><?php echo htmlspecialchars($history['game_name']); ?> | ทีมที่ใช้สมัคร: <?php echo htmlspecialchars($history['registered_team']); ?></p>
                            <p class="text-xs text-gray-300">Check-in ของฉัน: <b class="<?php echo in_array($history['own_checkin_status'], ['checked_in', 'waived'], true) ? 'text-emerald-400' : 'text-rose-300'; ?>"><?php echo in_array($history['own_checkin_status'], ['checked_in', 'waived'], true) ? 'เรียบร้อย' : 'ยังไม่ครบ'; ?></b></p>
                            <p class="text-xs text-gray-300">สถานะทีม: <b class="<?php echo $teamCheckinComplete ? 'text-emerald-400' : 'text-amber-300'; ?>"><?php echo (int) $history['checked_count']; ?>/<?php echo (int) $history['required_count']; ?> <?php echo $teamCheckinComplete ? 'Check-in ครบ' : 'Check-in ไม่ครบ'; ?></b></p>
                            <p class="text-xs text-gray-400">สถานะการแข่งขัน: <?php echo htmlspecialchars($history['participation_status'] ?: $history['status']); ?></p>
                            <a href="tournament-detail.php?id=<?php echo (int) $history['tournament_id']; ?>&category=<?php echo urlencode($history['category'] ?: 'open'); ?>" class="text-[11px] text-brand-orange hover:underline font-semibold">ดูตารางและเส้นทางการแข่งขัน <i class="fa-solid fa-arrow-right ml-1"></i></a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider flex items-center gap-2 border-b border-white/15 pb-3"
                    data-aos="fade-right">
                    <i class="fa-solid fa-shield-halved text-brand-orange"></i> ทีมที่สังกัด
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php if (count($teams) == 0): ?>
                        <div class="col-span-full glass-panel p-8 text-center text-gray-400 rounded-2xl" data-aos="fade-up">
                            ยังไม่ได้สังกัดทีมใดในขณะนี้
                        </div>
                    <?php endif; ?>
                    <?php foreach ($teams as $tIndex => $t): ?>
                        <a href="team-profile.php?id=<?php echo $t['team_id']; ?>"
                            class="glass-card p-5 rounded-2xl flex flex-col justify-between space-y-3 group shadow-lg"
                            data-aos="fade-up" data-aos-delay="<?php echo $tIndex * 80; ?>">
                            <h3 class="font-bold text-white text-base font-display group-hover:text-brand-orange transition-colors">
                                <?php echo htmlspecialchars($t['name']); ?>
                            </h3>
                            <span class="text-xs text-gray-400 flex items-center gap-1 font-semibold">
                                <i class="fa-solid fa-gamepad text-brand-orange"></i>
                                <?php echo htmlspecialchars($t['game_name'] ?? 'ไม่ระบุเกม'); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider flex items-center gap-2 border-b border-white/15 pb-3"
                    data-aos="fade-right">
                    <i class="fa-solid fa-calendar-days text-brand-orange"></i> ตารางการแข่งขันของคุณ (แมตช์ที่ต้องพบกับคู่ต่อสู้)
                </h2>

                <div class="space-y-3" data-aos="fade-up">
                    <?php if (empty($myMatches)): ?>
                        <div class="glass-panel p-8 text-center text-gray-400 rounded-2xl">
                            ยังไม่มีตารางการแข่งขันหรือคู่ต่อสู้ในขณะนี้
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($myMatches as $m): ?>
                                <div class="glass-panel p-5 rounded-2xl border border-white/15 space-y-3 shadow-lg hover:border-brand-orange/50 transition-all">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="px-2.5 py-1 rounded-lg bg-brand-orange/20 text-brand-orange font-bold uppercase">
                                            <?php echo htmlspecialchars($m['tour_name']); ?>
                                        </span>
                                        <span class="text-gray-400"><i class="fa-regular fa-clock mr-1"></i> <?php echo !empty($m['scheduled_at']) ? htmlspecialchars($m['scheduled_at']) : 'รอกำหนดเวลา'; ?></span>
                                    </div>
                                    <div class="flex items-center justify-between py-2 px-3 rounded-xl bg-black/40 border border-white/10 text-sm font-bold text-white">
                                        <span class="truncate max-w-[40%] text-left"><?php echo htmlspecialchars($m['t1_name']); ?></span>
                                        <span class="text-brand-orange font-display text-xs px-2 py-0.5 rounded bg-brand-orange/10">VS</span>
                                        <span class="truncate max-w-[40%] text-right"><?php echo htmlspecialchars($m['t2_name']); ?></span>
                                    </div>
                                    <div class="flex items-center justify-between text-xs text-gray-400">
                                        <span>Category: <?php echo htmlspecialchars($m['tournament_category'] ?: 'open'); ?></span>
                                        <?php if (in_array($m['status'], ['completed', 'walkover'], true)): ?>
                                            <span class="font-bold text-emerald-300"><?php echo $m['status'] === 'walkover' ? 'WO' : ((int) $m['team1_score'] . ' - ' . (int) $m['team2_score']); ?></span>
                                        <?php else: ?>
                                            <span class="text-amber-300">รอแข่งขัน</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-right">
                                        <a href="tournament-detail.php?id=<?php echo $m['tournament_id']; ?>" class="text-[11px] text-brand-orange hover:underline font-semibold">
                                            ดูสายการแข่งขันเต็ม <i class="fa-solid fa-arrow-right ml-1"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider flex items-center gap-2 border-b border-white/15 pb-3"
                    data-aos="fade-right">
                    <i class="fa-solid fa-chart-line text-amber-400"></i> สถิติการแข่งขัน
                </h2>

                <div class="glass-panel rounded-2xl overflow-hidden shadow-xl border border-white/15">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-gray-200">
                            <thead class="bg-black/40 text-xs uppercase font-bold text-gray-300 border-b border-white/15 font-display">
                                <tr>
                                    <th class="p-4">เกม</th>
                                    <th class="p-4 text-center">คะแนน</th>
                                    <th class="p-4 text-center">แข่งแล้ว</th>
                                    <th class="p-4 text-center">ชนะ</th>
                                    <th class="p-4 text-center">แพ้</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10 font-medium">
                                <?php if (count($rankings) == 0): ?>
                                    <tr>
                                        <td colspan="5" class="p-8 text-center text-gray-400">ยังไม่มีสถิติการแข่งขันในระบบ</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($rankings as $rIndex => $r):
                                    $rowDelay = min($rIndex * 50, 600);
                                    ?>
                                    <tr class="stat-row transition-colors hover:bg-white/10"
                                        style="animation-delay: <?php echo $rowDelay; ?>ms;">
                                        <td class="p-4 font-bold text-white"><?php echo htmlspecialchars($r['game_name']); ?></td>
                                        <td class="p-4 text-center font-display font-black text-brand-orange stat-counter"
                                            data-target="<?php echo $r['points'] ?? 0; ?>">0</td>
                                        <td class="p-4 text-center font-mono"><?php echo $r['matches_played'] ?? 0; ?></td>
                                        <td class="p-4 text-center font-mono text-emerald-400 font-bold"><?php echo $r['wins'] ?? 0; ?>W</td>
                                        <td class="p-4 text-center font-mono text-rose-400 font-bold"><?php echo $r['losses'] ?? 0; ?>L</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php if ($isOwner): ?>
                <div class="glass-panel p-6 sm:p-10 rounded-3xl border border-white/20 shadow-2xl space-y-6"
                    data-aos="fade-up" data-aos-duration="1000">
                    <div class="border-b border-white/15 pb-4">
                        <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-user-gear text-brand-orange"></i> แก้ไขโปรไฟล์ส่วนตัว
                        </h2>
                        <p class="text-xs text-gray-400 mt-1">อัปเดตข้อมูลส่วนตัว รูปโปรไฟล์ และรายละเอียดของคุณ</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="p-4 rounded-xl bg-rose-500/20 border border-rose-500/40 text-rose-300 text-xs flex items-center gap-2 alert-animate">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div id="success-alert"
                            class="p-4 rounded-xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 text-xs flex items-center gap-2 alert-animate">
                            <i class="fa-solid fa-circle-check"></i> <span><?php echo htmlspecialchars($success); ?></span>
                        </div>
                    <?php endif; ?>

                    <form id="profile-form" method="POST" enctype="multipart/form-data" class="space-y-6"
                        onsubmit="handleFormSubmit(this)">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                        <div class="space-y-2">
                            <label class="block text-xs font-bold uppercase text-gray-300 tracking-wider">รูปโปรไฟล์ (ไม่บังคับ)</label>
                            <input type="file" name="avatar" id="avatar-input" accept="image/jpeg,image/png,image/webp"
                                class="w-full text-xs text-gray-300 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-orange file:text-white hover:file:bg-brand-glow file:cursor-pointer bg-black/40 border border-white/15 rounded-xl p-2"
                                onchange="previewAvatar(event)">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="block text-xs font-bold uppercase text-gray-300 tracking-wider">ชื่อในเกม (Display Name)</label>
                                <input type="text" name="display_name"
                                    value="<?php echo htmlspecialchars($player['display_name']); ?>" required
                                    class="w-full bg-black/40 border border-white/15 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-orange font-medium">
                            </div>

                            <div class="space-y-2">
                                <label class="block text-xs font-bold uppercase text-gray-300 tracking-wider">ชื่อ-สกุลจริง (ไม่บังคับ)</label>
                                <input type="text" name="real_name"
                                    value="<?php echo htmlspecialchars($player['real_name'] ?? ''); ?>"
                                    class="w-full bg-black/40 border border-white/15 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-orange font-medium">
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <input type="checkbox" name="show_real_name" id="show_real_name" <?php echo $player['show_real_name'] ? 'checked' : ''; ?>
                                class="w-4 h-4 rounded accent-brand-orange bg-black/40 border-white/20">
                            <label for="show_real_name" class="text-xs text-gray-300 font-semibold cursor-pointer">แสดงชื่อ-สกุลจริงบนโปรไฟล์สาธารณะ</label>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-xs font-bold uppercase text-gray-300 tracking-wider">เกี่ยวกับฉัน (Bio)</label>
                            <textarea name="bio" rows="4" placeholder="แนะนำตัวสั้นๆ หรือช่องทางการติดต่อ..."
                                class="w-full bg-black/40 border border-white/15 rounded-xl p-3 text-sm text-white focus:outline-none focus:border-brand-orange font-medium"><?php echo htmlspecialchars($player['bio'] ?? ''); ?></textarea>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button type="submit" id="submit-btn"
                                class="px-8 py-3.5 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs uppercase tracking-wider transition-all shadow-orange-glow cursor-pointer flex items-center gap-2">
                                <span id="btn-text">บันทึกโปรไฟล์</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        </main>

        <footer class="border-t border-white/15 bg-slate-950/80 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4 text-center md:text-left">
                <div>
                    <p class="text-gray-300 font-semibold">&copy; <?= date('Y') ?> KORAT ESPORT. All rights reserved.</p>
                    <p class="text-[11px] text-gray-400 mt-1">ศูนย์กลางข้อมูลข่าวสารและการแข่งขันอีสปอร์ตจังหวัดนครราชสีมา</p>
                </div>
                <div class="flex items-center gap-4 text-gray-300">
                    <a href="https://www.facebook.com/koratesport/" target="_blank" rel="noopener noreferrer" title="Facebook: Korat Esport" class="hover:text-brand-orange transition-colors"><i class="fa-brands fa-facebook text-lg"></i></a>
                    <a href="https://www.youtube.com/@koratesport" target="_blank" rel="noopener noreferrer" title="YouTube: Korat Esport" class="hover:text-brand-orange transition-colors"><i class="fa-brands fa-youtube text-lg"></i></a>
                </div>
            </div>
        </footer>

    </div>

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            AOS.init({ once: true, duration: 800, easing: 'ease-out-cubic' });

            const counters = document.querySelectorAll('.stat-counter');
            counters.forEach(counter => {
                const target = +counter.getAttribute('data-target');
                let count = 0;
                const increment = Math.max(1, Math.ceil(target / 25));

                const updateCount = () => {
                    count += increment;
                    if (count < target) {
                        counter.innerText = count.toLocaleString();
                        setTimeout(updateCount, 25);
                    } else {
                        counter.innerText = target.toLocaleString();
                    }
                };
                updateCount();
            });

            const successAlert = document.getElementById('success-alert');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                    successAlert.style.opacity = '0';
                    successAlert.style.transform = 'translateY(-10px)';
                    setTimeout(() => successAlert.remove(), 600);
                }, 4000);
            }

            const canvas = document.getElementById('particles-canvas');
            const ctx = canvas.getContext('2d');

            let widthWin = canvas.width = window.innerWidth;
            let heightWin = canvas.height = window.innerHeight;

            window.addEventListener('resize', () => {
                widthWin = canvas.width = window.innerWidth;
                heightWin = canvas.height = window.innerHeight;
            });

            class Particle {
                constructor() { this.reset(); }
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
                particles.forEach(p => { p.update(); p.draw(); });
                requestAnimationFrame(animateParticles);
            }
            animateParticles();
        });

        function previewAvatar(event) {
            const file = event.target.files[0];
            if (file) {
                const previewImg = document.getElementById('profile-avatar-preview');
                previewImg.src = URL.createObjectURL(file);
            }
        }

        function handleFormSubmit(form) {
            const btn = document.getElementById('submit-btn');
            const btnText = document.getElementById('btn-text');
            btn.disabled = true;
            btn.classList.add('opacity-75', 'cursor-not-allowed');
            btnText.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> กำลังบันทึก...';
        }
    </script>
</body>

</html>