<?php
// config/db.php
// ไฟล์เชื่อมต่อฐานข้อมูล ให้ทุกไฟล์ include ตัวนี้แค่ตัวเดียว
// ไม่ต้องเขียน connection ซ้ำในแต่ละไฟล์

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
    // โหมด error ให้ throw exception ออกมาเลย จะได้เห็น error ชัดๆ ตอน debug
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
    
    // รายชื่อเกมที่เป็นประเภทบุคคล / เกมเดี่ยว (สามารถเพิ่มชื่อเกมเดี่ยวในอนาคตที่นี่ได้เลย)
    $soloGames = ['Tekken', 'Street Fighter', 'Efootball', 'Roblox'];
    
    foreach ($soloGames as $solo) {
        // ใช้ stripos เพื่อไม่สนใจตัวพิมพ์เล็ก-ใหญ่ (Case-insensitive)
        if (stripos($gameName, $solo) !== false) {
            return true;
        }
    }
    return false;
}
