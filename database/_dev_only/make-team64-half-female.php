<?php
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require __DIR__ . '/../config/db.php';

const TEAM_TAG_PREFIX = 'F64';
const TEAM_LIMIT = 32;
const MEMBER_COUNT = 8;
const USER_PREFIX = 'fulltest64female_';
const EMAIL_DOMAIN = '@fulltest64female.local';
const PASSWORD = 'Test1234!';
$options = getopt('', ['commit']);
$commit = array_key_exists('commit', $options);

function countRows(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

try {
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'esport_korattest') {
        throw new RuntimeException('Wrong database; stopped.');
    }
    $existing = countRows($pdo, 'SELECT COUNT(*) FROM users WHERE username LIKE :prefix', ['prefix' => USER_PREFIX . '%']);
    if ($existing > 0) throw new RuntimeException('Female Team64 batch already exists; stopped.');

    $teams = $pdo->query("SELECT team_id, tag, name, captain_player_id FROM teams WHERE tag REGEXP '^F64(0[1-9]|[12][0-9]|3[0-2])$' AND status = 'active' AND is_solo_wrapper = 0 ORDER BY team_id")->fetchAll(PDO::FETCH_ASSOC);
    if (count($teams) !== TEAM_LIMIT) throw new RuntimeException('Expected exactly 32 active Team64 teams.');
    $memberStmt = $pdo->prepare('SELECT team_member_id, player_id, in_game_role FROM team_members WHERE team_id = :team_id AND is_active = 1 ORDER BY team_member_id');
    foreach ($teams as $team) {
        $memberStmt->execute(['team_id' => $team['team_id']]);
        if (count($memberStmt->fetchAll(PDO::FETCH_ASSOC)) !== MEMBER_COUNT) {
            throw new RuntimeException('Each selected team must have exactly 8 active members.');
        }
    }

    echo "Database: esport_korattest\n";
    echo "Selected teams: 32 (Team 01-32)\n";
    echo "New female Players: 256\n";
    echo "Existing male Players: unchanged\n";
    if (!$commit) {
        echo "DRY RUN ONLY - no data was written\n";
        exit(0);
    }

    $hash = password_hash(PASSWORD, PASSWORD_DEFAULT);
    $answerHash = password_hash('test', PASSWORD_DEFAULT);
    $insertUser = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, security_question, security_answer_hash, status) VALUES (:username, :email, :password_hash, 'athlete', 'test', :answer_hash, 'active')");
    $insertPlayer = $pdo->prepare("INSERT INTO players (user_id, display_name, real_name, gender, province, eligibility_status) VALUES (:user_id, :display_name, :real_name, 'female', 'นครราชสีมา', 'verified')");
    $updateMember = $pdo->prepare('UPDATE team_members SET player_id = :new_player_id WHERE team_member_id = :team_member_id');
    $updateCaptain = $pdo->prepare('UPDATE teams SET captain_player_id = :captain_player_id WHERE team_id = :team_id');

    $pdo->beginTransaction();
    $number = 0;
    foreach ($teams as $teamIndex => $team) {
        $memberStmt->execute(['team_id' => $team['team_id']]);
        $members = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
        $newCaptainId = null;
        foreach ($members as $memberIndex => $member) {
            $number++;
            $username = sprintf('%s%04d', USER_PREFIX, $number);
            $displayName = sprintf('female player %02d-%02d', $teamIndex + 1, $memberIndex + 1);
            $insertUser->execute([
                'username' => $username,
                'email' => $username . EMAIL_DOMAIN,
                'password_hash' => $hash,
                'answer_hash' => $answerHash,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $insertPlayer->execute([
                'user_id' => $userId,
                'display_name' => $displayName,
                'real_name' => sprintf('Full Test Female %04d', $number),
            ]);
            $newPlayerId = (int) $pdo->lastInsertId();
            $updateMember->execute(['new_player_id' => $newPlayerId, 'team_member_id' => $member['team_member_id']]);
            if ((int) $member['player_id'] === (int) $team['captain_player_id']) $newCaptainId = $newPlayerId;
        }
        if ($newCaptainId === null) throw new RuntimeException('Could not map team captain.');
        $updateCaptain->execute(['captain_player_id' => $newCaptainId, 'team_id' => $team['team_id']]);
    }
    $pdo->commit();
    echo "Created 256 female Players and assigned them to Team 01-32.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Stopped: ' . $exception->getMessage() . "\n");
    exit(1);
}
