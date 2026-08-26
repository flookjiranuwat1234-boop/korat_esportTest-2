<?php
// pages/team-manage.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/upload.php';
require_once '../includes/team_roles.php';
require_once '../includes/tournament_categories.php';
requireLogin();
ensureTeamMemberRolesTable($pdo);
ensureTournamentCategorySchema($pdo);

$teamId = (int) ($_GET['id'] ?? 0);
$error = '';
$success = isset($_GET['created']) ? 'สร้างทีมเรียบร้อยแล้ว คุณเป็นกัปตันทีม' : '';

$stmt = $pdo->prepare("SELECT player_id FROM players WHERE user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$myPlayerId = $stmt->fetchColumn();

$tStmt = $pdo->prepare("
    SELECT t.*, g.name AS game_name, g.game_id AS game_id
    FROM teams t JOIN games g ON g.game_id = t.game_id
    WHERE t.team_id = :id
");
$tStmt->execute(['id' => $teamId]);
$team = $tStmt->fetch();

if (!$team) {
    die('ไม่พบทีมนี้');
}

$isCaptain = ($team['captain_player_id'] == $myPlayerId);
$rosterLockStmt = $pdo->prepare('SELECT tr.roster_locked_at, COALESCE(tour.roster_lock_at, tour.checkin_close_at) AS roster_lock_deadline
    FROM tournament_registrations tr
    JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
    WHERE tr.team_id = :team_id AND tr.status = \'approved\'
    ORDER BY tr.tournament_registration_id DESC LIMIT 1');
$rosterLockStmt->execute(['team_id' => $teamId]);
$rosterLock = $rosterLockStmt->fetch();
$rosterLocked = $rosterLock && ($rosterLock['roster_locked_at'] || ($rosterLock['roster_lock_deadline'] && strtotime($rosterLock['roster_lock_deadline']) <= time()));

// อัปโหลดโลโก้ทีม (กัปตันเท่านั้น)
if ($isCaptain && $_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'update_logo') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        try {
            $logoPath = handleImageUpload($_FILES['logo'] ?? null, 'team_logos');
            if (!$logoPath) {
                $error = 'กรุณาเลือกไฟล์รูปโลโก้';
            } else {
                deleteUploadedImage($team['logo_path']);
                $pdo->prepare("UPDATE teams SET logo_path = :logo WHERE team_id = :id")
                    ->execute(['logo' => $logoPath, 'id' => $teamId]);
                $team['logo_path'] = $logoPath; // อัปเดตตัวแปรในหน้านี้ให้ตรงทันที ไม่ต้อง query ใหม่
                $success = 'อัปเดตโลโก้ทีมเรียบร้อยแล้ว';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// เพิ่มสมาชิก (กัปตันเท่านั้น) — ค้นหาจากชื่อในเกม
if ($isCaptain && !$rosterLocked && $_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'add_member') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $newPlayerId = (int) $_POST['player_id'];

        $check = $pdo->prepare("SELECT team_member_id FROM team_members WHERE team_id = :tid AND player_id = :pid AND is_active = 1");
        $check->execute(['tid' => $teamId, 'pid' => $newPlayerId]);

        if ($check->fetch()) {
            $error = 'ผู้เล่นนี้อยู่ในทีมอยู่แล้ว';
        } else {
            $add = $pdo->prepare("INSERT INTO team_members (team_id, player_id, member_roles, in_game_role, is_active, joined_at) VALUES (:tid, :pid, 'player', 'player', 1, NOW())");
            $add->execute(['tid' => $teamId, 'pid' => $newPlayerId]);
            syncTeamMemberRoles($pdo, (int) $pdo->lastInsertId(), ['player']);
            $success = 'เพิ่มสมาชิกเรียบร้อยแล้ว';
        }
    }
}

// เปลี่ยนหลายบทบาทของสมาชิก (กัปตันจัดการได้เฉพาะทีมตัวเอง)
if ($isCaptain && !$rosterLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_member_roles') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $memberId = (int) ($_POST['team_member_id'] ?? 0);
        $roles = normalizeTeamRoles($_POST['member_roles'] ?? []);
        $memberCheck = $pdo->prepare('SELECT team_member_id FROM team_members WHERE team_member_id = :id AND team_id = :tid AND is_active = 1');
        $memberCheck->execute(['id' => $memberId, 'tid' => $teamId]);
        if (!$memberCheck->fetchColumn()) {
            $error = 'ไม่พบสมาชิกที่กำลังแก้ไขในทีมนี้';
        } elseif (!$roles) {
            $error = 'ต้องเลือกบทบาทอย่างน้อย 1 บทบาท';
        } else {
            syncTeamMemberRoles($pdo, $memberId, $roles);
            $success = 'บันทึกบทบาทสมาชิกเรียบร้อยแล้ว';
        }
    }
}

// เอาสมาชิกออก (กัปตันเท่านั้น เอาตัวเองออกไม่ได้)
if ($isCaptain && !$rosterLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_member') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $memberId = (int) ($_POST['player_id'] ?? 0);
        if ($memberId <= 0 || $memberId === (int) $myPlayerId) {
            $error = 'ไม่สามารถนำกัปตันออกจากทีมได้';
        } else {
            $pdo->prepare("UPDATE team_members SET is_active = 0, left_at = NOW() WHERE team_id = :tid AND player_id = :pid")
                ->execute(['tid' => $teamId, 'pid' => $memberId]);
            $success = 'เอาสมาชิกออกจากทีมแล้ว';
        }
    }
}

if ($isCaptain && $rosterLocked && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add_member', 'update_member_roles', 'remove_member'], true)) {
    $error = 'Tournament Roster ถูกล็อกแล้ว กรุณาให้ Admin ปลดล็อกหรือแก้ไขพร้อมบันทึกเหตุผล';
}

// ค้นหาผู้เล่นเพื่อเพิ่มเข้าทีม (ค้นได้ทุกคนในระบบ ไม่จำกัดว่าเคยอยู่ทีมไหน)
$searchResults = [];
$q = trim($_GET['q'] ?? '');
if ($isCaptain && $q !== '') {
    $s = $pdo->prepare("
        SELECT player_id, display_name FROM players
        WHERE display_name LIKE :q AND player_id != :me
        LIMIT 15
    ");
    $s->execute(['q' => "%{$q}%", 'me' => $myPlayerId]);
    $searchResults = $s->fetchAll();
}

$members = $pdo->prepare("
    SELECT tm.team_member_id, p.player_id, p.display_name, tm.in_game_role
    FROM team_members tm JOIN players p ON p.player_id = tm.player_id
    WHERE tm.team_id = :tid AND tm.is_active = 1
");
$members->execute(['tid' => $teamId]);
$members = $members->fetchAll();
foreach ($members as &$member) {
    $member['role_codes'] = getTeamMemberRoles($pdo, (int) $member['team_member_id']);
}
unset($member);

$csrfToken = generateCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: ($success ?? 'ดำเนินการเรียบร้อยแล้ว'));
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'team-manage.php'), true, 303);
    exit;
}
$flash = consumeFlashMessage();
if ($flash) $error = $flash['type'] === 'error' ? $flash['message'] : ($success = $flash['message']);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>จัดการทีม - <?php echo htmlspecialchars($team['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/public_nav.php'; ?>

    <section class="content">
        <div class="profile-header">
            <img src="<?php echo $team['logo_path'] ? '../assets/' . htmlspecialchars($team['logo_path']) : '../assets/img/team-placeholder.png'; ?>"
                 alt="<?php echo htmlspecialchars($team['name']); ?>" class="profile-avatar">
            <div>
                <h1><?php echo htmlspecialchars($team['name']); ?></h1>
                <p><?php echo htmlspecialchars($team['game_name']); ?></p>
            </div>
        </div>

        <?php if ($error): ?><p class="error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
        <?php if ($success): ?><p class="success"><?php echo htmlspecialchars($success); ?></p><?php endif; ?>
        <?php if ($rosterLocked): ?>
            <p class="error">Tournament Roster ถูกล็อกแล้ว การแก้สมาชิกทีมปัจจุบันจะไม่กระทบ Roster ที่อนุมัติแล้ว และต้องให้ Admin ปลดล็อกก่อน</p>
        <?php else: ?>
            <p>การแก้สมาชิกทีมปัจจุบันจะไม่เปลี่ยน Tournament Roster ที่ส่งและอนุมัติแล้ว</p>
        <?php endif; ?>

        <?php if ($isCaptain && !$rosterLocked): ?>
            <form method="POST" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; max-width:none; margin-bottom:1.5rem;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_logo">
                <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required>
                <button type="submit">อัปโหลดโลโก้ทีม</button>
            </form>
        <?php endif; ?>

        <h2>สมาชิกทีม</h2>
        <table class="public-table">
            <tr><th>ชื่อในเกม</th><th>ตำแหน่ง</th><?php if ($isCaptain): ?><th>จัดการ</th><?php endif; ?></tr>
            <?php foreach ($members as $m): ?>
            <tr>
                <td>
                    <?php echo htmlspecialchars($m['display_name']); ?>
                    <?php if ($m['player_id'] == $team['captain_player_id']): ?> (กัปตัน)<?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars(implode(', ', $m['role_codes'] ?: ['-'])); ?></td>
                <?php if ($isCaptain && !$rosterLocked): ?>
                <td>
                    <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_member_roles">
                        <input type="hidden" name="team_member_id" value="<?php echo (int) $m['team_member_id']; ?>">
                        <?php foreach (allowedTeamRoles() as $role): ?>
                            <?php $roleLabels = ['manager' => 'ผู้จัดการทีม', 'coach' => 'โค้ช', 'player' => 'นักกีฬาหลัก', 'substitute' => 'นักกีฬาสำรอง']; ?>
                            <label><input type="checkbox" name="member_roles[]" value="<?php echo htmlspecialchars($role); ?>" <?php echo in_array($role, $m['role_codes'], true) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($roleLabels[$role]); ?></label>
                        <?php endforeach; ?>
                        <button type="submit">บันทึกตำแหน่ง</button>
                    </form>
                    <?php if ($m['player_id'] != $myPlayerId): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('เอาสมาชิกคนนี้ออกจากทีม?')">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="remove_member">
                            <input type="hidden" name="player_id" value="<?php echo (int) $m['player_id']; ?>">
                            <button type="submit">เอาออก</button>
                        </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php if ($isCaptain && !$rosterLocked): ?>
            <h2>เพิ่มสมาชิก</h2>
            <form method="GET">
                <input type="hidden" name="id" value="<?php echo $teamId; ?>">
                <input type="text" name="q" placeholder="ค้นหาชื่อในเกม" value="<?php echo htmlspecialchars($q); ?>">
                <button type="submit">ค้นหา</button>
            </form>

            <?php if ($q !== ''): ?>
                <?php if (count($searchResults) == 0): ?>
                    <p>ไม่พบผู้เล่นที่ตรงกับคำค้นหา</p>
                <?php endif; ?>
                <?php foreach ($searchResults as $r): ?>
                    <div class="claim-result">
                        <span><?php echo htmlspecialchars($r['display_name']); ?></span>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="add_member">
                            <input type="hidden" name="player_id" value="<?php echo $r['player_id']; ?>">
                            <button type="submit">เพิ่มเข้าทีม</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php else: ?>
            <p><em>เฉพาะกัปตันทีมเท่านั้นที่แก้ไขสมาชิกได้</em></p>
        <?php endif; ?>

        <p><a href="my-team.php">&larr; กลับไปหน้าทีมของฉัน</a></p>
    </section>
</body>
</html>
