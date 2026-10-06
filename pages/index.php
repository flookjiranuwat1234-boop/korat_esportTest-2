<?php
// pages/index.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_demo.php';

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
      AND (
          t.is_demo = 1
          OR (t.registration_start <= :now AND t.registration_end >= :now)
      )
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
    <link rel="stylesheet" href="https://unpkg.com/aos@2.3.4/dist/aos.css" />

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
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
    <!-- Vanilla Tilt JS (เอฟเฟกต์การ์ด 3D ตามเมาส์) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/vanilla-tilt/1.8.1/vanilla-tilt.min.js" defer></script>

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
            will-change: contents;
        }

        html.intro-already-seen #intro-screen,
        #intro-screen.intro-dismissed {
            display: none !important;
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

        .mobile-public-nav {
            scrollbar-width: none;
        }

        .mobile-public-nav::-webkit-scrollbar {
            display: none;
        }

        .mobile-public-nav .nav-link-item {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        @media (max-width: 767px) {
            #main-navbar {
                background: transparent;
                box-shadow: none;
                backdrop-filter: none;
            }

            #main-navbar .max-w-7xl {
                padding-left: 0.75rem;
                padding-right: 0.75rem;
            }

            #main-navbar .max-w-7xl > div {
                min-height: 3.5rem;
                height: 3.5rem;
            }

            .mobile-public-nav {
                justify-content: flex-start;
                gap: 0.15rem;
            }

            .mobile-public-nav .nav-link-item {
                padding: 0.8rem 0.7rem;
                font-size: 0.78rem;
            }

            #main-navbar .mobile-public-nav {
                display: none;
            }

            #main-navbar .mobile-public-nav + #mobile-public-menu {
                top: 3.75rem;
            }
        }

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
        .tournament-slide-card {
            position: relative;
            border: 1px solid rgba(255, 85, 0, 0.55);
            background: linear-gradient(145deg, rgba(25, 24, 31, 0.98), rgba(8, 10, 16, 0.98));
            box-shadow: 0 0 16px rgba(255, 85, 0, 0.12), inset 0 0 22px rgba(255, 85, 0, 0.04);
            transition: transform 0.35s ease, border-color 0.35s ease, box-shadow 0.35s ease;
            flex: 0 0 100%;
            min-width: 0;
        }
        @media (min-width: 768px) {
            .tournament-slide-card { flex-basis: calc((100% - 1rem) / 2); }
        }
        @media (min-width: 1024px) {
            .tournament-slide-card { flex-basis: calc((100% - 2rem) / 3); }
        }
        .tournament-slide-card:hover {
            transform: translateY(-10px) scale(1.02);
            border-color: rgba(255, 85, 0, 0.9);
            box-shadow: 0 18px 38px rgba(255, 85, 0, 0.35), 0 0 28px rgba(255, 85, 0, 0.22), inset 0 0 22px rgba(255, 85, 0, 0.08);
        }
        .tournament-slide-card::after {
            content: '';
            position: absolute;
            inset: 0;
            padding: 1px;
            border-radius: inherit;
            background: linear-gradient(115deg, transparent 25%, rgba(255, 255, 255, 0.95) 45%, rgba(255, 140, 70, 0.85) 52%, transparent 72%);
            background-size: 250% 100%;
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            animation: tournamentFrameSweep 3.5s linear infinite;
        }
        @keyframes tournamentFrameGlow {
            0%, 100% { box-shadow: 0 0 10px rgba(255, 85, 0, 0.1), inset 0 0 14px rgba(255, 85, 0, 0.03); }
            50% { box-shadow: 0 0 20px rgba(255, 85, 0, 0.24), inset 0 0 18px rgba(255, 85, 0, 0.06); }
        }
        @keyframes tournamentFrameSweep {
            from { background-position: 150% 0; }
            to { background-position: -150% 0; }
        }
        .tournament-slide-card .group\/image {
            border-bottom: 1px solid rgba(255, 85, 0, 0.35);
        }
        .tournament-slide-card h3 {
            color: #ff6a1a;
            text-shadow: 0 0 14px rgba(255, 85, 0, 0.22);
        }
        .tournament-slide-card .rounded-2xl.border-white\/10 {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.025));
            border-color: rgba(255, 255, 255, 0.16);
            box-shadow: inset 0 0 16px rgba(255, 255, 255, 0.025);
        }
        .tournament-slide-card > div:last-child > a {
            box-shadow: 0 0 18px rgba(255, 85, 0, 0.28);
        }

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

        #promotion-banner {
            animation: promotionBannerEnter 0.7s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        @keyframes promotionBannerEnter {
            from {
                opacity: 0;
                transform: translateY(18px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            #promotion-banner {
                animation: none;
            }
        }
    </style>
    <script>
        (function () {
            try {
                const shouldShowIntro = new URLSearchParams(window.location.search).get('intro') === '1';
                const hasSeenIntro = window.sessionStorage.getItem('korat-esport-intro-seen-v3') === '1';
                if (shouldShowIntro) {
                    window.sessionStorage.removeItem('korat-esport-intro-seen-v3');
                } else if (hasSeenIntro) {
                    document.documentElement.classList.add('intro-already-seen');
                }
                if (shouldShowIntro) {
                    window.history.replaceState({}, document.title, window.location.pathname);
                }
            } catch (error) {
                // Keep the intro visible when browser storage is unavailable.
            }
        }());
    </script>
    <link rel="stylesheet" href="../assets/css/mobile-nav.css">
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

        <div class="text-center space-y-6 w-full max-w-[64rem] relative z-20">
            
            <?php if (count($tournaments) > 0): ?>
                <div id="intro-tournament-carousel" class="relative mx-auto mt-3 w-full max-w-[64rem] overflow-hidden" onclick="event.stopPropagation()">
                    <div class="intro-tournament-track flex items-stretch gap-3 will-change-transform">
                        <?php $isFirstIntroSlide = true; ?>
                        <?php foreach ($tournaments as $tournament): ?>
                            <?php $introRegistrationUrl = 'register-tournament.php?id=' . (int) $tournament['tournament_id']; ?>
                            <?php $introImageLoading = $isFirstIntroSlide ? 'fetchpriority="high" loading="eager"' : 'loading="lazy"'; ?>
                            <article class="intro-tournament-slide group relative min-w-0 shrink-0 basis-[78%] overflow-hidden rounded-2xl border border-white/30 bg-black/50 shadow-xl transition-[opacity,border-color,box-shadow,filter] duration-500 hover:brightness-110 sm:basis-[74%]">
                                <a href="<?php echo htmlspecialchars($introRegistrationUrl); ?>" class="block" onclick="event.stopPropagation()">
                                    <img src="<?php echo !empty($tournament['image_path']) ? '../assets/' . htmlspecialchars($tournament['image_path']) : 'https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=1000&auto=format&fit=crop'; ?>" alt="<?php echo htmlspecialchars($tournament['name']); ?>" class="aspect-video w-full object-cover" <?php echo $introImageLoading; ?>>
                                    <div class="pointer-events-none absolute inset-0 flex flex-col justify-end bg-gradient-to-t from-black/95 via-black/70 to-transparent p-4 text-left opacity-0 transition-opacity duration-300 group-hover:opacity-100 group-focus-within:opacity-100 sm:p-6">
                                        <div class="mb-1.5 flex items-center justify-between gap-2">
                                            <span class="truncate text-xs font-bold text-white sm:text-lg"><?php echo htmlspecialchars($tournament['name']); ?></span>
                                            <span class="shrink-0 rounded-full bg-emerald-500/30 px-2.5 py-0.5 text-[10px] font-bold text-emerald-100 sm:text-xs">เปิดรับสมัคร</span>
                                        </div>
                                        <p class="truncate text-[11px] text-orange-300 sm:text-sm"><?php echo htmlspecialchars($tournament['game_name']); ?></p>
                                    </div>
                                </a>
                            </article>
                            <?php $isFirstIntroSlide = false; ?>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($tournaments) > 1): ?>
                        <button type="button" class="intro-tournament-prev absolute left-2 top-1/2 z-20 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border border-white/30 bg-black/70 text-white hover:bg-brand-orange" aria-label="รายการก่อนหน้า" onclick="event.stopPropagation()"><i class="fa-solid fa-chevron-left"></i></button>
                        <button type="button" class="intro-tournament-next absolute right-2 top-1/2 z-20 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full border border-white/30 bg-black/70 text-white hover:bg-brand-orange" aria-label="รายการถัดไป" onclick="event.stopPropagation()"><i class="fa-solid fa-chevron-right"></i></button>
                        <div class="intro-tournament-dots absolute bottom-2 left-1/2 z-20 flex -translate-x-1/2 gap-1.5"></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="mt-5 space-y-2">
                <p class="font-display text-xl font-black tracking-wider text-white sm:text-3xl">KORAT <span class="text-brand-orange">ESPORT</span> SYSTEM</p>
                <p class="font-mono text-xs sm:text-sm text-brand-cyber uppercase tracking-widest animate-pulse font-bold drop-shadow">
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
        <header id="main-navbar" class="relative sticky top-0 z-50 glass-nav transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20 transition-all duration-300">

                    <!-- Logo & Brand Header -->
                    <a href="index.php?intro=1" class="hidden sm:flex items-center gap-3 group">
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

                    <button id="mobile-menu-toggle" type="button"
                        class="ml-auto flex h-10 w-10 items-center justify-center rounded-lg border border-white/20 text-white md:hidden"
                        aria-controls="mobile-public-menu" aria-expanded="false" aria-label="เปิดเมนู">
                        <i class="fa-solid fa-bars"></i>
                    </button>

                    <!-- Public Menu Items with Smooth Underline Indicator -->
                    <nav class="mobile-public-nav hidden items-center gap-1 md:flex md:w-auto md:justify-center lg:gap-3">
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

                    <nav id="mobile-public-menu"
                        class="absolute left-3 right-3 top-[4.25rem] hidden flex-col gap-1 rounded-xl border border-white/15 bg-[#121318]/95 p-2 shadow-2xl backdrop-blur-md md:hidden">
                        <a href="index.php" class="rounded-lg px-4 py-3 text-sm font-bold text-white">
                            <i class="fa-solid fa-house mr-2 text-xs"></i> หน้าแรก
                        </a>
                        <a href="tournaments.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-gray-200">
                            <i class="fa-solid fa-trophy mr-2 text-xs"></i> ทัวร์นาเมนต์
                        </a>
                        <a href="ranking.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-gray-200">
                            <i class="fa-solid fa-ranking-star mr-2 text-xs"></i> ตารางคะแนน
                        </a>
                        <a href="news.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-gray-200">
                            <i class="fa-solid fa-newspaper mr-2 text-xs"></i> ข่าวสาร
                        </a>
                        <a href="gallery.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-gray-200">
                            <i class="fa-solid fa-images mr-2 text-xs"></i> แกลเลอรี่
                        </a>
                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-gray-200">
                                <i class="fa-solid fa-hotel mr-2 text-xs"></i> ที่พักแนะนำ
                            </a>
                        <?php endif; ?>
                        <?php if ($isLoggedIn): ?>
                            <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                <a href="../admin/dashboard.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-brand-orange">
                                    <i class="fa-solid fa-user-shield mr-2 text-xs"></i> ระบบแอดมิน
                                </a>
                            <?php endif; ?>
                            <?php if (($currentUser['role'] ?? '') !== 'admin'): ?>
                                <a href="profile.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-gray-200">
                                    <i class="fa-solid fa-user mr-2 text-xs"></i> โปรไฟล์ของฉัน
                                </a>
                            <?php endif; ?>
                            <a href="../auth/logout.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-rose-300">
                                <i class="fa-solid fa-right-from-bracket mr-2 text-xs"></i> ออกจากระบบ
                            </a>
                        <?php else: ?>
                            <a href="../auth/login.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-brand-orange">
                                <i class="fa-solid fa-right-to-bracket mr-2 text-xs"></i> เข้าสู่ระบบ
                            </a>
                            <a href="../auth/register.php" class="rounded-lg px-4 py-3 text-sm font-semibold text-white">
                                <i class="fa-solid fa-user-plus mr-2 text-xs"></i> สมัครสมาชิก
                            </a>
                        <?php endif; ?>
                    </nav>

                    <!-- User Status / Auth Buttons -->
                    <div class="hidden md:flex items-center gap-4 text-base font-bold drop-shadow">
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

        <script>
            (function () {
                const toggle = document.getElementById('mobile-menu-toggle');
                const menu = document.getElementById('mobile-public-menu');
                if (!toggle || !menu) return;

                toggle.addEventListener('click', function () {
                    const isOpen = !menu.classList.contains('hidden');
                    menu.classList.toggle('hidden', isOpen);
                    menu.classList.toggle('flex', !isOpen);
                    toggle.setAttribute('aria-expanded', String(!isOpen));
                    toggle.setAttribute('aria-label', isOpen ? 'เปิดเมนู' : 'ปิดเมนู');
                    toggle.querySelector('i').className = isOpen ? 'fa-solid fa-bars' : 'fa-solid fa-xmark';
                });
            }());
        </script>

        <?php if ($banners): ?>
            <section id="promotion-banner" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-white flex items-center gap-2"><i class="fa-solid fa-bullhorn text-brand-orange"></i> ประชาสัมพันธ์</h2>
                    <span class="text-[10px] text-slate-400">ข่าวสารล่าสุดจาก Korat Esport</span>
                </div>
                <div class="relative overflow-hidden rounded-3xl border border-white/20 bg-slate-950/70 shadow-2xl">
                    <div class="promotion-banner-track flex transition-transform duration-700 ease-out">
                        <?php foreach ($banners as $bannerIndex => $banner): ?>
                            <article class="promotion-banner-slide relative min-w-full aspect-[16/7] overflow-hidden">
                                <img src="../assets/<?php echo htmlspecialchars($banner['image_path']); ?>" alt="<?php echo htmlspecialchars($banner['title'] ?: 'แบนเนอร์ประชาสัมพันธ์'); ?>" class="absolute inset-0 h-full w-full object-cover" loading="<?php echo $bannerIndex === 0 ? 'eager' : 'lazy'; ?>" fetchpriority="<?php echo $bannerIndex === 0 ? 'high' : 'auto'; ?>" decoding="async">
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($banners) > 1): ?>
                        <button type="button" class="promotion-banner-prev absolute left-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/30 bg-black/60 text-white transition hover:bg-brand-orange" aria-label="ประชาสัมพันธ์ก่อนหน้า"><i class="fa-solid fa-chevron-left"></i></button>
                        <button type="button" class="promotion-banner-next absolute right-3 top-1/2 z-10 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/30 bg-black/60 text-white transition hover:bg-brand-orange" aria-label="ประชาสัมพันธ์ถัดไป"><i class="fa-solid fa-chevron-right"></i></button>
                        <div class="promotion-banner-dots absolute bottom-4 left-1/2 flex -translate-x-1/2 gap-2" role="tablist" aria-label="ประชาสัมพันธ์"></div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- ================= 5. TOURNAMENTS SECTION ================= -->
        <section id="tournaments" data-tournament-count="<?php echo count($tournaments); ?>" class="tournament-carousel-section max-w-[1350px] mx-auto px-4 sm:px-6 lg:px-8 py-1 space-y-2 w-full">
            <div class="flex flex-col sm:flex-row sm:items-end justify-between border-b border-white/20 pb-2 gap-2"
                data-aos="fade-right">
                <div>
                    <span class="text-brand-orange font-bold text-xs uppercase tracking-widest block mb-1">                    รายการแข่งขัน</span>
                    <h2
                        class="text-3xl font-black font-display text-white uppercase tracking-wide flex items-center gap-3 drop-shadow">
                        <i class="fa-solid fa-fire text-brand-orange"></i>                         เปิดรับสมัครตอนนี้
                    </h2>
                </div>
            </div>

            <div class="relative">
                <div class="tournament-carousel-viewport w-full max-w-[1250px] mx-auto overflow-hidden rounded-2xl touch-pan-y" style="touch-action: pan-y;">
                    <div class="tournament-carousel-track flex items-stretch gap-4 transition-transform duration-500 ease-out">
                <?php if (count($tournaments) == 0): ?>
                    <div class="min-w-full glass-panel p-12 text-center text-gray-200 rounded-2xl" data-aos="fade-up">
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
                    <div class="min-w-full md:min-w-[calc(50%-0.75rem)] lg:min-w-[calc(33.333%-1rem)] overflow-hidden rounded-2xl border border-white/20 bg-[#0b0d14] flex flex-col group shadow-lg tournament-slide-card"
                        data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <a href="<?php echo htmlspecialchars($registrationUrl); ?>" class="relative block aspect-[16/9] overflow-hidden bg-black/50 group/image" aria-label="สมัครแข่งขัน <?php echo htmlspecialchars($t['name']); ?>">
                            <img src="<?php echo !empty($t['image_path']) ? '../assets/' . htmlspecialchars($t['image_path']) : 'https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=1000&auto=format&fit=crop'; ?>" alt="<?php echo htmlspecialchars($t['name']); ?>" class="h-full w-full object-cover transition-transform duration-500 group-hover/image:scale-105" loading="lazy" decoding="async">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-black/20"></div>
                            <span class="absolute left-3 top-3 rounded-full bg-pink-600 px-3 py-1 text-[10px] font-black text-white shadow-lg">กำลังรับสมัคร</span>
                            <?php if (!empty($t['prize_pool'])): ?>
                                <span class="absolute right-3 top-3 rounded-full bg-black/80 px-3 py-1 text-xs font-black text-amber-300 shadow-lg"><i class="fa-solid fa-trophy mr-1"></i><?php echo htmlspecialchars($t['prize_pool']); ?></span>
                            <?php endif; ?>
                            <span class="absolute bottom-3 left-3 rounded-md bg-cyan-400 px-2 py-1 text-[10px] font-black text-slate-950"><i class="fa-solid fa-gamepad mr-1"></i><?php echo htmlspecialchars($t['game_name']); ?> - <?php echo htmlspecialchars($openCategories[0]['label'] ?? 'โอเพ่น'); ?></span>
                        </a>
                        <div class="flex flex-1 flex-col gap-4 p-5 sm:p-6">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="truncate text-xl font-black text-white sm:text-2xl"><?php echo htmlspecialchars($t['name']); ?></h3>
                                <span class="shrink-0 rounded-full border border-emerald-400/60 bg-emerald-500/15 px-2 py-1 text-[10px] font-bold text-emerald-200">เปิด</span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                            <?php foreach ($openCategories as $category): ?>
                                <span class="rounded-full border border-brand-orange/60 bg-brand-orange/15 px-3 py-1 text-[10px] font-bold text-brand-orange"><?php echo htmlspecialchars($category['label'] ?: $category['category_code']); ?></span>
                            <?php endforeach; ?>
                            </div>
                            <div class="overflow-hidden rounded-2xl border border-white/10 bg-white/[0.03] text-xs text-gray-200">
                                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                                    <span><i class="fa-solid fa-shield-halved mr-2 text-orange-400"></i>โหมดการแข่งขัน</span>
                                    <strong><?php echo $t['play_mode'] === 'solo' ? 'SOLO' : 'TEAM'; ?></strong>
                                </div>
                                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                                    <span><i class="fa-regular fa-calendar-check mr-2 text-cyan-400"></i>วันแข่งขัน</span>
                                    <strong><?php echo !empty($t['start_date']) ? date('d/m/Y', strtotime($t['start_date'])) : '-'; ?></strong>
                                </div>
                                <div class="flex items-center justify-between border-b border-white/10 px-4 py-3">
                                    <span><i class="fa-solid fa-user-group mr-2 text-emerald-400"></i>ทีม/ผู้สมัคร</span>
                                    <strong><?php echo $totalRegistered; ?> / <?php echo $totalCapacity > 0 ? $totalCapacity : 'ไม่จำกัด'; ?></strong>
                                </div>
                                <div class="flex items-center justify-between px-4 py-3">
                                    <span><i class="fa-solid fa-bolt mr-2 text-amber-400"></i>รับสมัครถึง</span>
                                    <strong class="text-amber-300"><?php echo date('d/m/Y', strtotime($t['registration_end'])); ?></strong>
                                </div>
                            </div>
                            <a href="<?php echo htmlspecialchars($registrationUrl); ?>" class="mt-auto flex items-center justify-center gap-2 rounded-2xl bg-brand-orange px-4 py-3 text-sm font-black text-white transition hover:bg-orange-400 hover:shadow-[0_0_24px_rgba(255,85,0,0.55)]">
                                <i class="fa-solid fa-circle-info"></i>
                                <?php echo $hasRegistration ? 'ดูใบสมัครของฉัน' : 'ดูรายละเอียด'; ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
                    </div>
                </div>
                <?php if (count($tournaments) > 1): ?>
                    <button type="button" class="tournament-carousel-prev pointer-events-auto absolute left-1 top-1/2 z-30 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/30 bg-black/75 text-white transition hover:bg-brand-orange" aria-label="รายการก่อนหน้า">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <button type="button" class="tournament-carousel-next pointer-events-auto absolute right-1 top-1/2 z-30 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/30 bg-black/75 text-white transition hover:bg-brand-orange" aria-label="รายการถัดไป">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                    <div class="tournament-carousel-page mt-3 flex min-h-3 items-center justify-center gap-2 md:hidden" role="tablist" aria-label="รายการแข่งขัน"></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Cyber HUD Badge Divider -->
        <div class="hud-divider">
            <div class="hud-divider-badge">
                <span></span> สถิติการแข่งขัน <span></span>
            </div>
        </div>

        <!-- ================= 4. INFOGRAPHIC LIVE STATS STRIP ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 relative z-20 w-full">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-brand-orange relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="0" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">ทีม</span>
                        <i class="fa-solid fa-people-group text-brand-orange text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm" data-countup="<?php echo $totalTeams; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">ทีมสโมสรในระบบ</p>
                </div>

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-amber-400 relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="100" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">ผู้เล่น</span>
                        <i class="fa-solid fa-gamepad text-amber-400 text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm" data-countup="<?php echo $totalPlayers; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">นักกีฬาลงทะเบียน</p>
                </div>

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-purple-400 relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="200" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">รายการแข่งขัน</span>
                        <i class="fa-solid fa-trophy text-purple-400 text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm" data-countup="<?php echo $totalTournaments; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">รายการแข่งขันทั้งหมด</p>
                </div>

                <div class="glass-card p-6 rounded-2xl border-l-4 border-l-emerald-400 relative overflow-hidden group shadow-lg"
                    data-aos="fade-up" data-aos-delay="300" data-tilt data-tilt-glare data-tilt-max-glare="0.15">
                    <div class="flex items-center justify-between text-gray-200 mb-2">
                        <span class="text-xs font-bold uppercase tracking-wider">แมตช์</span>
                        <i class="fa-solid fa-bolt text-emerald-400 text-xl group-hover:scale-110 transition-transform stats-icon"></i>
                    </div>
                    <h3 class="text-3xl sm:text-4xl font-black font-display text-white drop-shadow-sm" data-countup="<?php echo $totalMatchesPlayed; ?>">0</h3>
                    <p class="text-xs text-gray-300 mt-1">แมตช์ที่สมบูรณ์แล้ว</p>
                </div>

            </div>
        </section>

        <!-- Cyber HUD Badge Divider -->
        <div class="hud-divider">
            <div class="hud-divider-badge">
                <span></span> ถ่ายทอดสดและวิดีโอการแข่งขัน <span></span>
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

                    <div class="relative aspect-video overflow-hidden bg-black" data-youtube-facade data-video-id="<?php echo htmlspecialchars($liveVideoId); ?>" data-video-title="<?php echo htmlspecialchars($liveVideoTitle); ?>">
                        <img src="https://img.youtube.com/vi/<?php echo htmlspecialchars($liveVideoId); ?>/hqdefault.jpg"
                            alt="<?php echo htmlspecialchars($liveVideoTitle); ?>"
                            class="h-full w-full object-cover opacity-80"
                            loading="lazy" decoding="async">
                        <button type="button" class="absolute left-1/2 top-1/2 flex h-16 w-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-rose-600 text-white shadow-[0_0_30px_rgba(225,29,72,0.65)] transition hover:scale-110 hover:bg-rose-500" data-youtube-play aria-label="เล่นวิดีโอ">
                            <i class="fa-solid fa-play ml-1 text-xl"></i>
                        </button>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5">
                        <div>
                            <h4 class="text-white font-bold text-base font-display"><?php echo htmlspecialchars($liveVideoTitle); ?></h4>
                            <p class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($liveVideoDesc); ?> — <?php echo htmlspecialchars($liveVideoSubDesc); ?></p>
                        </div>
                        <a href="<?php echo $youtubeChannelUrl; ?>" target="_blank" rel="noopener noreferrer"
                            class="shrink-0 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold uppercase tracking-wider transition-all shadow-lg">
                            <i class="fa-brands fa-youtube text-base"></i>
                            <span>ดูวิดีโอการแข่งขัน</span>
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
                            <span>ดูวิดีโอการแข่งขันทั้งหมด</span>
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
    <script src="https://unpkg.com/aos@2.3.4/dist/aos.js" defer></script>

    <!-- Gamer SFX & Core Animations Script -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (document.documentElement.classList.contains('intro-already-seen')) {
                const skippedIntro = document.getElementById('intro-screen');
                if (skippedIntro) skippedIntro.remove();
            }

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
            let scrollFrame = 0;
            let scrollStopTimer = 0;
            window.addEventListener('scroll', () => {
                if (scrollFrame) return;
                scrollFrame = requestAnimationFrame(() => {
                    navbar.classList.toggle('shrink', window.scrollY > 50);
                    scrollFrame = 0;
                });
                clearTimeout(scrollStopTimer);
                stopParticles();
                scrollStopTimer = window.setTimeout(startParticles, 140);
            }, { passive: true });

            // Cursor Spotlight Effect ตามเมาส์
            const spotlight = document.getElementById('cursor-spotlight');
            let spotlightFrame = 0;
            let spotlightX = 0;
            let spotlightY = 0;
            window.addEventListener('mousemove', (e) => {
                spotlightX = e.clientX;
                spotlightY = e.clientY;
                if (spotlightFrame) return;
                spotlightFrame = requestAnimationFrame(() => {
                    spotlight.style.background = `radial-gradient(600px circle at ${spotlightX}px ${spotlightY}px, rgba(255, 85, 0, 0.08), transparent 70%)`;
                    spotlightFrame = 0;
                });
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

            const particles = Array.from({ length: 25 }, () => new Particle());
            let particlesRunning = false;
            let particlesFrame = 0;

            function animateParticles() {
                if (!particlesRunning || document.hidden) {
                    particlesFrame = 0;
                    return;
                }
                ctx.clearRect(0, 0, widthWin, heightWin);
                particles.forEach(p => {
                    p.update();
                    p.draw();
                });
                particlesFrame = requestAnimationFrame(animateParticles);
            }
            function startParticles() {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                particlesRunning = true;
                if (!particlesFrame) particlesFrame = requestAnimationFrame(animateParticles);
            }
            function stopParticles() {
                particlesRunning = false;
                if (particlesFrame) {
                    cancelAnimationFrame(particlesFrame);
                    particlesFrame = 0;
                }
            }
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) stopParticles();
                else startParticles();
            });
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(startParticles, { timeout: 900 });
            } else {
                window.setTimeout(startParticles, 250);
            }

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

            document.querySelectorAll('[data-youtube-facade]').forEach(function (facade) {
                const playButton = facade.querySelector('[data-youtube-play]');
                if (!playButton) return;
                playButton.addEventListener('click', function () {
                    const videoId = facade.dataset.videoId;
                    const title = facade.dataset.videoTitle || 'YouTube video';
                    if (!videoId) return;
                    const iframe = document.createElement('iframe');
                    iframe.src = 'https://www.youtube.com/embed/' + encodeURIComponent(videoId) + '?autoplay=1';
                    iframe.title = title;
                    iframe.className = 'h-full w-full';
                    iframe.setAttribute('frameborder', '0');
                    iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
                    iframe.setAttribute('allowfullscreen', '');
                    facade.replaceChildren(iframe);
                });
            });
        });

        // Function ปิด Intro เข้าสู่อารีนาทันทีเมื่อคลิกที่ใดก็ได้
        function enterArena() {
            const intro = document.getElementById('intro-screen');
            if (intro && !intro.classList.contains('intro-leaving')) {
                intro.classList.add('intro-leaving');
                try {
                    window.sessionStorage.setItem('korat-esport-intro-seen-v3', '1');
                } catch (error) {
                    // The transition still works when browser storage is unavailable.
                }
                requestAnimationFrame(function () {
                    intro.style.opacity = '0';
                    intro.style.transform = 'scale(1.03)';
                    intro.style.pointerEvents = 'none';
                });
                setTimeout(function () {
                    intro.style.display = 'none';
                }, 420);
            }
        }
    </script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const introTournament = document.getElementById('intro-tournament-carousel');
        if (introTournament && !document.documentElement.classList.contains('intro-already-seen')) {
            const track = introTournament.querySelector('.intro-tournament-track');
            const originalSlides = Array.from(introTournament.querySelectorAll('.intro-tournament-slide'));
            const dots = introTournament.querySelector('.intro-tournament-dots');
            const previous = introTournament.querySelector('.intro-tournament-prev');
            const next = introTournament.querySelector('.intro-tournament-next');
            const slides = originalSlides;
            let current = 0;

            if (track && originalSlides.length > 1 && dots && previous && next) {
                originalSlides.forEach(function (_, index) {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'h-1.5 w-1.5 rounded-full bg-white/50 transition-all';
                    dot.setAttribute('aria-label', 'ไปยังประชาสัมพันธ์ที่ ' + (index + 1));
                    dot.addEventListener('click', function () {
                        current = index;
                        updateIntroPromo();
                    });
                    dots.appendChild(dot);
                });

                function updateIntroPromo() {
                    const active = slides[current];
                    if (!active) return;

                    const offset = (introTournament.clientWidth / 2)
                        - (active.offsetLeft + (active.offsetWidth / 2));

                    track.style.transition = 'transform 0.65s cubic-bezier(0.16, 1, 0.3, 1)';
                    track.style.transform = 'translateX(' + offset + 'px)';

                    slides.forEach(function (slide, index) {
                        const isActive = index === current;
                        slide.classList.toggle('border-brand-orange', isActive);
                        slide.classList.toggle('shadow-[0_0_28px_rgba(255,85,0,0.45)]', isActive);
                        slide.classList.toggle('opacity-100', isActive);
                        slide.classList.toggle('opacity-60', !isActive);
                        slide.classList.toggle('brightness-110', isActive);
                        slide.classList.toggle('brightness-75', !isActive);
                    });

                    Array.from(dots.children).forEach(function (dot, index) {
                        dot.classList.toggle('bg-brand-orange', index === current);
                        dot.classList.toggle('scale-125', index === current);
                    });
                }

                previous.addEventListener('click', function () {
                    current = (current - 1 + slides.length) % slides.length;
                    updateIntroPromo();
                });
                next.addEventListener('click', function () {
                    current = (current + 1) % slides.length;
                    updateIntroPromo();
                });

                if ('ResizeObserver' in window) {
                    const resizeObserver = new ResizeObserver(function () {
                        updateIntroPromo();
                    });
                    resizeObserver.observe(introTournament);
                }

                window.addEventListener('resize', updateIntroPromo);
                updateIntroPromo();
            }
        }

        const section = document.getElementById('tournaments');
        const header = document.querySelector('header');
        const track = section ? section.querySelector('.tournament-carousel-track') : null;
        const slides = track ? Array.from(track.children) : [];
        const previous = section ? section.querySelector('.tournament-carousel-prev') : null;
        const next = section ? section.querySelector('.tournament-carousel-next') : null;
        const pageIndicator = section ? section.querySelector('.tournament-carousel-page') : null;
        const tournamentCount = section ? Number(section.dataset.tournamentCount || 0) : 0;
        const viewport = section ? section.querySelector('.tournament-carousel-viewport') : null;
        let currentSlide = 0;

        if (!section || !header || !track || slides.length === 0) return;

        if (tournamentCount === 0) {
            if (previous) previous.hidden = true;
            if (next) next.hidden = true;
            if (pageIndicator) pageIndicator.hidden = true;
            return;
        }

        function getCardsPerPage() {
            if (window.matchMedia('(min-width: 1024px)').matches) return 3;
            if (window.matchMedia('(min-width: 768px)').matches) return 2;
            return 1;
        }

        function updatePageDots() {
            if (!pageIndicator) return;
            pageIndicator.innerHTML = '';
            const pageCount = Math.max(1, Math.ceil(slides.length / getCardsPerPage()));
            for (let index = 0; index < pageCount; index += 1) {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'tournament-carousel-dot w-2 h-2 rounded-full bg-white/40 hover:bg-white/80 transition-all';
                dot.setAttribute('role', 'tab');
                dot.setAttribute('aria-label', 'ไปยังหน้าที่ ' + (index + 1));
                dot.addEventListener('click', function () {
                    currentSlide = index;
                    updateCarousel();
                });
                pageIndicator.appendChild(dot);
            }
        }

        function updateCarousel() {
            const cardsPerPage = getCardsPerPage();
            const pageCount = Math.max(1, Math.ceil(slides.length / cardsPerPage));
            currentSlide = Math.min(currentSlide, pageCount - 1);
            const firstSlide = slides[currentSlide * cardsPerPage];
            track.style.transform = 'translateX(-' + (firstSlide ? firstSlide.offsetLeft : 0) + 'px)';
            updatePageDots();
            if (pageIndicator) {
                Array.from(pageIndicator.children).forEach(function (dot, index) {
                    const isActive = index === currentSlide;
                    dot.classList.toggle('bg-brand-orange', isActive);
                    dot.classList.toggle('bg-white/40', !isActive);
                    dot.classList.toggle('scale-125', isActive);
                    dot.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });
            }
        }

        if (previous) {
            const goPrevious = function (event) {
                if (event.type === 'click' && previous.dataset.pointerHandled === 'true') {
                    previous.dataset.pointerHandled = 'false';
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                const pageCount = Math.max(1, Math.ceil(slides.length / getCardsPerPage()));
                currentSlide = (currentSlide - 1 + pageCount) % pageCount;
                updateCarousel();
            };
            previous.addEventListener('click', goPrevious);
            previous.addEventListener('pointerup', function (event) {
                if (event.pointerType !== 'mouse') {
                    previous.dataset.pointerHandled = 'true';
                    goPrevious(event);
                }
            });
        }
        if (next) {
            const goNext = function (event) {
                if (event.type === 'click' && next.dataset.pointerHandled === 'true') {
                    next.dataset.pointerHandled = 'false';
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                const pageCount = Math.max(1, Math.ceil(slides.length / getCardsPerPage()));
                currentSlide = (currentSlide + 1) % pageCount;
                updateCarousel();
            };
            next.addEventListener('click', goNext);
            next.addEventListener('pointerup', function (event) {
                if (event.pointerType !== 'mouse') {
                    next.dataset.pointerHandled = 'true';
                    goNext(event);
                }
            });
        }
        if (viewport) {
            let swipeDetected = false;
            let pointerActive = false;
            let pointerStartX = 0;
            let pointerStartY = 0;

            function moveCarouselFromDelta(deltaX, deltaY) {
                if (Math.abs(deltaX) < 40 || Math.abs(deltaX) <= Math.abs(deltaY)) return;
                swipeDetected = true;
                const pageCount = Math.max(1, Math.ceil(slides.length / getCardsPerPage()));
                currentSlide = deltaX < 0
                    ? (currentSlide + 1) % pageCount
                    : (currentSlide - 1 + pageCount) % pageCount;
                updateCarousel();
            }

            viewport.addEventListener('pointerdown', function (event) {
                if (event.pointerType === 'mouse') return;
                pointerActive = true;
                pointerStartX = event.clientX;
                pointerStartY = event.clientY;
                swipeDetected = false;
                viewport.setPointerCapture?.(event.pointerId);
            });
            viewport.addEventListener('pointerup', function (event) {
                if (!pointerActive || event.pointerType === 'mouse') return;
                pointerActive = false;
                moveCarouselFromDelta(event.clientX - pointerStartX, event.clientY - pointerStartY);
            });
            viewport.addEventListener('pointercancel', function () {
                pointerActive = false;
            });
            viewport.addEventListener('click', function (event) {
                if (!swipeDetected) return;
                event.preventDefault();
                event.stopPropagation();
                swipeDetected = false;
            }, true);
        }
        window.addEventListener('resize', updateCarousel);
        updateCarousel();

        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            slides.forEach(function (card) {
                card.addEventListener('mousemove', function (event) {
                    const bounds = card.getBoundingClientRect();
                    const rotateX = ((event.clientY - bounds.top) / bounds.height - 0.5) * -8;
                    const rotateY = ((event.clientX - bounds.left) / bounds.width - 0.5) * 8;
                    card.style.transform = 'perspective(900px) translateY(-10px) scale(1.02) rotateX(' + rotateX.toFixed(2) + 'deg) rotateY(' + rotateY.toFixed(2) + 'deg)';
                });
                card.addEventListener('mouseleave', function () {
                    card.style.transform = '';
                });
            });
        }
    });
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const banner = document.getElementById('promotion-banner');
    if (!banner) return;
    const track = banner.querySelector('.promotion-banner-track');
    const slides = track ? Array.from(track.children) : [];
    const dots = banner.querySelector('.promotion-banner-dots');
    const previous = banner.querySelector('.promotion-banner-prev');
    const next = banner.querySelector('.promotion-banner-next');
    let current = 0;

    if (!track || slides.length < 2) return;

    slides.forEach(function (_, index) {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'h-2 w-2 rounded-full bg-white/50 transition-all hover:bg-white';
        dot.setAttribute('aria-label', 'ไปยังประชาสัมพันธ์ที่ ' + (index + 1));
        dot.addEventListener('click', function () {
            current = index;
            update();
        });
        dots.appendChild(dot);
    });

    function update() {
        track.style.transform = 'translateX(-' + (current * 100) + '%)';
        Array.from(dots.children).forEach(function (dot, index) {
            const active = index === current;
            dot.classList.toggle('bg-brand-orange', active);
            dot.classList.toggle('scale-125', active);
            dot.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    previous.addEventListener('click', function () {
        current = (current - 1 + slides.length) % slides.length;
        update();
    });
    next.addEventListener('click', function () {
        current = (current + 1) % slides.length;
        update();
    });
    update();
});
</script>
<script src="../assets/js/flash-messages.js" defer></script>
</body>

</html>