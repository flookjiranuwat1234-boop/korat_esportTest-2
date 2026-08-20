<?php
// pages/gallery.php
require_once '../config/db.php';
require_once '../includes/auth.php';

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$viewMode = trim($_GET['view'] ?? 'all'); // 'all' หรือ 'albums'
$selectedAlbumId = (int) ($_GET['album_id'] ?? 0);

if ($selectedAlbumId > 0) {
    // โหมดดูรูปภาพเฉพาะภายในอัลบั้มที่เลือก (ดึงชื่อจากตาราง gallery_albums คอลัมน์ title)
    $stmt = $pdo->prepare("
        SELECT g.*, COALESCE(a.title, CONCAT('อัลบั้ม #', g.album_id)) as display_album_name 
        FROM gallery g
        LEFT JOIN gallery_albums a ON a.album_id = g.album_id
        WHERE g.media_type = 'activity' AND g.is_active = 1 AND g.album_id = :aid 
        ORDER BY g.created_at DESC
    ");
    $stmt->execute(['aid' => $selectedAlbumId]);
    $images = $stmt->fetchAll();
    $currentAlbumName = !empty($images) ? $images[0]['display_album_name'] : 'อัลบั้ม #' . $selectedAlbumId;
} elseif ($viewMode === 'all') {
    // โหมดดูรูปทั้งหมด
    $images = $pdo->query("
        SELECT g.*, COALESCE(a.title, CONCAT('อัลบั้ม #', g.album_id)) as display_album_name 
        FROM gallery g
        LEFT JOIN gallery_albums a ON a.album_id = g.album_id
        WHERE g.media_type = 'activity' AND g.is_active = 1
        ORDER BY g.created_at DESC
    ")->fetchAll();
} else {
    // โหมดดูรายการอัลบั้ม (ดึงข้อมูลจากตาราง gallery_albums โดยตรง มั่นใจได้ว่าชื่อตรงกับหน้าแอดมิน)
    $albums = $pdo->query("
        SELECT a.album_id,
               a.title as album_name,
               (SELECT image_path FROM gallery g2 WHERE g2.album_id = a.album_id AND g2.media_type = 'activity' AND g2.is_active = 1 ORDER BY g2.created_at DESC LIMIT 1) as cover_image,
               (SELECT COUNT(*) FROM gallery g3 WHERE g3.album_id = a.album_id AND g3.media_type = 'activity' AND g3.is_active = 1) as image_count
        FROM gallery_albums a
        ORDER BY a.created_at DESC
    ")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แกลเลอรี่กิจกรรม - Korat Esport</title>
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
        /* ซ่อน Scrollbar สำหรับ Chrome, Safari และ Opera */
        ::-webkit-scrollbar {
            display: none;
        }

        /* ซ่อน Scrollbar สำหรับ Firefox, IE และ Edge */
        html,
        body {
            -ms-overflow-style: none;
            /* IE และ Edge */
            scrollbar-width: none;
            /* Firefox */
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

        .grid-bg {
            background-image: radial-gradient(rgba(255, 85, 0, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }

        /* Keyframe Animations */
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fade-down {
            animation: fadeInDown 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .animate-fade-up {
            animation: fadeInUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards;
            opacity: 0;
        }

        /* Album Grid Styles */
        .album-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 24px;
        }

        @media (min-width: 640px) {
            .album-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .album-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        .album-card {
            position: relative;
            border-radius: 1.5rem;
            overflow: hidden;
            background: rgba(20, 21, 28, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            text-decoration: none;
            display: block;
        }

        .album-card:hover {
            transform: translateY(-8px);
            border-color: #FF5500;
            box-shadow: 0 20px 40px -10px rgba(255, 85, 0, 0.5);
        }

        .album-card img {
            width: 100%;
            height: 260px;
            object-fit: cover;
            transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            filter: brightness(0.65);
        }

        .album-card:hover img {
            transform: scale(1.08);
            filter: brightness(0.9);
        }

        /* Masonry Wall for Images */
        .masonry-wall {
            column-count: 1;
            column-gap: 20px;
        }

        @media (min-width: 640px) {
            .masonry-wall {
                column-count: 2;
            }
        }

        @media (min-width: 1024px) {
            .masonry-wall {
                column-count: 3;
            }
        }

        .masonry-item {
            break-inside: avoid;
            margin-bottom: 20px;
            position: relative;
            border-radius: 1.5rem;
            overflow: hidden;
            background: rgba(20, 21, 28, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.12);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
        }

        .masonry-item:hover {
            transform: translateY(-6px);
            border-color: #FF5500;
            box-shadow: 0 15px 35px -10px rgba(255, 85, 0, 0.5);
        }

        .masonry-item img {
            width: 100%;
            height: auto;
            display: block;
            transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .masonry-item:hover img {
            transform: scale(1.06);
        }

        .masonry-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(10, 10, 12, 0.95), rgba(10, 10, 12, 0.4) 60%, transparent);
            opacity: 0;
            transform: translateY(15px);
            transition: opacity 0.35s ease, transform 0.35s ease;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 24px;
        }

        .masonry-item:hover .masonry-overlay {
            opacity: 1;
            transform: translateY(0);
        }

        .floating-badge {
            position: absolute;
            top: 16px;
            left: 16px;
            background: rgba(10, 10, 12, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 85, 0, 0.5);
            color: #FF5500;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.5);
            z-index: 10;
        }

        /* Lightbox Smooth Transition */
        #imageModal {
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        #imageModal.active {
            opacity: 1;
            pointer-events: auto;
        }

        #modalContentBox {
            transform: scale(0.92);
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        #imageModal.active #modalContentBox {
            transform: scale(1);
        }
    </style>
</head>

<body class="font-sans min-h-screen overflow-x-hidden antialiased">

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
                        <img src="../assets/img/logo.png" alt="Korat Esport"
                            class="h-11 w-auto filter drop-shadow-[0_2px_8px_rgba(255,85,0,0.4)] group-hover:scale-105 transition-transform"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span
                                class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors drop-shadow">KORAT
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
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
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
                            class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange transition-all shadow-orange-glow">
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

        <!-- ================= 2. PAGE HEADER (Animated Text) ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-6 w-full text-center space-y-4">
            <div
                class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest backdrop-blur-md animate-fade-down shadow-orange-glow">
                <i class="fa-solid fa-camera"></i> Photo Showcase & Albums
            </div>

            <h1
                class="text-4xl sm:text-6xl font-black font-display text-white tracking-wider uppercase leading-none drop-shadow-[0_0_35px_rgba(255,85,0,0.8)] animate-fade-down">
                <?php if ($selectedAlbumId > 0): ?>
                    อัลบั้ม: <span class="text-brand-orange"><?php echo htmlspecialchars($currentAlbumName); ?></span>
                <?php else: ?>
                    แกลเลอรี่กิจกรรม <span
                        class="text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-300 to-white">(GALLERY)</span>
                <?php endif; ?>
            </h1>

            <p class="text-sm sm:text-base text-gray-300 max-w-xl mx-auto font-normal animate-fade-up">
                <?php if ($selectedAlbumId > 0): ?>
                    รวมภาพบรรยากาศและความประทับใจของอัลบั้มนี้
                <?php else: ?>
                    ประมวลภาพบรรยากาศการแข่งขัน ไฮไลท์สำคัญ และกิจกรรมสุดประทับใจของ Korat Esport
                <?php endif; ?>
            </p>

            <?php if ($selectedAlbumId > 0): ?>
                <div class="pt-2 animate-fade-up">
                    <a href="gallery.php?view=albums"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-white/10 hover:bg-brand-orange text-white text-xs font-bold uppercase tracking-wider transition-all border border-white/20">
                        <i class="fa-solid fa-arrow-left"></i> กลับไปหน้าอัลบั้มทั้งหมด
                    </a>
                </div>
            <?php endif; ?>
        </section>

        <!-- ================= 3. VIEW SWITCHER BAR (Fixed Width Sliding Pill) ================= -->
        <?php if ($selectedAlbumId === 0): ?>
            <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 w-full" data-aos="fade-up">
                <div
                    class="glass-panel p-1.5 rounded-2xl flex items-center justify-between shadow-lg max-w-sm mx-auto relative">
                    <a href="gallery.php?view=all"
                        class="w-1/2 py-2.5 px-2 text-xs font-bold uppercase tracking-wider text-center transition-all z-10 <?php echo $viewMode === 'all' ? 'text-white' : 'text-gray-400 hover:text-white'; ?>">
                        <i class="fa-solid fa-border-all mr-1"></i> ดูรูปทั้งหมด
                    </a>
                    <a href="gallery.php?view=albums"
                        class="w-1/2 py-2.5 px-2 text-xs font-bold uppercase tracking-wider text-center transition-all z-10 <?php echo $viewMode === 'albums' ? 'text-white' : 'text-gray-400 hover:text-white'; ?>">
                        <i class="fa-solid fa-folder-open mr-1"></i> ดูแยกตามอัลบั้ม
                    </a>
                    <!-- Sliding Pill Indicator with exact 50% width -->
                    <div
                        class="absolute top-1.5 bottom-1.5 left-1.5 w-[calc(50%-3px)] rounded-xl bg-brand-orange shadow-orange-glow transition-transform duration-300 ease-out z-0 <?php echo $viewMode === 'albums' ? 'translate-x-full' : 'translate-x-0'; ?>">
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <!-- ================= 4. CONTENT SECTION (Stagger Fade-up) ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 mb-20 w-full">
            <?php if ($selectedAlbumId > 0): ?>
                <?php if (count($images) == 0): ?>
                    <div class="glass-panel p-20 text-center text-gray-300 rounded-3xl max-w-xl mx-auto border border-brand-orange/40 shadow-orange-glow"
                        data-aos="zoom-in">
                        <i class="fa-solid fa-camera text-6xl mb-4 block text-brand-orange animate-bounce"></i>
                        <h3 class="text-2xl font-bold font-display text-white mb-2">ไม่มีรูปภาพในอัลบั้มนี้</h3>
                        <a href="gallery.php?view=albums"
                            class="mt-4 inline-block px-6 py-2.5 rounded-xl bg-brand-orange text-white text-xs font-bold uppercase">กลับหน้าอัลบั้ม</a>
                    </div>
                <?php else: ?>
                    <div class="masonry-wall">
                        <?php foreach ($images as $imgIndex => $img): ?>
                            <div class="masonry-item" data-aos="fade-up" data-aos-delay="<?php echo min($imgIndex * 50, 600); ?>"
                                onclick="openLightboxFromIndex(<?php echo $imgIndex; ?>)">

                                <img src="../assets/<?php echo htmlspecialchars($img['image_path']); ?>"
                                    alt="<?php echo htmlspecialchars($img['caption'] ?? 'Gallery Image'); ?>" loading="lazy">

                                <div class="masonry-overlay space-y-2">
                                    <?php if ($img['caption']): ?>
                                        <p class="text-xs sm:text-sm text-gray-200 font-normal line-clamp-3 leading-relaxed">
                                            <?php echo htmlspecialchars($img['caption']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="text-brand-orange text-xs font-bold pt-1 flex items-center gap-2">
                                        <span>คลิกเพื่อดูรูปภาพเต็มจอ</span>
                                        <i class="fa-solid fa-arrow-right-long"></i>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($viewMode === 'albums'): ?>
                <?php if (count($albums) == 0): ?>
                    <div class="glass-panel p-20 text-center text-gray-300 rounded-3xl max-w-xl mx-auto border border-brand-orange/40 shadow-orange-glow"
                        data-aos="zoom-in">
                        <i class="fa-solid fa-images text-6xl mb-4 block text-brand-orange animate-bounce"></i>
                        <h3 class="text-2xl font-bold font-display text-white mb-2">ยังไม่มีอัลบั้มกิจกรรม</h3>
                        <p class="text-xs text-gray-400">รูปภาพและอัลบั้มกิจกรรมการแข่งขันจะถูกอัปเดตเร็วๆ นี้</p>
                    </div>
                <?php else: ?>
                    <div class="album-grid">
                        <?php foreach ($albums as $albIndex => $alb): ?>
                            <a href="gallery.php?album_id=<?php echo $alb['album_id']; ?>" class="album-card group"
                                data-aos="fade-up" data-aos-delay="<?php echo min($albIndex * 80, 600); ?>">
                                <div class="relative overflow-hidden w-full h-[260px]">
                                    <?php if (!empty($alb['cover_image']) && file_exists('../assets/' . $alb['cover_image'])): ?>
                                        <img src="../assets/<?php echo htmlspecialchars($alb['cover_image']); ?>"
                                            alt="<?php echo htmlspecialchars($alb['album_name']); ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center bg-slate-900 text-slate-600">
                                            <i class="fa-solid fa-folder text-5xl"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div
                                        class="absolute top-4 right-4 bg-black/80 backdrop-blur-md text-white text-xs font-bold px-3 py-1.5 rounded-full border border-white/20 shadow-md">
                                        <i class="fa-solid fa-images text-brand-orange mr-1"></i> <?php echo $alb['image_count']; ?>
                                        รูป
                                    </div>
                                </div>

                                <div class="p-6 bg-gradient-to-t from-black/90 to-transparent space-y-1">
                                    <h3
                                        class="text-lg font-bold font-display text-white group-hover:text-brand-orange transition-colors truncate">
                                        <?php echo htmlspecialchars($alb['album_name']); ?>
                                    </h3>
                                    <div class="text-xs text-brand-orange font-bold flex items-center gap-2 pt-1">
                                        <span>เปิดดูอัลบั้ม</span>
                                        <i
                                            class="fa-solid fa-arrow-right-long group-hover:translate-x-1.5 transition-transform"></i>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <?php if (count($images) == 0): ?>
                    <div class="glass-panel p-20 text-center text-gray-300 rounded-3xl max-w-xl mx-auto border border-brand-orange/40 shadow-orange-glow"
                        data-aos="zoom-in">
                        <i class="fa-solid fa-images text-6xl mb-4 block text-brand-orange animate-bounce"></i>
                        <h3 class="text-2xl font-bold font-display text-white mb-2">ยังไม่มีรูปภาพในแกลเลอรี่</h3>
                        <p class="text-xs text-gray-400">รูปภาพกิจกรรมการแข่งขันจะถูกอัปเดตเร็วๆ นี้</p>
                    </div>
                <?php else: ?>
                    <div class="masonry-wall">
                        <?php foreach ($images as $imgIndex => $img): ?>
                            <div class="masonry-item" data-aos="fade-up" data-aos-delay="<?php echo min($imgIndex * 50, 600); ?>"
                                onclick="openLightboxFromIndex(<?php echo $imgIndex; ?>)">

                                <?php if (!empty($img['display_album_name'])): ?>
                                    <div class="floating-badge">
                                        <i class="fa-solid fa-trophy mr-1 text-[10px]"></i>
                                        <?php echo htmlspecialchars($img['display_album_name']); ?>
                                    </div>
                                <?php endif; ?>

                                <img src="../assets/<?php echo htmlspecialchars($img['image_path'] ?? ''); ?>"
                                    alt="<?php echo htmlspecialchars($img['caption'] ?? 'Gallery Image'); ?>" loading="lazy">

                                <div class="masonry-overlay space-y-2">
                                    <?php if ($img['caption']): ?>
                                        <p class="text-xs sm:text-sm text-gray-200 font-normal line-clamp-3 leading-relaxed">
                                            <?php echo htmlspecialchars($img['caption']); ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="text-brand-orange text-xs font-bold pt-1 flex items-center gap-2">
                                        <span>คลิกเพื่อดูรูปภาพเต็มจอ</span>
                                        <i class="fa-solid fa-arrow-right-long"></i>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- ================= 5. LIGHTBOX MODAL ================= -->
        <div id="imageModal"
            class="fixed inset-0 z-50 bg-black/90 backdrop-blur-md flex items-center justify-center p-4">
            <div id="modalContentBox"
                class="relative max-w-4xl w-full glass-panel rounded-3xl overflow-hidden p-4 border border-white/20 shadow-2xl space-y-4"
                onclick="event.stopPropagation()">

                <button onclick="closeModal()"
                    class="absolute top-4 right-4 z-20 w-10 h-10 rounded-full bg-black/60 hover:bg-brand-orange text-white flex items-center justify-center transition-all text-lg cursor-pointer">
                    <i class="fa-solid fa-xmark"></i>
                </button>

                <button onclick="prevImage()"
                    class="absolute left-4 top-1/2 -translate-y-1/2 z-20 w-12 h-12 rounded-full bg-black/60 hover:bg-brand-orange text-white flex items-center justify-center transition-all text-lg cursor-pointer shadow-lg">
                    <i class="fa-solid fa-chevron-left"></i>
                </button>

                <button onclick="nextImage()"
                    class="absolute right-4 top-1/2 -translate-y-1/2 z-20 w-12 h-12 rounded-full bg-black/60 hover:bg-brand-orange text-white flex items-center justify-center transition-all text-lg cursor-pointer shadow-lg">
                    <i class="fa-solid fa-chevron-right"></i>
                </button>

                <div
                    class="rounded-2xl overflow-hidden max-h-[75vh] flex items-center justify-center bg-black/80 relative">
                    <img id="modalImg" src="" alt="Full Image" class="max-h-[75vh] w-auto object-contain mx-auto">
                </div>

                <div class="p-2 space-y-1 text-left">
                    <h3 id="modalEvent" class="text-sm font-bold text-brand-orange uppercase tracking-wider"></h3>
                    <p id="modalCaption" class="text-xs text-gray-200 font-normal"></p>
                </div>
            </div>
        </div>

        <!-- ================= 6. FOOTER ================= -->
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

    <!-- AOS JS Library & Lightbox JavaScript -->
    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            AOS.init({
                once: true,
                duration: 800,
                easing: 'ease-out-cubic'
            });
        });

        const galleryItems = [
            <?php
            if (isset($images) && count($images) > 0) {
                foreach ($images as $img) {
                    $pSrc = '../assets/' . htmlspecialchars($img['image_path']);
                    $pEvt = htmlspecialchars(addslashes($img['display_album_name'] ?? ''));
                    $pCap = htmlspecialchars(addslashes($img['caption'] ?? ''));
                    echo "{ src: '$pSrc', event: '$pEvt', caption: '$pCap' },";
                }
            }
            ?>
        ];

        let currentIndex = 0;

        function openLightboxFromIndex(index) {
            currentIndex = index;
            updateModalContent();
            const modal = document.getElementById('imageModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function updateModalContent() {
            if (galleryItems.length === 0) return;
            const item = galleryItems[currentIndex];
            document.getElementById('modalImg').src = item.src;
            document.getElementById('modalEvent').innerText = item.event;
            document.getElementById('modalCaption').innerText = item.caption;
        }

        function nextImage() {
            if (galleryItems.length === 0) return;
            currentIndex = (currentIndex + 1) % galleryItems.length;
            updateModalContent();
        }

        function prevImage() {
            if (galleryItems.length === 0) return;
            currentIndex = (currentIndex - 1 + galleryItems.length) % galleryItems.length;
            updateModalContent();
        }

        function closeModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        document.addEventListener('keydown', (e) => {
            const modal = document.getElementById('imageModal');
            if (!modal.classList.contains('active')) return;

            if (e.key === 'Escape') closeModal();
            if (e.key === 'ArrowRight') nextImage();
            if (e.key === 'ArrowLeft') prevImage();
        });

        document.getElementById('imageModal').addEventListener('click', closeModal);
    </script>
</body>

</html>