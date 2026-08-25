<?php
// pages/claim-profile.php
// หน้านี้ให้ user ที่เพิ่งสมัครสมาชิก (ยังไม่มีโปรไฟล์ players) เลือกว่า
// จะ "claim" โปรไฟล์เก่าที่ import มาจาก RoV (ที่ user_id ยังเป็น NULL อยู่)
// หรือจะสร้างโปรไฟล์ใหม่เอี่ยมแทน (ต้องกรอกชื่อในเกมก่อนเสมอ ห้ามสร้างเปล่าๆ)
require_once '../config/db.php';
require_once '../includes/auth.php';
requireLogin();

// ถ้ามีโปรไฟล์อยู่แล้ว ไม่ต้องมาหน้านี้อีก เด้งกลับไปหน้าโปรไฟล์เลย
$check = $pdo->prepare("SELECT player_id FROM players WHERE user_id = :user_id");
$check->execute(['user_id' => $_SESSION['user_id']]);
if ($check->fetchColumn()) {
    header('Location: profile.php');
    exit;
}

// ตรวจสอบสถานะการเข้าสู่ระบบ (สำหรับ navbar)
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$error = '';
$searchResults = [];
$query = trim($_GET['q'] ?? '');
$newDisplayName = trim($_POST['display_name'] ?? '');

// ค้นหาโปรไฟล์เก่าที่ยังไม่มีเจ้าของ จากชื่อในเกม หรือชื่อทีม
if ($query !== '') {
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.player_id, p.display_name,
            (SELECT t.name FROM team_members tm
             JOIN teams t ON t.team_id = tm.team_id
             WHERE tm.player_id = p.player_id AND tm.is_active = 1
             LIMIT 1) AS team_name
        FROM players p
        LEFT JOIN team_members tm ON tm.player_id = p.player_id
        LEFT JOIN teams t ON t.team_id = tm.team_id
        WHERE p.user_id IS NULL
          AND (p.display_name LIKE :q OR t.name LIKE :q2)
        LIMIT 20
    ");
    $stmt->execute(['q' => "%{$query}%", 'q2' => "%{$query}%"]);
    $searchResults = $stmt->fetchAll();
}

// กด claim โปรไฟล์ที่เจอ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'claim') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $playerId = (int) $_POST['player_id'];

        // เช็คซ้ำว่ายังไม่มีใครแอบ claim ไปก่อนหน้านี้ (กันกรณีกดพร้อมกัน)
        $claim = $pdo->prepare("
            UPDATE players SET user_id = :user_id
            WHERE player_id = :player_id AND user_id IS NULL
        ");
        $claim->execute(['user_id' => $_SESSION['user_id'], 'player_id' => $playerId]);

        if ($claim->rowCount() > 0) {
            header('Location: profile.php?claimed=1');
            exit;
        } else {
            $error = 'โปรไฟล์นี้เพิ่งถูก claim ไปแล้วโดยคนอื่น ลองค้นหาใหม่';
        }
    }
}

// กดสร้างโปรไฟล์ใหม่ (หาไม่เจอในระบบ) — ต้องกรอกชื่อในเกมก่อนเสมอ ห้ามสร้างเปล่าๆ ด้วยชื่อ username อัตโนมัติ
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'create_new') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } elseif ($newDisplayName === '') {
        $error = 'กรุณากรอกชื่อในเกมก่อนสร้างโปรไฟล์';
    } elseif (mb_strlen($newDisplayName) > 50) {
        $error = 'ชื่อในเกมยาวเกินไป (ไม่เกิน 50 ตัวอักษร)';
    } else {
        $insert = $pdo->prepare("
            INSERT INTO players (user_id, display_name) VALUES (:user_id, :display_name)
        ");
        $insert->execute([
            'user_id' => $_SESSION['user_id'],
            'display_name' => $newDisplayName,
        ]);
        header('Location: profile.php?created=1');
        exit;
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ค้นหาโปรไฟล์ - Korat Esport</title>
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

        .glass-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transition: all 0.3s ease;
        }
        .glass-card:hover {
            background: rgba(255, 255, 255, 0.12);
            border-color: rgba(255, 85, 0, 0.5);
        }

        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
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

        <!-- ================= 2. MAIN CONTENT ================= -->
        <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full space-y-8">

            <!-- Intro Card -->
            <div class="glass-panel p-6 sm:p-10 rounded-3xl border border-white/20 shadow-2xl space-y-4 text-center">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-orange/20 border border-brand-orange/40 text-brand-orange text-xs font-bold uppercase tracking-widest mx-auto">
                    <i class="fa-solid fa-user-magnifying-glass"></i> ขั้นตอนสุดท้ายก่อนใช้งาน
                </div>
                <h1 class="text-2xl sm:text-3xl font-black font-display text-white uppercase tracking-wide">
                    คุณเคยสมัครแข่งขันมาก่อนไหม?
                </h1>
                <p class="text-sm text-gray-300 max-w-xl mx-auto leading-relaxed">
                    ถ้าเคยสมัครแข่งขัน RoV กับสโมสรมาก่อน ระบบอาจมีข้อมูลชื่อในเกมของคุณอยู่แล้ว
                    ลองค้นหาชื่อในเกม หรือชื่อทีมของคุณดูก่อน จะได้ไม่ต้องสร้างโปรไฟล์ใหม่ซ้ำซ้อน
                </p>
            </div>

            <?php if ($error): ?>
                <div class="p-4 rounded-2xl bg-rose-500/20 border border-rose-500/40 text-rose-200 text-sm font-semibold flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-lg shrink-0"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Search Card -->
            <div class="glass-panel p-6 sm:p-8 rounded-3xl border border-white/20 shadow-xl space-y-5">
                <h2 class="text-sm font-bold font-display text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass text-brand-orange"></i> ค้นหาโปรไฟล์เก่าของคุณ
                </h2>

                <form method="GET" class="flex flex-col sm:flex-row gap-3">
                    <input type="text" name="q" placeholder="ชื่อในเกม หรือ ชื่อทีม" value="<?php echo htmlspecialchars($query); ?>"
                        class="flex-1 bg-black/40 border border-white/15 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-orange font-medium">
                    <button type="submit"
                        class="px-6 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm uppercase tracking-wider transition-all shadow-orange-glow flex items-center justify-center gap-2 cursor-pointer">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <span>ค้นหา</span>
                    </button>
                </form>

                <?php if ($query !== ''): ?>
                    <div class="pt-2 space-y-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-400">ผลการค้นหา</h3>

                        <?php if (count($searchResults) == 0): ?>
                            <div class="p-6 text-center text-gray-400 text-sm bg-black/30 rounded-2xl border border-white/10">
                                <i class="fa-regular fa-face-frown text-2xl mb-2 block opacity-50"></i>
                                ไม่พบข้อมูลที่ตรงกัน
                            </div>
                        <?php endif; ?>

                        <?php foreach ($searchResults as $r): ?>
                            <div class="glass-card p-4 rounded-2xl flex items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="font-bold text-white text-sm truncate">
                                        <i class="fa-solid fa-gamepad text-brand-orange mr-1.5"></i>
                                        <?php echo htmlspecialchars($r['display_name']); ?>
                                    </p>
                                    <?php if ($r['team_name']): ?>
                                        <p class="text-xs text-gray-400 mt-0.5">ทีม <?php echo htmlspecialchars($r['team_name']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" onsubmit="return confirm('ยืนยันว่านี่คือคุณจริงๆ? เปลี่ยนแปลงทีหลังยาก');" class="shrink-0">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="claim">
                                    <input type="hidden" name="player_id" value="<?php echo $r['player_id']; ?>">
                                    <button type="submit"
                                        class="px-4 py-2 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs uppercase tracking-wider transition-all shadow-md cursor-pointer whitespace-nowrap">
                                        นี่คือฉัน
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Create New Profile Card -->
            <div class="glass-panel p-6 sm:p-8 rounded-3xl border border-white/20 shadow-xl space-y-5">
                <h2 class="text-sm font-bold font-display text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-user-plus text-brand-orange"></i> หาไม่เจอ? สร้างโปรไฟล์ใหม่
                </h2>
                <p class="text-xs text-gray-400">
                    ถ้าไม่เคยสมัครแข่งขันมาก่อน หรือหาชื่อตัวเองในระบบไม่เจอ กรอกชื่อในเกมที่ต้องการใช้แล้วสร้างโปรไฟล์ใหม่ได้เลย
                </p>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="create_new">

                    <div class="space-y-2">
                        <label class="block text-xs font-bold uppercase text-gray-300 tracking-wider">ชื่อในเกม (Display Name)</label>
                        <input type="text" name="display_name" required maxlength="50"
                            value="<?php echo htmlspecialchars($newDisplayName); ?>"
                            placeholder="เช่น NightWolf_th"
                            class="w-full bg-black/40 border border-white/15 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-brand-orange font-medium">
                        <p class="text-[11px] text-gray-500">ตั้งชื่อที่อยากให้คนอื่นเห็นบนโปรไฟล์และในสายการแข่งขัน แก้ไขทีหลังได้จากหน้าโปรไฟล์</p>
                    </div>

                    <button type="submit"
                        class="w-full py-3.5 rounded-xl bg-white/10 hover:bg-brand-orange border border-white/20 hover:border-transparent text-white font-bold text-sm uppercase tracking-wider transition-all flex items-center justify-center gap-2 cursor-pointer">
                        <i class="fa-solid fa-plus"></i>
                        <span>สร้างโปรไฟล์ใหม่</span>
                    </button>
                </form>
            </div>

        </main>

        <!-- ================= 3. FOOTER ================= -->
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
</body>
</html>
```