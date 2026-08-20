<?php
// pages/tournament-detail.php
require_once '../config/db.php';
require_once '../includes/auth.php';

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$tournamentId = (int) ($_GET['id'] ?? 0);
$selectedCategory = $_GET['category'] ?? 'male'; // กำหนดค่าเริ่มต้นเป็น male กรณีไม่ได้เลือก

// ดึงข้อมูลทัวร์นาเมนต์
$tStmt = $pdo->prepare("
    SELECT t.*, g.name AS game_name, g.play_mode
    FROM tournaments t 
    JOIN games g ON g.game_id = t.game_id
    WHERE t.tournament_id = :id
");
$tStmt->execute(['id' => $tournamentId]);
$tournament = $tStmt->fetch();

if (!$tournament) {
    die('
    <div style="min-height:100vh; background-color:#0F1117; color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif; padding:2rem; text-align:center;">
        <h1 style="font-size:2rem; color:#FF5500; font-weight:bold;">ไม่พบทัวร์นาเมนต์นี้</h1>
        <p style="color:#aaa; margin-top:0.5rem;">รายการแข่งขันอาจถูกลบหรือระบุรหัสไม่ถูกต้อง</p>
        <a href="tournaments.php" style="margin-top:2rem; padding:0.8rem 1.5rem; background-color:#FF5500; color:#fff; text-decoration:none; border-radius:12px; font-weight:bold;">&larr; กลับไปหน้ารายการทัวร์นาเมนต์</a>
    </div>
    ');
}

$tournamentName = $tournament['name'] ?? ($tournament['title'] ?? ($tournament['tournament_name'] ?? 'ทัวร์นาเมนต์อีสปอร์ต'));
$isOpenGame = (stripos($tournament['game_name'], 'open') !== false || stripos($tournamentName, 'open') !== false);
$isUnder18 = (stripos($tournament['game_name'], 'ต่ำกว่า 18') !== false || stripos($tournamentName, 'ต่ำกว่า 18') !== false);

// หากเป็นทัวร์นาเมนต์รุ่น Open บังคับ category เป็น open เสมอ
if ($isOpenGame) {
    $selectedCategory = 'open';
} elseif ($selectedCategory === 'all') {
    $selectedCategory = 'male';
}

// ดึง matches ทั้งหมดของทัวร์นาเมนต์ พร้อมปรับกรองประเภทให้แม่นยำขึ้น
$sqlMatches = "
    SELECT m.*, 
           COALESCE(t1.name, u1.username, 'รอผู้ชนะรอบก่อน') AS team1_name, 
           COALESCE(t2.name, u2.username, 'รอผู้ชนะรอบก่อน') AS team2_name,
           tr1.category AS team1_cat,
           tr2.category AS team2_cat
    FROM matches m
    LEFT JOIN teams t1 ON t1.team_id = m.team1_id
    LEFT JOIN players p1 ON p1.player_id = m.team1_id
    LEFT JOIN users u1 ON u1.user_id = p1.user_id
    LEFT JOIN tournament_registrations tr1 ON tr1.tournament_id = m.tournament_id AND tr1.team_id = m.team1_id
    LEFT JOIN teams t2 ON t2.team_id = m.team2_id
    LEFT JOIN players p2 ON p2.player_id = m.team2_id
    LEFT JOIN users u2 ON u2.user_id = p2.user_id
    LEFT JOIN tournament_registrations tr2 ON tr2.tournament_id = m.tournament_id AND tr2.team_id = m.team2_id
    WHERE m.tournament_id = :tid AND m.group_id IS NULL
";

$paramsMatches = ['tid' => $tournamentId];

if ($tournament['play_mode'] !== 'solo' && !$isOpenGame) {
    $sqlMatches .= " AND (m.bracket_type LIKE :catSql OR tr1.category = :catExact OR tr2.category = :catExact)";
    $paramsMatches['catSql'] = '%' . $selectedCategory . '%';
    $paramsMatches['catExact'] = $selectedCategory;
}

$sqlMatches .= " ORDER BY m.round_number, m.match_index";

$mStmt = $pdo->prepare($sqlMatches);
$mStmt->execute($paramsMatches);
$matches = $mStmt->fetchAll();

// กรณีไม่พบแมตช์ ให้ดึงทั้งหมดแล้วคัดกรองด้วย PHP เพื่อป้องกันกรณี bracket_type ในฐานข้อมูลเก็บไม่ตรงรูปแบบ
if (empty($matches) && $tournament['play_mode'] !== 'solo' && !$isOpenGame) {
    $sqlMatchesFallback = "
        SELECT m.*, 
               COALESCE(t1.name, u1.username, 'รอผู้ชนะรอบก่อน') AS team1_name, 
               COALESCE(t2.name, u2.username, 'รอผู้ชนะรอบก่อน') AS team2_name,
               tr1.category AS team1_cat,
               tr2.category AS team2_cat
        FROM matches m
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN players p1 ON p1.player_id = m.team1_id
        LEFT JOIN users u1 ON u1.user_id = p1.user_id
        LEFT JOIN tournament_registrations tr1 ON tr1.tournament_id = m.tournament_id AND tr1.team_id = m.team1_id
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        LEFT JOIN players p2 ON p2.player_id = m.team2_id
        LEFT JOIN users u2 ON u2.user_id = p2.user_id
        LEFT JOIN tournament_registrations tr2 ON tr2.tournament_id = m.tournament_id AND tr2.team_id = m.team2_id
        WHERE m.tournament_id = :tid AND m.group_id IS NULL
        ORDER BY m.round_number, m.match_index
    ";
    $mStmtFallback = $pdo->prepare($sqlMatchesFallback);
    $mStmtFallback->execute(['tid' => $tournamentId]);
    $allMatches = $mStmtFallback->fetchAll();
    
    $matches = array_filter($allMatches, function($m) use ($selectedCategory) {
        $bt = strtolower($m['bracket_type'] ?? '');
        $c1 = strtolower($m['team1_cat'] ?? '');
        $c2 = strtolower($m['team2_cat'] ?? '');
        if (empty($bt) && empty($c1) && empty($c2)) return true;
        return (strpos($bt, $selectedCategory) !== false || $c1 === $selectedCategory || $c2 === $selectedCategory);
    });
}

$roundsGrouped = [];
foreach ($matches as $m) {
    $roundsGrouped[$m['round_number']][] = $m;
}
$totalRounds = count($roundsGrouped);

// ตารางคะแนนกลุ่ม (ถ้ามี)
$groups = $pdo->prepare("
    SELECT tg.tournament_group_id AS group_id, tg.name AS group_name,
           gt.team_id, t.name AS team_name, t.team_category,
           gt.played, gt.wins, gt.draws, gt.losses, gt.points, gt.score_diff
    FROM tournament_groups tg
    JOIN group_teams gt ON gt.group_id = tg.tournament_group_id
    JOIN teams t ON t.team_id = gt.team_id
    WHERE tg.tournament_id = :tid
    ORDER BY tg.name, gt.points DESC, gt.score_diff DESC
");
$groups->execute(['tid' => $tournamentId]);
$groupRows = $groups->fetchAll();

$groupedStandings = [];
foreach ($groupRows as $row) {
    if (!$isOpenGame && ($row['team_category'] ?? '') !== $selectedCategory) {
        continue;
    }
    $groupedStandings[$row['group_name']][] = $row;
}

// ที่พักแนะนำ
$accommodations = $pdo->prepare("
    SELECT * FROM accommodations
    WHERE tournament_id IS NULL OR tournament_id = :tid
    ORDER BY accommodation_id
");
$accommodations->execute(['tid' => $tournamentId]);
$accommodations = $accommodations->fetchAll();

function roundName($roundNum, $totalRounds)
{
    $fromEnd = $totalRounds - $roundNum;
    if ($fromEnd == 0)
        return 'รอบชิงชนะเลิศ';
    if ($fromEnd == 1)
        return 'รอบรองชนะเลิศ';
    if ($fromEnd == 2)
        return 'รอบก่อนรองชนะเลิศ';
    return "รอบที่ {$roundNum}";
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tournamentName); ?> - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- AOS CSS -->
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            orange: '#FF5500',
                            glow: '#FF7700',
                            cyber: '#00F0FF',
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
                        'cyber-glow': '0 0 25px rgba(255, 85, 0, 0.3)'
                    }
                }
            }
        }
    </script>

    <style>
        html, body { -ms-overflow-style: none; scrollbar-width: none; }
        body { background-color: #0F1117; color: #f3f4f6; }

        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.55), rgba(15, 17, 23, 0.90)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: scroll;
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

        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
            z-index: 2;
        }

        .lodging-card {
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            width: 100%;
        }

        .lodging-card:hover {
            transform: translateY(-8px);
            border-color: rgba(255, 85, 0, 0.7);
            box-shadow: 0 15px 35px -5px rgba(255, 85, 0, 0.45);
        }

        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }

        @keyframes kenBurns {
            0% { transform: scale(1); }
            50% { transform: scale(1.06); }
            100% { transform: scale(1); }
        }
        .animate-ken-burns { animation: kenBurns 18s ease-in-out infinite; }

        .shine-btn { position: relative; overflow: hidden; }
        .shine-btn::after {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: linear-gradient(60deg, transparent 30%, rgba(255, 255, 255, 0.4) 50%, transparent 70%);
            transform: rotate(30deg) translateX(-100%); transition: transform 0.7s ease;
        }
        .shine-btn:hover::after { transform: rotate(30deg) translateX(100%); }

        .trophy-glass-bg {
            position: absolute; right: 20px; top: 50%; transform: translateY(-50%); width: 500px; height: 400px;
            background: url('https://images.unsplash.com/photo-1511512578047-dfb367046420?q=80&w=1000&auto=format&fit=crop') no-repeat center;
            background-size: cover; filter: blur(4px); opacity: 0.18; pointer-events: none; z-index: 0;
            border-radius: 20px;
        }
        .victory-watermark {
            position: absolute; right: 50px; top: 40%; transform: translateY(-50%);
            font-family: 'Orbitron', sans-serif; font-size: 7rem; font-weight: 900; color: rgba(255, 85, 0, 0.05);
            text-transform: uppercase; pointer-events: none; z-index: 0; letter-spacing: 0.1em;
        }

        .bracket-container {
            display: flex; align-items: stretch; justify-content: flex-start; gap: 100px; position: relative; padding: 40px 20px; min-width: max-content; width: 100%;
        }
        .bracket-round {
            display: flex; flex-direction: column; justify-content: space-around; position: relative; z-index: 2; width: 280px; flex-shrink: 0;
        }

        .bracket-match {
            position: relative; margin: 20px 0; transition: transform 0.3s ease, box-shadow 0.3s ease; width: 100%;
        }
        .bracket-match:hover {
            transform: translateY(-4px); box-shadow: 0 10px 25px -5px rgba(255, 85, 0, 0.4);
        }
        .bracket-svg-lines {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 10;
        }
        .bracket-path-base {
            stroke: #FF5500; stroke-width: 3; fill: none; opacity: 0.85;
            filter: drop-shadow(0 0 5px rgba(255, 85, 0, 0.6));
            transition: stroke 0.3s, stroke-width 0.3s, opacity 0.3s, filter 0.3s;
        }
        .bracket-path-decided {
            stroke: #FF5500; stroke-width: 4px; opacity: 1;
            filter: drop-shadow(0 0 10px rgba(255, 85, 0, 0.95));
        }
        .bracket-path-active {
            stroke: #10E070 !important; stroke-width: 4px !important; opacity: 1;
            filter: drop-shadow(0 0 12px #10E070) !important;
        }
        .bracket-match-team { transition: all 0.25s ease; cursor: pointer; }
        .bracket-match-team:hover {
            background-color: rgba(255, 85, 0, 0.4) !important;
            border-color: #FF5500 !important;
            box-shadow: 0 0 18px rgba(255, 85, 0, 0.7);
        }

        .holo-arena-box {
            position: relative;
            background:
                radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1.2px),
                radial-gradient(circle at 88% 20%, rgba(255, 85, 0, 0.22), transparent 55%),
                radial-gradient(circle at 8% 90%, rgba(0, 240, 255, 0.14), transparent 50%),
                linear-gradient(135deg, rgba(10, 10, 14, 0.88), rgba(18, 10, 6, 0.92)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: 22px 22px, cover, cover, cover, cover;
            background-position: center, center, center, center, center;
            border: 2px solid rgba(255, 85, 0, 0.5);
            box-shadow: inset 0 0 50px rgba(255, 85, 0, 0.2), 0 20px 50px rgba(0, 0, 0, 0.9);
            overflow: hidden;
            overflow-x: auto;
            overflow-y: hidden;
        }
        
        .holo-arena-box::-webkit-scrollbar {
            display: block; height: 10px;
        }
        .holo-arena-box::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.3); border-radius: 10px;
        }
        .holo-arena-box::-webkit-scrollbar-thumb {
            background: rgba(255, 85, 0, 0.6); border-radius: 10px;
        }
        .holo-arena-box::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 85, 0, 0.9);
        }

        .cyber-corner-tr {
            position: absolute; top: 0; right: 0; width: 60px; height: 60px;
            border-top: 3px solid #00F0FF; border-right: 3px solid #00F0FF; pointer-events: none; z-index: 4;
        }
        .cyber-corner-bl {
            position: absolute; bottom: 0; left: 0; width: 60px; height: 60px;
            border-bottom: 3px solid #FF5500; border-left: 3px solid #FF5500; pointer-events: none; z-index: 4;
        }
    </style>
</head>

<body class="font-sans min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>

    <div class="relative z-10 flex flex-col min-h-screen">

        <!-- ================= 1. PUBLIC NAVIGATION BAR ================= -->
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
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange transition-all shadow-orange-glow"><i class="fa-solid fa-trophy text-xs mr-1.5"></i> ทัวร์นาเมนต์</a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-ranking-star text-xs mr-1.5"></i> ตารางคะแนน</a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-newspaper text-xs mr-1.5"></i> ข่าวสาร</a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-images text-xs mr-1.5"></i> แกลเลอรี่</a>
                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all"><i class="fa-solid fa-hotel text-xs mr-1.5"></i> ที่พักแนะนำ</a>
                        <?php endif; ?>
                    </nav>

                    <div class="flex items-center gap-4 text-base font-bold">
                        <?php if ($isLoggedIn): ?>
                            <div class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md shadow-cyber-glow">
                                <div class="flex flex-col text-right">
                                    <span class="text-sm font-bold text-white leading-tight"><?= htmlspecialchars($currentUser['username'] ?? 'User') ?></span>
                                    <span class="text-[10px] font-semibold text-brand-orange uppercase tracking-wider"><?= htmlspecialchars($currentUser['role'] ?? 'Player') ?></span>
                                </div>
                                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                    <a href="../admin/dashboard.php" title="ระบบหลังบ้าน Admin" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md"><i class="fa-solid fa-user-shield text-sm"></i></a>
                                <?php else: ?>
                                    <a href="profile.php" title="จัดการโปรไฟล์/ทีม" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md"><i class="fa-solid fa-user-gear text-sm"></i></a>
                                <?php endif; ?>
                                <a href="../auth/logout.php" title="ออกจากระบบ" class="w-9 h-9 rounded-xl bg-rose-500/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 flex items-center justify-center transition-all"><i class="fa-solid fa-right-from-bracket text-sm"></i></a>
                            </div>
                        <?php else: ?>
                            <a href="../auth/login.php" class="text-brand-orange hover:text-brand-glow transition-colors">เข้าสู่ระบบ</a>
                            <a href="../auth/register.php" class="text-white hover:text-brand-orange transition-colors">สมัครสมาชิก</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- ================= 2. TOURNAMENT HERO BANNER ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 w-full" data-aos="fade-down" data-aos-duration="1000">
            <div class="glass-panel rounded-3xl p-6 sm:p-10 border border-white/20 shadow-2xl space-y-6 relative overflow-hidden">
                <div class="trophy-glass-bg"></div>
                <div class="victory-watermark">VICTORY</div>
                
                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-6 relative z-10">
                    <div class="space-y-4 max-w-3xl">
                        <div class="p-3.5 rounded-2xl bg-black/40 border border-brand-orange/40 backdrop-blur-md inline-block shadow-[0_0_15px_rgba(255,85,0,0.2)]">
                            <p class="font-display font-black text-xs sm:text-sm tracking-widest text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-400 to-white uppercase">
                                VICTORY // FIGHT FOR GLORY — EVERY MOMENT COUNTS
                            </p>
                        </div>
                        <h1 class="text-3xl sm:text-5xl font-black font-display text-white uppercase leading-tight drop-shadow-md">
                            <?php echo htmlspecialchars($tournamentName); ?>
                        </h1>
                        <p class="text-gray-300">เกม: <?php echo htmlspecialchars($tournament['game_name']); ?></p>
                    </div>

                    <?php if (($tournament['status'] ?? '') == 'registration_open'): ?>
                        <div class="shrink-0 relative z-10">
                            <a href="register-tournament.php?id=<?php echo $tournamentId; ?>" class="shine-btn px-8 py-5 rounded-2xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm uppercase tracking-wider transition-all shadow-orange-glow flex items-center justify-center gap-3 w-full sm:w-auto transform hover:-translate-y-1">
                                <i class="fa-solid fa-trophy text-amber-300 text-lg"></i>
                                <span>สมัครเข้าร่วมแข่งขัน</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full space-y-12">

            <!-- ================= 4. RULES & SCHEDULE TIMELINE SECTION ================= -->
            <section class="grid grid-cols-1 md:grid-cols-2 gap-6" data-aos="fade-up" data-aos-duration="800">
                <?php if (!empty($tournament['rules'] ?? '')): ?>
                    <div class="glass-panel p-6 rounded-3xl border border-white/15 shadow-xl space-y-3 flex flex-col justify-between">
                        <div>
                            <h3 class="text-base font-bold font-display text-brand-orange uppercase tracking-wider flex items-center gap-2 border-b border-white/10 pb-3">
                                <i class="fa-solid fa-scroll"></i> กฎกติกาการแข่งขัน (RULES)
                            </h3>
                            <div class="text-xs text-gray-200 leading-relaxed font-normal whitespace-pre-line mt-3">
                                <?php echo htmlspecialchars($tournament['rules']); ?>
                            </div>
                        </div>
                        <?php if (!empty($tournament['description'] ?? '')): ?>
                            <div class="pt-4 border-t border-white/10 text-[11px] text-gray-400">
                                <span class="font-bold text-gray-300">หมายเหตุ:</span> <?php echo htmlspecialchars($tournament['description']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="glass-panel p-6 rounded-3xl border border-white/15 shadow-xl space-y-4 relative overflow-hidden flex flex-col justify-between">
                    <div class="absolute -right-10 -bottom-10 w-40 h-40 bg-brand-orange/10 rounded-full blur-3xl pointer-events-none"></div>
                    <div>
                        <h3 class="text-base font-bold font-display text-amber-400 uppercase tracking-wider flex items-center gap-2 border-b border-white/10 pb-3">
                            <i class="fa-solid fa-calendar-days"></i> กำหนดการสำคัญ & สถานะทัวร์นาเมนต์
                        </h3>
                        
                        <div class="space-y-3.5 mt-4 text-xs">
                            <div class="flex items-center justify-between p-3 rounded-xl bg-white/5 border border-white/10">
                                <span class="text-gray-400 flex items-center gap-2">
                                    <i class="fa-solid fa-circle-dot text-brand-orange text-[10px]"></i> สถานะการรับสมัคร
                                </span>
                                <span class="px-3 py-1 rounded-full font-bold uppercase tracking-wider text-[11px] <?php echo ($tournament['status'] == 'registration_open') ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-300 border border-rose-500/30'; ?>">
                                    <?php echo ($tournament['status'] == 'registration_open') ? 'เปิดรับสมัครอยู่' : 'ปิดรับสมัคร / กำลังแข่ง'; ?>
                                </span>
                            </div>

                            <div class="flex items-center justify-between p-3 rounded-xl bg-white/5 border border-white/10">
                                <span class="text-gray-400 flex items-center gap-2">
                                    <i class="fa-regular fa-calendar-plus text-brand-cyber"></i> วันเปิดรับสมัคร
                                </span>
                                <span class="font-mono font-semibold text-white">
                                    <?php echo !empty($tournament['start_date']) ? date('d / m / Y', strtotime($tournament['start_date'])) : '-'; ?>
                                </span>
                            </div>

                            <div class="flex items-center justify-between p-3 rounded-xl bg-white/5 border border-white/10">
                                <span class="text-gray-400 flex items-center gap-2">
                                    <i class="fa-regular fa-calendar-xmark text-rose-400"></i> วันปิดรับสมัคร
                                </span>
                                <span class="font-mono font-semibold text-white">
                                    <?php echo !empty($tournament['end_date']) ? date('d / m / Y', strtotime($tournament['end_date'])) : '-'; ?>
                                </span>
                            </div>

                            <div class="flex items-center justify-between p-3 rounded-xl bg-white/5 border border-white/10">
                                <span class="text-gray-400 flex items-center gap-2">
                                    <i class="fa-solid fa-trophy text-amber-400"></i> วันแข่งขัน
                                </span>
                                <span class="font-mono font-bold text-amber-300">
                                    <?php echo !empty($tournament['match_date']) ? date('d / m / Y', strtotime($tournament['match_date'])) : (!empty($tournament['start_date']) ? date('d / m / Y', strtotime($tournament['start_date'])) : '-'); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="p-3 rounded-2xl bg-gradient-to-r from-brand-orange/20 to-amber-500/10 border border-brand-orange/30 text-center">
                        <p class="text-[11px] text-gray-200 font-medium">
                            <i class="fa-solid fa-triangle-exclamation text-amber-400 mr-1"></i> กรุณาตรวจสอบรายชื่อและกฎกติกาก่อนกดยืนยันสมัครแข่งขันทุกครั้ง
                        </p>
                    </div>
                </div>
            </section>

            <!-- ================= 5. GROUP STAGE STANDINGS ================= -->
            <?php if (!empty($groupedStandings)): ?>
                <section class="space-y-6" data-aos="fade-up" data-aos-duration="900">
                    <div class="flex items-center gap-3 border-b border-white/15 pb-4">
                        <i class="fa-solid fa-table-cells-large text-brand-orange text-2xl"></i>
                        <div>
                            <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider">ตารางคะแนนรอบแบ่งกลุ่ม (GROUP STAGE)</h2>
                            <p class="text-xs text-gray-400">สรุปอันดับคะแนน ชนะ เสมอ แพ้ ประจำแต่ละสายการแข่งขัน</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($groupedStandings as $groupName => $rows): ?>
                            <div class="glass-panel rounded-2xl overflow-hidden border border-white/15 shadow-xl">
                                <div class="bg-black/50 p-4 border-b border-white/10 flex items-center justify-between">
                                    <h3 class="font-bold font-display text-brand-orange text-sm uppercase tracking-wider flex items-center gap-2">
                                        <i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars($groupName); ?>
                                    </h3>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs text-gray-200">
                                        <thead class="bg-white/5 uppercase font-bold text-gray-400 border-b border-white/10 font-display">
                                            <tr>
                                                <th class="p-3">ทีม</th>
                                                <th class="p-3 text-center">แข่ง</th>
                                                <th class="p-3 text-center">ชนะ</th>
                                                <th class="p-3 text-center">เสมอ</th>
                                                <th class="p-3 text-center">แพ้</th>
                                                <th class="p-3 text-right">คะแนน</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-white/5 font-medium">
                                            <?php foreach ($rows as $rowIndex => $r):
                                                $isTopTeamInGroup = ($rowIndex === 0);
                                                $rowHighlightClass = $isTopTeamInGroup ? 'border-l-4 border-l-amber-400 bg-amber-500/10' : 'hover:bg-white/10';
                                                ?>
                                                <tr class="transition-colors <?php echo $rowHighlightClass; ?>">
                                                    <td class="p-3 font-bold text-white flex items-center gap-2">
                                                        <?php if ($isTopTeamInGroup): ?>
                                                            <i class="fa-solid fa-crown text-amber-400"></i>
                                                        <?php else: ?>
                                                            <i class="fa-solid fa-shield-halved text-brand-orange"></i>
                                                        <?php endif; ?>
                                                        <?php echo htmlspecialchars($r['team_name']); ?>
                                                    </td>
                                                    <td class="p-3 text-center text-gray-400 font-mono"><?php echo $r['played']; ?></td>
                                                    <td class="p-3 text-center text-emerald-400 font-mono font-bold"><?php echo $r['wins']; ?></td>
                                                    <td class="p-3 text-center text-amber-400 font-mono"><?php echo $r['draws']; ?></td>
                                                    <td class="p-3 text-center text-rose-400 font-mono"><?php echo $r['losses']; ?></td>
                                                    <td class="p-3 text-right font-display font-black text-brand-orange text-sm"><?php echo $r['points']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <!-- ================= 6. TOURNAMENT BRACKET ================= -->
            <section class="space-y-6" data-aos="fade-up" data-aos-duration="1000">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between border-b border-white/15 pb-4 gap-4">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid fa-sitemap text-brand-orange text-2xl"></i>
                        <div>
                            <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider">สายการแข่งขัน (TOURNAMENTS BRACKET)</h2>
                            <p class="text-xs text-gray-400">เส้นทางของทีมที่รู้ผลผู้ชนะแล้วจะเรืองแสงส้มถาวร ชี้เมาส์เพื่อดูเส้นทาง</p>
                        </div>
                    </div>
                </div>

                <!-- TAB กรองสายชาย/หญิง -->
                <?php if ($tournament['play_mode'] !== 'solo' && !$isOpenGame): ?>
                    <div class="flex items-center gap-2 glass-panel p-4 rounded-2xl border border-white/15 shadow-xl">
                        <span class="text-xs font-bold text-gray-400 uppercase mr-2"><i class="fa-solid fa-filter text-brand-orange mr-1"></i> เลือกสายการแข่งขัน:</span>
                        <a href="tournament-detail.php?id=<?php echo $tournamentId; ?>&category=male" class="px-4 py-2 rounded-xl text-xs font-bold <?php echo ($selectedCategory === 'male') ? 'bg-brand-orange text-white shadow-orange-glow' : 'bg-white/10 text-gray-300 hover:bg-white/20'; ?>">👨 สายทีมชาย</a>
                        <a href="tournament-detail.php?id=<?php echo $tournamentId; ?>&category=female" class="px-4 py-2 rounded-xl text-xs font-bold <?php echo ($selectedCategory === 'female') ? 'bg-brand-orange text-white shadow-orange-glow' : 'bg-white/10 text-gray-300 hover:bg-white/20'; ?>">👩 สายทีมหญิง</a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($roundsGrouped)): ?>
                    <div class="holo-arena-box p-6 sm:p-10 rounded-3xl relative shadow-2xl">
                        <div class="flex items-center justify-between pb-4 mb-6 border-b border-white/15 relative z-10">
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/15 border border-emerald-400/40 text-emerald-300 text-xs font-bold uppercase tracking-wider">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                อัปเดตสายการแข่งขัน
                            </span>
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-brand-orange/15 border border-brand-orange/40 text-brand-orange text-xs font-bold uppercase tracking-wider">
                                <i class="fa-solid fa-layer-group"></i>
                                ทั้งหมด <?php echo $totalRounds; ?> รอบ
                            </span>
                        </div>
                        <div class="cyber-corner-tr"></div>
                        <div class="cyber-corner-bl"></div>

                        <div class="bracket-container relative z-10" id="bracketContainer">
                            <svg class="bracket-svg-lines" id="bracketSvg"></svg>
                            <?php foreach ($roundsGrouped as $roundNum => $roundMatches): ?>
                                <div class="bracket-round">
                                    <div class="text-center font-display font-bold text-xs text-brand-orange uppercase tracking-wider mb-2">
                                        <?php echo roundName($roundNum, $totalRounds); ?>
                                    </div>
                                    <div class="flex flex-col justify-around h-full">
                                        <?php foreach ($roundMatches as $m):
                                            $isDecided = !empty($m['winner_team_id']);
                                        ?>
                                            <div class="glass-card rounded-2xl border border-white/15 overflow-hidden shadow-lg p-1.5 space-y-1 bracket-match"
                                                data-match-id="<?php echo $m['match_id']; ?>"
                                                data-decided="<?php echo $isDecided ? '1' : '0'; ?>"
                                                data-winner-id="<?php echo $m['winner_team_id'] ?? ''; ?>">
                                        
                                                <?php 
                                                    $isT1Winner = ($m['winner_team_id'] == $m['team1_id'] && $m['team1_id']);
                                                    $t1Id = $m['team1_id'] ?? 'none';
                                                    $t1Name = $m['team1_name'] ?? 'รอผู้ชนะรอบก่อน';
                                                ?>
                                                <div onmouseenter="highlightTeamPath('<?php echo $t1Id; ?>')" onmouseleave="resetTeamPath()"
                                                    class="bracket-match-team flex items-center justify-between p-2.5 rounded-xl transition-all <?php echo $isT1Winner ? 'bg-brand-orange/40 border border-brand-orange text-white shadow-[0_0_15px_rgba(255,85,0,0.6)]' : 'bg-black/40 text-gray-300'; ?>"
                                                    data-team-id="<?php echo $t1Id; ?>">
                                                    <span class="text-xs font-bold truncate max-w-[170px] flex items-center gap-1.5">
                                                        <?php if ($isT1Winner): ?><i class="fa-solid fa-trophy text-amber-400 text-[10px]"></i><?php endif; ?>
                                                        <?php echo htmlspecialchars($t1Name); ?>
                                                    </span>
                                                    <?php if ($m['team1_score'] !== null): ?>
                                                        <span class="font-mono text-xs font-black px-2 py-0.5 rounded bg-black/50 text-white border border-white/10"><?php echo $m['team1_score']; ?></span>
                                                    <?php endif; ?>
                                                </div>

                                                <?php 
                                                    $isT2Winner = ($m['winner_team_id'] == $m['team2_id'] && $m['team2_id']);
                                                    $t2Id = $m['team2_id'] ?? 'none';
                                                    $t2Name = $m['team2_name'] ?? 'รอผู้ชนะรอบก่อน';
                                                ?>
                                                <div onmouseenter="highlightTeamPath('<?php echo $t2Id; ?>')" onmouseleave="resetTeamPath()"
                                                    class="bracket-match-team flex items-center justify-between p-2.5 rounded-xl transition-all <?php echo $isT2Winner ? 'bg-brand-orange/40 border border-brand-orange text-white shadow-[0_0_15px_rgba(255,85,0,0.6)]' : 'bg-black/40 text-gray-300'; ?>"
                                                    data-team-id="<?php echo $t2Id; ?>">
                                                    <span class="text-xs font-bold truncate max-w-[170px] flex items-center gap-1.5">
                                                        <?php if ($isT2Winner): ?><i class="fa-solid fa-trophy text-amber-400 text-[10px]"></i><?php endif; ?>
                                                        <?php echo htmlspecialchars($t2Name); ?>
                                                    </span>
                                                    <?php if ($m['team2_score'] !== null): ?>
                                                        <span class="font-mono text-xs font-black px-2 py-0.5 rounded bg-black/50 text-white border border-white/10"><?php echo $m['team2_score']; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="holo-arena-box p-12 text-center text-gray-400 rounded-3xl">
                        <i class="fa-solid fa-sitemap text-4xl mb-3 block opacity-40 text-brand-orange"></i>
                        ยังไม่มีการสร้างสายการแข่งขันสำหรับรายการนี้
                    </div>
                <?php endif; ?>
            </section>

            <!-- ================= 7. RECOMMENDED LODGING ================= -->
            <?php if (!empty($accommodations)): ?>
                <section class="space-y-6 pt-4" data-aos="fade-up" data-aos-duration="1000">
                    <div class="flex items-center gap-3 border-b border-white/15 pb-4">
                        <i class="fa-solid fa-hotel text-brand-orange text-2xl"></i>
                        <div>
                            <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider">ที่พักแนะนำสำหรับการแข่งขันนี้</h2>
                            <p class="text-xs text-gray-400">โรงแรมและที่พักใกล้สนามแข่งขันสำหรับนักกีฬาและผู้ติดตาม</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <?php foreach ($accommodations as $a): ?>
                            <div class="lodging-card p-6 rounded-2xl flex flex-col justify-between space-y-4">
                                <div class="space-y-2">
                                    <h3 class="font-bold text-white text-base font-display flex items-center gap-2">
                                        <i class="fa-solid fa-building text-brand-orange"></i> <?php echo htmlspecialchars($a['name']); ?>
                                    </h3>
                                    <?php if ($a['address'] ?? ''): ?>
                                        <p class="text-xs text-gray-300 font-normal leading-relaxed flex items-start gap-1.5">
                                            <i class="fa-solid fa-location-dot text-brand-orange mt-0.5 shrink-0"></i>
                                            <span><?php echo htmlspecialchars($a['address']); ?></span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <?php if ($a['link_url'] ?? ''): ?>
                                    <div class="pt-2 border-t border-white/10">
                                        <a href="<?php echo htmlspecialchars($a['link_url']); ?>" target="_blank" rel="noopener" class="w-full py-2 px-3 rounded-xl bg-blue-600/30 hover:bg-blue-600 text-blue-200 hover:text-white border border-blue-500/30 text-xs font-bold flex items-center justify-center gap-1.5 transition-all">
                                            <i class="fa-solid fa-map-location-dot"></i>
                                            <span>ดูแผนที่ / จองที่พัก</span>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
        
        <footer class="border-t border-white/15 bg-slate-950/90 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
             <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">&copy; <?= date('Y') ?> KORAT ESPORT.</div>
        </footer>
    </div>

   <!-- AOS JS Library -->
    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>

    <!-- Script คำนวณพิกัดเส้น SVG แบบสมมาตรตรงกลางเป๊ะ 100% พร้อมเรืองแสงถาวรสำหรับคู่ที่รู้ผลแล้ว -->
    <script>
        // 3. Team Spotlight Rotator
        const teamSpotlights = [
            { name: "JOSDevil", winRate: "92%", mvp: "PlayerX" },
            { name: "Bermuda Esport", winRate: "88%", mvp: "CyberGod" },
            { name: "Catbox Gaming", winRate: "85%", mvp: "NokHook" },
            { name: "Dararank VIP", winRate: "81%", mvp: "TeeKorat" }
        ];
        let spotlightIndex = 0;

        function updateSpotlight() {
            const teamEl = document.getElementById('spotlightTeamName');
            const winEl = document.getElementById('spotlightWinRate');
            const mvpEl = document.getElementById('spotlightMvp');
            const cardEl = document.getElementById('teamSpotlightCard');

            if (teamEl && winEl && mvpEl && cardEl) {
                cardEl.style.opacity = '0.3';
                cardEl.style.transform = 'translateY(5px)';
                setTimeout(() => {
                    spotlightIndex = (spotlightIndex + 1) % teamSpotlights.length;
                    const data = teamSpotlights[spotlightIndex];
                    teamEl.innerText = data.name;
                    winEl.innerText = data.winRate;
                    mvpEl.innerText = data.mvp;
                    cardEl.style.opacity = '1';
                    cardEl.style.transform = 'translateY(0)';
                }, 250);
            }
        }
        setInterval(updateSpotlight, 5000);

        document.addEventListener('DOMContentLoaded', () => {
            AOS.init({ once: true, duration: 800, easing: 'ease-out-cubic' });
            setTimeout(() => {
                drawProportionalCenterLines();
            }, 300);
        });

        // ฟังก์ชันวาดเส้นเชื่อมมุมฉากสมมาตร + ใส่เรืองแสงถาวรให้คู่ที่รู้ผลผู้ชนะแล้ว
        function drawProportionalCenterLines() {
            const container = document.getElementById('bracketContainer');
            const svg = document.getElementById('bracketSvg');
            if (!container || !svg) return;

            svg.innerHTML = '';
            
            const totalWidth = container.scrollWidth;
            const totalHeight = container.scrollHeight;
            svg.setAttribute('width', totalWidth);
            svg.setAttribute('height', totalHeight);

            const containerRect = container.getBoundingClientRect();
            const rounds = container.querySelectorAll('.bracket-round');

            for (let i = 0; i < rounds.length - 1; i++) {
                const currentRoundMatches = rounds[i].querySelectorAll('.bracket-match');
                const nextRoundMatches = rounds[i + 1].querySelectorAll('.bracket-match');

                for (let j = 0; j < nextRoundMatches.length; j++) {
                    const matchTop = currentRoundMatches[j * 2];
                    const matchBottom = currentRoundMatches[j * 2 + 1];
                    const targetMatch = nextRoundMatches[j];

                    if (matchTop && targetMatch) {
                        const rTop = matchTop.getBoundingClientRect();
                        const rTarget = targetMatch.getBoundingClientRect();

                        const x1 = rTop.right - containerRect.left + container.scrollLeft;
                        const yTop = rTop.top + (rTop.height / 2) - containerRect.top + container.scrollTop;
                        const xTarget = rTarget.left - containerRect.left + container.scrollLeft;
                        const yTarget = rTarget.top + (rTarget.height / 2) - containerRect.top + container.scrollTop;
                        const midX = x1 + (xTarget - x1) / 2;

                        const topDecided = matchTop.getAttribute('data-decided') === '1';
                        const pathTop = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                        pathTop.setAttribute('d', `M ${x1} ${yTop} L ${midX} ${yTop} L ${midX} ${yTarget} L ${xTarget} ${yTarget}`);
                        pathTop.setAttribute('class', 'bracket-path-base' + (topDecided ? ' bracket-path-decided' : ''));

                        const winnerTop = matchTop.getAttribute('data-winner-id');
                        if (winnerTop) pathTop.setAttribute('data-team-match', winnerTop);
                        svg.appendChild(pathTop);

                        if (matchBottom) {
                            const bottomDecided = matchBottom.getAttribute('data-decided') === '1';
                            const rBot = matchBottom.getBoundingClientRect();
                            const yBot = rBot.top + (rBot.height / 2) - containerRect.top + container.scrollTop;

                            const pathBot = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                            pathBot.setAttribute('d', `M ${x1} ${yBot} L ${midX} ${yBot} L ${midX} ${yTarget} L ${xTarget} ${yTarget}`);
                            pathBot.setAttribute('class', 'bracket-path-base' + (bottomDecided ? ' bracket-path-decided' : ''));

                            const winnerBot = matchBottom.getAttribute('data-winner-id');
                            if (winnerBot) pathBot.setAttribute('data-team-match', winnerBot);
                            svg.appendChild(pathBot);
                        }
                    }
                }
            }
        }

        // Interactive Hover Effect
        function highlightTeamPath(teamId) {
            if (!teamId || teamId === 'none') return;
            
            const teamElements = document.querySelectorAll(`[data-team-id="${teamId}"]`);
            teamElements.forEach(el => {
                el.style.backgroundColor = 'rgba(16, 224, 112, 0.30)';
                el.style.borderColor = '#10E070';
                el.style.boxShadow = '0 0 20px rgba(16, 224, 112, 0.85)';
            });

            const paths = document.querySelectorAll('.bracket-path-base');
            paths.forEach(path => {
                if (path.getAttribute('data-team-match') === teamId) {
                    path.classList.add('bracket-path-active');
                } else {
                    path.style.opacity = '0.2';
                }
            });
        }

        function resetTeamPath() {
            const teamElements = document.querySelectorAll('.bracket-match-team');
            teamElements.forEach(el => {
                el.style.backgroundColor = '';
                el.style.borderColor = '';
                el.style.boxShadow = '';
            });

            const paths = document.querySelectorAll('.bracket-path-base');
            paths.forEach(path => {
                path.classList.remove('bracket-path-active');
                path.style.opacity = '';
            });
        }

        window.addEventListener('resize', drawProportionalCenterLines);
        // เพิ่ม Event ให้วาดเส้นใหม่ทุกครั้งเวลาเลื่อน Scroll เผื่อมีปัญหาเส้นเบี้ยว
        const arenaBox = document.querySelector('.holo-arena-box');
        if (arenaBox) {
            arenaBox.addEventListener('scroll', drawProportionalCenterLines);
        }
    </script>
</body>
</html>