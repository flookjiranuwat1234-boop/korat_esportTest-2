<?php
// pages/news-detail.php
require_once '../config/db.php';
require_once '../includes/auth.php';

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$newsId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT n.*, u.username 
    FROM news n 
    LEFT JOIN users u ON u.user_id = n.created_by
    WHERE n.news_id = :id AND n.status = 'published'
");
$stmt->execute(['id' => $newsId]);
$news = $stmt->fetch();

if (!$news) {
    die('
    <div style="min-height:100vh; background-color:#0F1117; color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif; padding:5rem 2rem; text-align:center;">
        <h1 style="font-size:2rem; color:#FF5500; font-weight:bold;">ไม่พบข่าวสารที่ต้องการ</h1>
        <p style="color:#aaa; margin-top:0.5rem;">ข่าวสารนี้อาจถูกลบ ออกจากระบบ หรือยังไม่ได้เปิดเผยแพร่</p>
        <a href="news.php" style="margin-top:2rem; padding:0.8rem 1.5rem; background-color:#FF5500; color:#fff; text-decoration:none; border-radius:12px; font-weight:bold;">&larr; กลับไปหน้าข่าวสารทั้งหมด</a>
    </div>
    ');
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($news['title']); ?> - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap"
        rel="stylesheet">
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
                        'orange-glow': '0 0 25px rgba(255, 85, 0, 0.45)'
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
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.55), rgba(15, 17, 23, 0.90)),
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

        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }

        /* Reading Progress Bar ด้านบนสุด */
        #reading-progress {
            position: fixed;
            top: 0;
            left: 0;
            height: 3px;
            width: 0%;
            background: linear-gradient(90deg, #FF5500, #ff9900);
            box-shadow: 0 0 12px #FF5500;
            z-index: 100;
            transition: width 0.1s ease-out;
        }

        /* Ken Burns Effect สำหรับรูป Featured Image */
        @keyframes kenBurnsNews {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.04);
            }

            100% {
                transform: scale(1);
            }
        }

        .animate-ken-burns-news {
            animation: kenBurnsNews 20s ease-in-out infinite;
        }

        /* Keyframe Animations สำหรับ Header */
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

        .animate-fade-down {
            animation: fadeInDown 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
    </style>
</head>

<body class="font-sans min-h-screen overflow-x-hidden antialiased">

    <!-- Reading Progress Bar -->
    <div id="reading-progress"></div>

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

                    <!-- Public Menu Items -->
                    <nav class="hidden md:flex items-center gap-1 lg:gap-2">
                        <a href="index.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-house text-xs mr-1.5"></i> หน้าแรก
                        </a>
                        <a href="tournaments.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-trophy text-xs mr-1.5"></i> ทัวร์นาเมนต์
                        </a>
                        <a href="ranking.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-ranking-star text-xs mr-1.5"></i> ตารางคะแนน
                        </a>
                        <a href="news.php"
                            class="px-4 py-2 rounded-xl text-sm font-bold text-white bg-brand-orange transition-all shadow-md">
                            <i class="fa-solid fa-newspaper text-xs mr-1.5"></i> ข่าวสาร
                        </a>
                        <a href="gallery.php"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                            <i class="fa-solid fa-images text-xs mr-1.5"></i> แกลเลอรี่
                        </a>

                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php"
                                class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all drop-shadow-sm">
                                <i class="fa-solid fa-hotel text-xs mr-1.5"></i> ที่พักแนะนำ
                            </a>
                        <?php endif; ?>
                    </nav>

                    <!-- User Status / Auth Buttons -->
                    <div class="flex items-center gap-4 text-base font-bold drop-shadow">
                        <?php if ($isLoggedIn): ?>
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
                            <a href="../auth/login.php"
                                class="text-brand-orange hover:text-brand-glow transition-colors">เข้าสู่ระบบ</a>
                            <a href="../auth/register.php"
                                class="text-white hover:text-brand-orange transition-colors">สมัครสมาชิก</a>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </header>

        <!-- ================= 2. MAIN ARTICLE CONTENT ================= -->
        <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full space-y-6 relative">

            <!-- Back Button with Arrow Slide Effect -->
            <div>
                <a href="news.php"
                    class="group inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/15 text-xs font-bold text-gray-300 hover:text-white transition-all backdrop-blur-md">
                    <i
                        class="fa-solid fa-arrow-left text-brand-orange group-hover:-translate-x-1 transition-transform"></i>
                    <span>กลับไปหน้าข่าวสารทั้งหมด</span>
                </a>
            </div>

            <!-- Article Reader Container -->
            <article id="article-container"
                class="glass-panel p-6 sm:p-10 rounded-3xl border border-white/20 shadow-2xl space-y-8">

                <!-- Title & Meta Header with Fade-down -->
                <div class="space-y-4 border-b border-white/15 pb-6 animate-fade-down">
                    <div
                        class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-orange/20 border border-brand-orange/40 text-brand-orange text-[11px] font-bold uppercase tracking-wider">
                        <i class="fa-solid fa-bullhorn"></i> ข่าวประชาสัมพันธ์
                    </div>

                    <h1 class="text-2xl sm:text-4xl font-black font-display text-white leading-snug drop-shadow-md">
                        <?php echo htmlspecialchars($news['title']); ?>
                    </h1>

                    <div class="flex flex-wrap items-center gap-4 text-xs font-medium text-gray-300 pt-2">
                        <span class="flex items-center gap-1.5 text-brand-orange font-bold">
                            <i class="fa-regular fa-calendar-alt"></i>
                            <?php echo date('d/m/Y H:i', strtotime($news['created_at'])); ?> น.
                        </span>
                        <span class="text-gray-500">•</span>
                        <span class="flex items-center gap-1.5 text-gray-300">
                            <i class="fa-solid fa-user-pen text-amber-400"></i>
                            เผยแพร่โดย: <strong
                                class="text-white"><?php echo htmlspecialchars($news['username'] ?? 'Admin Korat Esport'); ?></strong>
                        </span>
                    </div>
                </div>

                <!-- Featured Image with Ken Burns Zoom -->
                <?php if (!empty($news['image_path'])): ?>
                    <div class="rounded-2xl overflow-hidden border border-white/15 bg-black/50 shadow-lg relative">
                        <img src="../assets/<?php echo htmlspecialchars($news['image_path']); ?>"
                            alt="<?php echo htmlspecialchars($news['title']); ?>"
                            class="w-full max-h-[480px] object-cover mx-auto animate-ken-burns-news">
                    </div>
                <?php endif; ?>

                <!-- News Body Text (Clean & Static for readability) -->
                <div
                    class="text-gray-200 leading-relaxed text-base sm:text-lg font-normal space-y-4 whitespace-pre-line border-b border-white/15 pb-8">
                    <?php echo htmlspecialchars($news['content']); ?>
                </div>

                <!-- Footer Action -->
                <div class="flex items-center pt-2">
                    <a href="news.php"
                        class="group inline-flex items-center gap-2 text-xs font-bold text-brand-orange hover:underline uppercase tracking-wider">
                        <i class="fa-solid fa-chevron-left group-hover:-translate-x-1 transition-transform"></i>
                        <span>กลับไปอ่านข่าวอื่น</span>
                    </a>
                </div>

            </article>
        </main>

        <!-- ================= 3. FOOTER ================= -->
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

    <!-- Script สำหรับคำนวณ Reading Progress Bar ตามการเลื่อนอ่านใน <article> -->
    <script>
        window.addEventListener('scroll', () => {
            const article = document.getElementById('article-container');
            const progressBar = document.getElementById('reading-progress');
            if (!article || !progressBar) return;

            const articleRect = article.getBoundingClientRect();
            const articleHeight = article.offsetHeight;
            const windowHeight = window.innerHeight;

            // คำนวณเปอร์เซ็นต์ความคืบหน้าในการเลื่อนผ่าน <article>
            const scrollTop = window.scrollY || document.documentElement.scrollTop;
            const articleTop = article.offsetTop;
            const distanceScrolled = scrollTop - articleTop + windowHeight * 0.2;
            const totalScrollable = articleHeight - windowHeight * 0.5;

            let percentage = (distanceScrolled / totalScrollable) * 100;
            percentage = Math.max(0, Math.min(100, percentage));

            progressBar.style.width = percentage + '%';
        });
    </script>
</body>

</html>