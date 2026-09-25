<?php
// CLI-only seeder for the approved test dataset.
// Run reset-and-seed-test-data.sql first, then run this script once.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This seeder must run from CLI.\n");
    exit(1);
}

require __DIR__ . '/config/db.php';

$seedUsernames = array_map(static fn(int $number): string => sprintf('athlete%02d', $number), range(1, 40));
$seedEmails = array_map(static fn(int $number): string => sprintf('athlete%02d@test.local', $number), range(1, 40));
$teamNames = array_map(static fn(int $number): string => sprintf('Team %02d', $number), range(1, 8));

try {
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'esport_korattest') {
        throw new RuntimeException('หยุดการทำงาน: ฐานข้อมูลปัจจุบันไม่ใช่ esport_korattest');
    }

    $duplicateUser = $pdo->prepare('SELECT user_id, username, email FROM users WHERE username = :username OR email = :email LIMIT 1');
    foreach ($seedUsernames as $index => $username) {
        $duplicateUser->execute(['username' => $username, 'email' => $seedEmails[$index]]);
        if ($duplicateUser->fetch()) {
            throw new RuntimeException("พบ Seed User เดิม ({$username}) จึงหยุดเพื่อป้องกันการสร้างซ้ำ");
        }
    }

    $duplicateTeam = $pdo->prepare('SELECT team_id FROM teams WHERE name = :name LIMIT 1');
    foreach ($teamNames as $teamName) {
        $duplicateTeam->execute(['name' => $teamName]);
        if ($duplicateTeam->fetchColumn()) {
            throw new RuntimeException("พบทีม Seed เดิม ({$teamName}) จึงหยุดเพื่อป้องกันการสร้างซ้ำ");
        }
    }

    $gameStmt = $pdo->query("SELECT game_id, name FROM games WHERE play_mode = 'team' ORDER BY game_id LIMIT 1");
    $game = $gameStmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        throw new RuntimeException('ไม่พบ Game ที่มี play_mode = team สำหรับสร้างทีมทดสอบ');
    }

    $pdo->beginTransaction();

    $passwordHash = password_hash('Test@1234', PASSWORD_DEFAULT);
    $securityAnswerHash = password_hash('test', PASSWORD_DEFAULT);
    $insertUser = $pdo->prepare('INSERT INTO users
        (username, email, password_hash, role, security_question, security_answer_hash, status)
        VALUES (:username, :email, :password_hash, \'athlete\', :security_question, :security_answer_hash, \'active\')');
    $insertPlayer = $pdo->prepare('INSERT INTO players
        (user_id, display_name, real_name, gender, birth_date, province)
        VALUES (:user_id, :display_name, :real_name, :gender, :birth_date, :province)');

    $playerIds = [];
    foreach ($seedUsernames as $index => $username) {
        $number = $index + 1;
        $insertUser->execute([
            'username' => $username,
            'email' => $seedEmails[$index],
            'password_hash' => $passwordHash,
            'security_question' => 'เกมที่คุณเล่นเป็นเกมแรกคือเกมอะไร',
            'security_answer_hash' => $securityAnswerHash,
        ]);
        $userId = (int) $pdo->lastInsertId();
        $insertPlayer->execute([
            'user_id' => $userId,
            'display_name' => 'Athlete ' . sprintf('%02d', $number),
            'real_name' => 'Test Athlete ' . sprintf('%02d', $number),
            'gender' => $number % 2 === 0 ? 'female' : 'male',
            'birth_date' => '2000-01-' . sprintf('%02d', (($number - 1) % 28) + 1),
            'province' => 'นครราชสีมา',
        ]);
        $playerIds[$number] = (int) $pdo->lastInsertId();
    }

    $insertTeam = $pdo->prepare('INSERT INTO teams
        (game_id, name, tag, captain_player_id, is_solo_wrapper, team_category, status)
        VALUES (:game_id, :name, :tag, :captain_player_id, 0, \'open\', \'active\')');
    $insertMember = $pdo->prepare('INSERT INTO team_members
        (team_id, player_id, in_game_role, member_roles, is_active, joined_at)
        VALUES (:team_id, :player_id, \'player\', \'player\', 1, NOW())');
    $insertRole = $pdo->prepare('INSERT INTO team_member_roles (team_member_id, role_code) VALUES (:team_member_id, \'player\')');

    $teamIds = [];
    for ($teamNumber = 1; $teamNumber <= 8; $teamNumber++) {
        $captainNumber = (($teamNumber - 1) * 5) + 1;
        $insertTeam->execute([
            'game_id' => (int) $game['game_id'],
            'name' => sprintf('Team %02d', $teamNumber),
            'tag' => sprintf('T%02d', $teamNumber),
            'captain_player_id' => $playerIds[$captainNumber],
        ]);
        $teamId = (int) $pdo->lastInsertId();
        $teamIds[$teamNumber] = $teamId;

        for ($memberOffset = 0; $memberOffset < 5; $memberOffset++) {
            $playerNumber = (($teamNumber - 1) * 5) + $memberOffset + 1;
            $insertMember->execute(['team_id' => $teamId, 'player_id' => $playerIds[$playerNumber]]);
            $insertRole->execute(['team_member_id' => (int) $pdo->lastInsertId()]);
        }
    }

    $pdo->commit();

    echo "Seed complete.\n";
    echo "Game: {$game['name']} (ID {$game['game_id']})\n";
    echo "Athletes: 40\nProfiles: 40\nTeams: 8\nTeam members: 40\n";
    echo "Test password for athlete01-athlete40: Test@1234\n";
    echo "Solo test athletes: athlete01, athlete06, athlete11, athlete16, athlete21, athlete26, athlete31, athlete36\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Seed failed: " . $exception->getMessage() . "\n");
    exit(1);
}
