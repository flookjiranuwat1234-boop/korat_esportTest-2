<?php
// admin/recommended-lodging.php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireRole('admin');

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$error = '';
$success = '';
$search = trim((string) ($_GET['q'] ?? ''));
$tournamentFilter = (int) ($_GET['tournament_id'] ?? 0);
$sort = $_GET['sort'] ?? 'latest';

function isSafeMapsUrl($url) {
    $parts = parse_url(trim($url));
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return false;
    $host = strtolower($parts['host'] ?? '');
    return in_array($host, ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl'], true)
        && !empty($parts['path']);
}

// ฟังก์ชันช่วยอัปโหลดรูปภาพที่พัก
function uploadAccommodationImage($file) {
    if (isset($file) && $file['error'] == UPLOAD_ERR_OK) {
        if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('ไฟล์รูปต้องมีขนาดไม่เกิน 5MB');
        $mimeType = mime_content_type($file['tmp_name']);
        $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (isset($allowedTypes[$mimeType]) && in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $fileName = 'hotel_' . bin2hex(random_bytes(8)) . '.' . $allowedTypes[$mimeType];
            $uploadDir = '../assets/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $destination = $uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                return 'uploads/' . $fileName;
            }
        }
    }
    if (isset($file) && $file['error'] !== UPLOAD_ERR_NO_FILE) throw new RuntimeException('อัปโหลดรูปภาพไม่สำเร็จหรือชนิดไฟล์ไม่ถูกต้อง');
    return null;
}

// ==========================================
// 1. บันทึกข้อมูลที่พักแนะนำ (พร้อมรูปภาพและระยะทาง)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_lodging') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $accommodationId = (int) ($_POST['accommodation_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $distance = trim($_POST['distance'] ?? '');
        $linkUrl = trim($_POST['link_url'] ?? '');
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        
        if (empty($name) || mb_strlen($name) > 150) {
            $error = 'กรุณากรอกชื่อที่พัก';
        } elseif ($tournamentId <= 0) {
            $error = 'กรุณาเลือก Tournament ก่อนเพิ่มที่พัก';
        } elseif ($address === '' || mb_strlen($address) > 255) {
            $error = 'กรุณากรอกที่อยู่ที่พัก';
        } elseif ($distance === '' || !is_numeric($distance) || (float) $distance < 0) {
            $error = 'ระยะทางต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป';
        } elseif ($linkUrl === '' || mb_strlen($linkUrl) > 255 || !isSafeMapsUrl($linkUrl)) {
            $error = 'ลิงก์ต้องเป็น Google Maps URL ที่ปลอดภัย';
        } else {
            try {
                $tournamentStmt = $pdo->prepare("SELECT tournament_id FROM tournaments WHERE tournament_id = :id AND status <> 'cancelled' LIMIT 1");
                $tournamentStmt->execute(['id' => $tournamentId]);
                if (!$tournamentStmt->fetchColumn()) throw new RuntimeException('ไม่พบ Tournament ที่เลือก หรือ Tournament นี้ถูกยกเลิกแล้ว');
                $imagePath = uploadAccommodationImage($_FILES['hotel_image'] ?? null);
                $duplicate = $pdo->prepare('SELECT accommodation_id FROM accommodations WHERE tournament_id = :tournament_id AND LOWER(name) = LOWER(:name) AND accommodation_id <> :id LIMIT 1');
                $duplicate->execute(['tournament_id' => $tournamentId, 'name' => $name, 'id' => $accommodationId]);
                if ($duplicate->fetchColumn()) throw new RuntimeException('Tournament นี้มีที่พักชื่อดังกล่าวอยู่แล้ว');
                if ($accommodationId > 0) {
                    $sql = 'UPDATE accommodations SET tournament_id = :tournament_id, name = :name, address = :address, distance = :distance, link_url = :link_url';
                    $params = compact('tournamentId', 'name', 'address', 'distance', 'linkUrl', 'accommodationId');
                    if ($imagePath) { $sql .= ', image_path = :image_path'; $params['imagePath'] = $imagePath; }
                    $sql .= ' WHERE accommodation_id = :accommodationId';
                    $updateParams = ['tournament_id' => $tournamentId, 'name' => $name, 'address' => $address, 'distance' => $distance, 'link_url' => $linkUrl, 'accommodationId' => $accommodationId];
                    if ($imagePath) $updateParams['image_path'] = $imagePath;
                    $pdo->prepare($sql)->execute($updateParams);
                    $success = 'แก้ไขข้อมูลที่พักเรียบร้อยแล้ว';
                } else {
                    $pdo->prepare('INSERT INTO accommodations (tournament_id, name, address, image_path, distance, link_url) VALUES (:tournament_id, :name, :address, :image_path, :distance, :link_url)')->execute(['tournament_id' => $tournamentId, 'name' => $name, 'address' => $address, 'image_path' => $imagePath, 'distance' => $distance ?: null, 'link_url' => $linkUrl ?: null]);
                    $success = 'เพิ่มข้อมูลที่พักแนะนำเรียบร้อยแล้ว';
                }
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = 'เกิดข้อผิดพลาดในการบันทึก กรุณาลองใหม่อีกครั้ง';
            }
        }
    }
    setFlashMessage($error ? 'error' : 'success', $error ?: $success);
    header('Location: recommended-lodging.php', true, 303);
    exit;
}

// ลบข้อมูลที่พักด้วย POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_lodging') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    }
    $deleteId = (int) ($_POST['accommodation_id'] ?? 0);
    if (!$error && $deleteId > 0) {
    try {
        $stmtDel = $pdo->prepare("DELETE FROM accommodations WHERE accommodation_id = :id");
        $stmtDel->execute(['id' => $deleteId]);
        $success = 'ลบข้อมูลที่พักเรียบร้อยแล้ว';
    } catch (PDOException $e) {
        $error = 'เกิดข้อผิดพลาดในการลบข้อมูล: ' . $e->getMessage();
    }
    }
    setFlashMessage($error ? 'error' : 'success', $error ?: $success);
    header('Location: recommended-lodging.php', true, 303);
    exit;
}

// ดึงรายการที่พักทั้งหมดจากตาราง accommodations
$tournaments = $pdo->query("SELECT tournament_id, name, venue_address, venue_lat_lng, start_date, end_date, status FROM tournaments WHERE status <> 'cancelled' ORDER BY start_date DESC, tournament_id DESC")->fetchAll(PDO::FETCH_ASSOC);
$where = ['1=1']; $params = [];
if ($search !== '') { $where[] = '(a.name LIKE :search OR a.address LIKE :search)'; $params['search'] = '%' . $search . '%'; }
if ($tournamentFilter > 0) { $where[] = 'a.tournament_id = :tournament_id'; $params['tournament_id'] = $tournamentFilter; }
$orderBy = $sort === 'distance_asc' ? 'CAST(a.distance AS DECIMAL(10,2)) ASC, a.name ASC' : ($sort === 'distance_desc' ? 'CAST(a.distance AS DECIMAL(10,2)) DESC, a.name ASC' : ($sort === 'name_asc' ? 'a.name ASC' : 'a.accommodation_id DESC'));
$accommodationStmt = $pdo->prepare("SELECT a.*, t.name AS tournament_name, t.venue_address, t.venue_lat_lng FROM accommodations a LEFT JOIN tournaments t ON t.tournament_id = a.tournament_id WHERE " . implode(' AND ', $where) . " ORDER BY {$orderBy}");
$accommodationStmt->execute($params);
$accommodations = $accommodationStmt->fetchAll(PDO::FETCH_ASSOC);
$allAccommodationCount = (int) $pdo->query('SELECT COUNT(*) FROM accommodations')->fetchColumn();
$activeAccommodationCount = $allAccommodationCount;
$tournamentAccommodationCount = (int) $pdo->query('SELECT COUNT(DISTINCT tournament_id) FROM accommodations WHERE tournament_id IS NOT NULL')->fetchColumn();
$csrfToken = generateCsrfToken();
$flash = consumeFlashMessage();
if ($flash) {
    if ($flash['type'] === 'error') $error = $flash['message'];
    else $success = $flash['message'];
}
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการที่พักแนะนำ - Korat Esport</title>
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
        .lodging-action-item { min-height: 2.5rem; width: 100%; display: flex; align-items: center; padding: .625rem .75rem; border-radius: .5rem; text-align: left; font-size: .75rem; font-weight: 600; color: #334155; }
        .lodging-action-item:hover { background: #f8fafc; }
        #lodgingForm { display: flex; flex-direction: column; }
        #lodgingForm > div:nth-of-type(1) { order: 2; }
        #lodgingForm > div:nth-of-type(2) { order: 1; }
        #lodgingForm > div:nth-of-type(3) { order: 4; }
        #lodgingForm > div:nth-of-type(4) { order: 5; }
        #lodgingForm > div:nth-of-type(5) { order: 6; }
        #lodgingForm > div:nth-of-type(6) { order: 7; }
        #lodgingForm > div:last-child { order: 7; }
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
                <span>จัดการแกลเลอรี่</span>
            </a>
            <a href="recommended-lodging.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-hotel w-5 text-center text-brand-orange"></i>
                <span>ที่พักแนะนำ</span>
            </a>
            <a href="record-match.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-pen-to-square w-5 text-center"></i>
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
                            <?= htmlspecialchars($currentUser['username'] ?? 'Admin User') ?>
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

    <!-- ================= 2. MAIN CONTENT AREA ================= -->
    <div class="flex-1 ml-64 min-h-screen flex flex-col">

        <!-- Header Panel -->
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    จัดการที่พักแนะนำ <span class="text-brand-orange">(RECOMMENDED LODGING)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">เพิ่มและจัดการที่พักใกล้สถานที่แข่งขัน</p>
            </div>
            
            <a href="../pages/lodging.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> ดูหน้าเว็บที่พัก
            </a>
        </header>

        <main class="p-8 space-y-8 flex-1">

            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="flash-alert p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-center gap-3" role="alert">
                    <i class="fa-solid fa-triangle-exclamation text-lg shrink-0 text-rose-500"></i>
                    <span class="flex-1"><?php echo htmlspecialchars($error); ?></span>
                    <button type="button" class="flash-alert-close text-rose-500 hover:text-rose-700 text-lg" aria-label="ปิดการแจ้งเตือน">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="flash-alert p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-3" role="status" data-auto-hide="5000">
                    <i class="fa-solid fa-circle-check text-lg shrink-0 text-emerald-500"></i>
                    <span class="flex-1"><?php echo htmlspecialchars($success); ?></span>
                    <button type="button" class="flash-alert-close text-emerald-500 hover:text-emerald-700 text-lg" aria-label="ปิดการแจ้งเตือน">&times;</button>
                </div>
            <?php endif; ?>

            <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">ที่พักทั้งหมด</div><div class="mt-2 text-2xl font-black text-slate-900"><?= $allAccommodationCount ?></div></div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-emerald-700">กำลังแสดงบนเว็บไซต์</div><div class="mt-2 text-2xl font-black text-emerald-700"><?= $activeAccommodationCount ?></div><div class="mt-1 text-[10px] text-emerald-700">ระบบยังไม่มี Field status</div></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">Tournament ที่มีที่พัก</div><div class="mt-2 text-2xl font-black text-slate-900"><?= $tournamentAccommodationCount ?></div></div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <form method="GET" class="flex flex-col gap-3 xl:flex-row"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาชื่อที่พักหรือที่อยู่" class="flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none"><select name="tournament_id" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><option value="">ทุก Tournament</option><?php foreach ($tournaments as $tournament): ?><option value="<?= (int) $tournament['tournament_id'] ?>" <?= $tournamentFilter === (int) $tournament['tournament_id'] ? 'selected' : '' ?>><?= htmlspecialchars($tournament['name']) ?></option><?php endforeach; ?></select><select name="sort" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>ล่าสุด</option><option value="distance_asc" <?= $sort === 'distance_asc' ? 'selected' : '' ?>>ใกล้ที่สุด</option><option value="distance_desc" <?= $sort === 'distance_desc' ? 'selected' : '' ?>>ไกลที่สุด</option></select><button type="submit" class="rounded-xl bg-brand-orange px-5 py-2.5 text-sm font-bold text-white hover:bg-brand-glow">ค้นหา</button><a href="recommended-lodging.php" class="rounded-xl bg-slate-100 px-5 py-2.5 text-center text-sm font-bold text-slate-600 hover:bg-slate-200">ล้างตัวกรอง</a></form>
            </section>

            <div class="flex items-center justify-between gap-3"><div><h2 class="text-base font-bold font-display text-slate-900"><i class="fa-solid fa-hotel mr-2 text-brand-orange"></i>รายการที่พักแนะนำ</h2><p class="mt-1 text-xs text-slate-500">ระยะทางอ้างอิงจากสถานที่แข่งขันของ Tournament ที่เลือก</p></div><button type="button" onclick="openLodgingForm()" class="inline-flex items-center gap-2 rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-plus"></i>เพิ่มที่พักแนะนำ</button></div>

            <?php if (!$accommodations): ?><div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500"><?= ($search !== '' || $tournamentFilter > 0) ? 'ไม่พบที่พักตามเงื่อนไขที่ค้นหา' : 'ยังไม่มีข้อมูลที่พักแนะนำ' ?><div class="mt-4"><button type="button" onclick="openLodgingForm()" class="rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white">+ เพิ่มที่พักแนะนำ</button></div></div><?php else: ?>
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($accommodations as $item): ?><article class="relative rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="h-44 overflow-hidden rounded-xl bg-slate-100"><?php if (!empty($item['image_path'])): ?><img src="../assets/<?= htmlspecialchars($item['image_path']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="h-full w-full object-cover"><?php else: ?><div class="flex h-full items-center justify-center text-4xl text-slate-300"><i class="fa-solid fa-hotel"></i></div><?php endif; ?></div><h3 class="mt-3 truncate font-bold text-slate-900"><?= htmlspecialchars($item['name']) ?></h3><p class="mt-1 line-clamp-2 text-xs text-slate-500"><?= htmlspecialchars($item['address'] ?: 'ไม่มีที่อยู่') ?></p><div class="mt-3 rounded-xl bg-orange-50 p-3 text-xs"><div class="font-bold text-brand-orange"><?= htmlspecialchars($item['tournament_name'] ?: 'ไม่ระบุ Tournament') ?></div><div class="mt-1 text-slate-600">สนาม: <?= htmlspecialchars($item['venue_address'] ?: 'ไม่มีข้อมูลสถานที่') ?></div><div class="mt-1 font-bold text-slate-700">ระยะทาง: <?= htmlspecialchars($item['distance'] ?: 'ไม่มีข้อมูล') ?> กม.</div></div><div class="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-3"><?php if (!empty($item['link_url'])): ?><a href="<?= htmlspecialchars($item['link_url']) ?>" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700">เปิดแผนที่</a><?php endif; ?><button type="button" onclick="openLodgingDetail(<?= (int) $item['accommodation_id'] ?>)" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">รายละเอียด</button><button type="button" onclick="openLodgingActionMenu(this)" class="lodging-action-toggle rounded-lg bg-brand-orange px-3 py-2 text-xs font-bold text-white" aria-expanded="false">จัดการ</button></div><div class="lodging-action-menu fixed z-[70] hidden w-52 rounded-xl border border-slate-200 bg-white p-2 shadow-xl"><button type="button" onclick="openLodgingForm(<?= (int) $item['accommodation_id'] ?>)" class="lodging-action-item">แก้ไขข้อมูล</button><?php if (!empty($item['link_url'])): ?><a href="<?= htmlspecialchars($item['link_url']) ?>" target="_blank" rel="noopener noreferrer" class="lodging-action-item">เปิด Google Maps</a><?php endif; ?><div class="my-1 border-t border-slate-100"></div><form method="POST" onsubmit="return confirm('ยืนยันลบที่พักนี้หรือไม่?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="delete_lodging"><input type="hidden" name="accommodation_id" value="<?= (int) $item['accommodation_id'] ?>"><button type="submit" class="lodging-action-item text-rose-600">ลบที่พัก</button></form></div></article><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (false): ?>

            <!-- FORM: เพิ่มที่พักแนะนำใหม่ -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 space-y-6">
                <div class="border-b border-slate-100 pb-4">
                    <h2 class="text-lg font-bold font-display text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-hotel text-brand-orange"></i>
                        เพิ่มข้อมูลที่พักแนะนำใหม่
                    </h2>
                    <p class="text-xs text-slate-500 mt-1">กรอกชื่อ รูปภาพ ระยะทางห่างจากสนามแข่ง และลิงก์แผนที่</p>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="save_lodging">

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <!-- name -->
                        <div class="lg:col-span-2">
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ชื่อโรงแรม / ที่พัก *</label>
                            <input type="text" name="name" required placeholder="เช่น โรงแรมสีมาธานี (Sima Thani Hotel)"
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium">
                        </div>

                        <!-- distance -->
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ระยะทางจากสนามแข่ง</label>
                            <input type="text" name="distance" placeholder="เช่น 1.5 กม. หรือ 5 นาทีจากสนาม"
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium">
                        </div>

                        <!-- hotel_image -->
                        <div class="lg:col-span-3">
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปภาพโรงแรม / ที่พัก</label>
                            <input type="file" name="hotel_image" accept="image/*"
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-sm text-slate-700 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-brand-orange file:text-white hover:file:bg-brand-glow transition-all">
                        </div>

                        <!-- link_url -->
                        <div class="lg:col-span-1">
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ลิงก์ Google Maps</label>
                            <input type="url" name="link_url" placeholder="https://www.google.com/maps/search/..."
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium">
                        </div>

                        <!-- address -->
                        <div class="lg:col-span-2">
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ที่อยู่ / ทำเลที่ตั้ง</label>
                            <input type="text" name="address" placeholder="เช่น 2112/2 ถ.มิตรภาพ ต.ในเมือง อ.เมือง จ.นครราชสีมา"
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium">
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" 
                            class="px-6 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs uppercase tracking-wider transition-all shadow-md flex items-center gap-2 cursor-pointer">
                            <i class="fa-solid fa-plus"></i>
                            <span>บันทึกข้อมูลที่พัก</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- TABLE: รายการที่พักทั้งหมด -->
            <div class="space-y-4">
                <h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-list text-brand-orange"></i>
                    รายชื่อที่พักแนะนำทั้งหมด <span class="text-xs px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 font-sans border border-slate-200"><?php echo count($accommodations); ?> แห่ง</span>
                </h2>

                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-600">
                            <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                <tr>
                                    <th class="p-4 text-center">รูปภาพ</th>
                                    <th class="p-4">ชื่อโรงแรม / ที่พัก</th>
                                    <th class="p-4">ระยะทาง</th>
                                    <th class="p-4">ที่อยู่</th>
                                    <th class="p-4 text-center">แผนที่</th>
                                    <th class="p-4 text-right">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (count($accommodations) == 0): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-slate-400">
                                            <i class="fa-solid fa-hotel text-3xl mb-2 block opacity-40"></i>
                                            ยังไม่มีข้อมูลที่พักในขณะนี้
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($accommodations as $item): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <td class="p-4 text-center">
                                        <?php if (!empty($item['image_path'])): ?>
                                            <img src="../assets/<?php echo htmlspecialchars($item['image_path']); ?>" alt="Hotel" class="w-14 h-10 object-cover rounded-lg border border-slate-200 shadow-sm mx-auto">
                                        <?php else: ?>
                                            <div class="w-14 h-10 rounded-lg bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-400 text-xs mx-auto">
                                                <i class="fa-solid fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 font-bold text-slate-900">
                                        <?php echo htmlspecialchars($item['name']); ?>
                                    </td>
                                    <td class="p-4 text-xs font-semibold text-brand-orange">
                                        <?php echo !empty($item['distance']) ? htmlspecialchars($item['distance']) : '-'; ?>
                                    </td>
                                    <td class="p-4 text-xs text-slate-500 max-w-xs truncate">
                                        <?php echo htmlspecialchars($item['address'] ?? '-'); ?>
                                    </td>
                                    <td class="p-4 text-center">
                                        <?php if (!empty($item['link_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($item['link_url']); ?>" target="_blank" 
                                                class="inline-flex items-center gap-1 px-3 py-1 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 text-xs font-semibold transition-all">
                                                <i class="fa-solid fa-map-location-dot"></i> ดูแผนที่
                                            </a>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-300">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 text-right">
                                        <a href="?delete=<?php echo $item['accommodation_id']; ?>" 
                                            onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบที่พักนี้?')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-600 border border-rose-200 text-xs font-semibold transition-all">
                                            <i class="fa-solid fa-trash"></i> ลบ
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <?php endif; ?>
        </main>
    </div>

    <div id="lodgingFormModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 id="lodgingFormTitle" class="font-bold text-slate-900">เพิ่มที่พักแนะนำ</h3><button type="button" onclick="closeLodgingForm()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><form id="lodgingForm" method="POST" enctype="multipart/form-data" class="space-y-4 p-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="save_lodging"><input type="hidden" name="accommodation_id" id="lodgingId"><div><label class="mb-1 block text-xs font-bold text-slate-700">ชื่อโรงแรม/ที่พัก</label><input name="name" id="lodgingName" required maxlength="150" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"></div><div><label class="mb-1 block text-xs font-bold text-slate-700">Tournament และสถานที่แข่งขัน</label><select name="tournament_id" id="lodgingTournament" required onchange="showLodgingVenue(this)" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><option value="">เลือก Tournament</option><?php foreach ($tournaments as $tournament): ?><option value="<?= (int) $tournament['tournament_id'] ?>" data-venue="<?= htmlspecialchars($tournament['venue_address'] ?: 'ไม่มีข้อมูลสถานที่แข่งขัน', ENT_QUOTES) ?>"><?= htmlspecialchars($tournament['name']) ?></option><?php endforeach; ?></select><div id="lodgingVenue" class="mt-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">เลือก Tournament เพื่อดูสถานที่แข่งขัน</div></div><div class="grid grid-cols-1 gap-4 sm:grid-cols-2"><div><label class="mb-1 block text-xs font-bold text-slate-700">ระยะทาง (กม.)</label><input type="number" min="0" step="0.1" name="distance" id="lodgingDistance" placeholder="เช่น 1.5" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><p class="mt-1 text-[11px] text-slate-400">เช่น 1.5 กม. จากสถานที่แข่งขัน</p></div><div><label class="mb-1 block text-xs font-bold text-slate-700">ลิงก์ Google Maps</label><input type="url" name="link_url" id="lodgingLink" placeholder="https://www.google.com/maps/..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><button type="button" onclick="testLodgingMap()" class="mt-2 text-xs font-bold text-blue-700 hover:underline">ทดสอบลิงก์แผนที่</button></div></div><div><label class="mb-1 block text-xs font-bold text-slate-700">ที่อยู่/ทำเลที่ตั้ง</label><textarea name="address" id="lodgingAddress" required rows="3" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"></textarea></div><div><label class="mb-1 block text-xs font-bold text-slate-700">รูปภาพ <span class="font-normal text-slate-400">JPG, PNG, WEBP ไม่เกิน 5MB</span></label><input type="file" name="hotel_image" id="lodgingImage" accept="image/jpeg,image/png,image/webp" onchange="previewLodgingImage(this)" class="w-full text-xs"><div id="lodgingImageName" class="mt-2 text-xs text-slate-500"></div><div id="lodgingImagePreview" class="mt-2"></div></div><div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onclick="closeLodgingForm()" class="rounded-xl bg-slate-100 px-4 py-2.5 text-xs font-bold text-slate-700">ยกเลิก</button><button type="submit" id="lodgingSubmit" class="rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white hover:bg-brand-glow">บันทึกที่พัก</button></div></form></div></div>

    <div id="lodgingDetailModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 id="lodgingDetailTitle" class="font-bold text-slate-900">รายละเอียดที่พัก</h3><button type="button" onclick="closeLodgingDetail()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><div class="p-6"><img id="lodgingDetailImage" class="mb-4 hidden max-h-72 w-full rounded-xl object-cover"><div id="lodgingDetailContent" class="space-y-3 text-sm"></div></div><div class="flex justify-end border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" onclick="closeLodgingDetail()" class="rounded-lg bg-slate-200 px-4 py-2 text-xs font-bold">ปิด</button></div></div></div>

    <script>
        const lodgingData = <?= json_encode(array_column($accommodations, null, 'accommodation_id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const tournamentData = <?= json_encode(array_column($tournaments, null, 'tournament_id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let lodgingDirty = false;
        function showLodgingModal(id) { const element = document.getElementById(id); element.classList.remove('hidden'); element.classList.add('flex'); }
        function hideLodgingModal(id) { const element = document.getElementById(id); element.classList.add('hidden'); element.classList.remove('flex'); }
        function openLodgingForm(id = 0) { const item = lodgingData[id] || {}; document.getElementById('lodgingFormTitle').textContent = id ? 'แก้ไขที่พักแนะนำ' : 'เพิ่มที่พักแนะนำ'; document.getElementById('lodgingId').value = id || ''; document.getElementById('lodgingName').value = item.name || ''; document.getElementById('lodgingAddress').value = item.address || ''; document.getElementById('lodgingDistance').value = item.distance || ''; document.getElementById('lodgingLink').value = item.link_url || ''; document.getElementById('lodgingTournament').value = item.tournament_id || ''; document.getElementById('lodgingImage').value = ''; document.getElementById('lodgingImageName').textContent = item.image_path ? 'รูปเดิม: ' + item.image_path : ''; document.getElementById('lodgingImagePreview').innerHTML = item.image_path ? `<img src="../assets/${item.image_path}" class="max-h-36 rounded-lg object-cover">` : ''; showLodgingVenue(document.getElementById('lodgingTournament')); lodgingDirty = false; showLodgingModal('lodgingFormModal'); }
        function closeLodgingForm() { if (lodgingDirty && !window.confirm('มีข้อมูลที่แก้ไขแล้วยังไม่ได้บันทึก ต้องการปิดหน้าต่างหรือไม่?')) return; hideLodgingModal('lodgingFormModal'); lodgingDirty = false; }
        function showLodgingVenue(select) { const option = select.options[select.selectedIndex]; document.getElementById('lodgingVenue').textContent = option?.dataset.venue ? 'สถานที่แข่งขัน: ' + option.dataset.venue : 'เลือก Tournament เพื่อดูสถานที่แข่งขัน'; }
        function previewLodgingImage(input) { const file = input.files?.[0]; document.getElementById('lodgingImageName').textContent = file ? file.name + ' (' + Math.round(file.size / 1024) + ' KB)' : ''; if (!file) return; if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) { input.value = ''; document.getElementById('lodgingImagePreview').innerHTML = '<p class="text-xs text-rose-600">กรุณาเลือก JPG, PNG หรือ WEBP ขนาดไม่เกิน 5MB</p>'; return; } const reader = new FileReader(); reader.onload = event => { document.getElementById('lodgingImagePreview').innerHTML = `<img src="${event.target.result}" class="max-h-36 rounded-lg object-cover">`; }; reader.readAsDataURL(file); }
        function testLodgingMap() { const value = document.getElementById('lodgingLink').value.trim(); if (!/^https:\/\/(www\.)?google\.com\/maps\//i.test(value) && !/^https:\/\/maps\.app\.goo\.gl\//i.test(value)) { alert('กรุณาระบุ Google Maps URL ที่ขึ้นต้นด้วย https://'); return; } window.open(value, '_blank', 'noopener,noreferrer'); }
        function openLodgingDetail(id) { const item = lodgingData[id]; if (!item) return; document.getElementById('lodgingDetailTitle').textContent = item.name; const image = document.getElementById('lodgingDetailImage'); if (item.image_path) { image.src = '../assets/' + item.image_path; image.classList.remove('hidden'); } else image.classList.add('hidden'); document.getElementById('lodgingDetailContent').innerHTML = `<div><b>Tournament</b><div>${item.tournament_name || 'ไม่มีข้อมูล'}</div></div><div><b>สถานที่แข่งขัน</b><div>${item.venue_address || 'ไม่มีข้อมูล'}</div></div><div><b>ระยะทาง</b><div>${item.distance || 'ไม่มีข้อมูล'} กม.</div></div><div><b>ที่อยู่</b><div>${item.address || 'ไม่มีข้อมูล'}</div></div>${item.link_url ? `<a href="${item.link_url}" target="_blank" rel="noopener noreferrer" class="inline-flex rounded-lg bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700">เปิด Google Maps</a>` : ''}`; showLodgingModal('lodgingDetailModal'); }
        function closeLodgingDetail() { hideLodgingModal('lodgingDetailModal'); }
        function openLodgingActionMenu(button) { const menu = button.parentElement.parentElement.querySelector('.lodging-action-menu'); if (!menu) return; document.querySelectorAll('.lodging-action-menu').forEach(item => item.classList.add('hidden')); const opening = menu.classList.contains('hidden'); if (!opening) return; document.body.appendChild(menu); menu.classList.remove('hidden'); const rect = button.getBoundingClientRect(); const width = menu.offsetWidth || 208; const height = menu.offsetHeight || 180; menu.style.left = `${Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8))}px`; menu.style.top = `${rect.bottom + height + 8 <= window.innerHeight - 8 ? rect.bottom + 8 : Math.max(8, rect.top - height - 8)}px`; button.setAttribute('aria-expanded', 'true'); }
        document.addEventListener('DOMContentLoaded', () => { document.getElementById('lodgingForm')?.querySelectorAll('input, textarea, select').forEach(field => field.addEventListener('input', () => { lodgingDirty = true; })); document.querySelectorAll('.lodging-action-menu').forEach(menu => menu.addEventListener('click', event => event.stopPropagation())); document.addEventListener('click', () => document.querySelectorAll('.lodging-action-menu').forEach(menu => menu.classList.add('hidden'))); document.querySelectorAll('[id$="Modal"]').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) hideLodgingModal(modal.id); })); document.addEventListener('keydown', event => { if (event.key === 'Escape') { document.querySelectorAll('.lodging-action-menu').forEach(menu => menu.classList.add('hidden')); closeLodgingForm(); closeLodgingDetail(); } }); document.getElementById('lodgingForm')?.addEventListener('submit', () => { const button = document.getElementById('lodgingSubmit'); button.disabled = true; button.textContent = 'กำลังบันทึก...'; }); });
    </script>
    <script>
        function getTournamentMapUrl(tournament) {
            const venueValue = String(tournament?.venue_lat_lng || '').trim();
            const coordinateMatch = venueValue.match(/@(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)(?:[,\/]|$)/);
            const coordinates = coordinateMatch ? [coordinateMatch[1], coordinateMatch[2]] : venueValue.split(',').map(value => value.trim());
            if (coordinates.length === 2 && coordinates.every(value => value !== '' && Number.isFinite(Number(value)))) {
                const latitude = Number(coordinates[0]);
                const longitude = Number(coordinates[1]);
                if (latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180) {
                    return `https://www.google.com/maps/search/${encodeURIComponent('โรงแรม')}/@${latitude},${longitude},15z`;
                }
            }
            const venueAddress = String(tournament?.venue_address || '').trim();
            return venueAddress ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent('โรงแรมใกล้ ' + venueAddress) : '';
        }
        function updateLodgingTournamentCard() {
            const select = document.getElementById('lodgingTournament');
            const venue = document.getElementById('lodgingVenue');
            const submit = document.getElementById('lodgingSubmit');
            const tournament = tournamentData[select?.value] || null;
            if (!venue || !select) return;
            venue.innerHTML = '';
            if (!tournament) {
                venue.textContent = 'กรุณาเลือก Tournament ก่อนเพิ่มที่พัก';
                if (submit) submit.disabled = true;
                return;
            }
            const details = document.createElement('div');
            details.textContent = 'Tournament ที่เลือก: ' + tournament.name + ' | สถานที่แข่งขัน: ' + (tournament.venue_address || 'ยังไม่ได้ระบุสถานที่แข่งขัน') + ' | วันที่แข่งขัน: ' + (tournament.start_date && tournament.end_date ? tournament.start_date + ' - ' + tournament.end_date : 'ยังไม่ได้กำหนดวันแข่งขัน') + ' | สถานะ: ' + (tournament.status || 'ไม่ทราบสถานะ');
            venue.appendChild(details);
            const mapUrl = getTournamentMapUrl(tournament);
            const coordinateParts = String(tournament.venue_lat_lng || '').split(',').map(value => value.trim());
            const coordinatesValid = coordinateParts.length === 2 && coordinateParts.every(value => value !== '' && Number.isFinite(Number(value))) && Number(coordinateParts[0]) >= -90 && Number(coordinateParts[0]) <= 90 && Number(coordinateParts[1]) >= -180 && Number(coordinateParts[1]) <= 180;
            const coordinates = coordinatesValid ? coordinateParts.join(', ') : '';
            const venueAddress = String(tournament.venue_address || '').trim();
            const locationInfo = document.createElement('p');
            locationInfo.className = 'mt-2 text-slate-600';
            locationInfo.textContent = coordinates ? 'พิกัด: ' + coordinates + ' | การค้นหา: ค้นหาโรงแรมรอบสนามในระยะพื้นที่ใกล้เคียง' : (venueAddress ? 'กำลังค้นหาจากที่อยู่ เนื่องจากยังไม่มีพิกัดสนาม' : '');
            venue.appendChild(locationInfo);
            if (mapUrl) {
                const mapButton = document.createElement('button');
                mapButton.type = 'button';
                mapButton.className = 'mt-3 inline-flex rounded-lg bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700';
                mapButton.textContent = 'ค้นหาที่พักใกล้สนาม';
                mapButton.addEventListener('click', () => window.open(mapUrl, '_blank', 'noopener,noreferrer'));
                venue.appendChild(mapButton);
            } else {
                const notice = document.createElement('p');
                notice.className = 'mt-2 text-rose-600';
                notice.textContent = 'Tournament นี้ยังไม่มีข้อมูลสถานที่แข่งขัน';
                venue.appendChild(notice);
            }
            if (submit) submit.disabled = false;
        }
        document.addEventListener('DOMContentLoaded', () => {
            const select = document.getElementById('lodgingTournament');
            if (select) {
                select.addEventListener('change', updateLodgingTournamentCard);
                updateLodgingTournamentCard();
            }
        });
        function showLodgingVenue(select) {
            updateLodgingTournamentCard();
        }
    </script>
    <script>
        function setLodgingFieldsEnabled(enabled) {
            ['lodgingName', 'lodgingDistance', 'lodgingLink', 'lodgingAddress', 'lodgingImage'].forEach(id => {
                const field = document.getElementById(id);
                if (field) field.disabled = !enabled;
            });
            const submit = document.getElementById('lodgingSubmit');
            if (submit) submit.disabled = !enabled || !lodgingRequiredFieldsComplete();
        }

        function lodgingRequiredFieldsComplete() {
            return ['lodgingName', 'lodgingAddress', 'lodgingDistance', 'lodgingLink'].every(id => document.getElementById(id)?.value.trim());
        }

        document.addEventListener('DOMContentLoaded', () => {
            const select = document.getElementById('lodgingTournament');
            if (!select) return;
            let previousTournament = select.value;
            setLodgingFieldsEnabled(Boolean(select.value));
            select.addEventListener('change', event => {
                const hasEnteredData = ['lodgingName', 'lodgingAddress', 'lodgingDistance', 'lodgingLink'].some(id => document.getElementById(id)?.value.trim());
                if (previousTournament && previousTournament !== select.value && hasEnteredData && !window.confirm('การเปลี่ยน Tournament อาจทำให้ข้อมูลที่พักอ้างอิงเปลี่ยน ต้องการดำเนินการต่อหรือไม่?')) {
                    select.value = previousTournament;
                    return;
                }
                previousTournament = select.value;
                setLodgingFieldsEnabled(Boolean(select.value));
                if (select.value) document.getElementById('lodgingName')?.focus();
            });
            ['lodgingName', 'lodgingAddress', 'lodgingDistance', 'lodgingLink'].forEach(id => document.getElementById(id)?.addEventListener('input', () => {
                const submit = document.getElementById('lodgingSubmit');
                if (submit) submit.disabled = !select.value || !lodgingRequiredFieldsComplete();
            }));
        });

        const originalOpenLodgingForm = openLodgingForm;
        openLodgingForm = function (id = 0) {
            originalOpenLodgingForm(id);
            const select = document.getElementById('lodgingTournament');
            setLodgingFieldsEnabled(Boolean(select?.value));
            if (select?.value) document.getElementById('lodgingName')?.focus();
        };
    </script>
    <script>
        let activeLodgingMenu = null;
        let activeLodgingButton = null;

        function closeLodgingActionMenus(restoreFocus = false) {
            document.querySelectorAll('.lodging-action-menu').forEach(menu => {
                menu.classList.add('hidden');
                menu.setAttribute('aria-hidden', 'true');
            });
            if (activeLodgingButton) activeLodgingButton.setAttribute('aria-expanded', 'false');
            const button = activeLodgingButton;
            activeLodgingMenu = null;
            activeLodgingButton = null;
            if (restoreFocus) button?.focus();
        }

        function openLodgingActionMenu(button) {
            const menu = button.parentElement.parentElement.querySelector('.lodging-action-menu');
            if (!menu) return;
            const isOpen = activeLodgingMenu === menu && !menu.classList.contains('hidden');
            closeLodgingActionMenus();
            if (isOpen) return;
            activeLodgingMenu = menu;
            activeLodgingButton = button;
            menu.classList.remove('hidden');
            menu.setAttribute('aria-hidden', 'false');
            button.setAttribute('aria-expanded', 'true');
            const rect = button.getBoundingClientRect();
            const width = menu.offsetWidth || 208;
            const height = menu.offsetHeight || 180;
            menu.style.left = `${Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8))}px`;
            menu.style.top = `${rect.bottom + height + 8 <= window.innerHeight - 8 ? rect.bottom + 8 : Math.max(8, rect.top - height - 8)}px`;
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.lodging-action-toggle').forEach((button, index) => {
                const menu = button.parentElement.parentElement.querySelector('.lodging-action-menu');
                if (!menu) return;
                const menuId = `lodging-action-menu-${index + 1}`;
                menu.id = menuId;
                menu.setAttribute('role', 'menu');
                menu.setAttribute('aria-hidden', 'true');
                button.setAttribute('aria-haspopup', 'menu');
                button.setAttribute('aria-controls', menuId);
                button.addEventListener('click', event => event.stopPropagation());
                button.addEventListener('keydown', event => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeLodgingActionMenus(true);
                    }
                });
                menu.querySelectorAll('button, a').forEach(item => {
                    item.setAttribute('role', 'menuitem');
                    item.addEventListener('click', () => closeLodgingActionMenus());
                });
                menu.addEventListener('keydown', event => {
                    const items = [...menu.querySelectorAll('[role="menuitem"]')];
                    const currentIndex = items.indexOf(document.activeElement);
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeLodgingActionMenus(true);
                    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        const nextIndex = event.key === 'ArrowDown' ? (currentIndex + 1) % items.length : (currentIndex - 1 + items.length) % items.length;
                        items[nextIndex]?.focus();
                    }
                });
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.flash-alert').forEach(alert => {
                const close = () => {
                    alert.style.opacity = '0';
                    window.setTimeout(() => alert.remove(), 250);
                };
                alert.querySelector('.flash-alert-close')?.addEventListener('click', close);
                const duration = Number(alert.dataset.autoHide || 0);
                if (duration > 0) window.setTimeout(close, duration);
            });
        });
    </script>
</body>
</html>