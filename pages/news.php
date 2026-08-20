<?php
// pages/news.php
require_once '../config/db.php';
require_once '../includes/auth.php';
// หมายเหตุ: ไม่มีการเรียก requireLogin() ในไฟล์นี้ เพื่อให้ผู้ใช้งานทั่วไปเข้าชมข่าวสารได้ตามขอบเขตระบบ

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$newsList = $pdo->query("
    SELECT news_id, title, content, image_path, created_at
    FROM news
    WHERE status = 'published'
    ORDER BY created_at DESC
")->fetchAll();

// ตัดเนื้อหาให้สั้นลงสำหรับโชว์ในหน้ารายการ
function excerpt($text, $length = 150)
{
    $text = strip_tags($text);
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}

function getNewsImages($imagePathStr) {
    if (empty($imagePathStr)) return [];
    if (str_starts_with(trim($imagePathStr), '[') || str_starts_with(trim($imagePathStr), '{')) {
        $decoded = json_decode($imagePathStr, true);
        if (is_array($decoded)) return $decoded;
    }
    return array_map('trim', explode(',', $imagePathStr));
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข่าวสารและประกาศ - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
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
                        'orange-glow': '0 0 35px rgba(255, 85, 0, 0.6)',
                        'cyber-glow': '0 0 25px rgba(255, 85, 0, 0.4)'
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
html, body {
    -ms-overflow-style: none;  /* IE และ Edge */
    scrollbar-width: none;  /* Firefox */
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
            background: rgba(10, 10, 12, 0.88);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 85, 0, 0.3);
        }

        .glass-panel {
            background: rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .grid-bg {
            background-image: radial-gradient(rgba(255, 85, 0, 0.15) 1px, transparent 0);
            background-size: 30px 30px;
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

        /* Animated Expanding Cards (Accordion Slider Effect) */
        .slider-container {
            display: flex;
            width: 100%;
            height: 520px;
            gap: 16px;
            overflow-x: auto;
            padding-bottom: 10px;
            scrollbar-width: thin;
            scrollbar-color: #FF5500 rgba(255,255,255,0.1);
        }
        .slider-container::-webkit-scrollbar {
            height: 6px;
        }
        .slider-container::-webkit-scrollbar-thumb {
            background: #FF5500;
            border-radius: 10px;
        }

        .slide-card {
            position: relative;
            min-width: 90px;
            height: 100%;
            border-radius: 2rem;
            overflow: hidden;
            cursor: pointer;
            flex: 1;
            transition: flex 0.6s cubic-bezier(0.25, 1, 0.5, 1);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .slide-card img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: brightness(0.45);
            transform: scale(1);
            transition: transform 0.7s cubic-bezier(0.25, 1, 0.5, 1), filter 0.5s ease;
        }

        .slide-card:hover, .slide-card.active {
            flex: 5;
            border-color: #FF5500;
            box-shadow: 0 0 35px rgba(255, 85, 0, 0.4);
        }

        .slide-card:hover img, .slide-card.active img {
            transform: scale(1.12);
            filter: brightness(0.75);
        }

        /* Staggered Content Animation ภายในเนื้อหาตอนขยาย */
        .slide-content {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 40px;
            background: linear-gradient(to top, rgba(10,10,12,0.95), rgba(10,10,12,0.6) 60%, transparent);
            color: #fff;
            text-align: left;
        }

        .slide-content > * {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.4s ease, transform 0.4s ease;
        }

        .slide-card:hover .slide-content > *, .slide-card.active .slide-content > * {
            opacity: 1;
            transform: translateY(0);
        }

        /* Stagger Delays สำหรับแต่ละบรรทัด */
        .slide-card:hover .slide-content > *:nth-child(1), .slide-card.active .slide-content > *:nth-child(1) { transition-delay: 0.1s; }
        .slide-card:hover .slide-content > *:nth-child(2), .slide-card.active .slide-content > *:nth-child(2) { transition-delay: 0.18s; }
        .slide-card:hover .slide-content > *:nth-child(3), .slide-card.active .slide-content > *:nth-child(3) { transition-delay: 0.26s; }
        .slide-card:hover .slide-content > *:nth-child(4), .slide-card.active .slide-content > *:nth-child(4) { transition-delay: 0.34s; }

        /* Vertical text when collapsed */
        .slide-collapsed-title {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) rotate(-90deg);
            transform-origin: center;
            white-space: nowrap;
            color: #fff;
            font-size: 0.85rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            transition: opacity 0.3s ease;
            opacity: 0.9;
            font-family: 'Orbitron', sans-serif;
        }

        .slide-card:hover .slide-collapsed-title, .slide-card.active .slide-collapsed-title {
            opacity: 0;
            pointer-events: none;
        }

        /* Progress Bar ระหว่าง Auto-play */
        .slide-progress-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 4px;
            width: 0%;
            background: #FF5500;
            box-shadow: 0 0 10px #FF5500;
            z-index: 10;
        }
        .slide-card.active .slide-progress-bar {
            animation: progressFill 4.5s linear forwards;
        }
        @keyframes progressFill {
            0% { width: 0%; }
            100% { width: 100%; }
        }

        @media (max-width: 768px) {
            .slider-container {
                flex-direction: column;
                height: auto;
            }
            .slide-card {
                min-width: 100%;
                height: 320px;
            }
            .slide-collapsed-title {
                display: none;
            }
            .slide-content > * {
                opacity: 1;
                transform: translateY(0);
            }
            .slide-progress-bar {
                display: none;
            }
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
                        <img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto filter drop-shadow-[0_2px_8px_rgba(255,85,0,0.4)] group-hover:scale-105 transition-transform" onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors drop-shadow">KORAT <span class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] tracking-widest text-gray-400 font-bold uppercase -mt-1">Official Arena & Hub</span>
                        </div>
                    </a>

                    <!-- Public Menu Items -->
                    <nav class="hidden md:flex items-center gap-1 lg:gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-house text-xs mr-1.5"></i> หน้าแรก
                        </a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-trophy text-xs mr-1.5"></i> ทัวร์นาเมนต์
                        </a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-ranking-star text-xs mr-1.5"></i> ตารางคะแนน
                        </a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange transition-all shadow-orange-glow">
                            <i class="fa-solid fa-newspaper text-xs mr-1.5"></i> ข่าวสาร
                        </a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                            <i class="fa-solid fa-images text-xs mr-1.5"></i> แกลเลอรี่
                        </a>

                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-300 hover:text-brand-orange hover:bg-white/10 transition-all">
                                <i class="fa-solid fa-hotel text-xs mr-1.5"></i> ที่พักแนะนำ
                            </a>
                        <?php endif; ?>
                    </nav>

                    <!-- User Status / Auth Buttons -->
                    <div class="flex items-center gap-4 text-base font-bold">
                        <?php if ($isLoggedIn): ?>
                            <div class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md shadow-cyber-glow">
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

        <!-- ================= 2. PAGE HEADER (Animated Text ขยับได้ตอนเข้ามา) ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-6 w-full text-center space-y-4">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest backdrop-blur-md animate-fade-down shadow-orange-glow">
                <i class="fa-solid fa-bullhorn"></i> News & Announcements
            </div>
            
            <h1 class="text-4xl sm:text-6xl font-black font-display text-white tracking-wider uppercase leading-none drop-shadow-[0_0_35px_rgba(255,85,0,0.8)] animate-fade-down">
                ข่าวสารและประกาศ <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-300 to-white">(NEWS)</span>
            </h1>

            <p class="text-sm sm:text-base text-gray-300 max-w-xl mx-auto font-normal animate-fade-up">
                ติดตามข่าวสารการแข่งขัน ประกาศสำคัญ และอัปเดตกิจกรรมประจำวงการอีสปอร์ต Korat Esport
            </p>
        </section>

        <!-- ================= 3. ANIMATED ACCORDION SLIDER FOR NEWS ================= -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 mb-16 w-full">
            <?php if (count($newsList) == 0): ?>
                <div class="glass-panel p-20 text-center text-gray-300 rounded-3xl max-w-xl mx-auto border border-brand-orange/40 shadow-orange-glow">
                    <i class="fa-solid fa-newspaper text-6xl mb-4 block text-brand-orange animate-bounce"></i>
                    <h3 class="text-2xl font-bold font-display text-white mb-2">ยังไม่มีประกาศข่าวสาร</h3>
                    <p class="text-xs text-gray-400">โปรดรอติดตามข่าวอัปเดตและกำหนดการแข่งขันใหม่ๆ จากผู้จัดเร็วๆ นี้</p>
                </div>
            <?php else: ?>
                <!-- Accordion Slider Container -->
                <div class="slider-container" id="newsSliderContainer">
                    <?php foreach ($newsList as $index => $n): 
                        $cardImages = getNewsImages($n['image_path']);
                        $bgImg = !empty($cardImages[0]) ? '../assets/' . htmlspecialchars($cardImages[0]) : 'https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop';
                        
                        // เช็คว่าเป็นข่าวใหม่ภายใน 48 ชั่วโมงหรือไม่
                        $isNew = (time() - strtotime($n['created_at'])) <= (48 * 3600);
                    ?>
                        <div class="slide-card <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>">
                            
                            <img src="<?php echo $bgImg; ?>" alt="<?php echo htmlspecialchars($n['title']); ?>">

                            <!-- เส้น Progress Bar วิ่งระหว่าง Auto-play -->
                            <div class="slide-progress-bar"></div>

                            <!-- ชื่อหัวข้อแนวตั้งตอนยังไม่ขยาย -->
                            <div class="slide-collapsed-title">
                                <?php echo htmlspecialchars(mb_substr($n['title'], 0, 20) . '...'); ?>
                            </div>

                            <!-- รายละเอียดชิดซ้ายที่จะโผล่ขึ้นมาตอนขยาย (Staggered Children) -->
                            <div class="slide-content space-y-3 max-w-2xl">
                                <div class="flex items-center gap-3 text-xs font-bold font-display flex-wrap">
                                    <span class="px-3 py-1 rounded-lg bg-brand-orange/20 border border-brand-orange/40 text-brand-orange flex items-center gap-1.5">
                                        <i class="regular fa-calendar-alt"></i> <?php echo date('d M Y - H:i', strtotime($n['created_at'])); ?>
                                    </span>
                                    
                                    <?php if ($isNew): ?>
                                        <span class="px-3 py-1 rounded-lg bg-rose-500/30 border border-rose-400 text-rose-300 uppercase tracking-widest text-[10px] animate-pulse flex items-center gap-1 shadow-md">
                                            <i class="fa-solid fa-bolt"></i> ข่าวใหม่
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h2 class="text-2xl sm:text-3xl font-black font-display text-white leading-tight line-clamp-2">
                                    <?php echo htmlspecialchars($n['title']); ?>
                                </h2>

                                <p class="text-xs sm:text-sm text-gray-300 leading-relaxed line-clamp-3 font-normal">
                                    <?php echo htmlspecialchars(excerpt($n['content'], 180)); ?>
                                </p>

                                <div class="pt-2">
                                    <a href="news-detail.php?id=<?php echo $n['news_id']; ?>" 
                                       class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white text-xs font-bold uppercase tracking-widest transition-all shadow-orange-glow">
                                        <span>อ่านข่าวเต็มรูปแบบ</span>
                                        <i class="fa-solid fa-arrow-right"></i>
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

    <!-- Script สำหรับ Accordion Slider (รองรับทั้ง Hover, Click/Tap บนมือถือ และ Auto-Play พร้อม Progress Bar) -->
    <script>
        const cards = document.querySelectorAll('.slide-card');
        const totalCards = cards.length;
        let currentIndex = 0;
        let autoplayInterval = null;
        let userInteracted = false;

        function setActiveCard(index) {
            cards.forEach(c => c.classList.remove('active'));
            cards[index].classList.add('active');
        }

        function startAutoplay() {
            if (totalCards <= 1 || userInteracted) return;
            autoplayInterval = setInterval(() => {
                if (userInteracted) {
                    clearInterval(autoplayInterval);
                    return;
                }
                currentIndex = (currentIndex + 1) % totalCards;
                setActiveCard(currentIndex);
            }, 4500);
        }

        // ผูก Event ทั้ง Mouseenter (Desktop) และ Click/Touch (Mobile & Desktop Fallback)
        cards.forEach((card, index) => {
            card.addEventListener('mouseenter', () => {
                userInteracted = true;
                if (autoplayInterval) clearInterval(autoplayInterval);
                currentIndex = index;
                setActiveCard(currentIndex);
            });

            card.addEventListener('click', () => {
                userInteracted = true;
                if (autoplayInterval) clearInterval(autoplayInterval);
                currentIndex = index;
                setActiveCard(currentIndex);
            });
        });

        // เริ่มระบบ Auto-Play ทันทีหลังโหลดหน้า
        if (totalCards > 1) {
            startAutoplay();
        }
    </script>
</body>
</html>