<?php
// pages/index.php
require_once '../config/db.php';
require_once '../includes/auth.php';

// ตรวจสอบสถานะการเข้าสู่ระบบแบบยืดหยุ่น ป้องกัน Error / Redirect Loop
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

// แสดงเฉพาะรายการที่ยังสมัครได้จริง ณ เวลาปัจจุบัน
$nowSql = (new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
$tournamentStmt = $pdo->prepare("
    SELECT t.*, g.name AS game_name, g.play_mode
    FROM tournaments t
    JOIN games g ON g.game_id = t.game_id
    WHERE t.status = 'registration_open'
      AND t.registration_start <= :now
      AND t.registration_end >= :now
      AND EXISTS (
          SELECT 1
          FROM tournament_categories tc
          WHERE tc.tournament_id = t.tournament_id
            AND tc.is_active = 1
            AND (
                tc.max_participants IS NULL OR tc.max_participants = 0
                OR (
                    SELECT COUNT(*)
                    FROM tournament_registrations tr
                    WHERE tr.tournament_id = t.tournament_id
                      AND tr.tournament_category_id = tc.tournament_category_id
                      AND tr.status IN ('pending', 'approved')
                ) < tc.max_participants
            )
      )
    ORDER BY t.created_at DESC
    LIMIT 6
");
$tournamentStmt->execute(['now' => $nowSql]);
$tournaments = $tournamentStmt->fetchAll(PDO::FETCH_ASSOC);
$tournamentCategoryStmt = $pdo->prepare("
    SELECT tc.tournament_category_id, tc.category_code, tc.label, tc.max_participants,
           (
               SELECT COUNT(*)
               FROM tournament_registrations tr
               WHERE tr.tournament_id = tc.tournament_id
                 AND tr.tournament_category_id = tc.tournament_category_id
                 AND tr.status IN ('pending', 'approved')
           ) AS registered_count
    FROM tournament_categories tc
    WHERE tc.tournament_id = :tournament_id
      AND tc.is_active = 1
      AND (
          tc.max_participants IS NULL OR tc.max_participants = 0
          OR (
              SELECT COUNT(*)
              FROM tournament_registrations tr
              WHERE tr.tournament_id = tc.tournament_id
                AND tr.tournament_category_id = tc.tournament_category_id
                AND tr.status IN ('pending', 'approved')
          ) < tc.max_participants
      )
    ORDER BY tc.tournament_category_id
");
$myPlayerId = 0;
if ($isLoggedIn) {
    $playerStmt = $pdo->prepare('SELECT player_id FROM players WHERE user_id = :user_id LIMIT 1');
    $playerStmt->execute(['user_id' => $_SESSION['user_id'] ?? 0]);
    $myPlayerId = (int) $playerStmt->fetchColumn();
}

// สถิติรวมของทั้งเว็บ (ปรับ Query ให้ตรงกันกับฝั่ง Admin Dashboard)
$totalTeams = $pdo->query("
    SELECT COUNT(*) FROM teams t
    JOIN players p ON p.player_id = t.captain_player_id
    WHERE p.user_id IS NOT NULL
")->fetchColumn();

$totalPlayers = $pdo->query("SELECT COUNT(*) FROM players WHERE user_id IS NOT NULL")->fetchColumn();
$totalTournaments = $pdo->query("SELECT COUNT(*) FROM tournaments")->fetchColumn();
$totalMatchesPlayed = $pdo->query("SELECT COUNT(*) FROM matches WHERE status IN ('completed', 'walkover')")->fetchColumn();

$banners = [];
try {
    $banners = $pdo->query("SELECT gallery_id, title, caption, image_path FROM gallery
        WHERE media_type = 'banner' AND is_active = 1 ORDER BY gallery_id DESC LIMIT 5")->fetchAll();
} catch (Throwable $e) {
    // The gallery media columns are added by the admin gallery setup when needed.
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Korat Esport - ศูนย์กลางการแข่งขันอีสปอร์ตระดับมืออาชีพ</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&family=Share+Tech+Mono&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- AOS CSS -->
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Vanilla Tilt JS (เอฟเฟกต์การ์ด 3D ตามเมาส์) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/vanilla-tilt/1.8.1/vanilla-tilt.min.js"></script>

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
                        display: ['Orbitron', 'sans-serif'],
                        mono: ['Share Tech Mono', 'monospace']
                    },
                    boxShadow: {
                        'orange-glow': '0 0 25px rgba(255, 85, 0, 0.45)',
                        'orange-glow-lg': '0 0 45px rgba(255, 85, 0, 0.65)'
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
            background-color: #0B0C10;
        }

        .custom-scrollbar::-webkit-scrollbar {
            height: 6px;
            background: rgba(10, 10, 14, 0.6);
            border-radius: 9999px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: linear-gradient(90deg, #FF5500, #ff9900);
            border-radius: 9999px;
            box-shadow: 0 0 10px rgba(255, 85, 0, 0.8);
        }

        /* Background Arena หลักของเว็บไซต์ */
        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(11, 12, 16, 0.65), rgba(11, 12, 16, 0.90)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }

        /* 🌟 พื้นหลังใหม่สำหรับหน้า Intro พร้อมตั้งค่าแสดงผลภาพเต็มจอชัดเจน */
        .bg-intro-unique {
            background: linear-gradient(to bottom, rgba(5, 6, 10, 0.70), rgba(10, 11, 16, 0.88)),
                url('http://googleusercontent.com/image_collection/image_retrieval/2264976174845130798');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .grid-bg {
            background-image:
                linear-gradient(to right, rgba(255, 85, 0, 0.12) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 85, 0, 0.12) 1px, transparent 1px);
            background-size: 40px 40px;
        }

        .cyber-orb-1 {
            position: fixed;
            top: 10%;
            left: 15%;
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(255, 85, 0, 0.35) 0%, transparent 70%);
            filter: blur(60px);
            animation: orbFloat1 18s ease-in-out infinite alternate;
            pointer-events: none;
        }

        .cyber-orb-2 {
            position: fixed;
            bottom: 15%;
            right: 10%;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, rgba(0, 240, 255, 0.25) 0%, transparent 70%);
            filter: blur(70px);
            animation: orbFloat2 22s ease-in-out infinite alternate;
            pointer-events: none;
        }

        @keyframes orbFloat1 {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(120px, 80px) scale(1.2); }
        }

        @keyframes orbFloat2 {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(-100px, -90px) scale(1.15); }
        }

        #particles-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .glass-nav {
            background: rgba(11, 12, 16, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.3s ease;
        }
        .glass-nav.shrink {
            height: 3.5rem !important;
            background: rgba(11, 12, 16, 0.95);
            border-bottom: 1px solid rgba(255, 85, 0, 0.4);
        }
        .glass-nav.shrink .h-20 {
            height: 3.5rem !important;
        }

        .glass-panel {
            background: rgba(255, 255, 255, 0.06);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transform-style: preserve-3d;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .glass-card:hover {
            border-color: rgba(255, 85, 0, 0.7);
            box-shadow: 0 15px 35px -5px rgba(255, 85, 0, 0.45);
        }

        .nav-link-item { position: relative; }
        .nav-link-item::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: #FF5500;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .nav-link-item:hover::after, .nav-link-item.active::after { width: 100%; }

        /* ขยายขนาดโลโก้และวงแหวนรอบให้ใหญ่และเด่นชัดสะดุดตา */
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .animate-logo-float { animation: logoFloat 3.5s ease-in-out infinite; }

        @keyframes orbitSpin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        @keyframes orbitSpinReverse {
            from { transform: rotate(360deg); }
            to { transform: rotate(0deg); }
        }
        #orbit-ring-slow { animation: orbitSpin 14s linear infinite; }
        #orbit-ring-fast { animation: orbitSpinReverse 7s linear infinite; }

        @keyframes pingSlow {
            0% { transform: scale(1); opacity: 0.8; }
            80%, 100% { transform: scale(1.8); opacity: 0; }
        }
        .animate-ping-slow { animation: pingSlow 2.5s cubic-bezier(0, 0, 0.2, 1) infinite; }

        .shine-btn { position: relative; overflow: hidden; }
        .shine-btn::after {
            content: '';
            position: absolute;
            top: -50%; left: -50%; width: 200%; height: 200%;
            background: linear-gradient(60deg, transparent 30%, rgba(255, 255, 255, 0.4) 50%, transparent 70%);
            transform: rotate(30deg) translateX(-100%);
            transition: transform 0.7s ease;
        }
        .shine-btn:hover::after { transform: rotate(30deg) translateX(100%); }

        @keyframes autoGlitch {
            0%, 100% { transform: translate(0); text-shadow: none; }
            20% { transform: translate(-3px, 2px); text-shadow: 2px 0 #00F0FF, -2px 0 #FF5500; }
            40% { transform: translate(3px, -2px); text-shadow: -2px 0 #00F0FF, 2px 0 #FF5500; }
            60% { transform: translate(-2px, 1px); text-shadow: 1px 0 #00F0FF, -1px 0 #FF5500; }
            80% { transform: translate(1px, -1px); text-shadow: none; }
        }
        .animate-auto-glitch { animation: autoGlitch 0.5s ease 1; }

        .esports-corner-card { clip-path: polygon(0 0, calc(100% - 15px) 0, 100% 15px, 100% 100%, 15px 100%, 0 calc(100% - 15px)); }

        @keyframes borderGlowPulse {
            0% { box-shadow: 0 0 5px rgba(244, 63, 94, 0.4); border-color: rgba(244, 63, 94, 0.6); }
            50% { box-shadow: 0 0 25px rgba(244, 63, 94, 0.8); border-color: rgba(244, 63, 94, 1); }
            100% { box-shadow: 0 0 5px rgba(244, 63, 94, 0.4); border-color: rgba(244, 63, 94, 0.6); }
        }
        .live-card-glow { animation: borderGlowPulse 2s infinite; }

        @keyframes rowShimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .shimmer-gold-row {
            background: linear-gradient(90deg, rgba(251, 191, 36, 0.05) 0%, rgba(251, 191, 36, 0.2) 50%, rgba(251, 191, 36, 0.05) 100%);
            background-size: 200% 100%;
            animation: rowShimmer 4s infinite linear;
        }

        #cursor-spotlight {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 2; transition: background 0.1s ease;
        }

        .hud-divider {
            display: flex; align-items: center; justify-content: center; margin: 2rem 0; position: relative; width: 100%;
        }
        .hud-divider::before, .hud-divider::after {
            content: ''; flex: 1; height: 1px; background: linear-gradient(90deg, transparent, rgba(255, 85, 0, 0.5), transparent);
        }
        .hud-divider-badge {
            padding: 0.3rem 1.2rem; background: rgba(18, 19, 24, 0.8); border: 1px solid rgba(255, 85, 0, 0.4); color: #FF5500; font-family: 'Orbitron', sans-serif; font-size: 10px; font-weight: 900; letter-spacing: 0.2em; text-transform: uppercase; box-shadow: 0 0 15px rgba(255, 85, 0, 0.2); display: flex; align-items: center; gap: 6px;
        }
        .hud-divider-badge span {
            width: 6px; height: 6px; background: #00F0FF; box-shadow: 0 0 8px #00F0FF; animation: pulse 1.5s infinite;
        }

        @keyframes iconPulse {
            0%, 100% { filter: drop-shadow(0 0 2px currentColor); transform: scale(1); }
            50% { filter: drop-shadow(0 0 12px currentColor); transform: scale(1.15); }
        }
        .icon-glow-active { animation: iconPulse 1.5s ease infinite; }

        @keyframes scanline {
            0% { transform: translateY(-100%); }
            100% { transform: translateY(100%); }
        }
        .animate-scanline { animation: scanline 8s linear infinite; }
    </style>
</head>

<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased select-none">

    <!-- ================================================================= -->
    <!-- 🎮 1. INTERACTIVE ENTRY SCREEN (คลิกเพื่อเข้าเว็บ + พื้นหลัง Arena ใหม่ + โลโก้ใหญ่เด่นชัด) -->
    <!-- ================================================================= -->
    <div id="intro-screen"
        class="fixed inset-0 z-[100] backdrop-blur-xl flex flex-col items-center justify-center transition-all duration-700 p-4 overflow-hidden cursor-pointer bg-intro-unique"
        onclick="enterArena()">

        <div class="absolute inset-0 grid-bg opacity-40 pointer-events-none"></div>
        <div class="cyber-orb-1"></div>
        <div class="cyber-orb-2"></div>

        <canvas id="intro-particles" class="absolute inset-0 pointer-events-none z-10"></canvas>

        <div class="text-center space-y-6 max-w-lg w-full relative z-20">
            
            <!-- วงแหวนและโลโก้ขนาดใหญ่ ขยายให้โดดเด่นสะดุดตาพร้อมวงแหวนออร์บิท -->
            <div class="relative inline-flex items-center justify-center w-80 h-80 sm:w-96 sm:h-96 mx-auto">
                <div class="absolute inset-0 bg-gradient-to-r from-brand-orange via-purple-600 to-brand-cyber rounded-full blur-3xl opacity-70 animate-pulse"></div>
                <div id="orbit-ring-slow" class="absolute inset-2 rounded-full border-2 border-dashed border-brand-orange/60"></div>
                <div id="orbit-ring-fast" class="absolute inset-8 rounded-full border-2 border-dotted border-brand-cyber/70"></div>
                <div class="absolute inset-12 rounded-full border border-brand-orange/80 animate-ping-slow"></div>
                
                <img src="../assets/img/logo.png" alt="Korat Esport"
                    class="relative z-10 h-56 sm:h-72 mx-auto drop-shadow-[0_0_65px_rgba(255,85,0,1)] animate-logo-float"
                    onError="this.src='https://placehold.co/150x150/121318/FF5500?text=KE';">
            </div>

            <div class="space-y-2">
                <h1 class="font-display text-3xl sm:text-5xl font-black text-white tracking-wider drop-shadow-lg">
                    KORAT <span class="text-brand-orange">ESPORT</span> SYSTEM
                </h1>
                <p class="font-mono text-xs sm:text-sm text-brand-cyber uppercase tracking-widest mt-1 animate-pulse font-bold drop-shadow">
                    [ CLICK ANYWHERE TO ENTER THE ARENA ]
                </p>
            </div>
        </div>
    </div>

    <!-- Dynamic Animated Background Layers -->
    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-60 z-0 pointer-events-none"></div>
    <div class="cyber-orb-1 z-0"></div>
    <div class="cyber-orb-2 z-0"></div>

    <!-- Cursor Spotlight Effect -->
    <div id="cursor-spotlight"></div>

    <!-- Canvas ละอองไฟ/พลังงาน -->
    <canvas id="particles-canvas"></canvas>

    <div class="relative z-10 flex flex-col min-h-screen">

        <!-- ================= 2. PUBLIC NAVIGATION BAR ================= -->
        <header id="main-navbar" class="sticky top-0 z-50 glass-nav transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20 transition-all duration-300">

                    <!-- Logo & Brand Header -->
                    <a href="index.php" class="flex items-center gap-3 group">
                        <img src="../assets/img/logo.png" alt="Korat Esport"
                            class="h-11 w-auto filter drop-shadow-[0_2px_8px_rgba(0,0,0,0.5)] group-hover:scale-105 transition-transform"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span
                                class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors drop-shadow">KORAT
                                <span class="text-brand-orange">ESPORT</span></span>
                            <span
                                class="block text-[10px] tracking-widest text-gray-200 font-bold uppercase -mt-1 drop-shadow-sm">Official
                                Arena & Hub</span>
                        </div>
                    </a>

                    <!-- Public Menu Items with Smooth Underline Indicator -->
                    <nav class="hidden md:flex items-center gap-1 lg:gap-3">
                        <a href="index.php"
                            class="nav-link-item px-4 py-2 text-sm font-bold text-white transition-all active">
                            <i class="fa-solid fa-house text-xs mr-1.5"></i> หน้าแรก
                        </a>
                        <a href="tournaments.php"
                            class="nav-link-item px-4 py-2 text-sm font-semibold text-gray-200 hover:text-brand-orange transition-all drop-shadow-sm">
                            <i class="fa-solid fa-trophy text-xs mr-1.5"></i> ทัวร์นาเมนต์
                        </a>
                        <a href="ranking.php"
                            class="nav-link-item px-4 py-2 text-sm font-semibold text-gray-200 hover:text-brand-orange transition-all drop-shadow-sm">
                            <i class="fa-solid fa-ranking-star text-xs mr-1.5"></i> ตารางคะแนน
                        </a>
                        <a href="news.php"
                            class="nav-link-item px-4 py-2 text-sm font-semibold text-gray-200 hover:text-brand-orange transition-all drop-shadow-sm">
                            <i class="fa-solid fa-newspaper text-xs mr-1.5"></i> ข่าวสาร
                        </a>
                        <a href="gallery.php"
                            class="nav-link-item px-4 py-2 text-sm font-semibold text-gray-200 hover:text-brand-orange transition-all drop-shadow-sm">
                            <i class="fa-solid fa-images text-xs mr-1.5"></i> แกลเลอรี่
                        </a>

                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php"
                                class="nav-link-item px-4 py-2 text-sm font-semibold text-gray-200 hover:text-brand-orange transition-all drop-shadow-sm">
                                <i class="fa-solid fa-hotel text-xs mr-1.5"></i> ที่พักแนะนำ
                            </a>
                        <?php endif; ?>
                    </nav>

                    <!-- User Status / Auth Buttons -->
                    <div class="flex items-center gap-4 text-base font-bold drop-shadow">
                        <?php if ($isLoggedIn && !empty($currentUser['username'])): ?>
                            <div
                                class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md">
                                <div class="flex flex-col text-right">
                                    <span class="text-sm font-bold text-white leading-tight">
                                        <?= htmlspecialchars($currentUser['username'] ?? 'User') ?>
                                    </span>
                                    <span class="text-[10px] font-semibold text-brand-orange uppercase tracking-wider">
                                        <?= htmlspecialchars($currentUser['role'] ?? 'Player') ?>
                                    </span>
                                </div>

                                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                    <a href="../admin/dashboard.php" title="ระบบหลังบ้าน Admin"
                                        class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                        <i class="fa-solid fa-user-shield text-sm"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="profile.php" title="จัดการโปรไฟล์/ทีม"
                                        class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                        <i class="fa-solid fa-user-gear text-sm"></i>
                                    </a>
                                <?php endif; ?>

                                <a href="../auth/logout.php" title="ออกจากระบบ"
                                    class="w-9 h-9 rounded-xl bg-rose-500/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 flex items-center justify-center transition-all">
                                    <i class="fa-solid fa-right-from-bracket text-sm"></i>
                                </a>
                            </div>
                        <?php else: ?>
                            <a href="../auth/login.php" class="text-brand-orange hover:text-brand-glow transition-colors">
                                เข้าสู่ระบบ
                            </a>
                            <a href="../auth/register.php" class="text-white hover:text-brand-orange transition-colors">
                                สมัครสมาชิก
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </header>

        <!-- ================= 3. HERO SECTION ================= -->
        <section
            class="relative min-h-[75vh] flex flex-col items-center justify-center text-center p-6 lg:p-12 overflow-hidden">
            <div
                class="absolute top-0 left-1/2 -translate-x-1/2 w-px h-full bg-gradient-to-b from-transparent via-brand-orange/40 to-transparent animate-scanline">
            </div>

            <div class="max-w-4xl space-y-6 relative z-10" data-aos="zoom-in" data-aos-duration="1000">
                <div
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest backdrop-blur-md shadow-sm">
                    <span class="w-2.5 h-2.5 rounded-full bg-brand-orange animate-ping"></span>
                    Nakhon Ratchasima Esport Hub
                </div>

                <div class="flex justify-center mb-2">
                    <img src="../assets/img/logo.png" alt="Korat Esport"
                        class="h-28 sm:h-36 w-auto filter drop-shadow-[0_4px_15px_rgba(0,0,0,0.5)] animate-logo-float transition-transform duration-300"
                        onError="this.src='https://placehold.co/150x150/121318/FF5500?text=KE';">
                </div>

                <h1 id="hero-title"
                    class="text-5xl sm:text-7xl font-black font-display text-white tracking-wider uppercase leading-none drop-shadow-md glitch-text animate-auto-glitch">
                    KORAT <span
                        class="text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-300 to-white">ESPORT</span>
                </h1>

                <p class="text-lg sm:text-2xl text-gray-100 font-normal max-w-2xl mx-auto leading-relaxed drop-shadow">
                    ศูนย์กลางข้อมูลข่าวสาร การแข่งขัน และจัดอันดับนักกีฬาอีสปอร์ตแห่งจังหวัดนครราชสีมา
                </p>

                <div class="flex flex-wrap items-center justify-center gap-4 pt-4">
                    <a href="#tournaments"
                        class="shine-btn px-8 py-4 rounded-xl font-bold text-white uppercase tracking-wider bg-brand-orange hover:bg-brand-glow shadow-orange-glow hover:shadow-orange-glow-lg transition-all duration-300 transform hover:-translate-y-1 flex items-center gap-2">
                        <i class="fa-solid fa-trophy text-sm"></i>
                        <span>เข้าร่วมการแข่งขัน</span>
                    </a>
                    <a href="ranking.php"
                        class="px-8 py-4 rounded-xl font-bold text-white uppercase tracking-wider glass-panel hover:bg-white/20 border border-white/40 transition-all duration-300 flex items-center gap-2 shadow-md">
                        <i class="fa-solid fa-ranking-star text-amber-400"></i>
                        <span>ดูอันดับตารางคะแนน</span>
                    </a>
                </div>
            </div>
        </section>

        <?php if ($banners): ?>
            <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full" data-aos="fade-up">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-white flex items-center gap-2"><i class="fa-solid fa-bullhorn text-brand-orange"></i> ประชาสัมพันธ์</h2>
                    <span class="text-[10px] text-slate-400">ข่าวสารล่าสุดจาก Korat Esport</span>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($banners as $banner): ?>
                        <article class="relative overflow-hidden rounded-2xl border border-white/20 bg-black/30 shadow-xl aspect-[16/7]">
                            <img src="../assets/<?php echo htmlspecialchars($banner['image_path']); ?>" alt="<?php echo htmlspecialchars($banner['title'] ?: 'แบนเนอร์ประชาสัมพันธ์'); ?>" class="absolute inset-0 w-full h-full object-cover">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                            <div class="absolute inset-x-0 bottom-0 p-4 text-left">
                                <h3 class="text-base font-bold text-white"><?php echo htmlspecialchars($banner['title'] ?: 'ประชาสัมพันธ์'); ?></h3>
                                <?php if (!empty($banner['caption'])): ?><p class="text-xs text-slate-200 mt-1"><?php echo htmlspecialchars($banner['caption']); ?></p><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Cyber HUD Badge Divider -->
        <div class="hud-divider">
            <div class="hud-divider-badge">
                <span></span> ARENA STATS <span></span>
            </div>
        </div>

        <!-- ================= 4. INFOGRAPHIC LIVE STATS STRIP ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 relative z-20 w-full">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-brand-orange relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="0" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">TEAMS</span>
                        <i
                            class="fa-solid fa-people-group text-brand-orange text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm"
                        data-countup="<?php echo $totalTeams; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">ทีมสโมสรในระบบ</p>
                </div>

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-amber-400 relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="100" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">PLAYERS</span>
                        <i
                            class="fa-solid fa-gamepad text-amber-400 text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm"
                        data-countup="<?php echo $totalPlayers; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">นักกีฬาลงทะเบียน</p>
                </div>

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-purple-400 relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="200" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">TOURNAMENTS</span>
                        <i
                            class="fa-solid fa-trophy text-purple-400 text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm"
                        data-countup="<?php echo $totalTournaments; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">รายการแข่งขันทั้งหมด</p>
                </div>

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-emerald-400 relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="300" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">MATCHES</span>
                        <i
                            class="fa-solid fa-bolt text-emerald-400 text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm"
                        data-countup="<?php echo $totalMatchesPlayed; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">แมตช์ที่สมบูรณ์แล้ว</p>
                </div>

            </div>
        </section>

        <!-- Cyber HUD Badge Divider -->
        <div class="hud-divider">
            <div class="hud-divider-badge">
                <span></span> TOURNAMENTS <span></span>
            </div>
        </div>

        <!-- ================= 5. TOURNAMENTS SECTION ================= -->
        <section id="tournaments" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8 w-full">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between border-b border-white/20 pb-4 gap-4"
                data-aos="fade-right">
                <div>
                    <span class="text-brand-orange font-bold text-xs uppercase tracking-widest block mb-1">ARENA
                        EVENTS</span>
                    <h2
                        class="text-3xl font-black font-display text-white uppercase tracking-wide flex items-center gap-3 drop-shadow">
                        <i class="fa-solid fa-fire text-brand-orange"></i>                         เปิดรับสมัครตอนนี้
                    </h2>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (count($tournaments) == 0): ?>
                    <div class="col-span-full glass-panel p-12 text-center text-gray-200 rounded-2xl" data-aos="fade-up">
                        <i class="fa-solid fa-calendar-xmark text-4xl mb-3 block opacity-60"></i>
                        ขณะนี้ยังไม่มีทัวร์นาเมนต์เปิดรับสมัคร
                    </div>
                <?php endif; ?>

                <?php foreach ($tournaments as $index => $t): ?>
                    <?php
                    $tournamentCategoryStmt->execute(['tournament_id' => (int) $t['tournament_id']]);
                    $openCategories = $tournamentCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
                    $registeredByCategory = [];
                    if ($myPlayerId > 0) {
                        $registeredStmt = $pdo->prepare("
                            SELECT DISTINCT tr.tournament_category_id
                            FROM tournament_registrations tr
                            JOIN tournament_registration_members trm
                              ON trm.tournament_registration_id = tr.tournament_registration_id
                             AND trm.player_id = :player_id
                             AND trm.roster_status = 'active'
                            WHERE tr.tournament_id = :tournament_id
                              AND tr.status IN ('pending', 'approved')
                        ");
                        $registeredStmt->execute([
                            'player_id' => $myPlayerId,
                            'tournament_id' => (int) $t['tournament_id'],
                        ]);
                        $registeredByCategory = array_fill_keys(array_map('intval', $registeredStmt->fetchAll(PDO::FETCH_COLUMN)), true);
                    }
                    $totalRegistered = array_sum(array_map(static fn(array $category): int => (int) $category['registered_count'], $openCategories));
                    $totalCapacity = array_sum(array_map(static fn(array $category): int => (int) ($category['max_participants'] ?? 0), $openCategories));
                    $remainingCapacity = $totalCapacity > 0 ? max(0, $totalCapacity - $totalRegistered) : null;
                    $registrationUrl = 'register-tournament.php?id=' . (int) $t['tournament_id'];
                    if (count($openCategories) === 1) {
                        $registrationUrl .= '&category_id=' . (int) $openCategories[0]['tournament_category_id'];
                    }
                    $loginUrl = '../auth/login.php?next=' . urlencode('../pages/' . $registrationUrl);
                    $hasRegistration = !empty($registeredByCategory);
                    ?>
                    <div class="glass-card p-6 rounded-2xl flex flex-col justify-between space-y-6 group shadow-lg esports-corner-card"
                        data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>" data-tilt data-tilt-scale="1.02">
                        <div class="space-y-3">
                            <div class="aspect-video rounded-xl overflow-hidden bg-black/50">
                                <img src="<?php echo !empty($t['image_path']) ? '../assets/' . htmlspecialchars($t['image_path']) : 'https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=1000&auto=format&fit=crop'; ?>"
                                    alt="<?php echo htmlspecialchars($t['name']); ?>" class="w-full h-full object-cover">
                            </div>
                            <div class="flex items-center justify-between">
                                <span
                                    class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-white/20 border border-white/30 text-white shadow-sm">
                                    <i class="fa-solid fa-gamepad mr-1 text-brand-orange"></i>
                                    <?php echo htmlspecialchars($t['game_name']); ?>
                                </span>

                                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-500/30 border border-emerald-400 text-emerald-300 text-xs font-bold shadow-sm">
                                    <i class="fa-solid fa-door-open text-[10px]"></i> เปิดรับสมัคร
                                </span>
                            </div>

                            <h3
                                class="text-xl font-black text-white group-hover:text-brand-orange transition-colors font-display line-clamp-2 drop-shadow-sm">
                                <?php echo htmlspecialchars($t['name']); ?>
                            </h3>
                            <div class="flex flex-wrap gap-1.5">
                                <?php foreach ($openCategories as $category): ?>
                                    <span class="px-2.5 py-1 rounded-full bg-brand-orange/15 border border-brand-orange/40 text-brand-orange text-[10px] font-bold">
                                        <?php echo htmlspecialchars($category['label'] ?: $category['category_code']); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <div class="grid grid-cols-2 gap-2 text-xs text-gray-300">
                                <span><i class="fa-solid fa-users text-brand-orange mr-1"></i><?php echo $t['play_mode'] === 'solo' ? 'Solo / คน' : 'Team / ทีม'; ?></span>
                                <span class="text-right"><i class="fa-regular fa-clock text-amber-400 mr-1"></i>ปิด <?php echo date('d/m/Y H:i', strtotime($t['registration_end'])); ?></span>
                                <span><i class="fa-solid fa-user-plus text-emerald-400 mr-1"></i>สมัคร <?php echo $totalRegistered; ?> / <?php echo $totalCapacity > 0 ? $totalCapacity : 'ไม่จำกัด'; ?></span>
                                <span class="text-right text-emerald-300"><?php echo $remainingCapacity === null ? 'ว่างไม่จำกัด' : 'ว่าง ' . $remainingCapacity; ?></span>
                            </div>
                        </div>

                        <div
                            class="pt-4 border-t border-white/15 flex items-center justify-between text-xs font-bold text-gray-200 uppercase tracking-wider">
                            <a href="<?php echo $isLoggedIn ? htmlspecialchars($registrationUrl) : htmlspecialchars($loginUrl); ?>"
                                class="w-full flex items-center justify-between text-brand-orange hover:text-white transition-colors">
                                <span><?php echo $hasRegistration ? 'ดูใบสมัครของฉัน' : 'สมัครแข่งขัน'; ?></span>
                                <i class="fa-solid fa-arrow-right group-hover:translate-x-2 transition-transform"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Cyber HUD Badge Divider -->
        <div class="hud-divider">
            <div class="hud-divider-badge">
                <span></span> LIVE STREAM & HIGHLIGHTS <span></span>
            </div>
        </div>

        <!-- ================= 5.5 YOUTUBE LIVE STREAM & HIGHLIGHTS ================= -->
        <?php
            $liveVideoId = 'l-QNkY2uZX8';
            $liveVideoTitle = 'TERMINAL 21 GAME FESTIVAL 2026 21/6/2569';
            $liveVideoDesc = 'Korat Esport Official Live Broadcast - ศึกชิงแชมป์ประจำฤดูกาล';
            $liveVideoSubDesc = 'ร่วมส่งเสียงเชียร์ทีมโปรดและรับชมการถ่ายทอดสดความคมชัดระดับ HD ได้ที่นี่';
            $youtubeChannelUrl = 'https://www.youtube.com/@koratesport';
            $highlightClips = [
                ['title' => 'TERMINAL 21 GAME FESTIVAL 2026 20/6/2569', 'video_id' => 'HmnPyAC3buY'],
                ['title' => 'Esport VLOG: เด็กโคราชไปชิงเหรียญถึงสกล', 'video_id' => 'O7p8OKiF5Fs'],
            ];
        ?>
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 w-full" data-aos="fade-up" data-aos-duration="900">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- ฝั่งซ้าย: การ์ดถ่ายทอดสดหลัก -->
                <div class="lg:col-span-2 glass-panel rounded-3xl border border-brand-orange/40 shadow-orange-glow overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-white/10">
                        <h3 class="text-sm font-bold font-display text-brand-orange uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-satellite-dish"></i> ถ่ายทอดสดการแข่งขันอีสปอร์ต
                        </h3>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-rose-500/25 border border-rose-400/60 text-rose-300 text-[10px] font-black uppercase tracking-widest">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-ping"></span> LIVE
                        </span>
                    </div>

                    <div class="relative aspect-video overflow-hidden bg-black">
                        <iframe
                            src="https://www.youtube.com/embed/<?php echo $liveVideoId; ?>"
                            title="<?php echo htmlspecialchars($liveVideoTitle); ?>"
                            class="w-full h-full"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            allowfullscreen>
                        </iframe>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5">
                        <div>
                            <h4 class="text-white font-bold text-base font-display"><?php echo htmlspecialchars($liveVideoTitle); ?></h4>
                            <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($liveVideoDesc); ?> — <?php echo htmlspecialchars($liveVideoSubDesc); ?></p>
                        </div>
                        <a href="<?php echo $youtubeChannelUrl; ?>" target="_blank" rel="noopener noreferrer"
                            class="shrink-0 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold uppercase tracking-wider transition-all shadow-lg">
                            <i class="fa-brands fa-youtube text-base"></i>
                            <span>ดูทาง YOUTUBE</span>
                        </a>
                    </div>
                </div>

                <!-- ฝั่งขวา: คลิปไฮไลต์ย้อนหลัง -->
                <div class="glass-panel rounded-3xl border border-white/15 shadow-xl overflow-hidden flex flex-col">
                    <div class="px-5 py-4 border-b border-white/10">
                        <h3 class="text-sm font-bold font-display text-white uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-clapperboard text-brand-orange"></i> คลิปไฮไลต์ย้อนหลัง
                        </h3>
                    </div>

                    <div class="p-3 space-y-2 flex-1">
                        <?php foreach ($highlightClips as $clip):
                            $clipLink = !empty($clip['video_id']) ? 'https://www.youtube.com/watch?v=' . $clip['video_id'] : $youtubeChannelUrl;
                            $clipThumb = !empty($clip['video_id']) ? 'https://img.youtube.com/vi/' . $clip['video_id'] . '/hqdefault.jpg' : 'https://placehold.co/300x180/1a1a1a/FF5500?text=Korat+Esport';
                        ?>
                            <a href="<?php echo $clipLink; ?>" target="_blank" rel="noopener noreferrer"
                                class="flex items-center gap-3 p-2 rounded-xl hover:bg-white/10 transition-all group">
                                <div class="relative w-20 h-14 rounded-lg overflow-hidden shrink-0 bg-black">
                                    <img src="<?php echo $clipThumb; ?>" alt="" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-opacity">
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <div class="w-6 h-6 rounded-full bg-rose-600/90 flex items-center justify-center">
                                            <i class="fa-solid fa-play text-white text-[9px] ml-0.5"></i>
                                        </div>
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs font-bold text-white line-clamp-2 leading-snug group-hover:text-brand-orange transition-colors"><?php echo htmlspecialchars($clip['title']); ?></p>
                                    <p class="text-[10px] text-gray-400 mt-1 flex items-center gap-1">
                                        <i class="fa-regular fa-clock"></i> Korat Esport Official
                                    </p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <div class="p-3 border-t border-white/10">
                        <a href="<?php echo $youtubeChannelUrl; ?>" target="_blank" rel="noopener noreferrer"
                            class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-white/10 hover:bg-brand-orange text-white text-xs font-bold uppercase tracking-wider transition-all border border-white/15">
                            <span>ดูวิดีโอทั้งหมดใน YOUTUBE</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>

            </div>
        </section>

        <!-- ================= 6. FOOTER ================= -->
        <footer class="border-t border-white/15 bg-slate-950/80 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
            <div
                class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col md:flex-row items-center justify-between gap-4 text-center md:text-left">
                <div>
                    <p class="text-gray-300 font-semibold">&copy; <?= date('Y') ?> KORAT ESPORT. All rights reserved.
                    </p>
                    <p class="text-[11px] text-gray-400 mt-1">
                        ศูนย์กลางข้อมูลข่าวสารและการแข่งขันอีสปอร์ตจังหวัดนครราชสีมา</p>
                </div>
                <div class="flex items-center gap-4 text-gray-300">
                    <a href="https://www.facebook.com/koratesport/" target="_blank" rel="noopener noreferrer" title="Facebook: Korat Esport" class="hover:text-brand-orange transition-colors"><i
                            class="fa-brands fa-facebook text-lg"></i></a>
                    <a href="https://www.youtube.com/@koratesport" target="_blank" rel="noopener noreferrer" title="YouTube: Korat Esport" class="hover:text-brand-orange transition-colors"><i
                            class="fa-brands fa-youtube text-lg"></i></a>
                </div>
            </div>
        </footer>

    </div>

    <!-- AOS JS Library -->
    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>

    <!-- Gamer SFX & Core Animations Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // อนุภาคไฟลอยหน้า Intro
            const introCanvas = document.getElementById('intro-particles');
            if (introCanvas) {
                const ictx = introCanvas.getContext('2d');
                let iw = introCanvas.width = window.innerWidth;
                let ih = introCanvas.height = window.innerHeight;
                window.addEventListener('resize', () => {
                    iw = introCanvas.width = window.innerWidth;
                    ih = introCanvas.height = window.innerHeight;
                });
                const introParticles = Array.from({ length: 30 }, () => ({
                    x: Math.random() * iw,
                    y: ih + Math.random() * 100,
                    size: Math.random() * 2 + 0.5,
                    speed: Math.random() * 0.6 + 0.2,
                    opacity: Math.random() * 0.5 + 0.2
                }));
                function animateIntroParticles() {
                    ictx.clearRect(0, 0, iw, ih);
                    introParticles.forEach(p => {
                        p.y -= p.speed;
                        if (p.y < -10) { p.y = ih + 10; p.x = Math.random() * iw; }
                        ictx.fillStyle = `rgba(255, 85, 0, ${p.opacity})`;
                        ictx.beginPath();
                        ictx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
                        ictx.fill();
                    });
                    if (document.getElementById('intro-screen')) requestAnimationFrame(animateIntroParticles);
                }
                animateIntroParticles();
            }

            // Init AOS
            AOS.init({
                once: true,
                duration: 800,
                easing: 'ease-out-cubic'
            });

            // Navbar Shrink Effect เมื่อเลื่อนลงมา > 50px
            const navbar = document.getElementById('main-navbar');
            window.addEventListener('scroll', () => {
                if (window.scrollY > 50) {
                    navbar.classList.add('shrink');
                } else {
                    navbar.classList.remove('shrink');
                }
            });

            // Cursor Spotlight Effect ตามเมาส์
            const spotlight = document.getElementById('cursor-spotlight');
            window.addEventListener('mousemove', (e) => {
                const x = e.clientX;
                const y = e.clientY;
                spotlight.style.background = `radial-gradient(600px circle at ${x}px ${y}px, rgba(255, 85, 0, 0.08), transparent 70%)`;
            });

            // Particles Canvas Engine
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
                    this.size = Math.random() * 2.5 + 0.5;
                    this.speedY = Math.random() * 0.8 + 0.2;
                    this.speedX = (Math.random() - 0.5) * 0.3;
                    this.opacity = Math.random() * 0.6 + 0.2;
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

            const particles = Array.from({ length: 45 }, () => new Particle());

            function animateParticles() {
                ctx.clearRect(0, 0, widthWin, heightWin);
                particles.forEach(p => {
                    p.update();
                    p.draw();
                });
                requestAnimationFrame(animateParticles);
            }
            animateParticles();

            // CountUp Animation Observer & Icon Pulse Sync
            const counters = document.querySelectorAll('[data-countup]');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const counter = entry.target;
                        const target = +counter.getAttribute('data-countup');
                        const cardContainer = counter.closest('.glass-card');
                        const icon = cardContainer ? cardContainer.querySelector('.stats-icon') : null;

                        let count = 0;
                        const increment = Math.max(1, Math.ceil(target / 25));

                        const updateCount = () => {
                            count += increment;
                            if (count < target) {
                                counter.innerText = count.toLocaleString();
                                setTimeout(updateCount, 25);
                            } else {
                                counter.innerText = target.toLocaleString();
                                if (icon) {
                                    icon.classList.add('icon-glow-active');
                                }
                            }
                        };
                        updateCount();
                        observer.unobserve(counter);
                    }
                });
            }, { threshold: 0.5 });

            counters.forEach(c => observer.observe(c));
        });

        // Function ปิด Intro เข้าสู่อารีนาทันทีเมื่อคลิกที่ใดก็ได้
        function enterArena() {
            const intro = document.getElementById('intro-screen');
            if (intro) {
                intro.style.opacity = '0';
                intro.style.transform = 'scale(1.08)';
                intro.style.pointerEvents = 'none';
                setTimeout(() => {
                    intro.style.display = 'none';
                }, 700);
            }
        }
    </script>
</body>

</html>