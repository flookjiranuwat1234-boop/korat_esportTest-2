<?php
// pages/my-team.php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireLogin();

// หา player_id ของบัญชีที่ login อยู่ (ถ้ายังไม่มีโปรไฟล์ ให้ไป claim ก่อน)
$stmt = $pdo->prepare("SELECT player_id FROM players WHERE user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$playerId = $stmt->fetchColumn();

if (!$playerId) {
    header('Location: claim-profile.php');
    exit;
}

// ทีมทั้งหมดที่เป็นสมาชิกอยู่ (ทุกเกม)
$teams = $pdo->prepare("
    SELECT t.team_id, t.name, t.captain_player_id,
           COALESCE(g.name, 'ทีมกลาง / ทั่วไป') AS game_name,
           g.game_id AS game_id
    FROM team_members tm
    JOIN teams t ON t.team_id = tm.team_id
    LEFT JOIN games g ON g.game_id = t.game_id
    WHERE tm.player_id = :player_id AND tm.is_active = 1
    ORDER BY COALESCE(g.name, 'ทีมกลาง / ทั่วไป')
");
$teams->execute(['player_id' => $playerId]);
$teams = $teams->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ทีมของฉัน - Korat Esport</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/public_nav.php'; ?>

    <section class="content">
        <h1>ทีมของฉัน</h1>

        <div class="card-grid">
            <?php if (count($teams) == 0): ?>
                <p>คุณยังไม่ได้สังกัดทีมใด</p>
            <?php endif; ?>
            <?php foreach ($teams as $t): ?>
                <a href="team-manage.php?id=<?php echo $t['team_id']; ?>" class="card">
                    <h3><?php echo htmlspecialchars($t['name']); ?></h3>
                    <p><?php echo htmlspecialchars($t['game_name']); ?></p>
                    <?php if ($t['captain_player_id'] == $playerId): ?>
                        <span class="badge">กัปตันทีม</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <p><a href="create-team.php" class="btn">+ สร้างทีมใหม่</a></p>
        <p><a href="register-tournament.php">สมัครเข้าร่วมทัวร์นาเมนต์ &rarr;</a></p>
    </section>
</body>
</html>
