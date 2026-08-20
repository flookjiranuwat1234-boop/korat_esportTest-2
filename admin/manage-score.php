<?php
// admin/record-match.php (และ admin/manage-score.php)
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/ranking.php';
require_once '../includes/bracket.php';
requireRole('admin');

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$error = '';
$success = '';

// บันทึกผลแมตช์
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'save_score') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $matchId = (int) $_POST['match_id'];
        $score1 = (int) $_POST['score1'];
        $score2 = (int) $_POST['score2'];

        // ต้องรู้ก่อนว่าแมตช์นี้อยู่ในทัวร์นาเมนต์รูปแบบไหน เพราะ Single Elimination ห้ามเสมอ
        $fmtStmt = $pdo->prepare("
            SELECT t.format, m.team1_id, m.team2_id
            FROM matches m JOIN tournaments t ON t.tournament_id = m.tournament_id
            WHERE m.match_id = :id
        ");
        $fmtStmt->execute(['id' => $matchId]);
        $matchInfo = $fmtStmt->fetch();

        $isKnockout = ($matchInfo['format'] == 'single_elimination');

        if ($isKnockout && $score1 == $score2) {
            $error = 'คะแนนเสมอกันไม่ได้ Single Elimination ต้องมีผู้ชนะ';
        } else {
            $winnerId = null;
            if ($score1 != $score2) {
                $winnerId = ($score1 > $score2) ? $matchInfo['team1_id'] : $matchInfo['team2_id'];
            }

            $update = $pdo->prepare("
                UPDATE matches
                SET team1_score = :s1, team2_score = :s2, winner_team_id = :winner,
                    status = 'completed', completed_at = NOW()
                WHERE match_id = :id
            ");
            $update->execute([
                's1' => $score1, 's2' => $score2, 'winner' => $winnerId, 'id' => $matchId,
            ]);

            try {
                // คำนวณคะแนนสะสมของทีม/ผู้เล่น
                updateRankingsAfterMatch($pdo, $matchId);

                // เลื่อนทีมชนะไปรอบถัดไป
                if ($winnerId) {
                    advanceWinner($pdo, $matchId, $winnerId);
                }

                $success = 'บันทึกผลและอัปเดตคะแนนเรียบร้อยแล้ว';
            } catch (Exception $e) {
                $error = 'บันทึกผลแล้ว แต่อัปเดตคะแนนไม่สำเร็จ: ' . $e->getMessage();
            }
        }
    }
}

// ดึงรายการทัวร์นาเมนต์ที่กำลังดำเนินการแข่ง
$tournaments = $pdo->query("SELECT tournament_id, name, format FROM tournaments WHERE status = 'ongoing' ORDER BY name")->fetchAll();

$matches = [];
$currentFormat = '';
if ($tournamentId) {
    $fStmt = $pdo->prepare("SELECT format FROM tournaments WHERE tournament_id = :id");
    $fStmt->execute(['id' => $tournamentId]);
    $currentFormat = $fStmt->fetchColumn();

    $mStmt = $pdo->prepare("
        SELECT m.*, t1.name AS team1_name, t2.name AS team2_name, tg.name AS group_name
        FROM matches m
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        LEFT JOIN tournament_groups tg ON tg.tournament_group_id = m.group_id
        WHERE m.tournament_id = :tid
        ORDER BY tg.name, m.round_number, m.match_index
    ");
    $mStmt->execute(['tid' => $tournamentId]);
    $matches = $mStmt->fetchAll();
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกผลแมตช์ - Korat Esport</title>
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
                            glow: '#FF6600',
                            lightbg: '#F4F6F9',
                            sidebar: '#0F172A',
                        }
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                        display: ['Orbitron', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #F4F6F9; }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover, .nav-item.active {
            background: rgba(255, 85, 0, 0.12);
            color: #FF5500;
            border-left: 4px solid #FF5500;
        }
    </style>
</head>
<body class="text-slate-800 font-sans min-h-screen flex antialiased">

    <!-- ================= 1. SIDEBAR ด้านข้าง ================= -->
    <aside class="w-64 bg-brand-sidebar text-slate-300 flex flex-col fixed inset-y-0 left-0 z-50 shadow-xl">
        <div class="p-6 border-b border-slate-800 flex items-center gap-3">
            <img src="../assets/img/logo.png" alt="Korat Esport" class="h-10 w-auto filter drop-shadow" onError="this.src='https://placehold.co/80x80/0F172A/FF5500?text=KE';">
            <div>
                <h1 class="font-display font-black text-lg text-white tracking-wider">KORAT <span class="text-brand-orange">ESPORT</span></h1>
                <p class="text-[10px] tracking-widest text-slate-400 uppercase font-semibold">Admin Command Center</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-1 text-sm font-medium">
            <a href="dashboard.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                <span>หน้าหลัก (Dashboard)</span>
            </a>
            <a href="manage-tournament.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-trophy w-5 text-center"></i>
                <span>จัดการทัวร์นาเมนต์</span>
            </a>
            <a href="manage-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-people-group w-5 text-center"></i>
                <span>จัดการทีมสมัคร</span>
            </a>
            <a href="manage-members.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-users-gear w-5 text-center"></i>
                <span>จัดการสมาชิก</span>
            </a>
            <a href="manage-news.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-newspaper w-5 text-center"></i>
                <span>จัดการข่าวสาร</span>
            </a>
            <a href="manage-gallery.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-images w-5 text-center"></i>
                <span>จัดการแกลลอรี่</span>
            </a>
            <a href="recommended-lodging.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-hotel w-5 text-center"></i>
                <span>ที่พักแนะนำ</span>
            </a>
            <a href="record-match.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-pen-to-square w-5 text-center text-brand-orange"></i>
                <span>บันทึกผลแมตช์</span>
            </a>
            <a href="checkin-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-user-check w-5 text-center"></i>
                <span>เช็คอินทีม</span>
            </a>
        </nav>

        <div class="p-4 border-t border-slate-800 bg-slate-950/50">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3 overflow-hidden">
                    <div class="w-9 h-9 rounded-full bg-brand-orange text-white flex items-center justify-center font-bold text-sm shrink-0">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div class="truncate">
                        <div class="text-sm font-bold text-white truncate">
                            <?= htmlspecialchars($currentUser['username'] ?? $currentUser['name'] ?? 'Admin User') ?>
                        </div>
                        <span class="inline-block text-[10px] font-semibold text-brand-orange bg-brand-orange/10 px-2 py-0.2 rounded uppercase">
                            <?= htmlspecialchars($currentUser['role'] ?? 'Administrator') ?>
                        </span>
                    </div>
                </div>
                <a href="../auth/logout.php" title="ออกจากระบบ" class="text-slate-400 hover:text-rose-400 transition-colors p-2 text-base">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- ================= 2. MAIN CONTENT AREA (พื้นหลังสว่าง) ================= -->
    <div class="flex-1 ml-64 min-h-screen flex flex-col">

        <!-- Header Panel -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    บันทึกผลแมตช์ <span class="text-brand-orange">(RECORD MATCH SCORE)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">เลือกรายการแข่งขันและกรอกคะแนนการแข่งขันรายคู่</p>
            </div>
            
            <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
            </a>
        </header>

        <main class="p-8 space-y-6 flex-1">

            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-lg shrink-0 text-rose-500"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-circle-check text-lg shrink-0 text-emerald-500"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <!-- SELECT TOURNAMENT FORM -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
                <form method="GET" class="flex flex-col sm:flex-row items-center gap-4">
                    <label class="text-xs font-bold uppercase text-slate-700 tracking-wider whitespace-nowrap flex items-center gap-2">
                        <i class="fa-solid fa-trophy text-brand-orange"></i>
                        เลือกทัวร์นาเมนต์ที่กำลังแข่ง:
                    </label>
                    <select name="tournament_id" onchange="this.form.submit()"
                        class="w-full sm:w-auto flex-1 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-semibold">
                        <option value="">-- กรุณาเลือกรายการแข่งขัน --</option>
                        <?php foreach ($tournaments as $t): ?>
                            <option value="<?php echo $t['tournament_id']; ?>" <?php echo ($t['tournament_id'] == $tournamentId) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?> (<?php echo htmlspecialchars($t['format']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <!-- MATCHES TABLE -->
            <?php if ($tournamentId): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden space-y-4">
                    <div class="p-4 border-b border-slate-100 bg-slate-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-2">
                            <i class="fa-solid fa-list-ol text-brand-orange"></i>
                            รายการแมตช์การแข่งขัน
                        </h2>

                        <?php if ($currentFormat != 'single_elimination'): ?>
                            <span class="text-xs text-amber-700 bg-amber-50 px-3 py-1 rounded-lg border border-amber-200 font-medium">
                                <i class="fa-solid fa-info-circle mr-1"></i> รูปแบบพบกันหมด: สามารถใส่คะแนนเท่ากันได้ (ผลเสมอ)
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-600">
                            <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                <tr>
                                    <?php if ($currentFormat != 'single_elimination'): ?>
                                        <th class="p-4">กลุ่ม</th>
                                    <?php endif; ?>
                                    <th class="p-4">รอบ</th>
                                    <th class="p-4 text-center">คู่ที่</th>
                                    <th class="p-4 text-right">ทีมแข่งขัน 1</th>
                                    <th class="p-4 text-center">VS</th>
                                    <th class="p-4">ทีมแข่งขัน 2</th>
                                    <th class="p-4 text-center">ผลการแข่งขัน / บันทึก</th>
                                    <th class="p-4 text-center">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (count($matches) == 0): ?>
                                    <tr>
                                        <td colspan="8" class="p-8 text-center text-slate-400">
                                            <i class="fa-solid fa-gamepad text-3xl mb-2 block opacity-40"></i>
                                            ยังไม่มีรายการแมตช์ในทัวร์นาเมนต์นี้
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($matches as $m): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <!-- กลุ่ม -->
                                    <?php if ($currentFormat != 'single_elimination'): ?>
                                        <td class="p-4 text-xs font-bold text-brand-orange">
                                            <?php echo htmlspecialchars($m['group_name'] ?? '-'); ?>
                                        </td>
                                    <?php endif; ?>

                                    <!-- รอบ -->
                                    <td class="p-4 text-xs font-medium text-slate-600">
                                        นัดที่ <?php echo $m['round_number']; ?>
                                    </td>

                                    <!-- คู่ที่ -->
                                    <td class="p-4 text-center font-mono text-xs font-bold text-slate-400">
                                        #<?php echo $m['match_index'] + 1; ?>
                                    </td>

                                    <!-- ทีม 1 -->
                                    <td class="p-4 text-right font-bold text-slate-900">
                                        <?php echo htmlspecialchars($m['team1_name'] ?? 'รอผู้ชนะรอบก่อน'); ?>
                                    </td>

                                    <td class="p-4 text-center text-xs font-black text-slate-300">VS</td>

                                    <!-- ทีม 2 -->
                                    <td class="p-4 font-bold text-slate-900">
                                        <?php echo htmlspecialchars($m['team2_name'] ?? 'รอผู้ชนะรอบก่อน'); ?>
                                    </td>

                                    <!-- บันทึกคะแนน / ผลการแข่ง -->
                                    <td class="p-4 text-center">
                                        <?php if ($m['status'] == 'completed' || $m['status'] == 'walkover'): ?>
                                            <span class="font-display font-bold text-slate-900 bg-slate-100 border border-slate-200 px-3 py-1 rounded-lg inline-block">
                                                <?php echo $m['team1_score']; ?> - <?php echo $m['team2_score']; ?>
                                            </span>
                                            <?php if ($m['status'] == 'completed' && $m['winner_team_id'] === null): ?>
                                                <span class="text-[11px] font-bold text-amber-600 block mt-0.5">(เสมอ)</span>
                                            <?php endif; ?>
                                        <?php elseif ($m['team1_id'] && $m['team2_id']): ?>
                                            <!-- ฟอร์มกรอกคะแนน -->
                                            <form method="POST" class="inline-flex items-center gap-2">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="save_score">
                                                <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                                                
                                                <input type="number" name="score1" min="0" required 
                                                    class="w-12 text-center bg-slate-50 border border-slate-300 rounded-lg py-1 px-1 text-sm font-bold text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange">
                                                
                                                <span class="font-bold text-slate-400">-</span>
                                                
                                                <input type="number" name="score2" min="0" required 
                                                    class="w-12 text-center bg-slate-50 border border-slate-300 rounded-lg py-1 px-1 text-sm font-bold text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange">
                                                
                                                <button type="submit" 
                                                    class="px-3 py-1 bg-brand-orange hover:bg-brand-glow text-white text-xs font-bold rounded-lg transition-all shadow-sm cursor-pointer ml-1">
                                                    บันทึก
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 italic">รอยืนยันคู่แข่งขัน</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- สถานะ -->
                                    <td class="p-4 text-center">
                                        <?php if ($m['status'] == 'completed'): ?>
                                            <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">แข่งเสร็จแล้ว</span>
                                        <?php elseif ($m['status'] == 'walkover'): ?>
                                            <span class="px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold">ชนะบาย</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 text-xs font-bold">รอแข่ง</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>

</body>
</html>