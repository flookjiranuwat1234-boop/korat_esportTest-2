<?php
/**
 * athlete64-cleanup.php
 * 
 * ลบข้อมูล Batch: ATHLETE64_ROBLOX50_20260829
 * 
 * วิธีใช้:
 *   php athlete64-cleanup.php              # Dry run
 *   php athlete64-cleanup.php --commit     # Delete data
 */

require_once __DIR__ . '/../config/db.php';

$BATCH_ID = 'ATHLETE64_ROBLOX50_20260829';
$isDryRun = !in_array('--commit', $argv);
$verbose = in_array('-v', $argv) || in_array('--verbose', $argv);

echo "🗑️  Cleanup: $BATCH_ID\n";
echo "Mode: " . ($isDryRun ? "DRY RUN" : "COMMIT") . "\n\n";

// ตรวจสอบ Database
if ($pdo->query("SELECT DATABASE()")->fetchColumn() !== 'esport_korattest') {
    die("❌ ไม่ใช่ esport_korattest\n");
}

try {
    $pdo->beginTransaction();
    
    // ค้นหาข้อมูล ATHLETE64
    $teams = $pdo->query("
        SELECT team_id FROM teams WHERE tag LIKE 'A64%'
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "ค้นหา Teams: " . count($teams) . "\n";
    
    if (empty($teams)) {
        echo "ไม่มีข้อมูล Batch นี้\n";
        if (!$isDryRun) $pdo->commit();
        exit(0);
    }
    
    // ลบ Team Members
    $teamsPlaceholder = implode(',', array_fill(0, count($teams), '?'));
    $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id IN ($teamsPlaceholder)");
    $stmt->execute($teams);
    $deletedMembers = $stmt->rowCount();
    echo "ลบ Team Members: $deletedMembers\n";
    
    // ลบ Teams
    $stmt = $pdo->prepare("DELETE FROM teams WHERE tag LIKE 'A64%'");
    $stmt->execute();
    $deletedTeams = $stmt->rowCount();
    echo "ลบ Teams: $deletedTeams\n";
    
    // ลบ Players (ath64_roblox* และ ath64_t*)
    $stmt = $pdo->prepare("DELETE FROM players WHERE user_id IN (SELECT user_id FROM users WHERE username LIKE 'ath64%')");
    $stmt->execute();
    $deletedPlayers = $stmt->rowCount();
    echo "ลบ Players: $deletedPlayers\n";
    
    // ลบ Users
    $stmt = $pdo->prepare("DELETE FROM users WHERE username LIKE 'ath64%'");
    $stmt->execute();
    $deletedUsers = $stmt->rowCount();
    echo "ลบ Users: $deletedUsers\n";
    
    if ($isDryRun) {
        $pdo->rollBack();
        echo "\n✅ Dry run ผ่าน (ยังไม่ลบข้อมูลจริง)\n";
    } else {
        $pdo->commit();
        echo "\n✅ ลบข้อมูลสำเร็จ\n";
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    die("❌ Error: " . $e->getMessage() . "\n");
}
