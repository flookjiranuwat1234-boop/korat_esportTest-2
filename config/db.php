<?php
// config/db.php
// ไฟล์เชื่อมต่อฐานข้อมูล ให้ทุกไฟล์ include ตัวนี้แค่ตัวเดียว
// ไม่ต้องเขียน connection ซ้ำในแต่ละไฟล์

date_default_timezone_set('Asia/Bangkok');

$host = 'localhost';
$dbname = 'esport_korattest';
$dbuser = 'root';
$dbpass = ''; // แก้เป็นรหัสจริงตอนขึ้น production

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $dbuser,
        $dbpass
    );
    $pdo->exec("SET time_zone = '+07:00'");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("เชื่อมต่อฐานข้อมูลไม่ได้: " . $e->getMessage());
}

/**
 * ดึง play_mode ของเกมจากฐานข้อมูลแบบเป็นแหล่งข้อมูลเดียว
 * รองรับค่า: team, solo
 */
function getGamePlayMode(PDO $pdo, int $gameId): string
{
    if ($gameId <= 0) {
        return 'team';
    }

    $stmt = $pdo->prepare('SELECT play_mode FROM games WHERE game_id = :game_id LIMIT 1');
    $stmt->execute(['game_id' => $gameId]);
    $playMode = strtolower(trim((string) $stmt->fetchColumn()));

    return in_array($playMode, ['team', 'solo'], true) ? $playMode : 'team';
}

/**
 * Backward-compatible helper. Avoid hard-coded game names.
 * When a numeric game ID is passed, resolve from games.play_mode.
 */
function isSoloGame($gameIdentifier): bool
{
    global $pdo;

    if (is_numeric($gameIdentifier)) {
        return getGamePlayMode($pdo, (int) $gameIdentifier) === 'solo';
    }

    return false;
}
