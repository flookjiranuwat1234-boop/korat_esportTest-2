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
$q = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$profileFilter = trim((string) ($_GET['profile'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$gameFilter = 0;
$genderFilter = trim((string) ($_GET['gender'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

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
                    $requestedStatus = $_POST['target_status'] ?? '';
                    $newStatus = in_array($requestedStatus, ['active', 'suspended', 'disabled'], true)
                        ? $requestedStatus
                        : (($currentStatus == 'active') ? 'suspended' : 'active');
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
                            'reason' => trim($_POST['suspension_reason'] ?? '') ?: 'ระงับโดยผู้ดูแลระบบ',
                            'id' => $userId,
                        ]);
                    } elseif ($newStatus === 'active') {
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
                    $success = $newStatus === 'suspended' ? 'ระงับบัญชีแล้ว' : ($newStatus === 'disabled' ? 'ปิดใช้งานบัญชีแล้ว' : 'เปิดใช้งานบัญชีแล้ว');
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
                $stmt = $pdo->prepare("UPDATE team_members SET is_active = 0, left_at = NOW() WHERE team_id = :tid AND player_id = :pid AND is_active = 1");
                $stmt->execute(['tid' => $teamId, 'pid' => $playerId]);
                $success = 'สิ้นสุดการเป็นสมาชิกทีมเรียบร้อยแล้ว';
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
$lastLoginColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->fetch();
$supportsLastLoginAt = (bool) $lastLoginColumn;
$lastLoginSelect = $supportsLastLoginAt ? 'u.last_login_at' : 'NULL AS last_login_at';
$lastLoginGroup = $supportsLastLoginAt ? ', u.last_login_at' : '';
$sql = "
    SELECT u.user_id, u.username, u.email, u.role, u.status, u.created_at, {$lastLoginSelect},
        u.suspended_at, u.suspended_by, suspended_admin.username AS suspended_by_username, u.suspension_reason, u.reactivated_at,
        p.player_id AS player_id, p.display_name, p.real_name, p.gender, p.birth_date, p.province, p.avatar_path,
        GROUP_CONCAT(DISTINCT CASE WHEN tm.is_active = 1 THEN t.name END ORDER BY t.name SEPARATOR ', ') AS team_names,
        GROUP_CONCAT(DISTINCT CASE WHEN tm.is_active = 1 THEN t.team_id END ORDER BY t.name SEPARATOR ',') AS team_ids,
        GROUP_CONCAT(DISTINCT CASE WHEN tm.is_active = 1 THEN g.name END ORDER BY g.name SEPARATOR ', ') AS game_names,
        GROUP_CONCAT(DISTINCT CASE WHEN tm.is_active = 1 THEN COALESCE(NULLIF(tm.member_roles, ''), tm.in_game_role) END ORDER BY t.name SEPARATOR ', ') AS team_roles,
        (CASE WHEN p.player_id IS NOT NULL AND EXISTS (
            SELECT 1 FROM tournament_registration_members trm
            WHERE trm.player_id = p.player_id
              AND trm.checkin_status IN ('checked_in', 'waived')
        ) THEN 1 ELSE 0 END) AS has_played
    FROM users u
    LEFT JOIN players p ON p.user_id = u.user_id
    LEFT JOIN users suspended_admin ON suspended_admin.user_id = u.suspended_by
    LEFT JOIN team_members tm ON tm.player_id = p.player_id
    LEFT JOIN teams t ON t.team_id = tm.team_id
    LEFT JOIN games g ON g.game_id = t.game_id
    WHERE 1=1
";
$params = [];
if ($q !== '') {
    $sql .= " AND (u.username LIKE :q OR u.email LIKE :q OR p.display_name LIKE :q OR p.real_name LIKE :q
        OR g.name LIKE :q
        OR EXISTS (SELECT 1 FROM team_members tm2 JOIN teams t2 ON t2.team_id = tm2.team_id
            WHERE tm2.player_id = p.player_id AND tm2.is_active = 1
              AND (t2.name LIKE :q OR t2.tag LIKE :q OR COALESCE(t2.team_tag, '') LIKE :q)))";
    $params['q'] = "%{$q}%";
}
$allowedGenders = ['male', 'female', 'other'];
if (in_array($genderFilter, $allowedGenders, true)) {
    $sql .= " AND p.gender = :gender";
    $params['gender'] = $genderFilter;
}
$allowedStatuses = ['active', 'suspended', 'disabled'];
if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= " AND u.status = :status";
    $params['status'] = $statusFilter;
}
if ($roleFilter === 'admin') {
    $sql .= " AND u.role = 'admin'";
} elseif ($roleFilter === 'athlete') {
    // "นักกีฬา" คือผู้ที่เคยเข้าเช็คอิน / อนุโลมในรายการแข่งขันจริง
    $sql .= " AND u.role != 'admin' AND p.player_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM tournament_registration_members trm
        WHERE trm.player_id = p.player_id
          AND trm.checkin_status IN ('checked_in', 'waived')
    )";
} elseif ($roleFilter === 'guest') {
    // "ผู้ใช้ทั่วไป" = ไม่ใช่แอดมิน และยังไม่เคยเช็คอิน/อนุโลมลงแข่งขันจริง
    $sql .= " AND u.role != 'admin' AND (
        p.player_id IS NULL
        OR NOT EXISTS (
            SELECT 1 FROM tournament_registration_members trm
            WHERE trm.player_id = p.player_id
              AND trm.checkin_status IN ('checked_in', 'waived')
        )
    )";
}
if ($profileFilter === 'none') {
    // สมัครสมาชิกแล้ว แต่ยังไม่เคยสร้าง/claim โปรไฟล์นักกีฬาเลย
    $sql .= " AND p.player_id IS NULL AND u.role != 'admin'";
} elseif ($profileFilter === 'has') {
    $sql .= " AND p.player_id IS NOT NULL";
} elseif ($profileFilter === 'confirmed') {
    // นักกีฬาตัวจริง: มีโปรไฟล์ + เคยเช็คอินเข้าแข่งจริงใน tournament_registration_members
    $sql .= " AND p.player_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM tournament_registration_members trm
        WHERE trm.player_id = p.player_id
          AND trm.checkin_status IN ('checked_in', 'waived')
    )";
} elseif ($profileFilter === 'profile_only') {
    // มีโปรไฟล์แล้ว แต่ยังไม่เคยเช็คอินเข้าแข่งเลยสักครั้ง
    $sql .= " AND p.player_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM tournament_registration_members trm
        WHERE trm.player_id = p.player_id
          AND trm.checkin_status IN ('checked_in', 'waived')
    )";
}
$sql .= " GROUP BY u.user_id, u.username, u.email, u.role, u.status, u.created_at{$lastLoginGroup}, suspended_admin.username,
    p.player_id, p.display_name, p.real_name, p.gender, p.birth_date, p.province, p.avatar_path
    ORDER BY u.created_at DESC";

$countSql = preg_replace('/^\s*SELECT.*?FROM users u/s', 'SELECT COUNT(DISTINCT u.user_id) FROM users u', $sql);
$countSql = preg_replace('/\s+GROUP BY.*$/s', '', (string) $countSql);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalMembers = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalMembers / $perPage));
$page = min($page, $totalPages);
$sql .= ' LIMIT ' . (($page - 1) * $perPage) . ', ' . $perPage;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$games = $pdo->query('SELECT game_id, name FROM games ORDER BY name ASC')->fetchAll();

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
    SELECT t.team_id, t.name AS team_name, t.logo_path, t.status AS team_status, t.created_at AS team_created_at, g.name AS game_name
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
        'logo_path' => $row['logo_path'],
        'team_status' => $row['team_status'],
        'team_created_at' => $row['team_created_at'],
        'game_name' => $row['game_name'],
        'members' => $teamMembers
    ];
    }
}

$memberPlayerIds = array_values(array_filter(array_map('intval', array_column($members, 'player_id'))));
$teamHistoryData = [];
$tournamentHistoryData = [];
$rankingData = [];
$rankingSourceData = [];
if ($memberPlayerIds) {
    $historyPlaceholders = implode(',', array_fill(0, count($memberPlayerIds), '?'));
    $teamHistoryStmt = $pdo->prepare("SELECT tm.player_id, tm.team_id, t.name AS team_name, g.name AS game_name,
            tm.in_game_role, tm.member_roles, tm.joined_at, tm.left_at, tm.is_active
        FROM team_members tm
        JOIN teams t ON t.team_id = tm.team_id
        LEFT JOIN games g ON g.game_id = t.game_id
        WHERE tm.player_id IN ($historyPlaceholders)
        ORDER BY tm.is_active DESC, tm.joined_at DESC");
    $teamHistoryStmt->execute($memberPlayerIds);
    foreach ($teamHistoryStmt->fetchAll() as $teamHistory) $teamHistoryData[(int) $teamHistory['player_id']][] = $teamHistory;

    $historyStmt = $pdo->prepare("SELECT trm.player_id, tour.name AS tournament_name, g.name AS game_name,
            tc.label AS category_label, tr.team_id, team.name AS team_name, trm.member_roles,
            trm.is_starter, tr.registered_at, tr.participation_status
        FROM tournament_registration_members trm
        JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
        JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
        LEFT JOIN games g ON g.game_id = tour.game_id
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
        LEFT JOIN teams team ON team.team_id = tr.team_id
        WHERE trm.player_id IN ($historyPlaceholders)
        ORDER BY tr.registered_at DESC");
    $historyStmt->execute($memberPlayerIds);
    foreach ($historyStmt->fetchAll() as $history) $tournamentHistoryData[(int) $history['player_id']][] = $history;

    $rankingStmt = $pdo->prepare("SELECT pr.player_id, pr.game_id, g.name AS game_name, pr.category,
            pr.points, pr.matches_played, pr.wins, pr.losses, pr.updated_at
        FROM player_rankings pr
        LEFT JOIN games g ON g.game_id = pr.game_id
        WHERE pr.player_id IN ($historyPlaceholders)
        ORDER BY g.name ASC, pr.points DESC");
    $rankingStmt->execute($memberPlayerIds);
    foreach ($rankingStmt->fetchAll() as $ranking) $rankingData[(int) $ranking['player_id']][] = $ranking;

        $rankingSourceStmt = $pdo->prepare("SELECT rh.player_id, rh.game_id, g.name AS game_name, tour.name AS tournament_name,
            rh.placement, rh.reason, rh.points, rh.created_at
        FROM ranking_history rh
        LEFT JOIN games g ON g.game_id = rh.game_id
        LEFT JOIN tournaments tour ON tour.tournament_id = rh.tournament_id
        WHERE rh.player_id IN ($historyPlaceholders)
        ORDER BY rh.created_at DESC");
    $rankingSourceStmt->execute($memberPlayerIds);
    foreach ($rankingSourceStmt->fetchAll() as $source) $rankingSourceData[(int) $source['player_id']][] = $source;
}

// ดึงรายชื่อนักกีฬาสำหรับเพิ่มเข้าทีมใน Modal
$allPlayers = $pdo->query("SELECT p.player_id, p.display_name, u.username FROM players p JOIN users u ON u.user_id = p.user_id ORDER BY p.display_name ASC")->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: $success);
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'manage-members.php'), true, 303);
    exit;
}
$flash = consumeFlashMessage();
if ($flash) {
    if ($flash['type'] === 'error') $error = $flash['message'];
    else $success = $flash['message'];
}
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
        .admin-action-menu { width: 14rem; padding: 0.5rem; }
        .admin-action-item { min-height: 2.5rem; width: 100%; display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem 0.75rem; border-radius: 0.5rem; text-align: left; font-size: 0.75rem; font-weight: 600; line-height: 1.25rem; }
        .admin-action-item i { width: 1rem; text-align: center; flex: 0 0 1rem; }
        .admin-action-group { padding: 0.35rem 0.75rem 0.25rem; color: #94a3b8; font-size: 0.625rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
        .member-detail-tab.is-active { color: #ea580c; border-color: #f97316; background: #fff7ed; }
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
                <span>จัดการผู้สมัคร/ทีมแข่งขัน</span>
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
                    จัดการสมาชิกในระบบ <span class="text-brand-orange">(USER MANAGEMENT)</span>
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
                <form method="GET" id="memberSearchForm" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-3">
                    <div class="relative sm:col-span-2 xl:col-span-2">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center text-slate-400">
                            <i class="fa-solid fa-magnifying-glass text-sm"></i>
                        </span>
                        <input type="text" name="q" id="memberSearchInput" placeholder="พิมพ์เพื่อค้นหาอัตโนมัติ... (ชื่อผู้ใช้ / อีเมล / ชื่อในเกม / ชื่อทีม)" value="<?php echo htmlspecialchars($q); ?>" autocomplete="off"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-10 pr-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                    </div>

                    <div>
                        <select name="role" onchange="this.form.submit()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                            <option value="">ทุกบทบาท (Roles)</option>
                            <option value="admin" <?php echo $roleFilter == 'admin' ? 'selected' : ''; ?>>ผู้ดูแลระบบ</option>
                            <option value="athlete" <?php echo $roleFilter == 'athlete' ? 'selected' : ''; ?>>นักกีฬา</option>
                            <option value="guest" <?php echo $roleFilter == 'guest' ? 'selected' : ''; ?>>ผู้ใช้ทั่วไป</option>
                        </select>
                    </div>

                    <div>
                        <select name="profile" onchange="this.form.submit()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                            <option value="">ทุกสถานะโปรไฟล์</option>
                            <option value="none" <?php echo $profileFilter == 'none' ? 'selected' : ''; ?>>สมัครแล้วแต่ยังไม่มีโปรไฟล์</option>
                            <option value="profile_only" <?php echo $profileFilter == 'profile_only' ? 'selected' : ''; ?>>มีโปรไฟล์ แต่ยังไม่เคยแข่ง</option>
                            <option value="confirmed" <?php echo $profileFilter == 'confirmed' ? 'selected' : ''; ?>>นักกีฬาตัวจริง (เคยแข่งแล้ว)</option>
                            <option value="has" <?php echo $profileFilter == 'has' ? 'selected' : ''; ?>>มีโปรไฟล์ทั้งหมด (ทุกกรณี)</option>
                        </select>
                    </div>

                    <div>
                        <select name="status" onchange="this.form.submit()"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                            <option value="">ทุกสถานะบัญชี</option>
                            <option value="active" <?= $statusFilter === 'active' ? 'selected' : ''; ?>>ใช้งานปกติ</option>
                            <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : ''; ?>>ระงับบัญชี</option>
                            <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : ''; ?>>ปิดใช้งาน</option>
                        </select>
                    </div>

                    <div>
                        <select name="gender" onchange="this.form.submit()" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange transition-all font-medium">
                            <option value="">ทุกเพศ</option>
                            <option value="male" <?= $genderFilter === 'male' ? 'selected' : '' ?>>ชาย</option>
                            <option value="female" <?= $genderFilter === 'female' ? 'selected' : '' ?>>หญิง</option>
                            <option value="other" <?= $genderFilter === 'other' ? 'selected' : '' ?>>อื่น ๆ</option>
                        </select>
                    </div>

                    <button type="submit" 
                        class="px-6 py-2.5 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <span>ค้นหา</span>
                    </button>
                    
                    <?php if ($q !== '' || $roleFilter !== '' || $profileFilter !== '' || $statusFilter !== '' || $genderFilter !== ''): ?>
                        <a href="manage-members.php" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-600 font-semibold text-sm flex items-center justify-center transition-all">
                            ล้างตัวกรอง
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-600 flex items-center gap-2">
                        <i class="fa-solid fa-users text-brand-orange"></i>
                        รายชื่อสมาชิกในระบบ <span class="font-normal text-slate-400">(ทั้งหมด <?= number_format($totalMembers) ?> รายการ)</span>
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="p-4">สมาชิก</th>
                                <th class="p-4">Username / Email</th>
                                <th class="p-4 text-center">บทบาท</th>
                                <th class="p-4">นักกีฬา / เกม</th>
                                <th class="p-4">ทีมปัจจุบัน / บทบาท</th>
                                <th class="p-4 text-center">สถานะ</th>
                                <th class="p-4">วันที่สมัคร</th>
                                <th class="p-4">เข้าใช้งานล่าสุด</th>
                                <th class="p-4 text-right">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (count($members) == 0): ?>
                                <tr>
                                    <td colspan="9" class="p-8 text-center text-slate-400">
                                        <i class="fa-solid fa-user-slash text-3xl mb-2 block opacity-40"></i>
                                        ไม่พบสมาชิกตามเงื่อนไขที่ค้นหา
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
                                            <span>
                                                <span class="block"><?php echo htmlspecialchars($m['real_name'] ?: $m['username']); ?></span>
                                                <?php if (!empty($m['display_name'])): ?><span class="block text-[10px] font-normal text-slate-400"><?php echo htmlspecialchars($m['display_name']); ?></span><?php endif; ?>
                                            </span>
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
                                    <div><?php echo htmlspecialchars($m['username']); ?></div>
                                    <div class="text-[11px] text-slate-400"><?php echo htmlspecialchars($m['email']); ?></div>
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
                                            <span><?php echo htmlspecialchars($m['display_name'] ?: 'มีโปรไฟล์นักกีฬา'); ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-300 italic">-</span>
                                    <?php endif; ?>
                                </td>

                                <td class="p-4 text-xs font-semibold">
                                    <?php if (!empty($m['team_names'])):
                                        $tNames = explode(', ', $m['team_names']);
                                        $tIds = explode(',', $m['team_ids']);
                                    ?>
                                        <?php foreach ($tNames as $idx => $tName): $tId = $tIds[$idx] ?? 0; ?>
                                            <button type="button" onclick="openTeamModal(<?= (int) $tId ?>)" class="mb-1 inline-flex items-center gap-1 rounded bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-brand-orange transition-all hover:bg-orange-100">
                                                <i class="fa-solid fa-users"></i> <?= htmlspecialchars($tName) ?>
                                            </button>
                                        <?php endforeach; ?>
                                        <span class="block text-[10px] font-normal text-slate-400"><?= htmlspecialchars($m['team_roles'] ?: 'บทบาทไม่ระบุ') ?></span>
                                    <?php else: ?>
                                        <span class="text-[10px] font-normal italic text-slate-400">ยังไม่สังกัดทีม</span>
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

                                <td class="p-4 text-xs text-slate-400">
                                    <?php echo $supportsLastLoginAt && !empty($m['last_login_at']) ? htmlspecialchars($m['last_login_at']) : 'ไม่มีข้อมูล'; ?>
                                </td>

                                <td class="p-4 text-right whitespace-nowrap">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" onclick="openMemberDetailModal(<?php echo (int) $m['user_id']; ?>)" class="inline-flex h-9 items-center gap-2 rounded-lg bg-slate-100 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                                            <i class="fa-solid fa-circle-info"></i>รายละเอียด
                                        </button>
                                        <?php if ($m['user_id'] != $_SESSION['user_id']): ?>
                                            <div class="relative">
                                                <button type="button" class="admin-action-toggle inline-flex h-9 items-center gap-2 rounded-lg bg-brand-orange px-3 text-xs font-semibold text-white hover:bg-brand-glow" data-action-menu="member-menu-<?php echo (int) $m['user_id']; ?>" aria-expanded="false" aria-controls="member-menu-<?php echo (int) $m['user_id']; ?>"><i class="fa-solid fa-ellipsis"></i>จัดการ</button>
                                                <div id="member-menu-<?php echo (int) $m['user_id']; ?>" class="admin-action-menu fixed z-[70] hidden rounded-xl border border-slate-200 bg-white text-left shadow-xl" role="menu">
                                                    <div class="admin-action-group">ข้อมูลสมาชิก</div>
                                                    <button type="button" onclick="openMemberModal(<?php echo (int) $m['user_id']; ?>)" class="admin-action-item text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-pen-to-square text-slate-400"></i>แก้ไขข้อมูล</button>
                                                    <?php if (!empty($m['team_ids'])): ?><button type="button" onclick="openMemberTeamModal(<?php echo (int) $m['user_id']; ?>)" class="admin-action-item text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-people-group text-slate-400"></i>ดูทีมและบทบาท</button><?php endif; ?>
                                                    <form method="POST" onsubmit="return confirm('ต้องการรีเซ็ตรหัสผ่านของ <?php echo htmlspecialchars($m['username'], ENT_QUOTES); ?> ใช่หรือไม่? ระบบจะสร้างรหัสผ่านชั่วคราวให้ใหม่')">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
                                                        <button type="submit" class="admin-action-item text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-key text-slate-400"></i>รีเซ็ตรหัสผ่าน</button>
                                                    </form>
                                                    <div class="my-1 border-t border-slate-100"></div>
                                                    <div class="admin-action-group">สถานะบัญชี</div>
                                                    <form method="POST" data-member-name="<?= htmlspecialchars($m['real_name'] ?: $m['username'], ENT_QUOTES) ?>" onsubmit="return <?php echo $m['status'] === 'active' ? 'openSuspendModal(this)' : "confirm('ต้องการเปิดใช้งานบัญชีนี้ใช่หรือไม่?')"; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="target_status" value="<?php echo $m['status'] === 'active' ? 'suspended' : 'active'; ?>"><input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
                                                        <button type="submit" class="admin-action-item <?php echo $m['status'] === 'active' ? 'text-red-600 hover:bg-red-50' : 'text-emerald-700 hover:bg-emerald-50'; ?>"><i class="fa-solid <?php echo $m['status'] === 'active' ? 'fa-user-slash' : 'fa-user-check'; ?>"></i><?php echo $m['status'] === 'active' ? 'ระงับบัญชี' : 'เปิดใช้งานอีกครั้ง'; ?></button>
                                                    </form>
                                                    <?php if ($m['status'] !== 'disabled'): ?>
                                                        <form method="POST" onsubmit="return confirm('ต้องการปิดใช้งานบัญชีนี้ใช่หรือไม่?')">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="target_status" value="disabled"><input type="hidden" name="user_id" value="<?php echo (int) $m['user_id']; ?>">
                                                            <button type="submit" class="admin-action-item text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-user-xmark text-slate-400"></i>ปิดใช้งาน</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="inline-flex h-9 items-center rounded-lg bg-slate-100 px-3 text-xs font-bold text-slate-400">บัญชีของคุณ</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-4 py-3 text-xs">
                        <span class="text-slate-500">หน้า <?= $page ?> / <?= $totalPages ?></span>
                        <div class="flex gap-1">
                            <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                                <a href="?<?= http_build_query(['q' => $q, 'role' => $roleFilter, 'profile' => $profileFilter, 'status' => $statusFilter, 'game' => $gameFilter, 'gender' => $genderFilter, 'page' => $pageNumber]) ?>" class="rounded-lg px-3 py-1.5 font-bold <?= $pageNumber === $page ? 'bg-brand-orange text-white' : 'bg-white text-slate-600 hover:bg-slate-100' ?>"><?= $pageNumber ?></a>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <div id="suspendConfirmModal" class="fixed inset-0 z-[80] hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3"><h3 class="font-bold text-slate-900"><i class="fa-solid fa-user-lock mr-2 text-rose-600"></i>ระงับบัญชีสมาชิก</h3><button type="button" onclick="closeSuspendModal()" class="text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark"></i></button></div>
            <p class="mt-4 text-sm text-slate-600">สมาชิก: <strong id="suspendMemberName" class="text-slate-900"></strong></p>
            <textarea id="suspensionReasonInput" rows="4" class="mt-3 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="กรอกเหตุผลการระงับบัญชี" required></textarea>
            <div class="mt-4 flex justify-end gap-2"><button type="button" onclick="closeSuspendModal()" class="rounded-lg bg-slate-100 px-4 py-2 text-xs font-bold text-slate-700">ยกเลิก</button><button type="button" onclick="submitSuspendForm()" class="rounded-lg bg-rose-600 px-4 py-2 text-xs font-bold text-white">ยืนยันระงับบัญชี</button></div>
        </div>
    </div>

    <div id="memberDetailModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-4xl overflow-hidden flex flex-col max-h-[90vh]">
            <div class="p-5 bg-slate-900 text-white flex items-center justify-between"><div class="flex items-center gap-2"><i class="fa-solid fa-user text-brand-orange text-lg"></i><h3 class="font-bold text-base" id="memberDetailTitle">รายละเอียดสมาชิก</h3></div><button type="button" onclick="closeMemberDetailModal()" class="text-slate-400 hover:text-white text-lg"><i class="fa-solid fa-xmark"></i></button></div>
            <div class="flex gap-1 overflow-x-auto border-b border-slate-200 px-4 pt-3"><button type="button" data-detail-tab="overview" class="member-detail-tab is-active shrink-0 border-b-2 border-transparent px-3 py-2 text-xs font-bold">ภาพรวม</button><button type="button" data-detail-tab="teams" class="member-detail-tab shrink-0 border-b-2 border-transparent px-3 py-2 text-xs font-bold text-slate-500">ทีมและบทบาท</button><button type="button" data-detail-tab="tournaments" class="member-detail-tab shrink-0 border-b-2 border-transparent px-3 py-2 text-xs font-bold text-slate-500">ประวัติการแข่งขัน</button><button type="button" data-detail-tab="ranking" class="member-detail-tab shrink-0 border-b-2 border-transparent px-3 py-2 text-xs font-bold text-slate-500">Ranking</button><button type="button" data-detail-tab="account" class="member-detail-tab shrink-0 border-b-2 border-transparent px-3 py-2 text-xs font-bold text-slate-500">ประวัติบัญชี</button></div>
            <div id="memberDetailContent" class="overflow-y-auto p-6 text-sm"></div>
            <div class="flex justify-end border-t border-slate-100 bg-slate-50 px-6 py-4"><button type="button" onclick="closeMemberDetailModal()" class="rounded-lg bg-slate-200 px-4 py-2 text-xs font-bold text-slate-700">ปิด</button></div>
        </div>
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

            <div id="modalTeamMeta" class="border-b border-slate-200 bg-slate-50 px-6 py-4"></div>

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
        const teamHistoryData = <?= json_encode($teamHistoryData, JSON_UNESCAPED_UNICODE) ?>;
        const tournamentHistoryData = <?= json_encode($tournamentHistoryData, JSON_UNESCAPED_UNICODE) ?>;
        const rankingData = <?= json_encode($rankingData, JSON_UNESCAPED_UNICODE) ?>;
        const rankingSourceData = <?= json_encode($rankingSourceData, JSON_UNESCAPED_UNICODE) ?>;
        const csrfToken = <?= json_encode($csrfToken) ?>;

        let activeMemberDetail = null;
        let pendingSuspendForm = null;
        let memberEditDirty = false;

        function openSuspendModal(form) {
            pendingSuspendForm = form;
            document.getElementById('suspendMemberName').textContent = form.dataset.memberName || '';
            document.getElementById('suspensionReasonInput').value = '';
            document.getElementById('suspendConfirmModal').classList.remove('hidden');
            return false;
        }

        function closeSuspendModal() {
            document.getElementById('suspendConfirmModal').classList.add('hidden');
            pendingSuspendForm = null;
        }

        function submitSuspendForm() {
            const reason = document.getElementById('suspensionReasonInput').value.trim();
            if (!reason || !pendingSuspendForm) {
                document.getElementById('suspensionReasonInput').focus();
                return;
            }
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'suspension_reason';
            input.value = reason;
            pendingSuspendForm.appendChild(input);
            pendingSuspendForm.removeAttribute('onsubmit');
            pendingSuspendForm.submit();
        }

        function detailValue(value, fallback = 'ไม่มีข้อมูล') {
            return value === null || value === undefined || String(value).trim() === '' ? fallback : escapeHtml(value);
        }

        function renderMemberDetailTab(tab) {
            const member = membersData[activeMemberDetail];
            if (!member) return;
            const playerId = Number(member.player_id || 0);
            const content = document.getElementById('memberDetailContent');
            const teams = teamHistoryData[playerId] || [];
            const tournaments = tournamentHistoryData[playerId] || [];
            const rankings = rankingData[playerId] || [];
            let html = '';
            if (tab === 'overview') {
                html = `<div class="grid grid-cols-1 md:grid-cols-3 gap-4"><div class="rounded-xl bg-slate-50 p-4 text-center"><div class="mx-auto flex h-16 w-16 items-center justify-center overflow-hidden rounded-full bg-orange-100 text-2xl font-black text-brand-orange">${member.avatar_path ? `<img src="../${escapeHtml(member.avatar_path)}" alt="" class="h-full w-full object-cover">` : '<i class="fa-solid fa-user"></i>'}</div><div class="mt-3 font-bold text-slate-900">${detailValue(member.real_name || member.username)}</div><div class="text-xs text-slate-500">${detailValue(member.display_name)}</div></div><div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs"><div><b>Username</b><div>${detailValue(member.username)}</div></div><div><b>Email</b><div>${detailValue(member.email)}</div></div><div><b>วันเกิด</b><div>${detailValue(member.birth_date)}</div></div><div><b>เพศ</b><div>${detailValue(member.gender)}</div></div><div><b>จังหวัด</b><div>${detailValue(member.province)}</div></div><div><b>วันที่สมัคร</b><div>${detailValue(member.created_at)}</div></div><div><b>เข้าใช้งานล่าสุด</b><div>${detailValue(member.last_login_at)}</div></div><div><b>สถานะบัญชี</b><div>${detailValue(member.status)}</div></div></div></div>`;
            } else if (tab === 'teams') {
                html = teams.length ? `<div class="space-y-3">${teams.map(team => `<div class="rounded-xl border border-slate-200 p-4"><div class="flex items-center justify-between gap-3"><div><b class="text-slate-900">${detailValue(team.team_name)}</b><div class="text-xs text-slate-500">${detailValue(team.game_name)}</div></div><span class="rounded-full px-2 py-1 text-[10px] font-bold ${Number(team.is_active) ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}">${Number(team.is_active) ? 'สมาชิกปัจจุบัน' : 'สิ้นสุดแล้ว'}</span></div><div class="mt-2 text-xs text-slate-600">บทบาท: ${detailValue(team.member_roles || team.in_game_role)}<br>เข้าร่วม: ${detailValue(team.joined_at)} | ออกจากทีม: ${detailValue(team.left_at)}</div><button type="button" onclick="openTeamModal(${Number(team.team_id)})" class="mt-3 rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-200"><i class="fa-solid fa-users"></i> เปิดรายละเอียดทีม</button></div>`).join('')}</div>` : '<div class="rounded-xl bg-slate-50 p-8 text-center text-slate-500">ยังไม่มีประวัติทีม</div>';
            } else if (tab === 'tournaments') {
                html = tournaments.length ? `<div class="space-y-3">${tournaments.map(item => `<div class="rounded-xl border border-slate-200 p-4"><div class="font-bold text-slate-900">${detailValue(item.tournament_name)}</div><div class="mt-1 text-xs text-slate-500">${detailValue(item.game_name)} | ${detailValue(item.category_label)}</div><div class="mt-2 text-xs text-slate-600">ทีมที่ใช้: ${detailValue(item.team_name)}<br>Roster: ${detailValue(item.member_roles)} (${Number(item.is_starter) ? 'ตัวจริง' : 'ตัวสำรอง'})<br>สถานะ: ${detailValue(item.participation_status)} | สมัครเมื่อ: ${detailValue(item.registered_at)}</div></div>`).join('')}</div>` : '<div class="rounded-xl bg-slate-50 p-8 text-center text-slate-500">ยังไม่มีประวัติการแข่งขัน</div>';
            } else if (tab === 'ranking') {
                html = rankings.length ? `<div class="overflow-x-auto"><table class="min-w-full text-left text-xs"><thead><tr class="border-b border-slate-200"><th class="p-3">เกม</th><th class="p-3">คะแนน</th><th class="p-3">Tournament</th><th class="p-3">ชนะ/แพ้</th><th class="p-3">อัปเดต</th><th class="p-3"></th></tr></thead><tbody>${rankings.map(item => `<tr class="border-b border-slate-100"><td class="p-3 font-bold">${detailValue(item.game_name)}</td><td class="p-3">${detailValue(item.points)}</td><td class="p-3">${detailValue(item.matches_played)}</td><td class="p-3">${detailValue(item.wins, '0')} / ${detailValue(item.losses, '0')}</td><td class="p-3">${detailValue(item.updated_at)}</td><td class="p-3"><button type="button" onclick="showRankingSources(${Number(item.game_id)})" class="rounded-lg bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-700 hover:bg-slate-200">ดูที่มาคะแนน</button></td></tr>`).join('')}</tbody></table></div>` : '<div class="rounded-xl bg-slate-50 p-8 text-center text-slate-500">ยังไม่มี Ranking รายบุคคล</div>';
            } else {
                html = `<div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs"><div><b>สถานะบัญชี</b><div>${detailValue(member.status)}</div></div><div><b>วันที่เปิดใช้งานอีกครั้ง</b><div>${detailValue(member.reactivated_at)}</div></div><div><b>วันที่ระงับ</b><div>${detailValue(member.suspended_at)}</div></div><div><b>Admin ผู้ดำเนินการ</b><div>${detailValue(member.suspended_by_username)}</div></div><div><b>เหตุผลการระงับ</b><div>${detailValue(member.suspension_reason)}</div></div><div class="md:col-span-2"><b>Audit Log</b><div class="mt-1 text-slate-500">ระบบยังไม่มีตาราง audit_logs</div></div></div>`;
            }
            content.innerHTML = html;
        }

        function openMemberDetailModal(userId) {
            if (!membersData[userId]) return;
            activeMemberDetail = userId;
            document.getElementById('memberDetailTitle').textContent = 'รายละเอียดสมาชิก: ' + (membersData[userId].username || '');
            document.getElementById('memberDetailModal').classList.remove('hidden');
            document.querySelectorAll('[data-detail-tab]').forEach(tab => tab.classList.toggle('is-active', tab.dataset.detailTab === 'overview'));
            renderMemberDetailTab('overview');
        }

        function closeMemberDetailModal() {
            document.getElementById('memberDetailModal').classList.add('hidden');
            activeMemberDetail = null;
        }

        function showRankingSources(gameId) {
            const sources = (rankingSourceData[Number(activeMemberDetail)] || []).filter(item => Number(item.game_id) === Number(gameId));
            document.getElementById('memberDetailContent').innerHTML = sources.length ? `<div class="space-y-3"><button type="button" onclick="renderMemberDetailTab('ranking')" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">กลับไป Ranking</button>${sources.map(item => `<div class="rounded-xl border border-slate-200 p-4 text-xs"><b>${detailValue(item.tournament_name)}</b><div class="mt-1 text-slate-600">${detailValue(item.game_name)} | อันดับ: ${detailValue(item.placement)} | เหตุผล: ${detailValue(item.reason)} | คะแนน: ${detailValue(item.points)} | ${detailValue(item.created_at)}</div></div>`).join('')}</div>` : '<div class="rounded-xl bg-slate-50 p-8 text-center text-slate-500">ยังไม่มีประวัติที่มาคะแนน</div>';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const actionMenus = document.querySelectorAll('.admin-action-menu');
            document.querySelectorAll('.admin-action-toggle').forEach(toggle => {
                toggle.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    const menu = document.getElementById(toggle.dataset.actionMenu);
                    if (!menu) return;
                    const opening = menu.classList.contains('hidden');
                    actionMenus.forEach(item => item.classList.add('hidden'));
                    document.querySelectorAll('.admin-action-toggle').forEach(item => item.setAttribute('aria-expanded', 'false'));
                    if (!opening) return;
                    document.body.appendChild(menu);
                    menu.classList.remove('hidden');
                    const rect = toggle.getBoundingClientRect();
                    const width = menu.offsetWidth || 224;
                    const height = menu.offsetHeight || 280;
                    menu.style.left = `${Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8))}px`;
                    menu.style.top = `${rect.bottom + height + 8 <= window.innerHeight - 8 ? rect.bottom + 8 : Math.max(8, rect.top - height - 8)}px`;
                    toggle.setAttribute('aria-expanded', 'true');
                });
                toggle.addEventListener('keydown', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    toggle.click();
                });
            });
            actionMenus.forEach(menu => menu.addEventListener('click', event => event.stopPropagation()));
            document.addEventListener('click', () => {
                actionMenus.forEach(menu => menu.classList.add('hidden'));
                document.querySelectorAll('.admin-action-toggle').forEach(item => item.setAttribute('aria-expanded', 'false'));
            });
            document.querySelectorAll('[data-detail-tab]').forEach(tab => tab.addEventListener('click', () => {
                document.querySelectorAll('[data-detail-tab]').forEach(item => item.classList.toggle('is-active', item === tab));
                renderMemberDetailTab(tab.dataset.detailTab);
            }));
            document.querySelector('#memberModal form')?.querySelectorAll('input, select, textarea').forEach(field => field.addEventListener('input', () => { memberEditDirty = true; }));
            document.getElementById('memberDetailModal')?.addEventListener('click', event => {
                if (event.target.id === 'memberDetailModal') closeMemberDetailModal();
            });
            document.getElementById('suspendConfirmModal')?.addEventListener('click', event => {
                if (event.target.id === 'suspendConfirmModal') closeSuspendModal();
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    actionMenus.forEach(menu => menu.classList.add('hidden'));
                    if (!document.getElementById('suspendConfirmModal')?.classList.contains('hidden')) closeSuspendModal();
                    if (!document.getElementById('memberDetailModal')?.classList.contains('hidden')) closeMemberDetailModal();
                }
            });
            window.addEventListener('resize', () => actionMenus.forEach(menu => menu.classList.add('hidden')));
            window.addEventListener('scroll', () => actionMenus.forEach(menu => menu.classList.add('hidden')), true);
        });

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
            memberEditDirty = false;
            document.getElementById('memberModal').classList.remove('hidden');
        }

        function closeMemberModal() {
            if (memberEditDirty && !window.confirm('มีข้อมูลที่แก้ไขแล้วยังไม่ได้บันทึก ต้องการปิดหน้าต่างหรือไม่?')) return;
            document.getElementById('memberModal').classList.add('hidden');
            memberEditDirty = false;
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
            document.getElementById('modalAddMemberTeamId').value = team.team_id;
            document.getElementById('modalRolesTeamId').value = team.team_id;
            document.getElementById('modalTeamNameInput').value = team.team_name;
            document.getElementById('modalTeamMeta').innerHTML = `<div class="flex items-center gap-3"><div class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-xl bg-white border border-slate-200">${team.logo_path ? `<img src="../${escapeHtml(team.logo_path)}" alt="" class="h-full w-full object-cover">` : '<i class="fa-solid fa-users text-brand-orange"></i>'}</div><div><div class="font-bold text-slate-900">${escapeHtml(team.team_name)}</div><div class="text-xs text-slate-500">เกม: ${escapeHtml(team.game_name)} | สถานะทีม: ${escapeHtml(team.team_status || 'active')} | สร้างเมื่อ: ${escapeHtml(team.team_created_at)}</div></div></div>`;

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