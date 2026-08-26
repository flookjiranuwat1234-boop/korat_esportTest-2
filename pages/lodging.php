<?php
// pages/lodging.php
require_once '../config/db.php';
require_once '../includes/auth.php';
// หมายเหตุ: ไม่มีการเรียก requireLogin() เพื่อให้ผู้ใช้งานทั่วไปเข้าชมที่พักแนะนำได้ตามขอบเขตระบบ

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$selectedTournamentId = filter_var($_GET['tournament_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedTournamentId = $selectedTournamentId === false ? null : $selectedTournamentId;
$statusLabels = [
    'registration_open' => 'เปิดรับสมัคร',
    'registration_closed' => 'ปิดรับสมัคร',
    'ongoing' => 'กำลังแข่งขัน',
    'completed' => 'แข่งขันจบแล้ว',
];
function isPublicMapsUrl($url) {
    $parts = parse_url(trim((string) $url));
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return false;
    return in_array(strtolower($parts['host'] ?? ''), ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl'], true) && !empty($parts['path']);
}
function getPublicVenueOrigin(array $accommodation): string {
    $venueValue = trim((string) ($accommodation['venue_lat_lng'] ?? ''));
    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $venueValue, $matches)) {
        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];
        if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) return $latitude . ',' . $longitude;
    }
    if (preg_match('~@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)(?:[,/]|$)~', $venueValue, $matches)) {
        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];
        if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) return $latitude . ',' . $longitude;
    }
    return trim((string) ($accommodation['venue_address'] ?? ''));
}
$selectedTournamentIdParam = $selectedTournamentId ?: 0;

$accommodationStmt = $pdo->prepare("SELECT a.accommodation_id, a.tournament_id, a.name, a.address, a.image_path, a.distance, a.link_url,
    t.name AS tournament_name, t.venue_address, t.venue_lat_lng, t.start_date, t.end_date, t.status, g.name AS game_name
    FROM accommodations a
    INNER JOIN tournaments t ON t.tournament_id = a.tournament_id
    LEFT JOIN games g ON g.game_id = t.game_id
    WHERE t.status IN ('registration_open', 'registration_closed', 'ongoing', 'completed')
    ORDER BY CASE WHEN a.tournament_id = :selected_tournament_id THEN 0 ELSE 1 END,
        CASE t.status WHEN 'registration_open' THEN 1 WHEN 'ongoing' THEN 2 WHEN 'registration_closed' THEN 3 WHEN 'completed' THEN 4 ELSE 5 END,
        t.start_date DESC, CAST(a.distance AS DECIMAL(10,2)) ASC, a.name ASC");
$accommodationStmt->execute(['selected_tournament_id' => $selectedTournamentIdParam]);
$accommodations = $accommodationStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ที่พักแนะนำ - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
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
                            dark: '#0A0A0C',
                            panel: '#121318'
                        }
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                        display: ['Orbitron', 'sans-serif']
                    },
                    boxShadow: {
                        'orange-glow': '0 0 25px rgba(255, 85, 0, 0.45)'
                    }
                }
            }
        }
    </script>

    <style>
        body {
            background-color: #0F1117;
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

        .lodging-card {
            position: relative;
            isolation: isolate;
            transform: translateZ(0);
            background: #171b26;
            transition: transform 130ms cubic-bezier(0.22, 0.61, 0.36, 1), box-shadow 130ms ease, background-color 130ms ease, border-color 130ms ease;
        }
        .lodging-card-badge,
        .lodging-card-distance,
        .lodging-card-info {
            transition: transform 140ms ease, box-shadow 140ms ease, background-color 140ms ease, border-color 140ms ease;
        }
        .lodging-card-image {
            transform: none;
        }
        .lodging-route-button {
            position: relative;
            z-index: 3;
            transition: transform 160ms cubic-bezier(0.22, 0.61, 0.36, 1), box-shadow 160ms ease, background-color 160ms ease, border-color 160ms ease, color 160ms ease;
        }
        .lodging-route-button i {
            transition: transform 160ms cubic-bezier(0.22, 0.61, 0.36, 1);
        }
        @media (hover: hover) and (pointer: fine) {
            .lodging-card:hover {
                transform: translateY(-7px) scale(1.01) !important;
                background: #202633;
                box-shadow: 0 18px 38px -8px rgba(0, 0, 0, 0.58), 0 0 22px rgba(255, 85, 0, 0.34);
            }
            .lodging-card:hover .lodging-card-badge,
            .lodging-card:hover .lodging-card-distance { transform: translateY(-2px); box-shadow: 0 0 12px rgba(255, 183, 77, 0.2); }
            .lodging-card:hover .lodging-card-info { background-color: rgba(255, 85, 0, 0.08); border-color: rgba(255, 140, 70, 0.55); }
            .lodging-card:hover .lodging-route-button {
                transform: translateY(-2px) scale(1.005);
                box-shadow: 0 8px 18px rgba(37, 99, 235, 0.22);
            }
            .lodging-card:hover .lodging-route-button i { transform: translateX(3px); }
        }
        @media (hover: none) {
            .lodging-card:hover {
                transform: none;
                background: #171b26;
                border-color: rgba(255, 255, 255, 0.15);
                box-shadow: none;
            }
        }
        .lodging-card:active { transform: translateY(-2px) scale(0.995) !important; }
        @media (prefers-reduced-motion: reduce) {
            .lodging-card,
            .lodging-card-image,
            .lodging-route-button,
            .lodging-route-button i { transition: none !important; animation: none !important; }
            .lodging-card:hover { transform: none; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.28); }
            .lodging-card:hover .lodging-card-image { transform: none; filter: none; }
            .lodging-card-badge,
            .lodging-card-distance,
            .lodging-card-info { transition: none !important; }
        }

        .lodging-selector {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
        }

        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }

        /* Shine Sweep Effect สำหรับปุ่มแผนที่ */
        .shine-btn {
            position: relative;
            overflow: hidden;
        }
        .shine-btn::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(60deg, transparent 30%, rgba(255, 255, 255, 0.4) 50%, transparent 70%);
            transform: rotate(30deg) translateX(-100%);
            transition: transform 0.7s ease;
        }
        .shine-btn:hover::after {
            transform: rotate(30deg) translateX(100%);
        }

        /* Pulse Glow สำหรับป้ายระยะทาง */
        @keyframes distancePulse {
            0%, 100% { box-shadow: 0 0 10px rgba(245, 158, 11, 0.4); transform: scale(1); }
            50% { box-shadow: 0 0 20px rgba(245, 158, 11, 0.8); transform: scale(1.03); }
        }
        .distance-badge-glow {
            animation: distancePulse 2.5s infinite;
        }

        /* Subtle Pulse สำหรับ Placeholder Icon */
        @keyframes subtlePulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.9; }
        }
        .placeholder-icon-pulse {
            animation: subtlePulse 3s infinite ease-in-out;
        }

        /* Keyframe Animations for Header Text */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-down {
            animation: fadeInDown 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .animate-fade-up {
            animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards;
            opacity: 0;
        }
    </style>
</head>
<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">

    <!-- Background Arena + Grid Layer -->
    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>

    <div class="relative z-10 flex flex-col min-h-screen">

        <!-- ================= 1. PUBLIC NAVIGATION BAR ================= -->
        <header class="sticky top-0 z-50 glass-nav transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    
                    <!-- Logo & Brand Header -->
                    <a href="index.php" class="flex items-center gap-3 group">
                        <img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto filter drop-shadow-[0_2px_8px_rgba(0,0,0,0.5)] group-hover:scale-105 transition-transform" onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors drop-shadow">KORAT <span class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] tracking-widest text-gray-200 font-bold uppercase -mt-1 drop-shadow-sm">Official Arena & Hub</span>
                        </div>
                    </a>

                    <!-- Public Menu Items -->
                    <nav class="hidden md:flex items-center gap-1 lg:gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-house text-xs mr-1.5"></i> หน้าแรก
                        </a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-trophy text-xs mr-1.5"></i> ทัวร์นาเมนต์
                        </a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-ranking-star text-xs mr-1.5"></i> ตารางคะแนน
                        </a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-newspaper text-xs mr-1.5"></i> ข่าวสาร
                        </a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-images text-xs mr-1.5"></i> แกลเลอรี่
                        </a>
                        <a href="lodging.php" class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange transition-all shadow-md">
                            <i class="fa-solid fa-hotel text-xs mr-1.5"></i> ที่พักแนะนำ
                        </a>
                    </nav>

                    <!-- User Status / Auth Buttons -->
                    <div class="flex items-center gap-4 text-base font-bold drop-shadow">
                        <?php if ($isLoggedIn): ?>
                            <div class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md">
                                <div class="flex flex-col text-right">
                                    <span class="text-sm font-bold text-white leading-tight">
                                        <?= htmlspecialchars($currentUser['username'] ?? 'User') ?>
                                    </span>
                                    <span class="text-[10px] font-semibold text-brand-orange uppercase tracking-wider">
                                        <?= htmlspecialchars($currentUser['role'] ?? 'Player') ?>
                                    </span>
                                </div>

                                <?php if (($currentUser['role'] ?? '') === 'admin'): ?>
                                    <a href="../admin/dashboard.php" title="ระบบหลังบ้าน Admin" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                        <i class="fa-solid fa-user-shield text-sm"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="profile.php" title="จัดการโปรไฟล์/ทีม" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                        <i class="fa-solid fa-user-gear text-sm"></i>
                                    </a>
                                <?php endif; ?>

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

        <!-- ================= 2. PAGE HEADER (Animated Text) ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 pb-4 w-full text-center space-y-3">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest backdrop-blur-md animate-fade-down">
                <i class="fa-solid fa-hotel"></i> Recommended Accommodations
            </div>
            
            <h1 class="text-4xl sm:text-6xl font-black font-display text-white tracking-wider uppercase leading-none drop-shadow-md animate-fade-down">
                ที่พักแนะนำ <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-300 to-white">(LODGING)</span>
            </h1>

            <p class="text-sm sm:text-base text-gray-300 max-w-xl mx-auto font-normal animate-fade-up">
                รวมโรงแรมและที่พักแนะนำใกล้นครราชสีมา สำหรับนักกีฬา สโมสร และผู้ติดตามที่เดินทางมาแข่งขัน
            </p>
        </section>

        <!-- ================= 3. ACCOMMODATIONS LIST SECTION ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 mb-12 w-full">
            <div class="mb-5 flex items-end justify-between gap-3">
                <div><h2 class="text-xl font-bold text-white">ที่พักใกล้สถานที่จัดการแข่งขัน</h2><p class="mt-1 text-xs text-gray-300">ที่พักที่ผู้ดูแลแนะนำสำหรับแต่ละ Tournament</p></div>
                <span class="shrink-0 text-xs text-gray-300">พบที่พักแนะนำ <?= count($accommodations) ?> แห่ง</span>
            </div>
            <?php if (!$accommodations): ?>
                <div class="glass-panel rounded-2xl p-10 text-center text-gray-300"><i class="fa-solid fa-hotel text-4xl mb-3 block text-brand-orange opacity-60"></i><h3 class="text-lg font-bold text-white mb-1">ยังไม่มีข้อมูลที่พักแนะนำ</h3><p class="text-xs text-gray-400">ข้อมูลที่พักจะปรากฏเมื่อผู้ดูแลระบบเพิ่มรายการ</p></div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($accommodations as $index => $a): ?>
                        <div class="glass-card lodging-card <?= $selectedTournamentId && (int) $a['tournament_id'] === $selectedTournamentId ? 'border-brand-orange shadow-orange-glow' : '' ?> rounded-2xl overflow-hidden flex flex-col justify-between group shadow-lg h-full"
                             data-aos="fade-up"
                             data-aos-delay="<?php echo min($index * 80, 80); ?>">
                            <div>
                                <!-- 🖼️ รูปภาพโรงแรมพร้อม Gradient Overlay ด้านล่าง -->
                                <div class="aspect-video relative overflow-hidden bg-black/60">
                                    <div class="lodging-card-badge absolute left-3 top-3 z-10 max-w-[75%] truncate rounded-full border border-brand-orange/50 bg-slate-950/85 px-3 py-1 text-[10px] font-bold text-orange-200" title="แนะนำสำหรับ: <?= htmlspecialchars($a['tournament_name']) ?>">แนะนำสำหรับ: <?= htmlspecialchars($a['tournament_name']) ?></div>
                                    <?php if (!empty($a['image_path'])): ?>
                                        <img src="../assets/<?php echo htmlspecialchars($a['image_path']); ?>" alt="<?php echo htmlspecialchars($a['name']); ?>" loading="lazy" onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');" class="lodging-card-image w-full h-full object-cover">
                                        <div class="hidden w-full h-full flex flex-col items-center justify-center text-gray-500 bg-slate-900/80"><i class="fa-solid fa-hotel text-4xl mb-1 text-brand-orange/50"></i><span class="text-[10px] tracking-widest uppercase opacity-70">KORAT ESPORT LODGING</span></div>
                                    <?php else: ?>
                                        <div class="w-full h-full flex flex-col items-center justify-center text-gray-500 bg-slate-900/80">
                                            <i class="fa-solid fa-hotel text-4xl mb-1 text-brand-orange/50 placeholder-icon-pulse"></i>
                                            <span class="text-[10px] tracking-widest uppercase opacity-70">KORAT ESPORT LODGING</span>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Gradient Overlay มืดด้านล่างรูปให้อ่านง่าย -->
                                    <div class="absolute inset-x-0 bottom-0 h-1/2 bg-gradient-to-t from-black/80 to-transparent pointer-events-none"></div>

                                    <!-- 📍 ป้ายแสดงระยะทางจากสนามแข่ง พร้อม Glow Pulse -->
                                    <?php if ($a['distance'] !== null && $a['distance'] !== ''): ?>
                                        <div class="lodging-card-distance absolute top-3 right-3 bg-black/85 backdrop-blur-md text-amber-300 text-[11px] font-bold px-3.5 py-1 rounded-full border border-amber-400/50 shadow-xl flex items-center gap-1.5 distance-badge-glow">
                                            <i class="fa-solid fa-route text-brand-orange"></i> ห่างจากสนามประมาณ <?php echo htmlspecialchars($a['distance']); ?> กม.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="p-6 space-y-3">
                                    <h3 class="text-xl font-bold text-white group-hover:text-brand-orange transition-colors font-display line-clamp-2 leading-snug">
                                        <?php echo htmlspecialchars($a['name']); ?>
                                    </h3>

                                    <?php if (!empty($a['address'])): ?>
                                        <p class="text-xs text-gray-300 flex items-start gap-2 leading-relaxed font-normal">
                                            <i class="fa-solid fa-location-dot text-brand-orange mt-0.5 shrink-0"></i>
                                            <span class="line-clamp-3"><?php echo htmlspecialchars($a['address']); ?></span>
                                        </p>
                                    <?php endif; ?>
                                    <div class="lodging-card-info rounded-xl border border-brand-orange/30 bg-slate-950/55 p-3 text-xs text-gray-300 space-y-1.5">
                                        <p class="flex items-center justify-between gap-2 font-bold text-orange-200"><span><i class="fa-solid fa-trophy text-brand-orange mr-2"></i>ใกล้สถานที่จัดการแข่งขัน</span><span class="shrink-0 rounded-full border border-brand-orange/30 px-2 py-0.5 text-[10px] text-brand-orange"><?= htmlspecialchars($statusLabels[$a['status']] ?? 'ไม่ทราบสถานะ') ?></span></p>
                                        <p><i class="fa-solid fa-trophy text-brand-orange mr-2"></i>Tournament: <?= htmlspecialchars($a['tournament_name']) ?></p>
                                        <p><i class="fa-solid fa-gamepad text-brand-orange mr-2"></i>เกม: <?= htmlspecialchars($a['game_name'] ?: 'ไม่ระบุเกม') ?></p>
                                        <p><i class="fa-solid fa-location-dot text-brand-orange mr-2"></i>สนาม: <?= htmlspecialchars($a['venue_address'] ?: 'ยังไม่ได้ระบุสถานที่แข่งขัน') ?></p>
                                        <p><i class="fa-regular fa-calendar text-brand-orange mr-2"></i>วันที่: <?= $a['start_date'] && $a['end_date'] ? htmlspecialchars(date('d/m/Y', strtotime($a['start_date'])) . ' - ' . date('d/m/Y', strtotime($a['end_date']))) : 'ยังไม่ได้กำหนดวันแข่งขัน' ?></p>
                                    </div>
                                    <?php if ($selectedTournamentId && (int) $a['tournament_id'] === $selectedTournamentId): ?><p class="text-[10px] font-bold text-brand-orange">แนะนำสำหรับ Tournament ที่คุณกำลังดู</p><?php endif; ?>
                                </div>
                            </div>

                            <div class="p-6 pt-0 border-t border-white/10 mt-2">
                                <?php $venueOrigin = getPublicVenueOrigin($a); $destination = trim((string) $a['name'] . ' ' . (string) ($a['address'] ?? '')); $directionsUrl = $venueOrigin !== '' && trim((string) $a['name']) !== '' ? 'https://www.google.com/maps/dir/?api=1&origin=' . urlencode($venueOrigin) . '&destination=' . urlencode($destination) . '&travelmode=driving' : ''; ?>
                                <?php if ($directionsUrl): ?>
                                    <a href="<?php echo htmlspecialchars($directionsUrl); ?>" target="_blank" rel="noopener noreferrer"
                                       class="shine-btn lodging-route-button w-full py-2.5 px-4 rounded-xl bg-blue-600/30 hover:bg-blue-600 text-blue-200 hover:text-white border border-blue-500/40 font-bold text-xs flex items-center justify-center gap-2 transition-all shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-orange">
                                        <i class="fa-solid fa-map-location-dot"></i>
                                        <span>ดูเส้นทางจากสนาม</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ================= 4. FOOTER ================= -->
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

    <!-- AOS JS Library -->
    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            AOS.init({
                once: true,
                duration: 800,
                easing: 'ease-out-cubic'
            });
        });
    </script>
</body>
</html>
```