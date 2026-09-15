<?php
// pages/players.php
require_once '../config/db.php';
require_once '../includes/auth.php';

$isLoggedIn = isLoggedIn();
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$games = $pdo->query("SELECT game_id, name FROM games WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$gameId = max(0, (int) ($_GET['game_id'] ?? 0));
$q = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT DISTINCT p.player_id, p.display_name,
        (SELECT t.name FROM team_members tm
         JOIN teams t ON t.team_id = tm.team_id
         WHERE tm.player_id = p.player_id AND tm.is_active = 1
         " . ($gameId ? "AND t.game_id = :game_id" : "") . "
         ORDER BY t.name LIMIT 1) AS team_name
    FROM players p
";
$conditions = [];
$params = [];
if ($gameId) {
    $sql .= " JOIN team_members tm2 ON tm2.player_id = p.player_id
              JOIN teams t2 ON t2.team_id = tm2.team_id AND t2.game_id = :game_id2";
    $params['game_id'] = $gameId;
    $params['game_id2'] = $gameId;
}
if ($q !== '') {
    $conditions[] = "p.display_name LIKE :q";
    $params['q'] = "%{$q}%";
}
if ($conditions) $sql .= " WHERE " . implode(' AND ', $conditions);
$sql .= " ORDER BY p.display_name LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$players = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teamSql = "SELECT t.team_id, t.name, g.name AS game_name
    FROM teams t
    JOIN games g ON g.game_id = t.game_id
    WHERE t.is_solo_wrapper = 0";
$teamParams = [];
if ($gameId) {
    $teamSql .= " AND t.game_id = :team_game_id";
    $teamParams['team_game_id'] = $gameId;
}
if ($q !== '') {
    $teamSql .= " AND t.name LIKE :team_q";
    $teamParams['team_q'] = "%{$q}%";
}
$teamSql .= " ORDER BY t.name LIMIT 100";
$teamStmt = $pdo->prepare($teamSql);
$teamStmt->execute($teamParams);
$teams = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ทีมและนักกีฬา - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { orange: '#FF5500', glow: '#FF7700', dark: '#08090C', panel: '#121318' } }, fontFamily: { sans: ['Kanit', 'sans-serif'], display: ['Orbitron', 'sans-serif'] }, boxShadow: { orange: '0 0 30px rgba(255,85,0,.35)' } } } };
    </script>
    <style>
        body { background: #08090C; color: #f3f4f6; }
        .arena-bg { background: linear-gradient(180deg, rgba(8,9,12,.72), #08090C 75%), url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop') center/cover fixed; }
        .glass { background: rgba(18,19,24,.76); border: 1px solid rgba(255,255,255,.12); backdrop-filter: blur(18px); }
        .player-card { background: linear-gradient(145deg, rgba(25,27,35,.92), rgba(12,13,17,.96)); border: 1px solid rgba(255,255,255,.12); transition: .25s ease; }
        .player-card:hover { transform: translateY(-4px); border-color: rgba(255,85,0,.7); box-shadow: 0 12px 28px rgba(255,85,0,.18); }
    </style>
</head>
<body class="font-sans min-h-screen antialiased">
    <div class="fixed inset-0 arena-bg pointer-events-none"></div>
    <div class="relative z-10 min-h-screen">
        <header class="sticky top-0 z-50 border-b border-brand-orange/30 bg-brand-dark/90 backdrop-blur-xl">
            <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                <a href="index.php" class="flex items-center gap-3">
                    <img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto" onerror="this.src='https://placehold.co/80x80/121318/FF5500?text=KE';">
                    <span class="hidden font-display text-lg font-black tracking-wider text-white sm:block">KORAT <span class="text-brand-orange">ESPORT</span><small class="mt-[-4px] block font-sans text-[9px] tracking-widest text-gray-400">OFFICIAL ARENA & HUB</small></span>
                </a>
                <nav class="hidden items-center gap-1 md:flex">
                    <a href="index.php" class="rounded-xl px-3 py-2 text-sm text-gray-300 hover:bg-white/10 hover:text-brand-orange">หน้าแรก</a>
                    <a href="tournaments.php" class="rounded-xl px-3 py-2 text-sm text-gray-300 hover:bg-white/10 hover:text-brand-orange">ทัวร์นาเมนต์</a>
                    <a href="ranking.php" class="rounded-xl px-3 py-2 text-sm text-gray-300 hover:bg-white/10 hover:text-brand-orange">อันดับ</a>
                    <a href="news.php" class="rounded-xl px-3 py-2 text-sm text-gray-300 hover:bg-white/10 hover:text-brand-orange">ข่าวสาร</a>
                </nav>
                <a href="<?= $isLoggedIn && ($currentUser['role'] ?? '') === 'admin' ? '../admin/dashboard.php' : ($isLoggedIn ? 'profile.php' : '../auth/login.php') ?>" class="rounded-xl border border-white/15 px-3 py-2 text-xs font-semibold text-gray-200 hover:border-brand-orange hover:text-brand-orange"><?= $isLoggedIn ? htmlspecialchars($currentUser['username'] ?: 'โปรไฟล์') : 'เข้าสู่ระบบ' ?></a>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="mb-8">
                <p class="mb-2 text-xs font-bold uppercase tracking-[.25em] text-brand-orange">KORAT ESPORT DIRECTORY</p>
                <h1 class="font-display text-3xl font-black text-white sm:text-5xl">ทีมและนักกีฬา</h1>
                <p class="mt-3 max-w-2xl text-sm text-gray-400">ค้นหาทีมและนักกีฬา ดูข้อมูลสมาชิก และเปิดโปรไฟล์เพื่อดูรายละเอียดการแข่งขัน</p>
            </div>

            <section class="glass mb-8 rounded-2xl p-4 sm:p-5">
                <form method="GET" class="grid gap-3 md:grid-cols-[1fr_18rem_auto]">
                    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="ค้นหาชื่อนักกีฬา..." class="rounded-xl border border-white/10 bg-black/30 px-4 py-3 text-sm text-white outline-none focus:border-brand-orange">
                    <select name="game_id" class="rounded-xl border border-white/10 bg-black/30 px-4 py-3 text-sm text-white outline-none focus:border-brand-orange">
                        <option value="0">ทุกเกม</option>
                        <?php foreach ($games as $game): ?><option value="<?= (int) $game['game_id'] ?>" <?= $gameId === (int) $game['game_id'] ? 'selected' : '' ?>><?= htmlspecialchars($game['name']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="rounded-xl bg-brand-orange px-6 py-3 text-sm font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-magnifying-glass mr-2"></i>ค้นหา</button>
                </form>
            </section>

            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-people-group mr-2 text-brand-orange"></i>ทีมทั้งหมด</h2>
                <span class="text-xs text-gray-500">แสดงสูงสุด 100 รายการ</span>
            </div>
            <?php if (!$teams): ?>
                <div class="glass mb-10 rounded-2xl p-10 text-center text-gray-400"><i class="fa-solid fa-people-group mb-3 block text-4xl text-brand-orange"></i>ไม่พบทีมที่ตรงกับเงื่อนไข</div>
            <?php else: ?>
                <div class="mb-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <?php foreach ($teams as $team): ?>
                        <a href="team-profile.php?id=<?= (int) $team['team_id'] ?>" class="player-card rounded-2xl p-5">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-orange/15 text-brand-orange"><i class="fa-solid fa-shield-halved text-xl"></i></div>
                            <h3 class="truncate text-lg font-bold text-white"><?= htmlspecialchars($team['name']) ?></h3>
                            <p class="mt-1 truncate text-xs text-gray-400"><i class="fa-solid fa-gamepad mr-1 text-brand-orange"></i><?= htmlspecialchars($team['game_name']) ?></p>
                            <div class="mt-5 border-t border-white/10 pt-3 text-xs font-semibold text-brand-orange">ดูโปรไฟล์ทีม <i class="fa-solid fa-arrow-right ml-1"></i></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-bold text-white"><i class="fa-solid fa-users mr-2 text-brand-orange"></i>นักกีฬาทั้งหมด</h2>
                <span class="text-xs text-gray-500">แสดงสูงสุด 100 รายการ</span>
            </div>
            <?php if (!$players): ?>
                <div class="glass rounded-2xl p-12 text-center text-gray-400"><i class="fa-solid fa-user-slash mb-3 block text-4xl text-brand-orange"></i>ไม่พบนักกีฬาที่ตรงกับเงื่อนไข</div>
            <?php else: ?>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <?php foreach ($players as $player): ?>
                        <a href="player-profile.php?id=<?= (int) $player['player_id'] ?>" class="player-card rounded-2xl p-5">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-xl bg-brand-orange/15 text-brand-orange"><i class="fa-solid fa-user-ninja text-xl"></i></div>
                            <h3 class="truncate text-lg font-bold text-white"><?= htmlspecialchars($player['display_name']) ?></h3>
                            <p class="mt-1 truncate text-xs text-gray-400"><i class="fa-solid fa-shield-halved mr-1 text-brand-orange"></i><?= htmlspecialchars($player['team_name'] ?: 'ยังไม่มีทีมสังกัด') ?></p>
                            <div class="mt-5 border-t border-white/10 pt-3 text-xs font-semibold text-brand-orange">ดูโปรไฟล์ <i class="fa-solid fa-arrow-right ml-1"></i></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
<script src="../assets/js/mobile-nav.js" defer></script>
</body>
</html>
