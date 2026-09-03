<?php
// includes/admin_nav.php
// เมนูด้านบนของทุกหน้า admin ต้อง include ไฟล์นี้หลังจากเช็ค requireRole('admin') แล้ว
?>
<nav class="admin-nav">
    <span>สวัสดี, <?php echo htmlspecialchars($_SESSION['username']); ?> (Admin)</span>
    <a href="dashboard.php">หน้าหลัก</a>
    <a href="manage-tournament.php">จัดการทัวร์นาเมนต์</a>
    <a href="manage-teams.php">ทีมสมัคร Tournament</a>
    <a href="manage-members.php">จัดการสมาชิก</a>
    <a href="manage-news.php">จัดการข่าวสาร</a>
    <a href="manage-gallery.php">จัดการแกลลอรี่</a>
    <a href="recommended-lodging.php">ที่พักแนะนำ</a>
    <a href="manage-score.php">บันทึกผลแมตช์</a>
    <a href="checkin-teams.php">เช็คอินทีม</a>
    <a href="../auth/logout.php">ออกจากระบบ</a>
</nav>
<script src="/korat_esportTest-2/assets/js/main.js" defer></script>
