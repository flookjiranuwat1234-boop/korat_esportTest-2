<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/registration_status.php';
requireRole('admin');

ensureTournamentRosterTables($pdo);
ensureTournamentCategorySchema($pdo);
ensureRegistrationStatusHistoryTable($pdo);

$currentUser = [
    'username' => $_SESSION['username'] ?? 'Admin',
    'role' => $_SESSION['role'] ?? 'Administrator',
];

function getTournamentRegistrationState(array $tournament, DateTimeImmutable $now): array
{
    $status = strtolower((string) ($tournament['status'] ?? 'draft'));
    $start = !empty($tournament['registration_start'])
        ? new DateTimeImmutable((string) $tournament['registration_start'], new DateTimeZone('Asia/Bangkok'))
        : null;
    $end = !empty($tournament['registration_end'])
        ? new DateTimeImmutable((string) $tournament['registration_end'], new DateTimeZone('Asia/Bangkok'))
        : null;

    if ($status === 'draft') {
        return ['allowed' => false, 'message' => 'ทัวร์นาเมนต์นี้ยังไม่เปิดรับสมัคร'];
    }
    if ($status === 'registration_closed') {
        return ['allowed' => false, 'message' => 'ปิดรับสมัครแล้ว'];
    }
    if ($status === 'ongoing') {
        return ['allowed' => false, 'message' => 'การแข่งขันเริ่มแล้ว ไม่สามารถสมัครได้'];
    }
    if ($status === 'completed') {
        return ['allowed' => false, 'message' => 'การแข่งขันสิ้นสุดแล้ว'];
    }
    if ($status === 'cancelled') {
        return ['allowed' => false, 'message' => 'ทัวร์นาเมนต์นี้ถูกยกเลิก'];
    }
    if ($start && $now < $start) {
        return ['allowed' => false, 'message' => 'ยังไม่ถึงวันเปิดรับสมัคร'];
    }
    if ($end && $now > $end) {
        return ['allowed' => false, 'message' => 'ปิดรับสมัครแล้ว'];
    }
    if ($status === 'registration_open' && (!$start || $now >= $start) && (!$end || $now <= $end)) {
        return ['allowed' => true, 'message' => ''];
    }

    return ['allowed' => false, 'message' => 'ทัวร์นาเมนต์นี้ยังไม่เปิดรับสมัคร'];
}

function statusBadge(string $status, string $type = 'approval'): string
{
    $approvedMap = [
        'pending' => ['label' => 'รอตรวจสอบ', 'class' => 'bg-yellow-100 text-yellow-700'],
        'approved' => ['label' => 'อนุมัติแล้ว', 'class' => 'bg-emerald-100 text-emerald-700'],
        'revision_required' => ['label' => 'ส่งกลับแก้ไข', 'class' => 'bg-orange-100 text-orange-700'],
        'rejected' => ['label' => 'ปฏิเสธ', 'class' => 'bg-red-100 text-red-700'],
        'withdrawn' => ['label' => 'ถอนตัว', 'class' => 'bg-slate-200 text-slate-700'],
        'disqualified' => ['label' => 'ตัดสิทธิ์', 'class' => 'bg-red-100 text-red-700'],
        'walkover' => ['label' => 'WO', 'class' => 'bg-red-700 text-white'],
    ];

    $checkinMap = [
        'not_checked_in' => ['label' => 'ยังไม่ Check-in', 'class' => 'bg-slate-200 text-slate-700'],
        'partial' => ['label' => 'Check-in บางส่วน', 'class' => 'bg-yellow-100 text-yellow-700'],
        'checked_in' => ['label' => 'Check-in ครบ', 'class' => 'bg-emerald-100 text-emerald-700'],
        'expired' => ['label' => 'หมดเวลา', 'class' => 'bg-red-100 text-red-700'],
        'waived' => ['label' => 'อนุโลม', 'class' => 'bg-sky-100 text-sky-700'],
    ];

    $participationMap = [
        'registered' => ['label' => 'ลงทะเบียนแล้ว', 'class' => 'bg-slate-100 text-slate-700'],
        'checkin_open' => ['label' => 'อยู่ในช่วง Check-in', 'class' => 'bg-blue-100 text-blue-700'],
        'checkin_complete' => ['label' => 'Check-in ครบ', 'class' => 'bg-emerald-100 text-emerald-700'],
        'checkin_incomplete' => ['label' => 'Check-in ไม่ครบ', 'class' => 'bg-yellow-100 text-yellow-700'],
        'pending_admin_review' => ['label' => 'รอ Admin ตรวจสอบ', 'class' => 'bg-yellow-100 text-yellow-700'],
        'qualified_for_draw' => ['label' => 'พร้อมจัดสาย', 'class' => 'bg-indigo-100 text-indigo-700'],
        'withdrawn' => ['label' => 'ถอนตัว', 'class' => 'bg-slate-200 text-slate-700'],
        'disqualified' => ['label' => 'ถูกตัดสิทธิ์', 'class' => 'bg-red-100 text-red-700'],
    ];

    $map = $type === 'checkin' ? $checkinMap : ($type === 'participation' ? $participationMap : $approvedMap);
    $info = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'bg-slate-100 text-slate-700'];
    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-bold ' . $info['class'] . '">' . $info['label'] . '</span>';
}

function getCheckinCompletion(PDO $pdo, int $registrationId): array
{
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_required,
            SUM(CASE WHEN checkin_status IN ('checked_in', 'waived') THEN 1 ELSE 0 END) AS checked_done
        FROM tournament_registration_members
        WHERE tournament_registration_id = :registration_id AND is_required_for_checkin = 1 AND checkin_status <> 'removed'
    ");
    $stmt->execute(['registration_id' => $registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_required' => 0, 'checked_done' => 0];
    $total = (int) ($row['total_required'] ?? 0);
    $done = (int) ($row['checked_done'] ?? 0);
    if ($total === 0) {
        return ['status' => 'not_checked_in', 'done' => 0, 'total' => 0];
    }
    if ($done >= $total) {
        return ['status' => 'checked_in', 'done' => $done, 'total' => $total];
    }
    if ($done > 0) {
        return ['status' => 'partial', 'done' => $done, 'total' => $total];
    }
    return ['status' => 'not_checked_in', 'done' => 0, 'total' => $total];
}

function getRegistrationDetail(PDO $pdo, int $registrationId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            tr.tournament_registration_id,
            tr.tournament_id,
            tr.status,
            tr.participation_status,
            tr.seed_no,
            tr.roster_locked_at,
            tr.registered_at,
            tr.tournament_category_id,
            tr.qr_code_token,
            tc.category_code,
            tc.label AS category_label,
            tc.max_participants,
            tc.starters_count,
            tc.substitutes_count,
            COALESCE(team.name, p.display_name, u.username, 'ผู้สมัครเดี่ยว') AS display_name,
            COALESCE(captain_u.username, '-') AS captain_name,
            tour.name AS tournament_name,
            tour.checkin_open_at,
            tour.checkin_close_at,
            tour.game_id,
            g.name AS game_name
        FROM tournament_registrations tr
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
        LEFT JOIN teams team ON team.team_id = tr.team_id
        LEFT JOIN players p ON p.player_id = tr.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        LEFT JOIN players captain_p ON captain_p.player_id = team.captain_player_id
        LEFT JOIN users captain_u ON captain_u.user_id = captain_p.user_id
        LEFT JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
        LEFT JOIN games g ON g.game_id = tour.game_id
        WHERE tr.tournament_registration_id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $progress = getCheckinCompletion($pdo, $registrationId);
    $row['progress'] = $progress;
    $row['roster_count'] = (int) $pdo->query("SELECT COUNT(*) FROM tournament_registration_members WHERE tournament_registration_id = " . (int) $registrationId)->fetchColumn();
    return $row;
}

function getRegistrationMembers(PDO $pdo, int $registrationId): array
{
    $stmt = $pdo->prepare("
        SELECT
            trm.id,
            trm.player_id,
            trm.member_roles,
            trm.is_starter,
            trm.is_required_for_checkin,
            trm.checkin_status,
            trm.checkin_at,
            u.username,
            p.display_name
        FROM tournament_registration_members trm
        LEFT JOIN players p ON p.player_id = trm.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE trm.tournament_registration_id = :registration_id
        ORDER BY trm.is_starter DESC, u.username ASC, p.display_name ASC
    ");
    $stmt->execute(['registration_id' => $registrationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getRegistrationMatchCount(PDO $pdo, array $registration): int
{
    if (empty($registration['team_id'])) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches
        WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id
          AND (team1_id = :team_id OR team2_id = :team_id)');
    $stmt->execute([
        'tournament_id' => (int) $registration['tournament_id'],
        'category_id' => (int) $registration['tournament_category_id'],
        'team_id' => (int) $registration['team_id'],
    ]);
    return (int) $stmt->fetchColumn();
}

$tournamentId = (int) ($_GET['tournament_id'] ?? 0);
$selectedCategoryId = (int) ($_GET['category_id'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$approvalStatus = trim((string) ($_GET['approval_status'] ?? 'all'));
$checkinStatus = trim((string) ($_GET['checkin_status'] ?? 'all'));
$drawStatus = trim((string) ($_GET['draw_status'] ?? 'all'));
$addPlayerSearch = trim((string) ($_GET['add_player_search'] ?? ''));
$isAddPlayerSearchAjax = ($_GET['ajax'] ?? '') === 'search_add_solo_players';

if (!$tournamentId) {
    $latestTournamentStmt = $pdo->query('SELECT tournament_id FROM tournaments ORDER BY created_at DESC LIMIT 1');
    $tournamentId = (int) $latestTournamentStmt->fetchColumn();
}

$tournament = null;
if ($tournamentId) {
    $tStmt = $pdo->prepare("
        SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t
        JOIN games g ON g.game_id = t.game_id
        WHERE t.tournament_id = :id
        LIMIT 1
    ");
    $tStmt->execute(['id' => $tournamentId]);
    $tournament = $tStmt->fetch(PDO::FETCH_ASSOC);
}
$addPlayerSearchResults = [];
if ($tournament && ($tournament['play_mode'] ?? 'team') === 'solo') {
    $playerSearchSql = "
         SELECT p.player_id, p.display_name, p.real_name, p.avatar_path, p.eligibility_status,
             u.user_id, u.username, u.email, u.status AS account_status,
               (SELECT COUNT(*)
                FROM tournament_registrations tr
                WHERE tr.player_id = p.player_id
                  AND tr.tournament_id = :tournament_id
                  AND tr.status IN ('pending', 'approved')) AS already_registered_count
        FROM players p
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE u.status = 'active'
          AND p.user_id IS NOT NULL
    ";
    $playerSearchParams = ['tournament_id' => $tournamentId];
    if ($addPlayerSearch !== '') {
        $playerSearchSql .= "
          AND (
              p.display_name LIKE :search OR
              p.real_name LIKE :search OR
              u.username LIKE :search OR
              u.email LIKE :search
          )
        ";
        $playerSearchParams['search'] = '%' . $addPlayerSearch . '%';
    }
    $playerSearchSql .= ' ORDER BY p.display_name ASC, u.username ASC';
    $playerSearchStmt = $pdo->prepare($playerSearchSql);
    $playerSearchStmt->execute($playerSearchParams);
    $addPlayerSearchResults = $playerSearchStmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($tournament && ($tournament['play_mode'] ?? 'team') === 'team') {
    $teamSearchSql = "
        SELECT t.team_id, t.name, t.logo_path, t.status, t.game_id,
               COALESCE(cu.username, '-') AS captain_username,
               COUNT(DISTINCT CASE WHEN tm.is_active = 1 THEN tm.player_id END) AS active_member_count,
               COALESCE(tc.starters_count, 0) AS starters_count,
               (SELECT COUNT(*)
                FROM tournament_registrations tr
                WHERE tr.team_id = t.team_id
                  AND tr.tournament_id = :tournament_id
                  AND tr.status IN ('pending', 'approved')) AS already_registered_count
        FROM teams t
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = :category_id AND tc.tournament_id = :tournament_id AND tc.is_active = 1
        LEFT JOIN players cp ON cp.player_id = t.captain_player_id
        LEFT JOIN users cu ON cu.user_id = cp.user_id
        LEFT JOIN team_members tm ON tm.team_id = t.team_id
        WHERE (t.game_id = :game_id OR (t.game_id IS NULL AND t.tag LIKE 'A64%'))
          AND t.status = 'active'
    ";
    $teamSearchParams = [
        'tournament_id' => $tournamentId,
        'category_id' => $selectedCategoryId > 0 ? $selectedCategoryId : 0,
        'game_id' => (int) $tournament['game_id'],
    ];
    if ($addPlayerSearch !== '') {
        $teamSearchSql .= "
          AND (t.name LIKE :search OR cu.username LIKE :search)
        ";
        $teamSearchParams['search'] = '%' . $addPlayerSearch . '%';
    }
    $teamSearchSql .= ' GROUP BY t.team_id, t.name, t.logo_path, t.status, t.game_id, cu.username, tc.starters_count ORDER BY t.name ASC';
    $teamSearchStmt = $pdo->prepare($teamSearchSql);
    $teamSearchStmt->execute($teamSearchParams);
    $addPlayerSearchResults = $teamSearchStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($isAddPlayerSearchAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($addPlayerSearchResults, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_solo_player') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $targetTournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $targetCategoryId = (int) ($_POST['tournament_category_id'] ?? 0);
        $targetPlayerId = (int) ($_POST['player_id'] ?? 0);

        if ($targetTournamentId <= 0 || $targetCategoryId <= 0 || $targetPlayerId <= 0) {
            $error = 'กรุณาเลือกผู้เล่นและประเภทการแข่งขันให้ครบถ้วน';
        } else {
            $targetTournamentStmt = $pdo->prepare('SELECT t.tournament_id, t.name, t.status, t.game_id, g.play_mode
                FROM tournaments t
                JOIN games g ON g.game_id = t.game_id
                WHERE t.tournament_id = :id LIMIT 1');
            $targetTournamentStmt->execute(['id' => $targetTournamentId]);
            $targetTournament = $targetTournamentStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetTournament) {
                $error = 'ไม่พบทัวร์นาเมนต์ที่เลือก';
            } elseif (($targetTournament['play_mode'] ?? 'team') !== 'solo') {
                $error = 'ฟังก์ชันเพิ่มผู้แข่งขันแบบเดี่ยวใช้ได้เฉพาะทัวร์นาเมนต์ Solo เท่านั้น';
            } else {
                $windowState = getTournamentRegistrationState($targetTournament, new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')));
                if (!$windowState['allowed']) {
                    $error = $windowState['message'];
                } else {
                    $categoryStmt = $pdo->prepare('SELECT tournament_category_id, category_code, label, max_participants, starters_count
                        FROM tournament_categories
                        WHERE tournament_category_id = :category_id AND tournament_id = :tournament_id AND is_active = 1 LIMIT 1');
                    $categoryStmt->execute(['category_id' => $targetCategoryId, 'tournament_id' => $targetTournamentId]);
                    $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$category) {
                        $error = 'Category ที่เลือกไม่พร้อมใช้งาน';
                    } else {
                        $duplicateStmt = $pdo->prepare('SELECT tournament_registration_id FROM tournament_registrations
                            WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id AND player_id = :player_id AND status IN (\'pending\', \'approved\') LIMIT 1');
                        $duplicateStmt->execute([
                            'tournament_id' => $targetTournamentId,
                            'category_id' => $targetCategoryId,
                            'player_id' => $targetPlayerId,
                        ]);
                        if ($duplicateStmt->fetchColumn()) {
                            $error = 'ผู้เล่นรายนี้สมัคร Category นี้แล้ว';
                        } else {
                            $registeredCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registrations
                                WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id AND status IN ('pending', 'approved')");
                            $registeredCountStmt->execute([
                                'tournament_id' => $targetTournamentId,
                                'category_id' => $targetCategoryId,
                            ]);
                            $registeredCount = (int) $registeredCountStmt->fetchColumn();
                            $maxParticipants = (int) ($category['max_participants'] ?? 0);
                            if ($maxParticipants > 0 && $registeredCount >= $maxParticipants) {
                                $error = 'Category นี้มีผู้สมัครเต็มแล้ว';
                            }
                        }
                    }
                }
            }
        }

        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO tournament_registrations (tournament_id, tournament_category_id, player_id, team_id, category, status, participation_status)
                    VALUES (:tournament_id, :category_id, :player_id, NULL, :category, :status, :participation_status)');
                $insert->execute([
                    'tournament_id' => $targetTournamentId,
                    'category_id' => $targetCategoryId,
                    'player_id' => $targetPlayerId,
                    'category' => (string) ($category['category_code'] ?? 'open'),
                    'status' => 'pending',
                    'participation_status' => 'registered',
                ]);

                $registrationId = (int) $pdo->lastInsertId();
                snapshotTournamentRoster($pdo, $registrationId, null, $targetPlayerId);
                recordRegistrationStatus($pdo, $registrationId, 'pending', (int) ($_SESSION['user_id'] ?? 0), 'Admin เพิ่มผู้แข่งขันเดี่ยวจากหน้า Manage Teams', null);
                $pdo->commit();
                $success = 'เพิ่มผู้แข่งขันรายบุคคลเรียบร้อยแล้ว';
                $tournamentId = $targetTournamentId;
                $selectedCategoryId = $targetCategoryId;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'เพิ่มผู้แข่งขันรายบุคคลไม่สำเร็จ';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_team') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $targetTournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $targetCategoryId = (int) ($_POST['tournament_category_id'] ?? 0);
        $targetTeamId = (int) ($_POST['team_id'] ?? 0);
        $targetTournamentStmt = $pdo->prepare('SELECT t.tournament_id, t.name, t.status, t.game_id, g.play_mode
            FROM tournaments t JOIN games g ON g.game_id = t.game_id
            WHERE t.tournament_id = :id LIMIT 1');
        $targetTournamentStmt->execute(['id' => $targetTournamentId]);
        $targetTournament = $targetTournamentStmt->fetch(PDO::FETCH_ASSOC);

        if ($targetTournamentId <= 0 || $targetCategoryId <= 0 || $targetTeamId <= 0 || !$targetTournament) {
            $error = 'กรุณาเลือกทีมและประเภทการแข่งขันให้ครบถ้วน';
        } elseif (($targetTournament['play_mode'] ?? '') !== 'team') {
            $error = 'ฟังก์ชันเพิ่มทีมใช้ได้เฉพาะทัวร์นาเมนต์ Team เท่านั้น';
        } else {
            $windowState = getTournamentRegistrationState($targetTournament, new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')));
            $categoryStmt = $pdo->prepare('SELECT tournament_category_id, category_code, max_participants, starters_count
                FROM tournament_categories WHERE tournament_category_id = :category_id AND tournament_id = :tournament_id AND is_active = 1 LIMIT 1');
            $categoryStmt->execute(['category_id' => $targetCategoryId, 'tournament_id' => $targetTournamentId]);
            $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
            $teamStmt = $pdo->prepare('SELECT team_id, name, tag, game_id, status FROM teams WHERE team_id = :team_id LIMIT 1');
            $teamStmt->execute(['team_id' => $targetTeamId]);
            $team = $teamStmt->fetch(PDO::FETCH_ASSOC);

            if (!$windowState['allowed']) {
                $error = $windowState['message'];
            } elseif (!$category) {
                $error = 'Category ที่เลือกไม่พร้อมใช้งาน';
            } elseif (!$team || $team['status'] !== 'active' || ((int) $team['game_id'] !== (int) $targetTournament['game_id'] && !($team['game_id'] === null && str_starts_with((string) $team['tag'], 'F64')))) {
                $error = 'ทีมนี้ไม่พร้อมใช้งานกับ Tournament นี้';
            } else {
                $genderMismatchStmt = $pdo->prepare("SELECT COUNT(*) FROM team_members tm
                    JOIN players p ON p.player_id = tm.player_id
                    WHERE tm.team_id = :team_id AND tm.is_active = 1
                      AND LOWER(COALESCE(p.gender, '')) <> LOWER(:gender)");
                $genderMismatchStmt->execute(['team_id' => $targetTeamId, 'gender' => (string) ($category['category_code'] ?? '')]);
                $memberStmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = :team_id AND is_active = 1');
                $memberStmt->execute(['team_id' => $targetTeamId]);
                $memberCount = (int) $memberStmt->fetchColumn();
                $duplicateStmt = $pdo->prepare('SELECT tournament_registration_id FROM tournament_registrations
                    WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id AND team_id = :team_id AND status IN (\'pending\', \'approved\') LIMIT 1');
                $duplicateStmt->execute(['tournament_id' => $targetTournamentId, 'category_id' => $targetCategoryId, 'team_id' => $targetTeamId]);
                $registeredCountStmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registrations
                    WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id AND status IN ('pending', 'approved')");
                $registeredCountStmt->execute(['tournament_id' => $targetTournamentId, 'category_id' => $targetCategoryId]);

                if ($duplicateStmt->fetchColumn()) {
                    $error = 'ทีมนี้สมัคร Category นี้แล้ว';
                } elseif (in_array($category['category_code'], ['male', 'female'], true) && (int) $genderMismatchStmt->fetchColumn() > 0) {
                    $error = 'เพศสมาชิกทีมไม่ตรงกับ Category ที่เลือก';
                } elseif ((int) ($category['starters_count'] ?? 0) > $memberCount) {
                    $error = 'จำนวนสมาชิกทีมยังไม่ครบตามกติกา';
                } elseif ((int) ($category['max_participants'] ?? 0) > 0 && (int) $registeredCountStmt->fetchColumn() >= (int) $category['max_participants']) {
                    $error = 'Category นี้มีผู้สมัครเต็มแล้ว';
                }
            }
        }

        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO tournament_registrations (tournament_id, tournament_category_id, team_id, player_id, category, status, participation_status)
                    VALUES (:tournament_id, :category_id, :team_id, NULL, :category, \'pending\', \'registered\')');
                $insert->execute([
                    'tournament_id' => $targetTournamentId,
                    'category_id' => $targetCategoryId,
                    'team_id' => $targetTeamId,
                    'category' => (string) ($category['category_code'] ?? 'open'),
                ]);
                $registrationId = (int) $pdo->lastInsertId();
                snapshotTournamentRoster($pdo, $registrationId, $targetTeamId, null);
                recordRegistrationStatus($pdo, $registrationId, 'pending', (int) ($_SESSION['user_id'] ?? 0), 'Admin เพิ่มทีมจากหน้า Manage Teams', null);
                $pdo->commit();
                $success = 'เพิ่มทีมเรียบร้อยแล้ว';
                $tournamentId = $targetTournamentId;
                $selectedCategoryId = $targetCategoryId;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'เพิ่มทีมไม่สำเร็จ';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'approve_registration') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $approveStmt = $pdo->prepare('SELECT tr.*, tc.is_active AS category_active, tour.status AS tournament_status
            FROM tournament_registrations tr
            LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
                AND tc.tournament_id = tr.tournament_id
            JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
            WHERE tr.tournament_registration_id = :registration_id LIMIT 1');
        $approveStmt->execute(['registration_id' => $registrationId]);
        $registration = $approveStmt->fetch(PDO::FETCH_ASSOC);
        $validParticipant = false;
        if ($registration) {
            $participantStmt = $registration['team_id']
                ? $pdo->prepare('SELECT COUNT(*) FROM teams WHERE team_id = :id')
                : $pdo->prepare('SELECT COUNT(*) FROM players WHERE player_id = :id');
            $participantStmt->execute(['id' => (int) ($registration['team_id'] ?: $registration['player_id'])]);
            $validParticipant = (int) $participantStmt->fetchColumn() > 0;
        }
        if (!$registration || $registration['status'] !== 'pending') {
            $error = 'ใบสมัครนี้ไม่อยู่ในสถานะรอตรวจสอบ';
        } elseif ($registration['tournament_status'] === 'completed') {
            $error = 'Tournament จบการแข่งขันแล้ว ไม่สามารถอนุมัติผู้สมัครเพิ่มได้';
        } elseif ((int) ($registration['category_active'] ?? 0) !== 1) {
            $error = 'Category ของใบสมัครนี้ไม่พร้อมใช้งาน';
        } elseif (!$validParticipant) {
            $error = 'ไม่พบทีม/ผู้เล่นของใบสมัครนี้';
        } else {
            try {
                $pdo->beginTransaction();
                $token = (string) ($registration['qr_code_token'] ?? '');
                if ($token === '') $token = bin2hex(random_bytes(24));
                $update = $pdo->prepare("UPDATE tournament_registrations
                    SET status = 'approved', qr_code_token = :token, reviewed_by = :reviewed_by,
                        reviewed_at = NOW(), participation_status = 'registered'
                    WHERE tournament_registration_id = :registration_id AND status = 'pending'");
                $update->execute(['token' => $token, 'reviewed_by' => (int) $_SESSION['user_id'], 'registration_id' => $registrationId]);
                if ($update->rowCount() !== 1) throw new RuntimeException('ไม่สามารถเปลี่ยนสถานะใบสมัครได้');
                snapshotTournamentRoster($pdo, $registrationId, $registration['team_id'] ? (int) $registration['team_id'] : null, $registration['player_id'] ? (int) $registration['player_id'] : null);
                recordRegistrationStatus($pdo, $registrationId, 'approved', (int) $_SESSION['user_id'], 'อนุมัติใบสมัครจากหน้าจัดการผู้สมัคร', (string) $registration['status']);
                $pdo->commit();
                $success = 'อนุมัติใบสมัครและสร้าง QR Check-in เรียบร้อยแล้ว';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'อนุมัติใบสมัครไม่สำเร็จ';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['change_registration_status', 'withdraw_registration', 'disqualify_registration'], true)) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $note = trim((string) ($_POST['status_note'] ?? ''));
        $newApprovalStatus = trim((string) ($_POST['registration_status'] ?? ''));
        $newParticipationStatus = $action === 'withdraw_registration' ? 'withdrawn' : ($action === 'disqualify_registration' ? 'disqualified' : null);
        if ($action === 'change_registration_status' && !in_array($newApprovalStatus, ['pending', 'approved', 'rejected'], true)) {
            $error = 'สถานะการอนุมัติไม่ถูกต้อง';
        } elseif ($action !== 'change_registration_status' && $note === '') {
            $error = 'กรุณาระบุเหตุผล';
        } elseif ($action === 'change_registration_status' && $newApprovalStatus === 'rejected' && $note === '') {
            $error = 'กรุณาระบุเหตุผลเมื่อปฏิเสธใบสมัคร';
        } else {
            $registrationStmt = $pdo->prepare('SELECT tr.*, tour.status AS tournament_status, tour.name AS tournament_name
                FROM tournament_registrations tr
                JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
                WHERE tr.tournament_registration_id = :registration_id LIMIT 1');
            $registrationStmt->execute(['registration_id' => $registrationId]);
            $registration = $registrationStmt->fetch(PDO::FETCH_ASSOC);
            if (!$registration) {
                $error = 'ไม่พบรายการสมัคร Tournament';
            } elseif ($action === 'change_registration_status' && $registration['status'] === $newApprovalStatus) {
                $error = 'ใบสมัครนี้อยู่ในสถานะดังกล่าวอยู่แล้ว';
            } elseif ($action === 'change_registration_status' && in_array($registration['participation_status'], ['withdrawn', 'disqualified'], true)) {
                $error = 'รายการนี้เป็นแบบอ่านอย่างเดียว เนื่องจากถอนตัวหรือตัดสิทธิ์แล้ว';
            } elseif ($action !== 'change_registration_status' && $registration['participation_status'] === $newParticipationStatus) {
                $error = 'รายการนี้อยู่ในสถานะดังกล่าวอยู่แล้ว';
            } elseif ($action === 'disqualify_registration' && $registration['status'] !== 'approved') {
                $error = 'ตัดสิทธิ์ได้เฉพาะใบสมัครที่อนุมัติแล้ว';
            } elseif ($action === 'change_registration_status' && $newApprovalStatus === 'approved' && $registration['tournament_status'] === 'completed') {
                $error = 'Tournament จบการแข่งขันแล้ว ไม่สามารถอนุมัติได้';
            } else {
                $matchCount = 0;
                $startedMatchCount = 0;
                if (!empty($registration['team_id'])) {
                    $matchStmt = $pdo->prepare('SELECT COUNT(*) AS total_matches,
                            SUM(CASE WHEN status NOT IN (\'scheduled\', \'cancelled\') THEN 1 ELSE 0 END) AS started_matches
                        FROM matches
                        WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id
                          AND (team1_id = :team_id OR team2_id = :team_id)');
                    $matchStmt->execute([
                        'tournament_id' => (int) $registration['tournament_id'],
                        'category_id' => (int) $registration['tournament_category_id'],
                        'team_id' => (int) $registration['team_id'],
                    ]);
                    $matchRow = $matchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $matchCount = (int) ($matchRow['total_matches'] ?? 0);
                    $startedMatchCount = (int) ($matchRow['started_matches'] ?? 0);
                } elseif (!empty($registration['player_id'])) {
                    $matchStmt = $pdo->prepare('SELECT COUNT(*) AS total_matches,
                            SUM(CASE WHEN status NOT IN (\'scheduled\', \'cancelled\') THEN 1 ELSE 0 END) AS started_matches
                        FROM matches
                        WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id
                          AND (team1_id = :player_id_1 OR team2_id = :player_id_2)');
                    $matchStmt->execute([
                        'tournament_id' => (int) $registration['tournament_id'],
                        'category_id' => (int) $registration['tournament_category_id'],
                        'player_id_1' => (int) $registration['player_id'],
                        'player_id_2' => (int) $registration['player_id'],
                    ]);
                    $matchRow = $matchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $matchCount = (int) ($matchRow['total_matches'] ?? 0);
                    $startedMatchCount = (int) ($matchRow['started_matches'] ?? 0);
                }
                if ($action === 'withdraw_registration' && $startedMatchCount > 0) {
                    $error = 'ใบสมัครนี้มี Match ที่เริ่มหรือจบแล้ว จึงไม่สามารถถอนผ่าน Flow ปกติได้';
                } elseif ($action === 'disqualify_registration' && $startedMatchCount > 0) {
                    $error = 'มี Match ที่เริ่มหรือจบแล้ว จึงไม่สามารถตัดสิทธิ์ผ่าน Flow ปกติได้';
                } else {
                    try {
                        $pdo->beginTransaction();
                        if ($newParticipationStatus === 'withdrawn' && !empty($registration['player_id'])) {
                            $withdrawnMatchStmt = $pdo->prepare("SELECT match_id, team1_id, team2_id FROM matches
                                WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id
                                  AND status NOT IN ('completed', 'walkover', 'cancelled')
                                  AND (team1_id = :player_id_1 OR team2_id = :player_id_2)");
                            $withdrawnMatchStmt->execute([
                                'tournament_id' => (int) $registration['tournament_id'],
                                'category_id' => (int) $registration['tournament_category_id'],
                                'player_id_1' => (int) $registration['player_id'],
                                'player_id_2' => (int) $registration['player_id'],
                            ]);
                            foreach ($withdrawnMatchStmt->fetchAll(PDO::FETCH_ASSOC) as $withdrawnMatch) {
                                $winnerId = (int) $withdrawnMatch['team1_id'] === (int) $registration['player_id']
                                    ? $withdrawnMatch['team2_id'] : $withdrawnMatch['team1_id'];
                                if (!$winnerId) continue;
                                $pdo->prepare("UPDATE matches SET winner_team_id = :winner, status = 'walkover', result_type = 'bye', wo_reason = 'คู่แข่งถอนตัว', completed_at = NOW() WHERE match_id = :match_id")
                                    ->execute(['winner' => $winnerId, 'match_id' => (int) $withdrawnMatch['match_id']]);
                                advanceMatchResult($pdo, (int) $withdrawnMatch['match_id'], (int) $winnerId, (int) $registration['player_id']);
                            }
                        }
                        if ($action === 'change_registration_status') {
                            $update = $pdo->prepare('UPDATE tournament_registrations
                                SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW(),
                                    review_note = :review_note,
                                    qr_code_token = CASE WHEN :status_for_token = \'approved\' AND (qr_code_token IS NULL OR qr_code_token = \'\') THEN :token ELSE qr_code_token END,
                                    participation_status = CASE WHEN :status_for_participation = \'approved\' THEN \'registered\' ELSE participation_status END
                                WHERE tournament_registration_id = :registration_id AND status = :old_status');
                            $token = bin2hex(random_bytes(24));
                            $update->execute([
                                'status' => $newApprovalStatus,
                                'reviewed_by' => (int) $_SESSION['user_id'],
                                'review_note' => $note !== '' ? $note : null,
                                'status_for_token' => $newApprovalStatus,
                                'status_for_participation' => $newApprovalStatus,
                                'token' => $token,
                                'registration_id' => $registrationId,
                                'old_status' => (string) $registration['status'],
                            ]);
                            if ($newApprovalStatus === 'approved') {
                                snapshotTournamentRoster($pdo, $registrationId, $registration['team_id'] ? (int) $registration['team_id'] : null, $registration['player_id'] ? (int) $registration['player_id'] : null);
                            }
                            $historyNote = 'Approval status: ' . $registration['status'] . ' -> ' . $newApprovalStatus . ($note !== '' ? ' | ' . $note : '');
                            recordRegistrationStatus($pdo, $registrationId, $newApprovalStatus, (int) $_SESSION['user_id'], $historyNote, (string) $registration['status']);
                        } else {
                            $update = $pdo->prepare('UPDATE tournament_registrations
                                SET participation_status = :participation_status
                                WHERE tournament_registration_id = :registration_id AND participation_status <> :old_participation_status');
                            $update->execute([
                                'participation_status' => $newParticipationStatus,
                                'registration_id' => $registrationId,
                                'old_participation_status' => $newParticipationStatus,
                            ]);
                            $historyNote = 'Participation status: ' . $registration['participation_status'] . ' -> ' . $newParticipationStatus . ' | ' . $note;
                            recordRegistrationStatus($pdo, $registrationId, (string) $newParticipationStatus, (int) $_SESSION['user_id'], $historyNote, (string) $registration['status']);
                        }
                        if ($update->rowCount() !== 1) throw new RuntimeException('ไม่สามารถบันทึกสถานะได้');
                        $pdo->commit();
                        $success = $action === 'change_registration_status' ? 'เปลี่ยนสถานะใบสมัครเรียบร้อยแล้ว' : 'อัปเดตสถานะการเข้าร่วมเรียบร้อยแล้ว';
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $error = 'ไม่สามารถบันทึกการเปลี่ยนแปลงได้';
                    }
                }
            }
        }
    }
}

$allTournaments = $pdo->query(
    "SELECT t.tournament_id, t.name, t.status, t.start_date, t.end_date, t.checkin_open_at, t.checkin_close_at, g.name AS game_name, g.play_mode
     FROM tournaments t
     JOIN games g ON g.game_id = t.game_id
     ORDER BY t.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$tournamentId && !empty($allTournaments)) {
    $tournamentId = (int) $allTournaments[0]['tournament_id'];
}

$tournament = null;
if ($tournamentId) {
    $tStmt = $pdo->prepare("
        SELECT t.*, g.name AS game_name, g.play_mode
        FROM tournaments t
        JOIN games g ON g.game_id = t.game_id
        WHERE t.tournament_id = :id
        LIMIT 1
    ");
    $tStmt->execute(['id' => $tournamentId]);
    $tournament = $tStmt->fetch(PDO::FETCH_ASSOC);
}

$activeCategories = [];
if ($tournament) {
    $categoryStmt = $pdo->prepare("
        SELECT tournament_category_id, category_code, label, max_participants, format, is_active
        FROM tournament_categories
        WHERE tournament_id = :tid AND is_active = 1
        ORDER BY tournament_category_id ASC
    ");
    $categoryStmt->execute(['tid' => $tournamentId]);
    $activeCategories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($selectedCategoryId && !empty($activeCategories)) {
    $allowedIds = array_map('intval', array_column($activeCategories, 'tournament_category_id'));
    if (!in_array($selectedCategoryId, $allowedIds, true)) {
        $selectedCategoryId = 0;
    }
}

if (!$selectedCategoryId && !empty($activeCategories)) {
    $selectedCategoryId = (int) ($activeCategories[0]['tournament_category_id'] ?? 0);
}

$registrationAction = trim((string) ($_GET['registration_action'] ?? 'detail'));
$allowedRegistrationActions = ['detail', 'view_roster', 'change_registration_status', 'view_checkin', 'show_qr', 'withdraw_registration', 'disqualify_registration'];
if (!in_array($registrationAction, $allowedRegistrationActions, true)) {
    $registrationAction = 'invalid';
    http_response_code(400);
}
$autoOpenRegistrationId = (int) ($_GET['registration_id'] ?? 0);
$autoOpenRegistration = $autoOpenRegistrationId ? getRegistrationDetail($pdo, $autoOpenRegistrationId) : null;
if ($autoOpenRegistrationId && (!$autoOpenRegistration || (int) $autoOpenRegistration['tournament_id'] !== $tournamentId)) {
    $autoOpenRegistrationId = 0;
    $autoOpenRegistration = null;
}
if ($autoOpenRegistration) {
    $selectedCategoryId = (int) $autoOpenRegistration['tournament_category_id'];
}
$autoOpenRegistrationMembers = $autoOpenRegistrationId ? getRegistrationMembers($pdo, $autoOpenRegistrationId) : [];
$autoOpenMatchCount = $autoOpenRegistration ? getRegistrationMatchCount($pdo, $autoOpenRegistration) : 0;
$registrationActionTitles = [
    'detail' => 'รายละเอียดใบสมัคร',
    'view_roster' => 'ตรวจ Tournament Roster',
    'change_registration_status' => 'เปลี่ยนสถานะใบสมัคร',
    'view_checkin' => 'ดูสถานะ Check-in',
    'show_qr' => 'แสดง QR Check-in',
    'withdraw_registration' => 'ถอนออกจากการแข่งขัน',
    'disqualify_registration' => 'ตัดสิทธิ์ผู้สมัคร',
    'invalid' => 'คำสั่งไม่ถูกต้อง',
];
$registrationActionTitle = $registrationActionTitles[$registrationAction] ?? $registrationActionTitles['detail'];

$summary = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'revision_required' => 0,
    'rejected' => 0,
    'withdrawn' => 0,
    'checkin_complete' => 0,
    'checkin_incomplete' => 0,
    'qualified' => 0,
    'disqualified' => 0,
];

if ($tournament) {
    $summarySql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN tr.status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN tr.status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN tr.status = 'revision_required' THEN 1 ELSE 0 END) AS revision_required,
            SUM(CASE WHEN tr.status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN tr.status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn,
            SUM(CASE WHEN tr.status = 'approved' AND EXISTS (
                SELECT 1
                FROM tournament_registration_members trm
                WHERE trm.tournament_registration_id = tr.tournament_registration_id
                  AND trm.is_required_for_checkin = 1
                  AND trm.checkin_status IN ('checked_in', 'waived')
            ) AND NOT EXISTS (
                SELECT 1
                FROM tournament_registration_members trm2
                WHERE trm2.tournament_registration_id = tr.tournament_registration_id
                  AND trm2.is_required_for_checkin = 1
                  AND trm2.checkin_status NOT IN ('checked_in', 'waived')
            ) THEN 1 ELSE 0 END) AS checkin_complete,
            SUM(CASE WHEN tr.status = 'approved' AND EXISTS (
                SELECT 1
                FROM tournament_registration_members trm3
                WHERE trm3.tournament_registration_id = tr.tournament_registration_id
                  AND trm3.is_required_for_checkin = 1
                  AND trm3.checkin_status NOT IN ('checked_in', 'waived')
            ) THEN 1 ELSE 0 END) AS checkin_incomplete,
            SUM(CASE WHEN tr.participation_status = 'qualified_for_draw' THEN 1 ELSE 0 END) AS qualified,
            SUM(CASE WHEN tr.participation_status IN ('disqualified', 'walkover') THEN 1 ELSE 0 END) AS disqualified
        FROM tournament_registrations tr
        WHERE tr.tournament_id = :tid";

    if ($selectedCategoryId) {
        $summarySql .= " AND tr.tournament_category_id = :category_id";
    }

    $summaryStmt = $pdo->prepare($summarySql);
    $params = ['tid' => $tournamentId];
    if ($selectedCategoryId) {
        $params['category_id'] = $selectedCategoryId;
    }
    $summaryStmt->execute($params);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($summary as $key => $value) {
        $summary[$key] = (int) ($summaryRow[$key] ?? 0);
    }
}

$rows = [];
if ($tournament) {
    $query = "
        SELECT
            tr.tournament_registration_id,
            tr.tournament_id,
            tr.status,
            tr.checkin_status,
            tr.participation_status,
            tr.registered_at,
            tr.seed_no,
            tr.tournament_category_id,
            tc.category_code,
            tc.label AS category_label,
            tr.team_id,
            tr.player_id,
            CASE
                WHEN tr.team_id IS NOT NULL THEN team.name
                WHEN p.display_name IS NOT NULL AND TRIM(p.display_name) <> '' THEN p.display_name
                ELSE u.username
            END AS display_name,
            CASE
                WHEN tr.team_id IS NOT NULL THEN team.name
                ELSE COALESCE(p.display_name, u.username)
            END AS display_name_raw,
            COALESCE(team.name, COALESCE(p.display_name, u.username, 'ผู้เล่น')) AS raw_name,
            u.username,
            u.email
        FROM tournament_registrations tr
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
        LEFT JOIN teams team ON team.team_id = tr.team_id
        LEFT JOIN players p ON p.player_id = tr.player_id
        LEFT JOIN users u ON u.user_id = p.user_id
        WHERE tr.tournament_id = :tid";

    $bind = ['tid' => $tournamentId];

    if ($selectedCategoryId) {
        $query .= " AND tr.tournament_category_id = :category_id";
        $bind['category_id'] = $selectedCategoryId;
    }

    if ($search !== '') {
        $query .= " AND (
            team.name LIKE :search OR
            p.display_name LIKE :search OR
            u.username LIKE :search OR
            u.email LIKE :search
        )";
        $bind['search'] = '%' . $search . '%';
    }

    if ($approvalStatus !== 'all') {
        $query .= " AND tr.status = :approval_status";
        $bind['approval_status'] = $approvalStatus;
    }

    if ($drawStatus !== 'all') {
        $query .= " AND tr.participation_status = :draw_status";
        $bind['draw_status'] = $drawStatus;
    }

    $query .= " ORDER BY tr.registered_at DESC, tr.tournament_registration_id DESC";

    $rowsStmt = $pdo->prepare($query);
    $rowsStmt->execute($bind);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$isSolo = ($tournament['play_mode'] ?? 'team') === 'solo';
$csrfToken = generateCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: $success);
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'manage-teams.php'), true, 303);
    exit;
}
$flash = consumeFlashMessage();
if ($flash) {
    if ($flash['type'] === 'error') $error = $flash['message'];
    else $success = $flash['message'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการผู้สมัคร/ทีมแข่งขัน</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700;800&family=Orbitron:wght@700;900&display=swap" rel="stylesheet">
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
                            sidebar: '#0F172A'
                        }
                    },
                    fontFamily: {
                        sans: ['Kanit', 'sans-serif'],
                        display: ['Orbitron', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style>
        body {
            background: #F4F6F9;
            overflow-x: hidden;
        }
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
        .scrollbar-thin::-webkit-scrollbar { height: 6px; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 9999px; }
        .page-shell {
            max-width: 100vw;
            min-width: 0;
        }
        .content-panel {
            min-width: 0;
            max-width: 100%;
        }
        .main-content {
            min-width: 0;
            width: 100%;
            max-width: 100%;
        }
    </style>
</head>
<body class="text-slate-800 font-sans min-h-screen flex antialiased">
    <div id="adminSidebarBackdrop" class="fixed inset-0 z-40 hidden bg-slate-950/50 lg:hidden" aria-hidden="true"></div>
    <aside id="adminSidebar" class="w-64 bg-brand-sidebar text-slate-300 flex flex-col fixed inset-y-0 left-0 z-50 shadow-xl -translate-x-full transition-transform duration-200 lg:translate-x-0">
        <div class="p-6 border-b border-slate-800 flex items-center gap-3">
            <img src="../assets/img/logo.png" alt="Korat Esport" class="h-10 w-auto filter drop-shadow" onError="this.src='https://placehold.co/80x80/0F172A/FF5500?text=KE';">
            <div>
                <h1 class="font-display font-black text-lg text-white tracking-wider">KORAT <span class="text-brand-orange">ESPORT</span></h1>
                <p class="text-[10px] tracking-widest text-slate-400 uppercase font-semibold">Admin Command Center</p>
            </div>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 px-2 space-y-1 text-sm font-medium">
            <a href="dashboard.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-chart-pie w-5 text-center"></i><span>หน้าหลัก (Dashboard)</span></a>
            <a href="manage-tournament.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-trophy w-5 text-center"></i><span>จัดการทัวร์นาเมนต์</span></a>
            <a href="manage-teams.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white"><i class="fa-solid fa-people-group w-5 text-center text-brand-orange"></i><span>จัดการผู้สมัคร/ทีมแข่งขัน</span></a>
            <a href="manage-members.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-users-gear w-5 text-center"></i><span>จัดการสมาชิก</span></a>
            <a href="manage-news.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-newspaper w-5 text-center"></i><span>จัดการข่าวสาร</span></a>
            <a href="manage-gallery.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-images w-5 text-center"></i><span>จัดการแกลเลอรี่</span></a>
            <a href="recommended-lodging.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-hotel w-5 text-center"></i><span>ที่พักแนะนำ</span></a>
            <a href="record-match.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-pen-to-square w-5 text-center"></i><span>บันทึกผลแมตช์</span></a>
            <a href="checkin-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white"><i class="fa-solid fa-user-check w-5 text-center"></i><span>เช็คอินทีม</span></a>
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

    <div class="page-shell flex-1 ml-0 lg:ml-64 min-h-screen flex flex-col">
        <header class="bg-white border-b border-slate-200 px-4 sm:px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div class="flex items-center gap-3">
                <button type="button" id="adminMenuToggle" class="lg:hidden inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200" aria-label="เปิดเมนู Admin" aria-expanded="false">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h1 class="text-lg sm:text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                        <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                        จัดการผู้สมัคร/ทีมแข่งขัน <span class="hidden md:inline text-brand-orange">(REGISTRATION MANAGEMENT)</span>
                    </h1>
                    <p class="text-xs text-slate-500 mt-0.5">ตรวจใบสมัคร Tournament Roster การอนุมัติ และ Check-in</p>
                </div>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="manage-tournament.php?tournament_id=<?= (int) $tournamentId ?>" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200">
                    <i class="fa-solid fa-arrow-left"></i><span class="hidden sm:inline">กลับไปหน้าจัดการ Tournament</span><span class="sm:hidden">Tournament</span>
                </a>
                <a href="../pages/index.php" target="_blank" class="hidden md:inline-flex text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-2 rounded-lg">
                    <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
                </a>
            </div>
        </header>

        <main class="main-content p-8 space-y-8">
            <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
                <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                    <div class="w-full lg:w-72">
                        <label class="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500">Tournament</label>
                        <form method="GET" class="mt-2">
                            <select name="tournament_id" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium focus:border-brand-orange focus:bg-white focus:outline-none">
                                <?php foreach ($allTournaments as $item): ?>
                                    <option value="<?= (int) $item['tournament_id'] ?>" <?= ((int) $item['tournament_id'] === $tournamentId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($item['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    <?php if ($tournament): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500">เกม</div>
                            <div class="mt-1 text-sm font-bold text-slate-800"><?= htmlspecialchars($tournament['game_name'] ?? '-') ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500">รูปแบบ</div>
                            <div class="mt-1 text-sm font-bold text-slate-800"><?= ($tournament['play_mode'] ?? 'team') === 'solo' ? 'Solo' : 'Team' ?></div>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500">Check-in</div>
                            <div class="mt-1 text-sm font-bold text-slate-800"><?= !empty($tournament['checkin_open_at']) ? date('d/m/Y', strtotime($tournament['checkin_open_at'])) : '-' ?> - <?= !empty($tournament['checkin_close_at']) ? date('d/m/Y', strtotime($tournament['checkin_close_at'])) : '-' ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($tournament): ?>
                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 overflow-x-auto scrollbar-thin">
                    <div class="flex flex-nowrap items-center gap-2 min-w-max">
                        <a href="?tournament_id=<?= $tournamentId ?>&category_id=0" class="px-4 py-2 rounded-xl text-xs font-bold <?= !$selectedCategoryId ? 'bg-brand-orange text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                            ทั้งหมด
                        </a>
                        <?php foreach ($activeCategories as $category): ?>
                            <a href="?tournament_id=<?= $tournamentId ?>&category_id=<?= (int) $category['tournament_category_id'] ?>" class="px-4 py-2 rounded-xl text-xs font-bold <?= ((int) $category['tournament_category_id'] === $selectedCategoryId) ? 'bg-brand-orange text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                                <?= htmlspecialchars($category['label'] ?: $category['category_code']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-4">
                    <?php
                    $summaryCards = [
                        ['label' => 'สมัครทั้งหมด', 'value' => $summary['total'], 'style' => 'bg-slate-100 text-slate-700 border-slate-200'],
                        ['label' => 'รอตรวจสอบ', 'value' => $summary['pending'], 'style' => 'bg-yellow-100 text-yellow-700 border-yellow-200'],
                        ['label' => 'อนุมัติแล้ว', 'value' => $summary['approved'], 'style' => 'bg-emerald-100 text-emerald-700 border-emerald-200'],
                        ['label' => 'ส่งกลับแก้ไข', 'value' => $summary['revision_required'], 'style' => 'bg-orange-100 text-orange-700 border-orange-200'],
                        ['label' => 'ปฏิเสธ', 'value' => $summary['rejected'], 'style' => 'bg-red-100 text-red-700 border-red-200'],
                        ['label' => 'ถอนตัว', 'value' => $summary['withdrawn'], 'style' => 'bg-slate-200 text-slate-700 border-slate-300'],
                    ];
                    ?>
                    <?php foreach ($summaryCards as $card): ?>
                        <div class="rounded-2xl border p-4 <?= $card['style'] ?> shadow-sm">
                            <div class="text-[10px] uppercase tracking-[0.2em] font-bold"><?= $card['label'] ?></div>
                            <div class="mt-2 text-2xl font-black"><?= (int) $card['value'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </section>

                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
                        <input type="hidden" name="tournament_id" value="<?= $tournamentId ?>">
                        <input type="hidden" name="category_id" value="<?= $selectedCategoryId ?>">
                        <div class="md:col-span-2 xl:col-span-2">
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">ค้นหา</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="ชื่อทีม / ผู้เล่น / Username / Email" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">สถานะการอนุมัติ</label>
                            <select name="approval_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                                <option value="all" <?= $approvalStatus === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                                <option value="pending" <?= $approvalStatus === 'pending' ? 'selected' : '' ?>>รอตรวจสอบ</option>
                                <option value="approved" <?= $approvalStatus === 'approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                                <option value="revision_required" <?= $approvalStatus === 'revision_required' ? 'selected' : '' ?>>ส่งกลับแก้ไข</option>
                                <option value="rejected" <?= $approvalStatus === 'rejected' ? 'selected' : '' ?>>ปฏิเสธ</option>
                                <option value="withdrawn" <?= $approvalStatus === 'withdrawn' ? 'selected' : '' ?>>ถอนตัว</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">Check-in</label>
                            <select name="checkin_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                                <option value="all" <?= $checkinStatus === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                                <option value="not_checked_in" <?= $checkinStatus === 'not_checked_in' ? 'selected' : '' ?>>ยังไม่ Check-in</option>
                                <option value="partial" <?= $checkinStatus === 'partial' ? 'selected' : '' ?>>Check-in บางส่วน</option>
                                <option value="checked_in" <?= $checkinStatus === 'checked_in' ? 'selected' : '' ?>>Check-in ครบ</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1">สิทธิ์จัดสาย</label>
                            <select name="draw_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                                <option value="all" <?= $drawStatus === 'all' ? 'selected' : '' ?>>ทั้งหมด</option>
                                <option value="registered" <?= $drawStatus === 'registered' ? 'selected' : '' ?>>ยังไม่พร้อม</option>
                                <option value="qualified_for_draw" <?= $drawStatus === 'qualified_for_draw' ? 'selected' : '' ?>>พร้อมจัดสาย</option>
                                <option value="disqualified" <?= $drawStatus === 'disqualified' ? 'selected' : '' ?>>ตัดสิทธิ์</option>
                                <option value="walkover" <?= $drawStatus === 'walkover' ? 'selected' : '' ?>>WO</option>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button type="submit" class="flex-1 rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow">กรอง</button>
                            <a href="?tournament_id=<?= $tournamentId ?>" class="flex-1 rounded-xl bg-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 text-center hover:bg-slate-300">ล้าง</a>
                        </div>
                    </form>
                </section>

                <section class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-visible">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 p-4 border-b border-slate-200 bg-slate-50/60">
                        <h2 class="text-sm font-bold uppercase tracking-[0.2em] text-slate-700">รายการสมัคร</h2>
                        <button type="button" id="openAddSoloPlayerModal" class="inline-flex items-center gap-2 rounded-xl bg-brand-orange px-4 py-2 text-sm font-bold text-white hover:bg-brand-glow">
                            <i class="fa-solid fa-plus"></i> + เพิ่มทีม/ผู้แข่งขัน
                        </button>
                    </div>

                    <?php if (empty($rows)): ?>
                        <div class="p-12 text-center text-slate-500">
                            <div class="text-4xl text-brand-orange mb-3"><i class="fa-solid fa-box-open"></i></div>
                            <p class="text-lg font-bold text-slate-700">ยังไม่มีทีมสมัครใน Category นี้</p>
                            <p class="text-sm mt-1">เพิ่มทีม/ผู้แข่งขันเพื่อเริ่มจัดการ Tournament</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto overflow-y-visible">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-slate-100 text-slate-600 text-[10px] uppercase tracking-[0.2em]">
                                    <tr>
                                        <th class="px-4 py-3">ทีม/ผู้แข่งขัน</th>
                                        <th class="px-4 py-3">Category</th>
                                        <th class="px-4 py-3">สมัครเมื่อ</th>
                                        <th class="px-4 py-3">Roster</th>
                                        <th class="px-4 py-3">การอนุมัติ</th>
                                        <th class="px-4 py-3">Check-in</th>
                                        <th class="px-4 py-3">สิทธิ์จัดสาย</th>
                                        <th class="px-4 py-3">Group/Seed</th>
                                        <th class="px-4 py-3 text-right">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <?php foreach ($rows as $row): ?>
                                        <?php $progress = getCheckinCompletion($pdo, (int) $row['tournament_registration_id']); ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-10 w-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-700 font-bold">
                                                        <?= htmlspecialchars(strtoupper(substr($row['display_name_raw'] ?? 'U', 0, 1))) ?>
                                                    </div>
                                                    <div>
                                                        <div class="font-bold text-slate-900"><?= htmlspecialchars($row['display_name_raw'] ?? '-') ?></div>
                                                        <div class="text-[11px] text-slate-500"><?= htmlspecialchars($row['username'] ?? 'User') ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-700">
                                                    <?= htmlspecialchars($row['category_label'] ?: $row['category_code'] ?: 'Open') ?>
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-slate-600"><?= !empty($row['registered_at']) ? date('d/m/Y H:i', strtotime($row['registered_at'])) : '-' ?></td>
                                            <td class="px-4 py-3 text-slate-700">
                                                <?php
                                                $rosterStmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registration_members WHERE tournament_registration_id = :id");
                                                $rosterStmt->execute(['id' => (int) $row['tournament_registration_id']]);
                                                $rosterCount = (int) $rosterStmt->fetchColumn();
                                                ?>
                                                <?= $rosterCount ?> คน
                                            </td>
                                            <td class="px-4 py-3"><?= statusBadge((string) ($row['status'] ?? 'pending'), 'approval') ?></td>
                                            <td class="px-4 py-3"><?= statusBadge($progress['status'], 'checkin') ?></td>
                                            <td class="px-4 py-3"><?= statusBadge((string) ($row['participation_status'] ?: 'registered'), 'participation') ?></td>
                                            <td class="px-4 py-3 text-slate-600"><?= $row['seed_no'] ? '#' . (int) $row['seed_no'] : '-' ?></td>
                                            <td class="px-4 py-3 text-right">
                                                <?php $registrationId = (int) $row['tournament_registration_id']; ?>
                                                <div class="flex justify-end gap-2">
                                                    <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=detail" class="inline-flex h-9 items-center rounded-lg bg-slate-100 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-200">รายละเอียด</a>
                                                    <?php if (($row['status'] ?? '') === 'pending'): ?>
                                                        <form method="POST" class="inline-flex" onsubmit="return confirm('ยืนยันอนุมัติใบสมัครนี้?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                            <input type="hidden" name="action" value="approve_registration">
                                                            <input type="hidden" name="registration_id" value="<?= (int) $row['tournament_registration_id'] ?>">
                                                            <button type="submit" class="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white hover:bg-emerald-700">อนุมัติ</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <div class="relative">
                                                        <button type="button" class="admin-action-toggle registration-action-toggle relative z-30 pointer-events-auto inline-flex h-9 items-center rounded-lg bg-brand-orange px-3 text-xs font-semibold text-white hover:bg-brand-glow" data-registration-id="<?= $registrationId ?>" data-menu-target="registration-menu-<?= $registrationId ?>" data-action-menu="registration-menu-<?= $registrationId ?>" aria-expanded="false" aria-controls="registration-menu-<?= $registrationId ?>">จัดการ</button>
                                                        <div id="registration-menu-<?= $registrationId ?>" class="admin-action-menu registration-action-menu fixed hidden z-[70] rounded-xl border border-slate-200 bg-white shadow-xl" data-registration-id="<?= $registrationId ?>" role="menu">
                                                            <div class="admin-action-group">การตรวจสอบ</div>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=view_roster" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-action="view_roster" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-clipboard-check text-slate-400"></i>ตรวจ Tournament Roster</a>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=change_registration_status" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-action="change_registration_status" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-pen-to-square text-slate-400"></i>เปลี่ยนสถานะใบสมัคร</a>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=view_checkin" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-action="view_checkin" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-user-check text-slate-400"></i>ดูสถานะ Check-in</a>
                                                            <div class="my-1 border-t border-slate-100"></div>
                                                            <div class="admin-action-group">การแข่งขัน</div>
                                                            <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=show_qr" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-action="show_qr" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-qrcode text-slate-400"></i>แสดง QR</a>
                                                            <?php if (($row['participation_status'] ?? '') === 'qualified_for_draw' || !empty($row['seed_no'])): ?>
                                                                <a href="manage-tournament.php?tournament_id=<?= (int) $tournamentId ?>" class="admin-action-item text-slate-700 hover:bg-slate-50" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-sitemap text-slate-400"></i>ดู Group/Bracket</a>
                                                            <?php endif; ?>
                                                            <div class="my-1 border-t border-slate-100"></div>
                                                            <div class="admin-action-group">คำสั่งที่มีผลกระทบ</div>
                                                            <?php if (($row['participation_status'] ?? '') !== 'withdrawn'): ?>
                                                                <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=withdraw_registration" class="admin-action-item text-red-600 hover:bg-red-50" data-registration-action="withdraw_registration" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-arrow-right-from-bracket"></i>ถอนออกจากการแข่งขัน</a>
                                                            <?php endif; ?>
                                                            <?php if (($row['participation_status'] ?? '') !== 'disqualified' && ($row['status'] ?? '') === 'approved'): ?>
                                                                <a href="?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>&registration_id=<?= $registrationId ?>&registration_action=disqualify_registration" class="admin-action-item text-red-600 hover:bg-red-50" data-registration-action="disqualify_registration" data-registration-id="<?= $registrationId ?>" role="menuitem"><i class="fa-solid fa-ban"></i>ตัดสิทธิ์</a>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?php if ($tournament && in_array(($tournament['play_mode'] ?? 'team'), ['solo', 'team'], true)): ?>
        <div id="addSoloPlayerModal" data-play-mode="<?= htmlspecialchars((string) $tournament['play_mode'], ENT_QUOTES) ?>" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-900/70 p-4">
            <div class="w-full max-w-3xl rounded-2xl border border-slate-200 bg-white shadow-2xl overflow-visible">
                <div class="flex items-center justify-between rounded-t-2xl border-b border-slate-200 bg-slate-50 px-6 py-4">
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500">เพิ่มผู้แข่งขัน</p>
                        <h3 id="addPlayerModalTitle" class="text-lg font-black text-slate-900">เพิ่ม<?= ($tournament['play_mode'] ?? 'team') === 'solo' ? 'ผู้แข่งขัน Solo' : 'ทีม' ?></h3>
                    </div>
                    <button type="button" id="closeAddSoloPlayerModal" class="text-slate-400 hover:text-slate-600 p-1" aria-label="ปิดหน้าต่างเพิ่มผู้แข่งขัน"><i class="fa-solid fa-xmark text-xl"></i></button>
                </div>
                <div class="p-6 space-y-5">
                    <form id="addPlayerSearchForm" method="GET" class="grid gap-3 md:grid-cols-[1fr_auto]">
                        <input type="hidden" name="tournament_id" value="<?= (int) $tournamentId ?>">
                        <input type="hidden" name="category_id" value="<?= (int) $selectedCategoryId ?>">
                        <div class="relative">
                            <label id="addPlayerSearchLabel" class="block text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-1"><?= ($tournament['play_mode'] ?? 'team') === 'solo' ? 'ค้นหาผู้เล่นที่มีบัญชีและโปรไฟล์แล้ว' : 'ค้นหาทีมที่พร้อมสมัคร' ?></label>
                            <input id="addPlayerSearchInput" type="text" name="add_player_search" value="<?= htmlspecialchars($addPlayerSearch) ?>" placeholder="พิมพ์ชื่อเพื่อค้นหา..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="addPlayerSearchResultsList" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-brand-orange focus:bg-white focus:outline-none">
                            <div id="addPlayerSearchResultsContainer" class="absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                                <div id="addPlayerSearchEmptyState" class="hidden p-4 text-sm text-slate-600">ไม่พบผู้เล่นที่ตรงกับคำค้นหา</div>
                                <div id="addPlayerSearchLoadingState" class="hidden p-4 text-sm text-sky-700">กำลังค้นหา...</div>
                                <div id="addPlayerSearchErrorState" class="hidden p-4 text-sm text-red-700">ไม่สามารถค้นหาข้อมูลได้ กรุณาลองใหม่</div>
                                <div id="addPlayerSearchResultsList" class="max-h-[400px] overflow-y-auto divide-y divide-slate-200"></div>
                            </div>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="rounded-xl bg-brand-orange px-4 py-2.5 text-sm font-bold text-white hover:bg-brand-glow">ค้นหา</button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($autoOpenRegistrationId): ?>
        <div id="registrationDetailModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 p-4" data-registration-id="<?= (int) $autoOpenRegistrationId ?>" data-tournament-id="<?= (int) $tournamentId ?>" data-category-id="<?= (int) $selectedCategoryId ?>">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
                <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4 bg-slate-50">
                    <div>
                        <p class="text-[10px] uppercase tracking-[0.2em] text-slate-500"><?= htmlspecialchars($registrationActionTitle) ?></p>
                        <h3 class="text-lg font-black text-slate-900">
                            <?= htmlspecialchars($autoOpenRegistration['display_name'] ?? 'ผู้สมัคร') ?>
                        </h3>
                    </div>
                    <button type="button" id="registrationDetailClose" class="text-slate-400 hover:text-slate-600 p-1" aria-label="ปิดรายละเอียดใบสมัคร"><i class="fa-solid fa-xmark text-xl"></i></button>
                </div>
                <div class="overflow-y-auto p-6 space-y-5">
                    <?php if (!$autoOpenRegistration): ?>
                        <div class="p-8 text-center text-slate-500">ไม่พบข้อมูล Registration ที่เลือก</div>
                    <?php else: ?>
                        <?php if ($registrationAction === 'invalid'): ?>
                            <div class="rounded-xl border border-red-200 bg-red-50 p-8 text-center text-sm font-semibold text-red-700">ไม่รู้จักคำสั่งที่เลือก</div>
                        <?php elseif ($registrationAction === 'view_roster'): ?>
                            <div class="space-y-4">
                                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">Category</div><div class="mt-1 text-sm font-bold text-slate-800"><?= htmlspecialchars($autoOpenRegistration['category_label'] ?: $autoOpenRegistration['category_code'] ?: 'Open') ?></div></div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">รูปแบบ</div><div class="mt-1 text-sm font-bold text-slate-800"><?= !empty($autoOpenRegistration['team_id']) ? 'Team' : 'Solo' ?></div></div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">สมาชิก</div><div class="mt-1 text-sm font-bold text-slate-800"><?= count($autoOpenRegistrationMembers) ?> คน</div></div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">Starter/สำรอง</div><div class="mt-1 text-sm font-bold text-slate-800"><?= count(array_filter($autoOpenRegistrationMembers, static fn($member) => (int) $member['is_starter'] === 1)) ?>/<?= count(array_filter($autoOpenRegistrationMembers, static fn($member) => (int) $member['is_starter'] === 0)) ?></div></div>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">Roster</div><div class="mt-1 text-sm font-bold <?= empty($autoOpenRegistrationMembers) ? 'text-red-700' : 'text-emerald-700' ?>"><?= empty($autoOpenRegistrationMembers) ? 'ไม่ครบ' : 'มีข้อมูล' ?></div></div>
                                </div>
                                <div class="rounded-xl border border-slate-200 overflow-hidden">
                                    <div class="bg-slate-50 px-4 py-3 border-b border-slate-200 text-sm font-bold text-slate-700">สมาชิกและบทบาทใน Tournament Roster</div>
                                    <?php if (empty($autoOpenRegistrationMembers)): ?>
                                        <div class="p-6 text-center text-slate-500">ยังไม่มี Tournament Roster สำหรับใบสมัครนี้</div>
                                    <?php else: ?>
                                        <div class="overflow-x-auto"><table class="w-full text-left text-xs"><thead class="bg-white text-slate-500"><tr><th class="px-4 py-3">ผู้เล่น</th><th class="px-4 py-3">Username</th><th class="px-4 py-3">บทบาท</th><th class="px-4 py-3">ตำแหน่ง</th><th class="px-4 py-3">Required</th><th class="px-4 py-3">Roster Status</th></tr></thead><tbody class="divide-y divide-slate-200">
                                            <?php foreach ($autoOpenRegistrationMembers as $member): ?><tr><td class="px-4 py-3 font-bold text-slate-800"><?= htmlspecialchars($member['display_name'] ?: 'Player') ?></td><td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($member['username'] ?: '-') ?></td><td class="px-4 py-3 text-slate-600"><?= htmlspecialchars($member['member_roles'] ?: 'player') ?></td><td class="px-4 py-3 text-slate-600"><?= (int) $member['is_starter'] ? 'Starter' : 'Substitute' ?></td><td class="px-4 py-3"><?= (int) $member['is_required_for_checkin'] ? 'Required' : 'Optional' ?></td><td class="px-4 py-3 text-emerald-700 font-bold">Active</td></tr><?php endforeach; ?>
                                        </tbody></table></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif ($registrationAction === 'view_checkin'): ?>
                            <?php $activeMembers = array_values(array_filter($autoOpenRegistrationMembers, static fn($member) => ($member['checkin_status'] ?? '') !== 'removed')); $requiredMembers = array_values(array_filter($activeMembers, static fn($member) => (int) $member['is_required_for_checkin'] === 1)); $checkedMembers = array_values(array_filter($requiredMembers, static fn($member) => in_array($member['checkin_status'] ?? '', ['checked_in', 'waived'], true))); $waivedMembers = array_values(array_filter($requiredMembers, static fn($member) => ($member['checkin_status'] ?? '') === 'waived')); $missingMembers = array_values(array_filter($requiredMembers, static fn($member) => !in_array($member['checkin_status'] ?? '', ['checked_in', 'waived'], true))); $checkinPercent = count($requiredMembers) ? (int) round(count($checkedMembers) * 100 / count($requiredMembers)) : 0; $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')); $openAt = !empty($autoOpenRegistration['checkin_open_at']) ? new DateTimeImmutable($autoOpenRegistration['checkin_open_at'], new DateTimeZone('Asia/Bangkok')) : null; $closeAt = !empty($autoOpenRegistration['checkin_close_at']) ? new DateTimeImmutable($autoOpenRegistration['checkin_close_at'], new DateTimeZone('Asia/Bangkok')) : null; $checkinWindow = $openAt && $now < $openAt ? 'ยังไม่เปิด' : ($closeAt && $now > $closeAt ? 'ปิดแล้ว' : 'กำลังเปิด'); ?>
                            <div class="space-y-4">
                                <div class="grid grid-cols-2 md:grid-cols-5 gap-3"><div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">ต้อง Check-in</div><div class="mt-1 text-lg font-bold text-slate-800"><?= count($requiredMembers) ?></div></div><div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">Check-in แล้ว</div><div class="mt-1 text-lg font-bold text-emerald-700"><?= count($checkedMembers) - count($waivedMembers) ?></div></div><div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">อนุโลม</div><div class="mt-1 text-lg font-bold text-sky-700"><?= count($waivedMembers) ?></div></div><div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">ยังไม่ Check-in</div><div class="mt-1 text-lg font-bold text-amber-700"><?= count($missingMembers) ?></div></div><div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><div class="text-[10px] text-slate-500">ความพร้อม</div><div class="mt-1 text-lg font-bold <?= $checkinPercent === 100 ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $checkinPercent ?>%</div></div></div>
                                <div class="rounded-xl border border-slate-200 p-4 text-sm text-slate-700"><div class="flex flex-wrap gap-x-6 gap-y-2"><span>สถานะ: <strong><?= $checkinPercent === 100 ? 'Check-in ครบ' : 'Check-in ไม่ครบ' ?></strong></span><span>ช่วงเวลา: <strong><?= $checkinWindow ?></strong></span><span>เปิด: <strong><?= $openAt ? $openAt->format('d/m/Y H:i') : '-' ?></strong></span><span>ปิด: <strong><?= $closeAt ? $closeAt->format('d/m/Y H:i') : '-' ?></strong></span><span>Roster Lock: <strong><?= !empty($autoOpenRegistration['roster_locked_at']) ? date('d/m/Y H:i', strtotime($autoOpenRegistration['roster_locked_at'])) : '-' ?></strong></span></div></div>
                                <div class="rounded-xl border border-slate-200 overflow-hidden"><div class="bg-slate-50 px-4 py-3 border-b border-slate-200 text-sm font-bold text-slate-700">สถานะ Check-in รายสมาชิก</div><div class="divide-y divide-slate-200"><?php foreach ($activeMembers as $member): $memberCheckin = $member['checkin_status'] ?? 'not_checked_in'; $memberLabel = $memberCheckin === 'waived' ? 'ได้รับการอนุโลม' : ($memberCheckin === 'checked_in' ? 'Check-in แล้ว' : ((int) $member['is_required_for_checkin'] ? 'ยังไม่ Check-in' : 'ไม่จำเป็นต้อง Check-in')); ?><div class="flex items-center justify-between gap-3 px-4 py-3"><div><div class="font-bold text-slate-800"><?= htmlspecialchars($member['display_name'] ?: 'Player') ?></div><div class="text-[11px] text-slate-500"><?= htmlspecialchars($member['member_roles'] ?: 'player') ?> • <?= (int) $member['is_required_for_checkin'] ? 'Required' : 'Optional' ?></div></div><div class="text-right text-xs"><div class="font-bold <?= in_array($memberCheckin, ['checked_in', 'waived'], true) ? 'text-emerald-700' : 'text-amber-700' ?>"><?= $memberLabel ?></div><div class="text-slate-500"><?= !empty($member['checkin_at']) ? date('d/m/Y H:i', strtotime($member['checkin_at'])) : '-' ?></div></div></div><?php endforeach; ?></div></div>
                            </div>
                        <?php elseif ($registrationAction === 'change_registration_status'): ?>
                            <div class="rounded-xl border border-slate-200 p-4 space-y-4">
                                <div class="text-sm font-bold text-slate-800">สถานะการอนุมัติปัจจุบัน: <?= statusBadge((string) $autoOpenRegistration['status'], 'approval') ?></div>
                                <form method="POST" class="space-y-3">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="change_registration_status">
                                    <input type="hidden" name="registration_id" value="<?= (int) $autoOpenRegistrationId ?>">
                                    <label class="block text-xs font-bold text-slate-600">สถานะใหม่
                                        <select name="registration_status" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                            <?php foreach (['pending' => 'รอตรวจสอบ', 'approved' => 'อนุมัติแล้ว', 'rejected' => 'ไม่อนุมัติ'] as $statusValue => $statusLabel): ?>
                                                <option value="<?= $statusValue ?>" <?= $autoOpenRegistration['status'] === $statusValue ? 'selected' : '' ?>><?= $statusLabel ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="block text-xs font-bold text-slate-600">เหตุผล/หมายเหตุ
                                        <textarea name="status_note" rows="3" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="จำเป็นเมื่อไม่อนุมัติ"></textarea>
                                    </label>
                                    <button type="submit" class="inline-flex items-center rounded-lg bg-brand-orange px-4 py-2 text-xs font-bold text-white">ยืนยันเปลี่ยนสถานะ</button>
                                </form>
                            </div>
                        <?php elseif ($registrationAction === 'show_qr'): ?>
                            <div class="rounded-xl border border-slate-200 p-6 text-center space-y-3">
                                <?php if ($autoOpenRegistration['status'] === 'approved' && !empty($autoOpenRegistration['qr_code_token'])): ?>
                                    <div class="inline-block rounded-xl bg-white p-3 shadow"><img src="https://quickchart.io/qr?text=<?= urlencode((string) $autoOpenRegistration['qr_code_token']) ?>&size=220" alt="QR Code Check-in" class="h-52 w-52"></div>
                                    <div class="font-mono text-sm text-slate-700">รหัส Check-in: ****<?= htmlspecialchars(substr((string) $autoOpenRegistration['qr_code_token'], -4)) ?></div>
                                <?php else: ?>
                                    <div class="text-sm text-slate-500">ยังไม่มี QR สำหรับใบสมัครนี้ ต้องอนุมัติใบสมัครก่อน</div>
                                <?php endif; ?>
                            </div>
                        <?php elseif (in_array($registrationAction, ['withdraw_registration', 'disqualify_registration'], true)): ?>
                            <div class="rounded-xl border <?= $registrationAction === 'disqualify_registration' ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50' ?> p-4 space-y-4">
                                <div class="text-sm font-bold text-slate-800"><?= $registrationAction === 'disqualify_registration' ? 'ยืนยันตัดสิทธิ์ผู้สมัคร' : 'ยืนยันถอนผู้สมัครออกจากการแข่งขัน' ?></div>
                                <div class="text-xs text-slate-600">สถานะปัจจุบัน: <?= statusBadge((string) $autoOpenRegistration['status'], 'approval') ?> <?= statusBadge((string) ($autoOpenRegistration['participation_status'] ?: 'registered'), 'participation') ?></div>
                                <div class="text-xs text-slate-600">Match ที่เกี่ยวข้อง: <?= $autoOpenMatchCount ?> รายการ<?= $autoOpenMatchCount ? ' (หากจัดสายแล้ว ระบบจะไม่แก้ Bracket อัตโนมัติ)' : '' ?></div>
                                <?php if ($registrationAction === 'withdraw_registration' && $autoOpenMatchCount > 0): ?>
                                    <div class="text-sm font-semibold text-red-700">ไม่สามารถถอนผ่าน Flow ปกติได้ เนื่องจากมี Match แล้ว</div>
                                <?php else: ?>
                                    <form method="POST" class="space-y-3">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="action" value="<?= $registrationAction ?>">
                                        <input type="hidden" name="registration_id" value="<?= (int) $autoOpenRegistrationId ?>">
                                        <textarea name="status_note" rows="3" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="ระบุเหตุผล (จำเป็น)"></textarea>
                                        <button type="submit" class="inline-flex items-center rounded-lg <?= $registrationAction === 'disqualify_registration' ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700' ?> px-4 py-2 text-xs font-bold text-white" onclick="return confirm('ยืนยันรายการนี้หรือไม่?');">ยืนยัน</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">ประเภทการแข่งขัน</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= htmlspecialchars($autoOpenRegistration['category_label'] ?: $autoOpenRegistration['category_code'] ?: 'Open') ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">กัปตันทีม</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= htmlspecialchars($autoOpenRegistration['captain_name'] ?? '-') ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">Tournament Roster</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= (int) $autoOpenRegistration['roster_count'] ?> คน</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">สรุปการเช็กอิน</div>
                                <div class="mt-1 text-sm font-bold text-slate-800"><?= (int) $autoOpenRegistration['progress']['done'] ?>/<?= (int) $autoOpenRegistration['progress']['total'] ?></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-2">ข้อมูลสมัคร</div>
                                <div class="space-y-2 text-sm text-slate-700">
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Tournament</span><span class="font-bold text-slate-900"><?= htmlspecialchars($autoOpenRegistration['tournament_name'] ?? '-') ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">สถานะการอนุมัติ</span><span><?= statusBadge((string) ($autoOpenRegistration['status'] ?? 'pending'), 'approval') ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">สถานะพร้อมจัดสาย</span><span class="font-bold"><?= htmlspecialchars(($autoOpenRegistration['participation_status'] ?: 'registered') === 'registered' ? 'รอ Check-in' : ($autoOpenRegistration['participation_status'] ?: 'registered')) ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Group/Seed</span><span class="font-bold"><?= $autoOpenRegistration['seed_no'] ? '#' . (int) $autoOpenRegistration['seed_no'] : '-' ?></span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">สมัครเมื่อ</span><span class="font-bold"><?= !empty($autoOpenRegistration['registered_at']) ? date('d/m/Y H:i', strtotime($autoOpenRegistration['registered_at'])) : '-' ?></span></div>
                                </div>
                            </div>
                            <div class="rounded-xl border border-slate-200 p-4">
                                <div class="text-[10px] uppercase tracking-[0.2em] text-slate-500 mb-2">สรุปการเช็กอิน</div>
                                <div class="space-y-2 text-sm text-slate-700">
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">รวมที่ต้อง Check-in</span><span class="font-bold"><?= (int) $autoOpenRegistration['progress']['total'] ?> คน</span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Check-in ครบแล้ว</span><span class="font-bold text-emerald-700"><?= (int) $autoOpenRegistration['progress']['done'] ?> คน</span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Check-in ไม่ครบ</span><span class="font-bold text-amber-700"><?= max(0, (int) $autoOpenRegistration['progress']['total'] - (int) $autoOpenRegistration['progress']['done']) ?> คน</span></div>
                                    <div class="flex justify-between gap-3"><span class="text-slate-500">Roster Locked</span><span class="font-bold"><?= !empty($autoOpenRegistration['roster_locked_at']) ? date('d/m/Y H:i', strtotime($autoOpenRegistration['roster_locked_at'])) : '—' ?></span></div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 overflow-hidden">
                            <div class="bg-slate-50 px-4 py-3 border-b border-slate-200 text-sm font-bold text-slate-700">สมาชิกที่ลงแข่งขัน</div>
                            <?php if (empty($autoOpenRegistrationMembers)): ?>
                                <div class="p-6 text-center text-slate-500">ยังไม่มีสมาชิกใน Roster</div>
                            <?php else: ?>
                                <div class="divide-y divide-slate-200">
                                    <?php foreach ($autoOpenRegistrationMembers as $member): ?>
                                        <?php $memberStatus = in_array($member['checkin_status'] ?? 'not_checked_in', ['checked_in', 'waived'], true) ? 'checked_in' : 'not_checked_in'; ?>
                                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 px-4 py-3">
                                            <div>
                                                <div class="font-bold text-slate-800"><?= htmlspecialchars($member['display_name'] ?: $member['username'] ?: 'Player') ?></div>
                                                <div class="text-[11px] text-slate-500"><?= htmlspecialchars($member['member_roles'] ?: 'player') ?> • <?= $member['is_starter'] ? 'ตัวจริง' : 'สำรอง' ?></div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-bold <?= $memberStatus === 'checked_in' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' ?>">
                                                    <?= $memberStatus === 'checked_in' ? (strtolower((string) ($member['checkin_status'] ?? '')) === 'waived' ? 'อนุโลม' : 'Check-in') : 'ยังไม่ Check-in' ?>
                                                </span>
                                                <?php if ($member['is_required_for_checkin']): ?>
                                                    <span class="inline-flex items-center rounded-full bg-orange-100 text-orange-700 px-2.5 py-1 text-[10px] font-bold">ต้องเช็กอิน</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="border-t border-slate-200 bg-slate-50 px-6 py-4 flex justify-end gap-2">
                    <button type="button" id="registrationDetailCloseFooter" class="inline-flex items-center rounded-xl bg-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-300">ปิด</button>
                    <?php if ($registrationAction === 'detail'): ?>
                        <button type="button" id="registrationManageAction" data-registration-id="<?= (int) $autoOpenRegistrationId ?>" class="inline-flex items-center rounded-xl bg-brand-orange px-4 py-2 text-xs font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-list-check mr-1"></i>จัดการใบสมัคร</button>
                    <?php elseif ($registrationAction === 'view_checkin'): ?>
                        <a href="checkin-teams.php?tournament_id=<?= (int) $tournamentId ?>" class="inline-flex items-center rounded-xl bg-brand-orange px-4 py-2 text-xs font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-user-check mr-1"></i>เปิดหน้าจัดการ Check-in</a>
                    <?php elseif ($registrationAction === 'show_qr'): ?>
                        <?php if ($autoOpenRegistration && $autoOpenRegistration['status'] === 'approved' && !empty($autoOpenRegistration['qr_code_token'])): ?><button type="button" onclick="window.print()" class="inline-flex items-center rounded-xl bg-brand-orange px-4 py-2 text-xs font-bold text-white hover:bg-brand-glow"><i class="fa-solid fa-print mr-1"></i>พิมพ์ QR</button><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const adminSidebar = document.getElementById('adminSidebar');
            const adminSidebarBackdrop = document.getElementById('adminSidebarBackdrop');
            const adminMenuToggle = document.getElementById('adminMenuToggle');
            const actionToggles = document.querySelectorAll('.admin-action-toggle, .registration-action-toggle');
            const actionMenus = document.querySelectorAll('.admin-action-menu, .registration-action-menu');
            const registrationDetailModal = document.getElementById('registrationDetailModal');
            const registrationDetailClose = document.getElementById('registrationDetailClose');
            const registrationDetailCloseFooter = document.getElementById('registrationDetailCloseFooter');
            const registrationManageAction = document.getElementById('registrationManageAction');
            let registrationDetailDirty = false;

            function setAdminSidebarOpen(isOpen) {
                if (!adminSidebar || !adminSidebarBackdrop || !adminMenuToggle) return;
                adminSidebar.classList.toggle('-translate-x-full', !isOpen);
                adminSidebarBackdrop.classList.toggle('hidden', !isOpen);
                adminMenuToggle.setAttribute('aria-expanded', String(isOpen));
            }

            function closeRegistrationMenus() {
                actionMenus.forEach(menu => {
                    menu.classList.add('hidden');
                    const toggle = document.querySelector(`[data-menu-target="${menu.id}"], [data-action-menu="${menu.id}"]`);
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                });
            }

            function positionRegistrationMenu(toggle, menu) {
                const buttonRect = toggle.getBoundingClientRect();
                const menuWidth = menu.offsetWidth || 192;
                const menuHeight = menu.offsetHeight || 260;
                const gap = 8;
                const left = Math.max(8, Math.min(buttonRect.right - menuWidth, window.innerWidth - menuWidth - 8));
                const openBelow = buttonRect.bottom + gap + menuHeight <= window.innerHeight - 8;
                const top = openBelow ? buttonRect.bottom + gap : Math.max(8, buttonRect.top - menuHeight - gap);
                menu.style.left = `${left}px`;
                menu.style.top = `${top}px`;
            }

            function toggleRegistrationMenu(toggle) {
                const menuId = toggle.dataset.menuTarget || toggle.dataset.actionMenu;
                const menu = document.getElementById(menuId);
                if (!menu) return;
                const shouldOpen = menu.classList.contains('hidden');
                closeRegistrationMenus();
                if (!shouldOpen) return;
                document.body.appendChild(menu);
                menu.classList.remove('hidden');
                positionRegistrationMenu(toggle, menu);
                toggle.setAttribute('aria-expanded', 'true');
            }

            document.querySelectorAll('.admin-action-item[data-registration-action]').forEach(item => {
                item.addEventListener('click', () => closeRegistrationMenus());
            });

            function closeRegistrationDetailModal() {
                if (!registrationDetailModal) return;
                if (registrationDetailDirty && !window.confirm('มีข้อมูลที่แก้ไขแล้วยังไม่ได้บันทึก ต้องการปิดหน้าต่างหรือไม่?')) return;
                registrationDetailModal.classList.add('hidden');
                registrationDetailModal.classList.remove('flex');
                registrationDetailModal.style.pointerEvents = 'none';
                const url = new URL(window.location.href);
                url.searchParams.delete('registration_id');
                url.searchParams.set('tournament_id', registrationDetailModal.dataset.tournamentId || '<?= (int) $tournamentId ?>');
                url.searchParams.set('category_id', registrationDetailModal.dataset.categoryId || '<?= (int) $selectedCategoryId ?>');
                window.history.replaceState({}, '', url);
            }

            const addSoloPlayerModal = document.getElementById('addSoloPlayerModal');
            const openAddSoloPlayerModal = document.getElementById('openAddSoloPlayerModal');
            const closeAddSoloPlayerModalBtn = document.getElementById('closeAddSoloPlayerModal');

            function openAddPlayerModal() {
                if (!addSoloPlayerModal) return;
                if (addPlayerSearchInput) addPlayerSearchInput.value = '';
                addSoloPlayerModal.classList.remove('hidden');
                addSoloPlayerModal.classList.add('flex');
                addPlayerSearchInput?.focus();
                // Show results container and render initial teams
                addPlayerSearchResultsContainer?.classList.remove('hidden');
                addPlayerSearchLoadingState?.classList.add('hidden');
                addPlayerSearchErrorState?.classList.add('hidden');
                renderAddPlayerSearchResults(initialAddPlayerSearchResults);
            }

            function closeAddPlayerModal() {
                if (!addSoloPlayerModal) return;
                addSoloPlayerModal.classList.add('hidden');
                addSoloPlayerModal.classList.remove('flex');
                if (addPlayerSearchInput) addPlayerSearchInput.value = '';
                addPlayerSearchResultsList?.replaceChildren();
                addPlayerSearchResultsContainer?.classList.add('hidden');
                addPlayerSearchEmptyState?.classList.add('hidden');
                addPlayerSearchLoadingState?.classList.add('hidden');
                addPlayerSearchErrorState?.classList.add('hidden');
            }

            if (openAddSoloPlayerModal) {
                openAddSoloPlayerModal.addEventListener('click', openAddPlayerModal);
            }
            if (closeAddSoloPlayerModalBtn) {
                closeAddSoloPlayerModalBtn.addEventListener('click', closeAddPlayerModal);
            }
            if (addSoloPlayerModal) {
                addSoloPlayerModal.addEventListener('click', event => {
                    if (event.target === addSoloPlayerModal) closeAddPlayerModal();
                });
            }

            window.closeRegistrationDetailModal = closeRegistrationDetailModal;

            if (registrationDetailModal) {
                registrationDetailModal.querySelectorAll('input, textarea, select').forEach(field => {
                    field.addEventListener('change', () => { registrationDetailDirty = true; });
                });
                registrationDetailModal.addEventListener('click', event => {
                    if (event.target === registrationDetailModal) closeRegistrationDetailModal();
                });
            }
            if (registrationDetailClose) registrationDetailClose.addEventListener('click', closeRegistrationDetailModal);
            if (registrationDetailCloseFooter) registrationDetailCloseFooter.addEventListener('click', closeRegistrationDetailModal);
            if (registrationManageAction) {
                registrationManageAction.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    const registrationId = registrationManageAction.dataset.registrationId;
                    closeRegistrationDetailModal();
                    if (registrationDetailModal && !registrationDetailModal.classList.contains('hidden')) return;
                    const toggle = document.querySelector(`.registration-action-toggle[data-registration-id="${registrationId}"]`);
                    if (toggle) toggleRegistrationMenu(toggle);
                });
            }

            actionToggles.forEach(toggle => {
                toggle.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleRegistrationMenu(toggle);
                });
                toggle.addEventListener('keydown', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    event.stopPropagation();
                    toggleRegistrationMenu(toggle);
                });
            });

            actionMenus.forEach(menu => {
                menu.addEventListener('click', event => event.stopPropagation());
            });

            document.addEventListener('click', () => closeRegistrationMenus());
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') {
                    closeRegistrationMenus();
                    if (registrationDetailModal && !registrationDetailModal.classList.contains('hidden')) closeRegistrationDetailModal();
                }
            });
            window.addEventListener('resize', closeRegistrationMenus);
            window.addEventListener('scroll', closeRegistrationMenus, true);

            const addPlayerSearchForm = document.getElementById('addPlayerSearchForm');
            const addPlayerSearchInput = document.getElementById('addPlayerSearchInput');
            const addPlayerSearchResultsContainer = document.getElementById('addPlayerSearchResultsContainer');
            const addPlayerSearchResultsList = document.getElementById('addPlayerSearchResultsList');
            const addPlayerSearchEmptyState = document.getElementById('addPlayerSearchEmptyState');
            const addPlayerSearchLoadingState = document.getElementById('addPlayerSearchLoadingState');
            const addPlayerSearchErrorState = document.getElementById('addPlayerSearchErrorState');
            const initialAddPlayerSearchResults = <?= json_encode($addPlayerSearchResults, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            let addPlayerSearchRequestId = 0;
            let addPlayerSearchDebounce;

            function renderAddPlayerSearchResults(players) {
                if (!addPlayerSearchResultsList || !addPlayerSearchResultsContainer || !addPlayerSearchEmptyState) return;

                addPlayerSearchResultsList.replaceChildren();
                const isSolo = addSoloPlayerModal?.dataset.playMode === 'solo';
                players.forEach(player => {
                    const row = document.createElement('div');
                    row.className = 'player-search-result flex flex-col md:flex-row md:items-center md:justify-between gap-3 px-4 py-3';

                    const details = document.createElement('div');
                    details.className = 'flex items-center gap-3';
                    const avatar = document.createElement('div');
                    avatar.className = 'flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-orange-100 text-sm font-black text-brand-orange';
                    const imagePath = isSolo ? player.avatar_path : player.logo_path;
                    if (imagePath) {
                        const image = document.createElement('img');
                        image.src = `../${String(imagePath).replace(/^\/+/, '')}`;
                        image.alt = '';
                        image.className = 'h-full w-full object-cover';
                        avatar.append(image);
                    } else {
                        avatar.textContent = (isSolo ? (player.display_name || player.real_name) : player.name || 'T').trim().charAt(0).toUpperCase();
                    }
                    const name = document.createElement('div');
                    name.className = 'font-bold text-slate-800';
                    name.textContent = isSolo ? (player.display_name || player.real_name || 'Player') : (player.name || 'Team');
                    const account = document.createElement('div');
                    account.className = 'text-[11px] text-slate-500';
                    account.textContent = isSolo
                        ? `${player.username || '-'} • ${player.email || '-'} • ${player.eligibility_status || 'ไม่ระบุสถานะ'}`
                        : `กัปตัน: ${player.captain_username || '-'} • สมาชิก ${player.active_member_count || 0}/${player.starters_count || 0} คน • ${player.status || 'ไม่ระบุสถานะ'}`;
                    const text = document.createElement('div');
                    text.append(name, account);
                    details.append(avatar, text);
                    row.append(details);

                    const categoryNotSelected = !isSolo && <?= $selectedCategoryId > 0 ? 'false' : 'true' ?>;
                    const cannotAdd = categoryNotSelected || Number(player.already_registered_count || 0) > 0 || (!isSolo && Number(player.active_member_count || 0) < Number(player.starters_count || 0));
                    if (cannotAdd) {
                        const registered = document.createElement('span');
                        registered.className = 'inline-flex items-center rounded-lg bg-slate-200 px-3 py-2 text-xs font-bold text-slate-600';
                        registered.textContent = categoryNotSelected ? 'เลือก Category ก่อน' : (Number(player.already_registered_count || 0) > 0 ? 'สมัครแล้ว' : 'สมาชิกไม่ครบ');
                        row.append(registered);
                    } else {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = `manage-teams.php?tournament_id=<?= (int) $tournamentId ?>&category_id=<?= (int) $selectedCategoryId ?>`;
                        form.className = 'inline-flex';
                        const participantId = isSolo ? player.player_id : player.team_id;
                        const action = isSolo ? 'add_solo_player' : 'add_team';
                        const participantField = isSolo ? 'player_id' : 'team_id';
                        [["csrf_token", '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>'], ['action', action], ['tournament_id', '<?= (int) $tournamentId ?>'], ['tournament_category_id', '<?= (int) $selectedCategoryId ?>'], [participantField, participantId]].forEach(([nameValue, value]) => {
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = nameValue;
                            hidden.value = value;
                            form.append(hidden);
                        });
                        const button = document.createElement('button');
                        button.type = 'submit';
                        button.className = 'rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-700';
                        button.textContent = isSolo ? 'เพิ่มผู้แข่งขัน' : 'เพิ่มทีม';
                        button.addEventListener('click', event => {
                            if (!confirm(isSolo ? 'ยืนยันเพิ่มผู้เล่นรายนี้เข้าสู่ Tournament นี้หรือไม่?' : 'ยืนยันเพิ่มทีมนี้เข้าสู่ Tournament หรือไม่?')) event.preventDefault();
                        });
                        form.addEventListener('submit', () => {
                            button.disabled = true;
                            button.textContent = 'กำลังเพิ่ม...';
                        });
                        form.append(button);
                        row.append(form);
                    }
                    addPlayerSearchResultsList.append(row);
                });

                const hasResults = players.length > 0;
                addPlayerSearchLoadingState?.classList.add('hidden');
                addPlayerSearchErrorState?.classList.add('hidden');
                addPlayerSearchEmptyState.textContent = addSoloPlayerModal?.dataset.playMode === 'solo'
                    ? 'ไม่พบผู้เล่นที่ตรงกับคำค้นหา'
                    : 'ไม่พบทีมที่ตรงกับคำค้นหา';
                addPlayerSearchResultsContainer.classList.toggle('hidden', !hasResults);
                if (!hasResults) addPlayerSearchResultsContainer.classList.remove('hidden');
                addPlayerSearchEmptyState.classList.toggle('hidden', hasResults);
            }

            async function updateAddPlayerSearchResults() {
                if (!addPlayerSearchForm || !addPlayerSearchInput || !addPlayerSearchResultsList) return;

                const query = (addPlayerSearchInput.value || '').trim().toLowerCase();
                if (!query) {
                    renderAddPlayerSearchResults(initialAddPlayerSearchResults);
                    return;
                }
                const requestId = ++addPlayerSearchRequestId;
                const url = new URL(window.location.href);
                url.searchParams.set('ajax', 'search_add_solo_players');
                if (query) url.searchParams.set('add_player_search', query);
                else url.searchParams.delete('add_player_search');
                addPlayerSearchLoadingState?.classList.remove('hidden');
                addPlayerSearchErrorState?.classList.add('hidden');
                addPlayerSearchEmptyState?.classList.add('hidden');
                addPlayerSearchResultsContainer?.classList.add('hidden');
                addPlayerSearchResultsContainer?.classList.remove('hidden');
                try {
                    const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!response.ok || requestId !== addPlayerSearchRequestId) throw new Error('Search request failed');
                    renderAddPlayerSearchResults(await response.json());
                } catch (error) {
                    if (requestId === addPlayerSearchRequestId) {
                        addPlayerSearchLoadingState?.classList.add('hidden');
                        addPlayerSearchErrorState?.classList.remove('hidden');
                    }
                }
            }

            if (addPlayerSearchForm && addPlayerSearchInput) {
                addPlayerSearchForm.addEventListener('submit', (event) => {
                    event.preventDefault();
                    clearTimeout(addPlayerSearchDebounce);
                    updateAddPlayerSearchResults();
                });

                addPlayerSearchInput.addEventListener('input', () => {
                    clearTimeout(addPlayerSearchDebounce);
                    const query = addPlayerSearchInput.value.trim();
                    if (query.length > 0 && query.length < 2) {
                        updateAddPlayerSearchResults();
                        return;
                    }
                    addPlayerSearchDebounce = setTimeout(updateAddPlayerSearchResults, 400);
                });
                addPlayerSearchInput.addEventListener('focus', () => {
                    if (addPlayerSearchInput.value.trim().length >= 2) updateAddPlayerSearchResults();
                });
                document.addEventListener('click', event => {
                    if (!addPlayerSearchForm.contains(event.target) && !addPlayerSearchResultsContainer.contains(event.target)) {
                        addPlayerSearchResultsContainer.classList.add('hidden');
                    }
                });
            }

            if (adminMenuToggle) {
                adminMenuToggle.addEventListener('click', () => {
                    setAdminSidebarOpen(adminSidebar.classList.contains('-translate-x-full'));
                });
            }
            if (adminSidebarBackdrop) {
                adminSidebarBackdrop.addEventListener('click', () => setAdminSidebarOpen(false));
            }
            window.addEventListener('resize', () => {
                if (window.innerWidth >= 1024) setAdminSidebarOpen(false);
            });
        });
    </script>
</body>
</html>
