<?php
// auth/forgot-username.php
require_once '../config/db.php';
require_once '../includes/auth.php';

$error = '';
$foundUsername = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $email = trim($_POST['email']);
        $stmt = $pdo->prepare("SELECT username FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $username = $stmt->fetchColumn();

        if ($username) {
            $foundUsername = $username;
        } else {
            $error = 'ไม่พบบัญชีที่ใช้อีเมลนี้';
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลืมชื่อผู้ใช้ - Korat Esport</title>
    <!-- Google Fonts & FontAwesome -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
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
        /* ซ่อน Scrollbar ทุกเบราว์เซอร์ */
        ::-webkit-scrollbar {
            display: none;
        }
        html, body {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        /* Ken Burns Zoom Effect สำหรับพื้นหลัง Arena */
        @keyframes kenBurnsLogin {
            0% { transform: scale(1); }
            50% { transform: scale(1.07); }
            100% { transform: scale(1); }
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

        @keyframes scaleInBox {
            from { opacity: 0; transform: scale(0.96) translateY(15px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
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

        @keyframes shimmerAccent {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .shimmer-line {
            background: linear-gradient(90deg, transparent, #FF5500, #ffaa33, #FF5500, transparent);
            background-size: 200% 100%;
            animation: shimmerAccent 4s infinite linear;
        }

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

        @keyframes slideDownAlert {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-slide-down {
            animation: slideDownAlert 0.4s ease forwards;
        }

        @keyframes revealText {
            0% { clip-path: inset(0 100% 0 0); opacity: 0; }
            100% { clip-path: inset(0 0 0 0); opacity: 1; }
        }
        .reveal-line-1 {
            animation: revealText 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards;
            opacity: 0;
        }
        .reveal-line-2 {
            animation: revealText 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.4s forwards;
            opacity: 0;
        }

        @keyframes singleGlitch {
            0%, 100% { transform: translate(0); text-shadow: none; }
            20% { transform: translate(-3px, 2px); text-shadow: 2px 0 #00F0FF, -2px 0 #FF5500; }
            40% { transform: translate(3px, -2px); text-shadow: -2px 0 #00F0FF, 2px 0 #FF5500; }
            60% { transform: translate(-1px, 1px); text-shadow: 1px 0 #00F0FF, -1px 0 #FF5500; }
            80% { transform: translate(1px, -1px); text-shadow: none; }
        }
        .glitch-logo-load {
            animation: singleGlitch 0.6s ease 1 0.3s;
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .form-title-animate {
            animation: slideInLeft 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards;
            opacity: 0;
        }

        @keyframes boltPulse {
            0%, 100% { transform: scale(1) rotate(0deg); }
            50% { transform: scale(1.3) rotate(15deg); }
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
<body class="font-sans min-h-screen flex items-center justify-center p-4 sm:p-6 lg:p-8 antialiased bg-esports-arena">

    <!-- Background Layers & Canvas Effect -->
    <div class="fixed inset-0 grid-dots opacity-40 z-0 pointer-events-none"></div>
    <canvas id="particles-canvas"></canvas>

    <!-- Main Split Wrapper -->
    <div class="relative z-10 w-full max-w-5xl grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
        
        <!-- ฝั่งซ้าย: โซนกราฟฟิกสโมสร & แบรนดิ้ง (กินพื้นที่ 5 คอลัมน์) -->
        <div class="hidden lg:flex lg:col-span-5 flex-col items-center justify-center text-center p-8 space-y-6">
            <div class="relative group">
                <div class="absolute -inset-2 bg-gradient-to-r from-brand-orange to-amber-500 rounded-3xl blur-xl opacity-50 group-hover:opacity-80 transition duration-1000"></div>
                
                <div class="relative glass-box p-8 rounded-3xl border border-white/20 shadow-2xl flex flex-col items-center space-y-4">
                    <img src="../assets/img/logo.png" alt="Korat Esport" class="h-28 w-auto filter drop-shadow-[0_0_20px_rgba(255,85,0,0.8)]">
                    <div>
                        <h1 class="font-display font-black text-3xl tracking-widest text-white">KORAT <span class="text-brand-orange glitch-logo-load inline-block">ESPORT</span></h1>
                        <span class="block text-xs font-bold text-slate-400 uppercase tracking-widest mt-1">Official Arena & Hub</span>
                    </div>
                    
                    <div class="space-y-1">
                        <p class="text-xs text-slate-200 leading-relaxed max-w-xs reveal-line-1">
                            ระบบค้นหาชื่อผู้ใช้ด้วยอีเมลที่คุณใช้สมัครสมาชิก
                        </p>
                        <p class="text-xs text-slate-200 leading-relaxed max-w-xs reveal-line-2">
                            เพื่อให้คุณกลับเข้าสู่ระบบได้สะดวกรวดเร็ว
                        </p>
                    </div>
                </div>
            </div>

            <div class="text-xs text-slate-300 drop-shadow">
                &copy; <?= date('Y') ?> KORAT ESPORT. All rights reserved.
            </div>
        </div>

        <!-- ฝั่งขวา: ฟอร์มลืมชื่อผู้ใช้ (กินพื้นที่ 7 คอลัมน์) -->
        <div class="lg:col-span-7 glass-panel-light p-6 sm:p-8 lg:p-10 rounded-3xl relative overflow-hidden text-slate-800 shadow-2xl">
            <div class="absolute top-0 left-0 right-0 h-1.5 shimmer-line"></div>

            <div class="lg:hidden text-center mb-6">
                <img src="../assets/img/logo.png" alt="Korat Esport" class="h-14 w-auto mx-auto mb-2 filter drop-shadow">
                <h1 class="font-display font-black text-xl text-slate-900">KORAT <span class="text-brand-orange">ESPORT</span></h1>
            </div>

            <div class="mb-6 form-title-animate">
                <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                    <span>ลืมชื่อผู้ใช้</span>
                    <i class="fa-solid fa-user-shield text-brand-orange text-lg bolt-pop inline-block"></i>
                </h2>
                <p class="text-xs sm:text-sm text-slate-500 mt-1 font-medium">กรอกอีเมลที่ใช้สมัครไว้ ระบบจะแสดงชื่อผู้ใช้ของคุณให้ทันที</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-5 p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold flex items-center gap-3 shadow-sm alert-slide-down">
                    <i class="fa-solid fa-triangle-exclamation text-base shrink-0 text-rose-500"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($foundUsername): ?>
                <div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm font-semibold space-y-3 alert-slide-down">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-emerald-500 text-lg"></i>
                        <span>ชื่อผู้ใช้ของคุณคือ: <strong class="text-slate-900 underline"><?php echo htmlspecialchars($foundUsername); ?></strong></span>
                    </div>
                </div>
                <a href="login.php"
                    class="shine-btn w-full py-4 px-6 rounded-xl font-bold text-white uppercase tracking-wider bg-brand-orange hover:bg-brand-glow shadow-orange-glow transition-all duration-300 flex items-center justify-center gap-2 text-xs group">
                    <span class="transition-transform duration-300 group-hover:-translate-x-1">ไปหน้าเข้าสู่ระบบ</span>
                    <i class="fa-solid fa-arrow-right text-xs transition-transform duration-300 group-hover:translate-x-2"></i>
                </a>
            <?php else: ?>
                <p class="text-xs text-slate-600 mb-4 font-medium">กรอกอีเมลที่ใช้สมัครสมาชิกไว้เพื่อค้นหาบัญชี</p>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">อีเมล (Email)</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i class="fa-solid fa-envelope"></i></span>
                            <input type="email" name="email" required
                                class="w-full bg-transparent px-3.5 py-3.5 text-slate-900 placeholder-slate-400 focus:outline-none text-sm font-medium"
                                placeholder="name@example.com">
                        </div>
                    </div>
                    <button type="submit"
                        class="shine-btn group w-full py-4 px-6 rounded-xl font-bold text-white uppercase tracking-wider bg-brand-orange hover:bg-brand-glow shadow-orange-glow transition-all duration-300 flex items-center justify-center gap-2 mt-4 cursor-pointer text-xs">
                        <span class="transition-transform duration-300 group-hover:-translate-x-1">ค้นหา</span>
                        <i class="fa-solid fa-magnifying-glass text-xs transition-transform duration-300 group-hover:translate-x-2"></i>
                    </button>
                </form>
            <?php endif; ?>

            <!-- Footer Links -->
            <div class="mt-8 pt-5 border-t border-slate-200 text-center text-xs font-medium text-slate-600 space-x-2">
                <a href="login.php" class="hover:text-brand-orange font-bold transition-colors">กลับไปหน้าเข้าสู่ระบบ</a>
                <span>&bull;</span>
                <a href="forgot-password.php" class="hover:text-brand-orange font-bold transition-colors">ลืมรหัสผ่าน?</a>
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