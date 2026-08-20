```php
<?php
// includes/public_nav.php
// ต้อง include includes/auth.php มาก่อนแล้ว (เพื่อให้ session_start() ทำงานและเช็ค login ได้)
?>
<nav class="<?php echo (isLoggedIn() && $_SESSION['role'] == 'admin') ? 'admin-nav' : 'public-nav'; ?>">
    <?php if (isLoggedIn() && $_SESSION['role'] == 'admin'): ?>
    <span>สวัสดี, <?php echo htmlspecialchars($_SESSION['username']); ?> (Admin)</span>
    <a href="/koratesport/admin/dashboard.php">หน้าหลัก</a>
    <a href="/koratesport/admin/manage-tournament.php">จัดการทัวร์นาเมนต์</a>
    <a href="/koratesport/admin/manage-teams.php">จัดการทีมสมัคร</a>
    <a href="/koratesport/admin/manage-members.php">จัดการสมาชิก</a>
    <a href="/koratesport/admin/manage-news.php">จัดการข่าวสาร</a>
    <a href="/koratesport/admin/manage-gallery.php">จัดการแกลลอรี่</a>
    <a href="/koratesport/admin/manage-accommodations.php">ที่พักแนะนำ</a>
    <a href="/koratesport/admin/manage-score.php">บันทึกผลแมตช์</a>
    <a href="/koratesport/admin/checkin.php">เช็คอินทีม</a>
    <a href="/koratesport/auth/logout.php">ออกจากระบบ</a>
<?php else: ?>
    <a href="/koratesport/pages/index.php" class="logo">
        <img src="/koratesport/assets/img/logo.png" alt="Korat Esport" class="nav-logo-img">
        KORAT ESPORT
    </a>
    <a href="/koratesport/pages/tournament.php">ทัวร์นาเมนต์</a>
    <a href="/koratesport/pages/news.php">ข่าวสาร</a>
    <a href="/koratesport/pages/gallery.php">แกลลอรี่</a>
    <a href="/koratesport/pages/accommodations.php">ที่พักแนะนำ</a>
    <a href="/koratesport/pages/teams.php">ทีม</a>
    <a href="/koratesport/pages/players.php">นักกีฬา</a>
    <a href="/koratesport/pages/ranking.php">อันดับ</a>

    <?php if (isLoggedIn()): ?>
        <a href="/koratesport/pages/my-team.php">ทีมของฉัน</a>
        <a href="/koratesport/pages/my-checkin.php">QR Check-in</a>
        <a href="/koratesport/pages/profile.php">โปรไฟล์ของฉัน</a>
        <a href="/koratesport/auth/logout.php">ออกจากระบบ</a>
    <?php else: ?>
        <a href="/koratesport/auth/login.php">เข้าสู่ระบบ</a>
        <a href="/koratesport/auth/register.php">สมัครสมาชิก</a>
    <?php endif; ?>
<?php endif; ?>
</nav>
<script src="/koratesport/assets/js/main.js" defer></script>

```