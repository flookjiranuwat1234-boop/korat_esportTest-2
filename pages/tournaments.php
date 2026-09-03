<?php
// pages/tournaments.php
require_once '../config/db.php';
require_once '../includes/auth.php';

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$requestedView = strtolower(trim((string) ($_GET['view'] ?? 'current')));
$view = in_array($requestedView, ['current', 'completed'], true) ? $requestedView : 'current';
$searchFilter = trim((string) ($_GET['search'] ?? ''));
$gameFilter = max(0, (int) ($_GET['game_id'] ?? 0));
$categoryFilter = strtolower(trim((string) ($_GET['category'] ?? '')));
$modeFilter = strtolower(trim((string) ($_GET['mode'] ?? '')));
$requestedStatusFilter = trim((string) ($_GET['status'] ?? ''));
$statusFilter = in_array($requestedStatusFilter, ['registration_closed', 'check_in', 'checkin_open', 'ready_for_draw', 'grouped', 'bracket_generated', 'ongoing'], true) ? $requestedStatusFilter : '';
$requestedDateFilter = trim((string) ($_GET['date'] ?? ''));
$dateFilter = in_array($requestedDateFilter, ['today', 'week', 'month', 'starting'], true) ? $requestedDateFilter : '';
$yearFilter = preg_match('/^20\d{2}$/', (string) ($_GET['year'] ?? '')) ? (int) $_GET['year'] : 0;
$availableGameIds = array_map('intval', $pdo->query('SELECT game_id FROM games')->fetchAll(PDO::FETCH_COLUMN));
if ($gameFilter > 0 && !in_array($gameFilter, $availableGameIds, true)) $gameFilter = 0;
$categoryLabels = ['male' => 'ชาย', 'female' => 'หญิง', 'open' => 'Open'];
if ($categoryFilter !== '' && !isset($categoryLabels[$categoryFilter])) $categoryFilter = '';
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
$nowSql = $now->format('Y-m-d H:i:s');
$currentStatuses = ['registration_closed', 'check_in', 'checkin_open', 'ready_for_draw', 'grouped', 'bracket_generated', 'ongoing'];
$currentStatusSql = "'" . implode("', '", $currentStatuses) . "'";
$tournamentWhere = [$view === 'completed' ? "t.status = 'completed'" : "t.status IN ($currentStatusSql)"];
$tournamentParams = [];
if ($searchFilter !== '') {
    $tournamentWhere[] = 't.name LIKE :search_name';
    $tournamentParams['search_name'] = '%' . $searchFilter . '%';
}
if ($gameFilter > 0) {
    $tournamentWhere[] = 't.game_id = :game_id';
    $tournamentParams['game_id'] = $gameFilter;
}
if ($categoryFilter !== '') {
    $tournamentWhere[] = 'EXISTS (SELECT 1 FROM tournament_categories filter_category WHERE filter_category.tournament_id = t.tournament_id AND filter_category.category_code = :category AND filter_category.is_active = 1)';
    $tournamentParams['category'] = $categoryFilter;
}
if (in_array($modeFilter, ['solo', 'team'], true)) {
    $tournamentWhere[] = 'g.play_mode = :play_mode';
    $tournamentParams['play_mode'] = $modeFilter;
}
if ($view === 'current' && $statusFilter === 'checkin_open') {
    $tournamentWhere[] = "t.checkin_open_at IS NOT NULL AND t.checkin_close_at IS NOT NULL AND t.checkin_open_at <= :status_now AND t.checkin_close_at >= :status_now";
    $tournamentParams['status_now'] = $nowSql;
} elseif ($view === 'current' && $statusFilter !== '') {
    $tournamentWhere[] = 't.status = :status';
    $tournamentParams['status'] = $statusFilter;
}
if ($view === 'current' && $dateFilter === 'today') {
    $tournamentWhere[] = 'DATE(t.start_date) = :today';
    $tournamentParams['today'] = $now->format('Y-m-d');
} elseif ($view === 'current' && $dateFilter === 'week') {
    $tournamentWhere[] = 'DATE(t.start_date) BETWEEN :week_start AND :week_end';
    $tournamentParams['week_start'] = $now->modify('monday this week')->format('Y-m-d');
    $tournamentParams['week_end'] = $now->modify('sunday this week')->format('Y-m-d');
} elseif ($view === 'current' && $dateFilter === 'month') {
    $tournamentWhere[] = 'YEAR(t.start_date) = :month_year AND MONTH(t.start_date) = :month_number';
    $tournamentParams['month_year'] = $now->format('Y');
    $tournamentParams['month_number'] = $now->format('n');
} elseif ($view === 'current' && $dateFilter === 'starting') {
    $tournamentWhere[] = 't.start_date > :starting_now AND t.start_date <= :starting_limit';
    $tournamentParams['starting_now'] = $nowSql;
    $tournamentParams['starting_limit'] = $now->modify('+7 days')->format('Y-m-d H:i:s');
} elseif ($view === 'completed' && $yearFilter > 0) {
    $tournamentWhere[] = 'YEAR(t.start_date) = :completed_year';
    $tournamentParams['completed_year'] = $yearFilter;
}

// ดึงรายการทัวร์นาเมนต์พร้อมชื่อเกมและรูปแบบการแข่งขันจากตาราง games
try {
    $tournamentStmt = $pdo->prepare("
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
        WHERE " . implode(' AND ', $tournamentWhere) . "
        ORDER BY t.start_date DESC
    ");
    $tournamentStmt->execute($tournamentParams);
    $tournaments = $tournamentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $tournaments = [];
}
$games = $pdo->query('SELECT game_id, name FROM games ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$categoryOptionsStmt = $pdo->query("SELECT DISTINCT tc.category_code, tc.label
    FROM tournament_categories tc
    JOIN tournaments public_t ON public_t.tournament_id = tc.tournament_id
    WHERE tc.is_active = 1 AND public_t.status " . ($view === 'completed' ? "= 'completed'" : "IN ($currentStatusSql)") . "
    ORDER BY tc.label ASC");
$categoryOptions = $categoryOptionsStmt->fetchAll(PDO::FETCH_ASSOC);
$categoryOptions = array_values(array_filter($categoryOptions, static function (array $category): bool {
    return isset($categoryLabels[strtolower((string) $category['category_code'])]);
}));
$statusLabels = [
    'registration_closed' => 'ปิดรับสมัคร',
    'check_in' => 'เตรียม Check-in',
    'checkin_open' => 'กำลัง Check-in',
    'ready_for_draw' => 'รอจับสาย',
    'grouped' => 'แบ่งกลุ่มแล้ว',
    'bracket_generated' => 'สร้างสายการแข่งขันแล้ว',
    'ongoing' => 'กำลังแข่งขัน',
];
$completedYears = $pdo->query("SELECT DISTINCT YEAR(start_date) AS year FROM tournaments WHERE status = 'completed' AND start_date IS NOT NULL ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
$tabCommonParams = array_filter(['search' => $searchFilter, 'game_id' => $gameFilter ?: '', 'category' => $categoryFilter, 'mode' => $modeFilter], static fn($value): bool => $value !== '');
$currentTabUrl = 'tournaments.php?' . http_build_query(array_merge(['view' => 'current'], $tabCommonParams));
$completedTabUrl = 'tournaments.php?' . http_build_query(array_merge(['view' => 'completed'], $tabCommonParams));
$clearViewUrl = 'tournaments.php?view=' . urlencode($view);
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
                class="text-3xl sm:text-7xl font-black font-display text-white tracking-wider uppercase leading-none break-words drop-shadow-[0_0_40px_rgba(255,85,0,0.9)] animate-fade-down">
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
            <div class="mb-5 flex gap-2 overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.04] p-2 backdrop-blur-md" role="tablist" aria-label="Tournament views">
                <a href="<?= htmlspecialchars($currentTabUrl) ?>" role="tab" aria-selected="<?= $view === 'current' ? 'true' : 'false' ?>" class="min-w-[190px] flex-1 rounded-xl px-4 py-3 text-center text-xs font-bold transition-all focus:outline-none focus:ring-2 focus:ring-brand-orange <?= $view === 'current' ? 'bg-brand-orange text-white shadow-orange-glow' : 'text-gray-400 hover:bg-white/10 hover:text-white' ?>">กำลังแข่งขัน</a>
                <a href="<?= htmlspecialchars($completedTabUrl) ?>" role="tab" aria-selected="<?= $view === 'completed' ? 'true' : 'false' ?>" class="min-w-[190px] flex-1 rounded-xl px-4 py-3 text-center text-xs font-bold transition-all focus:outline-none focus:ring-2 focus:ring-brand-orange <?= $view === 'completed' ? 'bg-brand-orange text-white shadow-orange-glow' : 'text-gray-400 hover:bg-white/10 hover:text-white' ?>">ผลการแข่งขัน</a>
            </div>
            <form method="GET" class="mb-8 grid grid-cols-1 gap-3 rounded-2xl border border-white/10 bg-white/[0.06] p-4 backdrop-blur-md sm:grid-cols-2 lg:grid-cols-6">
                <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                <input type="search" name="search" value="<?= htmlspecialchars($searchFilter) ?>" placeholder="ค้นหาชื่อ Tournament" class="rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-xs text-white placeholder:text-gray-500 focus:border-brand-orange focus:outline-none">
                <select name="game_id" class="rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-xs text-white focus:border-brand-orange focus:outline-none"><option value="">ทุกเกม</option><?php foreach ($games as $game): ?><option value="<?= (int) $game['game_id'] ?>" <?= $gameFilter === (int) $game['game_id'] ? 'selected' : '' ?>><?= htmlspecialchars($game['name']) ?></option><?php endforeach; ?></select>
                <select name="category" class="rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-xs text-white focus:border-brand-orange focus:outline-none"><option value="">ทุก Category</option><?php foreach ($categoryOptions as $category): $categoryCode = strtolower((string) $category['category_code']); ?><option value="<?= htmlspecialchars($categoryCode) ?>" <?= $categoryFilter === $categoryCode ? 'selected' : '' ?>><?= htmlspecialchars($categoryLabels[$categoryCode]) ?></option><?php endforeach; ?></select>
                <select name="mode" class="rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-xs text-white focus:border-brand-orange focus:outline-none"><option value="">ทุกประเภทการแข่งขัน</option><?php foreach (['team' => 'ประเภททีม', 'solo' => 'ประเภทบุคคล'] as $modeValue => $modeLabel): ?><option value="<?= $modeValue ?>" <?= $modeFilter === $modeValue ? 'selected' : '' ?>><?= $modeLabel ?></option><?php endforeach; ?></select>
                <?php if ($view === 'current'): ?>
                    <select name="status" class="rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-xs text-white focus:border-brand-orange focus:outline-none"><option value="">ทุกสถานะ</option><?php foreach ($statusLabels as $statusValue => $statusLabel): ?><option value="<?= $statusValue ?>" <?= $statusFilter === $statusValue ? 'selected' : '' ?>><?= $statusLabel ?></option><?php endforeach; ?></select>
                    <select name="date" class="rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-xs text-white focus:border-brand-orange focus:outline-none"><option value="">ทุกช่วงเวลา</option><option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>วันนี้</option><option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>สัปดาห์นี้</option><option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>เดือนนี้</option><option value="starting" <?= $dateFilter === 'starting' ? 'selected' : '' ?>>ใกล้เริ่มการแข่งขัน</option></select>
                <?php else: ?>
                    <select name="year" class="rounded-xl border border-white/10 bg-black/30 px-3 py-2.5 text-xs text-white focus:border-brand-orange focus:outline-none"><option value="">ทุกปีที่แข่งขัน</option><?php foreach ($completedYears as $completedYear): ?><option value="<?= (int) $completedYear ?>" <?= $yearFilter === (int) $completedYear ? 'selected' : '' ?>><?= (int) $completedYear ?></option><?php endforeach; ?></select>
                <?php endif; ?>
                <div class="flex gap-2"><button type="submit" class="flex-1 rounded-xl bg-brand-orange px-3 py-2.5 text-xs font-bold text-white hover:bg-brand-glow">กรอง</button><a href="<?= htmlspecialchars($clearViewUrl) ?>" class="flex-1 rounded-xl bg-white/10 px-3 py-2.5 text-center text-xs font-bold text-gray-300 hover:bg-white/15">ล้าง</a></div>
            </form>
            <?php if (empty($tournaments)): ?>
                <div
                    class="glass-panel p-20 text-center text-gray-300 rounded-3xl max-w-xl mx-auto border border-brand-orange/40 shadow-orange-glow animate-fade-up">
                    <i class="fa-solid fa-trophy text-6xl mb-4 block text-brand-orange animate-bounce"></i>
                    <h3 class="text-2xl font-bold font-display text-white mb-2"><?= $view === 'completed' ? 'ยังไม่มีผลการแข่งขันย้อนหลัง' : 'ขณะนี้ยังไม่มีทัวร์นาเมนต์' ?></h3>
                    <p class="text-xs text-gray-400 mb-4">ลองเปลี่ยนตัวกรองเพื่อดูรายการอื่น</p>
                    <a href="<?= htmlspecialchars($clearViewUrl) ?>" class="inline-flex rounded-xl bg-brand-orange px-4 py-2 text-xs font-bold text-white hover:bg-brand-glow">ล้างตัวกรอง</a>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach ($tournaments as $tIndex => $t):
                        $imgSrc = !empty($t['image_path']) ? '../assets/' . htmlspecialchars($t['image_path']) : 'https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=1000&auto=format&fit=crop';
                        $status = $t['status'] ?? '';
                        $isCheckinOpen = $view === 'current' && !empty($t['checkin_open_at']) && !empty($t['checkin_close_at'])
                            && $nowSql >= $t['checkin_open_at'] && $nowSql <= $t['checkin_close_at'];
                        $tId = $t['tournament_id'] ?? ($t['id'] ?? 0);
                        $tTitle = $t['title'] ?? 'ทัวร์นาเมนต์อีสปอร์ต';
                        $tPrize = $t['prize_pool'] ?? 0;
                        $tGame = !empty($t['game_name']) ? $t['game_name'] : 'Arena of Valor (RoV)';
                        $tMode = !empty($t['play_mode']) ? $t['play_mode'] : 'ทีม (Team 5v5)';
                        $tStartDate = !empty($t['start_date']) ? date('d/m/Y', strtotime($t['start_date'])) : '-';
                        $tEndDate = !empty($t['end_date']) ? date('d/m/Y', strtotime($t['end_date'])) : '-';
                        $tCompetitionDate = !empty($t['match_date']) ? date('d/m/Y', strtotime($t['match_date'])) : $tStartDate;
                        $tVenue = '-';
                        $tChampion = '';
                        try {
                            $venueStmt = $pdo->prepare('SELECT venue_name FROM tournament_days WHERE tournament_id = :tournament_id AND venue_name IS NOT NULL AND venue_name <> \'\' ORDER BY day_number LIMIT 1');
                            $venueStmt->execute(['tournament_id' => $tId]);
                            $tVenue = (string) ($venueStmt->fetchColumn() ?: '-');
                        } catch (PDOException $exception) {
                            $tVenue = '-';
                        }
                        if ($view === 'completed') {
                            $championStmt = $pdo->prepare("SELECT COALESCE(winner.name, winner_user.username, winner_player.display_name) AS champion_name
                                FROM matches m
                                LEFT JOIN teams winner ON winner.team_id = m.winner_team_id
                                LEFT JOIN players winner_player ON winner_player.player_id = m.winner_team_id
                                LEFT JOIN users winner_user ON winner_user.user_id = winner_player.user_id
                                WHERE m.tournament_id = :tournament_id AND m.winner_team_id IS NOT NULL AND m.status IN ('completed', 'walkover')
                                ORDER BY CASE WHEN m.bracket_type LIKE 'double_grand_final_reset_%' THEN 0 WHEN m.bracket_type LIKE 'double_grand_final_%' THEN 1 ELSE 2 END, m.round_number DESC, m.match_index DESC LIMIT 1");
                            $championStmt->execute(['tournament_id' => $tId]);
                            $tChampion = trim((string) ($championStmt->fetchColumn() ?: ''));
                        }
                        $categoryStmt = $pdo->prepare('SELECT category_code, label FROM tournament_categories WHERE tournament_id = :tournament_id AND is_active = 1 ORDER BY tournament_category_id');
                        $categoryStmt->execute(['tournament_id' => $tId]);
                        $categories = $categoryStmt->fetchAll();
                        $staggerDelay = min($tIndex * 100, 800);
                        ?>
                        <div class="tournament-card rounded-3xl overflow-hidden flex flex-col justify-between shadow-2xl group"
                            data-aos="zoom-in-up" data-aos-delay="<?php echo $staggerDelay; ?>" data-aos-duration="700">
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
                                        <?php if ($isCheckinOpen): ?>
                                            <span class="px-3.5 py-1.5 rounded-full bg-blue-600/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest border border-blue-300"><i class="fa-solid fa-user-check mr-1"></i> กำลัง Check-in</span>
                                        <?php elseif ($status === 'ongoing'): ?>
                                            <span
                                                class="px-3.5 py-1.5 rounded-full bg-rose-600/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest shadow-[0_0_15px_rgba(225,29,72,0.6)] flex items-center gap-1.5 border border-rose-400">
                                                <span class="w-2 h-2 rounded-full bg-white animate-ping"></span> กำลังแข่งขัน (LIVE)
                                            </span>
                                        <?php elseif ($status === 'registration_closed'): ?>
                                            <span class="px-3.5 py-1.5 rounded-full bg-sky-600/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest border border-sky-300"><i class="fa-solid fa-lock mr-1"></i> ปิดรับสมัคร</span>
                                        <?php elseif (isset($statusLabels[$status])): ?>
                                           <span class="px-3.5 py-1.5 rounded-full bg-brand-orange/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest shadow-orange-glow flex items-center gap-1.5 border border-amber-300">
                                        <i class="fa-solid fa-flag text-amber-200"></i> <?= htmlspecialchars($statusLabels[$status]) ?>
                                           </span>
                                        <?php elseif ($status === 'completed'): ?>
                                            <span class="px-3.5 py-1.5 rounded-full bg-emerald-700/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest border border-emerald-300"><i class="fa-solid fa-flag-checkered mr-1"></i> แข่งขันจบแล้ว</span>
                                        <?php else: ?>
                                            <span
                                                class="px-3.5 py-1.5 rounded-full bg-brand-orange/90 backdrop-blur-md text-white text-[10px] font-black uppercase tracking-widest shadow-orange-glow flex items-center gap-1.5 border border-amber-300">
                                                <i class="fa-solid fa-circle-question text-amber-200"></i> ไม่ทราบสถานะ
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
                                                <i class="fa-regular fa-calendar-check text-brand-cyber"></i> วันแข่งขัน:
                                            </span>
                                            <span class="font-mono text-gray-200 text-[11px]">
                                                <?php echo $view === 'completed' ? $tCompetitionDate : $tStartDate; ?>
                                            </span>
                                        </div>

                                        <?php if ($view === 'completed'): ?>
                                            <div class="flex items-center justify-between text-gray-300 pt-1.5 border-t border-white/5">
                                                <span class="text-gray-400 text-[11px]"><i class="fa-solid fa-location-dot text-brand-orange mr-1"></i> สถานที่</span>
                                                <span class="font-mono text-gray-200 text-[11px] text-right"><?php echo htmlspecialchars($tVenue); ?></span>
                                            </div>
                                            <div class="flex items-center justify-between text-gray-300 pt-1.5 border-t border-white/5">
                                                <span class="text-gray-400 text-[11px]"><i class="fa-solid fa-crown text-amber-400 mr-1"></i> แชมป์</span>
                                                <span class="font-bold text-amber-300 text-[11px] text-right"><?php echo htmlspecialchars($tChampion ?: 'ยังไม่มีข้อมูลยืนยัน'); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($view === 'current'): ?>
                                        <div class="flex items-center justify-between text-gray-300 pt-1.5 border-t border-white/5">
                                            <span class="text-gray-400 text-[11px]"><i class="fa-solid fa-user-check text-emerald-400 mr-1"></i> Check-in / Match</span>
                                            <span class="font-mono text-gray-200 text-[11px]"><?php echo (int) $t['checkin_complete_count']; ?> / <?php echo (int) $t['completed_match_count']; ?>-<?php echo (int) $t['match_count']; ?></span>
                                        </div>
                                        <?php endif; ?>

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

                            <!-- ปุ่มติดตามการแข่งขันหรือดูผลย้อนหลัง -->
                            <div class="p-6 pt-0">
                                <div class="grid grid-cols-1 gap-2">
                                    <a href="tournament-detail.php?id=<?php echo $tId; ?>"
                                        class="py-3 px-4 rounded-2xl bg-brand-orange hover:bg-brand-glow shadow-orange-glow text-white text-xs font-bold text-center transition-all">
                                        <i class="fa-solid fa-circle-info mr-1"></i> <?= $view === 'completed' ? 'ดูผลการแข่งขัน' : 'ดูรายละเอียด' ?>
                                    </a>
                                </div>
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