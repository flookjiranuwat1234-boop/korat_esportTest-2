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
 * ฟังก์ชันตรวจสอบว่าเกมนี้เป็นการแข่งขันประเภทเดี่ยว (Solo) หรือไม่
 * โดยเช็คจากชื่อเกมที่มีคำเหล่านี้ผสมอยู่
 * * @param string $gameName ชื่อเกมที่ต้องการตรวจสอบ
 * @return bool คืนค่า true หากเป็นเกมเดี่ยว
 */
function isSoloGame($gameName) {
    if (empty($gameName)) return false;

    $soloGames = ['Tekken', 'Street Fighter', 'Efootball', 'Roblox'];

    foreach ($soloGames as $solo) {
        if (stripos($gameName, $solo) !== false) {
            return true;
        }
    }
    return false;
}
