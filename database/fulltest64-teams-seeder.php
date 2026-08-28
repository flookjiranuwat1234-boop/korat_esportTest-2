<?php
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/team_roles.php';

const BATCH = 'FULLTEST64_20260828';
const TEAM_COUNT = 64;
const MEMBERS_PER_TEAM = 9;
const TEST_PASSWORD = 'Test1234!';
$options = getopt('', ['commit']);
$commit = array_key_exists('commit', $options);

try {
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'esport_korattest') {
        throw new RuntimeException('Wrong database; stopped.');
    }
    foreach (['users', 'players', 'teams', 'team_members', 'team_member_roles'] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute(['table' => $table]);
        if ((int) $stmt->fetchColumn() !== 1) throw new RuntimeException("Missing table: {$table}");
    }
    $existing = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE name LIKE '[FULLTEST64_20260828] %'");
    $existing->execute();
    if ((int) $existing->fetchColumn() > 0) throw new RuntimeException('FULLTEST64 batch already exists; stopped.');

    $totalUsers = TEAM_COUNT * MEMBERS_PER_TEAM;
    echo "Batch: " . BATCH . "\n";
    echo "Teams: " . TEAM_COUNT . "\n";
    echo "People: {$totalUsers} (5 players + 1 substitute + 1 solo player + 1 coach + 1 manager per team)\n";
    echo "Password for all test accounts: " . TEST_PASSWORD . "\n";
    if (!$commit) {
        echo "DRY RUN ONLY - no data was written\n";
        exit(0);
    }

    $hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);
    $answerHash = password_hash('test', PASSWORD_DEFAULT);
    $insertUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, security_question, security_answer_hash, status) VALUES (:username, :email, :password_hash, 'athlete', 'test', :answer_hash, 'active')");
    $insertPlayer = $pdo->prepare("INSERT INTO players (user_id, display_name, real_name, gender, province, eligibility_status) VALUES (:user_id, :display_name, :real_name, :gender, 'นครราชสีมา', 'verified')");
    $insertTeam = $pdo->prepare("INSERT INTO teams (game_id, name, tag, captain_player_id, is_solo_wrapper, team_category, status) VALUES (NULL, :name, :tag, :captain, 0, 'open', 'active')");
    $insertMember = $pdo->prepare("INSERT INTO team_members (team_id, player_id, in_game_role, member_roles, is_active, joined_at) VALUES (:team_id, :player_id, :role, :role, 1, NOW())");
    $insertRole = $pdo->prepare("INSERT INTO team_member_roles (team_member_id, role_code) VALUES (:team_member_id, :role_code)");

    $pdo->beginTransaction();
    $number = 0;
    for ($teamNumber = 1; $teamNumber <= TEAM_COUNT; $teamNumber++) {
        $playerIds = [];
        for ($memberNumber = 1; $memberNumber <= MEMBERS_PER_TEAM; $memberNumber++) {
            $number++;
            $username = sprintf('fulltest64_%04d', $number);
            $role = $memberNumber <= 5 ? 'player' : ($memberNumber === 6 ? 'substitute' : ($memberNumber === 7 ? 'solo_player' : ($memberNumber === 8 ? 'coach' : 'manager')));
            $insertUser->execute([
                'username' => $username,
                'email' => $username . '@fulltest64.local',
                'password_hash' => $hash,
                'answer_hash' => $answerHash,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $insertPlayer->execute([
                'user_id' => $userId,
                'display_name' => sprintf('[FULLTEST64_20260828] %s %02d-%02d', $role, $teamNumber, $memberNumber),
                'real_name' => sprintf('Full Test 64 %04d', $number),
                'gender' => 'male',
            ]);
            $playerIds[] = (int) $pdo->lastInsertId();
        }
        $insertTeam->execute([
            'name' => sprintf('[FULLTEST64_20260828] Team %02d', $teamNumber),
            'tag' => sprintf('F64%02d', $teamNumber),
            'captain' => $playerIds[0],
        ]);
        $teamId = (int) $pdo->lastInsertId();
        foreach ($playerIds as $index => $playerId) {
            $role = $index < 5 ? 'player' : ($index === 5 ? 'substitute' : ($index === 6 ? 'solo_player' : ($index === 7 ? 'coach' : 'manager')));
            $insertMember->execute(['team_id' => $teamId, 'player_id' => $playerId, 'role' => $role === 'solo_player' ? 'player' : $role]);
            $insertRole->execute(['team_member_id' => (int) $pdo->lastInsertId(), 'role_code' => $role === 'solo_player' ? 'player' : $role]);
        }
    }
    $pdo->commit();
    echo "FULLTEST64 committed successfully.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'FULLTEST64 stopped: ' . $e->getMessage() . "\n");
    exit(1);
}
