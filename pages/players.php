<?php
// pages/players.php
require_once '../config/db.php';
require_once '../includes/auth.php';

$games = $pdo->query("SELECT game_id, name FROM games WHERE is_active = 1 ORDER BY name")->fetchAll();
$gameId = (int) ($_GET['game_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$sql = "
    SELECT DISTINCT p.player_id, p.display_name,
        (SELECT t.name FROM team_members tm
         JOIN teams t ON t.team_id = tm.team_id
         WHERE tm.player_id = p.player_id AND tm.is_active = 1
         " . ($gameId ? "AND t.game_id = :game_id" : "") . "
         LIMIT 1) AS team_name
    FROM players p
";
$conditions = [];
$params = [];
if ($gameId) {
    $sql .= " JOIN team_members tm2 ON tm2.player_id = p.player_id
              JOIN teams t2 ON t2.team_id = tm2.team_id AND t2.game_id = :game_id2";
    $params['game_id'] = $gameId;
    $params['game_id2'] = $gameId;
}
if ($q !== '') {
    $conditions[] = "p.display_name LIKE :q";
    $params['q'] = "%{$q}%";
}
if ($conditions) {
    $sql .= " WHERE " . implode(' AND ', $conditions);
}
$sql .= " ORDER BY p.display_name LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$players = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>นักกีฬาทั้งหมด - Korat Esport</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/public_nav.php'; ?>

    <section class="content">
        <h1>นักกีฬาทั้งหมด</h1>

        <div class="filter-bar">
            <a href="players.php" class="<?php echo $gameId == 0 ? 'active' : ''; ?>">ทั้งหมด</a>
            <?php foreach ($games as $g): ?>
                <a href="players.php?game_id=<?php echo $g['game_id']; ?>"
                   class="<?php echo $gameId == $g['game_id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($g['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET">
            <?php if ($gameId): ?><input type="hidden" name="game_id" value="<?php echo $gameId; ?>"><?php endif; ?>
            <input type="text" name="q" placeholder="ค้นหาชื่อในเกม" value="<?php echo htmlspecialchars($q); ?>">
            <button type="submit">ค้นหา</button>
        </form>

        <div class="card-grid">
            <?php if (count($players) == 0): ?>
                <p>ไม่พบนักกีฬาที่ตรงกับเงื่อนไข</p>
            <?php endif; ?>
            <?php foreach ($players as $p): ?>
                <a href="player-profile.php?id=<?php echo $p['player_id']; ?>" class="card">
                    <h3><?php echo htmlspecialchars($p['display_name']); ?></h3>
                    <?php if ($p['team_name']): ?><p><?php echo htmlspecialchars($p['team_name']); ?></p><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
</body>
</html>
