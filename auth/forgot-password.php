<?php
// auth/forgot-password.php
// ขั้นตอน: 1) กรอกชื่อผู้ใช้ -> 2) ตอบคำถามกันลืม -> 3) ตั้งรหัสผ่านใหม่
// ใช้ $_SESSION['reset_user_id'] เก็บสถานะระหว่างขั้น เพื่อไม่ให้ขั้น 3 เชื่อ user_id จาก form ตรงๆ
require_once '../config/db.php';
require_once '../includes/auth.php';

$error = '';
$step = 1;
$question = '';

// ขั้น 1: กรอกชื่อผู้ใช้ ตรวจว่ามีจริงไหม แล้วโชว์คำถามกันลืมของบัญชีนั้น
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['step'] ?? '') == '1') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $username = trim($_POST['username']);
        $stmt = $pdo->prepare("SELECT user_id, security_question FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        // ข้อความ error ตั้งใจเขียนให้เหมือนกันไม่ว่าจะหาเจอหรือไม่เจอ กันคนสุ่มเช็คว่า username ไหนมีอยู่จริง
        if (!$user || empty($user['security_question'])) {
            $error = 'ไม่พบบัญชีนี้ หรือบัญชีนี้ยังไม่ได้ตั้งคำถามกันลืมรหัสผ่านไว้ กรุณาติดต่อผู้ดูแลระบบ';
        } else {
            $_SESSION['reset_user_id'] = $user['user_id'];
            $_SESSION['reset_step'] = 2;
        }
    }
}

// ขั้น 2: ตอบคำถามกันลืม
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['step'] ?? '') == '2') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } elseif (empty($_SESSION['reset_user_id']) || ($_SESSION['reset_step'] ?? 0) < 2) {
        $error = 'ขั้นตอนไม่ถูกต้อง กรุณาเริ่มใหม่';
        unset($_SESSION['reset_user_id'], $_SESSION['reset_step']);
    } else {
        $stmt = $pdo->prepare("SELECT security_answer_hash FROM users WHERE user_id = :id");
        $stmt->execute(['id' => $_SESSION['reset_user_id']]);
        $answerHash = $stmt->fetchColumn();

        if (verifySecurityAnswer($answerHash, $_POST['answer'] ?? '')) {
            $_SESSION['reset_step'] = 3;
        } else {
            $error = 'คำตอบไม่ถูกต้อง กรุณาลองใหม่';
        }
    }
}

// ขั้น 3: ตั้งรหัสผ่านใหม่
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['step'] ?? '') == '3') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } elseif (empty($_SESSION['reset_user_id']) || ($_SESSION['reset_step'] ?? 0) < 3) {
        $error = 'ขั้นตอนไม่ถูกต้อง กรุณาเริ่มใหม่';
        unset($_SESSION['reset_user_id'], $_SESSION['reset_step']);
    } else {
        $newPassword = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        if (strlen($newPassword) < 6) {
            $error = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif ($newPassword != $confirm) {
            $error = 'รหัสผ่านไม่ตรงกัน';
        } else {
            setNewPassword($pdo, $_SESSION['reset_user_id'], $newPassword);
            unset($_SESSION['reset_user_id'], $_SESSION['reset_step']);
            header('Location: login.php?reset_success=1');
            exit;
        }
    }
}

// กำหนดว่าตอนนี้อยู่ขั้นไหน (เอาไว้แสดงฟอร์มให้ตรง)
if (($_SESSION['reset_step'] ?? 0) == 3 && !empty($_SESSION['reset_user_id'])) {
    $step = 3;
} elseif (($_SESSION['reset_step'] ?? 0) >= 2 && !empty($_SESSION['reset_user_id'])) {
    $step = 2;
    $stmt = $pdo->prepare("SELECT security_question FROM users WHERE user_id = :id");
    $stmt->execute(['id' => $_SESSION['reset_user_id']]);
    $question = $stmt->fetchColumn();
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลืมรหัสผ่าน - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&family=Orbitron:wght@700;900&display=swap"
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
                        'orange-glow': '0 4px 20px rgba(255, 85, 0, 0.4)',
                        'orange-glow-lg': '0 8px 30px rgba(255, 85, 0, 0.6)'
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

        /* Ken Burns Zoom Effect สำหรับพื้นหลัง Arena */
        @keyframes kenBurnsLogin {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.07);
            }

            100% {
                transform: scale(1);
            }
        }

        .bg-esports-arena {
            background: linear-gradient(to right, rgba(15, 17, 23, 0.45), rgba(15, 17, 23, 0.25)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            animation: kenBurnsLogin 25s ease-in-out infinite;
        }

        .glass-box {
            background: rgba(18, 19, 24, 0.75);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        /* กล่องฟอร์มด้านขวา: Light Frosted Glass พร้อม Scale-in Animation */
        @keyframes scaleInBox {
            from {
                opacity: 0;
                transform: scale(0.96) translateY(15px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .glass-panel-light {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: scaleInBox 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .glass-input-light {
            background: #FFFFFF;
            border: 1px solid #CBD5E1;
            transition: all 0.25s ease;
        }

        .glass-input-light:focus-within {
            background: #FFFFFF;
            border-color: #FF5500;
            box-shadow: 0 0 12px rgba(255, 85, 0, 0.25);
        }

        .glass-input-light:focus-within .input-icon {
            color: #FF5500 !important;
        }

        /* Shimmer Effect สำหรับเส้น Accent ด้านบนกล่อง */
        @keyframes shimmerAccent {
            0% {
                background-position: -200% 0;
            }

            100% {
                background-position: 200% 0;
            }
        }

        .shimmer-line {
            background: linear-gradient(90deg, transparent, #FF5500, #ffaa33, #FF5500, transparent);
            background-size: 200% 100%;
            animation: shimmerAccent 4s infinite linear;
        }

        /* Shine Sweep Effect สำหรับปุ่มหลัก */
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

        /* Slide-down Animation สำหรับ Alerts */
        @keyframes slideDownAlert {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-slide-down {
            animation: slideDownAlert 0.4s ease forwards;
        }

        /* Text Reveal Mask สำหรับหัวข้อฝั่งซ้าย */
        @keyframes revealText {
            0% {
                clip-path: inset(0 100% 0 0);
                opacity: 0;
            }

            100% {
                clip-path: inset(0 0 0 0);
                opacity: 1;
            }
        }

        .reveal-line-1 {
            animation: revealText 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards;
            opacity: 0;
        }

        .reveal-line-2 {
            animation: revealText 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.4s forwards;
            opacity: 0;
        }

        /* Glitch Effect ครั้งเดียวตอนโหลดโลโก้ */
        @keyframes singleGlitch {

            0%,
            100% {
                transform: translate(0);
                text-shadow: none;
            }

            20% {
                transform: translate(-3px, 2px);
                text-shadow: 2px 0 #00F0FF, -2px 0 #FF5500;
            }

            40% {
                transform: translate(3px, -2px);
                text-shadow: -2px 0 #00F0FF, 2px 0 #FF5500;
            }

            60% {
                transform: translate(-1px, 1px);
                text-shadow: 1px 0 #00F0FF, -1px 0 #FF5500;
            }

            80% {
                transform: translate(1px, -1px);
                text-shadow: none;
            }
        }

        .glitch-logo-load {
            animation: singleGlitch 0.6s ease 1 0.3s;
        }

        /* Slide-in & Bolt Animation สำหรับหัวข้อฝั่งขวา */
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .form-title-animate {
            animation: slideInLeft 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards;
            opacity: 0;
        }

        @keyframes boltPulse {

            0%,
            100% {
                transform: scale(1) rotate(0deg);
            }

            50% {
                transform: scale(1.3) rotate(15deg);
            }
        }

        .bolt-pop {
            animation: boltPulse 0.6s ease 1 0.7s;
        }

        .grid-dots {
            background-image: radial-gradient(rgba(255, 255, 255, 0.2) 1px, transparent 0);
            background-size: 24px 24px;
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
    </style>
</head>

<body
    class="font-sans min-h-screen flex items-center justify-center p-4 sm:p-6 lg:p-8 antialiased overflow-x-hidden bg-esports-arena">

    <!-- Background Layers & Canvas Effect -->
    <div class="fixed inset-0 grid-dots opacity-40 z-0 pointer-events-none"></div>
    <canvas id="particles-canvas"></canvas>

    <!-- Main Split Wrapper -->
    <div class="relative z-10 w-full max-w-5xl grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">

        <!-- ฝั่งซ้าย: โซนกราฟฟิกสโมสร & แบรนดิ้ง (กินพื้นที่ 5 คอลัมน์) -->
        <div class="hidden lg:flex lg:col-span-5 flex-col items-center justify-center text-center p-8 space-y-6">
            <div class="relative group">
                <div
                    class="absolute -inset-2 bg-gradient-to-r from-brand-orange to-amber-500 rounded-3xl blur-xl opacity-50 group-hover:opacity-80 transition duration-1000">
                </div>

                <div
                    class="relative glass-box p-8 rounded-3xl border border-white/20 shadow-2xl flex flex-col items-center space-y-4">
                    <img src="../assets/img/logo.png" alt="Korat Esport"
                        class="h-28 w-auto filter drop-shadow-[0_0_20px_rgba(255,85,0,0.8)]">
                    <div>
                        <h1 class="font-display font-black text-3xl tracking-widest text-white">KORAT <span
                                class="text-brand-orange glitch-logo-load inline-block">ESPORT</span></h1>
                        <span class="block text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">Official
                            Arena & Hub</span>
                    </div>

                    <div class="space-y-1">
                        <p class="text-xs text-slate-200 leading-relaxed max-w-xs reveal-line-1">
                            ระบบกู้คืนรหัสผ่านความปลอดภัยสูง
                        </p>
                        <p class="text-xs text-slate-200 leading-relaxed max-w-xs reveal-line-2">
                            ตอบคำถามกันลืมเพื่อตั้งรหัสผ่านใหม่และเข้าสู่สังเวียน
                        </p>
                    </div>
                </div>
            </div>

            <div class="text-xs text-slate-300 drop-shadow">
                &copy; <?= date('Y') ?> KORAT ESPORT. All rights reserved.
            </div>
        </div>

        <!-- ฝั่งขวา: ฟอร์มลืมรหัสผ่าน (กินพื้นที่ 7 คอลัมน์) -->
        <div
            class="lg:col-span-7 glass-panel-light p-6 sm:p-8 lg:p-10 rounded-3xl relative overflow-hidden text-slate-800 shadow-2xl">
            <div class="absolute top-0 left-0 right-0 h-1.5 shimmer-line"></div>

            <div class="lg:hidden text-center mb-6">
                <img src="../assets/img/logo.png" alt="Korat Esport"
                    class="h-14 w-auto mx-auto mb-2 filter drop-shadow">
                <h1 class="font-display font-black text-xl text-slate-900">KORAT <span
                        class="text-brand-orange">ESPORT</span></h1>
            </div>

            <div class="mb-6 form-title-animate">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>ลืมรหัสผ่าน</span>
                    <i class="fa-solid fa-key text-brand-orange text-lg bolt-pop inline-block"></i>
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 font-medium">กู้คืนบัญชีของคุณด้วยคำถามกันลืมรหัสผ่าน
                </p>
            </div>

            <?php if ($error): ?>
                <div
                    class="mb-5 p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold flex items-center gap-3 shadow-sm alert-slide-down">
                    <i class="fa-solid fa-triangle-exclamation text-base shrink-0 text-rose-500"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Step 1 -->
            <?php if ($step == 1): ?>
                <p class="text-xs text-slate-600 mb-4 font-medium">กรอกชื่อผู้ใช้ของคุณ
                    ระบบจะแสดงคำถามกันลืมที่ตั้งไว้ตอนสมัคร</p>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="step" value="1">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">ชื่อผู้ใช้
                            (Username)</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i
                                    class="fa-solid fa-user"></i></span>
                            <input type="text" name="username" required
                                class="w-full bg-transparent px-3.5 py-3.5 text-slate-900 placeholder-slate-400 focus:outline-none text-sm font-medium"
                                placeholder="ระบุชื่อผู้ใช้ของคุณ">
                        </div>
                    </div>
                    <button type="submit"
                        class="shine-btn group w-full py-4 px-6 rounded-xl font-bold text-white uppercase tracking-wider bg-brand-orange hover:bg-brand-glow shadow-orange-glow transition-all duration-300 flex items-center justify-center gap-2 mt-4 cursor-pointer text-xs">
                        <span class="transition-transform duration-300 group-hover:-translate-x-1">ถัดไป</span>
                        <i
                            class="fa-solid fa-arrow-right text-xs transition-transform duration-300 group-hover:translate-x-2"></i>
                    </button>
                </form>

                <!-- Step 2 -->
            <?php elseif ($step == 2): ?>
                <div
                    class="mb-4 p-4 rounded-xl bg-slate-100 border border-slate-200 text-slate-700 text-xs font-semibold alert-slide-down">
                    <span class="block text-slate-400 uppercase text-[10px] mb-1">คำถามกันลืมของคุณ:</span>
                    <span class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($question); ?></span>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="step" value="2">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">คำตอบ
                            (Security Answer)</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i
                                    class="fa-solid fa-key"></i></span>
                            <input type="text" name="answer" required
                                class="w-full bg-transparent px-3.5 py-3.5 text-slate-900 placeholder-slate-400 focus:outline-none text-sm font-medium"
                                placeholder="กรอกคำตอบของคุณ">
                        </div>
                    </div>
                    <button type="submit"
                        class="shine-btn group w-full py-4 px-6 rounded-xl font-bold text-white uppercase tracking-wider bg-brand-orange hover:bg-brand-glow shadow-orange-glow transition-all duration-300 flex items-center justify-center gap-2 mt-4 cursor-pointer text-xs">
                        <span class="transition-transform duration-300 group-hover:-translate-x-1">ยืนยันคำตอบ</span>
                        <i
                            class="fa-solid fa-check text-xs transition-transform duration-300 group-hover:translate-x-2"></i>
                    </button>
                </form>

                <!-- Step 3 -->
            <?php elseif ($step == 3): ?>
                <div
                    class="mb-4 p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold alert-slide-down">
                    <i class="fa-solid fa-circle-check text-emerald-500 mr-1"></i> ตอบถูกต้อง ตั้งรหัสผ่านใหม่ได้เลย
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="step" value="3">
                    <div>
                        <label
                            class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">รหัสผ่านใหม่
                            (New Password)</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i
                                    class="fa-solid fa-lock"></i></span>
                            <input type="password" name="new_password" required
                                class="w-full bg-transparent px-3.5 py-3.5 text-slate-900 placeholder-slate-400 focus:outline-none text-sm font-medium"
                                placeholder="อย่างน้อย 6 ตัวอักษร">
                        </div>
                    </div>
                    <div>
                        <label
                            class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">ยืนยันรหัสผ่านใหม่</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i
                                    class="fa-solid fa-lock"></i></span>
                            <input type="password" name="confirm_password" required
                                class="w-full bg-transparent px-3.5 py-3.5 text-slate-900 placeholder-slate-400 focus:outline-none text-sm font-medium"
                                placeholder="กรอกรหัสผ่านใหม่อีกครั้ง">
                        </div>
                    </div>
                    <button type="submit"
                        class="shine-btn group w-full py-4 px-6 rounded-xl font-bold text-white uppercase tracking-wider bg-brand-orange hover:bg-brand-glow shadow-orange-glow transition-all duration-300 flex items-center justify-center gap-2 mt-4 cursor-pointer text-xs">
                        <span class="transition-transform duration-300 group-hover:-translate-x-1">ตั้งรหัสผ่านใหม่</span>
                        <i
                            class="fa-solid fa-arrow-right text-xs transition-transform duration-300 group-hover:translate-x-2"></i>
                    </button>
                </form>
            <?php endif; ?>

            <!-- Footer Links -->
            <div class="mt-8 pt-5 border-t border-slate-200 text-center text-xs font-medium text-slate-600 space-x-2">
                <a href="login.php"
                    class="hover:text-brand-orange font-bold transition-colors">กลับไปหน้าเข้าสู่ระบบ</a>
                <span>&bull;</span>
                <a href="forgot-username.php"
                    class="hover:text-brand-orange font-bold transition-colors">ลืมชื่อผู้ใช้?</a>
            </div>

        </div>
    </div>

    <!-- Canvas เอฟเฟกต์ละอองไฟ -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
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
                    this.speedY = Math.random() * 0.6 + 0.15;
                    this.speedX = (Math.random() - 0.5) * 0.3;
                    this.opacity = Math.random() * 0.5 + 0.15;
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
        });
    </script>
</body>

</html>