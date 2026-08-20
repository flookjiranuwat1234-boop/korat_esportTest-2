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

// ==========================================
// AUTO SETUP: ตรวจสอบและสร้างคอลัมน์เพิ่มอัตโนมัติ (image_path และ distance)
// ==========================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM accommodations")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('image_path', $cols)) {
        $pdo->exec("ALTER TABLE accommodations ADD COLUMN image_path VARCHAR(255) NULL AFTER address");
    }
    if (!in_array('distance', $cols)) {
        $pdo->exec("ALTER TABLE accommodations ADD COLUMN distance VARCHAR(50) NULL AFTER image_path");
    }
} catch (Exception $e) {
    // ซ่อนข้อผิดพลาดกรณีไม่มีสิทธิ์ ALTER TABLE
}

// ฟังก์ชันช่วยอัปโหลดรูปภาพที่พัก
function uploadAccommodationImage($file) {
    if (isset($file) && $file['error'] == UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($file['type'], $allowedTypes)) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = 'hotel_' . uniqid() . '.' . $ext;
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
    return null;
}

// ==========================================
// 1. บันทึกข้อมูลที่พักแนะนำ (พร้อมรูปภาพและระยะทาง)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_lodging') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
    } else {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $distance = trim($_POST['distance'] ?? '');
        $linkUrl = trim($_POST['link_url'] ?? '');
        
        // อัปโหลดรูปภาพ
        $imagePath = uploadAccommodationImage($_FILES['hotel_image'] ?? null);

        if (empty($name)) {
            $error = 'กรุณากรอกชื่อที่พัก';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO accommodations (name, address, image_path, distance, link_url)
                    VALUES (:name, :address, :image_path, :distance, :link_url)
                ");
                $stmt->execute([
                    'name' => $name,
                    'address' => $address,
                    'image_path' => $imagePath,
                    'distance' => $distance,
                    'link_url' => $linkUrl
                ]);
                $success = 'เพิ่มข้อมูลที่พักแนะนำเรียบร้อยแล้ว';
            } catch (PDOException $e) {
                $error = 'เกิดข้อผิดพลาดในการบันทึก: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// 2. จัดการลบข้อมูลที่พัก
// ==========================================
if (isset($_GET['delete'])) {
    $deleteId = (int) $_GET['delete'];
    try {
        $stmtDel = $pdo->prepare("DELETE FROM accommodations WHERE accommodation_id = :id");
        $stmtDel->execute(['id' => $deleteId]);
        $success = 'ลบข้อมูลที่พักเรียบร้อยแล้ว';
    } catch (PDOException $e) {
        $error = 'เกิดข้อผิดพลาดในการลบข้อมูล: ' . $e->getMessage();
    }
}

// ดึงรายการที่พักทั้งหมดจากตาราง accommodations
$accommodations = $pdo->query("SELECT * FROM accommodations ORDER BY accommodation_id DESC")->fetchAll();
$csrfToken = generateCsrfToken();
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
                <p class="text-xs text-slate-500 mt-0.5">เพิ่มรูปภาพ ระยะทาง และลิงก์แผนที่โรงแรมสำหรับนักกีฬา</p>
            </div>
            
            <a href="../pages/lodging.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> ดูหน้าเว็บที่พัก
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

        </main>
    </div>

</body>
</html>