<?php
// pages/create-team.php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireLogin();

// ดึงข้อมูล Player จาก user_id
$stmt = $pdo->prepare("SELECT player_id FROM players WHERE user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$playerId = $stmt->fetchColumn();

if (!$playerId) {
    header('Location: claim-profile.php');
    exit;
}

$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $teamName = trim($_POST['team_name'] ?? '');
        $logoPath = null;

        if (empty($teamName)) {
            $error = 'กรุณากรอกชื่อทีม';
        } else {
            // เช็กการอัปโหลดรูปโลโก้ทีม (ถ้ามี)
            if (isset($_FILES['team_logo']) && $_FILES['team_logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['team_logo']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];

                if (in_array($ext, $allowed)) {
                    $uploadDir = '../assets/uploads/teams/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    $fileName = 'team_' . time() . '_' . rand(100, 999) . '.' . $ext;
                    if (move_uploaded_file($_FILES['team_logo']['tmp_name'], $uploadDir . $fileName)) {
                        $logoPath = 'uploads/teams/' . $fileName;
                    }
                } else {
                    $error = 'รูปโลโก้รองรับเฉพาะไฟล์ JPG, PNG และ WEBP';
                }
            }

            if (empty($error)) {
                // 1. สร้างทีมกลาง (Global Team) โดยไม่บังคับ game_id และ team_category
                $insert = $pdo->prepare("INSERT INTO teams (name, logo_path, captain_player_id, game_id) VALUES (:name, :logo, :captain, NULL)");
                $insert->execute([
                    'name' => $teamName,
                    'logo' => $logoPath,
                    'captain' => $playerId
                ]);
                $teamId = $pdo->lastInsertId();

                // 2. เพิ่มตัวเองเป็นสมาชิกทีมทันที
                $addMember = $pdo->prepare("
                    INSERT INTO team_members (team_id, player_id, in_game_role, is_active) 
                    VALUES (:team_id, :player_id, 'Captain', 1)
                ");
                $addMember->execute(['team_id' => $teamId, 'player_id' => $playerId]);

                // ส่งกลับไปหน้าโปรไฟล์พร้อมแสดงแจ้งเตือนสร้างทีมสำเร็จ
                setFlashMessage('success', 'สร้างทีมเรียบร้อยแล้ว คุณเป็นกัปตันทีม');
                header('Location: profile.php', true, 303);
                exit;
            }
        }
    }
}

$csrfToken = generateCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: ($success ?? 'สร้างทีมเรียบร้อยแล้ว'));
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'create-team.php'), true, 303);
    exit;
}
$flash = consumeFlashMessage();
if ($flash) $error = $flash['type'] === 'error' ? $flash['message'] : ($success = $flash['message']);
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สร้างทีมใหม่ - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&family=Orbitron:wght@700;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { orange: '#FF5500', glow: '#FF7700', dark: '#0A0A0C' }
                    },
                    fontFamily: { sans: ['Kanit', 'sans-serif'], display: ['Orbitron', 'sans-serif'] },
                    boxShadow: { 'orange-glow': '0 0 25px rgba(255, 85, 0, 0.45)' }
                }
            }
        }
    </script>
    <style>
        ::-webkit-scrollbar { display: none; }
        html, body { -ms-overflow-style: none; scrollbar-width: none; }
        body { background-color: #0F1117; }
        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.60), rgba(15, 17, 23, 0.95)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .glass-nav {
            background: rgba(15, 17, 23, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
        }
        .glass-panel {
            background: rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }
    </style>
</head>

<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>

    <div class="relative z-10 flex flex-col min-h-screen">

        <!-- NAVBAR -->
        <header class="sticky top-0 z-50 glass-nav">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <a href="index.php" class="flex items-center gap-3">
                        <img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl text-white">KORAT <span
                                    class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] text-gray-200 font-bold uppercase -mt-1">Official Arena & Hub</span>
                        </div>
                    </a>

                    <nav class="hidden md:flex items-center gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">หน้าแรก</a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ทัวร์นาเมนต์</a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ตารางคะแนน</a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ข่าวสาร</a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">แกลเลอรี่</a>
                    </nav>

                    <div class="flex items-center gap-3 bg-white/10 p-1.5 pl-3.5 rounded-2xl">
                        <a href="profile.php" class="text-sm font-bold text-white hover:text-brand-orange transition-colors">
                            <i class="fa-solid fa-user text-xs mr-1 text-brand-orange"></i> โปรไฟล์ของฉัน
                        </a>
                        <a href="../auth/logout.php" title="ออกจากระบบ"
                            class="w-9 h-9 rounded-xl bg-rose-500/20 text-rose-300 flex items-center justify-center hover:bg-rose-600 hover:text-white">
                            <i class="fa-solid fa-right-from-bracket"></i>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- MAIN FORM CONTENT -->
        <main class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full my-auto">

            <div class="glass-panel p-6 sm:p-10 rounded-3xl border border-white/20 shadow-2xl space-y-6">
                <div class="text-center space-y-2 border-b border-white/10 pb-6">
                    <div
                        class="w-16 h-16 rounded-2xl bg-brand-orange/20 border border-brand-orange/40 text-brand-orange flex items-center justify-center text-3xl mx-auto shadow-orange-glow">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-black font-display text-white uppercase tracking-wider">
                        สร้างทีมสโมสรใหม่ (CREATE TEAM)
                    </h1>
                    <p class="text-xs text-gray-400">
                        ลงทะเบียนแบรนด์สโมสรทีมกลางของคุณ เพื่อใช้สมัครเข้าร่วมการแข่งขันได้ทุกเกม
                    </p>
                </div>

                <!-- ALERT ERROR -->
                <?php if ($error): ?>
                    <div
                        class="p-4 rounded-2xl bg-rose-500/20 border border-rose-500/40 text-rose-200 text-xs font-bold flex items-center gap-3">
                        <i class="fa-solid fa-circle-exclamation text-lg text-rose-400"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <!-- ชื่อทีม -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-gray-300 mb-1">
                            <i class="fa-solid fa-signature text-brand-orange mr-1"></i> ชื่อทีม / สโมสร (Team Name):
                            <span class="text-rose-400">*</span>
                        </label>
                        <input type="text" name="team_name" placeholder="ระบุชื่อทีมแข่งขัน..." required
                            class="w-full bg-black/60 border border-white/20 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-brand-orange">
                    </div>

                    <!-- อัปโหลดโลโก้ทีม -->
                    <div>
                        <label class="block text-xs font-bold uppercase text-gray-300 mb-1">
                            <i class="fa-solid fa-image text-brand-orange mr-1"></i> โลโก้สโมสรประจำทีม (Team Logo):
                        </label>
                        <input type="file" name="team_logo" accept="image/*"
                            class="w-full text-xs text-gray-300 bg-black/60 border border-white/20 rounded-xl p-2.5 focus:outline-none focus:border-brand-orange">
                        <span class="text-[10px] text-gray-400 block mt-1">* รองรับประเภทไฟล์ JPG, PNG, WEBP (ไม่บังคับ)</span>
                    </div>

                    <!-- Notice Box -->
                    <div
                        class="bg-brand-orange/10 border border-brand-orange/20 p-4 rounded-2xl text-xs text-brand-orange space-y-1">
                        <div class="font-bold flex items-center gap-1.5">
                            <i class="fa-solid fa-crown text-amber-400"></i> สิทธิ์กัปตันทีม (Captain Rights)
                        </div>
                        <p class="text-gray-300 text-[11px]">
                            เมื่อกดสร้างทีมสำเร็จ คุณจะได้รับสิทธิ์เป็นกัปตันทีมโดยอัตโนมัติ และสามารถใช้ระบบพิมพ์ค้นหาเพื่อส่งคำเชิญดึงผู้เล่นคนอื่นเข้ามาเป็นสมาชิกในทีมได้ทันทีในหน้าโปรไฟล์
                        </p>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <a href="profile.php"
                            class="px-5 py-3 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-bold text-gray-300 transition-all">
                            ยกเลิก
                        </a>
                        <button type="submit"
                            class="px-8 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white text-xs font-bold uppercase shadow-orange-glow transition-all flex items-center gap-2 cursor-pointer">
                            <i class="fa-solid fa-plus"></i>
                            <span>ยืนยันสร้างทีมกลาง</span>
                        </button>
                    </div>
                </form>
            </div>

        </main>

        <!-- FOOTER -->
        <footer class="border-t border-white/15 bg-slate-950/80 backdrop-blur-md mt-auto py-8 text-xs text-gray-400">
            <div class="max-w-7xl mx-auto px-4 text-center">
                <p class="text-gray-300 font-semibold">&copy; <?= date('Y') ?> KORAT ESPORT. All rights reserved.</p>
            </div>
        </footer>

    </div>

</body>

</html>