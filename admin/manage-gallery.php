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

// ================= AUTO SETUP: ตรวจสอบและสร้างตาราง/คอลัมน์ให้อัตโนมัติ =================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gallery_albums (
            album_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $chkCol = $pdo->query("SHOW COLUMNS FROM gallery LIKE 'album_id'")->fetch();
    if (!$chkCol) {
        $pdo->exec("ALTER TABLE gallery ADD COLUMN album_id INT NULL AFTER gallery_id;");
    }
} catch (Exception $e) {
    // ซ่อน Error การ Setup
}

// ================= 1. ประมวลผลสร้างอัลบั้ม + อัปโหลดรูปพร้อมกันในขั้นตอนเดียว =================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_gallery') {
    $albumOption = trim($_POST['album_option'] ?? '');
    $newAlbumName = trim($_POST['new_album_name'] ?? '');
    $newAlbumDesc = trim($_POST['new_album_desc'] ?? '');
    $caption = trim($_POST['caption'] ?? '');
    $adminUserId = (int) ($_SESSION['user_id'] ?? 0);

    $albumId = 0;

    // 1.1 ตรวจสอบกรณีเลือกสร้างอัลบั้มใหม่
    if ($albumOption === 'new') {
        if (empty($newAlbumName)) {
            $error = 'กรุณาระบุชื่ออัลบั้ม/โฟลเดอร์ใหม่';
        } else {
            $ins = $pdo->prepare("INSERT INTO gallery_albums (title, description) VALUES (:title, :desc)");
            $ins->execute(['title' => $newAlbumName, 'desc' => $newAlbumDesc]);
            $albumId = (int) $pdo->lastInsertId();
        }
    } else {
        $albumId = (int) $albumOption;
    }

    // 1.2 ดำเนินการอัปโหลดรูปภาพ
    if (empty($error)) {
        if ($albumId <= 0) {
            $error = 'กรุณาเลือกอัลบั้ม หรือสร้างอัลบั้มใหม่';
        } elseif (!isset($_FILES['photos']) || empty($_FILES['photos']['name'][0])) {
            $error = 'กรุณาเลือกรูปภาพอย่างน้อย 1 รูป';
        } else {
            $uploadDir = "../assets/uploads/gallery/album_{$albumId}/";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            $uploadedCount = 0;

            foreach ($_FILES['photos']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['photos']['name'][$key], PATHINFO_EXTENSION));

                    if (in_array($ext, $allowed)) {
                        $fileName = 'img_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        $targetFile = $uploadDir . $fileName;

                        if (move_uploaded_file($tmpName, $targetFile)) {
                            $relativePath = "uploads/gallery/album_{$albumId}/" . $fileName;

                            try {
                                $stmt = $pdo->prepare("INSERT INTO gallery (album_id, image_path, caption, created_by, created_at) VALUES (:aid, :path, :cap, :uid, NOW())");
                                $stmt->execute(['aid' => $albumId, 'path' => $relativePath, 'cap' => $caption, 'uid' => $adminUserId]);
                            } catch (Exception $e) {
                                $stmt = $pdo->prepare("INSERT INTO gallery (album_id, image_path, caption, created_at) VALUES (:aid, :path, :cap, NOW())");
                                $stmt->execute(['aid' => $albumId, 'path' => $relativePath, 'cap' => $caption]);
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

// ================= 2. ลบรูปภาพ =================
if (isset($_GET['delete_photo_id'])) {
    $photoId = (int) $_GET['delete_photo_id'];
    $stmt = $pdo->prepare("SELECT image_path FROM gallery WHERE gallery_id = :id");
    $stmt->execute(['id' => $photoId]);
    $photo = $stmt->fetch();

    if ($photo) {
        $filePath = '../assets/' . $photo['image_path'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $pdo->prepare("DELETE FROM gallery WHERE gallery_id = :id")->execute(['id' => $photoId]);
        $success = 'ลบรูปภาพเรียบร้อยแล้ว';
    }
}

// ================= 3. ลบอัลบั้ม (พร้อมลบรูปทั้งหมดในอัลบั้ม) =================
if (isset($_GET['delete_album_id'])) {
    $delAlbumId = (int) $_GET['delete_album_id'];

    $stmt = $pdo->prepare("SELECT image_path FROM gallery WHERE album_id = :aid");
    $stmt->execute(['aid' => $delAlbumId]);
    $photos = $stmt->fetchAll();

    foreach ($photos as $p) {
        $filePath = '../assets/' . $p['image_path'];
        if (file_exists($filePath)) { @unlink($filePath); }
    }

    $dirPath = "../assets/uploads/gallery/album_{$delAlbumId}/";
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

// รับค่าฟิลเตอร์อัลบั้มที่เลือกดู
$selectedAlbumId = (int) ($_GET['view_album'] ?? 0);

// ดึงรูปภาพตามอัลบั้ม
if ($selectedAlbumId > 0) {
    $pStmt = $pdo->prepare("SELECT g.*, a.title AS album_title FROM gallery g JOIN gallery_albums a ON a.album_id = g.album_id WHERE g.album_id = :aid ORDER BY g.gallery_id DESC");
    $pStmt->execute(['aid' => $selectedAlbumId]);
    $galleryPhotos = $pStmt->fetchAll();
} else {
    $galleryPhotos = $pdo->query("SELECT g.*, a.title AS album_title FROM gallery g LEFT JOIN gallery_albums a ON a.album_id = g.album_id ORDER BY g.gallery_id DESC")->fetchAll();
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
                <p class="text-xs text-slate-500 mt-0.5">สร้างโฟลเดอร์อัลบั้มและอัปโหลดรูปภาพได้ครบจบในขั้นตอนเดียว</p>
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

            <!-- ================= UNIFIED FORM: ฟอร์มรวมอัปโหลดรูป + สร้างอัลบั้ม ================= -->
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-700 flex items-center gap-2 border-b border-slate-100 pb-3">
                    <i class="fa-solid fa-cloud-arrow-up text-brand-orange"></i> อัปโหลดรูปภาพ / สร้างอัลบั้มใหม่
                </h2>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="save_gallery">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- ตัวเลือกอัลบั้ม -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">เลือกโฟลเดอร์อัลบั้ม:</label>
                            <select name="album_option" id="albumSelect" onchange="toggleNewAlbumFields()" required 
                                    class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-xs font-bold focus:bg-white focus:outline-none focus:border-brand-orange">
                                <option value="new">➕ [สร้างอัลบั้ม/โฟลเดอร์ใหม่]</option>
                                <?php foreach ($albums as $a): ?>
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

            <!-- ALBUM FOLDERS LIST -->
            <div class="space-y-4 pt-2">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-xs font-bold text-slate-700 uppercase flex items-center gap-2">
                        <i class="fa-solid fa-folder-open text-amber-500"></i> อัลบั้มทั้งหมดในระบบ (<?php echo count($albums); ?> โฟลเดอร์)
                    </h2>
                    <?php if ($selectedAlbumId > 0): ?>
                        <a href="manage-gallery.php" class="text-xs font-bold text-brand-orange hover:underline">
                            <i class="fa-solid fa-rotate-left"></i> แสดงรูปภาพจากทุกอัลบั้ม
                        </a>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    <?php foreach ($albums as $alb): ?>
                        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-3 flex flex-col justify-between hover:border-brand-orange transition-all group">
                            <div class="space-y-2">
                                <div class="h-28 rounded-xl bg-slate-100 overflow-hidden relative border border-slate-100 flex items-center justify-center">
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
                                <a href="?delete_album_id=<?php echo $alb['album_id']; ?>" 
                                   onclick="return confirm('ยืนยันลบอัลบั้มนี้และรูปภาพทั้งหมดในอัลบั้มใช่หรือไม่?')"
                                   class="text-rose-500 hover:text-rose-700 p-1">
                                    <i class="fa-solid fa-trash-can"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
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
                                    <?php if ($photo['caption']): ?>
                                        <p class="text-[11px] text-slate-600 line-clamp-1"><?php echo htmlspecialchars($photo['caption']); ?></p>
                                    <?php endif; ?>
                                </div>

                                <!-- ปุ่มลบรูป -->
                                <a href="?delete_photo_id=<?php echo $photo['gallery_id']; ?><?php echo $selectedAlbumId > 0 ? '&view_album=' . $selectedAlbumId : ''; ?>" 
                                   onclick="return confirm('ยืนยันลบรูปภาพนี้ใช่หรือไม่?')"
                                   class="absolute top-2 right-2 w-7 h-7 rounded-xl bg-rose-600 text-white flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition-opacity shadow-md">
                                    <i class="fa-solid fa-trash-can"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <!-- JavaScript สำหรับสลับโหมดสร้างอัลบั้มใหม่ -->
    <script>
        function toggleNewAlbumFields() {
            const select = document.getElementById('albumSelect');
            const newBox = document.getElementById('newAlbumBox');
            const nameInput = document.getElementById('newAlbumNameInput');

            if (select.value === 'new') {
                newBox.style.display = 'block';
                nameInput.required = true;
            } else {
                newBox.style.display = 'none';
                nameInput.required = false;
            }
        }

        // เรียกทำงานทันทีเมื่อโหลดหน้าเว็บ
        document.addEventListener('DOMContentLoaded', toggleNewAlbumFields);
    </script>
</body>
</html>