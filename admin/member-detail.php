<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireRole('admin');

$userId = (int) ($_GET['id'] ?? 0);
$error = '';
$success = '';
$csrfToken = generateCsrfToken();

if ($userId <= 0) {
    header('Location: manage-members.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } elseif (($_POST['action'] ?? '') === 'update_member') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $realName = trim($_POST['real_name'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $birthDate = trim($_POST['birth_date'] ?? '');
        $province = trim($_POST['province'] ?? '');

        if ($username === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'กรุณากรอก Username และ Email ให้ถูกต้อง';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET username = :username, email = :email WHERE user_id = :id')
                    ->execute(['username' => $username, 'email' => $email, 'id' => $userId]);

                $playerStmt = $pdo->prepare('SELECT player_id FROM players WHERE user_id = :id');
                $playerStmt->execute(['id' => $userId]);
                $playerId = $playerStmt->fetchColumn();
                if ($playerId) {
                    $pdo->prepare('UPDATE players SET display_name = :display_name, real_name = :real_name,
                        gender = :gender, birth_date = :birth_date, province = :province WHERE player_id = :player_id')
                        ->execute([
                            'display_name' => $displayName,
                            'real_name' => $realName !== '' ? $realName : null,
                            'gender' => $gender !== '' ? $gender : null,
                            'birth_date' => $birthDate !== '' ? $birthDate : null,
                            'province' => $province !== '' ? $province : null,
                            'player_id' => $playerId,
                        ]);
                }
                $pdo->commit();
                $success = 'บันทึกข้อมูลสมาชิกเรียบร้อยแล้ว';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e instanceof PDOException && $e->getCode() === '23000'
                    ? 'Username หรือ Email นี้ถูกใช้งานแล้ว'
                    : 'ไม่สามารถบันทึกข้อมูลได้';
            }
        }
    } elseif (($_POST['action'] ?? '') === 'update_account_status') {
        $newStatus = $_POST['status'] ?? '';
        $reason = trim($_POST['suspension_reason'] ?? '');
        if (!in_array($newStatus, ['active', 'suspended', 'disabled'], true)) {
            $error = 'สถานะบัญชีไม่ถูกต้อง';
        } elseif ($userId === (int) $_SESSION['user_id'] && $newStatus !== 'active') {
            $error = 'ไม่สามารถระงับหรือปิดใช้งานบัญชีของตัวเองได้';
        } elseif ($newStatus === 'active') {
            $pdo->prepare("UPDATE users SET status='active', suspended_at=NULL, suspended_by=NULL,
                suspension_reason=NULL, reactivated_at=NOW() WHERE user_id=:id")->execute(['id'=>$userId]);
            $success = 'เปิดใช้งานบัญชีเรียบร้อยแล้ว';
        } else {
            $pdo->prepare('UPDATE users SET status=:status, suspended_at=NOW(), suspended_by=:admin,
                suspension_reason=:reason, reactivated_at=NULL WHERE user_id=:id')
                ->execute(['status'=>$newStatus,'admin'=>$_SESSION['user_id'],'reason'=>$reason ?: 'ดำเนินการโดยผู้ดูแลระบบ','id'=>$userId]);
            $success = $newStatus === 'suspended' ? 'ระงับบัญชีเรียบร้อยแล้ว' : 'ปิดใช้งานบัญชีเรียบร้อยแล้ว';
        }
    }
}

$stmt = $pdo->prepare('SELECT u.*, p.player_id, p.display_name, p.real_name, p.gender, p.birth_date,
    p.eligibility_status, p.avatar_path, p.bio, p.province
    FROM users u LEFT JOIN players p ON p.user_id = u.user_id WHERE u.user_id = :id');
$stmt->execute(['id' => $userId]);
$member = $stmt->fetch();
if (!$member) {
    http_response_code(404);
    exit('ไม่พบสมาชิก');
}

$teams = [];
$registrations = [];
$rankings = [];
if ($member['player_id']) {
    $stmt = $pdo->prepare('SELECT tm.*, t.name AS team_name, t.status AS team_status, t.captain_player_id,
        g.name AS game_name FROM team_members tm JOIN teams t ON t.team_id = tm.team_id
        LEFT JOIN games g ON g.game_id = t.game_id WHERE tm.player_id = :pid ORDER BY tm.is_active DESC, tm.joined_at DESC');
    $stmt->execute(['pid' => $member['player_id']]);
    $teams = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT tr.tournament_registration_id, tr.status, tr.participation_status,
        tr.registered_at, tn.name AS tournament_name, COALESCE(t.name, roster_team.name) AS team_name,
        tc.name AS category_name, trm.member_roles, trm.is_starter, trm.checkin_status
        FROM tournament_registration_members trm
        JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
        JOIN tournaments tn ON tn.tournament_id = tr.tournament_id
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
        LEFT JOIN teams t ON t.team_id = tr.team_id
        LEFT JOIN team_members old_tm ON old_tm.player_id = trm.player_id AND old_tm.team_id = tr.team_id
        LEFT JOIN teams roster_team ON roster_team.team_id = old_tm.team_id
        WHERE trm.player_id = :pid ORDER BY tr.registered_at DESC');
    $stmt->execute(['pid' => $member['player_id']]);
    $registrations = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT pr.*, g.name AS game_name FROM player_rankings pr
        JOIN games g ON g.game_id = pr.game_id WHERE pr.player_id = :pid ORDER BY pr.points DESC');
    $stmt->execute(['pid' => $member['player_id']]);
    $rankings = $stmt->fetchAll();
}

function h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดสมาชิก - Korat Esport</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-slate-100 text-slate-800">
<main class="max-w-7xl mx-auto p-6 space-y-6">
    <div class="flex items-center justify-between gap-4">
        <div><a href="manage-members.php" class="text-sm text-orange-600 hover:underline"><i class="fa-solid fa-arrow-left"></i> กลับหน้าสมาชิก</a>
            <h1 class="text-2xl font-bold mt-2">รายละเอียดสมาชิก: <?= h($member['username']); ?></h1>
            <p class="text-sm text-slate-500">ข้อมูลบัญชี นักกีฬา ทีม และประวัติ Tournament</p></div>
        <?php if ($member['player_id']): ?><a target="_blank" href="../pages/player-profile.php?id=<?= (int) $member['player_id']; ?>" class="px-4 py-2 bg-slate-800 text-white rounded-lg">ดูโปรไฟล์สาธารณะ</a><?php endif; ?>
    </div>
    <?php if ($error): ?><div class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg"><?= h($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg"><?= h($success); ?></div><?php endif; ?>

    <section class="bg-white rounded-2xl shadow-sm border p-6">
        <div class="flex flex-wrap gap-2 mb-5"><span class="px-3 py-1 rounded-full bg-orange-50 text-orange-700 font-semibold"><?= h($member['role']); ?></span>
            <span class="px-3 py-1 rounded-full <?= $member['status'] === 'active' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?> font-semibold"><?= h($member['status']); ?></span>
            <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-600">สมัคร <?= h($member['created_at']); ?></span>
            <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-600">เข้าใช้ล่าสุด <?= h($member['last_login_at'] ?: '-'); ?></span></div>
        <form method="post" class="grid md:grid-cols-3 gap-4">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>"><input type="hidden" name="action" value="update_member">
            <?php foreach ([['username','Username',$member['username'],'text'],['email','Email',$member['email'],'email'],['display_name','ชื่อในเกม',$member['display_name'],'text'],['real_name','ชื่อ-นามสกุล',$member['real_name'],'text'],['birth_date','วันเกิด',$member['birth_date'],'date'],['province','จังหวัด',$member['province'],'text']] as $f): ?>
                <label class="text-sm font-medium"><?= h($f[1]); ?><input type="<?= h($f[3]); ?>" name="<?= h($f[0]); ?>" value="<?= h($f[2]); ?>" <?= in_array($f[0], ['display_name','real_name','birth_date','province'], true) && !$member['player_id'] ? 'disabled' : ''; ?> class="mt-1 w-full border rounded-lg px-3 py-2 disabled:bg-slate-100"></label>
            <?php endforeach; ?>
            <label class="text-sm font-medium">เพศ<input type="text" name="gender" value="<?= h($member['gender']); ?>" <?= !$member['player_id'] ? 'disabled' : ''; ?> class="mt-1 w-full border rounded-lg px-3 py-2 disabled:bg-slate-100" placeholder="ตามข้อมูลที่ผู้สมัครระบุ"></label>
            <div class="md:col-span-3"><button class="px-5 py-2.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg font-semibold">บันทึกข้อมูล</button></div>
        </form>
        <form method="post" class="mt-6 pt-5 border-t grid md:grid-cols-3 gap-4" onsubmit="return confirm('ยืนยันการเปลี่ยนสถานะบัญชีนี้?')">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>"><input type="hidden" name="action" value="update_account_status">
            <label class="text-sm font-medium">สถานะบัญชี<select name="status" class="mt-1 w-full border rounded-lg px-3 py-2"><option value="active" <?= $member['status']==='active'?'selected':''; ?>>ใช้งานปกติ</option><option value="suspended" <?= $member['status']==='suspended'?'selected':''; ?>>ระงับบัญชี</option><option value="disabled" <?= $member['status']==='disabled'?'selected':''; ?>>ปิดใช้งาน</option></select></label>
            <label class="text-sm font-medium md:col-span-2">เหตุผล<input name="suspension_reason" value="<?= h($member['suspension_reason']); ?>" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="ระบุเหตุผลเมื่อระงับหรือปิดใช้งาน"></label>
            <div class="md:col-span-3"><button class="px-5 py-2.5 bg-slate-800 text-white rounded-lg font-semibold">บันทึกสถานะบัญชี</button></div>
        </form>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border overflow-hidden"><div class="p-5 border-b"><h2 class="font-bold text-lg">ทีมและบทบาท</h2></div>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="p-3 text-left">ทีม</th><th class="p-3 text-left">เกม</th><th class="p-3 text-left">บทบาท</th><th class="p-3">ช่วงเวลา</th><th class="p-3">สถานะ</th></tr></thead><tbody>
        <?php foreach ($teams as $team): ?><tr class="border-t"><td class="p-3"><a class="text-orange-600 font-semibold hover:underline" href="team-detail.php?id=<?= (int) $team['team_id']; ?>"><?= h($team['team_name']); ?></a></td><td class="p-3"><?= h($team['game_name'] ?: '-'); ?></td><td class="p-3"><?= h($team['member_roles']); ?><?= (int)$team['captain_player_id'] === (int)$member['player_id'] ? ', captain' : ''; ?></td><td class="p-3 text-center"><?= h($team['joined_at']); ?> – <?= h($team['left_at'] ?: 'ปัจจุบัน'); ?></td><td class="p-3 text-center"><?= $team['is_active'] ? 'สมาชิกปัจจุบัน' : 'อดีตสมาชิก'; ?></td></tr><?php endforeach; ?>
        <?php if (!$teams): ?><tr><td colspan="5" class="p-6 text-center text-slate-400">ยังไม่มีข้อมูลทีม</td></tr><?php endif; ?>
        </tbody></table></div></section>

    <section class="bg-white rounded-2xl shadow-sm border overflow-hidden"><div class="p-5 border-b"><h2 class="font-bold text-lg">Tournament Roster และประวัติการแข่งขัน</h2><p class="text-xs text-slate-500">อ้างอิง Roster ของ Tournament ไม่เปลี่ยนตามทีมปัจจุบัน</p></div>
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="p-3 text-left">Tournament</th><th class="p-3 text-left">ทีมตอนสมัคร</th><th class="p-3">ประเภท</th><th class="p-3">บทบาท</th><th class="p-3">อนุมัติ</th><th class="p-3">Check-in</th></tr></thead><tbody>
        <?php foreach ($registrations as $reg): ?><tr class="border-t"><td class="p-3 font-semibold"><?= h($reg['tournament_name']); ?></td><td class="p-3"><?= h($reg['team_name'] ?: 'ผู้เล่นเดี่ยว'); ?></td><td class="p-3 text-center"><?= h($reg['category_name'] ?: 'Open'); ?></td><td class="p-3 text-center"><?= h($reg['member_roles']); ?></td><td class="p-3 text-center"><?= h($reg['status']); ?></td><td class="p-3 text-center"><?= h($reg['checkin_status']); ?></td></tr><?php endforeach; ?>
        <?php if (!$registrations): ?><tr><td colspan="6" class="p-6 text-center text-slate-400">ยังไม่มี Tournament Roster</td></tr><?php endif; ?>
        </tbody></table></div></section>

    <section class="bg-white rounded-2xl shadow-sm border p-5"><h2 class="font-bold text-lg mb-4">Ranking รายบุคคลแยกตามเกม</h2><div class="grid md:grid-cols-3 gap-3">
        <?php foreach ($rankings as $rank): ?><div class="border rounded-xl p-4"><div class="font-semibold"><?= h($rank['game_name']); ?></div><div class="text-2xl font-bold text-orange-600 mt-2"><?= number_format((float)$rank['points']); ?> คะแนน</div><div class="text-xs text-slate-500 mt-1">ชนะ <?= (int)$rank['wins']; ?> / แพ้ <?= (int)$rank['losses']; ?> · <?= h($rank['category']); ?></div></div><?php endforeach; ?>
        <?php if (!$rankings): ?><p class="text-slate-400">ยังไม่มีข้อมูล Ranking</p><?php endif; ?>
    </div></section>
</main></body></html>
