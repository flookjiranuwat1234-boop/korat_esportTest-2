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

// เพิ่ม/แก้ไขข่าว
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'save') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $newsId = (int) ($_POST['news_id'] ?? 0);
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $status = $_POST['status'];

        if ($title == '' || $content == '') {
            $error = 'กรุณากรอกหัวข้อและเนื้อหาข่าว';
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

// ลบข่าว
if (isset($_GET['delete'])) {
    $newsId = (int) $_GET['delete'];
    $stmt = $pdo->prepare("SELECT image_path FROM news WHERE news_id = :id");
    $stmt->execute(['id' => $newsId]);
    $imagePath = $stmt->fetchColumn();

    $pdo->prepare("DELETE FROM news WHERE news_id = :id")->execute(['id' => $newsId]);
    deleteUploadedImage($imagePath);
    $success = 'ลบข่าวเรียบร้อยแล้ว';
}

// โหลดข่าวที่จะแก้ไข (ถ้ามี ?edit=)
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM news WHERE news_id = :id");
    $stmt->execute(['id' => (int) $_GET['edit']]);
    $editingNews = $stmt->fetch();
}

$newsList = $pdo->query("
    SELECT n.*, u.username FROM news n JOIN users u ON u.user_id = n.created_by
    ORDER BY n.created_at DESC
")->fetchAll();

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

            <!-- FORM: เพิ่ม/แก้ไขข่าวสาร -->
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

            <!-- TABLE: รายการข่าวทั้งหมด -->
            <div class="space-y-4">
                <h2 class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-list text-brand-orange"></i>
                    รายการข่าวสารทั้งหมด
                </h2>

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
                                            ยังไม่มีรายการข่าวสารในระบบ
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
                                    <td class="p-4 font-bold text-slate-900 max-w-xs truncate">
                                        <?php echo htmlspecialchars($n['title']); ?>
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
                                        <?php echo htmlspecialchars($n['created_at']); ?>
                                    </td>

                                    <!-- จัดการ -->
                                    <td class="p-4 text-right space-x-1">
                                        <a href="?edit=<?php echo $n['news_id']; ?>" 
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 text-xs font-semibold transition-all">
                                            <i class="fa-solid fa-pen-to-square"></i> แก้ไข
                                        </a>

                                        <a href="?delete=<?php echo $n['news_id']; ?>" 
                                            onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบข่าวนี้?')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-all">
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

        </main>
    </div>

</body>
</html>