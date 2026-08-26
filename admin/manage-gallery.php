<?php
// admin/manage-gallery.php
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
$csrfToken = generateCsrfToken();
$activeTab = ($_GET['tab'] ?? 'activity') === 'banner' ? 'banner' : 'activity';
$gallerySearch = trim((string) ($_GET['q'] ?? ''));
$galleryStatus = $_GET['status'] ?? '';

// ================= 1. ประมวลผลสร้างอัลบั้ม + อัปโหลดรูปพร้อมกันในขั้นตอนเดียว =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_gallery') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    }
    $albumOption = trim($_POST['album_option'] ?? '');
    $bannerAlbumOption = trim($_POST['banner_album_option'] ?? '');
    $newAlbumName = trim($_POST['new_album_name'] ?? '');
    $newAlbumDesc = trim($_POST['new_album_desc'] ?? '');
    $newBannerAlbumName = trim($_POST['new_banner_album_name'] ?? '');
    $newBannerAlbumDesc = trim($_POST['new_banner_album_desc'] ?? '');
    $mediaType = ($_POST['media_type'] ?? 'activity') === 'banner' ? 'banner' : 'activity';
    $mediaTitle = trim($_POST['media_title'] ?? '');
    $caption = trim($_POST['caption'] ?? '');
    $adminUserId = (int) ($_SESSION['user_id'] ?? 0);

    $albumId = 0;

    // แบนเนอร์ไม่ต้องผูกกับอัลบั้ม ส่วนรูปกิจกรรมยังใช้อัลบั้มเดิม
    if ($error !== '') {
        // Stop upload processing when the CSRF token is invalid.
    } elseif ($mediaType === 'banner') {
        if ($bannerAlbumOption === 'new') {
            if ($newBannerAlbumName === '') {
                $error = 'กรุณาระบุชื่ออัลบั้มแบนเนอร์';
            } else {
                $ins = $pdo->prepare("INSERT INTO gallery_albums (title, description, album_type) VALUES (:title, :desc, 'banner')");
                $ins->execute(['title' => $newBannerAlbumName, 'desc' => $newBannerAlbumDesc]);
                $albumId = (int) $pdo->lastInsertId();
            }
        } else {
            $albumId = (int) $bannerAlbumOption;
        }
        if ($albumId <= 0) $error = 'กรุณาเลือกหรือสร้างอัลบั้มแบนเนอร์';
        if ($mediaTitle === '') $error = 'กรุณาระบุหัวข้อแบนเนอร์ประชาสัมพันธ์';
    } elseif ($albumOption === 'new') {
        if (empty($newAlbumName)) {
            $error = 'กรุณาระบุชื่ออัลบั้ม/โฟลเดอร์ใหม่';
        } else {
            $ins = $pdo->prepare("INSERT INTO gallery_albums (title, description, album_type) VALUES (:title, :desc, 'activity')");
            $ins->execute(['title' => $newAlbumName, 'desc' => $newAlbumDesc]);
            $albumId = (int) $pdo->lastInsertId();
        }
    } else {
        $albumId = (int) $albumOption;
    }

    // 1.2 ดำเนินการอัปโหลดรูปภาพ
    if (empty($error)) {
        if ($mediaType === 'activity' && $albumId <= 0) {
            $error = 'กรุณาเลือกอัลบั้ม หรือสร้างอัลบั้มใหม่';
        } elseif (!isset($_FILES['photos']) || empty($_FILES['photos']['name'][0])) {
            $error = 'กรุณาเลือกรูปภาพอย่างน้อย 1 รูป';
        } else {
            $uploadDir = $mediaType === 'banner'
                ? "../assets/uploads/banners/album_{$albumId}/"
                : "../assets/uploads/gallery/album_{$albumId}/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $uploadedCount = 0;

            foreach ($_FILES['photos']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                        $mimeType = mime_content_type($tmpName);
                        $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mimeType] ?? null;

                    if ($mimeExt && in_array(strtolower(pathinfo($_FILES['photos']['name'][$key], PATHINFO_EXTENSION)), $allowed, true)) {
                        $fileName = 'img_' . bin2hex(random_bytes(8)) . '.' . $mimeExt;
                        $targetFile = $uploadDir . $fileName;

                        if (move_uploaded_file($tmpName, $targetFile)) {
                            $relativePath = $mediaType === 'banner'
                                ? "uploads/banners/album_{$albumId}/" . $fileName
                                : "uploads/gallery/album_{$albumId}/" . $fileName;

                            try {
                                $stmt = $pdo->prepare("INSERT INTO gallery (album_id, media_type, title, image_path, caption, is_active, created_by, created_at) VALUES (:aid, :type, :title, :path, :cap, 1, :uid, NOW())");
                                $stmt->execute(['aid' => $albumId ?: null, 'type' => $mediaType, 'title' => $mediaTitle ?: null, 'path' => $relativePath, 'cap' => $caption, 'uid' => $adminUserId]);
                            } catch (Exception $e) {
                                $stmt = $pdo->prepare("INSERT INTO gallery (album_id, media_type, title, image_path, caption, is_active, created_at) VALUES (:aid, :type, :title, :path, :cap, 1, NOW())");
                                $stmt->execute(['aid' => $albumId ?: null, 'type' => $mediaType, 'title' => $mediaTitle ?: null, 'path' => $relativePath, 'cap' => $caption]);
                            }

                            $uploadedCount++;
                        }
                    }
                }
            }

            if ($uploadedCount > 0) {
                $success = "บันทึกและอัปโหลดรูปภาพจำนวน {$uploadedCount} รูป เรียบร้อยแล้ว!";
            } else {
                $error = 'ไม่สามารถอัปโหลดรูปภาพได้ โปรดตรวจสอบประเภทไฟล์ (JPG, PNG, WEBP)';
            }
        }
    }
}

// แก้ไขหัวข้อ คำบรรยาย และสถานะเผยแพร่ของสื่อ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_media') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $title = trim($_POST['media_title'] ?? '');
        $caption = trim($_POST['caption'] ?? '');
        $isActive = in_array((string) ($_POST['is_active'] ?? ''), ['1', 'on'], true) ? 1 : 0;
        if ($mediaId > 0) {
            $pdo->prepare('UPDATE gallery SET title = :title, caption = :caption, is_active = :active WHERE gallery_id = :id')
                ->execute(['title' => $title ?: null, 'caption' => $caption ?: null, 'active' => $isActive, 'id' => $mediaId]);
            $success = 'แก้ไขข้อมูลสื่อเรียบร้อยแล้ว';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_album') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $albumId = (int) ($_POST['album_id'] ?? 0);
        $title = trim((string) ($_POST['album_title'] ?? ''));
        $description = trim((string) ($_POST['album_description'] ?? ''));
        if ($albumId <= 0 || $title === '') {
            $error = 'กรุณาระบุชื่ออัลบั้ม';
        } else {
            $pdo->prepare('UPDATE gallery_albums SET title = :title, description = :description WHERE album_id = :id')
                ->execute(['title' => $title, 'description' => $description ?: null, 'id' => $albumId]);
            $success = 'แก้ไขข้อมูลอัลบั้มเรียบร้อยแล้ว';
        }
    }
}

// ================= 2. ลบรูปภาพ =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_media') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $photoId = (int) ($_POST['media_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT image_path FROM gallery WHERE gallery_id = :id");
        $stmt->execute(['id' => $photoId]);
        $photo = $stmt->fetch();

        if ($photo) {
            $filePath = '../assets/' . $photo['image_path'];
            $inUseStmt = $pdo->prepare('SELECT COUNT(*) FROM gallery WHERE image_path = :path AND gallery_id <> :id');
            $inUseStmt->execute(['path' => $photo['image_path'], 'id' => $photoId]);
            if ((int) $inUseStmt->fetchColumn() === 0 && file_exists($filePath)) @unlink($filePath);
            $pdo->prepare("DELETE FROM gallery WHERE gallery_id = :id")->execute(['id' => $photoId]);
            $success = 'ลบสื่อเรียบร้อยแล้ว';
        }
    }
}

// ================= 3. ลบอัลบั้ม (พร้อมลบรูปทั้งหมดในอัลบั้ม) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_album' && verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $delAlbumId = (int) ($_POST['album_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT image_path FROM gallery WHERE album_id = :aid");
    $stmt->execute(['aid' => $delAlbumId]);
    $photos = $stmt->fetchAll();

    foreach ($photos as $p) {
        $filePath = '../assets/' . $p['image_path'];
        if (file_exists($filePath)) { @unlink($filePath); }
    }

    $albumTypeStmt = $pdo->prepare('SELECT album_type FROM gallery_albums WHERE album_id = :id');
    $albumTypeStmt->execute(['id' => $delAlbumId]);
    $albumType = $albumTypeStmt->fetchColumn() ?: 'activity';
    $dirPath = $albumType === 'banner'
        ? "../assets/uploads/banners/album_{$delAlbumId}/"
        : "../assets/uploads/gallery/album_{$delAlbumId}/";
    if (is_dir($dirPath)) { @rmdir($dirPath); }

    $pdo->prepare("DELETE FROM gallery WHERE album_id = :aid")->execute(['aid' => $delAlbumId]);
    $pdo->prepare("DELETE FROM gallery_albums WHERE album_id = :aid")->execute(['aid' => $delAlbumId]);
    $success = 'ลบอัลบั้มและรูปภาพทั้งหมดในอัลบั้มเรียบร้อยแล้ว';
}

// ดึงข้อมูลอัลบั้มทั้งหมด
try {
    $albums = $pdo->query("
        SELECT a.*, COUNT(g.gallery_id) AS photo_count,
               (SELECT image_path FROM gallery WHERE album_id = a.album_id ORDER BY gallery_id DESC LIMIT 1) AS cover_image
        FROM gallery_albums a
        LEFT JOIN gallery g ON g.album_id = a.album_id
        GROUP BY a.album_id
        ORDER BY a.created_at DESC
    ")->fetchAll();
} catch (Exception $e) {
    $albums = [];
}
$bannerAlbums = array_values(array_filter($albums, static fn($album) => ($album['album_type'] ?? 'activity') === 'banner'));
$activityAlbums = array_values(array_filter($albums, static fn($album) => ($album['album_type'] ?? 'activity') === 'activity'));
if ($gallerySearch !== '') {
    $needle = mb_strtolower($gallerySearch);
    $activityAlbums = array_values(array_filter($activityAlbums, static fn($album) => str_contains(mb_strtolower((string) $album['title']), $needle)));
    $bannerAlbums = array_values(array_filter($bannerAlbums, static fn($album) => str_contains(mb_strtolower((string) $album['title']), $needle)));
}

// รับค่าฟิลเตอร์อัลบั้มที่เลือกดู
$selectedAlbumId = (int) ($_GET['view_album'] ?? 0);
$banners = $pdo->query("SELECT g.*, a.title AS album_title
    FROM gallery g LEFT JOIN gallery_albums a ON a.album_id = g.album_id
    WHERE g.media_type = 'banner' ORDER BY g.gallery_id DESC")->fetchAll();
$banners = array_values(array_filter($banners, static function (array $banner) use ($gallerySearch, $galleryStatus): bool {
    if ($gallerySearch !== '' && !str_contains(mb_strtolower((string) ($banner['title'] ?? '') . ' ' . ($banner['caption'] ?? '')), mb_strtolower($gallerySearch))) return false;
    if ($galleryStatus !== '' && (string) $banner['is_active'] !== (string) ((int) ($galleryStatus === 'active'))) return false;
    return true;
}));

$activityPhotosByAlbum = [];
$allActivityPhotos = $pdo->query("SELECT g.*, a.title AS album_title FROM gallery g LEFT JOIN gallery_albums a ON a.album_id = g.album_id WHERE g.media_type = 'activity' ORDER BY g.created_at DESC")->fetchAll();
foreach ($allActivityPhotos as $photo) $activityPhotosByAlbum[(int) $photo['album_id']][] = $photo;

// ดึงรูปภาพตามอัลบั้ม
if ($selectedAlbumId > 0) {
    $pStmt = $pdo->prepare("SELECT g.*, a.title AS album_title FROM gallery g JOIN gallery_albums a ON a.album_id = g.album_id WHERE g.media_type = 'activity' AND g.album_id = :aid ORDER BY g.gallery_id DESC");
    $pStmt->execute(['aid' => $selectedAlbumId]);
    $galleryPhotos = $pStmt->fetchAll();
} else {
    $galleryPhotos = $pdo->query("SELECT g.*, a.title AS album_title FROM gallery g LEFT JOIN gallery_albums a ON a.album_id = g.album_id WHERE g.media_type = 'activity' ORDER BY g.gallery_id DESC")->fetchAll();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: $success);
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'manage-gallery.php'), true, 303);
    exit;
}
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
    <title>จัดการแกลเลอรี่ - Korat Esport</title>
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
        .gallery-action-item { min-height: 2.5rem; width: 100%; display: flex; align-items: center; padding: .625rem .75rem; border-radius: .5rem; text-align: left; font-size: .75rem; font-weight: 600; color: #334155; }
        .gallery-action-item:hover { background: #f8fafc; }
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
            <a href="manage-gallery.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-images w-5 text-center text-brand-orange"></i>
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
                    จัดการแกลเลอรี่ <span class="text-brand-orange">(GALLERY MANAGEMENT)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">จัดการอัลบั้มกิจกรรม รูปภาพ และแบนเนอร์ประชาสัมพันธ์</p>
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

            <div class="flex items-center justify-between gap-3 overflow-x-auto border-b border-slate-200">
                <div class="flex min-w-max gap-2">
                    <a href="?tab=activity" class="rounded-t-xl px-5 py-3 text-sm font-bold <?= $activeTab === 'activity' ? 'border-b-2 border-brand-orange bg-orange-50 text-brand-orange' : 'text-slate-500 hover:bg-slate-50' ?>"><i class="fa-solid fa-images mr-2"></i>อัลบั้มกิจกรรม</a>
                    <a href="?tab=banner" class="rounded-t-xl px-5 py-3 text-sm font-bold <?= $activeTab === 'banner' ? 'border-b-2 border-brand-orange bg-orange-50 text-brand-orange' : 'text-slate-500 hover:bg-slate-50' ?>"><i class="fa-solid fa-bullhorn mr-2"></i>แบนเนอร์ประชาสัมพันธ์</a>
                </div>
                <button type="button" onclick="openGalleryFormModal('<?= $activeTab ?>')" class="inline-flex min-w-max items-center gap-2 rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-plus"></i><?= $activeTab === 'activity' ? 'สร้างอัลบั้ม' : 'เพิ่มแบนเนอร์' ?></button>
            </div>

            <section class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <?php if ($activeTab === 'activity'): ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">อัลบั้มทั้งหมด</div><div class="mt-2 text-2xl font-black text-slate-900"><?= count($activityAlbums) ?></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">รูปทั้งหมด</div><div class="mt-2 text-2xl font-black text-slate-900"><?= array_sum(array_map(static fn($album) => (int) $album['photo_count'], $activityAlbums)) ?></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">อัลบั้มล่าสุด</div><div class="mt-2 truncate text-sm font-black text-slate-900"><?= htmlspecialchars($activityAlbums[0]['title'] ?? 'ไม่มีข้อมูล') ?></div></div>
                <?php else: ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">แบนเนอร์ทั้งหมด</div><div class="mt-2 text-2xl font-black text-slate-900"><?= count($banners) ?></div></div>
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-emerald-700">กำลังแสดง</div><div class="mt-2 text-2xl font-black text-emerald-700"><?= count(array_filter($banners, static fn($banner) => (int) $banner['is_active'] === 1)) ?></div></div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">ปิดการแสดง</div><div class="mt-2 text-2xl font-black text-slate-700"><?= count(array_filter($banners, static fn($banner) => (int) $banner['is_active'] === 0)) ?></div></div>
                <?php endif; ?>
            </section>

            <form method="GET" class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row">
                <input type="hidden" name="tab" value="<?= $activeTab ?>"><input type="text" name="q" value="<?= htmlspecialchars($gallerySearch) ?>" placeholder="ค้นหา<?= $activeTab === 'activity' ? 'ชื่ออัลบั้ม' : 'หัวข้อหรือคำบรรยายแบนเนอร์' ?>" class="flex-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                <?php if ($activeTab === 'banner'): ?><select name="status" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><option value="">ทุกสถานะ</option><option value="active" <?= $galleryStatus === 'active' ? 'selected' : '' ?>>กำลังแสดง</option><option value="inactive" <?= $galleryStatus === 'inactive' ? 'selected' : '' ?>>ปิดการแสดง</option></select><?php endif; ?><button type="submit" class="rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow">ค้นหา</button><a href="?tab=<?= $activeTab ?>" class="rounded-xl bg-slate-100 px-4 py-2.5 text-center text-sm font-bold text-slate-600 hover:bg-slate-200">ล้างตัวกรอง</a>
            </form>

            <?php if ($activeTab === 'activity'): ?>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    <?php if (!$activityAlbums): ?><div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500">ยังไม่มีอัลบั้มกิจกรรม<div class="mt-4"><button type="button" onclick="openGalleryFormModal('activity')" class="rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white">+ สร้างอัลบั้ม</button></div></div><?php endif; ?>
                    <?php foreach ($activityAlbums as $album): ?>
                        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="h-44 overflow-hidden rounded-xl bg-slate-100"><?php if ($album['cover_image']): ?><img src="../assets/<?= htmlspecialchars($album['cover_image']) ?>" alt="<?= htmlspecialchars($album['title']) ?>" class="h-full w-full object-cover"><?php else: ?><div class="flex h-full items-center justify-center text-4xl text-amber-400"><i class="fa-solid fa-folder"></i></div><?php endif; ?></div><h3 class="mt-3 truncate font-bold text-slate-900"><?= htmlspecialchars($album['title']) ?></h3><p class="mt-1 line-clamp-2 text-xs text-slate-500"><?= htmlspecialchars($album['description'] ?? '') ?></p><div class="mt-3 flex items-center justify-between text-[11px] text-slate-400"><span><?= (int) $album['photo_count'] ?> รูป</span><span><?= date('d/m/Y', strtotime($album['created_at'])) ?></span></div><div class="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-3"><button type="button" onclick="openAlbumModal(<?= (int) $album['album_id'] ?>)" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">รายละเอียด</button><button type="button" onclick="openGalleryActionMenu(this, <?= (int) $album['album_id'] ?>)" class="gallery-action-toggle rounded-lg bg-brand-orange px-3 py-2 text-xs font-bold text-white" aria-expanded="false">จัดการ</button></div><div class="gallery-action-menu fixed z-[70] hidden w-52 rounded-xl border border-slate-200 bg-white p-2 shadow-xl"><button type="button" onclick="openGalleryFormModal('activity', <?= (int) $album['album_id'] ?>)" class="gallery-action-item">เพิ่มรูปภาพ</button><button type="button" onclick="openAlbumEditModal(<?= (int) $album['album_id'] ?>)" class="gallery-action-item">แก้ไขอัลบั้ม</button><form method="POST" onsubmit="return confirm('ยืนยันลบอัลบั้มและรูปทั้งหมดหรือไม่?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="delete_album"><input type="hidden" name="album_id" value="<?= (int) $album['album_id'] ?>"><button type="submit" class="gallery-action-item text-rose-600">ลบ/เก็บอัลบั้ม</button></form></div></article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    <?php if (!$banners): ?><div class="col-span-full rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500">ยังไม่มีแบนเนอร์ประชาสัมพันธ์<div class="mt-4"><button type="button" onclick="openGalleryFormModal('banner')" class="rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white">+ เพิ่มแบนเนอร์</button></div></div><?php endif; ?>
                    <?php foreach ($banners as $banner): ?><article class="relative rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="aspect-[16/7] overflow-hidden rounded-xl bg-slate-100"><img src="../assets/<?= htmlspecialchars($banner['image_path']) ?>" alt="<?= htmlspecialchars($banner['title'] ?: 'แบนเนอร์') ?>" class="h-full w-full object-cover"></div><h3 class="mt-3 truncate font-bold text-slate-900"><?= htmlspecialchars($banner['title'] ?: 'ไม่มีหัวข้อ') ?></h3><p class="mt-1 line-clamp-2 text-xs text-slate-500"><?= htmlspecialchars($banner['caption'] ?? '') ?></p><div class="mt-3 flex items-center justify-between"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold <?= (int) $banner['is_active'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>"><?= (int) $banner['is_active'] ? 'กำลังแสดง' : 'ปิดการแสดง' ?></span><button type="button" onclick="openBannerPreview(<?= (int) $banner['gallery_id'] ?>)" class="text-xs font-bold text-brand-orange">ดูตัวอย่าง</button></div><div class="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-3"><button type="button" onclick="openBannerPreview(<?= (int) $banner['gallery_id'] ?>)" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">รายละเอียด</button><button type="button" onclick="openGalleryActionMenu(this)" class="gallery-action-toggle rounded-lg bg-brand-orange px-3 py-2 text-xs font-bold text-white" aria-expanded="false">จัดการ</button></div><div class="gallery-action-menu fixed z-[70] hidden w-52 rounded-xl border border-slate-200 bg-white p-2 shadow-xl"><button type="button" onclick="openBannerEditModal(<?= (int) $banner['gallery_id'] ?>)" class="gallery-action-item">แก้ไขแบนเนอร์</button><button type="button" onclick="openBannerPreview(<?= (int) $banner['gallery_id'] ?>)" class="gallery-action-item">ดูตัวอย่าง</button><form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="update_media"><input type="hidden" name="media_id" value="<?= (int) $banner['gallery_id'] ?>"><input type="hidden" name="media_title" value="<?= htmlspecialchars($banner['title'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="caption" value="<?= htmlspecialchars($banner['caption'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="is_active" value="<?= (int) $banner['is_active'] ? '0' : '1' ?>"><button type="submit" class="gallery-action-item"> <?= (int) $banner['is_active'] ? 'ปิดการแสดงผล' : 'เปิดการแสดงผล' ?></button></form><div class="my-1 border-t border-slate-100"></div><form method="POST" onsubmit="return confirm('ยืนยันลบแบนเนอร์นี้หรือไม่?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="delete_media"><input type="hidden" name="media_id" value="<?= (int) $banner['gallery_id'] ?>"><button type="submit" class="gallery-action-item text-rose-600">ลบแบนเนอร์</button></form></div></article><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (false): ?>

            <!-- ================= UNIFIED FORM: ฟอร์มรวมอัปโหลดรูป + สร้างอัลบั้ม ================= -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-2 border-b border-slate-100 pb-3">
                    <i class="fa-solid fa-cloud-arrow-up text-brand-orange"></i> อัปโหลดรูปภาพ / สร้างอัลบั้มใหม่
                </h2>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_gallery">
                    <input type="hidden" name="media_type" value="activity">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- ตัวเลือกอัลบั้ม -->
                        <div id="albumField">
                            <label class="block text-xs font-bold text-slate-700 mb-1">เลือกโฟลเดอร์อัลบั้ม:</label>
                            <select name="album_option" id="albumSelect" onchange="toggleNewAlbumFields()" required 
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs font-bold focus:bg-white focus:outline-none focus:border-brand-orange">
                                <option value="new">➕ [สร้างอัลบั้ม/โฟลเดอร์ใหม่]</option>
                                <?php foreach ($activityAlbums as $a): ?>
                                    <option value="<?php echo $a['album_id']; ?>" <?php echo $selectedAlbumId == $a['album_id'] ? 'selected' : ''; ?>>
                                        📁 <?php echo htmlspecialchars($a['title']); ?> (<?php echo $a['photo_count']; ?> รูป)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- คำบรรยายรูป -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">คำบรรยายรูปภาพ (ไม่บังคับ):</label>
                            <input type="text" name="caption" placeholder="เช่น ภาพบรรยากาศรอบชิงชนะเลิศ..."
                                   class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs focus:bg-white focus:outline-none focus:border-brand-orange font-medium">
                        </div>
                    </div>

                    <!-- ช่องกรอกชื่ออัลบั้มใหม่ (จะซ่อนเมื่อเลือกอัลบั้มเดิม) -->
                    <div id="newAlbumBox" class="p-4 rounded-xl bg-orange-50/50 border border-orange-200 space-y-3">
                        <div class="text-xs font-bold text-brand-orange flex items-center gap-1.5">
                            <i class="fa-solid fa-folder-plus"></i> ข้อมูลอัลบั้มใหม่ที่ต้องการสร้าง
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">ชื่ออัลบั้ม / กิจกรรม: <span class="text-rose-500">*</span></label>
                                <input type="text" name="new_album_name" id="newAlbumNameInput" placeholder="เช่น KORAT RoV OPEN 2026"
                                       class="w-full bg-white border border-slate-200 rounded-xl p-2.5 text-xs focus:outline-none focus:border-brand-orange font-medium">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-700 mb-1">รายละเอียดอัลบั้ม (ไม่บังคับ):</label>
                                <input type="text" name="new_album_desc" placeholder="คำอธิบายสั้นๆ..."
                                       class="w-full bg-white border border-slate-200 rounded-xl p-2.5 text-xs focus:outline-none focus:border-brand-orange font-medium">
                            </div>
                        </div>
                    </div>

                    <!-- อัปโหลดไฟล์รูปภาพ -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">เลือกรูปภาพ (เลือกพร้อมกันได้หลายรูป):</label>
                        <input type="file" name="photos[]" multiple accept="image/*" required
                               class="w-full text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-xl p-2 focus:outline-none focus:border-brand-orange">
                    </div>

                    <!-- ปุ่มส่งฟอร์ม -->
                    <button type="submit" class="w-full py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs uppercase shadow-md flex items-center justify-center gap-2 cursor-pointer transition-all">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <span>บันทึกข้อมูล & อัปโหลดรูปภาพ</span>
                    </button>
                </form>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-orange-200 shadow-sm space-y-4">
                <h2 class="text-xs font-bold tracking-wider text-slate-700 flex items-center gap-2 border-b border-slate-100 pb-3">
                    <i class="fa-solid fa-bullhorn text-brand-orange"></i> สร้างแบนเนอร์ประชาสัมพันธ์
                </h2>
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_gallery">
                    <input type="hidden" name="media_type" value="banner">
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">อัลบั้มแบนเนอร์</label>
                        <select name="banner_album_option" id="bannerAlbumSelect" onchange="toggleBannerAlbumFields()" required class="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-xs font-bold focus:bg-white focus:outline-none focus:border-brand-orange">
                            <option value="new">สร้างอัลบั้มแบนเนอร์ใหม่</option>
                            <?php foreach ($bannerAlbums as $bannerAlbum): ?>
                                <option value="<?php echo (int) $bannerAlbum['album_id']; ?>"><?php echo htmlspecialchars($bannerAlbum['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="newBannerAlbumBox" class="p-3 rounded-lg bg-orange-50/60 border border-orange-200 space-y-2">
                        <input type="text" name="new_banner_album_name" id="newBannerAlbumName" required placeholder="ชื่ออัลบั้มแบนเนอร์" class="w-full bg-white border border-slate-200 rounded-lg p-2.5 text-xs">
                        <input type="text" name="new_banner_album_desc" placeholder="รายละเอียดอัลบั้ม (ไม่บังคับ)" class="w-full bg-white border border-slate-200 rounded-lg p-2.5 text-xs">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">หัวข้อแบนเนอร์ <span class="text-rose-500">*</span></label>
                        <input type="text" name="media_title" required placeholder="เช่น เปิดรับสมัครการแข่งขัน" class="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-xs focus:bg-white focus:outline-none focus:border-brand-orange">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">คำบรรยาย</label>
                        <input type="text" name="caption" placeholder="รายละเอียดสั้น ๆ ของประชาสัมพันธ์" class="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-xs focus:bg-white focus:outline-none focus:border-brand-orange">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">ไฟล์แบนเนอร์ <span class="text-rose-500">*</span></label>
                        <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" required class="w-full text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg p-2">
                    </div>
                    <button type="submit" class="w-full py-3 rounded-lg bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs"><i class="fa-solid fa-cloud-arrow-up"></i> อัปโหลดแบนเนอร์</button>
                </form>
            </div>
            </div>

            <!-- ALBUM FOLDERS LIST -->
            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
            <div>
            <div class="p-0 space-y-4 h-full">
                <div class="flex items-center justify-between border-b border-slate-300 pb-3">
                    <h2 class="text-xs font-bold text-slate-700 flex items-center gap-2">
                        <i class="fa-solid fa-folder-open text-amber-500"></i> อัลบั้มรูปกิจกรรม (<?php echo count($activityAlbums); ?> โฟลเดอร์)
                    </h2>
                    <?php if ($selectedAlbumId > 0): ?>
                        <a href="manage-gallery.php" class="text-xs font-bold text-brand-orange hover:underline">
                            <i class="fa-solid fa-rotate-left"></i> แสดงรูปภาพจากทุกอัลบั้ม
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                    <?php foreach ($activityAlbums as $alb): ?>
                        <div class="bg-white rounded-xl border border-slate-200 p-3 space-y-2 shadow-sm hover:border-brand-orange hover:shadow-md transition-all group">
                            <div class="space-y-2">
                                <div class="h-28 rounded-lg bg-slate-100 overflow-hidden relative border border-slate-100 flex items-center justify-center">
                                    <?php if (!empty($alb['cover_image']) && file_exists('../assets/' . $alb['cover_image'])): ?>
                                        <img src="../assets/<?php echo htmlspecialchars($alb['cover_image']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                    <?php else: ?>
                                        <i class="fa-solid fa-folder text-4xl text-amber-400"></i>
                                    <?php endif; ?>

                                    <span class="absolute top-2 right-2 bg-slate-900/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full backdrop-blur-sm">
                                        <?php echo $alb['photo_count']; ?> รูป
                                    </span>
                                </div>

                                <div>
                                    <h3 class="font-bold text-xs text-slate-900 line-clamp-1 group-hover:text-brand-orange"><?php echo htmlspecialchars($alb['title']); ?></h3>
                                    <p class="text-[10px] text-slate-400"><?php echo date('d/m/Y', strtotime($alb['created_at'])); ?></p>
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-1 pt-3 mt-2 border-t border-slate-100 text-[11px]">
                                <a href="?view_album=<?php echo $alb['album_id']; ?>" class="font-bold text-slate-600 hover:text-brand-orange">
                                    <i class="fa-solid fa-images mr-1"></i> ดูรูปในอัลบั้ม
                                </a>
                                <form method="POST" onsubmit="return confirm('ยืนยันลบอัลบั้มนี้และรูปภาพทั้งหมดในอัลบั้มใช่หรือไม่?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete_album">
                                    <input type="hidden" name="album_id" value="<?php echo (int) $alb['album_id']; ?>">
                                    <button type="submit" class="text-rose-500 hover:text-rose-700 p-1"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            </div>
            <div>
            <div class="p-0 space-y-4 h-full">
                <h2 class="text-xs font-bold text-slate-700 flex items-center gap-2 border-b border-slate-300 pb-3">
                    <i class="fa-solid fa-bullhorn text-brand-orange"></i> อัลบั้มแบนเนอร์ประชาสัมพันธ์ (<?php echo count($bannerAlbums); ?> อัลบั้ม)
                </h2>
                <?php if (!$bannerAlbums): ?>
                    <p class="text-center py-6 text-xs text-slate-400">ยังไม่มีอัลบั้มแบนเนอร์</p>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($bannerAlbums as $bannerAlbum): ?>
                            <div class="bg-white rounded-xl border border-slate-200 p-3 space-y-2 shadow-sm hover:border-brand-orange hover:shadow-md transition-all">
                                <div class="h-28 rounded-lg overflow-hidden bg-slate-100 flex items-center justify-center">
                                    <?php if (!empty($bannerAlbum['cover_image']) && file_exists('../assets/' . $bannerAlbum['cover_image'])): ?>
                                        <img src="../assets/<?php echo htmlspecialchars($bannerAlbum['cover_image']); ?>" class="w-full h-full object-cover" alt="<?php echo htmlspecialchars($bannerAlbum['title']); ?>">
                                    <?php else: ?><i class="fa-solid fa-bullhorn text-2xl text-brand-orange"></i><?php endif; ?>
                                </div>
                                <p class="text-xs font-bold text-slate-800 line-clamp-1"><?php echo htmlspecialchars($bannerAlbum['title']); ?></p>
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[10px] text-slate-400"><?php echo (int) $bannerAlbum['photo_count']; ?> รูป</p>
                                    <form method="POST" onsubmit="return confirm('ยืนยันลบอัลบั้มแบนเนอร์นี้และรูปทั้งหมดใช่หรือไม่?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete_album">
                                        <input type="hidden" name="album_id" value="<?php echo (int) $bannerAlbum['album_id']; ?>">
                                        <button type="submit" class="text-rose-500 hover:text-rose-700 p-1" title="ลบอัลบั้มแบนเนอร์"><i class="fa-solid fa-trash-can"></i></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
            </div>
            </div>

            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <h2 class="text-xs font-bold text-slate-700 flex items-center gap-2 border-b border-slate-100 pb-3 pt-4">
                    <i class="fa-solid fa-images text-brand-orange"></i> รายการรูปภาพแบนเนอร์ (<?php echo count($banners); ?> รูป)
                </h2>
                <?php if (!$banners): ?>
                    <p class="text-center py-8 text-xs text-slate-400">ยังไม่มีรูปภาพแบนเนอร์</p>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($banners as $banner): ?>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 space-y-2 relative group">
                                <div class="h-32 rounded-lg overflow-hidden bg-slate-200">
                                    <img src="../assets/<?php echo htmlspecialchars($banner['image_path']); ?>" alt="<?php echo htmlspecialchars($banner['title'] ?: 'แบนเนอร์'); ?>" class="w-full h-full object-cover">
                                </div>
                                <p class="text-[10px] font-bold text-brand-orange line-clamp-1">📁 <?php echo htmlspecialchars($banner['album_title'] ?? 'อัลบั้มแบนเนอร์'); ?></p>
                                <p class="text-xs font-semibold text-slate-700 line-clamp-1"><?php echo htmlspecialchars($banner['title'] ?: 'ไม่มีหัวข้อ'); ?></p>
                                <form method="POST" onsubmit="return confirm('ยืนยันลบแบนเนอร์นี้ใช่หรือไม่?')" class="flex justify-end pt-1">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete_media">
                                    <input type="hidden" name="media_id" value="<?php echo (int) $banner['gallery_id']; ?>">
                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-rose-50 border border-rose-200 text-rose-600 text-[11px] font-bold"><i class="fa-solid fa-trash"></i> ลบ</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PHOTOS GRID SHOWCASE -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <h2 class="text-xs font-bold text-slate-700 uppercase flex items-center justify-between border-b border-slate-100 pb-3">
                    <span><i class="fa-solid fa-images text-brand-orange mr-1"></i> รายการรูปภาพ (<?php echo count($galleryPhotos); ?> รูป)</span>
                    <?php if ($selectedAlbumId > 0): ?>
                        <span class="text-xs font-bold text-brand-orange bg-orange-50 px-3 py-1 rounded-full border border-orange-200">
                            กำลังแสดงรูปเฉพาะโฟลเดอร์นี้
                        </span>
                    <?php endif; ?>
                </h2>

                <?php if (count($galleryPhotos) == 0): ?>
                    <div class="text-center py-12 text-slate-400 text-xs">ยังไม่มีรูปภาพในอัลบั้มนี้</div>
                <?php else: ?>
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                        <?php foreach ($galleryPhotos as $photo): ?>
                            <div class="group bg-slate-50 rounded-2xl overflow-hidden border border-slate-200 relative shadow-sm">
                                <div class="h-36 overflow-hidden">
                                    <img src="../assets/<?php echo htmlspecialchars($photo['image_path']); ?>" alt="Gallery Image" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                                </div>

                                <div class="p-2 space-y-1">
                                    <p class="text-[10px] font-bold text-brand-orange line-clamp-1">📁 <?php echo htmlspecialchars($photo['album_title'] ?? 'ไม่ระบุอัลบั้ม'); ?></p>
                                    <?php if (!empty($photo['caption'])): ?>
                                        <p class="text-[11px] text-slate-600 line-clamp-1"><?php echo htmlspecialchars($photo['caption']); ?></p>
                                    <?php endif; ?>
                                </div>

                                <!-- ปุ่มลบรูป -->
                                <form method="POST" onsubmit="return confirm('ยืนยันลบรูปภาพนี้ใช่หรือไม่?')" class="absolute top-2 right-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete_media">
                                    <input type="hidden" name="media_id" value="<?php echo (int) $photo['gallery_id']; ?>">
                                    <button type="submit" class="w-7 h-7 rounded-xl bg-rose-600 text-white flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity shadow-md"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </main>
    </div>

    <div id="galleryFormModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/70 p-4"><div class="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 id="galleryFormTitle" class="font-bold text-slate-900">สร้างอัลบั้ม</h3><button type="button" onclick="closeGalleryFormModal()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" enctype="multipart/form-data" class="space-y-4 p-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="save_gallery"><input type="hidden" name="media_type" id="galleryFormMediaType" value="activity"><input type="hidden" name="album_option" id="galleryFormAlbumOption" value="new"><input type="hidden" name="banner_album_option" id="galleryFormBannerAlbumOption" value="new"><div id="activityFormFields"><label class="mb-1 block text-xs font-bold text-slate-700">ชื่ออัลบั้ม/กิจกรรม</label><input name="new_album_name" id="galleryAlbumName" required maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><label class="mb-1 mt-3 block text-xs font-bold text-slate-700">รายละเอียดอัลบั้ม</label><textarea name="new_album_desc" id="galleryAlbumDesc" rows="3" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"></textarea></div><div id="bannerFormFields" class="hidden"><label class="mb-1 block text-xs font-bold text-slate-700">หัวข้อแบนเนอร์</label><input name="media_title" id="galleryBannerTitle" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><label class="mb-1 mt-3 block text-xs font-bold text-slate-700">คำบรรยาย</label><input name="caption" id="galleryBannerCaption" maxlength="200" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"><input type="hidden" name="new_banner_album_name" value="แบนเนอร์ประชาสัมพันธ์"><input type="hidden" name="new_banner_album_desc" value=""></div><div><label class="mb-1 block text-xs font-bold text-slate-700">รูปภาพ <span class="font-normal text-slate-400">JPG, PNG, WEBP ไม่เกิน 5MB ต่อไฟล์</span></label><input type="file" name="photos[]" id="galleryPhotosInput" multiple required accept="image/jpeg,image/png,image/webp" onchange="previewGalleryFiles(this)" class="w-full text-xs"><div id="galleryFileInfo" class="mt-2 text-xs text-slate-500"></div><div id="galleryFilePreview" class="mt-3 grid grid-cols-3 gap-2"></div></div><div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onclick="closeGalleryFormModal()" class="rounded-xl bg-slate-100 px-4 py-2.5 text-xs font-bold text-slate-700">ยกเลิก</button><button type="submit" class="rounded-xl bg-brand-orange px-4 py-2.5 text-xs font-bold text-white hover:bg-brand-glow">บันทึกและอัปโหลด</button></div></form></div></div>

    <div id="albumEditModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 class="font-bold text-slate-900">แก้ไขอัลบั้ม</h3><button type="button" onclick="closeAlbumEditModal()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" class="space-y-4 p-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="update_album"><input type="hidden" name="album_id" id="albumEditId"><input name="album_title" id="albumEditTitle" required maxlength="255" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><textarea name="album_description" id="albumEditDescription" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea><div class="flex justify-end gap-2"><button type="button" onclick="closeAlbumEditModal()" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold">ยกเลิก</button><button type="submit" class="rounded-lg bg-brand-orange px-4 py-2 text-xs font-bold text-white">บันทึก</button></div></form></div></div>

    <div id="albumViewModal" class="fixed inset-0 z-[55] hidden items-center justify-center bg-slate-900/70 p-4"><div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><div><h3 id="albumViewTitle" class="font-bold text-slate-900"></h3><p id="albumViewMeta" class="text-xs text-slate-500"></p></div><button type="button" onclick="closeAlbumModal()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><div id="albumViewContent" class="overflow-y-auto p-6"></div><div class="flex justify-end border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" onclick="closeAlbumModal()" class="rounded-lg bg-slate-200 px-4 py-2 text-xs font-bold">ปิด</button></div></div></div>

    <div id="bannerEditModal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4"><div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 py-4"><h3 class="font-bold text-slate-900">จัดการแบนเนอร์</h3><button type="button" onclick="closeBannerEditModal()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><form method="POST" class="space-y-4 p-6"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="update_media"><input type="hidden" name="media_id" id="bannerEditId"><input name="media_title" id="bannerEditTitle" maxlength="255" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><textarea name="caption" id="bannerEditCaption" maxlength="200" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea><label class="flex items-center gap-2 text-xs font-bold"><input type="checkbox" name="is_active" id="bannerEditActive" value="1"> กำลังแสดง</label><div class="flex justify-end gap-2"><button type="button" onclick="closeBannerEditModal()" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold">ยกเลิก</button><button type="submit" class="rounded-lg bg-brand-orange px-4 py-2 text-xs font-bold text-white">บันทึก</button></div></form></div></div>

    <div id="bannerPreviewModal" class="fixed inset-0 z-[65] hidden items-center justify-center bg-slate-900/70 p-4"><div class="w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl"><div class="flex items-center justify-between border-b border-slate-200 px-6 py-4"><h3 class="font-bold">ตัวอย่างแบนเนอร์</h3><button type="button" onclick="closeBannerPreview()" class="text-slate-400"><i class="fa-solid fa-xmark"></i></button></div><div class="p-6"><img id="bannerPreviewImage" class="aspect-[16/7] w-full rounded-xl object-cover"><h4 id="bannerPreviewTitle" class="mt-4 text-lg font-bold"></h4><p id="bannerPreviewCaption" class="mt-1 text-sm text-slate-500"></p></div></div></div>

    <script>
        const activityPhotosByAlbum = <?= json_encode($activityPhotosByAlbum, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const activityAlbums = <?= json_encode(array_column($activityAlbums, null, 'album_id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const bannersData = <?= json_encode(array_column($banners, null, 'gallery_id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        function showModal(id) { const modal = document.getElementById(id); modal.classList.remove('hidden'); modal.classList.add('flex'); }
        function hideModal(id) { const modal = document.getElementById(id); modal.classList.add('hidden'); modal.classList.remove('flex'); }
        function openGalleryActionMenu(button) { const menu = button.parentElement.parentElement.querySelector('.gallery-action-menu'); if (!menu) return; document.querySelectorAll('.gallery-action-menu').forEach(item => item.classList.add('hidden')); const opening = menu.classList.contains('hidden'); if (!opening) return; document.body.appendChild(menu); menu.classList.remove('hidden'); const rect = button.getBoundingClientRect(); const width = menu.offsetWidth || 208; const height = menu.offsetHeight || 180; menu.style.left = `${Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8))}px`; menu.style.top = `${rect.bottom + height + 8 <= window.innerHeight - 8 ? rect.bottom + 8 : Math.max(8, rect.top - height - 8)}px`; button.setAttribute('aria-expanded', 'true'); }
        function openGalleryFormModal(type = 'activity', albumId = 0) { document.getElementById('galleryFormMediaType').value = type; document.getElementById('galleryFormTitle').textContent = type === 'activity' ? (albumId ? 'เพิ่มรูปเข้าอัลบั้ม' : 'สร้างอัลบั้ม') : 'เพิ่มแบนเนอร์'; document.getElementById('activityFormFields').classList.toggle('hidden', type !== 'activity' || albumId > 0); document.getElementById('bannerFormFields').classList.toggle('hidden', type !== 'banner'); document.getElementById('galleryFormAlbumOption').value = albumId || 'new'; document.getElementById('galleryFormBannerAlbumOption').value = 'new'; document.getElementById('galleryAlbumName').required = type === 'activity' && !albumId; document.getElementById('galleryBannerTitle').required = type === 'banner'; showModal('galleryFormModal'); }
        function closeGalleryFormModal() { hideModal('galleryFormModal'); }
        function previewGalleryFiles(input) { const files = [...(input.files || [])]; document.getElementById('galleryFileInfo').textContent = files.length + ' รูปที่เลือก: ' + files.map(file => file.name).join(', '); document.getElementById('galleryFilePreview').innerHTML = ''; files.forEach(file => { if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) return; const reader = new FileReader(); reader.onload = event => { const item = document.createElement('img'); item.src = event.target.result; item.className = 'h-24 w-full rounded-lg object-cover'; document.getElementById('galleryFilePreview').appendChild(item); }; reader.readAsDataURL(file); }); }
        function openAlbumModal(albumId) { const album = activityAlbums[albumId]; const photos = activityPhotosByAlbum[albumId] || []; document.getElementById('albumViewTitle').textContent = album ? album.title : 'อัลบั้ม'; document.getElementById('albumViewMeta').textContent = (album?.description || '') + ' | ' + photos.length + ' รูป'; document.getElementById('albumViewContent').innerHTML = photos.length ? `<div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">${photos.map(photo => `<div class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50"><img src="../assets/${photo.image_path}" alt="" class="h-36 w-full object-cover"><div class="p-2"><div class="truncate text-[11px] text-slate-600">${photo.caption || ''}</div><form method="POST" class="mt-2" onsubmit="return confirm('ยืนยันลบรูปนี้หรือไม่?')"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="delete_media"><input type="hidden" name="media_id" value="${photo.gallery_id}"><button type="submit" class="w-full rounded-lg bg-rose-50 px-2 py-1.5 text-[10px] font-bold text-rose-600">ลบรูป</button></form></div></div>`).join('')}</div>` : '<div class="p-12 text-center text-sm text-slate-500">ยังไม่มีรูปในอัลบั้มนี้</div>'; showModal('albumViewModal'); }
        function closeAlbumModal() { hideModal('albumViewModal'); }
        function openAlbumEditModal(albumId) { const album = activityAlbums[albumId]; if (!album) return; document.getElementById('albumEditId').value = albumId; document.getElementById('albumEditTitle').value = album.title || ''; document.getElementById('albumEditDescription').value = album.description || ''; showModal('albumEditModal'); }
        function closeAlbumEditModal() { hideModal('albumEditModal'); }
        function openBannerEditModal(mediaId) { const banner = bannersData[mediaId]; if (!banner) return; document.getElementById('bannerEditId').value = mediaId; document.getElementById('bannerEditTitle').value = banner.title || ''; document.getElementById('bannerEditCaption').value = banner.caption || ''; document.getElementById('bannerEditActive').checked = Number(banner.is_active) === 1; showModal('bannerEditModal'); }
        function closeBannerEditModal() { hideModal('bannerEditModal'); }
        function openBannerPreview(mediaId) { const banner = bannersData[mediaId]; if (!banner) return; document.getElementById('bannerPreviewImage').src = '../assets/' + banner.image_path; document.getElementById('bannerPreviewTitle').textContent = banner.title || ''; document.getElementById('bannerPreviewCaption').textContent = banner.caption || ''; showModal('bannerPreviewModal'); }
        function closeBannerPreview() { hideModal('bannerPreviewModal'); }

        document.addEventListener('DOMContentLoaded', () => { document.querySelectorAll('.gallery-action-menu').forEach(menu => menu.addEventListener('click', event => event.stopPropagation())); document.addEventListener('click', () => document.querySelectorAll('.gallery-action-menu').forEach(menu => menu.classList.add('hidden'))); document.querySelectorAll('[id$="Modal"]').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) hideModal(modal.id); })); document.addEventListener('keydown', event => { if (event.key === 'Escape') { document.querySelectorAll('.gallery-action-menu').forEach(menu => menu.classList.add('hidden')); document.querySelectorAll('[id$="Modal"]').forEach(modal => hideModal(modal.id)); } }); });
    </script>
</body>
</html>