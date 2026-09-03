```php
<?php
// includes/public_nav.php
// ต้อง include includes/auth.php มาก่อนแล้ว (เพื่อให้ session_start() ทำงานและเช็ค login ได้)
?>
<nav class="<?php echo (isLoggedIn() && $_SESSION['role'] == 'admin') ? 'admin-nav' : 'public-nav'; ?>">
    <?php if (isLoggedIn() && $_SESSION['role'] == 'admin'): ?>
    <span>สวัสดี, <?php echo htmlspecialchars($_SESSION['username']); ?> (Admin)</span>
    <a href="/korat_esportTest-2/admin/dashboard.php">หน้าหลัก</a>
    <a href="/korat_esportTest-2/admin/manage-tournament.php">จัดการทัวร์นาเมนต์</a>
    <a href="/korat_esportTest-2/admin/manage-teams.php">จัดการทีมสมัคร</a>
    <a href="/korat_esportTest-2/admin/manage-members.php">จัดการสมาชิก</a>
    <a href="/korat_esportTest-2/admin/manage-news.php">จัดการข่าวสาร</a>
    <a href="/korat_esportTest-2/admin/manage-gallery.php">จัดการแกลลอรี่</a>
    <a href="/korat_esportTest-2/admin/recommended-lodging.php">ที่พักแนะนำ</a>
    <a href="/korat_esportTest-2/admin/record-match.php">บันทึกผลแมตช์</a>
    <a href="/korat_esportTest-2/admin/checkin-teams.php">เช็คอินทีม</a>
    <a href="/korat_esportTest-2/auth/logout.php">ออกจากระบบ</a>
<?php else: ?>
    <a href="/korat_esportTest-2/pages/index.php" class="logo">
        <img src="/korat_esportTest-2/assets/img/logo.png" alt="Korat Esport" class="nav-logo-img">
        KORAT ESPORT
    </a>
    <a href="/korat_esportTest-2/pages/tournaments.php">ทัวร์นาเมนต์</a>
    <a href="/korat_esportTest-2/pages/news.php">ข่าวสาร</a>
    <a href="/korat_esportTest-2/pages/gallery.php">แกลลอรี่</a>
    <a href="/korat_esportTest-2/pages/lodging.php">ที่พักแนะนำ</a>
    <a href="/korat_esportTest-2/pages/teams.php">ทีม</a>
    <a href="/korat_esportTest-2/pages/players.php">นักกีฬา</a>
    <a href="/korat_esportTest-2/pages/ranking.php">อันดับ</a>

    <?php if (isLoggedIn()): ?>
        <a href="/korat_esportTest-2/pages/my-team.php">ทีมของฉัน</a>
        <a href="/korat_esportTest-2/pages/my-checkin.php">QR Check-in</a>
        <a href="/korat_esportTest-2/pages/profile.php">โปรไฟล์ของฉัน</a>
        <a href="/korat_esportTest-2/auth/logout.php">ออกจากระบบ</a>
    <?php else: ?>
        <a href="/korat_esportTest-2/auth/login.php">เข้าสู่ระบบ</a>
        <a href="/korat_esportTest-2/auth/register.php">สมัครสมาชิก</a>
    <?php endif; ?>
<?php endif; ?>
</nav>
<script src="/korat_esportTest-2/assets/js/main.js" defer></script>

```