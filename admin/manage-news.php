<?php
// admin/manage-news.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';
requireRole('admin');

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$error = '';
$success = '';
$editingNews = null;
$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$allowedStatuses = ['draft', 'published'];

// เพิ่ม/แก้ไขข่าว
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'save') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $newsId = (int) ($_POST['news_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'draft');

        if ($title === '' || mb_strlen($title) > 200 || $content === '') {
            $error = $title === '' || mb_strlen($title) > 200
                ? 'กรุณากรอกหัวข้อข่าวไม่เกิน 200 ตัวอักษร'
                : 'กรุณากรอกเนื้อหาข่าว';
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $error = 'สถานะข่าวไม่ถูกต้อง';
        } else {
            try {
                $imagePath = handleImageUpload($_FILES['image'] ?? null, 'news');

                if ($newsId > 0) {
                    // แก้ไขข่าวเดิม — ถ้าอัปโหลดรูปใหม่มาด้วย ลบรูปเก่าทิ้งก่อน
                    if ($imagePath) {
                        $old = $pdo->prepare("SELECT image_path FROM news WHERE news_id = :id");
                        $old->execute(['id' => $newsId]);
                        deleteUploadedImage($old->fetchColumn());

                        $update = $pdo->prepare("
                            UPDATE news SET title = :title, content = :content, status = :status, image_path = :image
                            WHERE news_id = :id
                        ");
                        $update->execute(['title' => $title, 'content' => $content, 'status' => $status, 'image' => $imagePath, 'id' => $newsId]);
                    } else {
                        $update = $pdo->prepare("
                            UPDATE news SET title = :title, content = :content, status = :status WHERE news_id = :id
                        ");
                        $update->execute(['title' => $title, 'content' => $content, 'status' => $status, 'id' => $newsId]);
                    }
                    $success = 'แก้ไขข่าวเรียบร้อยแล้ว';
                } else {
                    $insert = $pdo->prepare("
                        INSERT INTO news (title, content, image_path, status, created_by)
                        VALUES (:title, :content, :image, :status, :created_by)
                    ");
                    $insert->execute([
                        'title' => $title, 'content' => $content, 'image' => $imagePath,
                        'status' => $status, 'created_by' => $_SESSION['user_id'],
                    ]);
                    $success = 'เพิ่มข่าวใหม่เรียบร้อยแล้ว';
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// เปลี่ยนสถานะ/ลบข่าว ต้องใช้ POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['change_status', 'delete'], true)) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $newsId = (int) ($_POST['news_id'] ?? 0);
        $action = $_POST['action'];
        $newsStmt = $pdo->prepare('SELECT title, image_path, status FROM news WHERE news_id = :id');
        $newsStmt->execute(['id' => $newsId]);
        $targetNews = $newsStmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetNews) {
            $error = 'ไม่พบข่าวที่ต้องการดำเนินการ';
        } elseif ($action === 'change_status') {
            $newStatus = (string) ($_POST['status'] ?? '');
            if (!in_array($newStatus, $allowedStatuses, true)) {
                $error = 'สถานะข่าวไม่ถูกต้อง';
            } else {
                $pdo->prepare('UPDATE news SET status = :status WHERE news_id = :id')
                    ->execute(['status' => $newStatus, 'id' => $newsId]);
                $success = $newStatus === 'published' ? 'เผยแพร่ข่าวเรียบร้อยแล้ว' : 'ยกเลิกการเผยแพร่ข่าวแล้ว';
            }
        } else {
            $pdo->prepare('DELETE FROM news WHERE news_id = :id')->execute(['id' => $newsId]);
            $imageInUse = $pdo->prepare('SELECT COUNT(*) FROM news WHERE image_path = :image AND image_path IS NOT NULL AND image_path <> \'\'');
            $imageInUse->execute(['image' => $targetNews['image_path']]);
            if ((int) $imageInUse->fetchColumn() === 0) deleteUploadedImage($targetNews['image_path']);
            $success = 'ลบข่าวเรียบร้อยแล้ว';
        }
    }
}

// โหลดข่าวที่จะแก้ไข (ถ้ามี ?edit=)
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM news WHERE news_id = :id");
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editingNews = $stmt->fetch();
}

$where = ['1=1'];
$params = [];
if ($search !== '') {
    $where[] = '(n.title LIKE :search OR n.content LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
if (in_array($statusFilter, $allowedStatuses, true)) {
    $where[] = 'n.status = :status_filter';
    $params['status_filter'] = $statusFilter;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'n.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'n.created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM news n WHERE {$whereSql}");
$countStmt->execute($params);
$totalNews = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalNews / $perPage));
$page = min($page, $totalPages);
$newsStmt = $pdo->prepare("SELECT n.*, u.username FROM news n LEFT JOIN users u ON u.user_id = n.created_by
    WHERE {$whereSql} ORDER BY n.created_at DESC LIMIT " . (($page - 1) * $perPage) . ', ' . $perPage);
$newsStmt->execute($params);
$newsList = $newsStmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->query("SELECT COUNT(*) AS total,
    SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft
    FROM news");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'published' => 0, 'draft' => 0];
$formNews = $editingNews ?: ['news_id' => 0, 'title' => '', 'content' => '', 'image_path' => '', 'status' => 'draft'];

function formatAdminNewsDate(?string $date): string
{
    return $date ? date('d/m/Y H:i', strtotime($date)) : 'ไม่มีข้อมูล';
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข่าวสาร - Korat Esport</title>
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
        .news-action-item { min-height: 2.5rem; width: 100%; display: flex; align-items: center; gap: .625rem; padding: .625rem .75rem; border-radius: .5rem; text-align: left; font-size: .75rem; font-weight: 600; line-height: 1.25rem; color: #334155; }
        .news-action-item:hover { background: #f8fafc; }
        .news-action-item i { width: 1rem; text-align: center; color: #94a3b8; }
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
            <a href="manage-news.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-newspaper w-5 text-center text-brand-orange"></i>
                <span>จัดการข่าวสาร</span>
            </a>
            <a href="manage-gallery.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-images w-5 text-center"></i>
                <span>จัดการแกลเลอรี่</span>
            </a>
            <a href="recommended-lodging.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-hotel w-5 text-center"></i>
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
                    จัดการข่าวสาร <span class="text-brand-orange">(NEWS MANAGEMENT)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">เพิ่ม แก้ไข เผยแพร่ และลบข่าวสารประชาสัมพันธ์</p>
            </div>
            
            <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
            </a>
        </header>

        <main class="p-8 space-y-8 flex-1">

            <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <a href="manage-news.php" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-brand-orange"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">ข่าวทั้งหมด</div><div class="mt-2 text-2xl font-black text-slate-900"><?= (int) $summary['total'] ?></div></a>
                <a href="?status=published" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm hover:border-emerald-400"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-emerald-700">เผยแพร่แล้ว</div><div class="mt-2 text-2xl font-black text-emerald-700"><?= (int) $summary['published'] ?></div></a>
                <a href="?status=draft" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-400"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-700">ฉบับร่าง</div><div class="mt-2 text-2xl font-black text-amber-700"><?= (int) $summary['draft'] ?></div></a>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="ค้นหาหัวข้อหรือเนื้อหา" class="xl:col-span-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                    <select name="status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none"><option value="">ทุกสถานะ</option><option value="draft" <?= $statusFilter === 'draft' ? 'selected' : '' ?>>ฉบับร่าง</option><option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>>เผยแพร่แล้ว</option></select>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" aria-label="วันที่เริ่มต้น" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" aria-label="วันที่สิ้นสุด" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                    <div class="flex gap-2"><button type="submit" class="flex-1 rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-magnifying-glass"></i> ค้นหา</button><a href="manage-news.php" class="flex-1 rounded-xl bg-slate-100 px-4 py-2.5 text-center text-sm font-bold text-slate-600 hover:bg-slate-200">ล้างตัวกรอง</a></div>
                </form>
            </section>

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

            <div class="flex items-center justify-between gap-3">
                <div><h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2"><i class="fa-solid fa-list text-brand-orange"></i>รายการข่าวสาร</h2><p class="mt-1 text-xs text-slate-500">ทั้งหมด <?= number_format($totalNews) ?> รายการ</p></div>
                <button type="button" onclick="openNewsFormModal()" class="inline-flex items-center gap-2 rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-brand-glow"><i class="fa-solid fa-plus"></i>เพิ่มข่าวใหม่</button>
            </div>

            <?php if (false): ?>
            <!-- Legacy form markup retained but hidden; the shared modal below is the active form. -->
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 sm:p-8 space-y-6">
                <div class="border-b border-slate-100 pb-4 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold font-display text-slate-900 flex items-center gap-2">
                            <i class="fa-solid <?php echo $editingNews ? 'fa-pen-to-square' : 'fa-circle-plus'; ?> text-brand-orange"></i>
                            <?php echo $editingNews ? 'แก้ไขข่าวสาร' : 'เพิ่มข่าวใหม่'; ?>
                        </h2>
                        <p class="text-xs text-slate-500 mt-1">กรอกรายละเอียดข่าวประชาสัมพันธ์สำหรับแสดงบนหน้าเว็บไซต์</p>
                    </div>

                    <?php if ($editingNews): ?>
                        <a href="manage-news.php" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold text-xs flex items-center gap-1.5 transition-all">
                            <i class="fa-solid fa-xmark"></i> ยกเลิกการแก้ไข
                        </a>
                    <?php endif; ?>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="save">
                    <?php if ($editingNews): ?>
                        <input type="hidden" name="news_id" value="<?php echo $editingNews['news_id']; ?>">
                    <?php endif; ?>

                    <div class="space-y-4">
                        <!-- หัวข้อข่าว -->
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">หัวข้อข่าว</label>
                            <input type="text" name="title" required placeholder="ระบุหัวข้อข่าวประชาสัมพันธ์..."
                                value="<?php echo $editingNews ? htmlspecialchars($editingNews['title']) : ''; ?>"
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange focus:ring-1 focus:ring-brand-orange transition-all font-medium">
                        </div>

                        <!-- เนื้อหาข่าว -->
                        <div>
                            <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เนื้อหาข่าว</label>
                            <textarea name="content" rows="6" required placeholder="พิมพ์รายละเอียดเนื้อหาข่าวสารที่นี่..."
                                class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange focus:ring-1 focus:ring-brand-orange transition-all font-medium"><?php echo $editingNews ? htmlspecialchars($editingNews['content']) : ''; ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                            <!-- รูปปกข่าว -->
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">
                                    รูปปกข่าว <?php echo $editingNews ? '<span class="text-slate-400 font-normal">(เลือกใหม่หากต้องการเปลี่ยน)</span>' : '<span class="text-slate-400 font-normal">(รองรับ JPG, PNG, WEBP)</span>'; ?>
                                </label>
                                <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                                    class="block w-full text-xs text-slate-500 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-orange-50 file:text-brand-orange hover:file:bg-orange-100 cursor-pointer">
                                
                                <?php if ($editingNews && $editingNews['image_path']): ?>
                                    <div class="mt-3 flex items-center gap-3 p-2 bg-slate-50 rounded-xl border border-slate-200">
                                        <img src="../assets/<?php echo htmlspecialchars($editingNews['image_path']); ?>" class="h-16 w-24 object-cover rounded-lg">
                                        <span class="text-xs text-slate-500 font-medium">รูปปกข่าวปัจจุบัน</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- สถานะ -->
                            <div>
                                <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">สถานะการเผยแพร่</label>
                                <select name="status" 
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                                    <option value="published" <?php echo ($editingNews && $editingNews['status'] == 'published') ? 'selected' : ''; ?>>เผยแพร่ทันที (Published)</option>
                                    <option value="draft" <?php echo ($editingNews && $editingNews['status'] == 'draft') ? 'selected' : ''; ?>>ฉบับร่าง (Draft - ยังไม่แสดงต่อสาธารณะ)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <?php if ($editingNews): ?>
                            <a href="manage-news.php" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold text-xs flex items-center gap-1.5 transition-all">
                                ยกเลิก
                            </a>
                        <?php endif; ?>
                        
                        <button type="submit" 
                            class="px-6 py-2.5 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs uppercase tracking-wider transition-all shadow-md flex items-center gap-2 cursor-pointer">
                            <i class="fa-solid fa-check"></i>
                            <span><?php echo $editingNews ? 'บันทึกการแก้ไข' : 'เพิ่มข่าวใหม่'; ?></span>
                        </button>
                    </div>
                </form>
            </div>

            <?php endif; ?>

            <!-- TABLE: รายการข่าวทั้งหมด -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm text-slate-600">
                            <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                                <tr>
                                    <th class="p-4">รูปปก</th>
                                    <th class="p-4">หัวข้อข่าว</th>
                                    <th class="p-4 text-center">สถานะ</th>
                                    <th class="p-4">ผู้เขียน</th>
                                    <th class="p-4">วันที่ลงข่าว</th>
                                    <th class="p-4 text-right">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (count($newsList) == 0): ?>
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-slate-400">
                                            <i class="fa-solid fa-newspaper text-3xl mb-2 block opacity-40"></i>
                                            <?= ($search !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== '') ? 'ไม่พบข่าวตามเงื่อนไขที่ค้นหา' : 'ยังไม่มีข่าวสารในระบบ' ?>
                                            <?php if ($totalNews === 0 && $search === '' && $statusFilter === '' && $dateFrom === '' && $dateTo === ''): ?><div class="mt-4"><button type="button" onclick="openNewsFormModal()" class="inline-flex items-center gap-2 rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-plus"></i>เพิ่มข่าวใหม่</button></div><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($newsList as $n): ?>
                                <tr class="hover:bg-slate-50/80 transition-colors">
                                    <!-- รูปปก -->
                                    <td class="p-4 w-20">
                                        <?php if ($n['image_path']): ?>
                                            <img src="../assets/<?php echo htmlspecialchars($n['image_path']); ?>" class="h-10 w-14 object-cover rounded-lg border border-slate-200">
                                        <?php else: ?>
                                            <div class="h-10 w-14 rounded-lg bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-400 text-xs">
                                                <i class="fa-regular fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- หัวข้อข่าว -->
                                    <td class="p-4 font-bold text-slate-900 max-w-xs">
                                        <div class="max-w-xs truncate" title="<?= htmlspecialchars($n['title']) ?>"><?= htmlspecialchars($n['title']); ?></div>
                                        <div class="mt-1 max-w-xs truncate text-[11px] font-normal text-slate-400"><?= htmlspecialchars(mb_strimwidth(strip_tags($n['content']), 0, 100, '...')) ?></div>
                                    </td>

                                    <!-- สถานะ -->
                                    <td class="p-4 text-center">
                                        <?php if ($n['status'] == 'published'): ?>
                                            <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">เผยแพร่แล้ว</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 text-xs font-bold">ฉบับร่าง</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- ผู้เขียน -->
                                    <td class="p-4 text-xs font-semibold text-slate-700">
                                        <i class="fa-regular fa-user mr-1 text-slate-400"></i>
                                        <?php echo htmlspecialchars($n['username']); ?>
                                    </td>

                                    <!-- วันที่ -->
                                    <td class="p-4 text-xs text-slate-400">
                                        <?php echo formatAdminNewsDate($n['created_at']); ?>
                                    </td>

                                    <!-- จัดการ -->
                                    <td class="p-4 text-right whitespace-nowrap">
                                        <div class="flex justify-end gap-2">
                                            <button type="button" onclick="openNewsDetailModal(<?= (int) $n['news_id'] ?>)" class="inline-flex h-9 items-center gap-2 rounded-lg bg-slate-100 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-200"><i class="fa-solid fa-circle-info"></i>รายละเอียด</button>
                                            <div class="relative">
                                                <button type="button" class="news-action-toggle inline-flex h-9 items-center gap-2 rounded-lg bg-brand-orange px-3 text-xs font-semibold text-white hover:bg-brand-glow" data-menu-id="news-menu-<?= (int) $n['news_id'] ?>" aria-expanded="false" aria-controls="news-menu-<?= (int) $n['news_id'] ?>"><i class="fa-solid fa-ellipsis"></i>จัดการ</button>
                                                <div id="news-menu-<?= (int) $n['news_id'] ?>" class="news-action-menu fixed z-[70] hidden w-56 rounded-xl border border-slate-200 bg-white p-2 text-left shadow-xl" role="menu">
                                                    <button type="button" onclick="openNewsFormModal(<?= (int) $n['news_id'] ?>)" class="news-action-item"><i class="fa-solid fa-pen-to-square"></i>แก้ไขข่าว</button>
                                                    <button type="button" onclick="openNewsPreview(<?= (int) $n['news_id'] ?>)" class="news-action-item"><i class="fa-solid fa-eye"></i>ดูตัวอย่าง</button>
                                                    <?php if ($n['status'] === 'draft'): ?>
                                                        <form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="news_id" value="<?= (int) $n['news_id'] ?>"><input type="hidden" name="status" value="published"><button type="submit" class="news-action-item text-emerald-700"><i class="fa-solid fa-upload"></i>เผยแพร่</button></form>
                                                    <?php else: ?>
                                                        <form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="news_id" value="<?= (int) $n['news_id'] ?>"><input type="hidden" name="status" value="draft"><button type="submit" class="news-action-item"><i class="fa-solid fa-eye-slash"></i>ยกเลิกการเผยแพร่</button></form>
                                                    <?php endif; ?>
                                                    <div class="my-1 border-t border-slate-100"></div>
                                                    <form method="POST" onsubmit="return openNewsDeleteConfirm(this, <?= htmlspecialchars(json_encode($n['title'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="news_id" value="<?= (int) $n['news_id'] ?>"><button type="submit" class="news-action-item text-rose-600"><i class="fa-solid fa-trash"></i>ลบข่าว</button></form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php if ($totalPages > 1): ?>
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs shadow-sm">
                    <span class="text-slate-500">หน้า <?= $page ?> / <?= $totalPages ?></span>
                    <div class="flex flex-wrap gap-1">
                        <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                            <a href="?<?= http_build_query(['q' => $search, 'status' => $statusFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'page' => $pageNumber]) ?>" class="rounded-lg px-3 py-1.5 font-bold <?= $pageNumber === $page ? 'bg-brand-orange text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>"><?= $pageNumber ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <div id="newsDetailModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 id="newsDetailTitle" class="font-bold text-slate-900">รายละเอียดข่าว</h3><button type="button" onclick="closeNewsDetailModal()" class="text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark"></i></button></div><article class="p-6"><img id="newsDetailImage" class="mb-4 hidden max-h-72 w-full rounded-xl object-cover"><p id="newsDetailMeta" class="text-xs text-slate-400"></p><div id="newsDetailContent" class="mt-5 whitespace-pre-line text-sm leading-7 text-slate-700"></div></article><div class="flex justify-end border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" onclick="closeNewsDetailModal()" class="rounded-lg bg-slate-200 px-4 py-2 text-xs font-bold text-slate-700">ปิด</button></div></div></div>

    <div id="newsFormModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4">
        <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 id="newsFormTitle" class="font-bold text-slate-900">เพิ่มข่าวใหม่</h3><button type="button" onclick="closeNewsFormModal()" class="p-1 text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark text-lg"></i></button></div>
            <form id="newsForm" method="POST" enctype="multipart/form-data" class="space-y-4 overflow-y-auto p-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="news_id" id="newsFormId">
                <div><label class="mb-1 block text-xs font-bold text-slate-700">หัวข้อข่าว</label><input type="text" name="title" id="newsFormTitleInput" maxlength="200" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none"></div>
                <div><label class="mb-1 block text-xs font-bold text-slate-700">เนื้อหาข่าว</label><textarea name="content" id="newsFormContent" rows="9" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none"></textarea></div>
                <div><label class="mb-1 block text-xs font-bold text-slate-700">รูปปกข่าว <span class="font-normal text-slate-400">JPG, PNG, WEBP ไม่เกิน 5MB</span></label><input type="file" name="image" id="newsFormImage" accept="image/jpeg,image/png,image/webp" class="w-full text-xs" onchange="previewNewsImage(this)"><div id="newsImageName" class="mt-2 text-xs text-slate-500"></div><div id="newsImagePreview" class="mt-2"></div></div>
                <div><label class="mb-1 block text-xs font-bold text-slate-700">สถานะการเผยแพร่</label><select name="status" id="newsFormStatus" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><option value="draft">ฉบับร่าง</option><option value="published">เผยแพร่</option></select></div>
                <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-slate-100 bg-white pt-4"><button type="button" onclick="openNewsPreview()" class="rounded-xl bg-slate-100 px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-200"><i class="fa-solid fa-eye"></i> ดูตัวอย่าง</button><button type="button" onclick="closeNewsFormModal()" class="rounded-xl bg-slate-100 px-4 py-2.5 text-xs font-bold text-slate-600">ยกเลิก</button><button type="submit" class="rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-floppy-disk"></i> บันทึกข่าว</button></div>
            </form>
        </div>
    </div>

    <div id="newsPreviewModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><h3 class="font-bold text-slate-900">ตัวอย่างข่าว</h3><button type="button" onclick="closeNewsPreview()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><article class="p-6"><img id="previewNewsImage" class="mb-4 hidden max-h-72 w-full rounded-xl object-cover"><h1 id="previewNewsTitle" class="text-xl font-black text-slate-900"></h1><p class="mt-1 text-xs text-slate-400">ผู้เขียน: <?= htmlspecialchars($currentUser['username'] ?? 'Admin') ?> | <?= date('d/m/Y H:i') ?></p><div id="previewNewsContent" class="mt-5 whitespace-pre-line text-sm leading-7 text-slate-700"></div></article></div></div>

    <div id="newsDeleteModal" class="fixed inset-0 z-[70] hidden items-center justify-center bg-slate-900/70 p-4"><div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h3 class="font-bold text-slate-900"><i class="fa-solid fa-triangle-exclamation mr-2 text-rose-600"></i>ยืนยันการลบข่าว</h3><p class="mt-3 text-sm text-slate-600">ข่าว <strong id="deleteNewsTitle" class="text-slate-900"></strong> จะหายจากหน้าสาธารณะและไม่สามารถกู้คืนได้</p><div class="mt-5 flex justify-end gap-2"><button type="button" onclick="closeNewsDeleteConfirm()" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold text-slate-700">ยกเลิก</button><button type="button" onclick="submitNewsDelete()" class="rounded-lg bg-rose-600 px-4 py-2 text-xs font-bold text-white">ยืนยันลบข่าว</button></div></div></div>

    <script>
        const newsData = <?= json_encode(array_column(array_merge($newsList, $editingNews ? [$editingNews] : []), null, 'news_id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let newsFormDirty = false;
        let pendingDeleteForm = null;

        function escapeNewsHtml(value) {
            return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }
        function openNewsFormModal(newsId = 0) {
            const news = newsData[newsId] || {news_id: 0, title: '', content: '', image_path: '', status: 'draft'};
            document.getElementById('newsFormTitle').textContent = newsId ? 'แก้ไขข่าวสาร' : 'เพิ่มข่าวใหม่';
            document.getElementById('newsFormId').value = news.news_id || '';
            document.getElementById('newsFormTitleInput').value = news.title || '';
            document.getElementById('newsFormContent').value = news.content || '';
            document.getElementById('newsFormStatus').value = news.status || 'draft';
            document.getElementById('newsFormImage').value = '';
            document.getElementById('newsImageName').textContent = news.image_path ? 'รูปปกเดิม: ' + news.image_path : '';
            document.getElementById('newsImagePreview').innerHTML = news.image_path ? `<img src="../assets/${escapeNewsHtml(news.image_path)}" alt="" class="max-h-40 rounded-lg object-cover">` : '';
            newsFormDirty = false;
            document.getElementById('newsFormModal').classList.remove('hidden');
            document.getElementById('newsFormModal').classList.add('flex');
        }
        function openNewsDetailModal(newsId) {
            const news = newsData[newsId]; if (!news) return;
            document.getElementById('newsDetailTitle').textContent = news.title || 'รายละเอียดข่าว';
            document.getElementById('newsDetailMeta').textContent = 'ผู้เขียน: ' + (news.username || 'ไม่ระบุ') + ' | สร้างเมื่อ: ' + (news.created_at || 'ไม่มีข้อมูล') + ' | สถานะ: ' + (news.status === 'published' ? 'เผยแพร่แล้ว' : 'ฉบับร่าง');
            document.getElementById('newsDetailContent').textContent = news.content || '';
            const image = document.getElementById('newsDetailImage');
            if (news.image_path) { image.src = '../assets/' + news.image_path; image.classList.remove('hidden'); } else image.classList.add('hidden');
            document.getElementById('newsDetailModal').classList.remove('hidden'); document.getElementById('newsDetailModal').classList.add('flex');
        }
        function closeNewsDetailModal() { document.getElementById('newsDetailModal').classList.add('hidden'); document.getElementById('newsDetailModal').classList.remove('flex'); }
        function closeNewsFormModal() {
            if (newsFormDirty && !window.confirm('มีข้อมูลที่แก้ไขแล้วยังไม่ได้บันทึก ต้องการปิดหน้าต่างหรือไม่?')) return;
            document.getElementById('newsFormModal').classList.add('hidden');
            document.getElementById('newsFormModal').classList.remove('flex');
            newsFormDirty = false;
        }
        function previewNewsImage(input) {
            const file = input.files && input.files[0];
            document.getElementById('newsImageName').textContent = file ? file.name + ' (' + Math.round(file.size / 1024) + ' KB)' : '';
            if (!file) return;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
                input.value = '';
                document.getElementById('newsImagePreview').innerHTML = '<p class="text-xs text-rose-600">กรุณาเลือก JPG, PNG หรือ WEBP ขนาดไม่เกิน 5MB</p>';
                return;
            }
            const reader = new FileReader();
            reader.onload = event => { document.getElementById('newsImagePreview').innerHTML = `<img src="${event.target.result}" alt="" class="max-h-40 rounded-lg object-cover">`; };
            reader.readAsDataURL(file);
        }
        function openNewsPreview(newsId = 0) {
            const news = newsId ? newsData[newsId] : {title: document.getElementById('newsFormTitleInput').value, content: document.getElementById('newsFormContent').value};
            if (!news) return;
            document.getElementById('previewNewsTitle').textContent = news.title || 'ไม่มีหัวข้อข่าว';
            document.getElementById('previewNewsContent').textContent = news.content || 'ไม่มีเนื้อหาข่าว';
            const image = document.getElementById('previewNewsImage');
            const selected = document.getElementById('newsFormImage').files[0];
            if (selected) { image.src = URL.createObjectURL(selected); image.classList.remove('hidden'); }
            else if (news.image_path) { image.src = '../assets/' + news.image_path; image.classList.remove('hidden'); }
            else image.classList.add('hidden');
            document.getElementById('newsPreviewModal').classList.remove('hidden');
            document.getElementById('newsPreviewModal').classList.add('flex');
        }
        function closeNewsPreview() { document.getElementById('newsPreviewModal').classList.add('hidden'); document.getElementById('newsPreviewModal').classList.remove('flex'); }
        function openNewsDeleteConfirm(form, title) { pendingDeleteForm = form; document.getElementById('deleteNewsTitle').textContent = title; document.getElementById('newsDeleteModal').classList.remove('hidden'); document.getElementById('newsDeleteModal').classList.add('flex'); return false; }
        function closeNewsDeleteConfirm() { pendingDeleteForm = null; document.getElementById('newsDeleteModal').classList.add('hidden'); document.getElementById('newsDeleteModal').classList.remove('flex'); }
        function submitNewsDelete() { if (!pendingDeleteForm) return; pendingDeleteForm.removeAttribute('onsubmit'); pendingDeleteForm.submit(); }

        document.addEventListener('DOMContentLoaded', () => {
            const menus = document.querySelectorAll('.news-action-menu');
            const toggles = document.querySelectorAll('.news-action-toggle');
            const closeMenus = () => { menus.forEach(menu => menu.classList.add('hidden')); toggles.forEach(toggle => toggle.setAttribute('aria-expanded', 'false')); };
            toggles.forEach(toggle => {
                toggle.addEventListener('click', event => {
                    event.preventDefault(); event.stopPropagation();
                    const menu = document.getElementById(toggle.dataset.menuId); if (!menu) return;
                    const opening = menu.classList.contains('hidden'); closeMenus(); if (!opening) return;
                    document.body.appendChild(menu); menu.classList.remove('hidden');
                    const rect = toggle.getBoundingClientRect(); const width = menu.offsetWidth || 224; const height = menu.offsetHeight || 260;
                    menu.style.left = `${Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8))}px`;
                    menu.style.top = `${rect.bottom + height + 8 <= window.innerHeight - 8 ? rect.bottom + 8 : Math.max(8, rect.top - height - 8)}px`;
                    toggle.setAttribute('aria-expanded', 'true');
                });
                toggle.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggle.click(); } });
            });
            menus.forEach(menu => menu.addEventListener('click', event => event.stopPropagation()));
            document.addEventListener('click', closeMenus);
            document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeMenus(); closeNewsFormModal(); closeNewsPreview(); closeNewsDeleteConfirm(); } });
            window.addEventListener('resize', closeMenus); window.addEventListener('scroll', closeMenus, true);
            document.getElementById('newsForm')?.querySelectorAll('input, textarea, select').forEach(field => field.addEventListener('input', () => { newsFormDirty = true; }));
            document.getElementById('newsFormModal')?.addEventListener('click', event => { if (event.target.id === 'newsFormModal') closeNewsFormModal(); });
            document.getElementById('newsDetailModal')?.addEventListener('click', event => { if (event.target.id === 'newsDetailModal') closeNewsDetailModal(); });
            document.getElementById('newsPreviewModal')?.addEventListener('click', event => { if (event.target.id === 'newsPreviewModal') closeNewsPreview(); });
            document.getElementById('newsDeleteModal')?.addEventListener('click', event => { if (event.target.id === 'newsDeleteModal') closeNewsDeleteConfirm(); });
            <?php if ($editingNews): ?>openNewsFormModal(<?= (int) $editingNews['news_id'] ?>);<?php endif; ?>
        });
    </script>

</body>
</html>