<?php
// admin/manage-members.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/team_roles.php';
requireRole('admin');
ensureTeamMemberRolesTable($pdo);

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$error = '';
$success = '';
$q = trim($_GET['q'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$profileFilter = $_GET['profile'] ?? ''; // '' = ทั้งหมด, 'none' = สมัครแล้วแต่ยังไม่มีโปรไฟล์, 'has' = มีโปรไฟล์แล้ว
$statusFilter = $_GET['status'] ?? '';

// สร้าง CSRF Token สำหรับแบบฟอร์ม POST
$csrfToken = generateCsrfToken();

$tempPasswordShown = null;
$tempPasswordForUsername = null;

// ================= จัดการ Action แบบ POST (เพิ่มความปลอดภัย ป้องกัน CSRF) =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่ (Invalid CSRF Token)';
    } else {
        $action = $_POST['action'] ?? '';
        $userId = (int) ($_POST['user_id'] ?? 0);

        // 1. ระงับ/ปลดระงับบัญชี
        if ($action === 'toggle_status' && $userId > 0) {
            // กันแอดมินระงับบัญชีตัวเอง
            if ($userId == $_SESSION['user_id']) {
                $error = 'ไม่สามารถระงับบัญชีของตัวเองได้';
            } else {
                $stmt = $pdo->prepare("SELECT status FROM users WHERE user_id = :id");
                $stmt->execute(['id' => $userId]);
                $currentStatus = $stmt->fetchColumn();

                if ($currentStatus) {
                    $newStatus = ($currentStatus == 'active') ? 'suspended' : 'active';
                    if ($newStatus === 'suspended') {
                        $pdo->prepare("
                            UPDATE users
                            SET status = 'suspended',
                                suspended_at = NOW(),
                                suspended_by = :suspended_by,
                                suspension_reason = :reason,
                                reactivated_at = NULL
                            WHERE user_id = :id
                        ")->execute([
                            'suspended_by' => $_SESSION['user_id'],
                            'reason' => 'ระงับโดยผู้ดูแลระบบ',
                            'id' => $userId,
                        ]);
                    } else {
                        $pdo->prepare("
                            UPDATE users
                            SET status = 'active',
                                suspended_at = NULL,
                                suspended_by = NULL,
                                suspension_reason = NULL,
                                reactivated_at = NOW()
                            WHERE user_id = :id
                        ")->execute(['id' => $userId]);
                    }
                    $success = $newStatus == 'suspended' ? 'ระงับบัญชีแล้ว' : 'ปลดระงับบัญชีแล้ว';
                }
            }
        }
        
        // 2. Admin รีเซ็ตรหัสผ่านให้สมาชิก
        elseif ($action === 'reset_password' && $userId > 0) {
            $tempPassword = generateTempPassword();
            setNewPassword($pdo, $userId, $tempPassword);

            $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = :id");
            $stmt->execute(['id' => $userId]);
            $tempPasswordForUsername = $stmt->fetchColumn();
            $tempPasswordShown = $tempPassword;
        }

        // แก้ไขข้อมูลบัญชีและโปรไฟล์นักกีฬาในหน้าต่างเดียวกัน
        elseif ($action === 'update_member' && $userId > 0) {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $displayName = trim($_POST['display_name'] ?? '');
            $realName = trim($_POST['real_name'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $birthDate = trim($_POST['birth_date'] ?? '');
            $province = trim($_POST['province'] ?? '');

            if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
                        : 'ไม่สามารถบันทึกข้อมูลสมาชิกได้';
                }
            }
        }

        // สร้างโปรไฟล์นักกีฬาให้กับบัญชีที่ยังไม่มีโปรไฟล์
        elseif ($action === 'create_player') {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            $displayName = trim($_POST['display_name'] ?? '');
            if ($targetUserId <= 0 || $displayName === '') {
                $error = 'กรุณาเลือกบัญชีผู้ใช้และกรอกชื่อในเกม';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO players (user_id, display_name) VALUES (:user_id, :display_name)');
                    $stmt->execute(['user_id' => $targetUserId, 'display_name' => $displayName]);
                    $success = 'เพิ่มโปรไฟล์นักกีฬาเรียบร้อยแล้ว';
                } catch (PDOException $e) {
                    $error = $e->getCode() === '23000' ? 'บัญชีนี้มีโปรไฟล์นักกีฬาอยู่แล้ว' : 'ไม่สามารถเพิ่มโปรไฟล์นักกีฬาได้';
                }
            }
        }

        // ลบบัญชีสมาชิกที่ยังไม่มีประวัติการแข่งขัน
        elseif ($action === 'delete_member' && $userId > 0) {
            if ($userId === (int) $_SESSION['user_id']) {
                $error = 'ไม่สามารถลบบัญชีของตัวเองได้';
            } else {
                try {
                    $stmt = $pdo->prepare('SELECT player_id, role FROM users u LEFT JOIN players p ON p.user_id = u.user_id WHERE u.user_id = :id');
                    $stmt->execute(['id' => $userId]);
                    $target = $stmt->fetch();
                    if (!$target || $target['role'] === 'admin') {
                        $error = 'ไม่สามารถลบบัญชีผู้ดูแลระบบได้';
                    } else {
                        $playerId = (int) ($target['player_id'] ?? 0);
                        if ($playerId > 0) {
                            $history = $pdo->prepare('SELECT
                                (SELECT COUNT(*) FROM player_checkin_history WHERE player_id = :pid) +
                                (SELECT COUNT(*) FROM tournament_registration_members WHERE player_id = :pid) +
                                (SELECT COUNT(*) FROM player_rankings WHERE player_id = :pid)');
                            $history->execute(['pid' => $playerId]);
                            if ((int) $history->fetchColumn() > 0) {
                                $error = 'ไม่สามารถลบสมาชิกนี้ได้ เพราะมีประวัติการแข่งขัน ให้ปิดใช้งานบัญชีแทน';
                            }
                        }
                        if ($error === '') {
                            $pdo->beginTransaction();
                            $pdo->prepare('DELETE FROM team_members WHERE player_id = :pid')->execute(['pid' => $playerId]);
                            if ($playerId > 0) {
                                $pdo->prepare('DELETE FROM players WHERE player_id = :pid')->execute(['pid' => $playerId]);
                            }
                            $pdo->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $userId]);
                            $pdo->commit();
                            $success = 'ลบบัญชีสมาชิกเรียบร้อยแล้ว';
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'ไม่สามารถลบบัญชีนี้ได้ เนื่องจากมีข้อมูลที่อ้างอิงอยู่';
                }
            }
        }

        // เพิ่มทีมใหม่
        elseif ($action === 'create_team') {
            $teamName = trim($_POST['team_name'] ?? '');
            if ($teamName === '') {
                $error = 'กรุณากรอกชื่อทีม';
            } else {
                $pdo->prepare('INSERT INTO teams (name) VALUES (:name)')->execute(['name' => $teamName]);
                $success = 'เพิ่มทีมเรียบร้อยแล้ว';
            }
        }

        // 3. อัปเดตข้อมูลทีม (หน้าต่างลอย Manage Team)
        elseif ($action === 'update_team') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $teamName = trim($_POST['team_name'] ?? '');

            if ($teamId > 0 && $teamName !== '') {
                $stmt = $pdo->prepare("UPDATE teams SET name = :name WHERE team_id = :id");
                $stmt->execute(['name' => $teamName, 'id' => $teamId]);
                $success = 'อัปเดตข้อมูลทีมเรียบร้อยแล้ว';
            } else {
                $error = 'กรุณากรอกชื่อทีมให้ถูกต้อง';
            }
        }

        // 4. เปลี่ยนบทบาทสมาชิกในทีม
        elseif ($action === 'update_team_roles') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $memberRoles = $_POST['member_roles'] ?? [];
            if ($teamId > 0 && is_array($memberRoles)) {
                $memberStmt = $pdo->prepare('SELECT team_member_id FROM team_members WHERE team_id = :tid AND player_id = :pid AND is_active = 1');
                foreach ($memberRoles as $playerId => $roles) {
                    $memberStmt->execute(['tid' => $teamId, 'pid' => (int) $playerId]);
                    $teamMemberId = (int) $memberStmt->fetchColumn();
                    if ($teamMemberId > 0 && is_array($roles)) {
                        $roles = normalizeTeamRoles($roles);
                        if (!$roles) {
                            $error = 'สมาชิกแต่ละคนต้องมีบทบาทอย่างน้อย 1 บทบาท';
                            continue;
                        }
                        syncTeamMemberRoles($pdo, $teamMemberId, $roles);
                    }
                }
                if ($error === '') $success = 'บันทึกตำแหน่งสมาชิกเรียบร้อยแล้ว';
            }
        }

        // เปลี่ยนบทบาทสมาชิกทีละคน (รองรับคำขอเดิม)
        elseif ($action === 'update_member_role') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $playerId = (int) ($_POST['player_id'] ?? 0);
            $newRole = trim($_POST['role_in_team'] ?? 'member');
            $newRole = ['leader' => 'manager', 'member' => 'player'][$newRole] ?? $newRole;

            if ($teamId > 0 && $playerId > 0) {
                $stmt = $pdo->prepare('SELECT team_member_id FROM team_members WHERE team_id = :tid AND player_id = :pid');
                $stmt->execute(['tid' => $teamId, 'pid' => $playerId]);
                $teamMemberId = (int) $stmt->fetchColumn();
                if ($teamMemberId > 0 && in_array($newRole, allowedTeamRoles(), true)) {
                    syncTeamMemberRoles($pdo, $teamMemberId, [$newRole]);
                    $success = 'ปรับเปลี่ยนบทบาทสมาชิกเรียบร้อยแล้ว';
                } else {
                    $error = 'บทบาทสมาชิกไม่ถูกต้อง';
                }
            }
        }

        // 5. ลบสมาชิกออกจากทีม
        elseif ($action === 'remove_team_member') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $playerId = (int) ($_POST['player_id'] ?? 0);
            if ($teamId > 0 && $playerId > 0) {
                $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = :tid AND player_id = :pid");
                $stmt->execute(['tid' => $teamId, 'pid' => $playerId]);
                $success = 'ลบสมาชิกออกจากทีมเรียบร้อยแล้ว';
            }
        }

        // ลบทีมที่ยังไม่ถูกใช้ในรายการแข่งขัน
        elseif ($action === 'delete_team') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            if ($teamId > 0) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM tournament_registrations WHERE team_id = :tid');
                $check->execute(['tid' => $teamId]);
                if ((int) $check->fetchColumn() > 0) {
                    $error = 'ไม่สามารถลบทีมนี้ได้ เพราะมีประวัติการสมัครแข่งขันอยู่';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('DELETE FROM team_members WHERE team_id = :tid')->execute(['tid' => $teamId]);
                        $pdo->prepare('DELETE FROM teams WHERE team_id = :tid')->execute(['tid' => $teamId]);
                        $pdo->commit();
                        $success = 'ลบทีมเรียบร้อยแล้ว';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'ไม่สามารถลบทีมนี้ได้ เนื่องจากมีข้อมูลที่อ้างอิงอยู่';
                    }
                }
            }
        }

        // 6. เพิ่มสมาชิกเข้าทีม
        elseif ($action === 'add_team_member') {
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $playerId = (int) ($_POST['player_id'] ?? 0);
            $roleInTeam = trim($_POST['role_in_team'] ?? 'member');

            if ($teamId > 0 && $playerId > 0) {
                // ตรวจสอบว่าเคยอยู่ในทีมแล้วหรือยัง
                $check = $pdo->prepare("SELECT player_id FROM team_members WHERE team_id = :tid AND player_id = :pid");
                $check->execute(['tid' => $teamId, 'pid' => $playerId]);
                if ($check->fetch()) {
                    $error = 'นักกีฬารายนี้อยู่ในทีมแล้ว';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO team_members (team_id, player_id, in_game_role, is_active, joined_at) VALUES (:tid, :pid, :role, 1, NOW())");
                    $stmt->execute(['tid' => $teamId, 'pid' => $playerId, 'role' => $roleInTeam]);
                    $success = 'เพิ่มสมาชิกเข้าทีมเรียบร้อยแล้ว';
                }
            }
        }
    }
}

// ================= ดึงข้อมูลสมาชิกเพื่อแสดงผลในตาราง =================
$sql = "
    SELECT u.user_id, u.username, u.email, u.role, u.status, u.created_at, u.last_login_at,
        p.player_id AS player_id, p.display_name, p.real_name, p.gender, p.birth_date, p.province,
        GROUP_CONCAT(DISTINCT CASE WHEN tm.is_active = 1 THEN t.name END ORDER BY t.name SEPARATOR ', ') AS team_names,
        GROUP_CONCAT(DISTINCT CASE WHEN tm.is_active = 1 THEN t.team_id END ORDER BY t.name SEPARATOR ',') AS team_ids,
        GROUP_CONCAT(DISTINCT CASE WHEN tm.is_active = 1 THEN g.name END ORDER BY g.name SEPARATOR ', ') AS game_names,
        (CASE WHEN p.player_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM player_checkin_history ch
            WHERE ch.player_id = p.player_id
        ) THEN 1 ELSE 0 END) AS has_played
    FROM users u
    LEFT JOIN players p ON p.user_id = u.user_id
    LEFT JOIN team_members tm ON tm.player_id = p.player_id
    LEFT JOIN teams t ON t.team_id = tm.team_id
    LEFT JOIN games g ON g.game_id = t.game_id
    WHERE 1=1
";
$params = [];
if ($q !== '') {
    $sql .= " AND (u.username LIKE :q OR u.email LIKE :q OR p.display_name LIKE :q OR p.real_name LIKE :q
        OR EXISTS (SELECT 1 FROM team_members tm2 JOIN teams t2 ON t2.team_id = tm2.team_id
            WHERE tm2.player_id = p.player_id AND tm2.is_active = 1 AND t2.name LIKE :q))";
    $params['q'] = "%{$q}%";
}
$allowedStatuses = ['active', 'suspended', 'disabled'];
if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= " AND u.status = :status";
    $params['status'] = $statusFilter;
}
if ($roleFilter === 'admin') {
    $sql .= " AND u.role = 'admin'";
} elseif ($roleFilter === 'athlete') {
    // "นักกีฬา" คือผู้ที่มีประวัติเช็คอินถาวรแล้ว
    $sql .= " AND u.role != 'admin' AND p.player_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM player_checkin_history ch
        WHERE ch.player_id = p.player_id
    )";
} elseif ($roleFilter === 'guest') {
    // "ทั่วไป" = สมัครแล้วแต่ยังไม่เคยเช็คอินแข่งจริง
    $sql .= " AND u.role != 'admin' AND NOT EXISTS (
        SELECT 1 FROM players p2
        JOIN player_checkin_history ch ON ch.player_id = p2.player_id
        WHERE p2.user_id = u.user_id
    )";
}
if ($profileFilter === 'none') {
    // สมัครสมาชิกแล้ว แต่ยังไม่เคยสร้าง/claim โปรไฟล์นักกีฬาเลย
    $sql .= " AND p.player_id IS NULL AND u.role != 'admin'";
} elseif ($profileFilter === 'has') {
    $sql .= " AND p.player_id IS NOT NULL";
} elseif ($profileFilter === 'confirmed') {
    // นักกีฬาตัวจริง: มีโปรไฟล์ + เคยเช็คอินเข้าแข่งจริงในตารางประวัติถาวร
    $sql .= " AND p.player_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM player_checkin_history ch
        WHERE ch.player_id = p.player_id
    )";
} elseif ($profileFilter === 'profile_only') {
    // มีโปรไฟล์แล้ว แต่ยังไม่เคยเช็คอินเข้าแข่งเลยสักครั้ง
    $sql .= " AND p.player_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM player_checkin_history ch
        WHERE ch.player_id = p.player_id
    )";
}
$sql .= " GROUP BY u.user_id, u.username, u.email, u.role, u.status, u.created_at, u.last_login_at,
    p.player_id, p.display_name, p.real_name, p.gender, p.birth_date, p.province
    ORDER BY u.created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// ดึงเฉพาะทีมที่สมาชิกในผลลัพธ์ปัจจุบันสังกัดอยู่ สำหรับ Modal ป๊อปอัพ
$teamsData = [];
$teamIds = [];
foreach ($members as $member) {
    foreach (explode(',', (string) ($member['team_ids'] ?? '')) as $teamId) {
        $teamId = (int) $teamId;
        if ($teamId > 0) $teamIds[$teamId] = $teamId;
    }
}
if ($teamIds) {
    $teamPlaceholders = implode(',', array_fill(0, count($teamIds), '?'));
    $teamStmt = $pdo->prepare("
    SELECT t.team_id, t.name AS team_name, g.name AS game_name
    FROM teams t
    LEFT JOIN games g ON g.game_id = t.game_id
    WHERE t.team_id IN ($teamPlaceholders)
    ORDER BY t.name ASC
");
    $teamStmt->execute(array_values($teamIds));
    while ($row = $teamStmt->fetch()) {
    $memStmt = $pdo->prepare("
        SELECT tm.team_member_id, tm.team_id, tm.player_id, tm.in_game_role AS role_in_team, p.display_name, p.real_name, u.username
        FROM team_members tm
        JOIN players p ON p.player_id = tm.player_id
        JOIN users u ON u.user_id = p.user_id
        WHERE tm.team_id = :tid
    ");
    $memStmt->execute(['tid' => $row['team_id']]);
    
    $teamMembers = $memStmt->fetchAll();
    foreach ($teamMembers as &$teamMember) {
        $teamMember['role_codes'] = getTeamMemberRoles($pdo, (int) $teamMember['team_member_id']);
    }
    unset($teamMember);

    $teamsData[$row['team_id']] = [
        'team_id' => $row['team_id'],
        'team_name' => $row['team_name'],
        'game_name' => $row['game_name'],
        'members' => $teamMembers
    ];
    }
}

// ดึงรายชื่อนักกีฬาสำหรับเพิ่มเข้าทีมใน Modal
$allPlayers = $pdo->query("SELECT p.player_id, p.display_name, u.username FROM players p JOIN users u ON u.user_id = p.user_id ORDER BY p.display_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลสมาชิก - Korat Esport</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:ital,wght@0,300;0,400;0,500;0,600;0,700;1,800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    // Additional context or code can be added here if necessary
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            orange: '#FF5500',
                            glow: '#FF6600',
                            lightbg: '#F4F6F9',
                            sidebar: '#0F172A',
                        }
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                        display: ['Orbitron', 'sans-serif']
                    }
                }
            }
        }
    </script>
    <style>
        html, body, * {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        *::-webkit-scrollbar {
            display: none;
            width: 0;
            height: 0;
        }
        body { background-color: #F4F6F9; }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover, .nav-item.active {
            background: rgba(255, 85, 0, 0.12);
            color: #FF5500;
            border-left: 4px solid #FF5500;
        }
    </style>
    <script>
        function copyPassword(password) {
            navigator.clipboard.writeText(password).then(() => {
                alert('คัดลอกรหัสผ่านชั่วคราวแล้ว!');
            });
        }
    </script>
</head>
<body class="text-slate-800 font-sans min-h-screen antialiased">

    <aside class="w-64 bg-brand-sidebar text-slate-300 flex flex-col fixed inset-y-0 left-0 z-50 shadow-xl">
        <div class="p-6 border-b border-slate-800 flex items-center gap-3">
            <img src="../assets/img/logo.png" alt="Korat Esport" class="h-10 w-auto filter drop-shadow" onError="this.src='https://placehold.co/80x80/0F172A/FF5500?text=KE';">
            <div>
                <h1 class="font-display font-black text-lg text-white tracking-wider">KORAT <span class="text-brand-orange">ESPORT</span></h1>
                <p class="text-[10px] tracking-widest text-slate-400 uppercase font-semibold">Admin Command Center</p>
            </div>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-1 text-sm font-medium">
            <a href="dashboard.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-chart-pie w-5 text-center"></i>
                <span>หน้าหลัก (Dashboard)</span>
            </a>
            <a href="manage-tournament.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-trophy w-5 text-center"></i>
                <span>จัดการทัวร์นาเมนต์</span>
            </a>
            <a href="manage-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-people-group w-5 text-center"></i>
                <span>ทีมสมัคร Tournament</span>
            </a>
            <a href="manage-members.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-users-gear w-5 text-center text-brand-orange"></i>
                <span>จัดการสมาชิก</span>
            </a>
            <a href="manage-news.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-newspaper w-5 text-center"></i>
                <span>จัดการข่าวสาร</span>
            </a>
            <a href="manage-gallery.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-images w-5 text-center"></i>
                <span>จัดการแกลเลอรี่</span>
            </a>
            <a href="recommended-lodging.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-hotel w-5 text-center"></i>
                <span>ที่พักแนะนำ</span>
            </a>
            <a href="record-match.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-pen-to-square w-5 text-center"></i>
                <span>บันทึกผลแมตช์</span>
            </a>
            <a href="checkin-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-user-check w-5 text-center"></i>
                <span>เช็คอินทีม</span>
            </a>
        </nav>

        <div class="p-4 border-t border-slate-800 bg-slate-950/50">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3 overflow-hidden">
                    <div class="w-9 h-9 rounded-full bg-brand-orange text-white flex items-center justify-center font-bold text-sm shrink-0">
                        <i class="fa-solid fa-user-shield"></i>
                    </div>
                    <div class="truncate">
                        <div class="text-sm font-bold text-white truncate">
                            <?= htmlspecialchars($currentUser['username'] ?? $currentUser['name'] ?? 'Admin User') ?>
                        </div>
                        <span class="inline-block text-[10px] font-semibold text-brand-orange bg-brand-orange/10 px-2 py-0.2 rounded uppercase">
                            <?= htmlspecialchars($currentUser['role'] ?? 'Administrator') ?>
                        </span>
                    </div>
                </div>
                <a href="../auth/logout.php" title="ออกจากระบบ" class="text-slate-400 hover:text-rose-400 transition-colors p-2 text-base">
                    <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </aside>

    <div class="ml-64 min-h-screen flex flex-col min-w-0">

        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    จัดการข้อมูลสมาชิก <span class="text-brand-orange">(USER MANAGEMENT)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">จัดการบัญชีผู้ใช้ ข้อมูลนักกีฬา และการสังกัดทีมในระดับระบบ</p>
            </div>
            
            <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
            </a>
        </header>

        <main class="p-8 space-y-6 flex-1">

            <?php if ($error): ?>
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-lg shrink-0 text-rose-500"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm flex items-center gap-3">
                    <i class="fa-solid fa-circle-check text-lg shrink-0 text-emerald-500"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($tempPasswordShown): ?>
                <div class="p-6 rounded-2xl bg-amber-50 border border-amber-300 text-amber-900 space-y-3 shadow-md">
                    <div class="flex items-center gap-3 border-b border-amber-200/80 pb-3">
                        <i class="fa-solid fa-key text-2xl text-amber-600"></i>
                        <div>
                            <h3 class="font-bold text-base">รีเซ็ตรหัสผ่านชั่วคราวสำเร็จ</h3>
                            <p class="text-xs text-amber-700">บัญชีผู้ใช้: <strong class="text-slate-900"><?php echo htmlspecialchars($tempPasswordForUsername); ?></strong></p>
                        </div>
                    </div>
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-white p-4 rounded-xl border border-amber-200">
                        <div>
                            <span class="text-xs text-slate-500 font-semibold block">รหัสผ่านชั่วคราว (Temporary Password)</span>
                            <span class="text-xl font-mono font-bold text-brand-orange tracking-wider"><?php echo htmlspecialchars($tempPasswordShown); ?></span>
                        </div>
                        <button onclick="copyPassword('<?php echo htmlspecialchars($tempPasswordShown); ?>')" 
                            class="px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs flex items-center gap-2 transition-all cursor-pointer shadow-sm">
                            <i class="fa-regular fa-copy"></i>
                            <span>คัดลอกรหัสผ่าน</span>
                        </button>
                    </div>
                    <p class="text-[11px] text-amber-700 italic">
                        * หมายเหตุ: รหัสผ่านนี้จะแสดงเฉพาะครั้งนี้เท่านั้น กรุณาคัดลอกไปแจ้งสมาชิก และแนะนำให้สมาชิกเปลี่ยนรหัสผ่านใหม่ทันทีหลังเข้าสู่ระบบ
                    </p>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <form method="GET" id="memberSearchForm" class="flex flex-col md:flex-row gap-3">
                    <div class="flex-1 relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-solid fa-magnifying-glass text-sm"></i>
                        </span>
                        <input type="text" name="q" id="memberSearchInput" placeholder="พิมพ์เพื่อค้นหาอัตโนมัติ... (ชื่อผู้ใช้ / อีเมล / ชื่อในเกม / ชื่อทีม)" value="<?php echo htmlspecialchars($q); ?>" autocomplete="off"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                    </div>

                    <div class="w-full md:w-48">
                        <select name="role" onchange="this.form.submit()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                            <option value="">ทุกบทบาท (Roles)</option>
                            <option value="admin" <?php echo $roleFilter == 'admin' ? 'selected' : ''; ?>>ผู้ดูแลระบบ</option>
                            <option value="athlete" <?php echo $roleFilter == 'athlete' ? 'selected' : ''; ?>>นักกีฬา</option>
                            <option value="guest" <?php echo $roleFilter == 'guest' ? 'selected' : ''; ?>>ผู้ใช้ทั่วไป</option>
                        </select>
                    </div>

                    <div class="w-full md:w-56">
                        <select name="profile" onchange="this.form.submit()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                            <option value="">ทุกสถานะโปรไฟล์</option>
                            <option value="none" <?php echo $profileFilter == 'none' ? 'selected' : ''; ?>>สมัครแล้วแต่ยังไม่มีโปรไฟล์</option>
                            <option value="profile_only" <?php echo $profileFilter == 'profile_only' ? 'selected' : ''; ?>>มีโปรไฟล์ แต่ยังไม่เคยแข่ง</option>
                            <option value="confirmed" <?php echo $profileFilter == 'confirmed' ? 'selected' : ''; ?>>นักกีฬาตัวจริง (เคยแข่งแล้ว)</option>
                            <option value="has" <?php echo $profileFilter == 'has' ? 'selected' : ''; ?>>มีโปรไฟล์ทั้งหมด (ทุกกรณี)</option>
                        </select>
                    </div>

                    <div class="w-full md:w-48">
                        <select name="status" onchange="this.form.submit()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                            <option value="">ทุกสถานะบัญชี</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>ใช้งานปกติ</option>
                            <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : ''; ?>>ระงับบัญชี</option>
                            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : ''; ?>>ปิดใช้งาน</option>
                        </select>
                    </div>

                    <button type="submit" 
                        class="px-6 py-2.5 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer">
                        <span>ค้นหา</span>
                    </button>
                    
                    <?php if ($q !== '' || $roleFilter !== '' || $profileFilter !== '' || $statusFilter !== ''): ?>
                        <a href="manage-members.php" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold text-sm flex items-center justify-center transition-all">
                            ล้างค่า
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-600 flex items-center gap-2">
                        <i class="fa-solid fa-users text-brand-orange"></i>
                        รายชื่อสมาชิกในระบบ (แสดงสูงสุด 200 รายการล่าสุด)
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="p-4">ชื่อผู้ใช้ (Username)</th>
                                <th class="p-4">อีเมล</th>
                                <th class="p-4 text-center">บทบาท</th>
                                <th class="p-4">นักกีฬา / ทีม / เกม</th>
                                <th class="p-4 text-center">สถานะ</th>
                                <th class="p-4">วันที่สมัคร</th>
                                <th class="p-4 text-right">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (count($members) == 0): ?>
                                <tr>
                                    <td colspan="7" class="p-8 text-center text-slate-400">
                                        <i class="fa-solid fa-user-slash text-3xl mb-2 block opacity-40"></i>
                                        ไม่พบข้อมูลสมาชิกที่ตรงกับเงื่อนไขการค้นหา
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($members as $m): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="p-4 font-bold text-slate-900">
                                    <?php if (!empty($m['player_id'])): ?>
                                        <a href="../pages/player-profile.php?id=<?php echo (int) $m['player_id']; ?>" title="เปิดหน้าโปรไฟล์ผู้เล่น" class="flex items-center gap-2 hover:text-brand-orange hover:underline transition-colors">
                                            <span class="w-8 h-8 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs shrink-0">
                                                <i class="fa-regular fa-user"></i>
                                            </span>
                                            <span><?php echo htmlspecialchars($m['username']); ?></span>
                                        </a>
                                    <?php else: ?>
                                        <div class="flex items-center gap-2">
                                            <span class="w-8 h-8 rounded-full bg-slate-100 text-slate-600 flex items-center justify-center font-bold text-xs shrink-0">
                                                <i class="fa-regular fa-user"></i>
                                            </span>
                                            <span><?php echo htmlspecialchars($m['username']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4 text-xs font-medium text-slate-600">
                                    <?php echo htmlspecialchars($m['email']); ?>
                                </td>

                                <td class="p-4 text-center">
                                    <?php if ($m['role'] == 'admin'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-purple-50 text-purple-700 border border-purple-200 text-[11px] font-bold">ผู้ดูแลระบบ</span>
                                    <?php elseif ($m['has_played']): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-orange-50 text-brand-orange border border-orange-200 text-[11px] font-bold">นักกีฬา</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 text-[11px] font-bold">ทั่วไป</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4 text-xs font-semibold">
                                    <?php if ($m['player_id']): ?>
                                        <div class="flex items-center gap-1.5 text-slate-900">
                                            <i class="fa-solid fa-gamepad text-brand-orange"></i>
                                            <span><?php echo htmlspecialchars($m['display_name']); ?></span>
                                        </div>
                                        <?php if (!empty($m['team_names'])): 
                                            $tNames = explode(', ', $m['team_names']);
                                            $tIds = explode(',', $m['team_ids']);
                                        ?>
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                <?php foreach ($tNames as $idx => $tName): 
                                                    $tId = $tIds[$idx] ?? 0;
                                                ?>
                                                    <button onclick="openTeamModal(<?= (int)$tId ?>)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-orange-50 hover:bg-orange-100 text-brand-orange border border-orange-200 text-[10px] font-bold transition-all cursor-pointer">
                                                        <i class="fa-solid fa-users"></i> <?= htmlspecialchars($tName) ?>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="block mt-1 text-[10px] text-slate-400 italic">ยังไม่สังกัดทีม</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-slate-300 italic">-</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4 text-center">
                                    <?php if ($m['status'] == 'active'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">ใช้งานปกติ</span>
                                    <?php elseif ($m['status'] === 'suspended'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200 text-xs font-bold">ถูกระงับ</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-700 border border-slate-200 text-xs font-bold">ปิดใช้งาน</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4 text-xs text-slate-400">
                                    <?php echo htmlspecialchars($m['created_at']); ?>
                                </td>

                                <td class="p-4 text-right space-x-1 whitespace-nowrap">
                                    <?php if ($m['user_id'] != $_SESSION['user_id']): ?>
                                        
                                        <form method="POST" class="inline-block" onsubmit="return confirm('<?php echo $m['status'] == 'active' ? 'ต้องการระงับบัญชีนี้ใช่หรือไม่?' : 'ต้องการปลดระงับบัญชีนี้ใช่หรือไม่?'; ?>')">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo $m['user_id']; ?>">
                                            <button type="submit"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg <?php echo $m['status'] == 'active' ? 'bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200'; ?> text-xs font-semibold transition-all cursor-pointer">
                                                <i class="fa-solid <?php echo $m['status'] == 'active' ? 'fa-user-slash' : 'fa-user-check'; ?>"></i>
                                                <span><?php echo $m['status'] == 'active' ? 'ระงับ' : 'ปลดระงับ'; ?></span>
                                            </button>
                                        </form>

                                        <form method="POST" class="inline-block" onsubmit="return confirm('ต้องการรีเซ็ตรหัสผ่านของ <?php echo htmlspecialchars($m['username'], ENT_QUOTES); ?> ใช่หรือไม่? ระบบจะสร้างรหัสผ่านชั่วคราวให้ใหม่')">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo $m['user_id']; ?>">
                                            <button type="submit"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 text-xs font-semibold transition-all cursor-pointer">
                                                <i class="fa-solid fa-key"></i>
                                                <span>รีเซ็ตรหัส</span>
                                            </button>
                                        </form>

                                        <button type="button" onclick="openMemberModal(<?php echo (int) $m['user_id']; ?>)"
                                            class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-sky-50 hover:bg-sky-100 text-sky-700 border border-sky-200 text-xs font-semibold transition-all">
                                            <i class="fa-solid fa-pen-to-square"></i><span>แก้ไข</span>
                                        </button>
                                        <?php if (!empty($m['team_ids'])): ?>
                                            <button type="button" onclick="openMemberTeamModal(<?php echo (int) $m['user_id']; ?>)"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-orange-50 hover:bg-orange-100 text-brand-orange border border-orange-200 text-xs font-semibold transition-all">
                                                <i class="fa-solid fa-people-group"></i><span>ทีม</span>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($m['role'] !== 'admin'): ?>
                                            <form method="POST" class="inline-block" onsubmit="return confirm('ยืนยันลบบัญชี <?php echo htmlspecialchars($m['username'], ENT_QUOTES); ?> ? หากมีประวัติการแข่งขัน ระบบจะไม่อนุญาตให้ลบ')">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="delete_member">
                                                <input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
                                                <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 text-xs font-semibold transition-all">
                                                    <i class="fa-solid fa-trash"></i><span>ลบ</span>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <span class="inline-block text-xs font-bold text-slate-400 bg-slate-100 px-3 py-1 rounded-lg">
                                            บัญชีของคุณ
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <div id="memberModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-3xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
                <div class="flex items-center gap-2"><i class="fa-solid fa-user-pen text-brand-orange text-lg"></i><h3 class="font-bold text-base">แก้ไขข้อมูลสมาชิก</h3></div>
                <button type="button" onclick="closeMemberModal()" class="text-slate-400 hover:text-white text-lg"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="p-6 overflow-y-auto space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_member">
                <input type="hidden" name="user_id" id="editMemberUserId">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="text-xs font-bold text-slate-700">Username<input name="username" id="editMemberUsername" required class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900"></label>
                    <label class="text-xs font-bold text-slate-700">Email<input type="email" name="email" id="editMemberEmail" required class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900"></label>
                    <label class="text-xs font-bold text-slate-700">ชื่อในเกม<input name="display_name" id="editMemberDisplayName" class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900"></label>
                    <label class="text-xs font-bold text-slate-700">ชื่อ-นามสกุล<input name="real_name" id="editMemberRealName" class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900"></label>
                    <label class="text-xs font-bold text-slate-700">เพศ<input name="gender" id="editMemberGender" class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900"></label>
                    <label class="text-xs font-bold text-slate-700">วันเกิด<input type="date" name="birth_date" id="editMemberBirthDate" class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900"></label>
                    <label class="text-xs font-bold text-slate-700 md:col-span-2">จังหวัด<input name="province" id="editMemberProvince" class="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900"></label>
                </div>
                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100">
                    <button type="button" onclick="closeMemberModal()" class="px-4 py-2 rounded-lg bg-slate-100 text-slate-600 font-bold text-xs">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs"><i class="fa-solid fa-floppy-disk"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <div id="teamModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-users-gear text-brand-orange text-lg"></i>
                    <h3 class="font-bold text-base" id="modalTeamTitle">จัดการข้อมูลทีม</h3>
                </div>
                <button onclick="closeTeamModal()" class="text-slate-400 hover:text-white text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="p-6 overflow-y-auto space-y-6 flex-1 text-sm">
                <div class="space-y-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="update_team">
                    <input type="hidden" name="team_id" id="modalTeamId">

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">ชื่อทีม</label>
                        <input type="text" name="team_name" id="modalTeamNameInput" required
                            class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900 focus:outline-none focus:border-brand-orange">
                    </div>

                    <div class="flex justify-end pt-3">
                        <button type="submit" class="px-5 py-2.5 bg-brand-orange hover:bg-brand-glow text-white font-bold text-xs rounded-lg transition-all shadow-sm">
                            บันทึกข้อมูลทีม
                        </button>
                    </div>
                    </form>
                    <form method="POST" class="mt-5 pt-4 border-t border-slate-200" onsubmit="return confirm('ยืนยันลบทีมนี้? ระบบจะลบสมาชิกในทีมด้วย และจะปฏิเสธหากทีมมีประวัติสมัครแข่งขัน')">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="delete_team">
                        <input type="hidden" name="team_id" id="modalDeleteTeamId">
                        <button type="submit" class="px-5 py-2.5 bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-bold text-xs rounded-lg transition-all">
                            <i class="fa-solid fa-trash"></i> ลบทีม
                        </button>
                    </form>
                </div>

                <form method="POST" id="teamRolesForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="update_team_roles">
                    <input type="hidden" name="team_id" id="modalRolesTeamId">
                    <h4 class="font-bold text-xs tracking-wider text-slate-500 mb-4 flex items-center justify-between">
                        <span>สมาชิกภายในทีม</span>
                    </h4>
                    <div id="modalMemberList" class="space-y-2">
                    </div>
                    <div class="flex justify-end pt-5 mt-5 border-t border-slate-100">
                        <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-lg transition-all">
                            <i class="fa-solid fa-floppy-disk"></i> บันทึกการเปลี่ยนแปลง
                        </button>
                    </div>
                </form>

                <form method="POST" id="addTeamMemberForm" class="bg-orange-50/50 p-4 rounded-xl border border-orange-100 space-y-3">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="add_team_member">
                    <input type="hidden" name="team_id" id="modalAddMemberTeamId">

                    <h4 class="font-bold text-xs text-brand-orange">เพิ่มสมาชิกใหม่เข้าทีม</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="md:col-span-2 relative">
                            <input type="text" id="teamPlayerSearch" autocomplete="off" placeholder="พิมพ์ค้นหาชื่อนักกีฬา..." required class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-brand-orange">
                            <input type="hidden" name="player_id" id="teamPlayerId">
                            <div id="teamPlayerSuggestions" class="hidden absolute left-0 right-0 top-full z-10 mt-1 max-h-48 overflow-y-auto rounded-lg border border-slate-200 bg-white shadow-lg"></div>
                        </div>
                        <div>
                            <select name="role_in_team" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-900 focus:outline-none focus:border-brand-orange">
                                <option value="member">Member (ตัวจริง)</option>
                                <option value="leader">Leader (กัปตัน)</option>
                                <option value="substitute">Substitute (ตัวสำรอง)</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs rounded-lg transition-all">
                            + เพิ่มสมาชิก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const teamsData = <?= json_encode($teamsData) ?>;
        const membersData = <?= json_encode(array_column($members, null, 'user_id')) ?>;
        const playersData = <?= json_encode($allPlayers) ?>;
        const csrfToken = <?= json_encode($csrfToken) ?>;

        function openMemberModal(userId) {
            const member = membersData[userId];
            if (!member) return;
            document.getElementById('editMemberUserId').value = member.user_id || '';
            document.getElementById('editMemberUsername').value = member.username || '';
            document.getElementById('editMemberEmail').value = member.email || '';
            document.getElementById('editMemberDisplayName').value = member.display_name || '';
            document.getElementById('editMemberRealName').value = member.real_name || '';
            document.getElementById('editMemberGender').value = member.gender || '';
            document.getElementById('editMemberBirthDate').value = member.birth_date || '';
            document.getElementById('editMemberProvince').value = member.province || '';
            document.getElementById('memberModal').classList.remove('hidden');
        }

        function closeMemberModal() {
            document.getElementById('memberModal').classList.add('hidden');
        }

        function openMemberTeamModal(userId) {
            const member = membersData[userId];
            if (!member || !member.team_ids) return;

            const teamId = String(member.team_ids).split(',').map(Number).find(id => teamsData[id]);
            if (teamId) openTeamModal(teamId);
        }

        function openTeamModal(teamId) {
            const team = teamsData[teamId];
            if (!team) return;

            document.getElementById('modalTeamTitle').innerText = 'จัดการทีม: ' + team.team_name;
            document.getElementById('modalTeamId').value = team.team_id;
            document.getElementById('modalDeleteTeamId').value = team.team_id;
            document.getElementById('modalAddMemberTeamId').value = team.team_id;
            document.getElementById('modalRolesTeamId').value = team.team_id;
            document.getElementById('modalTeamNameInput').value = team.team_name;

            const memberListDiv = document.getElementById('modalMemberList');
            memberListDiv.innerHTML = '';

            if (!team.members || team.members.length === 0) {
                memberListDiv.innerHTML = '<p class="text-xs text-slate-400 italic p-3 bg-slate-50 rounded-lg text-center">ไม่มีสมาชิกในทีมนี้</p>';
            } else {
                team.members.forEach(m => {
                    const item = document.createElement('div');
                    item.className = 'flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-200 text-xs';
                    item.innerHTML = `
                        <div>
                            <span class="font-bold text-slate-900">${escapeHtml(m.display_name)}</span>
                            <span class="text-slate-400 ml-1">(@${escapeHtml(m.username)})</span>
                        </div>
                        <div class="flex items-center gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    ${['manager', 'coach', 'player', 'substitute'].map(role => `
                                        <label class="inline-flex items-center gap-1 text-[10px] text-slate-600">
                                            <input type="checkbox" name="member_roles[${m.player_id}][]" value="${role}" ${(m.role_codes || []).includes(role) ? 'checked' : ''}>
                                            ${role === 'manager' ? 'ผู้จัดการทีม' : role === 'coach' ? 'โค้ช' : role === 'player' ? 'นักกีฬาหลัก' : 'นักกีฬาสำรอง'}
                                        </label>
                                    `).join('')}
                                </div>
                                <button type="button" onclick="removeTeamMember(${m.team_id}, ${m.player_id})" class="p-1.5 text-rose-500 hover:bg-rose-50 rounded transition-colors" title="ลบออกจากทีม">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                        </div>
                    `;
                    memberListDiv.appendChild(item);
                });
            }

            document.getElementById('teamModal').classList.remove('hidden');
        }

        function closeTeamModal() {
            document.getElementById('teamModal').classList.add('hidden');
        }

        function removeTeamMember(teamId, playerId) {
            if (!confirm('ยืนยันลบสมาชิกคนนี้ออกจากทีม?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="csrf_token" value="${csrfToken}">
                <input type="hidden" name="action" value="remove_team_member">
                <input type="hidden" name="team_id" value="${teamId}">
                <input type="hidden" name="player_id" value="${playerId}">`;
            document.body.appendChild(form);
            form.submit();
        }

        function escapeHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        (function () {
            const search = document.getElementById('teamPlayerSearch');
            const hiddenId = document.getElementById('teamPlayerId');
            const form = document.getElementById('addTeamMemberForm');
            const suggestions = document.getElementById('teamPlayerSuggestions');
            if (!search || !hiddenId || !form || !suggestions) return;
            search.addEventListener('input', function () {
                const value = search.value.trim().toLowerCase();
                hiddenId.value = '';
                suggestions.innerHTML = '';
                if (!value) {
                    suggestions.classList.add('hidden');
                    return;
                }
                const matches = playersData.filter(item =>
                    String(item.display_name || '').toLowerCase().includes(value) ||
                    String(item.username || '').toLowerCase().includes(value)
                ).slice(0, 8);
                matches.forEach(player => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'block w-full px-3 py-2 text-left text-xs text-slate-700 hover:bg-orange-50 hover:text-brand-orange';
                    option.innerHTML = `<strong>${escapeHtml(player.display_name)}</strong> <span class="text-slate-400">(@${escapeHtml(player.username)})</span>`;
                    option.addEventListener('click', function () {
                        search.value = `${player.display_name} (@${player.username})`;
                        hiddenId.value = player.player_id;
                        suggestions.classList.add('hidden');
                    });
                    suggestions.appendChild(option);
                });
                suggestions.classList.toggle('hidden', matches.length === 0);
            });
            search.addEventListener('focus', function () {
                if (search.value.trim()) search.dispatchEvent(new Event('input'));
            });
            document.addEventListener('click', function (event) {
                if (!search.contains(event.target) && !suggestions.contains(event.target)) suggestions.classList.add('hidden');
            });
            form.addEventListener('submit', function (event) {
                if (!hiddenId.value) {
                    event.preventDefault();
                    alert('กรุณาพิมพ์และเลือกนักกีฬาจากรายการค้นหา');
                    search.focus();
                }
            });
        })();

        (function () {
            const input = document.getElementById('memberSearchInput');
            const form = document.getElementById('memberSearchForm');
            if (!input || !form) return;

            let debounceTimer = null;
            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function () {
                    form.submit();
                }, 500);
            });

            if (input.value) {
                input.focus();
                const val = input.value;
                input.value = '';
                input.value = val;
            }
        })();
    </script>

</body>
</html>