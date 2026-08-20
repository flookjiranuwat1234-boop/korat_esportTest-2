<?php
// auth/register.php
require_once '../config/db.php';
require_once '../includes/auth.php';

$error = '';

// ดึงข้อมูลสถิติสดสำหรับ Infographic ฝั่งซ้าย (ให้ตรงกับ Dashboard)
// นับเฉพาะทีมที่สมัครผ่านเว็บจริง (captain มี user_id ในระบบ)
$totalTeamsRegister = $pdo->query("
    SELECT COUNT(*) FROM teams t
    JOIN players p ON p.player_id = t.captain_player_id
    WHERE p.user_id IS NOT NULL
")->fetchColumn();

$totalTournamentsRegister = $pdo->query("SELECT COUNT(*) FROM tournaments")->fetchColumn();
$totalGamesRegister = $pdo->query("SELECT COUNT(*) FROM games WHERE is_active = 1")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'];
        $securityQuestion = $_POST['security_question'] ?? '';
        $securityAnswer = trim($_POST['security_answer'] ?? '');

        if (strlen($username) < 3) {
            $error = 'ชื่อผู้ใช้ต้องมีอย่างน้อย 3 ตัวอักษร';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'อีเมลไม่ถูกต้อง';
        } elseif (strlen($password) < 6) {
            $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        } elseif ($password != $confirmPassword) {
            $error = 'รหัสผ่านไม่ตรงกัน';
        } elseif (!in_array($securityQuestion, securityQuestionOptions())) {
            $error = 'กรุณาเลือกคำถามกันลืมรหัสผ่าน';
        } elseif ($securityAnswer == '') {
            $error = 'กรุณากรอกคำตอบของคำถามกันลืมรหัสผ่าน';
        } else {
            try {
                registerUser($pdo, $username, $email, $password, $securityQuestion, $securityAnswer);
                header('Location: login.php?registered=1');
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$questions = securityQuestionOptions();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สมัครสมาชิก - Korat Esport</title>
    <!-- Google Fonts & FontAwesome Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap"
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
        ::-webkit-scrollbar { display: none; }
        html, body { -ms-overflow-style: none; scrollbar-width: none; }

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

        .glass-panel-left {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            transition: all 0.3s ease;
        }

        .glass-panel-left:hover {
            background: rgba(255, 255, 255, 0.18);
            transform: translateY(-3px);
            border-color: rgba(255, 85, 0, 0.6);
        }

        .glass-panel-light {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
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

        .shimmer-line {
            background: linear-gradient(90deg, transparent, #FF5500, #ffaa33, #FF5500, transparent);
            background-size: 200% 100%;
            animation: shimmerAccent 4s infinite linear;
        }

        @keyframes shimmerAccent {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .shine-btn { position: relative; overflow: hidden; }
        .shine-btn::after {
            content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%;
            background: linear-gradient(60deg, transparent 30%, rgba(255, 255, 255, 0.4) 50%, transparent 70%);
            transform: rotate(30deg) translateX(-100%); transition: transform 0.7s ease;
        }
        .shine-btn:hover::after { transform: rotate(30deg) translateX(100%); }

        @keyframes slideDownAlert {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-slide-down { animation: slideDownAlert 0.4s ease forwards; }

        @keyframes revealText {
            0% { clip-path: inset(0 100% 0 0); opacity: 0; }
            100% { clip-path: inset(0 0 0 0); opacity: 1; }
        }
        .reveal-line-1 { animation: revealText 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards; opacity: 0; }
        .reveal-line-2 { animation: revealText 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.4s forwards; opacity: 0; }

        @keyframes singleGlitch {
            0%, 100% { transform: translate(0); text-shadow: none; }
            20% { transform: translate(-3px, 2px); text-shadow: 2px 0 #00F0FF, -2px 0 #FF5500; }
            40% { transform: translate(3px, -2px); text-shadow: -2px 0 #00F0FF, 2px 0 #FF5500; }
            60% { transform: translate(-1px, 1px); text-shadow: 1px 0 #00F0FF, -1px 0 #FF5500; }
            80% { transform: translate(1px, -1px); text-shadow: none; }
        }
        .glitch-logo-load { animation: singleGlitch 0.6s ease 1 0.3s; }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .form-title-animate { animation: slideInLeft 0.6s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards; opacity: 0; }

        @keyframes boltPulse {
            0%, 100% { transform: scale(1) rotate(0deg); }
            50% { transform: scale(1.3) rotate(15deg); }
        }
        .bolt-pop { animation: boltPulse 0.6s ease 1 0.7s; }

        @keyframes fieldFadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .field-stagger-1 { animation: fieldFadeUp 0.4s ease 0.3s forwards; opacity: 0; }
        .field-stagger-2 { animation: fieldFadeUp 0.4s ease 0.4s forwards; opacity: 0; }
        .field-stagger-3 { animation: fieldFadeUp 0.4s ease 0.5s forwards; opacity: 0; }
        .field-stagger-4 { animation: fieldFadeUp 0.4s ease 0.6s forwards; opacity: 0; }
        .field-stagger-5 { animation: fieldFadeUp 0.4s ease 0.7s forwards; opacity: 0; }
        .field-stagger-6 { animation: fieldFadeUp 0.4s ease 0.8s forwards; opacity: 0; }

        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.2) 1px, transparent 0);
            background-size: 24px 24px;
        }

        @keyframes scanline {
            0% { transform: translateY(-100%); }
            100% { transform: translateY(100%); }
        }
        .animate-scanline { animation: scanline 8s linear infinite; }
    </style>
</head>

<body class="bg-slate-900 text-gray-100 font-sans h-full min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0"></div>
    <div class="fixed inset-0 grid-bg opacity-40 z-0 pointer-events-none"></div>

    <div class="relative z-10 min-h-screen flex flex-col lg:flex-row">

        <!-- LEFT SIDE -->
        <div class="hidden lg:flex flex-1 flex-col justify-between p-12 relative overflow-hidden">
            <div class="absolute top-0 left-1/4 w-px h-full bg-gradient-to-b from-transparent via-brand-orange/40 to-transparent animate-scanline"></div>

            <a href="../pages/index.php" class="flex items-center gap-4 group w-fit">
                <img src="../assets/img/logo.png" alt="Korat Esport" class="h-14 w-auto filter drop-shadow-[0_2px_8px_rgba(0,0,0,0.5)] group-hover:scale-105 transition-transform" onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                <div>
                    <h1 class="font-display font-black text-2xl tracking-wider text-white drop-shadow">KORAT <span class="text-brand-orange glitch-logo-load inline-block">ESPORT</span></h1>
                    <p class="text-xs tracking-widest text-gray-200 uppercase font-semibold drop-shadow-sm">Official Arena & Hub</p>
                </div>
            </a>

            <div class="my-auto max-w-xl space-y-6">
                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest backdrop-blur-md shadow-sm">
                    <span class="w-2.5 h-2.5 rounded-full bg-brand-orange animate-ping"></span>
                    Join The Ultimate Arena
                </div>

                <div class="space-y-1">
                    <h2 class="text-4xl xl:text-5xl font-black text-white leading-tight uppercase font-display drop-shadow-md reveal-line-1">สร้างโปรไฟล์นักกีฬา</h2>
                    <h2 class="text-4xl xl:text-5xl font-black leading-tight uppercase font-display drop-shadow-md reveal-line-2">
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-orange via-amber-300 to-white">ก้าวสู่สังเวียนมืออาชีพ</span>
                    </h2>
                </div>

                <p id="typewriter-text" class="text-gray-100 text-base leading-relaxed drop-shadow min-h-[3rem]"></p>

                <!-- Infographic Cards -->
                <div class="grid grid-cols-3 gap-4 pt-4">
                    <div class="glass-panel-left p-4 rounded-2xl border-l-4 border-l-brand-orange shadow-lg">
                        <div class="text-xs text-gray-200 font-bold uppercase tracking-wider">TEAMS</div>
                        <div class="text-2xl font-black font-display text-white mt-1 flex items-center gap-0.5">
                            <span data-countup="<?php echo $totalTeamsRegister; ?>">0</span><span class="suffix-span opacity-0 transition-opacity duration-300"></span>
                        </div>
                        <div class="text-[11px] text-emerald-400 font-semibold mt-1 flex items-center gap-1">
                            <i class="fa-solid fa-arrow-trend-up"></i> Registered
                        </div>
                    </div>

                    <div class="glass-panel-left p-4 rounded-2xl border-l-4 border-l-amber-400 shadow-lg">
                        <div class="text-xs text-gray-200 font-bold uppercase tracking-wider">TOURNAMENTS</div>
                        <div class="text-2xl font-black font-display text-white mt-1">
                            <span data-countup="<?php echo $totalTournamentsRegister; ?>">0</span>
                        </div>
                        <div class="text-[11px] text-brand-orange font-semibold mt-1">Live & Upcoming</div>
                    </div>

                    <div class="glass-panel-left p-4 rounded-2xl border-l-4 border-l-purple-500 shadow-lg">
                        <div class="text-xs text-gray-200 font-bold uppercase tracking-wider">GAMES TITLE</div>
                        <div class="text-2xl font-black font-display text-purple-300 mt-1 flex items-center gap-1">
                            <span data-countup="<?php echo $totalGamesRegister; ?>">0</span><span class="suffix-span opacity-0 transition-opacity duration-300 text-xs font-normal">TITLES</span>
                        </div>
                        <div class="text-[11px] text-purple-400/80 font-semibold mt-1">Mobile & PC Esports</div>
                    </div>
                </div>
            </div>

            <div class="text-xs text-gray-200 flex items-center justify-between drop-shadow">
                <span>&copy; <?= date('Y') ?> Korat Esport. All rights reserved.</span>
                <span class="font-display tracking-widest text-[10px] text-gray-300 uppercase">Powered by Korat Esports Auth Engine</span>
            </div>
        </div>

        <!-- RIGHT SIDE: Register Form -->
        <div class="w-full lg:w-[500px] xl:w-[540px] flex items-center justify-center p-6 sm:p-10 z-10 my-auto min-h-screen py-12">
            <div class="w-full glass-panel-light p-8 sm:p-10 rounded-3xl relative overflow-hidden text-slate-800">
                <div class="absolute top-0 left-0 right-0 h-1.5 shimmer-line"></div>

                <div class="lg:hidden text-center mb-6">
                    <img src="../assets/img/logo.png" alt="Korat Esport" class="h-14 w-auto mx-auto mb-2 filter drop-shadow" onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                    <h1 class="font-display font-black text-xl text-slate-900">KORAT <span class="text-brand-orange">ESPORT</span></h1>
                </div>

                <div class="mb-6 form-title-animate">
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2">
                        <span>สมัครสมาชิก</span>
                        <i class="fa-solid fa-user-plus text-brand-orange text-lg bolt-pop inline-block"></i>
                    </h2>
                    <p class="text-xs sm:text-sm text-slate-500 mt-1 font-medium">กรอกข้อมูลรายละเอียดด้านล่างเพื่อเริ่มต้นใช้งาน</p>
                </div>

                <?php if ($error): ?>
                    <div class="mb-5 p-3.5 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs sm:text-sm font-semibold flex items-center gap-3 shadow-sm alert-slide-down">
                        <i class="fa-solid fa-triangle-exclamation text-base shrink-0 text-rose-500"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                    <div class="field-stagger-1">
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">ชื่อผู้ใช้ (Username)</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i class="fa-solid fa-user"></i></span>
                            <input type="text" name="username" required class="w-full bg-transparent px-3.5 py-3 text-slate-900 placeholder-slate-400 focus:outline-none text-xs font-medium" placeholder="อย่างน้อย 3 ตัวอักษร">
                        </div>
                    </div>

                    <div class="field-stagger-2">
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">อีเมล (Email)</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i class="fa-solid fa-envelope"></i></span>
                            <input type="email" name="email" required class="w-full bg-transparent px-3.5 py-3 text-slate-900 placeholder-slate-400 focus:outline-none text-xs font-medium" placeholder="name@example.com">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 field-stagger-3">
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">รหัสผ่าน</label>
                            <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                                <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="password" required class="w-full bg-transparent px-3.5 py-3 text-slate-900 placeholder-slate-400 focus:outline-none text-xs font-medium" placeholder="อย่างน้อย 6 ตัว">
                            </div>
                        </div>
                        <div>
                            <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">ยืนยันรหัสผ่าน</label>
                            <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                                <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" name="confirm_password" required class="w-full bg-transparent px-3.5 py-3 text-slate-900 placeholder-slate-400 focus:outline-none text-xs font-medium" placeholder="ยืนยันรหัสผ่าน">
                            </div>
                        </div>
                    </div>

                    <div class="field-stagger-4">
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">คำถามกันลืมรหัสผ่าน</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i class="fa-solid fa-circle-question"></i></span>
                            <select name="security_question" required class="w-full bg-transparent px-3 py-3 text-slate-900 focus:outline-none text-xs font-medium cursor-pointer">
                                <option value="">-- เลือกคำถาม --</option>
                                <?php foreach ($questions as $q): ?>
                                    <option value="<?php echo htmlspecialchars($q); ?>"><?php echo htmlspecialchars($q); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field-stagger-5">
                        <label class="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">คำตอบกันลืม</label>
                        <div class="relative glass-input-light rounded-xl overflow-hidden flex items-center">
                            <span class="pl-3.5 text-slate-400 text-xs input-icon transition-colors duration-200"><i class="fa-solid fa-key"></i></span>
                            <input type="text" name="security_answer" required class="w-full bg-transparent px-3.5 py-3 text-slate-900 placeholder-slate-400 focus:outline-none text-xs font-medium" placeholder="คำตอบของคุณ">
                        </div>
                        <p class="text-[10px] text-slate-500 mt-1 italic">* จำคำตอบนี้ไว้ให้ดี ใช้สำหรับรีเซ็ตรหัสผ่านหากลืมในอนาคต</p>
                    </div>

                    <div class="field-stagger-6 pt-2">
                        <button type="submit" class="shine-btn group w-full py-3.5 px-6 rounded-xl font-bold text-white uppercase tracking-wider bg-brand-orange hover:bg-brand-glow shadow-orange-glow hover:shadow-orange-glow-lg transition-all duration-300 transform active:scale-[0.98] flex items-center justify-center gap-2 cursor-pointer text-xs">
                            <span class="transition-transform duration-300 group-hover:-translate-x-1">สมัครสมาชิก</span>
                            <i class="fa-solid fa-arrow-right text-xs transition-transform duration-300 group-hover:translate-x-2"></i>
                        </button>
                    </div>
                </form>

                <div class="mt-6 pt-5 border-t border-slate-200 text-center text-xs font-medium text-slate-600">
                    มีบัญชีอยู่แล้ว? <a href="login.php" class="text-brand-orange font-bold hover:underline ml-1">เข้าสู่ระบบที่นี่</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const subtitleEl = document.getElementById('typewriter-text');
            const subtitleText = "สมัครสมาชิกเพื่อสร้างทีมสโมสร สมัครแข่งขันอีสปอร์ต และรับรหัส QR Code สำหรับรายงานตัวเข้าแข่งขันได้ทันที";
            let charIndex = 0;

            function typeWriter() {
                if (charIndex < subtitleText.length) {
                    subtitleEl.innerHTML += subtitleText.charAt(charIndex);
                    charIndex++;
                    setTimeout(typeWriter, 25);
                }
            }
            setTimeout(typeWriter, 600);

            const counters = document.querySelectorAll('[data-countup]');
            counters.forEach(c => {
                const target = +c.getAttribute('data-countup');
                let count = 0;
                const increment = Math.max(1, Math.ceil(target / 20));
                const updateCount = () => {
                    count += increment;
                    if (count < target) {
                        c.innerText = count;
                        setTimeout(updateCount, 40);
                    } else {
                        c.innerText = target;
                        const suffix = c.closest('div').querySelector('.suffix-span');
                        if (suffix) suffix.classList.remove('opacity-0');
                    }
                };
                updateCount();
            });
        });
    </script>
</body>
</html>