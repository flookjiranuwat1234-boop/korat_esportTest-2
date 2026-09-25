<?php
/**
 * athlete64-seeder.php
 * 
 * Batch: ATHLETE64_ROBLOX50_20260829
 * 
 * สร้างข้อมูลทดสอบ:
 * - Team 64 ทีม (ชาย/หญิง/ผสม)
 * - Team Member 512 คน (Starter/Substitute/Coach/Manager)
 * - Roblox Solo Player 50 คน (ไม่อยู่ใน Team)
 * 
 * อนุญาต: --commit (ถ้า dry run ผ่าน)
 * ห้าม: ไม่ต้องการ --confirm หรือการยืนยัน
 */

require_once __DIR__ . '/../config/db.php';

$BATCH_ID = 'ATHLETE64_ROBLOX50_20260829';
$TEST_PASSWORD = 'KoratDemo@2569';
$TEST_PASSWORD_HASH = password_hash($TEST_PASSWORD, PASSWORD_BCRYPT);

// ค่า FLAGS
$isDryRun = !in_array('--commit', $argv);
$verbose = in_array('-v', $argv) || in_array('--verbose', $argv);

// ข้อมูล Team
$teams = generateTeamData();
$roboxPlayers = generateRobloxPlayers();

// ความปลอดภัย
if (!isDatabaseSafe()) {
    die("❌ Database ไม่ปลอดภัย หรือไม่ใช่ test environment\n");
}

// ตรวจสอบ Batch ยังไม่มี
if (batchExists($BATCH_ID)) {
    die("❌ Batch '$BATCH_ID' มีอยู่แล้ว\n");
}

try {
    $pdo->beginTransaction();
    
    // สร้าง User + Player + Team + Member + Role
    $userTeamStats = [];
    foreach ($teams as $teamIndex => $teamData) {
        // สร้าง Team ก่อน
        $teamId = createTeam($teamData['name'], $teamData['tag']);
        $teamData['team_id'] = $teamId;
        
        $teamUserIds = [];
        
        // สร้าง User, Player, Team Member สำหรับแต่ละสมาชิก
        foreach ($teamData['members'] as $memberIndex => $member) {
            $userId = createUser(
                $member['username'],
                $member['email'],
                $member['fullname'],
                $member['gender'],
                $TEST_PASSWORD_HASH
            );
            
            $playerId = createPlayer(
                $userId,
                $member['display_name'],
                $member['gender'],
                $member['birth_date']
            );
            
            $teamUserId = createTeamMember(
                $teamId,
                $playerId,
                $member['role'],
                $member['is_starter'],
                $memberIndex === 0 // is_captain = first member only
            );
            
            $teamUserIds[] = $teamUserId;
            if ($verbose) {
                echo "✓ User {$member['username']} → Player {$playerId} → TeamMember\n";
            }
        }
        
        $userTeamStats[$teamId] = [
            'name' => $teamData['name'],
            'tag' => $teamData['tag'],
            'type' => $teamData['type'],
            'member_count' => count($teamUserIds)
        ];
    }
    
    // สร้าง Roblox Solo Players
    foreach ($roboxPlayers as $robloxData) {
        $userId = createUser(
            $robloxData['username'],
            $robloxData['email'],
            $robloxData['fullname'],
            $robloxData['gender'],
            $TEST_PASSWORD_HASH
        );
        
        $playerId = createPlayer(
            $userId,
            $robloxData['display_name'],
            $robloxData['gender'],
            $robloxData['birth_date']
        );
        
        if ($verbose) {
            echo "✓ Roblox Player {$robloxData['username']} (ID: {$playerId})\n";
        }
    }
    
    if ($isDryRun) {
        $pdo->rollBack();
        echo "\n✅ DRY RUN ผ่านแล้ว (ยังไม่บันทึก)\n";
        exit(0);
    }
    
    $pdo->commit();
    echo "\n✅ Commit สำเร็จ!\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    die("❌ Error: " . $e->getMessage() . "\n");
}

// ========== FUNCTIONS ==========

function isDatabaseSafe() {
    global $pdo;
    try {
        $result = $pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($result !== 'esport_korattest') {
            echo "❌ Database ไม่ถูกต้อง: $result\n";
            return false;
        }
        return true;
    } catch (Exception $e) {
        echo "❌ ไม่สามารถตรวจสอบ Database: " . $e->getMessage() . "\n";
        return false;
    }
}

function batchExists($batchId) {
    // ตรวจสอบว่า Batch มีอยู่แล้วในระบบ
    // ใช้ผ่านการแสดงความเห็น หรือ metadata
    $batchFile = __DIR__ . "/{$batchId}-manifest.json";
    return file_exists($batchFile);
}

function generateTeamData() {
    $teams = [];
    
    // Team 001-016: Male Teams
    for ($i = 1; $i <= 16; $i++) {
        $teams[] = [
            'team_id' => null,
            'number' => str_pad($i, 3, '0', STR_PAD_LEFT),
            'tag' => 'A64M' . str_pad($i, 2, '0', STR_PAD_LEFT),
            'name' => getMaleTeamName($i),
            'type' => 'male',
            'members' => generateTeamMembers('male', $i)
        ];
    }
    
    // Team 017-032: Female Teams
    for ($i = 17; $i <= 32; $i++) {
        $teams[] = [
            'team_id' => null,
            'number' => str_pad($i, 3, '0', STR_PAD_LEFT),
            'tag' => 'A64F' . str_pad($i, 2, '0', STR_PAD_LEFT),
            'name' => getFemaleTeamName($i - 16),
            'type' => 'female',
            'members' => generateTeamMembers('female', $i)
        ];
    }
    
    // Team 033-064: Mixed Open Teams
    for ($i = 33; $i <= 64; $i++) {
        $teams[] = [
            'team_id' => null,
            'number' => str_pad($i, 3, '0', STR_PAD_LEFT),
            'tag' => 'A64X' . str_pad($i, 2, '0', STR_PAD_LEFT),
            'name' => getMixedTeamName($i - 32),
            'type' => 'mixed',
            'members' => generateTeamMembers('mixed', $i)
        ];
    }
    
    return $teams;
}

function generateTeamMembers($gender, $teamNumber) {
    $members = [];
    $genders = ($gender === 'mixed') ? ['male', 'male', 'male', 'female', 'female', 'female'] : array_fill(0, 6, $gender);
    
    // 5 Starters
    for ($i = 1; $i <= 5; $i++) {
        $members[] = [
            'username' => "ath64_t{$teamNumber}_s{$i}",
            'email' => "ath64_t{$teamNumber}_s{$i}@ath64.local",
            'fullname' => generateThaiName($genders[$i-1], $teamNumber, $i),
            'display_name' => "Starter {$i}",
            'gender' => $genders[$i-1],
            'birth_date' => generateAge(16, 25),
            'role' => 'player',
            'is_starter' => true
        ];
    }
    
    // 1 Substitute
    $members[] = [
        'username' => "ath64_t{$teamNumber}_sub",
        'email' => "ath64_t{$teamNumber}_sub@ath64.local",
        'fullname' => generateThaiName($genders[5], $teamNumber, 6),
        'display_name' => "Substitute",
        'gender' => $genders[5],
        'birth_date' => generateAge(16, 25),
        'role' => 'substitute',
        'is_starter' => false
    ];
    
    // 1 Coach
    $members[] = [
        'username' => "ath64_t{$teamNumber}_coach",
        'email' => "ath64_t{$teamNumber}_coach@ath64.local",
        'fullname' => generateThaiName('male', $teamNumber, 7),
        'display_name' => "Coach",
        'gender' => 'male',
        'birth_date' => generateAge(30, 50),
        'role' => 'coach',
        'is_starter' => false
    ];
    
    // 1 Manager
    $members[] = [
        'username' => "ath64_t{$teamNumber}_manager",
        'email' => "ath64_t{$teamNumber}_manager@ath64.local",
        'fullname' => generateThaiName('female', $teamNumber, 8),
        'display_name' => "Manager",
        'gender' => 'female',
        'birth_date' => generateAge(25, 45),
        'role' => 'manager',
        'is_starter' => false
    ];
    
    return $members;
}

function generateRobloxPlayers() {
    $players = [];
    
    // 25 Male Roblox Players (ath64_roblox001 - ath64_roblox025)
    for ($i = 1; $i <= 25; $i++) {
        $num = str_pad($i, 3, '0', STR_PAD_LEFT);
        $players[] = [
            'username' => "ath64_roblox{$num}",
            'email' => "ath64_roblox{$num}@ath64.local",
            'fullname' => generateThaiName('male', 99, $i),
            'display_name' => "Roblox Player {$num}",
            'gender' => 'male',
            'birth_date' => generateAge(8, 12)
        ];
    }
    
    // 25 Female Roblox Players (ath64_roblox026 - ath64_roblox050)
    for ($i = 26; $i <= 50; $i++) {
        $num = str_pad($i, 3, '0', STR_PAD_LEFT);
        $players[] = [
            'username' => "ath64_roblox{$num}",
            'email' => "ath64_roblox{$num}@ath64.local",
            'fullname' => generateThaiName('female', 99, $i),
            'display_name' => "Roblox Player {$num}",
            'gender' => 'female',
            'birth_date' => generateAge(8, 12)
        ];
    }
    
    return $players;
}

function createUser($username, $email, $fullname, $gender, $passwordHash) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, role, is_athlete, status)
        VALUES (:username, :email, :password_hash, :role, :is_athlete, :status)
    ");
    
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => 'athlete',
        'is_athlete' => 1,
        'status' => 'active'
    ]);
    
    return $pdo->lastInsertId();
}

function createTeam($name, $tag) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO teams (name, tag, team_category, status, created_at)
        VALUES (:name, :tag, :team_category, :status, NOW())
    ");
    
    $stmt->execute([
        'name' => $name,
        'tag' => $tag,
        'team_category' => determineCategory($tag),
        'status' => 'active'
    ]);
    
    return $pdo->lastInsertId();
}

function determineCategory($tag) {
    // A64M = Male, A64F = Female, A64X = Open/Mixed
    if (strpos($tag, 'A64M') === 0) return 'male';
    if (strpos($tag, 'A64F') === 0) return 'female';
    if (strpos($tag, 'A64X') === 0) return 'open';
    return 'open';
}

function createPlayer($userId, $displayName, $gender, $birthDate) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        INSERT INTO players (user_id, display_name, real_name, gender, birth_date, eligibility_status)
        VALUES (:user_id, :display_name, :real_name, :gender, :birth_date, :eligibility_status)
    ");
    
    $stmt->execute([
        'user_id' => $userId,
        'display_name' => $displayName,
        'real_name' => $displayName,
        'gender' => $gender,
        'birth_date' => $birthDate,
        'eligibility_status' => 'verified'
    ]);
    
    return $pdo->lastInsertId();
}

function createTeamMember($teamId, $playerId, $role, $isStarter, $isCaptain) {
    global $pdo;
    
    // สร้าง Team Member
    $stmt = $pdo->prepare("
        INSERT INTO team_members (team_id, player_id, member_roles, is_active)
        VALUES (:team_id, :player_id, :member_roles, :is_active)
    ");
    
    $stmt->execute([
        'team_id' => $teamId,
        'player_id' => $playerId,
        'member_roles' => $role,
        'is_active' => 1
    ]);
    
    $teamMemberId = $pdo->lastInsertId();
    
    // ถ้าเป็น captain ให้อัปเดต captain_player_id ใน teams table
    if ($isCaptain) {
        $stmt = $pdo->prepare("UPDATE teams SET captain_player_id = :player_id WHERE team_id = :team_id");
        $stmt->execute(['player_id' => $playerId, 'team_id' => $teamId]);
    }
    
    return $teamMemberId;
}

// ===== HELPER FUNCTIONS =====

function getMaleTeamName($index) {
    $names = [
        'Crimson Nova', 'Lunar Wolves', 'Neon Falcons', 'Thunder Core',
        'Mystic Raiders', 'Phoenix Byte', 'Silent Vortex', 'Quantum Foxes',
        'Arctic Blaze', 'Cyber Hawks', 'Inferno Squad', 'Storm Riders',
        'Eclipse Force', 'Vortex Kings', 'Steel Dragons', 'Shadow Titans'
    ];
    return $names[($index - 1) % 16];
}

function getFemaleTeamName($index) {
    $names = [
        'Radiant Stars', 'Luna Sirens', 'Blazing Phoenixes', 'Crystal Queens',
        'Aurora Spirits', 'Nebula Warriors', 'Starlight Pixies', 'Diamond Angels',
        'Mystic Maidens', 'Thunder Goddesses', 'Neon Nymphs', 'Twilight Valkyries',
        'Quantum Goddesses', 'Celestial Beauties', 'Cosmic Princesses', 'Eternal Sorceresses'
    ];
    return $names[($index - 1) % 16];
}

function getMixedTeamName($index) {
    $names = [
        'Void Sentinels', 'Apex Legends', 'Prism Riders', 'Xenon Pulse',
        'Synapse Nexus', 'Velocity Strike', 'Omega Force', 'Stellar Blaze',
        'Quantum Echo', 'Titan Surge', 'Eclipse Venom', 'Primal Fury',
        'Nova Breach', 'Kinetic Storm', 'Sonic Paradox', 'Cyber Rebellion',
        'Flux Dynasty', 'Helix Warriors', 'Phantom Ascent', 'Graviton Syndicate',
        'Tempest Legion', 'Nebula Nexus', 'Vortex Uprising', 'Lumina Ascension',
        'Resonance Faction', 'Drift Collective', 'Catalyst Alliance', 'Apex Nexus',
        'Zenith Collective', 'Prism Dynasty', 'Velocity Legion', 'Omega Nexus'
    ];
    return $names[($index - 1) % 32];
}

function generateThaiName($gender, $teamNumber, $memberNumber) {
    static $firstNames, $lastNames;
    
    if (!isset($firstNames)) {
        $firstNames = [
            'male' => ['พัชร', 'สมชาย', 'วิชัย', 'ธวัฒน์', 'ณัฐพล', 'จิรัฐ', 'ศิริพล', 'กิติพัฒน์', 'นวัฒน์', 'ประวิทย์'],
            'female' => ['สุนิสา', 'วิภาพ', 'นัทนา', 'ณัฐฐา', 'ศิลปา', 'ปัญญา', 'สยาม', 'เกียรติ', 'ศรีศา', 'กมลา']
        ];
        
        $lastNames = ['ศรีวัฒน์', 'นวลวรรณ', 'สมจริง', 'เชิดชัย', 'ธรรมชาติ', 'มั่งมี', 'ทองแท', 'ประเสริฐ', 'สิริ', 'สวัสดี'];
    }
    
    $fNameKey = ($teamNumber + $memberNumber) % count($firstNames[$gender]);
    $lNameKey = ($teamNumber * 3 + $memberNumber) % count($lastNames);
    
    return $firstNames[$gender][$fNameKey] . ' ' . $lastNames[$lNameKey];
}

function generateAge($minAge, $maxAge) {
    $now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
    $minYear = $now->format('Y') - $maxAge;
    $maxYear = $now->format('Y') - $minAge;
    
    $year = rand($minYear, $maxYear);
    $month = rand(1, 12);
    $day = rand(1, 28);
    
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

echo "✅ Seeder started (Database: esport_korattest, Batch: {$BATCH_ID})\n";
if ($isDryRun) echo "ℹ️  Running in DRY RUN mode\n";
