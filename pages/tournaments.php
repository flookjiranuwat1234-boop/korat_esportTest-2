<?php
// pages/tournaments.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_categories.php';
ensureTournamentCategorySchema($pdo);

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

// ดึงรายการทัวร์นาเมนต์พร้อมชื่อเกมและรูปแบบการแข่งขันจากตาราง games
try {
    $tournaments = $pdo->query("
        SELECT t.*, t.name AS title, g.name AS game_name, g.play_mode,
            (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id) AS registered_count,
            (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.status = 'approved') AS approved_count,
            (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.status = 'approved'
                AND EXISTS (SELECT 1 FROM tournament_registration_members req WHERE req.tournament_registration_id = tr.tournament_registration_id AND req.is_required_for_checkin = 1)
                AND NOT EXISTS (SELECT 1 FROM tournament_registration_members waiting WHERE waiting.tournament_registration_id = tr.tournament_registration_id AND waiting.is_required_for_checkin = 1 AND waiting.checkin_status NOT IN ('checked_in', 'waived'))) AS checkin_complete_count,
            (SELECT COUNT(*) FROM matches m WHERE m.tournament_id = t.tournament_id) AS match_count,
            (SELECT COUNT(*) FROM matches m WHERE m.tournament_id = t.tournament_id AND m.status IN ('completed', 'walkover')) AS completed_match_count
        FROM tournaments t
        LEFT JOIN games g ON g.game_id = t.game_id
        ORDER BY t.start_date DESC
    ")->fetchAll();
} catch (Exception $e) {
    // Fallback หากโครงสร้างตารางแตกต่างออกไป
    $tournaments = $pdo->query("SELECT *, name AS title FROM tournaments ORDER BY start_date DESC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทัวร์นาเมนต์การแข่งขัน - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&family=Share+Tech+Mono&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- AOS CSS (สำหรับ stagger fade-up การ์ดทัวร์นาเมนต์) -->
    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Vanilla Tilt JS (เอฟเฟกต์การ์ดเอียง 3D ตามตำแหน่งเมาส์) -->
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
                        'orange-glow': '0 0 35px rgba(255, 85, 0, 0.6)',
                        'cyber-glow': '0 0 25px rgba(255, 85, 0, 0.4)',
                        'neon-border': '0 0 15px rgba(255, 85, 0, 0.3)'
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
            background-color: #08090C;
            color: #f3f4f6;
        }

        @keyframes kenBurnsArena {
            0% { transform: scale(1); }
            50% { transform: scale(1.08); }
            100% { transform: scale(1); }
        }

        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(8, 9, 12, 0.65), rgba(8, 9, 12, 0.95)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            animation: kenBurnsArena 24s ease-in-out infinite;
        }

        .glass-nav {
            background: rgba(10, 10, 14, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 85, 0, 0.4);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);
        }

        .glass-panel {
            background: rgba(20, 21, 28, 0.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .grid-bg {
            background-image: radial-gradient(rgba(255, 85, 0, 0.2) 1px, transparent 0);
            background-size: 32px 32px;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulseGlow {
            0%, 100% {
                box-shadow: 0 0 20px rgba(255, 85, 0, 0.4);
            }
            50% {
                box-shadow: 0 0 40px rgba(255, 85, 0, 0.8);
            }
        }

        .animate-fade-down {
            animation: fadeInDown 0.9s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .animate-fade-up {
            animation: fadeInUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards;
            opacity: 0;
        }

        .animate-pulse-glow {
            animation: pulseGlow 3s infinite;
        }

        @keyframes badgePopIn {
            0% { opacity: 0; transform: scale(0.7); }
            70% { opacity: 1; transform: scale(1.08); }
            100% { opacity: 1; transform: scale(1); }
        }
        .badge-pop-in {
            animation: badgePopIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) 0.4s backwards;
        }

        /* Ultra Cyberpunk Tournament Card */
        .tournament-card {
            background: linear-gradient(145deg, rgba(18, 19, 26, 0.92), rgba(10, 10, 14, 0.98));
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        .tournament-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 85, 0, 0.15), transparent);
            transition: 0.7s;
        }

        .tournament-card:hover::before {
            left: 100%;
        }

        .tournament-card:hover {
            transform: translateY(-8px) scale(1.015);
            border-color: #FF5500;
            box-shadow: 0 25px 50px -12px rgba(255, 85, 0, 0.5), inset 0 0 20px rgba(255, 85, 0, 0.2);
        }

        .tournament-card img {
            transition: transform 0.7s cubic-bezier(0.16, 1, 0.3, 1), filter 0.5s ease;
            filter: brightness(0.85);
        }

        .tournament-card:hover img {
            transform: scale(1.08);
            filter: brightness(1.05);
        }

        #cursor-spotlight {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
            transition: background 0.1s ease;
        }
    </style>
</head>

<body class="font-sans min-h-screen overflow-x-hidden antialiased">

    <!-- Background Arena + Grid Layer -->
    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>
    <!-- Cursor Spotlight Effect -->
    <div id="cursor-spotlight"></div>

    <div class="relative z-10 flex flex-col min-h-screen">

        <!-- ================= 1. PUBLIC NAVIGATION BAR ================= -->
        <header class="sticky top-0 z-50 glass-nav transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">

                    <!-- Logo & Brand Header -->
                    <a href="index.php" class="flex items-center gap-3 group">
                        <img src="../assets/img/logo.png" alt="Korat Esport"
                            class="h-11 w-auto filter drop-shadow-[0_2px_12px_rgba(255,85,0,0.6)] group-hover:scale-110 transition-transform"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span
                                class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors drop-shadow-lg">KORAT
                                <span class="text-brand-orange">ESPORT</span></span>
                            <span
                                class="block text-[10px] tracking-widest text-gray-400 font-bold uppercase -mt-1">Official
                                Arena & Hub</span>
                        </div>
                    </a>

                    <!-- Public Menu Items -->
                    <nav class="hidden md:flex items-center gap-1 lg:gap-2">
                        <a href="index.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-house text-xs mr-1.5"></i> หน้าแรก
                        </a>
                        <a href="tournaments.php"
                            class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange transition-all shadow-orange-glow animate-pulse-glow">
                            <i class="fa-solid fa-trophy text-xs mr-1.5"></i> ทัวร์นาเมนต์
                        </a>
                        <a href="ranking.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-ranking-star text-xs mr-1.5"></i> ตารางคะแนน
                        </a>
                        <a href="news.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-newspaper text-xs mr-1.5"></i> ข่าวสาร
                        </a>
                        <a href="gallery.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-images text-xs mr-1.5"></i> แกลเลอรี่
                        </a>

                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                                <i class="fa-solid fa-hotel text-xs mr-1.5"></i> ที่พักแนะนำ
                            </a>
                        <?php endif; ?>
                    </nav>

                    <!-- User Status / Auth Buttons -->
                    <div class="flex items-center gap-4 text-base font-bold">
                        <?php if ($isLoggedIn): ?>
                            <div
                                class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md shadow-cyber-glow">
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
                            <a href="../auth/login.php"
                                class="text-brand-orange hover:text-brand-glow transition-colors">เข้าสู่ระบบ</a>
                            <a href="../auth/register.php"
                                class="text-white hover:text-brand-orange transition-colors">สมัครสมาชิก</a>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </header>

        <!-- ================= 2. PAGE HEADER (Animated Text with Cyber Glow) ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-8 w-full text-center space-y-5">
            <div
                class="inline-flex items-center gap-2 px-5 py-2 rounded-full bg-brand-orange/20 border border-brand-orange/60 text-brand-orange text-xs font-bold uppercase tracking-widest backdrop-blur-md animate-fade-down shadow-orange-glow">
                <i class="fa-solid fa-bolt text-amber-300 animate-bounce"></i> Official Cyber Arena Tournaments
            </div>

            <h1
                class="text-4xl sm:text-7xl font-black font-display text-white tracking-wider uppercase leading-none drop-shadow-[0_0_40px_rgba(255,85,0,0.9)] animate-fade-down">
                ทัวร์นาเมนต์การแข่งขัน <span
                    class="text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-400 to-white">(BATTLEGROUNDS)</span>
            </h1>

            <p class="text-sm sm:text-base text-gray-300 max-w-2xl mx-auto font-normal animate-fade-up leading-relaxed">
                เตรียมทีมของคุณให้พร้อมแล้วก้าวเข้าสู่สมรภูมิอีสปอร์ตระดับจังหวัด ชิงเงินรางวัลและเกียรติยศสูงสุดแห่ง
                Korat Esport
            </p>
        </section>

        <!-- ================= 3. TOURNAMENTS GRID SECTION ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 mb-24 w-full">
            <?php if (empty($tournaments)): ?>
                <div
                    class="glass-panel p-20 text-center text-gray-300 rounded-3xl max-w-xl mx-auto border border-brand-orange/40 shadow-orange-glow animate-fade-up">
                    <i class="fa-solid fa-trophy text-6xl mb-4 block text-brand-orange animate-bounce"></i>
                    <h3 class="text-2xl font-bold font-display text-white mb-2">ยังไม่มีทัวร์นาเมนต์เปิดแข่งขัน</h3>
                    <p class="text-xs text-gray-400">โปรดรอติดตามประกาศเปิดรับสมัครการแข่งขันใหม่ๆ จากผู้จัดเร็วๆ นี้</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($tournaments as $tIndex => $t):
                        $imgSrc = !empty($t['image_path']) ? '../assets/' . htmlspecialchars($t['image_path']) : 'https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=1000&auto=format&fit=crop';
                        $status = $t['status'] ?? 'upcoming';
                        $tId = $t['tournament_id'] ?? ($t['id'] ?? 0);
                        $tTitle = $t['title'] ?? 'ทัวร์นาเมนต์อีสปอร์ต';
                        $tPrize = $t['prize_pool'] ?? 0;
                        $tGame = !empty($t['game_name']) ? $t['game_name'] : 'Arena of Valor (RoV)';
                        $tMode = !empty($t['play_mode']) ? $t['play_mode'] : 'ทีม (Team 5v5)';
                        $tStartDate = !empty($t['start_date']) ? date('d/m/Y', strtotime($t['start_date'])) : '-';
                        $tEndDate = !empty($t['end_date']) ? date('d/m/Y', strtotime($t['end_date'])) : '-';
                        ensureDefaultTournamentCategories($pdo, (int) $tId);
                        $categoryStmt = $pdo->prepare('SELECT category_code, label FROM tournament_categories WHERE tournament_id = :tournament_id AND is_active = 1 ORDER BY tournament_category_id');
                        $categoryStmt->execute(['tournament_id' => $tId]);
                        $categories = $categoryStmt->fetchAll();
                        $staggerDelay = min($tIndex * 100, 800);
                        ?>
                        <div class="tournament-card rounded-3xl overflow-hidden flex flex-col justify-between shadow-2xl group"
                            data-aos="zoom-in-up" data-aos-delay="<?php echo $staggerDelay; ?>" data-aos-duration="700"
                            data-tilt data-tilt-max="8" data-tilt-glare data-tilt-max-glare="0.2" data-tilt-scale="1.02">
                            <div>
                                <!-- Image & Cyber Badges -->
                                <div class="aspect-video relative overflow-hidden bg-black/90">
                                    <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($tTitle); ?>"
                                        class="w-full h-full object-cover">

                                    <div
                                        class="absolute inset-0 bg-gradient-to-t from-[#0A0A0C] via-black/40 to-transparent opacity-90">
                                    </div>

                                    <!-- ป้ายสถานะทัวร์นาเมนต์ -->
                                    <div class="absolute top-4 left-4 z-10 badge-pop-in">
                                        <?php if ($status === 'ongoing' || $status === 'active'): ?>
                                            <span
                                                class="px-3.5 py-1.5 rounded-full bg-rose-600/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest shadow-[0_0_15px_rgba(225,29,72,0.6)] flex items-center gap-1.5 border border-rose-400">
                                                <span class="w-2 h-2 rounded-full bg-white animate-ping"></span> กำลังแข่งขัน (LIVE)
                                            </span>
                                        <?php elseif ($status === 'completed'): ?>
                                            <span
                                                class="px-3.5 py-1.5 rounded-full bg-slate-800/90 backdrop-blur-md text-gray-300 text-[10px] font-black uppercase tracking-widest shadow-lg border border-white/10">
                                                <i class="fa-solid fa-flag-checkered mr-1"></i> จบการแข่งขันแล้ว
                                            </span>
                                        <?php elseif ($status === 'bracket_generated'): ?>
                                            <span class="px-3.5 py-1.5 rounded-full bg-sky-600/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest border border-sky-300"><i class="fa-solid fa-sitemap mr-1"></i> จัดสายแล้ว</span>
                                        <?php elseif ($status === 'checkin_open'): ?>
                                            <span class="px-3.5 py-1.5 rounded-full bg-amber-500/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest border border-amber-300"><i class="fa-solid fa-user-check mr-1"></i> เปิด Check-in</span>
                                        <?php else: ?>
                                            <span
                                                class="px-3.5 py-1.5 rounded-full bg-brand-orange/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest shadow-orange-glow flex items-center gap-1.5 border border-amber-300">
                                                <i class="fa-solid fa-door-open text-amber-200"></i> เปิดรับสมัคร
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- ป้ายเงินรางวัล (Prize Pool) -->
                                    <?php if (!empty($tPrize)): ?>
                                        <div
                                            class="absolute top-4 right-4 bg-black/85 backdrop-blur-md text-amber-400 text-xs font-black px-3 py-1.5 rounded-xl border border-amber-400/50 shadow-[0_0_15px_rgba(245,158,11,0.3)] font-display flex items-center gap-1.5">
                                            <i class="fa-solid fa-trophy text-amber-300"></i> ฿<?php echo is_numeric($tPrize) ? number_format($tPrize) : htmlspecialchars($tPrize); ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- ชื่อเกมบนภาพ -->
                                    <div class="absolute bottom-3 left-4 right-4 flex items-center justify-between">
                                        <span class="px-2.5 py-1 rounded-lg bg-black/75 border border-white/20 text-brand-cyber text-[10px] font-bold uppercase tracking-wider backdrop-blur-sm">
                                            <i class="fa-solid fa-gamepad mr-1"></i> <?php echo htmlspecialchars($tGame); ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- รายละเอียดเนื้อหาของการ์ด -->
                                <div class="p-6 space-y-4">
                                    <h3
                                        class="text-xl font-black text-white group-hover:text-brand-orange transition-colors font-display line-clamp-2 leading-snug drop-shadow">
                                        <?php echo htmlspecialchars($tTitle); ?>
                                    </h3>

                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach ($categories as $category): ?>
                                            <?php $categoryClass = $category['category_code'] === 'female' ? 'bg-pink-500/15 border-pink-400/40 text-pink-200' : ($category['category_code'] === 'male' ? 'bg-blue-500/15 border-blue-400/40 text-blue-200' : 'bg-brand-orange/15 border-brand-orange/40 text-brand-orange'); ?>
                                            <span class="px-2.5 py-1 rounded-full border text-[10px] font-bold <?php echo $categoryClass; ?>"><?php echo htmlspecialchars($category['label']); ?></span>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- กล่องสเปกข้อมูลทัวร์นาเมนต์แบบ Cyber HUD Strip -->
                                    <div class="p-3.5 rounded-2xl bg-white/[0.04] border border-white/10 space-y-2 text-xs">
                                        <div class="flex items-center justify-between text-gray-300">
                                            <span class="text-gray-400 flex items-center gap-1.5 text-[11px]">
                                                <i class="fa-solid fa-shield-halved text-brand-orange"></i> โหมดการแข่ง:
                                            </span>
                                            <span class="font-bold text-white uppercase text-[11px]">
                                                <?php echo htmlspecialchars($tMode); ?>
                                            </span>
                                        </div>

                                        <div class="flex items-center justify-between text-gray-300 pt-1.5 border-t border-white/5">
                                            <span class="text-gray-400 flex items-center gap-1.5 text-[11px]">
                                                <i class="fa-regular fa-calendar-check text-brand-cyber"></i> วันเปิดรับสมัคร:
                                            </span>
                                            <span class="font-mono text-gray-200 text-[11px]">
                                                <?php echo $tStartDate; ?>
                                            </span>
                                        </div>

                                        <div class="flex items-center justify-between text-gray-300 pt-1.5 border-t border-white/5">
                                            <span class="text-gray-400 text-[11px]"><i class="fa-solid fa-users text-brand-orange mr-1"></i> สมัคร / อนุมัติ</span>
                                            <span class="font-mono text-gray-200 text-[11px]"><?php echo (int) $t['registered_count']; ?> / <?php echo (int) $t['approved_count']; ?></span>
                                        </div>
                                        <div class="flex items-center justify-between text-gray-300 pt-1.5 border-t border-white/5">
                                            <span class="text-gray-400 text-[11px]"><i class="fa-solid fa-user-check text-emerald-400 mr-1"></i> Check-in / Match</span>
                                            <span class="font-mono text-gray-200 text-[11px]"><?php echo (int) $t['checkin_complete_count']; ?> / <?php echo (int) $t['completed_match_count']; ?>-<?php echo (int) $t['match_count']; ?></span>
                                        </div>

                                        <div class="flex items-center justify-between text-gray-300 pt-1.5 border-t border-white/5">
                                            <span class="text-gray-400 flex items-center gap-1.5 text-[11px]">
                                                <i class="fa-solid fa-bolt text-amber-400"></i> วันเริ่มแข่งขัน:
                                            </span>
                                            <span class="font-mono font-bold text-amber-300 text-[11px]">
                                                <?php echo $tStartDate; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ปุ่มกดย้ายไปหน้าทัวร์นาเมนต์ -->
                            <div class="p-6 pt-0">
                                <a href="tournament-detail.php?id=<?php echo $tId; ?>"
                                    class="w-full py-3.5 px-5 rounded-2xl bg-brand-orange hover:bg-brand-glow text-white text-xs font-bold uppercase tracking-widest flex items-center justify-between transition-all group/btn shadow-orange-glow">
                                    <span class="flex items-center gap-2">
                                        <i class="fa-solid fa-circle-info text-amber-200"></i>
                                        ดูรายละเอียด / สมัครแข่ง
                                    </span>
                                    <i class="fa-solid fa-arrow-right group-hover/btn:translate-x-1.5 transition-transform"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ================= 4. FOOTER ================= -->
        <footer class="border-t border-white/15 bg-slate-950/90 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
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
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            AOS.init({
                once: true,
                duration: 800,
                easing: 'ease-out-cubic'
            });

            // Cursor Spotlight ตามเมาส์
            const spotlight = document.getElementById('cursor-spotlight');
            window.addEventListener('mousemove', (e) => {
                const x = e.clientX;
                const y = e.clientY;
                spotlight.style.background = `radial-gradient(600px circle at ${x}px ${y}px, rgba(255, 85, 0, 0.08), transparent 70%)`;
            });

            // เปิดใช้งาน Vanilla Tilt กับการ์ดทัวร์นาเมนต์ทุกใบ
            if (window.VanillaTilt) {
                VanillaTilt.init(document.querySelectorAll('[data-tilt]'));
            }
        });
    </script>
</body>

</html>