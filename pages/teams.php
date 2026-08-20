<?php
// pages/teams.php
require_once '../config/db.php';
require_once '../includes/auth.php';

$games = $pdo->query("SELECT game_id, name FROM games WHERE is_active = 1 ORDER BY name")->fetchAll();
$gameId = (int) ($_GET['game_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$sql = "SELECT t.team_id, t.name, g.name AS game_name FROM teams t JOIN games g ON g.game_id = t.game_id WHERE t.is_solo_wrapper = 0";
$params = [];
if ($gameId) {
    $sql .= " AND t.game_id = :game_id";
    $params['game_id'] = $gameId;
}
if ($q !== '') {
    $sql .= " AND t.name LIKE :q";
    $params['q'] = "%{$q}%";
}
$sql .= " ORDER BY t.name LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$teams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ทีมทั้งหมด - Korat Esport</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/public_nav.php'; ?>

    <section class="content">
        <h1>ทีมทั้งหมด</h1>

        <div class="filter-bar">
            <a href="teams.php" class="<?php echo $gameId == 0 ? 'active' : ''; ?>">ทั้งหมด</a>
            <?php foreach ($games as $g): ?>
                <a href="teams.php?game_id=<?php echo $g['game_id']; ?>"
                   class="<?php echo $gameId == $g['game_id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($g['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET">
            <?php if ($gameId): ?><input type="hidden" name="game_id" value="<?php echo $gameId; ?>"><?php endif; ?>
            <input type="text" name="q" placeholder="ค้นหาชื่อทีม" value="<?php echo htmlspecialchars($q); ?>">
            <button type="submit">ค้นหา</button>
        </form>

        <div class="card-grid">
            <?php if (count($teams) == 0): ?>
                <p>ไม่พบทีมที่ตรงกับเงื่อนไข</p>
            <?php endif; ?>
            <?php foreach ($teams as $t): ?>
                <a href="team-profile.php?id=<?php echo $t['team_id']; ?>" class="card">
                    <h3><?php echo htmlspecialchars($t['name']); ?></h3>
                    <p><?php echo htmlspecialchars($t['game_name']); ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</body>
</html>
