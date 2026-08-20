<?php
// pages/team-profile.php
require_once '../config/db.php';
require_once '../includes/auth.php';

// ตรวจสอบสถานะการเข้าสู่ระบบ
$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$teamId = (int) ($_GET['id'] ?? 0);

// ใช้ LEFT JOIN games เพื่อป้องกันปัญหา error หากตาราง teams ไม่มีคอลัมน์ game_id ตรงๆ
$tStmt = $pdo->prepare("
    SELECT t.*, COALESCE(g.name, 'ทั่วไป / ไม่ระบุ') AS game_name, t.game_id AS game_id
    FROM teams t 
    LEFT JOIN games g ON g.game_id = t.game_id
    WHERE t.team_id = :id
");
$tStmt->execute(['id' => $teamId]);
$team = $tStmt->fetch();

if (!$team) {
    die('
    <div style="min-height:100vh; background-color:#0F1117; color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif; padding:2rem; text-align:center;">
        <h1 style="font-size:2rem; color:#FF5500; font-weight:bold;">ไม่พบทีมนี้</h1>
        <p style="color:#aaa; margin-top:0.5rem;">ข้อมูลสโมสรหรือทีมอีสปอร์ตอาจถูกลบหรือระบุรหัสไม่ถูกต้อง</p>
        <a href="index.php" style="margin-top:2rem; padding:0.8rem 1.5rem; background-color:#FF5500; color:#fff; text-decoration:none; border-radius:12px; font-weight:bold;">&larr; กลับไปหน้าแรก</a>
    </div>
    ');
}

// สมาชิกในทีม
$members = [];
try {
    $membersStmt = $pdo->prepare("
        SELECT p.player_id, COALESCE(p.display_name, 'Unknown Player') AS display_name, tm.in_game_role
        FROM team_members tm
        JOIN players p ON p.player_id = tm.player_id
        WHERE tm.team_id = :team_id AND tm.is_active = 1
    ");
    $membersStmt->execute(['team_id' => $teamId]);
    $members = $membersStmt->fetchAll();
} catch (Exception $e) {
    $members = [];
}

// อันดับคะแนนของทีมนี้ในเกมนี้
$ranking = null;
if (!empty($team['game_id'])) {
    try {
        $rankStmt = $pdo->prepare("SELECT * FROM team_rankings WHERE team_id = :team_id AND game_id = :game_id");
        $rankStmt->execute(['team_id' => $teamId, 'game_id' => $team['game_id']]);
        $ranking = $rankStmt->fetch();
    } catch (Exception $e) {
        $ranking = null;
    }
}

// ตรวจสอบว่าทีมนี้ติด Top 3 ของเกมนี้หรือไม่ (เพื่อให้ Badge พิเศษ)
$isTopTeam = false;
if ($ranking && isset($ranking['points']) && !empty($team['game_id'])) {
    try {
        $topCheck = $pdo->prepare("SELECT COUNT(*) FROM team_rankings WHERE game_id = :game_id AND points > :points");
        $topCheck->execute(['game_id' => $team['game_id'], 'points' => $ranking['points']]);
        $higherCount = $topCheck->fetchColumn();
        if ($higherCount < 3) {
            $isTopTeam = true;
        }
    } catch (Exception $e) {
        $isTopTeam = false;
    }
}

// ประวัติการแข่งขันของทีมนี้ (10 แมตช์ล่าสุดที่จบแล้ว)
$history = [];
try {
    $historyStmt = $pdo->prepare("
        SELECT m.*, t1.name AS team1_name, t2.name AS team2_name, tour.name AS tournament_name
        FROM matches m
        JOIN tournaments tour ON tour.tournament_id = m.tournament_id
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        WHERE (m.team1_id = :team_id OR m.team2_id = :team_id2)
          AND m.status IN ('completed', 'walkover')
        ORDER BY m.completed_at DESC
        LIMIT 10
    ");
    $historyStmt->execute(['team_id' => $teamId, 'team_id2' => $teamId]);
    $history = $historyStmt->fetchAll();
} catch (Exception $e) {
    $history = [];
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($team['name']); ?> - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link rel="stylesheet" href="https://unpkg.com/aos@next/dist/aos.css" />

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
                        'orange-glow': '0 0 25px rgba(255, 85, 0, 0.45)',
                        'gold-glow': '0 0 25px rgba(245, 158, 11, 0.6)'
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
            background-color: #0F1117;
            color: #f3f4f6;
        }
        .bg-esports-arena {
            background: linear-gradient(to bottom, rgba(15, 17, 23, 0.45), rgba(15, 17, 23, 0.85)),
                url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            background-attachment: scroll;
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
        .grid-bg {
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 0);
            background-size: 24px 24px;
        }
        @keyframes logoPulseGlow {
            0%, 100% {
                box-shadow: 0 0 15px rgba(255, 85, 0, 0.4);
                border-color: #FF5500;
            }
            50% {
                box-shadow: 0 0 35px rgba(255, 85, 0, 0.8);
                border-color: #ff8844;
            }
        }
        .logo-pulse-glow {
            animation: logoPulseGlow 3s infinite;
        }
        @keyframes shimmerAnim {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        .shimmer-badge {
            background: linear-gradient(90deg, rgba(245, 158, 11, 0.2) 0%, rgba(255, 255, 255, 0.4) 50%, rgba(245, 158, 11, 0.2) 100%);
            background-size: 200% 100%;
            animation: shimmerAnim 3s infinite linear;
            border: 1px solid rgba(245, 158, 11, 0.6);
        }
        .captain-glow {
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.5);
            border: 1px solid rgba(245, 158, 11, 0.8);
        }
        @keyframes rowFadeIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        .history-row {
            animation: rowFadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transition: all 0.3s ease;
        }
    </style>
</head>

<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">

    <div class="fixed inset-0 bg-esports-arena z-0 pointer-events-none"></div>
    <div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>

    <div class="relative z-10 flex flex-col min-h-screen">

        <header class="sticky top-0 z-50 glass-nav transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-20">
                    <a href="index.php" class="flex items-center gap-3 group">
                        <img src="../assets/img/logo.png" alt="Korat Esport"
                            class="h-11 w-auto filter drop-shadow group-hover:scale-105 transition-transform"
                            onError="this.src='https://placehold.co/100x100/121318/FF5500?text=KE';">
                        <div>
                            <span class="font-display font-black text-xl tracking-wider text-white group-hover:text-brand-orange transition-colors">KORAT <span class="text-brand-orange">ESPORT</span></span>
                            <span class="block text-[10px] tracking-widest text-gray-200 font-bold uppercase -mt-1">Official Arena & Hub</span>
                        </div>
                    </a>

                    <nav class="hidden md:flex items-center gap-1 lg:gap-2">
                        <a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">หน้าแรก</a>
                        <a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ทัวร์นาเมนต์</a>
                        <a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ตารางคะแนน</a>
                        <a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ข่าวสาร</a>
                        <a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">แกลเลอรี่</a>
                        <?php if ($isLoggedIn): ?>
                            <a href="lodging.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-200 hover:text-brand-orange hover:bg-white/10 transition-all">ที่พักแนะนำ</a>
                        <?php endif; ?>
                    </nav>

                    <div class="flex items-center gap-4 text-base font-bold">
                        <?php if ($isLoggedIn): ?>
                            <div class="flex items-center gap-3 bg-white/10 border border-white/20 p-1.5 pl-3.5 rounded-2xl backdrop-blur-md">
                                <div class="flex flex-col text-right">
                                    <span class="text-sm font-bold text-white leading-tight"><?= htmlspecialchars($currentUser['username'] ?? 'User') ?></span>
                                    <span class="text-[10px] font-semibold text-brand-orange uppercase tracking-wider"><?= htmlspecialchars($currentUser['role'] ?? 'Player') ?></span>
                                </div>
                                <a href="profile.php" title="จัดการโปรไฟล์" class="w-9 h-9 rounded-xl bg-brand-orange hover:bg-brand-glow text-white flex items-center justify-center transition-all shadow-md">
                                    <i class="fa-solid fa-user-gear text-sm"></i>
                                </a>
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

        <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full space-y-12">

            <div class="glass-panel p-6 sm:p-10 rounded-3xl border border-white/20 shadow-2xl flex flex-col md:flex-row items-center gap-8"
                data-aos="zoom-in" data-aos-duration="800">
                <?php
                $logoSrc = 'https://placehold.co/150x150/121318/FF5500?text=Team';
                if (!empty($team['logo_path'])) {
                    $path = trim($team['logo_path']);
                    if (strpos($path, 'http') === 0) {
                        $logoSrc = $path;
                    } else {
                        $cleanPath = ltrim($path, '/');
                        $logoSrc = (strpos($cleanPath, 'assets/') === 0) ? '../' . $cleanPath : '../assets/' . $cleanPath;
                    }
                }
                ?>
                <img src="<?= htmlspecialchars($logoSrc) ?>" alt="<?php echo htmlspecialchars($team['name']); ?>"
                    class="w-32 h-32 sm:w-40 sm:h-40 rounded-2xl object-cover border-2 border-brand-orange logo-pulse-glow shrink-0 bg-black/40 p-1"
                    onError="this.src='https://placehold.co/150x150/121318/FF5500?text=Team';">

                <div class="space-y-3 text-center md:text-left flex-1">
                    <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-orange/20 border border-brand-orange/40 text-brand-orange text-xs font-bold uppercase tracking-widest">
                        <i class="fa-solid fa-shield-halved"></i> Esports Team Profile
                    </div>

                    <div class="flex flex-col md:flex-row md:items-center gap-3">
                        <h1 class="text-3xl sm:text-4xl font-black font-display text-white uppercase tracking-wide">
                            <?php echo htmlspecialchars($team['name']); ?>
                        </h1>
                        <?php if ($isTopTeam): ?>
                            <span class="px-3 py-1 rounded-full text-xs font-black uppercase tracking-wider shimmer-badge text-amber-300 inline-flex items-center gap-1.5 w-fit mx-auto md:mx-0 shadow-gold-glow">
                                <i class="fa-solid fa-award"></i> Top Tier Club
                            </span>
                        <?php endif; ?>
                    </div>

                    <p class="text-sm text-gray-300 font-semibold flex items-center justify-center md:justify-start gap-2">
                        <i class="fa-solid fa-gamepad text-brand-orange"></i>
                        <span><?php echo htmlspecialchars($team['game_name']); ?></span>
                        <?php echo !empty($team['tag']) ? '<span class="text-brand-orange">[' . htmlspecialchars($team['tag']) . ']</span>' : ''; ?>
                    </p>

                    <?php if ($ranking): ?>
                        <div class="pt-2 flex flex-wrap items-center justify-center md:justify-start gap-3 text-xs font-bold">
                            <span class="px-3 py-1.5 rounded-xl bg-amber-500/20 border border-amber-400/40 text-amber-300">
                                <i class="fa-solid fa-crown mr-1"></i> คะแนนสะสม: <?php echo number_format($ranking['points'] ?? 0); ?> PTS
                            </span>
                            <span class="px-3 py-1.5 rounded-xl bg-white/10 border border-white/20 text-gray-200 font-mono">
                                ชนะ <strong class="text-emerald-400"><?php echo $ranking['wins'] ?? 0; ?></strong> — แพ้ <strong class="text-rose-400"><?php echo $ranking['losses'] ?? 0; ?></strong>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider flex items-center gap-2 border-b border-white/15 pb-3"
                    data-aos="fade-right">
                    <i class="fa-solid fa-users text-brand-orange"></i> สมาชิกทีม (Roster)
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php if (empty($members)): ?>
                        <div class="col-span-full glass-panel p-8 text-center text-gray-400 rounded-2xl" data-aos="fade-up">
                            ยังไม่มีสมาชิกในทีมนี้
                        </div>
                    <?php endif; ?>
                    <?php foreach ($members as $index => $m):
                        $isCaptain = ($m['player_id'] == ($team['captain_player_id'] ?? 0));
                        $roleIcon = 'fa-user-ninja';
                        $roleLower = strtolower($m['in_game_role'] ?? '');
                        if (str_contains($roleLower, 'tank') || str_contains($roleLower, 'roam'))
                            $roleIcon = 'fa-shield-heart';
                        elseif (str_contains($roleLower, 'carry') || str_contains($roleLower, 'adc') || str_contains($roleLower, 'damage'))
                            $roleIcon = 'fa-crosshairs';
                        elseif (str_contains($roleLower, 'mage') || str_contains($roleLower, 'mid'))
                            $roleIcon = 'fa-wand-magic-sparkles';
                        elseif (str_contains($roleLower, 'jungle') || str_contains($roleLower, 'assassin'))
                            $roleIcon = 'fa-bolt';
                        ?>
                        <a href="player-profile.php?id=<?php echo $m['player_id']; ?>"
                            class="glass-card p-5 rounded-2xl flex flex-col justify-between space-y-3 group shadow-lg <?php echo $isCaptain ? 'captain-glow bg-amber-500/10' : ''; ?>"
                            data-aos="fade-up" data-aos-delay="<?php echo $index * 80; ?>">
                            <div class="space-y-1">
                                <h3 class="font-bold text-white text-base font-display group-hover:text-brand-orange transition-colors flex items-center justify-between">
                                    <span class="flex items-center gap-2">
                                        <i class="fa-solid <?php echo $roleIcon; ?> text-brand-orange text-xs"></i>
                                        <?php echo htmlspecialchars($m['display_name']); ?>
                                    </span>
                                    <?php if ($isCaptain): ?>
                                        <span class="px-2.5 py-0.5 rounded bg-amber-500 text-slate-950 text-[10px] font-black uppercase shadow-[0_0_10px_rgba(245,158,11,0.8)]">กัปตัน</span>
                                    <?php endif; ?>
                                </h3>
                                <?php if (!empty($m['in_game_role'])): ?>
                                    <p class="text-xs text-gray-400 font-medium pl-5"><?php echo htmlspecialchars($m['in_game_role']); ?></p>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="space-y-4">
                <h2 class="text-xl font-bold font-display text-white uppercase tracking-wider flex items-center gap-2 border-b border-white/15 pb-3"
                    data-aos="fade-right">
                    <i class="fa-solid fa-clock-rotate-left text-amber-400"></i> ประวัติการแข่งขันล่าสุด
                </h2>

                <div class="glass-panel rounded-2xl overflow-hidden shadow-xl border border-white/15">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-gray-200">
                            <thead class="bg-black/40 text-xs uppercase font-bold text-gray-300 border-b border-white/15 font-display">
                                <tr>
                                    <th class="p-4">ทัวร์นาเมนต์</th>
                                    <th class="p-4">คู่แข่งขัน</th>
                                    <th class="p-4 text-right">ผลการแข่งขัน</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10 font-medium">
                                <?php if (empty($history)): ?>
                                    <tr>
                                        <td colspan="3" class="p-12 text-center text-gray-400">
                                            <i class="fa-solid fa-calendar-xmark text-4xl mb-2 block opacity-40 text-brand-orange"></i>
                                            ยังไม่มีประวัติการแข่งขันในระบบ
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($history as $hIndex => $h):
                                    $isTeam1 = ($h['team1_id'] == $teamId);
                                    $opponent = $isTeam1 ? $h['team2_name'] : $h['team1_name'];
                                    $myScore = $isTeam1 ? $h['team1_score'] : $h['team2_score'];
                                    $oppScore = $isTeam1 ? $h['team2_score'] : $h['team1_score'];
                                    $won = ($h['winner_team_id'] == $teamId);
                                    $rowStyle = $won ? 'border-l-4 border-l-emerald-500 bg-emerald-500/5' : 'hover:bg-white/10';
                                    $staggerDelay = min($hIndex * 40, 600);
                                    ?>
                                    <tr class="history-row transition-colors <?php echo $rowStyle; ?>"
                                        style="animation-delay: <?php echo $staggerDelay; ?>ms;">
                                        <td class="p-4 font-bold text-white"><?php echo htmlspecialchars($h['tournament_name']); ?></td>
                                        <td class="p-4 text-gray-300">vs <?php echo htmlspecialchars($opponent ?? '(bye)'); ?></td>
                                        <td class="p-4 text-right font-mono font-bold">
                                            <?php if ($won): ?>
                                                <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 text-xs">ชนะ</span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-1 rounded-full bg-rose-500/20 text-rose-300 border border-rose-500/40 text-xs">แพ้</span>
                                            <?php endif; ?>
                                            <?php if ($myScore !== null): ?>
                                                <span class="ml-2 text-white font-black">(<?php echo $myScore; ?> - <?php echo $oppScore; ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main>

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

    <script src="https://unpkg.com/aos@next/dist/aos.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            AOS.init({ once: true, duration: 800, easing: 'ease-out-cubic' });
        });
    </script>
</body>

</html>