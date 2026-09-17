<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/registration_status.php';
require_once '../includes/tournament_registration.php';
requireLogin();

date_default_timezone_set('Asia/Bangkok');
$currentUser = [
    'username' => $_SESSION['username'] ?? 'ผู้ใช้งาน',
    'role' => $_SESSION['role'] ?? 'Player',
];
$error = '';
$success = '';
$csrfToken = generateCsrfToken();
$myTeamsStmt = $pdo->prepare('SELECT t.team_id, t.name, t.tag FROM teams t INNER JOIN players p ON p.player_id = t.captain_player_id WHERE p.user_id = :user_id AND t.status = "active" ORDER BY t.name');
$myTeamsStmt->execute(['user_id' => (int) $_SESSION['user_id']]);
$myTeams = $myTeamsStmt->fetchAll(PDO::FETCH_ASSOC);

if (($_GET['action'] ?? '') === 'search_players') {
    header('Content-Type: application/json; charset=utf-8');
    $tournamentId = filter_input(INPUT_GET, 'tournament_id', FILTER_VALIDATE_INT);
    $categoryId = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT);
    $term = trim((string) ($_GET['q'] ?? ''));
    echo json_encode(
        $tournamentId && $categoryId ? searchTournamentPlayers($pdo, $tournamentId, $categoryId, $term) : [],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $mode = strtolower((string) ($_POST['mode'] ?? 'team'));
        $categoryId = (int) ($_POST['tournament_category_id'] ?? 0);
        $tournamentStmt = $pdo->prepare('SELECT t.*, g.play_mode FROM tournaments t INNER JOIN games g ON g.game_id = t.game_id WHERE t.tournament_id = :id LIMIT 1');
        $tournamentStmt->execute(['id' => $tournamentId]);
        $tournament = $tournamentStmt->fetch(PDO::FETCH_ASSOC);
        $window = $tournament ? getTournamentRegistrationState($tournament, new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'))) : ['allowed' => false, 'message' => 'ไม่พบ Tournament'];
        if (!$tournament || !$window['allowed']) {
            $error = $window['message'];
        } elseif ($mode === 'solo') {
            if ($tournament['play_mode'] !== 'solo') {
                $error = 'รายการนี้ไม่ใช่ประเภทเดี่ยว';
            } else {
                $categoryStmt = $pdo->prepare('SELECT * FROM tournament_categories WHERE tournament_category_id = :category AND tournament_id = :tournament AND is_active = 1 LIMIT 1');
                $categoryStmt->execute(['category' => $categoryId, 'tournament' => $tournamentId]);
                $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
                if (!$category) {
                    $error = 'ไม่พบรุ่นการแข่งขันของ Tournament นี้';
                } else {
                    $playerStmt = $pdo->prepare('SELECT p.*, u.status AS account_status FROM players p INNER JOIN users u ON u.user_id = p.user_id WHERE p.user_id = :user LIMIT 1');
                    $playerStmt->execute(['user' => (int) $_SESSION['user_id']]);
                    $player = $playerStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$player) {
                        $error = 'ไม่พบ Player Profile ของบัญชีนี้';
                    } elseif (($eligibilityError = tournamentCategoryAllowsPlayer($player, $category)) !== null) {
                        $error = $eligibilityError;
                    } else {
                        $duplicate = $pdo->prepare('SELECT 1 FROM tournament_registrations WHERE tournament_id = :tournament AND tournament_category_id = :category AND player_id = :player AND status IN ("pending","approved") LIMIT 1');
                        $duplicate->execute(['tournament' => $tournamentId, 'category' => $categoryId, 'player' => (int) $player['player_id']]);
                        if ($duplicate->fetchColumn()) {
                            $error = 'ผู้เล่นนี้สมัคร Category นี้แล้ว';
                        } else {
                            $categoryCode = (string) ($category['category_code'] ?: 'open');
                            try {
                                $pdo->beginTransaction();
                                $capacityStmt = $pdo->prepare('SELECT tc.max_participants, COUNT(tr.tournament_registration_id) AS registered_count
                                    FROM tournament_categories tc
                                    LEFT JOIN tournament_registrations tr
                                        ON tr.tournament_category_id = tc.tournament_category_id
                                        AND tr.status IN ("pending", "approved")
                                    WHERE tc.tournament_category_id = :category_id
                                    GROUP BY tc.tournament_category_id, tc.max_participants
                                    FOR UPDATE');
                                $capacityStmt->execute(['category_id' => $categoryId]);
                                $capacity = $capacityStmt->fetch(PDO::FETCH_ASSOC);
                                if (!$capacity || (int) $capacity['registered_count'] >= (int) $capacity['max_participants']) {
                                    throw new InvalidArgumentException('รายการนี้เต็มจำนวนแล้ว');
                                }
                                    ensureRegistrationStatusHistoryTable($pdo);
                                    $insert = $pdo->prepare('INSERT INTO tournament_registrations (tournament_id,tournament_category_id,player_id,team_id,category,status,participation_status) VALUES (:tournament,:category,:player,NULL,:code,"pending","pending_admin_review")');
                                    $insert->execute(['tournament' => $tournamentId, 'category' => $categoryId, 'player' => (int) $player['player_id'], 'code' => $categoryCode]);
                                    $registrationId = (int) $pdo->lastInsertId();
                                    snapshotTournamentRoster($pdo, $registrationId, null, (int) $player['player_id']);
                                    recordRegistrationStatus($pdo, $registrationId, 'pending', (int) $_SESSION['user_id'], 'สมัครแข่งขันแบบเดี่ยว');
                                    $pdo->commit();
                                    $success = 'สมัครเข้าร่วมรายการเรียบร้อยแล้ว';
                                } catch (Throwable $exception) {
                                    if ($pdo->inTransaction()) $pdo->rollBack();
                                    $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'สมัครแข่งขันไม่สำเร็จ';
                                }
                        }
                    }
                }
            }
        } elseif ($mode === 'team') {
            $roster = [];
            foreach ((array) ($_POST['roster'] ?? []) as $member) {
                $roster[] = ['player_id' => (int) ($member['player_id'] ?? 0), 'roles' => (array) ($member['roles'] ?? [])];
            }
            $logoPath = null;
            $uploadedLogo = null;
            try {
                if (!empty($_FILES['team_logo']['tmp_name'])) {
                    if ($_FILES['team_logo']['error'] !== UPLOAD_ERR_OK || (int) $_FILES['team_logo']['size'] > 2 * 1024 * 1024) {
                        throw new InvalidArgumentException('ไฟล์โลโก้ไม่ถูกต้องหรือมีขนาดเกิน 2MB');
                    }
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['team_logo']['tmp_name']);
                    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!isset($extensions[$mime]) || @getimagesize($_FILES['team_logo']['tmp_name']) === false) {
                        throw new InvalidArgumentException('รองรับโลโก้เฉพาะ JPG, PNG หรือ WebP');
                    }
                    $uploadDir = dirname(__DIR__) . '/assets/uploads';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) throw new RuntimeException('ไม่สามารถเตรียมพื้นที่เก็บโลโก้');
                    $filename = 'team_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
                    $uploadedLogo = $uploadDir . '/' . $filename;
                    if (!move_uploaded_file($_FILES['team_logo']['tmp_name'], $uploadedLogo)) throw new RuntimeException('ไม่สามารถบันทึกโลโก้');
                    $logoPath = 'assets/uploads/' . $filename;
                }
                saveTeamTournamentRegistration($pdo, (int) $_SESSION['user_id'], $tournamentId, $categoryId, (string) ($_POST['team_name'] ?? ''), $roster, (string) ($_POST['team_tag'] ?? ''), $logoPath, (int) ($_POST['team_id'] ?? 0) ?: null);
                $success = 'สมัครเข้าร่วมรายการเรียบร้อยแล้ว';
            } catch (Throwable $exception) {
                if ($uploadedLogo && is_file($uploadedLogo)) unlink($uploadedLogo);
                error_log(sprintf(
                    'Team tournament registration failed: user_id=%d tournament_id=%d category_id=%d error=%s',
                    (int) ($_SESSION['user_id'] ?? 0),
                    $tournamentId,
                    $categoryId,
                    $exception->getMessage()
                ));
                if ($exception instanceof InvalidArgumentException && $exception->getMessage() === 'หัวหน้าทีมนี้สมัคร Category นี้แล้ว') {
                    $success = 'สมัครเข้าร่วมรายการเรียบร้อยแล้ว';
                } else {
                    $error = $exception instanceof InvalidArgumentException
                        ? $exception->getMessage()
                        : 'ส่งใบสมัครไม่สำเร็จ กรุณาตรวจสอบข้อมูลทีมและรายชื่ออีกครั้ง';
                }
            }
        } else {
            $error = 'รูปแบบรายการแข่งขันไม่ถูกต้อง';
        }
    }
}

function getTournamentRegistrationState(array $tournament, DateTimeImmutable $now): array
{
    if (($tournament['status'] ?? '') !== 'registration_open') return ['allowed' => false, 'message' => 'รายการนี้ยังไม่เปิดรับสมัคร'];
    if (!empty($tournament['registration_start']) && $now < new DateTimeImmutable($tournament['registration_start'])) return ['allowed' => false, 'message' => 'ยังไม่ถึงเวลาเปิดรับสมัคร'];
    if (!empty($tournament['registration_end']) && $now > new DateTimeImmutable($tournament['registration_end'])) return ['allowed' => false, 'message' => 'ปิดรับสมัครแล้ว'];
    return ['allowed' => true, 'message' => ''];
}

$requestedTournamentId = (int) ($_GET['id'] ?? $_POST['tournament_id'] ?? 0);
$requestedCategoryId = (int) ($_GET['category_id'] ?? $_POST['tournament_category_id'] ?? 0);
$tournaments = [];
if ($requestedTournamentId > 0) {
    $tournamentListStmt = $pdo->prepare("SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t
        INNER JOIN games g ON g.game_id = t.game_id
        WHERE t.tournament_id = :tournament_id
        LIMIT 1");
    $tournamentListStmt->execute(['tournament_id' => $requestedTournamentId]);
    $selectedTournament = $tournamentListStmt->fetch(PDO::FETCH_ASSOC);
    if ($selectedTournament) {
        $tournaments[] = $selectedTournament;
    }
}
$categories = [];
$categoryStmt = $pdo->prepare('SELECT * FROM tournament_categories WHERE tournament_id = :tournament AND is_active = 1 ORDER BY tournament_category_id');
foreach ($tournaments as $tournament) {
    $categoryStmt->execute(['tournament' => $tournament['tournament_id']]);
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($requestedCategoryId > 0) {
        $availableCategories = array_values(array_filter(
            $availableCategories,
            static fn(array $category): bool => (int) $category['tournament_category_id'] === $requestedCategoryId
        ));
    }
    $categories[(int) $tournament['tournament_id']] = $availableCategories;
}
if ($requestedTournamentId > 0 && !$tournaments) {
    $error = 'ไม่พบรายการแข่งขันที่เลือก';
} elseif ($requestedCategoryId > 0 && $tournaments && !$categories[$requestedTournamentId]) {
    $error = 'ไม่พบหมวดการแข่งขันของรายการที่เลือก';
}
?>
<!doctype html>
<html lang="th">
<head>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={theme:{extend:{colors:{brand:{orange:'#FF5500',glow:'#FF7700',dark:'#0A0A0C'}},fontFamily:{sans:['Kanit','sans-serif'],display:['Orbitron','sans-serif']},boxShadow:{'orange-glow':'0 0 25px rgba(255,85,0,.45)'}}}};</script>
    <style>
        body{background:#0f1117}.bg-arena{background:linear-gradient(to bottom,rgba(15,17,23,.65),rgba(15,17,23,.96)),url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');background-size:cover;background-position:center;background-attachment:fixed}.glass-nav{background:rgba(15,17,23,.88);backdrop-filter:blur(16px);border-bottom:1px solid rgba(255,255,255,.15)}.glass-panel{background:rgba(255,255,255,.07);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.15)}.grid-bg{background-image:radial-gradient(rgba(255,255,255,.15) 1px,transparent 0);background-size:24px 24px}
    </style>
</head>
<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">
<div class="fixed inset-0 bg-arena z-0 pointer-events-none"></div><div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>
<div class="relative z-10 min-h-screen">
<header class="sticky top-0 z-50 glass-nav"><div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8"><div class="flex items-center justify-between h-20">
    <a href="index.php" class="flex items-center gap-3"><img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto" onerror="this.src='https://placehold.co/100x100/121318/FF5500?text=KE'"><div><span class="font-display font-black text-xl text-white">KORAT <span class="text-brand-orange">ESPORT</span></span><span class="block text-[10px] text-gray-300 font-bold uppercase -mt-1">Official Arena &amp; Hub</span></div></a>
    <nav class="hidden md:flex items-center gap-1"><a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">หน้าแรก</a><a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-brand-orange bg-white/10">ทัวร์นาเมนต์</a><a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ตารางคะแนน</a><a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ข่าวสาร</a><a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">แกลเลอรี่</a></nav>
    <div class="flex items-center gap-3 bg-white/10 p-1.5 pl-3.5 rounded-2xl"><span class="hidden sm:block text-sm font-bold"><?= htmlspecialchars($currentUser['username']) ?></span><a href="profile.php" class="w-9 h-9 rounded-xl bg-brand-orange text-white flex items-center justify-center"><i class="fa-solid fa-user"></i></a><a href="../auth/logout.php" class="w-9 h-9 rounded-xl bg-rose-500/20 text-rose-300 flex items-center justify-center"><i class="fa-solid fa-right-from-bracket"></i></a></div>
</div></div></header>
<main class="mx-auto max-w-5xl px-4 sm:px-6 py-12">
    <div class="mb-8 text-center"><div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest"><i class="fa-solid fa-trophy"></i> เปิดรับสมัคร</div><h1 class="mt-4 text-3xl sm:text-4xl font-black font-display">สมัครเข้าร่วมการแข่งขัน</h1><p class="mt-2 text-sm text-gray-400">กรอกข้อมูลทีมและเลือกผู้เล่นสำหรับรายการนี้</p></div>
    <?php if ($error): ?><div class="mb-5 rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-red-200"><?= htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mb-5 rounded-lg border border-green-500/40 bg-green-500/10 p-4 text-green-200"><?= htmlspecialchars($success); ?></div><?php endif; ?>
    <div class="space-y-8">
    <?php foreach ($tournaments as $tournament): ?>
        <?php $id = (int) $tournament['tournament_id']; ?>
        <?php $tournamentCategories = $categories[$id] ?? []; $fixedCategoryId = count($tournamentCategories) === 1 ? (int) $tournamentCategories[0]['tournament_category_id'] : 0; ?>
        <section class="glass-panel rounded-3xl p-6 sm:p-8 shadow-2xl">
            <h2 class="text-2xl font-bold"><?= htmlspecialchars($tournament['name']); ?> <span class="text-orange-400">(<?= htmlspecialchars($tournament['game_name']); ?>)</span></h2>
            <?php if ($tournament['play_mode'] === 'solo'): ?>
                <form method="post" class="mt-5 flex flex-wrap items-end gap-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>"><input type="hidden" name="tournament_id" value="<?= $id; ?>"><input type="hidden" name="mode" value="solo">
                    <?php if ($fixedCategoryId): ?><input type="hidden" name="tournament_category_id" value="<?= $fixedCategoryId; ?>"><?php endif; ?>
                    <label class="text-sm">รุ่นการแข่งขัน<select <?= $fixedCategoryId ? 'disabled' : ''; ?> name="<?= $fixedCategoryId ? '' : 'tournament_category_id'; ?>" required class="mt-1 block rounded-lg bg-slate-900 p-3"><?php foreach ($tournamentCategories as $category): ?><option value="<?= (int) $category['tournament_category_id']; ?>" <?= $fixedCategoryId === (int) $category['tournament_category_id'] ? 'selected' : ''; ?>><?= htmlspecialchars(strtolower((string) ($category['category_code'] ?? '')) === 'open' ? 'โอเพ่น' : ($category['label'] ?: $category['name'])); ?></option><?php endforeach; ?></select></label>
                    <button class="rounded-lg bg-orange-500 px-5 py-3 font-bold">สมัคร Solo</button>
                </form>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" class="team-form mt-5 space-y-5" data-tournament="<?= $id; ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>"><input type="hidden" name="tournament_id" value="<?= $id; ?>"><input type="hidden" name="mode" value="team">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label>ชื่อทีม<input name="team_name" required class="mt-1 w-full rounded-lg bg-slate-900 p-3"></label>
                        <label>ตัวย่อทีม<input name="team_tag" required maxlength="10" pattern="[A-Za-z0-9_-]{2,10}" class="mt-1 w-full rounded-lg bg-slate-900 p-3"></label>
                        <label>โลโก้ทีม (ถ้ามี)<input type="file" name="team_logo" accept="image/jpeg,image/png,image/webp" class="mt-1 w-full rounded-lg bg-slate-900 p-3"></label>
                    </div>
                    <?php if ($fixedCategoryId): ?><input type="hidden" name="tournament_category_id" value="<?= $fixedCategoryId; ?>"><?php endif; ?>
                    <label>รุ่นการแข่งขัน<select class="category mt-1 w-full rounded-lg bg-slate-900 p-3" <?= $fixedCategoryId ? 'disabled' : ''; ?> name="<?= $fixedCategoryId ? '' : 'tournament_category_id'; ?>" required><option value="" <?= $fixedCategoryId ? '' : 'selected'; ?>>เลือกรุ่นการแข่งขัน</option><?php foreach ($tournamentCategories as $category): ?><option value="<?= (int) $category['tournament_category_id']; ?>" <?= $fixedCategoryId === (int) $category['tournament_category_id'] ? 'selected' : ''; ?> data-starters="<?= (int) $category['starters_count']; ?>" data-subs="<?= (int) $category['substitutes_count']; ?>" data-required="<?= htmlspecialchars((string) $category['checkin_required_roles']); ?>"><?= htmlspecialchars(strtolower((string) ($category['category_code'] ?? '')) === 'open' ? 'โอเพ่น' : ($category['label'] ?: $category['name'])); ?></option><?php endforeach; ?></select></label>
                    <div class="category-info text-sm text-slate-400"></div><div class="roster-warning text-xs text-amber-300"></div>
                    <input type="search" class="search w-full rounded-lg bg-slate-900 p-3" placeholder="ค้นหาผู้เล่นด้วยชื่อ Username หรือ Player ID" disabled>
                    <div class="search-results space-y-2"></div>
                    <div class="selected grid gap-4 sm:grid-cols-2"><div><h3>ผู้เล่นตัวจริง <span class="starter-count">0</span></h3><div class="starters space-y-2"></div></div><div><h3>ตัวสำรอง <span class="sub-count">0</span></h3><div class="subs space-y-2"></div></div></div>
                    <div><h3>ทีมงาน</h3><div class="staff space-y-2"></div></div>
                    <button type="submit" disabled class="submit-team rounded-lg bg-orange-500 px-5 py-3 font-bold disabled:cursor-not-allowed disabled:opacity-40">ยืนยันการสมัคร</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
</main></div>
<script>
document.querySelectorAll('.team-form').forEach((form) => {
    const category = form.querySelector('.category'), search = form.querySelector('.search'), results = form.querySelector('.search-results');
    const starters = form.querySelector('.starters'), subs = form.querySelector('.subs'), staff = form.querySelector('.staff');
    let selected = new Map(), config = { starters: 0, subs: 0, required: [] };
    const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const hidden = (name, value) => { const i=document.createElement('input'); i.type='hidden'; i.name=name; i.value=value; i.dataset.generated='1'; return i; };
    const render = () => {
        form.querySelectorAll('[data-generated]').forEach((e) => e.remove()); starters.innerHTML=''; subs.innerHTML=''; staff.innerHTML='';
        let si=0, ui=0, fi=0; selected.forEach((v, id) => { const index = v.role==='starter'?si++:v.role==='substitute'?ui++:fi++; const row=document.createElement('div'); row.className='rounded-lg bg-slate-900 p-3 flex justify-between items-center gap-2'; row.innerHTML=`<span>${esc(v.name)}</span><span><select class="change-role rounded bg-slate-800 p-1" data-id="${id}"><option value="starter" ${v.role==='starter'?'selected':''}>ตัวจริง</option><option value="substitute" ${v.role==='substitute'?'selected':''}>สำรอง</option><option value="coach" ${v.role==='coach'?'selected':''}>โค้ช</option><option value="manager" ${v.role==='manager'?'selected':''}>ผู้จัดการ</option></select> <button type="button" class="remove text-red-300" data-id="${id}">นำออก</button></span>`; (v.role==='starter'?starters:v.role==='substitute'?subs:staff).appendChild(row); form.appendChild(hidden(`roster[${v.index}][player_id]`, id)); form.appendChild(hidden(`roster[${v.index}][roles][]`, v.role==='starter'?'player':v.role==='substitute'?'substitute':v.role)); });
        form.querySelector('.starter-count').textContent=`${si}/${config.starters}`; form.querySelector('.sub-count').textContent=`${ui}/${config.subs}`;
        const selectedRoles=[...selected.values()].map(v=>v.role); const requiredOk=config.required.every(role=>selectedRoles.indexOf(role==='player'?'starter':role==='substitute'?'substitute':role)>=0); form.querySelector('.submit-team').disabled=si!==config.starters||ui!==config.subs||!requiredOk;
    };
    category.addEventListener('change', () => { const o=category.selectedOptions[0]; config={ starters:Number(o.dataset.starters||0),subs:Number(o.dataset.subs||0),required:(o.dataset.required||'').split(',').map(role=>role.trim()).filter(Boolean)}; search.disabled=!category.value; form.querySelector('.category-info').textContent=`ผู้เล่นตัวจริง ${config.starters} คน · ตัวสำรอง ${config.subs} คน · โค้ช/ผู้จัดการทีมเป็นตัวเลือกเสริม`; selected.clear(); render(); });
    search.addEventListener('input', async () => { if(search.value.trim().length<1)return results.innerHTML=''; const p=new URLSearchParams({action:'search_players',tournament_id:form.dataset.tournament,category_id:category.value,q:search.value.trim()}); const r=await fetch(`register-tournament.php?${p}`); const players=await r.json(); results.innerHTML=players.length?players.map(x=>{const disabled=x.eligible?'':'disabled title="'+esc(x.eligibility_reason||'ผู้เล่นคนนี้ไม่ผ่านเงื่อนไขของรายการ')+'"';return `<div class="flex justify-between rounded-lg border border-white/10 p-3"><span>${esc(x.real_name||x.username)} (#${x.player_id}) ${x.age_at_tournament===null?'':'อายุ '+x.age_at_tournament+' ปี'}<small class="ml-2 text-red-300">${x.eligible?'':'ผู้เล่นคนนี้ไม่ผ่านเงื่อนไขของรายการ'}</small></span><span><button ${disabled} type="button" data-role="starter" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add mr-2 text-green-300">ตัวจริง</button><button ${disabled} type="button" data-role="substitute" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add mr-2 text-cyan-300">สำรอง</button><button ${disabled} type="button" data-role="coach" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add mr-2 text-purple-300">โค้ช</button><button ${disabled} type="button" data-role="manager" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add text-purple-300">ผู้จัดการ</button></span></div>`}).join(''):'<div class="rounded-lg border border-white/10 p-3 text-sm text-slate-400">ยังไม่พบนักกีฬาที่ตรงกับคำค้น</div>'; });
    form.addEventListener('change',(e)=>{const select=e.target.closest('.change-role');if(!select)return;const item=selected.get(Number(select.dataset.id));if(item){const role=select.value;if(role==='starter'&&[...selected.values()].filter(v=>v.role==='starter').length>=config.starters)return render();if(role==='substitute'&&[...selected.values()].filter(v=>v.role==='substitute').length>=config.subs)return render();item.role=role;render();}});
    form.addEventListener('click',(e)=>{const b=e.target.closest('.add,.remove');if(!b)return;if(b.classList.contains('remove'))selected.delete(Number(b.dataset.id));else{const id=Number(b.dataset.id),role=b.dataset.role;if(selected.has(id))return;if(role==='starter'&&[...selected.values()].filter(v=>v.role==='starter').length>=config.starters)return;if(role==='substitute'&&[...selected.values()].filter(v=>v.role==='substitute').length>=config.subs)return;selected.set(id,{role,name:b.dataset.name,index:selected.size});}render();});
    if (category.value) category.dispatchEvent(new Event('change'));
});
</script>    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>สมัครแข่งขัน - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&family=Orbitron:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body{background:#0f1117}.bg-arena{background:linear-gradient(to bottom,rgba(15,17,23,.65),rgba(15,17,23,.96)),url('https://images.unsplash.com/photo-1542751371-adc38448a05e?q=80&w=2070&auto=format&fit=crop');background-size:cover;background-position:center;background-attachment:fixed}.glass-nav{background:rgba(15,17,23,.88);backdrop-filter:blur(16px);border-bottom:1px solid rgba(255,255,255,.15)}.glass-panel{background:rgba(255,255,255,.07);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.15)}.grid-bg{background-image:radial-gradient(rgba(255,255,255,.15) 1px,transparent 0);background-size:24px 24px}
    </style>
</head>
<body class="text-gray-100 font-sans min-h-screen overflow-x-hidden antialiased">
<div class="fixed inset-0 bg-arena z-0 pointer-events-none"></div><div class="fixed inset-0 grid-bg opacity-30 z-0 pointer-events-none"></div>
<div class="relative z-10 min-h-screen">
<header class="sticky top-0 z-50 glass-nav"><div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8"><div class="flex items-center justify-between h-20">
    <a href="index.php" class="flex items-center gap-3"><img src="../assets/img/logo.png" alt="Korat Esport" class="h-11 w-auto" onerror="this.src='https://placehold.co/100x100/121318/FF5500?text=KE'"><div><span class="font-display font-black text-xl text-white">KORAT <span class="text-brand-orange">ESPORT</span></span><span class="block text-[10px] text-gray-300 font-bold uppercase -mt-1">Official Arena &amp; Hub</span></div></a>
    <nav class="hidden md:flex items-center gap-1"><a href="index.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">หน้าแรก</a><a href="tournaments.php" class="px-4 py-2 rounded-xl text-sm font-semibold text-brand-orange bg-white/10">ทัวร์นาเมนต์</a><a href="ranking.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ตารางคะแนน</a><a href="news.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">ข่าวสาร</a><a href="gallery.php" class="px-4 py-2 rounded-xl text-sm font-semibold hover:text-brand-orange">แกลเลอรี่</a></nav>
    <div class="flex items-center gap-3 bg-white/10 p-1.5 pl-3.5 rounded-2xl"><span class="hidden sm:block text-sm font-bold"><?= htmlspecialchars($currentUser['username']) ?></span><a href="profile.php" class="w-9 h-9 rounded-xl bg-brand-orange text-white flex items-center justify-center"><i class="fa-solid fa-user"></i></a><a href="../auth/logout.php" class="w-9 h-9 rounded-xl bg-rose-500/20 text-rose-300 flex items-center justify-center"><i class="fa-solid fa-right-from-bracket"></i></a></div>
</div></div></header>
<main class="mx-auto max-w-5xl px-4 sm:px-6 py-12">
    <div class="mb-8 text-center"><div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-brand-orange/20 border border-brand-orange/50 text-brand-orange text-xs font-bold uppercase tracking-widest"><i class="fa-solid fa-trophy"></i> เปิดรับสมัคร</div><h1 class="mt-4 text-3xl sm:text-4xl font-black font-display">สมัครเข้าร่วมการแข่งขัน</h1><p class="mt-2 text-sm text-gray-400">กรอกข้อมูลทีมและเลือกผู้เล่นสำหรับรายการนี้</p></div>
    <?php
        $registrationFlash = consumeFlashMessage();
        if (!$registrationFlash && ($error || $success)) {
            $registrationFlash = [
                'type' => $error ? 'error' : 'success',
                'message' => $error ?: $success,
            ];
        }
        echo renderFlashAlert($registrationFlash);
    ?>
    <div class="space-y-8">
    <?php foreach ($tournaments as $tournament): ?>
        <?php $id = (int) $tournament['tournament_id']; ?>
        <?php $tournamentCategories = $categories[$id] ?? []; $fixedCategoryId = count($tournamentCategories) === 1 ? (int) $tournamentCategories[0]['tournament_category_id'] : 0; ?>
        <section class="glass-panel rounded-3xl p-6 sm:p-8 shadow-2xl">
            <h2 class="text-2xl font-bold"><?= htmlspecialchars($tournament['name']); ?> <span class="text-orange-400">(<?= htmlspecialchars($tournament['game_name']); ?>)</span></h2>
            <?php if ($tournament['play_mode'] === 'solo'): ?>
                <form method="post" class="mt-5 flex flex-wrap items-end gap-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>"><input type="hidden" name="tournament_id" value="<?= $id; ?>"><input type="hidden" name="mode" value="solo">
                    <?php if ($fixedCategoryId): ?><input type="hidden" name="tournament_category_id" value="<?= $fixedCategoryId; ?>"><?php endif; ?>
                    <label class="text-sm">รุ่นการแข่งขัน<select <?= $fixedCategoryId ? 'disabled' : ''; ?> name="<?= $fixedCategoryId ? '' : 'tournament_category_id'; ?>" required class="mt-1 block rounded-lg bg-slate-900 p-3"><?php foreach ($tournamentCategories as $category): ?><option value="<?= (int) $category['tournament_category_id']; ?>" <?= $fixedCategoryId === (int) $category['tournament_category_id'] ? 'selected' : ''; ?>><?= htmlspecialchars(strtolower((string) ($category['category_code'] ?? '')) === 'open' ? 'โอเพ่น' : ($category['label'] ?: $category['name'])); ?></option><?php endforeach; ?></select></label>
                    <button class="rounded-lg bg-orange-500 px-5 py-3 font-bold">สมัคร Solo</button>
                </form>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" class="team-form mt-5 space-y-5" data-tournament="<?= $id; ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>"><input type="hidden" name="tournament_id" value="<?= $id; ?>"><input type="hidden" name="mode" value="team">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="sm:col-span-2">เลือกทีมของฉัน
                            <select name="team_id" required class="mt-1 w-full rounded-lg bg-slate-900 p-3">
                                <option value="">เลือกทีมที่ต้องการใช้สมัคร</option>
                                <?php foreach ($myTeams as $team): ?>
                                    <option value="<?= (int) $team['team_id']; ?>"><?= htmlspecialchars($team['name'] . ' (' . $team['tag'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (!$myTeams): ?><span class="mt-2 block text-xs text-amber-300">ยังไม่มีทีมของคุณ <a class="underline" href="create-team.php">สร้างทีมก่อนสมัครแข่งขัน</a></span><?php endif; ?>
                        </label>
                    </div>
                    <?php if ($fixedCategoryId): ?><input type="hidden" name="tournament_category_id" value="<?= $fixedCategoryId; ?>"><?php endif; ?>
                    <label>รุ่นการแข่งขัน<select class="category mt-1 w-full rounded-lg bg-slate-900 p-3" <?= $fixedCategoryId ? 'disabled' : ''; ?> name="<?= $fixedCategoryId ? '' : 'tournament_category_id'; ?>" required><option value="" <?= $fixedCategoryId ? '' : 'selected'; ?>>เลือกรุ่นการแข่งขัน</option><?php foreach ($tournamentCategories as $category): ?><option value="<?= (int) $category['tournament_category_id']; ?>" <?= $fixedCategoryId === (int) $category['tournament_category_id'] ? 'selected' : ''; ?> data-starters="<?= (int) $category['starters_count']; ?>" data-subs="<?= (int) $category['substitutes_count']; ?>" data-required="<?= htmlspecialchars((string) $category['checkin_required_roles']); ?>"><?= htmlspecialchars(strtolower((string) ($category['category_code'] ?? '')) === 'open' ? 'โอเพ่น' : ($category['label'] ?: $category['name'])); ?></option><?php endforeach; ?></select></label>
                    <div class="category-info text-sm text-slate-400"></div><div class="roster-warning text-xs text-amber-300"></div>
                    <input type="search" class="search w-full rounded-lg bg-slate-900 p-3" placeholder="ค้นหาผู้เล่นด้วยชื่อ Username หรือ Player ID" disabled>
                    <div class="search-results space-y-2"></div>
                    <div class="selected grid gap-4 sm:grid-cols-2"><div><h3>ผู้เล่นตัวจริง <span class="starter-count">0</span></h3><div class="starters space-y-2"></div></div><div><h3>ตัวสำรอง <span class="sub-count">0</span></h3><div class="subs space-y-2"></div></div></div>
                    <div><h3>ทีมงาน</h3><div class="staff space-y-2"></div></div>
                    <button type="submit" disabled class="submit-team rounded-lg bg-orange-500 px-5 py-3 font-bold disabled:cursor-not-allowed disabled:opacity-40">ยืนยันการสมัคร</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
</main></div>
<script>
document.querySelectorAll('.team-form').forEach((form) => {
    const category = form.querySelector('.category'), search = form.querySelector('.search'), results = form.querySelector('.search-results');
    const starters = form.querySelector('.starters'), subs = form.querySelector('.subs'), staff = form.querySelector('.staff');
    let selected = new Map(), config = { starters: 0, subs: 0, required: [] };
    const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const hidden = (name, value) => { const i=document.createElement('input'); i.type='hidden'; i.name=name; i.value=value; i.dataset.generated='1'; return i; };
    const render = () => {
        form.querySelectorAll('[data-generated]').forEach((e) => e.remove()); starters.innerHTML=''; subs.innerHTML=''; staff.innerHTML='';
        let si=0, ui=0, fi=0; selected.forEach((v, id) => { const index = v.role==='starter'?si++:v.role==='substitute'?ui++:fi++; const row=document.createElement('div'); row.className='rounded-lg bg-slate-900 p-3 flex justify-between items-center gap-2'; row.innerHTML=`<span>${esc(v.name)}</span><span><select class="change-role rounded bg-slate-800 p-1" data-id="${id}"><option value="starter" ${v.role==='starter'?'selected':''}>ตัวจริง</option><option value="substitute" ${v.role==='substitute'?'selected':''}>สำรอง</option><option value="coach" ${v.role==='coach'?'selected':''}>โค้ช</option><option value="manager" ${v.role==='manager'?'selected':''}>ผู้จัดการ</option></select> <button type="button" class="remove text-red-300" data-id="${id}">นำออก</button></span>`; (v.role==='starter'?starters:v.role==='substitute'?subs:staff).appendChild(row); form.appendChild(hidden(`roster[${v.index}][player_id]`, id)); form.appendChild(hidden(`roster[${v.index}][roles][]`, v.role==='starter'?'player':v.role==='substitute'?'substitute':v.role)); });
        form.querySelector('.starter-count').textContent=`${si}/${config.starters}`; form.querySelector('.sub-count').textContent=`${ui}/${config.subs}`;
        const selectedRoles=[...selected.values()].map(v=>v.role); const requiredOk=config.required.every(role=>selectedRoles.indexOf(role==='player'?'starter':role==='substitute'?'substitute':role)>=0); form.querySelector('.submit-team').disabled=si!==config.starters||ui!==config.subs||!requiredOk;
    };
    category.addEventListener('change', () => { const o=category.selectedOptions[0]; config={ starters:Number(o.dataset.starters||0),subs:Number(o.dataset.subs||0),required:(o.dataset.required||'').split(',').map(role=>role.trim()).filter(Boolean)}; search.disabled=!category.value; form.querySelector('.category-info').textContent=`ผู้เล่นตัวจริง ${config.starters} คน · ตัวสำรอง ${config.subs} คน · โค้ช/ผู้จัดการทีมเป็นตัวเลือกเสริม`; selected.clear(); render(); });
    search.addEventListener('input', async () => { if(search.value.trim().length<1)return results.innerHTML=''; const p=new URLSearchParams({action:'search_players',tournament_id:form.dataset.tournament,category_id:category.value,q:search.value.trim()}); const r=await fetch(`register-tournament.php?${p}`); const players=await r.json(); results.innerHTML=players.length?players.map(x=>{const disabled=x.eligible?'':'disabled title="'+esc(x.eligibility_reason||'ผู้เล่นคนนี้ไม่ผ่านเงื่อนไขของรายการ')+'"';return `<div class="flex justify-between rounded-lg border border-white/10 p-3"><span>${esc(x.real_name||x.username)} (#${x.player_id}) ${x.age_at_tournament===null?'':'อายุ '+x.age_at_tournament+' ปี'}<small class="ml-2 text-red-300">${x.eligible?'':'ผู้เล่นคนนี้ไม่ผ่านเงื่อนไขของรายการ'}</small></span><span><button ${disabled} type="button" data-role="starter" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add mr-2 text-green-300">ตัวจริง</button><button ${disabled} type="button" data-role="substitute" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add mr-2 text-cyan-300">สำรอง</button><button ${disabled} type="button" data-role="coach" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add mr-2 text-purple-300">โค้ช</button><button ${disabled} type="button" data-role="manager" data-id="${x.player_id}" data-name="${esc(x.real_name||x.username)}" class="add text-purple-300">ผู้จัดการ</button></span></div>`}).join(''):'<div class="rounded-lg border border-white/10 p-3 text-sm text-slate-400">ยังไม่พบนักกีฬาที่ตรงกับคำค้น</div>'; });
    form.addEventListener('change',(e)=>{const select=e.target.closest('.change-role');if(!select)return;const item=selected.get(Number(select.dataset.id));if(item){const role=select.value;if(role==='starter'&&[...selected.values()].filter(v=>v.role==='starter').length>=config.starters)return render();if(role==='substitute'&&[...selected.values()].filter(v=>v.role==='substitute').length>=config.subs)return render();item.role=role;render();}});
    form.addEventListener('click',(e)=>{const b=e.target.closest('.add,.remove');if(!b)return;if(b.classList.contains('remove'))selected.delete(Number(b.dataset.id));else{const id=Number(b.dataset.id),role=b.dataset.role;if(selected.has(id))return;if(role==='starter'&&[...selected.values()].filter(v=>v.role==='starter').length>=config.starters)return;if(role==='substitute'&&[...selected.values()].filter(v=>v.role==='substitute').length>=config.subs)return;selected.set(id,{role,name:b.dataset.name,index:selected.size});}render();});
    if (category.value) category.dispatchEvent(new Event('change'));
});
</script>
<script src="../assets/js/mobile-nav.js" defer></script>
<script src="../assets/js/flash-messages.js" defer></script>
</body>
</html>
