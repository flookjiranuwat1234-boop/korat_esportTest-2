<?php
// pages/my-checkin.php
// หน้าให้กัปตันทีมดู QR code ของทีมตัวเอง เอาไปโชว์ตอนเช็คอินหน้างาน
require_once '../config/db.php';
require_once '../includes/auth.php';
requireLogin();

$stmt = $pdo->prepare("SELECT player_id FROM players WHERE user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$myPlayerId = $stmt->fetchColumn();

if (!$myPlayerId) {
    header('Location: claim-profile.php');
    exit;
}

// ทีมที่ตัวเองเป็นกัปตัน พร้อมสถานะการสมัคร/เช็คอินของแต่ละทัวร์นาเมนต์ที่อนุมัติแล้ว
$stmt = $pdo->prepare("
    SELECT tr.qr_code_token, tr.checkin_status, tr.checkin_at,
           tour.name AS tournament_name, tour.venue_address, tour.venue_lat_lng,
           t.name AS team_name
    FROM tournament_registrations tr
    JOIN teams t ON t.team_id = tr.team_id
    JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
    WHERE t.captain_player_id = :pid AND tr.status = 'approved'
    ORDER BY tour.created_at DESC
");
$stmt->execute(['pid' => $myPlayerId]);
$checkins = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>QR Check-in ของทีมฉัน - Korat Esport</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/public_nav.php'; ?>

    <section class="content">
        <h1>QR Check-in ของทีมฉัน</h1>
        <p>เอาโค้ด QR นี้ไปให้เจ้าหน้าที่สแกนตอนเช็คอินหน้างานวันแข่งขัน</p>

        <?php if (count($checkins) == 0): ?>
            <p>ยังไม่มีทัวร์นาเมนต์ที่ทีมของคุณได้รับการอนุมัติเข้าร่วม</p>
        <?php endif; ?>

        <div class="card-grid">
            <?php foreach ($checkins as $c): ?>
                <div class="card">
                    <h3><?php echo htmlspecialchars($c['tournament_name']); ?></h3>
                    <p>ทีม: <?php echo htmlspecialchars($c['team_name']); ?></p>

                    <?php if ($c['checkin_status'] == 'checked_in'): ?>
                        <span class="badge">เช็คอินแล้วเมื่อ <?php echo htmlspecialchars($c['checkin_at']); ?></span>
                    <?php else: ?>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?php echo urlencode($c['qr_code_token']); ?>"
                             alt="QR Code เช็คอิน" style="margin: 0.8rem 0;">
                        <p>รหัส: <strong><?php echo htmlspecialchars($c['qr_code_token']); ?></strong></p>
                        <span class="badge">ยังไม่เช็คอิน</span>
                    <?php endif; ?>

                    <?php if ($c['venue_address']): ?>
                        <p style="margin-top:0.6rem;">สถานที่แข่งขัน: <?php echo htmlspecialchars($c['venue_address']); ?></p>
                    <?php endif; ?>
                    <?php if ($c['venue_lat_lng']): ?>
                        <p><a href="https://www.google.com/maps?q=<?php echo urlencode($c['venue_lat_lng']); ?>" target="_blank" rel="noopener">เปิดแผนที่</a></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</body>
</html>
