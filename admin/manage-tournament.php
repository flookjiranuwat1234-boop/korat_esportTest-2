<?php
// admin/manage-tournament.php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/bracket.php';
require_once '../includes/round_robin.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/registration_status.php';
requireRole('admin');
ensureTournamentCategorySchema($pdo);
ensureRegistrationStatusHistoryTable($pdo);

// ดึงข้อมูล User ปัจจุบันที่ Login อยู่
$currentUser = [
    'username' => $_SESSION['username'] ?? null,
    'role' => $_SESSION['role'] ?? null,
];

$error = '';
$success = '';
$tournamentStatusColumn = $pdo->query("SHOW COLUMNS FROM tournaments LIKE 'status'")->fetch();
$supportsArchivedStatus = is_array($tournamentStatusColumn) && strpos((string) ($tournamentStatusColumn['Type'] ?? ''), "'archived'") !== false;

function adminRegistrationStatusLabel(string $status): string
{
    return [
        'pending' => 'รออนุมัติ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ไม่อนุมัติ',
        'withdrawn' => 'ถอนทีม',
        'disqualified' => 'ตัดสิทธิ์',
    ][$status] ?? $status;
}

function displayGameName(string $name): string
{
    return trim((string) preg_replace('/\s*-\s*รุ่น.*$/u', '', $name));
}

function getTournamentRegistrationSummary(PDO $pdo, int $tournamentId, ?int $categoryId = null): array
{
    $params = ['tid' => $tournamentId];
    $where = 'WHERE tr.tournament_id = :tid';
    if ($categoryId) {
        $where .= ' AND tr.tournament_category_id = :category_id';
        $params['category_id'] = $categoryId;
    }

    $sql = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN tr.status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN tr.status = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM tournament_registration_members trm
            WHERE trm.tournament_registration_id = tr.tournament_registration_id
              AND trm.is_required_for_checkin = 1
              AND trm.checkin_status IN ('checked_in', 'waived')
        ) AND NOT EXISTS (
            SELECT 1 FROM tournament_registration_members trm2
            WHERE trm2.tournament_registration_id = tr.tournament_registration_id
              AND trm2.is_required_for_checkin = 1
              AND trm2.checkin_status NOT IN ('checked_in', 'waived')
        ) THEN 1 ELSE 0 END) AS checkin_complete,
        SUM(CASE WHEN EXISTS (
            SELECT 1 FROM tournament_registration_members trm3
            WHERE trm3.tournament_registration_id = tr.tournament_registration_id
              AND trm3.is_required_for_checkin = 1
              AND trm3.checkin_status NOT IN ('checked_in', 'waived')
        ) THEN 1 ELSE 0 END) AS checkin_incomplete,
        SUM(CASE WHEN tr.participation_status = 'qualified_for_draw' THEN 1 ELSE 0 END) AS qualified_for_draw,
        SUM(CASE WHEN tr.participation_status IN ('disqualified', 'walkover') THEN 1 ELSE 0 END) AS disqualified_or_wo
    FROM tournament_registrations tr
    $where";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'pending' => (int) ($row['pending'] ?? 0),
        'approved' => (int) ($row['approved'] ?? 0),
        'checkin_complete' => (int) ($row['checkin_complete'] ?? 0),
        'checkin_incomplete' => (int) ($row['checkin_incomplete'] ?? 0),
        'qualified_for_draw' => (int) ($row['qualified_for_draw'] ?? 0),
        'disqualified_or_wo' => (int) ($row['disqualified_or_wo'] ?? 0),
    ];
}

function getTournamentRegistrationRowsForOverview(PDO $pdo, int $tournamentId, ?int $categoryId = null): array
{
    $params = ['tid' => $tournamentId];
    $sql = "SELECT
        tr.tournament_registration_id,
        tr.tournament_category_id,
        tr.status,
        tr.participation_status,
        tr.seed_no,
        tr.registered_at,
        tc.category_code,
        tc.label AS category_label,
        COALESCE(team.name, p.display_name, u.username, 'ผู้สมัครเดี่ยว') AS display_name,
        COALESCE(captain_u.username, '-') AS captain_name,
        COUNT(trm.id) AS roster_count,
        SUM(CASE WHEN trm.is_required_for_checkin = 1 THEN 1 ELSE 0 END) AS required_count,
        SUM(CASE WHEN trm.is_required_for_checkin = 1 AND trm.checkin_status IN ('checked_in', 'waived') THEN 1 ELSE 0 END) AS checked_count
    FROM tournament_registrations tr
    LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
    LEFT JOIN teams team ON team.team_id = tr.team_id
    LEFT JOIN players p ON p.player_id = tr.player_id
    LEFT JOIN users u ON u.user_id = p.user_id
    LEFT JOIN players captain_p ON captain_p.player_id = team.captain_player_id
    LEFT JOIN users captain_u ON captain_u.user_id = captain_p.user_id
    LEFT JOIN tournament_registration_members trm ON trm.tournament_registration_id = tr.tournament_registration_id
    WHERE tr.tournament_id = :tid";

    if ($categoryId) {
        $sql .= ' AND tr.tournament_category_id = :category_id';
        $params['category_id'] = $categoryId;
    }

    $sql .= ' GROUP BY tr.tournament_registration_id, tr.tournament_category_id, tr.status, tr.participation_status, tr.seed_no, tr.registered_at, tc.category_code, tc.label, team.name, p.display_name, u.username, captain_u.username ORDER BY tr.registered_at DESC, tr.tournament_registration_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveTournamentFormCategories(PDO $pdo, int $tournamentId, array $input): void
{
    $codes = array_values(array_unique(array_filter(array_map('strtolower', $input['category_codes'] ?? []))));
    foreach ($codes as $code) {
        if (!in_array($code, ['male', 'female', 'open'], true)) continue;
        $categoryId = getTournamentCategoryId($pdo, $tournamentId, $code);
        $stmt = $pdo->prepare('UPDATE tournament_categories SET label = :label, max_participants = :max_participants,
            format = :format, group_size = :group_size, teams_advance_per_group = :advance,
            starters_count = :starters, substitutes_count = :substitutes,
            checkin_required_roles = :required_roles, seed_method = :seed_method, is_active = 1
            WHERE tournament_category_id = :category_id AND tournament_id = :tournament_id');
        $stmt->execute([
            'label' => ['male' => 'ชาย', 'female' => 'หญิง', 'open' => 'Open'][$code],
            'max_participants' => max(1, (int) ($input['category_max_participants'][$code] ?? $input['max_teams'] ?? 1)),
            'format' => $input['category_format'][$code] ?? $input['format'] ?? 'single_elimination',
            'group_size' => (($input['category_group_size'][$code] ?? '') !== '') ? max(2, (int) $input['category_group_size'][$code]) : null,
            'advance' => (($input['category_advance'][$code] ?? '') !== '') ? max(1, (int) $input['category_advance'][$code]) : null,
            'starters' => (($input['category_starters'][$code] ?? '') !== '') ? max(0, (int) $input['category_starters'][$code]) : null,
            'substitutes' => (($input['category_substitutes'][$code] ?? '') !== '') ? max(0, (int) $input['category_substitutes'][$code]) : null,
            'required_roles' => is_array($input['category_required_roles'][$code] ?? null) ? implode(',', $input['category_required_roles'][$code]) : (trim($input['category_required_roles'][$code] ?? '') ?: null),
            'seed_method' => $input['category_seed_method'][$code] ?? ($input['seed_method'] ?? 'ranking'),
            'category_id' => $categoryId,
            'tournament_id' => $tournamentId,
        ]);
    }
}

function selectedCategoryFormData(array $input): array
{
    $codes = array_values(array_unique(array_filter(array_map('strtolower', $input['category_codes'] ?? []))));
    $maxTeams = 0;
    $formats = [];
    foreach ($codes as $code) {
        $maxTeams = max($maxTeams, (int) ($input['category_max_participants'][$code] ?? 0));
        if (!empty($input['category_format'][$code])) $formats[] = $input['category_format'][$code];
    }
    return ['codes' => $codes, 'max_teams' => $maxTeams, 'format' => $formats[0] ?? 'single_elimination'];
}

function validateCategoryForm(array $input): ?string
{
    $codes = array_values(array_unique(array_filter(array_map('strtolower', $input['category_codes'] ?? []))));
    if (!$codes) return 'กรุณาเลือก Category อย่างน้อย 1 ประเภท';
    foreach ($codes as $code) {
        $max = (int) ($input['category_max_participants'][$code] ?? 0);
        $starters = (int) ($input['category_starters'][$code] ?? 0);
        $substitutes = (int) ($input['category_substitutes'][$code] ?? -1);
        $roles = $input['category_required_roles'][$code] ?? [];
        $roles = is_array($roles) ? $roles : array_filter(array_map('trim', explode(',', $roles)));
        $format = $input['category_format'][$code] ?? 'single_elimination';
        $groupSize = (int) ($input['category_group_size'][$code] ?? 0);
        $advance = (int) ($input['category_advance'][$code] ?? 0);
        if ($max <= 0) return 'จำนวนทีม/ผู้แข่งขันสูงสุดของ Category ' . $code . ' ต้องมากกว่า 0';
        if ($starters <= 0) return 'ผู้เล่นตัวจริงของ Category ' . $code . ' ต้องมากกว่า 0';
        if ($substitutes < 0) return 'ตัวสำรองของ Category ' . $code . ' ต้องไม่ติดลบ';
        if (!in_array('player', $roles, true)) return 'Category ' . $code . ' ต้องมีบทบาท Player';
        if (in_array($format, ['round_robin', 'group_playoff'], true)) {
            if ($groupSize < 2 || $advance < 1) return 'กรุณากรอกจำนวนทีมต่อกลุ่มและทีมที่ผ่านของ Category ' . $code;
            if ($advance >= $groupSize) return 'ทีมที่ผ่านต่อกลุ่มต้องน้อยกว่าทีมต่อกลุ่มของ Category ' . $code;
            if ($groupSize > $max) return 'จำนวนทีมต่อกลุ่มต้องไม่เกินจำนวนสูงสุดของ Category ' . $code;
        }
    }
    return null;
}

function saveTournamentDays(PDO $pdo, int $tournamentId, string $json): void
{
    if (trim($json) === '') {
        $pdo->prepare('DELETE FROM tournament_days WHERE tournament_id = :tournament_id')->execute(['tournament_id' => $tournamentId]);
        return;
    }
    $days = json_decode($json, true);
    if (!is_array($days)) return;
    $pdo->prepare('DELETE FROM tournament_days WHERE tournament_id = :tournament_id')->execute(['tournament_id' => $tournamentId]);
    $stmt = $pdo->prepare('INSERT INTO tournament_days (tournament_id, day_number, event_date, start_time, end_time, venue_name, notes)
        VALUES (:tournament_id, :day_number, :event_date, :start_time, :end_time, :venue_name, :notes)');
    foreach ($days as $index => $day) {
        if (empty($day['event_date'])) continue;
        $stmt->execute([
            'tournament_id' => $tournamentId,
            'day_number' => $index + 1,
            'event_date' => $day['event_date'],
            'start_time' => $day['start_time'] ?? null,
            'end_time' => $day['end_time'] ?? null,
            'venue_name' => trim($day['venue_name'] ?? '') ?: null,
            'notes' => trim($day['notes'] ?? '') ?: null,
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'registration_action') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $newStatus = $_POST['registration_status'] ?? '';
        $note = trim($_POST['status_note'] ?? '');
        $allowedStatuses = ['approved', 'rejected', 'withdrawn', 'disqualified'];
        if (!$registrationId || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'ข้อมูลการเปลี่ยนสถานะไม่ถูกต้อง';
        } else {
            $registrationStmt = $pdo->prepare('SELECT tournament_id, status FROM tournament_registrations
                WHERE tournament_registration_id = :registration_id');
            $registrationStmt->execute(['registration_id' => $registrationId]);
            $registration = $registrationStmt->fetch();
            if (!$registration) {
                $error = 'ไม่พบรายการสมัคร Tournament';
            } else {
                $pdo->prepare('UPDATE tournament_registrations SET status = :status,
                    participation_status = CASE WHEN :status2 = \'approved\' THEN \'registered\' ELSE :status3 END
                    WHERE tournament_registration_id = :registration_id')
                    ->execute([
                        'status' => $newStatus,
                        'status2' => $newStatus,
                        'status3' => $newStatus === 'disqualified' ? 'disqualified' : 'registered',
                        'registration_id' => $registrationId,
                    ]);
                recordRegistrationStatus($pdo, $registrationId, $newStatus, (int) ($_SESSION['user_id'] ?? 0), $note ?: null);
                $success = 'อัปเดตสถานะผู้สมัครเรียบร้อยแล้ว';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_seed') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $seedNo = trim($_POST['seed_no'] ?? '');
        $seedValue = $seedNo === '' ? null : max(1, (int) $seedNo);
        $seedStmt = $pdo->prepare('UPDATE tournament_registrations SET seed_no = :seed_no
            WHERE tournament_registration_id = :registration_id');
        $seedStmt->execute(['seed_no' => $seedValue, 'registration_id' => $registrationId]);
        $success = 'บันทึก Seed เรียบร้อยแล้ว';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_roster') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $note = trim($_POST['unlock_reason'] ?? '');
        if ($registrationId && $note !== '') {
            $pdo->prepare('UPDATE tournament_registrations SET roster_locked_at = NULL WHERE tournament_registration_id = :registration_id')->execute(['registration_id' => $registrationId]);
            recordRegistrationStatus($pdo, $registrationId, 'approved', (int) ($_SESSION['user_id'] ?? 0), 'ปลดล็อก Roster: ' . $note);
            $success = 'ปลดล็อก Tournament Roster แล้ว';
        } else {
            $error = 'กรุณาระบุ Registration และเหตุผลการปลดล็อก';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_category') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $categoryId = (int) ($_POST['tournament_category_id'] ?? 0);
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $categoryStmt = $pdo->prepare('UPDATE tournament_categories SET label = :label,
                max_participants = :max_participants, format = :format, group_size = :group_size,
                teams_advance_per_group = :teams_advance, starters_count = :starters,
                substitutes_count = :substitutes, checkin_required_roles = :required_roles,
                seed_method = :seed_method
            WHERE tournament_category_id = :category_id AND tournament_id = :tournament_id');
        $categoryStmt->execute([
            'label' => trim($_POST['label'] ?? $_POST['category_code'] ?? 'Open'),
            'max_participants' => ($_POST['max_participants'] ?? '') !== '' ? max(1, (int) $_POST['max_participants']) : null,
            'format' => $_POST['format'] ?? 'single_elimination',
            'group_size' => ($_POST['group_size'] ?? '') !== '' ? max(2, (int) $_POST['group_size']) : null,
            'teams_advance' => ($_POST['teams_advance_per_group'] ?? '') !== '' ? max(1, (int) $_POST['teams_advance_per_group']) : null,
            'starters' => ($_POST['starters_count'] ?? '') !== '' ? max(0, (int) $_POST['starters_count']) : null,
            'substitutes' => ($_POST['substitutes_count'] ?? '') !== '' ? max(0, (int) $_POST['substitutes_count']) : null,
            'required_roles' => trim($_POST['checkin_required_roles'] ?? '') ?: null,
            'seed_method' => $_POST['seed_method'] ?? 'ranking',
            'category_id' => $categoryId,
            'tournament_id' => $tournamentId,
        ]);
        $requiredRoles = array_values(array_filter(array_map('trim', explode(',', strtolower($_POST['checkin_required_roles'] ?? '')))));
        if ($requiredRoles) {
            $registrationStmt = $pdo->prepare('SELECT tournament_registration_id FROM tournament_registrations
                WHERE tournament_category_id = :category_id AND roster_locked_at IS NULL');
            $registrationStmt->execute(['category_id' => $categoryId]);
            $memberUpdate = $pdo->prepare('UPDATE tournament_registration_members SET is_required_for_checkin = :required
                WHERE id = :member_id');
            foreach ($registrationStmt->fetchAll(PDO::FETCH_COLUMN) as $registrationId) {
                $membersStmt = $pdo->prepare('SELECT id, member_roles FROM tournament_registration_members
                    WHERE tournament_registration_id = :registration_id');
                $membersStmt->execute(['registration_id' => $registrationId]);
                foreach ($membersStmt->fetchAll() as $member) {
                    $memberRoles = array_values(array_filter(array_map('trim', explode(',', strtolower($member['member_roles'] ?? '')))));
                    $memberUpdate->execute(['required' => array_intersect($requiredRoles, $memberRoles) ? 1 : 0, 'member_id' => $member['id']]);
                }
            }
        }
        $success = $categoryStmt->rowCount() ? 'บันทึกกติกา Category แล้ว' : 'ไม่พบ Category ที่ต้องการแก้ไข';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['cancel_tournament', 'archive_tournament'], true)) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $isArchiveAction = ($_POST['action'] ?? '') === 'archive_tournament';
        if ($isArchiveAction && !$supportsArchivedStatus) {
            $error = 'ฐานข้อมูลปัจจุบันยังไม่รองรับสถานะ archived จึงยังเก็บเข้าคลังไม่ได้';
        }
        $newStatus = $isArchiveAction ? 'archived' : 'cancelled';
        $statusStmt = $pdo->prepare('UPDATE tournaments SET status = :status WHERE tournament_id = :tournament_id
            AND status NOT IN (\'completed\', \'cancelled\', \'archived\')');
        if (!$error) {
            $statusStmt->execute(['status' => $newStatus, 'tournament_id' => $tournamentId]);
            $success = $statusStmt->rowCount() ? ($newStatus === 'cancelled' ? 'ยกเลิก Tournament และเก็บประวัติแล้ว' : 'เก็บเข้าคลังและเก็บประวัติแล้ว') : 'ไม่สามารถเปลี่ยนสถานะ Tournament นี้ได้';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_tournament') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $relatedStmt = $pdo->prepare('SELECT
                (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = :id) AS registrations,
                (SELECT COUNT(*) FROM matches WHERE tournament_id = :id2) AS matches,
                (SELECT COUNT(*) FROM tournament_days WHERE tournament_id = :id3) AS days');
        $relatedStmt->execute(['id' => $tournamentId, 'id2' => $tournamentId, 'id3' => $tournamentId]);
        $related = $relatedStmt->fetch();
        if ((int) $related['registrations'] > 0 || (int) $related['matches'] > 0 || (int) $related['days'] > 0) {
            $error = 'ลบถาวรไม่ได้ เพราะ Tournament นี้มีข้อมูลหรือประวัติแล้ว กรุณาใช้ยกเลิกแทน';
        } else {
            $deleteStmt = $pdo->prepare('DELETE FROM tournaments WHERE tournament_id = :tournament_id
                AND status NOT IN (\'completed\', \'cancelled\')');
            $deleteStmt->execute(['tournament_id' => $tournamentId]);
            $success = $deleteStmt->rowCount() ? 'ลบ Tournament ที่ยังไม่มีข้อมูลเรียบร้อยแล้ว' : 'ไม่สามารถลบ Tournament นี้ได้';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_tournament') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $tournamentId = (int) ($_POST['tournament_id'] ?? 0);
        $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id
            AND status NOT IN ('completed', 'walkover', 'cancelled')");
        $pendingStmt->execute(['tournament_id' => $tournamentId]);
        $winnerStmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = :tournament_id
            AND status IN ('completed', 'walkover') AND winner_team_id IS NULL AND result_type <> 'bye'");
        $winnerStmt->execute(['tournament_id' => $tournamentId]);
        if ((int) $pendingStmt->fetchColumn() > 0) {
            $error = 'ยังมี Match ค้าง จึงยังจบ Tournament ไม่ได้';
        } elseif ((int) $winnerStmt->fetchColumn() > 0) {
            $error = 'มี Match ที่ยังไม่มีผู้ชนะ จึงยังจบ Tournament ไม่ได้';
        } else {
            $pdo->prepare("UPDATE tournaments SET status = 'completed' WHERE tournament_id = :tournament_id
                AND status IN ('bracket_generated', 'ongoing')")->execute(['tournament_id' => $tournamentId]);
            $success = 'จบการแข่งขันและเก็บผลการแข่งขันเรียบร้อยแล้ว';
        }
    }
}

if (isset($_GET['ajax_get_categories'])) {
    $tournamentId = (int) $_GET['ajax_get_categories'];
    ensureDefaultTournamentCategories($pdo, $tournamentId);
    $categoryStmt = $pdo->prepare('SELECT * FROM tournament_categories WHERE tournament_id = :tournament_id AND is_active = 1 ORDER BY tournament_category_id');
    $categoryStmt->execute(['tournament_id' => $tournamentId]);
    $categories = $categoryStmt->fetchAll();
    $csrf = htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8');
    foreach ($categories as $category) {
        echo '<form method="POST" class="border border-slate-200 rounded-xl p-4 space-y-3"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="save_category"><input type="hidden" name="tournament_id" value="' . $tournamentId . '"><input type="hidden" name="tournament_category_id" value="' . (int) $category['tournament_category_id'] . '"><div class="flex items-center justify-between"><b>' . htmlspecialchars($category['category_code']) . '</b><input name="label" value="' . htmlspecialchars($category['label']) . '" class="rounded border border-slate-200 px-2 py-1 text-xs"></div><div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs"><label>รับสมัครสูงสุด<input type="number" name="max_participants" value="' . htmlspecialchars($category['max_participants'] ?? '') . '" class="w-full rounded border p-1"></label><label>ทีมต่อ Group<input type="number" name="group_size" value="' . htmlspecialchars($category['group_size'] ?? '') . '" class="w-full rounded border p-1"></label><label>ผ่านต่อ Group<input type="number" name="teams_advance_per_group" value="' . htmlspecialchars($category['teams_advance_per_group'] ?? '') . '" class="w-full rounded border p-1"></label><label>ตัวจริง<input type="number" name="starters_count" value="' . htmlspecialchars($category['starters_count'] ?? '') . '" class="w-full rounded border p-1"></label><label>สำรอง<input type="number" name="substitutes_count" value="' . htmlspecialchars($category['substitutes_count'] ?? '') . '" class="w-full rounded border p-1"></label><label>Role ที่ต้อง Check-in<input name="checkin_required_roles" value="' . htmlspecialchars($category['checkin_required_roles'] ?? '') . '" placeholder="player,coach" class="w-full rounded border p-1"></label><label>รูปแบบ<select name="format" class="w-full rounded border p-1"><option value="single_elimination" ' . ($category['format'] === 'single_elimination' ? 'selected' : '') . '>Single</option><option value="round_robin" ' . ($category['format'] === 'round_robin' ? 'selected' : '') . '>Round Robin</option><option value="group_playoff" ' . ($category['format'] === 'group_playoff' ? 'selected' : '') . '>Group Playoff</option></select></label><label>Seed<select name="seed_method" class="w-full rounded border p-1"><option value="ranking" ' . ($category['seed_method'] === 'ranking' ? 'selected' : '') . '>Ranking</option><option value="admin" ' . ($category['seed_method'] === 'admin' ? 'selected' : '') . '>Admin Seed</option><option value="random" ' . ($category['seed_method'] === 'random' ? 'selected' : '') . '>สุ่ม</option></select></label></div><button class="rounded-lg bg-brand-orange px-3 py-2 text-xs font-bold text-white">บันทึก Category</button></form>';
    }
    exit;
}

if (isset($_GET['ajax_get_tournament_form_data'])) {
    $tournamentId = (int) $_GET['ajax_get_tournament_form_data'];
    $categoryStmt = $pdo->prepare('SELECT category_code, max_participants, format, group_size,
            teams_advance_per_group, starters_count, substitutes_count, checkin_required_roles, seed_method
        FROM tournament_categories WHERE tournament_id = :tournament_id AND is_active = 1');
    $categoryStmt->execute(['tournament_id' => $tournamentId]);
    $dayStmt = $pdo->prepare('SELECT event_date, start_time, end_time, venue_name, notes
        FROM tournament_days WHERE tournament_id = :tournament_id ORDER BY day_number');
    $dayStmt->execute(['tournament_id' => $tournamentId]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['categories' => $categoryStmt->fetchAll(), 'days' => $dayStmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['ajax_get_bracket'])) {
    $tournamentId = (int) $_GET['ajax_get_bracket'];
    $categoryId = (int) ($_GET['category_id'] ?? 0);
    $sql = 'SELECT m.match_id, m.round_number, m.match_index, m.bracket_type, m.status, m.result_type,
            m.wo_reason, m.team1_id, m.team2_id, m.team1_score, m.team2_score, m.scheduled_at,
            m.venue_name, m.venue_area, COALESCE(t1.name, u1.username, \'รอผู้ชนะรอบก่อน\') AS team1_name,
            COALESCE(t2.name, u2.username, \'รอผู้ชนะรอบก่อน\') AS team2_name,
            tc.label AS category_label, tg.name AS group_name
        FROM matches m
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN players p1 ON p1.player_id = m.team1_id
        LEFT JOIN users u1 ON u1.user_id = p1.user_id
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        LEFT JOIN players p2 ON p2.player_id = m.team2_id
        LEFT JOIN users u2 ON u2.user_id = p2.user_id
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = m.tournament_category_id
        LEFT JOIN tournament_groups tg ON tg.tournament_group_id = m.group_id
        WHERE m.tournament_id = :tournament_id';
    $params = ['tournament_id' => $tournamentId];
    if ($categoryId > 0) { $sql .= ' AND m.tournament_category_id = :category_id'; $params['category_id'] = $categoryId; }
    $sql .= ' ORDER BY m.tournament_category_id, m.group_id, m.round_number, m.match_index';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll();
    if (!$matches) { echo '<div class="p-8 text-center text-slate-400 text-sm">ยังไม่มี Group หรือ Bracket</div>'; exit; }
    $currentCategory = null;
    foreach ($matches as $match) {
        if ($currentCategory !== $match['category_label']) {
            if ($currentCategory !== null) echo '</div>';
            $currentCategory = $match['category_label'] ?: 'ไม่ระบุ Category';
            echo '<h4 class="border-b border-slate-200 pb-2 pt-3 font-bold text-slate-900">' . htmlspecialchars($currentCategory) . '</h4><div class="grid grid-cols-1 md:grid-cols-2 gap-3">';
        }
        $resultLabel = ($match['result_type'] === 'bye') ? 'Bye' : (($match['status'] === 'walkover') ? 'WO' : (($match['status'] === 'completed') ? 'จบแล้ว' : 'รอแข่ง'));
        $resultClass = $match['result_type'] === 'bye' ? 'bg-amber-50 text-amber-700' : (($match['status'] === 'walkover') ? 'bg-rose-50 text-rose-700' : (($match['status'] === 'completed') ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'));
        $stage = $match['group_name'] ? 'group' : 'knockout';
        echo '<article data-bracket-category="' . htmlspecialchars($currentCategory, ENT_QUOTES, 'UTF-8') . '" data-bracket-stage="' . $stage . '" class="bracket-card rounded-xl border border-slate-200 bg-white p-3 space-y-2"><div class="flex items-center justify-between text-[11px] text-slate-500"><b>' . htmlspecialchars($match['group_name'] ?: strtoupper($match['bracket_type'] ?: 'KNOCKOUT')) . ' · รอบ ' . (int) $match['round_number'] . ' · Match #' . ((int) $match['match_index'] + 1) . '</b><span class="rounded-full px-2 py-1 font-bold ' . $resultClass . '">' . $resultLabel . '</span></div><div class="grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-sm font-bold"><span>' . htmlspecialchars($match['team1_name']) . '</span><span class="font-mono text-brand-orange">' . ($match['team1_score'] !== null ? (int) $match['team1_score'] : 'VS') . '</span><span class="text-right">' . htmlspecialchars($match['team2_name']) . ' <b class="font-mono text-brand-orange">' . ($match['team2_score'] !== null ? (int) $match['team2_score'] : '') . '</b></span></div>';
        if ($match['scheduled_at'] || $match['venue_name'] || $match['wo_reason']) echo '<p class="text-[11px] text-slate-500">' . ($match['scheduled_at'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($match['scheduled_at']))) : 'ยังไม่กำหนดเวลา') . ($match['venue_name'] ? ' · ' . htmlspecialchars($match['venue_name']) : '') . ($match['venue_area'] ? ' / ' . htmlspecialchars($match['venue_area']) : '') . ($match['wo_reason'] ? '<br><span class="text-rose-600">เหตุผล: ' . htmlspecialchars($match['wo_reason']) . '</span>' : '') . '</p>';
        echo '</article>';
    }
    if ($currentCategory !== null) echo '</div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'waive_member') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $playerId = (int) ($_POST['player_id'] ?? 0);
        $reason = trim($_POST['waive_reason'] ?? '');
        if (!$registrationId || !$playerId || $reason === '') {
            $error = 'กรุณาระบุสมาชิกและเหตุผลการอนุโลม';
        } else {
            waiveRosterMemberCheckin($pdo, $registrationId, $playerId, $reason, (int) ($_SESSION['user_id'] ?? 0));
            $success = 'อนุโลม Check-in ให้สมาชิกเรียบร้อยแล้ว';
        }
    }
}

if (isset($_GET['ajax_get_registrations'])) {
    $tournamentId = (int) $_GET['ajax_get_registrations'];
    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
    $summary = getTournamentRegistrationSummary($pdo, $tournamentId, $categoryId);
    $registrations = getTournamentRegistrationRowsForOverview($pdo, $tournamentId, $categoryId);

    if (!$registrations) {
        echo '<div class="p-8 text-center text-slate-400 text-sm">ยังไม่มีผู้สมัครใน Tournament นี้</div>';
        exit;
    }

    echo '<div class="space-y-4">';
    echo '<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-7 gap-3">';
    $summaryCards = [
        ['label' => 'ทั้งหมด', 'value' => $summary['total'], 'class' => 'bg-slate-100 text-slate-700'],
        ['label' => 'รอตรวจสอบ', 'value' => $summary['pending'], 'class' => 'bg-yellow-100 text-yellow-700'],
        ['label' => 'อนุมัติแล้ว', 'value' => $summary['approved'], 'class' => 'bg-emerald-100 text-emerald-700'],
        ['label' => 'Check-in ครบ', 'value' => $summary['checkin_complete'], 'class' => 'bg-emerald-100 text-emerald-700'],
        ['label' => 'Check-in ไม่ครบ', 'value' => $summary['checkin_incomplete'], 'class' => 'bg-amber-100 text-amber-700'],
        ['label' => 'พร้อมจัดสาย', 'value' => $summary['qualified_for_draw'], 'class' => 'bg-sky-100 text-sky-700'],
        ['label' => 'ตัดสิทธิ์/WO', 'value' => $summary['disqualified_or_wo'], 'class' => 'bg-rose-100 text-rose-700'],
    ];
    foreach ($summaryCards as $card) {
        echo '<div class="rounded-xl border border-slate-200 p-3 ' . $card['class'] . '"><div class="text-[10px] font-bold uppercase tracking-[0.18em]">' . htmlspecialchars($card['label']) . '</div><div class="mt-2 text-2xl font-black">' . (int) $card['value'] . '</div></div>';
    }
    echo '</div>';

    echo '<div class="flex justify-between items-center gap-3 pt-2 border-t border-slate-100">';
    echo '<div class="text-sm font-bold text-slate-700">รายการทีม/ผู้แข่งขัน</div>';
    echo '<a href="manage-teams.php?tournament_id=' . $tournamentId . ($categoryId ? '&category_id=' . $categoryId : '') . '" class="inline-flex items-center gap-2 rounded-xl bg-brand-orange px-4 py-2 text-xs font-bold text-white hover:bg-brand-glow">จัดการผู้สมัครทั้งหมด</a>';
    echo '</div>';

    foreach ($registrations as $registration) {
        $progress = getRegistrationCheckinProgress($pdo, (int) $registration['tournament_registration_id']);
        $requiredCount = (int) ($registration['required_count'] ?: $progress['required']);
        $checkedCount = (int) ($registration['checked_count'] ?: $progress['checked_in']);
        $categoryLabel = !empty($registration['category_label']) ? $registration['category_label'] : (!empty($registration['category_code']) ? $registration['category_code'] : 'Open');
        $statusBadge = adminRegistrationStatusLabel($registration['status'] ?? 'pending');
        $participationBadge = $registration['participation_status'] ?? 'registered';
        echo '<article class="border border-slate-200 rounded-xl p-4 space-y-3 bg-white">';
        echo '<div class="flex flex-wrap items-center justify-between gap-2"><div><h4 class="font-bold text-slate-900">' . htmlspecialchars($registration['display_name'] ?? '-') . '</h4><p class="text-xs text-slate-500">Category: ' . htmlspecialchars($categoryLabel) . ' | Captain: ' . htmlspecialchars($registration['captain_name'] ?? '-') . '</p></div>';
        echo '<span class="px-2 py-1 rounded-full bg-slate-100 text-xs font-bold text-slate-700">' . htmlspecialchars($statusBadge) . '</span></div>';
        echo '<div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px] text-slate-600">';
        echo '<div class="rounded-lg bg-slate-50 p-2"><span class="block text-[10px] uppercase tracking-[0.15em] text-slate-400">Roster</span><b>' . (int) ($registration['roster_count'] ?? 0) . ' คน</b></div>';
        echo '<div class="rounded-lg bg-slate-50 p-2"><span class="block text-[10px] uppercase tracking-[0.15em] text-slate-400">Check-in</span><b>' . $checkedCount . '/' . $requiredCount . '</b></div>';
        echo '<div class="rounded-lg bg-slate-50 p-2"><span class="block text-[10px] uppercase tracking-[0.15em] text-slate-400">พร้อมจัดสาย</span><b>' . htmlspecialchars($participationBadge) . '</b></div>';
        echo '<div class="rounded-lg bg-slate-50 p-2"><span class="block text-[10px] uppercase tracking-[0.15em] text-slate-400">Group/Seed</span><b>' . ($registration['seed_no'] ? '#' . (int) $registration['seed_no'] : '-') . '</b></div>';
        echo '</div>';
        echo '<div class="flex justify-end gap-2 pt-1 border-t border-slate-100"><a href="manage-teams.php?tournament_id=' . $tournamentId . ($categoryId ? '&category_id=' . $categoryId : '') . '&registration_id=' . (int) $registration['tournament_registration_id'] . '" title="เปิดรายละเอียดใบสมัครในหน้าจัดการผู้สมัคร" class="inline-flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-200"><i class="fa-solid fa-arrow-up-right-from-square"></i>เปิดใบสมัคร</a></div>';
        echo '</article>';
    }
    echo '</div>';
    exit;
}

// ==========================================
// AJAX: ดึงตารางสรุปคะแนนสำหรับ Modal
// ==========================================
if (isset($_GET['ajax_get_results'])) {
    $tid = (int)$_GET['ajax_get_results'];
    $filterCategory = $_GET['category'] ?? 'all';

    // ปรับให้ดึง category จาก tournament_registrations แทนตาราง teams
    $sql = "
        SELECT t.team_id, t.name, tr.category AS team_category 
        FROM tournament_registrations tr
        JOIN teams t ON t.team_id = tr.team_id
        WHERE tr.tournament_id = :tid AND (tr.status = 'approved' OR tr.status = 'checked_in')
    ";
    $params = ['tid' => $tid];

    if ($filterCategory !== 'all') {
        $sql .= " AND tr.category = :cat";
        $params['cat'] = $filterCategory;
    }

    $teamsStmt = $pdo->prepare($sql);
    $teamsStmt->execute($params);
    $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($teams)) {
        echo '<div class="p-8 text-center text-slate-400 text-xs">ยังไม่มีทีมที่อนุมัติเข้าร่วมในประเภทนี้</div>';
        exit;
    }

    $matchesStmt = $pdo->prepare("
        SELECT team1_id, team2_id, team1_score, team2_score, status 
        FROM matches 
        WHERE tournament_id = :tid AND status IN ('completed', 'walkover')
    ");
    $matchesStmt->execute(['tid' => $tid]);
    $matches = $matchesStmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [];
    foreach ($teams as $team) {
        $stats[$team['team_id']] = [
            'name' => $team['name'],
            'category' => $team['team_category'],
            'wins' => 0,
            'losses' => 0,
            'points' => 0
        ];
    }

    foreach ($matches as $m) {
        $t1 = $m['team1_id'];
        $t2 = $m['team2_id'];
        $s1 = (int)$m['team1_score'];
        $s2 = (int)$m['team2_score'];

        if (!isset($stats[$t1]) || !isset($stats[$t2])) continue;

        if ($m['status'] == 'walkover') {
            if ($s1 > $s2) {
                $stats[$t1]['wins']++; $stats[$t1]['points'] += 3;
                $stats[$t2]['losses']++;
            } else {
                $stats[$t2]['wins']++; $stats[$t2]['points'] += 3;
                $stats[$t1]['losses']++;
            }
        } else {
            if ($s1 > $s2) {
                $stats[$t1]['wins']++; $stats[$t1]['points'] += 3;
                $stats[$t2]['losses']++;
            } elseif ($s2 > $s1) {
                $stats[$t2]['wins']++; $stats[$t2]['points'] += 3;
                $stats[$t1]['losses']++;
            } else {
                $stats[$t1]['points'] += 1;
                $stats[$t2]['points'] += 1;
            }
        }
    }

    usort($stats, function($a, $b) {
        if ($b['points'] == $a['points']) {
            return $b['wins'] - $a['wins'];
        }
        return $b['points'] - $a['points'];
    });
    
    echo '<table class="w-full text-left text-sm text-slate-600">';
    echo '<thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">';
    echo '<tr><th class="p-3 text-center w-16">อันดับ</th><th class="p-3">ชื่อทีม</th><th class="p-3 text-center">ประเภท</th><th class="p-3 text-center">ชนะ - แพ้</th><th class="p-3 text-right">คะแนน</th></tr>';
    echo '</thead><tbody class="divide-y divide-slate-100">';
    
    $i = 1;
    foreach ($stats as $r) {
        $rankClass = ($i == 1) ? 'text-amber-500 font-black' : 'font-bold text-slate-900';
        $catBadge = '';
        if ($r['category'] == 'male') $catBadge = '<span class="px-2 py-0.5 rounded text-[10px] bg-blue-50 text-blue-600 font-bold">ชาย</span>';
        elseif ($r['category'] == 'female') $catBadge = '<span class="px-2 py-0.5 rounded text-[10px] bg-pink-50 text-pink-600 font-bold">หญิง</span>';
        else $catBadge = '<span class="px-2 py-0.5 rounded text-[10px] bg-purple-50 text-purple-600 font-bold">Open</span>';

        echo "<tr class='hover:bg-slate-50/80 transition-colors'>
                <td class='p-3 text-center {$rankClass}'>{$i}</td>
                <td class='p-3 font-bold text-slate-900'>".htmlspecialchars($r['name'])."</td>
                <td class='p-3 text-center'>{$catBadge}</td>
                <td class='p-3 text-center font-mono text-xs'><span class='text-emerald-600 font-bold'>{$r['wins']}W</span> - <span class='text-rose-500 font-bold'>{$r['losses']}L</span></td>
                <td class='p-3 text-right font-display font-black text-brand-orange'>{$r['points']} PTS</td>
              </tr>";
        $i++;
    }
    echo '</tbody></table>';
    exit;
}

// ==========================================
// AUTO SETUP
// ==========================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM tournaments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('prize_pool', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN prize_pool VARCHAR(255) NULL AFTER max_teams"); }
    if (!in_array('rules', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN rules TEXT NULL AFTER prize_pool"); }
    if (!in_array('description', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN description TEXT NULL AFTER rules"); }
    if (!in_array('venue_address', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN venue_address VARCHAR(255) NULL AFTER description"); }
    if (!in_array('image_path', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN image_path VARCHAR(255) NULL AFTER venue_address"); }
    if (!in_array('best_of', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN best_of TINYINT NOT NULL DEFAULT 5 AFTER format"); }
    if (!in_array('registration_start', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN registration_start DATETIME NULL AFTER description"); }
    if (!in_array('registration_end', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN registration_end DATETIME NULL AFTER registration_start"); }
    if (!in_array('start_date', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN start_date DATETIME NULL AFTER registration_end"); }
    if (!in_array('roster_lock_at', $cols)) { $pdo->exec("ALTER TABLE tournaments ADD COLUMN roster_lock_at DATETIME NULL AFTER registration_end"); }

    $defaultGames = [
        'Arena of Valor (RoV) - รุ่นอายุต่ำกว่า 18 ปี',
        'Arena of Valor (RoV) - รุ่น Open',
        'Free Fire - รุ่น Open',
        'Tekken 8 - รุ่น Open',
        'Street Fighter 6 - รุ่น Open',
        'Efootball Mobile - รุ่น Open',
        'Roblox - รุ่นอายุ 8-12 ปี'
    ];
    $checkCol = $pdo->query("SHOW COLUMNS FROM games LIKE 'is_active'")->fetch();
    foreach ($defaultGames as $gName) {
        $chk = $pdo->prepare("SELECT game_id FROM games WHERE name = ?");
        $chk->execute([$gName]);
        if (!$chk->fetch()) {
            if ($checkCol) {
                $pdo->prepare("INSERT INTO games (name, is_active) VALUES (?, 1)")->execute([$gName]);
            } else {
                $pdo->prepare("INSERT INTO games (name) VALUES (?)")->execute([$gName]);
            }
        }
    }
} catch (Exception $e) { }

$games = $pdo->query("SELECT game_id, name, play_mode FROM games WHERE is_active = 1 ORDER BY game_id ASC")->fetchAll(PDO::FETCH_ASSOC);
$formGames = [];
foreach ($games as $game) {
    $gameLabel = displayGameName($game['name']);
    if (!isset($formGames[$gameLabel])) $formGames[$gameLabel] = $game;
}
$formGames = array_values($formGames);

function getGameId($games_array, $game_name) {
    foreach($games_array as $g) {
        if (trim($g['name']) == trim($game_name)) return $g['game_id'];
    }
    return '';
}

function uploadTournamentImage($file) {
    if (isset($file) && $file['error'] == UPLOAD_ERR_OK) {
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (in_array($file['type'], $allowedTypes)) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $fileName = 'tourney_' . uniqid() . '.' . $ext;
            $uploadDir = '../assets/uploads/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $destination = $uploadDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $destination)) { return 'uploads/' . $fileName; }
        }
    }
    return null;
}

// ==========================================
// 1. เพิ่มทัวร์นาเมนต์ใหม่
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'create') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $name = trim($_POST['name'] ?? '');
        $gameId = trim($_POST['game_id'] ?? '');
        $categoryForm = selectedCategoryFormData($_POST);
        $format = $categoryForm['format'];
        $bestOf = (int)($_POST['best_of'] ?? 5); 
        $maxTeams = $categoryForm['max_teams'];
        $prizePool = trim($_POST['prize_pool'] ?? '');
        $venueAddress = trim($_POST['venue_address'] ?? '');
        $venueLatLng = trim($_POST['venue_lat_lng'] ?? '');
        $rules = trim($_POST['rules'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $regStart = !empty($_POST['registration_start']) ? $_POST['registration_start'] : null;
        $regEnd = !empty($_POST['registration_end']) ? $_POST['registration_end'] : null;
        $rosterLock = !empty($_POST['roster_lock_at']) ? $_POST['roster_lock_at'] : null;
        $checkinOpen = !empty($_POST['checkin_open_at']) ? $_POST['checkin_open_at'] : null;
        $checkinClose = !empty($_POST['checkin_close_at']) ? $_POST['checkin_close_at'] : null;
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

        $imagePath = uploadTournamentImage($_FILES['tournament_image'] ?? null);

        $adminId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
        if (!$adminId) {
            $fallbackAdmin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetch();
            $adminId = $fallbackAdmin['user_id'] ?? 1;
        }

        if ($name == '' || empty($gameId) || $maxTeams < 1 || !$categoryForm['codes']) {
            $error = 'กรุณากรอกชื่อทัวร์นาเมนต์ เลือกเกม และกำหนดจำนวนทีมให้ถูกต้อง';
        } elseif (($categoryError = validateCategoryForm($_POST)) !== null) {
            $error = $categoryError;
        } elseif (!$regStart || !$regEnd || !$startDate) {
            $error = 'กรุณากำหนดวันเปิดรับสมัคร วันปิดรับสมัคร และวันเริ่มแข่งขัน';
        } elseif (strtotime($regStart) >= strtotime($regEnd)) {
            $error = 'วันปิดรับสมัครต้องอยู่หลังวันเปิดรับสมัคร';
        } elseif (strtotime($regEnd) > strtotime($startDate)) {
            $error = 'วันเริ่มแข่งขันต้องไม่อยู่ก่อนวันปิดรับสมัคร';
        } elseif ($rosterLock && strtotime($rosterLock) < strtotime($regEnd)) {
            $error = 'วัน Lock Roster ต้องไม่ก่อนวันปิดรับสมัคร';
        } elseif ($rosterLock && $rosterLock > $startDate) {
            $error = 'วัน Lock Roster ต้องไม่หลังวันเริ่มแข่งขัน';
        } elseif ($rosterLock && $checkinOpen && strtotime($rosterLock) > strtotime($checkinOpen)) {
            $error = 'วัน Lock Roster ต้องไม่หลังเวลาเปิด Check-in';
        } elseif ($endDate && strtotime($endDate) < strtotime($startDate)) {
            $error = 'วันสิ้นสุดการแข่งขันต้องอยู่หลังวันเริ่มแข่งขัน';
        } elseif ($checkinOpen && $checkinClose && strtotime($checkinOpen) >= strtotime($checkinClose)) {
            $error = 'เวลาเปิด Check-in ต้องอยู่ก่อนเวลาปิด Check-in';
        } elseif ($checkinClose && strtotime($checkinClose) > strtotime($startDate)) {
            $error = 'เวลาปิด Check-in ต้องไม่อยู่หลังวันเริ่มแข่งขัน';
        } elseif ($checkinOpen && $checkinClose && substr($checkinOpen, 0, 10) !== substr($startDate, 0, 10)) {
            $error = 'Check-in ต้องอยู่ในวันเริ่มการแข่งขัน';
        } elseif ($checkinOpen && $checkinClose && substr($checkinClose, 0, 10) !== substr($startDate, 0, 10)) {
            $error = 'Check-in ต้องอยู่ในวันเริ่มการแข่งขัน';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO tournaments (name, game_id, format, best_of, max_teams, prize_pool, venue_address, venue_lat_lng, image_path, rules, description, registration_start, registration_end, roster_lock_at, checkin_open_at, checkin_close_at, start_date, end_date, status, created_by)
                    VALUES (:name, :game_id, :format, :best_of, :max_teams, :prize_pool, :venue_address, :venue_lat_lng, :image_path, :rules, :description, :reg_start, :reg_end, :roster_lock, :checkin_open, :checkin_close, :start_date, :end_date, 'registration_open', :created_by)
                ");
                $stmt->execute([
                    'name' => $name, 'game_id' => $gameId, 'format' => $format, 'best_of' => $bestOf, 'max_teams' => $maxTeams, 'prize_pool' => $prizePool,
                    'venue_address' => $venueAddress, 'venue_lat_lng' => $venueLatLng, 'image_path' => $imagePath, 'rules' => $rules, 'description' => $description, 'reg_start' => $regStart,
                    'reg_end' => $regEnd, 'roster_lock' => $rosterLock, 'checkin_open' => $checkinOpen, 'checkin_close' => $checkinClose, 'start_date' => $startDate, 'end_date' => $endDate, 'created_by' => $adminId
                ]);
                $createdTournamentId = (int) $pdo->lastInsertId();
                ensureDefaultTournamentCategories($pdo, $createdTournamentId);
                saveTournamentFormCategories($pdo, $createdTournamentId, $_POST);
                saveTournamentDays($pdo, $createdTournamentId, $_POST['tournament_days_json'] ?? '');
                $success = 'สร้างทัวร์นาเมนต์ใหม่เรียบร้อยแล้ว';
            } catch (Exception $e) {
                $error = 'เกิดข้อผิดพลาดในการสร้างทัวร์นาเมนต์: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// 2. Export ผลการแข่งขันเป็นไฟล์ CSV
// ==========================================
if (isset($_GET['export_results_csv'])) {
    $exportTid = (int) $_GET['export_results_csv'];
    $exportCategory = $_GET['category'] ?? 'all';

    $stmtT = $pdo->prepare("SELECT name FROM tournaments WHERE tournament_id = :id");
    $stmtT->execute(['id' => $exportTid]);
    $tName = $stmtT->fetchColumn() ?: 'tournament_results';

    $sql = "
        SELECT 
            m.match_id,
            m.team1_id,
            m.team2_id,
            t1.name AS team1_name,
            t2.name AS team2_name,
            m.team1_score,
            m.team2_score,
            m.status
        FROM matches m
        LEFT JOIN teams t1 ON t1.team_id = m.team1_id
        LEFT JOIN teams t2 ON t2.team_id = m.team2_id
        WHERE m.tournament_id = :id
    ";
    
    $stmtMatches = $pdo->prepare($sql . " ORDER BY m.match_id ASC");
    $stmtMatches->execute(['id' => $exportTid]);
    $matches = $stmtMatches->fetchAll(PDO::FETCH_ASSOC);

    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="match_results_' . $exportTid . '_' . $exportCategory . '.csv"');

    $output = fopen('php://output', 'w');

    if ($output !== false) {
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, ['Match ID', 'ทีมเหย้า', 'คะแนน', 'ทีมเยือน', 'คะแนน', 'ผลการแข่งขัน']);

        foreach ($matches as $row) {
            $team1Score = is_numeric($row['team1_score']) ? (int)$row['team1_score'] : 0;
            $team2Score = is_numeric($row['team2_score']) ? (int)$row['team2_score'] : 0;
            $team1Name = !empty($row['team1_name']) ? $row['team1_name'] : 'รอระบุทีม';
            $team2Name = !empty($row['team2_name']) ? $row['team2_name'] : 'รอระบุทีม';

            if ($row['status'] === 'completed') {
                if ($team1Score > $team2Score) {
                    $resultText = $team1Name . ' ชนะ';
                } elseif ($team2Score > $team1Score) {
                    $resultText = $team2Name . ' ชนะ';
                } else {
                    $resultText = 'เสมอ';
                }
            } elseif ($row['status'] === 'walkover') {
                $resultText = 'ชนะบาย';
            } elseif ($row['status'] === 'ongoing') {
                $resultText = 'กำลังแข่งขัน';
            } else {
                $resultText = 'ยังไม่แข่ง';
            }

            fputcsv($output, [
                ' ' . ($row['match_id'] ?? '-') . ' ',
                ' ' . $team1Name . ' ',
                ' ' . $team1Score . ' ',
                ' ' . $team2Name . ' ',
                ' ' . $team2Score . ' ',
                ' ' . $resultText . ' '
            ]);
        }
        fclose($output);
    }
    exit;
}

// ==========================================
// 3. แก้ไขทัวร์นาเมนต์
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['action'] ?? '') == 'update') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $tid = (int) $_POST['tournament_id'];
        $name = trim($_POST['name']);
        $gameId = trim($_POST['game_id'] ?? '');
        $categoryForm = selectedCategoryFormData($_POST);
        $format = $categoryForm['format'];
        $bestOf = (int)($_POST['best_of'] ?? 5);
        $maxTeams = $categoryForm['max_teams'];
        $prizePool = trim($_POST['prize_pool'] ?? '');
        $venueAddress = trim($_POST['venue_address'] ?? '');
        $venueLatLng = trim($_POST['venue_lat_lng'] ?? '');
        $rules = trim($_POST['rules'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $regStart = !empty($_POST['registration_start']) ? $_POST['registration_start'] : null;
        $regEnd = !empty($_POST['registration_end']) ? $_POST['registration_end'] : null;
        $rosterLock = !empty($_POST['roster_lock_at']) ? $_POST['roster_lock_at'] : null;
        $checkinOpen = !empty($_POST['checkin_open_at']) ? $_POST['checkin_open_at'] : null;
        $checkinClose = !empty($_POST['checkin_close_at']) ? $_POST['checkin_close_at'] : null;
        $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

        $newImagePath = uploadTournamentImage($_FILES['tournament_image'] ?? null);

        if ($name == '' || empty($gameId) || $maxTeams < 1 || !$categoryForm['codes']) {
            $error = 'กรอกชื่อทัวร์นาเมนต์ เลือกเกม และจำนวนทีมให้ถูกต้อง';
        } elseif (($categoryError = validateCategoryForm($_POST)) !== null) {
            $error = $categoryError;
        } elseif (!$regStart || !$regEnd || !$startDate) {
            $error = 'กรุณากำหนดวันเปิดรับสมัคร วันปิดรับสมัคร และวันเริ่มแข่งขัน';
        } elseif (strtotime($regStart) >= strtotime($regEnd)) {
            $error = 'วันปิดรับสมัครต้องอยู่หลังวันเปิดรับสมัคร';
        } elseif (strtotime($regEnd) > strtotime($startDate)) {
            $error = 'วันเริ่มแข่งขันต้องไม่อยู่ก่อนวันปิดรับสมัคร';
        } elseif ($rosterLock && strtotime($rosterLock) < strtotime($regEnd)) {
            $error = 'วัน Lock Roster ต้องไม่ก่อนวันปิดรับสมัคร';
        } elseif ($rosterLock && $rosterLock > $startDate) {
            $error = 'วัน Lock Roster ต้องไม่หลังวันเริ่มแข่งขัน';
        } elseif ($rosterLock && $checkinOpen && strtotime($rosterLock) > strtotime($checkinOpen)) {
            $error = 'วัน Lock Roster ต้องไม่หลังเวลาเปิด Check-in';
        } elseif ($endDate && strtotime($endDate) < strtotime($startDate)) {
            $error = 'วันสิ้นสุดการแข่งขันต้องอยู่หลังวันเริ่มแข่งขัน';
        } elseif ($checkinOpen && $checkinClose && strtotime($checkinOpen) >= strtotime($checkinClose)) {
            $error = 'เวลาเปิด Check-in ต้องอยู่ก่อนเวลาปิด Check-in';
        } elseif ($checkinClose && strtotime($checkinClose) > strtotime($startDate)) {
            $error = 'เวลาปิด Check-in ต้องไม่อยู่หลังวันเริ่มแข่งขัน';
        } elseif ($checkinOpen && $checkinClose && substr($checkinOpen, 0, 10) !== substr($startDate, 0, 10)) {
            $error = 'Check-in ต้องอยู่ในวันเริ่มการแข่งขัน';
        } elseif ($checkinOpen && $checkinClose && substr($checkinClose, 0, 10) !== substr($startDate, 0, 10)) {
            $error = 'Check-in ต้องอยู่ในวันเริ่มการแข่งขัน';
        } else {
            if ($newImagePath) {
                $update = $pdo->prepare("
                    UPDATE tournaments 
                    SET name = :name, game_id = :game_id, format = :format, best_of = :best_of, max_teams = :max_teams, prize_pool = :prize_pool,
                        venue_address = :venue_address, venue_lat_lng = :venue_lat_lng, image_path = :image_path, rules = :rules, description = :description,
                        registration_start = :reg_start, registration_end = :reg_end, roster_lock_at = :roster_lock, checkin_open_at = :checkin_open, checkin_close_at = :checkin_close, start_date = :start_date, end_date = :end_date
                    WHERE tournament_id = :id
                ");
                $update->execute([
                    'name' => $name, 'game_id' => $gameId, 'format' => $format, 'best_of' => $bestOf, 'max_teams' => $maxTeams, 'prize_pool' => $prizePool,
                    'venue_address' => $venueAddress, 'venue_lat_lng' => $venueLatLng, 'image_path' => $newImagePath, 'rules' => $rules,
                    'description' => $description, 'reg_start' => $regStart, 'reg_end' => $regEnd, 'checkin_open' => $checkinOpen, 'checkin_close' => $checkinClose,
                    'start_date' => $startDate, 'end_date' => $endDate, 'roster_lock' => $rosterLock, 'id' => $tid
                ]);
            } else {
                $update = $pdo->prepare("
                    UPDATE tournaments 
                    SET name = :name, game_id = :game_id, format = :format, best_of = :best_of, max_teams = :max_teams, prize_pool = :prize_pool,
                        venue_address = :venue_address, venue_lat_lng = :venue_lat_lng, rules = :rules, description = :description,
                        registration_start = :reg_start, registration_end = :reg_end, roster_lock_at = :roster_lock, checkin_open_at = :checkin_open, checkin_close_at = :checkin_close, start_date = :start_date, end_date = :end_date
                    WHERE tournament_id = :id
                ");
                $update->execute([
                    'name' => $name, 'game_id' => $gameId, 'format' => $format, 'best_of' => $bestOf, 'max_teams' => $maxTeams, 'prize_pool' => $prizePool,
                    'venue_address' => $venueAddress, 'venue_lat_lng' => $venueLatLng, 'rules' => $rules, 'description' => $description,
                    'reg_start' => $regStart, 'reg_end' => $regEnd, 'roster_lock' => $rosterLock, 'checkin_open' => $checkinOpen, 'checkin_close' => $checkinClose, 'start_date' => $startDate, 'end_date' => $endDate, 'id' => $tid
                ]);
            }
            saveTournamentFormCategories($pdo, $tid, $_POST);
            saveTournamentDays($pdo, $tid, $_POST['tournament_days_json'] ?? '');
            $success = 'อัปเดตข้อมูลทัวร์นาเมนต์เรียบร้อยแล้ว';
        }
    }
}

// ==========================================
// 4. ลบทัวร์นาเมนต์
// ==========================================
if (isset($_GET['delete_tournament'])) {
    $tid = (int) $_GET['delete_tournament'];
    $error = 'ปิดการลบ Tournament ถาวรแล้ว กรุณาใช้เก็บเข้าคลังแทน';
}

// ==========================================
// 5. ปิดรับสมัคร & สร้างตารางแข่ง
// ==========================================
if (isset($_GET['close_registration'])) {
    $tid = (int) $_GET['close_registration'];

    $windowStmt = $pdo->prepare('SELECT registration_end, checkin_close_at, status FROM tournaments WHERE tournament_id = :tournament_id');
    $windowStmt->execute(['tournament_id' => $tid]);
    $window = $windowStmt->fetch();
    if (!$window) {
        $error = 'ไม่พบ Tournament ที่ต้องการจัดสาย';
    } elseif (!empty($window['registration_end']) && strtotime($window['registration_end']) > time()) {
        $error = 'ยังไม่ถึงเวลาปิดรับสมัคร';
    } elseif (!empty($window['checkin_close_at']) && strtotime($window['checkin_close_at']) > time()) {
        $error = 'ยังไม่ถึงเวลาปิด Check-in จึงยังจัดสายไม่ได้';
    }

    if ($error === '') {
        qualifyCompletedCheckins($pdo, $tid);
        disqualifyIncompleteCheckins($pdo, $tid);
    }
    if ($error === '') {
        $eligibleStmt = $pdo->prepare("SELECT COUNT(*) FROM tournament_registrations
            WHERE tournament_id = :tid AND participation_status = 'qualified_for_draw'");
        $eligibleStmt->execute(['tid' => $tid]);
        if ((int) $eligibleStmt->fetchColumn() < 2) {
            $error = 'ยังมีผู้สมัครที่ Check-in ไม่ครบ หรือผู้ผ่านการจัดสายไม่ถึง 2 รายการ';
        }
    }

    if ($error === '') {
        $tStmt = $pdo->prepare("SELECT format FROM tournaments WHERE tournament_id = :id");
        $tStmt->execute(['id' => $tid]);
        $format = $tStmt->fetchColumn();

        try {
            if ($format == 'double_elimination') {
                generateDoubleEliminationBracket($pdo, $tid);
            } elseif (in_array($format, ['round_robin', 'group_playoff'], true)) {
                generateRoundRobin($pdo, $tid);
            } else {
                generateSingleEliminationBracket($pdo, $tid);
            }

            if ($error === '') {
                $pdo->prepare("UPDATE tournaments SET status = 'bracket_generated' WHERE tournament_id = :id")->execute(['id' => $tid]);
                header("Location: record-match.php?tournament_id=" . $tid);
                exit;
            }
        } catch (Exception $e) {
            $error = 'สร้างตารางแข่งขันไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['generate_playoff'])) {
    $tid = (int) $_GET['generate_playoff'];
    try {
        generateGroupPlayoff($pdo, $tid);
        $pdo->prepare("UPDATE tournaments SET status = 'bracket_generated' WHERE tournament_id = :id")->execute(['id' => $tid]);
        $success = 'สร้างสาย Playoff จากทีมที่ผ่านรอบแบ่งกลุ่มแล้ว';
    } catch (Exception $e) {
        $error = 'สร้างสาย Playoff ไม่สำเร็จ: ' . $e->getMessage();
    }
}

// ==========================================
// 6. สลับสถานะเป็น "แข่งจบแล้ว (completed)"
// ==========================================
$filterSearch = trim($_GET['search'] ?? '');
$filterGame = (int) ($_GET['game_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');
$filterYear = (int) ($_GET['year'] ?? 0);
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterAction = trim($_GET['needs_action'] ?? '');
$tournamentWhere = [];
$tournamentParams = [];
if ($filterSearch !== '') { $tournamentWhere[] = 't.name LIKE :search'; $tournamentParams['search'] = '%' . $filterSearch . '%'; }
if ($filterGame > 0) { $tournamentWhere[] = 't.game_id = :game_id_filter'; $tournamentParams['game_id_filter'] = $filterGame; }
if ($filterStatus !== '') { $tournamentWhere[] = 't.status = :status_filter'; $tournamentParams['status_filter'] = $filterStatus; }
if ($filterYear > 0) { $tournamentWhere[] = 'YEAR(t.start_date) = :year_filter'; $tournamentParams['year_filter'] = $filterYear; }
if ($filterDateFrom !== '') { $tournamentWhere[] = 'DATE(t.start_date) >= :date_from'; $tournamentParams['date_from'] = $filterDateFrom; }
if ($filterDateTo !== '') { $tournamentWhere[] = 'DATE(t.start_date) <= :date_to'; $tournamentParams['date_to'] = $filterDateTo; }
if ($filterCategory !== '') { $tournamentWhere[] = 'EXISTS (SELECT 1 FROM tournament_registrations category_reg WHERE category_reg.tournament_id = t.tournament_id AND category_reg.category = :category_filter)'; $tournamentParams['category_filter'] = $filterCategory; }
if ($filterAction === 'checkin') { $tournamentWhere[] = '(SELECT COUNT(*) FROM tournament_registrations action_reg WHERE action_reg.tournament_id = t.tournament_id AND action_reg.status = \'approved\' AND EXISTS (SELECT 1 FROM tournament_registration_members action_member WHERE action_member.tournament_registration_id = action_reg.tournament_registration_id AND action_member.is_required_for_checkin = 1 AND action_member.checkin_status NOT IN (\'checked_in\', \'waived\'))) > 0'; }
if ($filterAction === 'draw') { $tournamentWhere[] = 'EXISTS (SELECT 1 FROM tournament_registrations draw_reg WHERE draw_reg.tournament_id = t.tournament_id AND draw_reg.participation_status = \'qualified_for_draw\')'; }
$tournamentSql = "
    SELECT t.*, g.name AS game_name,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id) AS total_registrations,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND status = 'pending') AS pending_count,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND (status = 'approved' OR status = 'checked_in')) AS team_count,
        (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.category = 'male' AND (tr.status = 'approved' OR tr.status = 'checked_in')) AS count_male,
        (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.category = 'female' AND (tr.status = 'approved' OR tr.status = 'checked_in')) AS count_female,
        (SELECT COUNT(*) FROM tournament_registrations tr WHERE tr.tournament_id = t.tournament_id AND tr.category = 'open' AND (tr.status = 'approved' OR tr.status = 'checked_in')) AS count_open,
        (SELECT COUNT(*) FROM tournament_registrations tr2
            WHERE tr2.tournament_id = t.tournament_id AND tr2.status = 'approved'
              AND EXISTS (SELECT 1 FROM tournament_registration_members trm2
                          WHERE trm2.tournament_registration_id = tr2.tournament_registration_id AND trm2.is_required_for_checkin = 1)
              AND NOT EXISTS (SELECT 1 FROM tournament_registration_members trm
                          WHERE trm.tournament_registration_id = tr2.tournament_registration_id
                            AND trm.is_required_for_checkin = 1 AND trm.checkin_status NOT IN ('checked_in', 'waived'))
        ) AS checkin_complete_count,
                (SELECT COUNT(*) FROM tournament_registrations tr3
                        WHERE tr3.tournament_id = t.tournament_id AND tr3.status = 'approved' AND tr3.category = 'male'
                            AND EXISTS (SELECT 1 FROM tournament_registration_members trm3 WHERE trm3.tournament_registration_id = tr3.tournament_registration_id AND trm3.is_required_for_checkin = 1)
                            AND NOT EXISTS (SELECT 1 FROM tournament_registration_members trm4 WHERE trm4.tournament_registration_id = tr3.tournament_registration_id AND trm4.is_required_for_checkin = 1 AND trm4.checkin_status NOT IN ('checked_in', 'waived'))
                ) AS checkin_male_count,
                (SELECT COUNT(*) FROM tournament_registrations tr4
                        WHERE tr4.tournament_id = t.tournament_id AND tr4.status = 'approved' AND tr4.category = 'female'
                            AND EXISTS (SELECT 1 FROM tournament_registration_members trm5 WHERE trm5.tournament_registration_id = tr4.tournament_registration_id AND trm5.is_required_for_checkin = 1)
                            AND NOT EXISTS (SELECT 1 FROM tournament_registration_members trm6 WHERE trm6.tournament_registration_id = tr4.tournament_registration_id AND trm6.is_required_for_checkin = 1 AND trm6.checkin_status NOT IN ('checked_in', 'waived'))
                ) AS checkin_female_count,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND participation_status = 'disqualified') AS disqualified_count,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND participation_status = 'withdrawn') AS withdrawn_count,
        (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.tournament_id AND participation_status = 'qualified_for_draw') AS qualified_count,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id AND status IN ('completed', 'walkover')) AS completed_matches_count,
        (SELECT COUNT(*) FROM matches WHERE tournament_id = t.tournament_id) AS total_matches_count,
        (SELECT COUNT(*) FROM tournament_days WHERE tournament_id = t.tournament_id) AS tournament_days_count,
        (SELECT COUNT(*) FROM tournament_groups WHERE tournament_id = t.tournament_id) AS group_count,
        (SELECT COALESCE(MAX(round_number), 0) FROM matches WHERE tournament_id = t.tournament_id) AS current_round
    FROM tournaments t
    LEFT JOIN games g ON g.game_id = t.game_id
    " . ($tournamentWhere ? 'WHERE ' . implode(' AND ', $tournamentWhere) : '') . "
    ORDER BY t.created_at DESC
";
$tournamentStmt = $pdo->prepare($tournamentSql);
$tournamentStmt->execute($tournamentParams);
$tournaments = $tournamentStmt->fetchAll();

$summaryTournaments = [
    'total_count' => count($tournaments),
    'registration_open_count' => 0,
    'checkin_open_count' => 0,
    'bracket_generated_count' => 0,
    'ongoing_count' => 0,
    'completed_count' => 0,
];
foreach ($tournaments as $summaryTournament) {
    $statusKey = $summaryTournament['status'] . '_count';
    if (array_key_exists($statusKey, $summaryTournaments)) $summaryTournaments[$statusKey]++;
}

foreach ($tournaments as $tournamentRow) {
    ensureDefaultTournamentCategories($pdo, (int) $tournamentRow['tournament_id']);
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="th" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการทัวร์นาเมนต์ - Korat Esport</title>
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
        ::-webkit-scrollbar { display: none; }
        html, body {
            -ms-overflow-style: none;
            scrollbar-width: none;
            background-color: #F4F6F9;
        }
        .nav-item { transition: all 0.2s ease; }
        .nav-item:hover, .nav-item.active {
            background: rgba(255, 85, 0, 0.12);
            color: #FF5500;
            border-left: 4px solid #FF5500;
        }
        .tournament-stepper { display:flex; align-items:flex-start; gap:0; overflow-x:auto; padding:0.25rem 0 0.75rem; }
        .tournament-step { display:flex; align-items:flex-start; flex:1 0 110px; min-width:110px; }
        .tournament-step:last-child { flex:0 0 auto; }
        .tournament-step-line { height:2px; flex:1; margin:1rem 0.5rem 0; background:#cbd5e1; }
        .tournament-step.is-active .tournament-step-circle { background:#f97316; color:white; border-color:#f97316; }
        .tournament-step.is-complete .tournament-step-circle { background:#0f172a; color:white; border-color:#0f172a; }
        .tournament-step-circle { width:2rem; height:2rem; border:2px solid #cbd5e1; border-radius:9999px; display:flex; align-items:center; justify-content:center; flex:0 0 auto; font-size:0.75rem; font-weight:800; background:white; color:#64748b; }
        .tournament-step-label { margin-top:0.4rem; font-size:0.68rem; font-weight:700; color:#64748b; white-space:nowrap; }
        .tournament-step.is-active .tournament-step-label { color:#ea580c; }
        .tournament-step.is-complete .tournament-step-label { color:#0f172a; }
        .tournament-step-panel[hidden] { display:none !important; }
        .tournament-step-page { min-height:280px; }
        #createModal > div, #editModal > div { overflow:hidden; display:flex; flex-direction:column; }
        #createModal form, #editModal form { overflow-y:auto; min-height:0; }
        #createModal form > div:last-child, #editModal form > div:last-child { position:sticky; bottom:0; background:#fff; z-index:11; }
        .admin-action-menu { width: 14rem; padding: 0.5rem; }
        .admin-action-item { min-height: 2.5rem; width: 100%; display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem 0.75rem; border-radius: 0.5rem; text-align: left; font-size: 0.75rem; font-weight: 600; line-height: 1.25rem; }
        .admin-action-item i { width: 1rem; text-align: center; flex: 0 0 1rem; }
        .admin-action-group { padding: 0.35rem 0.75rem 0.25rem; color: #94a3b8; font-size: 0.625rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; }
        .admin-action-menu > button, .admin-action-menu > a, .admin-action-menu > form > button { min-height: 2.5rem; width: 100%; display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem 0.75rem; border-radius: 0.5rem; text-align: left; font-size: 0.75rem; font-weight: 600; line-height: 1.25rem; }
        .admin-action-menu > button i, .admin-action-menu > a i, .admin-action-menu > form > button i { width: 1rem; text-align: center; flex: 0 0 1rem; }
    </style>
    <script>
        const tournamentsList = <?php echo json_encode($tournaments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

        const gameRulesData = {
            "Tekken": `TEKKEN 8 (ประเภทบุคคล รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. ระบบการแข่งขัน : Double Elimination (สายบน Winners /สายล่าง Losers)
1.2. Best of 3 (2 ใน 3 เกม)
1.3. รอบ Top 8 (Finals) : Best of 5 (3 ใน 5 เกม)
2. การตั้งค่าในเกม: 3 Rounds (60 วินาทีต่อ Round)
2.1. กฎการเลือกตัวละครและฉาก (Character & Stage Selection)
2.2. เกมที่ 1: ตกลงเลือกตัวละคร (หรือใช้ Blind Pick หากตกลงกันไม่ได้)
2.3. เลือกฉากด้วยระบบ "Random" เท่านั้น
2.4. เกมถัดไป ผู้แพ้สามารถเลือกทำอย่างใดอย่างหนึ่งดังนี้
2.4.1. ขอเปลี่ยนตัวละคร และต้องเลือกฉากแบบ Random เท่านั้น
2.4.2. ใช้ตัวละครเดิม และต้องเลือกฉากแบบ Random เท่านั้น
2.4.3. ผู้ชนะห้ามเปลี่ยนตัวละคร
3. การตั้งค่าระบบเกม (Game Settings)
3.1. Platform: PlayStation 5 แบบปิด Bluetooth
3.2. Special Style: "อนุญาต" ให้ใช้งานได้ (ระบบช่วยกดคอมโบพื้นฐานของ Tekken 8)
3.3. ฝั่งซ้ายและขวาตัดสินจากการทอยเหรียญหัวและก้อย
4. ข้อบังคับด้านอุปกรณ์และการขัดจังหวะ
4.1. ผู้แข่งต้องเตรียมอุปกรณ์มาเอง ห้ามมีระบบ Macro/Turbo
4.2. เครื่อง PS5 จะปิด Bluetooth และต้องต่ออุปกรณ์ด้วยสายเท่านั้น
4.3. หากมีการกด Pause หรือปุ่ม Home ระหว่างสู้ ผู้ที่กดจะถูกปรับแพ้ ใน "Round" นั้นทันที`,

            "Roblox": `Roblox (ประเภทบุคคล รุ่นอายุ 8-12 ปี)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แบ่ง 10 กลุ่ม กลุ่มละ 10 คน แข่ง 5 รอบ เกมแข่งกระโดดหลบสิ่งกีดขวาง Obby, อันดับ 1 แต่ละกลุ่มเข้า Final)
1.2. รอบ Final (แข่ง 5 รอบ เกมแข่งกระโดดหลบสิ่งกีดขวาง Obby, ชิงอันดับ 1-3, อันดับ 5-10 รับใบประกาศอันดับ 4 ร่วม)
2. การนับคะแนน: อันดับ 1 = 10, อันดับ 2 = 8, อันดับ 3 = 7, อันดับ 4 = 5, อันดับ 5 = 3, อันดับ 6 = 2, อันดับ 7-10 = 1 คะแนน
3. ข้อบังคับด้านอุปกรณ์: อนุญาตให้ใช้ Tablet, iPad มือถือส่วนตัว ในแอปพลิเคชันเกม Roblox
4. การขัดจังหวะและการ Pause: หากมีการกด Pause หรือปุ่ม Home ระหว่างแข่ง ผู้ที่กดจะถูกปรับแพ้ใน "Round" นั้นทันที`,

            "Street Fighter": `STREET FIGHTER 6 (ประเภทบุคคล รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. แข่งแบบ Double Elimination
1.2. รอบคัดเลือกจนถึงก่อน Top 8 แข่ง Best of 3
1.3. รอบ Top 8 (Finals): Best of 5
1.4. การตั้งค่าในเกม: 99 Seconds, 2/3 Rounds ต่อเกม
2. กฎการเลือกตัวละครและประเภทการควบคุม
2.1. Control Types: อนุญาตให้ใช้ทั้ง Classic และ Modern
2.2. เกมที่ 1: เลือกตัวละครและประเภทการควบคุม
2.3. เลือกฉากด้วยระบบ Random หรือแล้วแต่ผู้เล่นจะตกลงกัน
2.4. ผู้ชนะห้ามเปลี่ยนตัวละคร และ ห้ามเปลี่ยนประเภทการควบคุม
2.5. ผู้แพ้ มีสิทธิเลือก เปลี่ยนตัวละคร หรือ เปลี่ยนประเภทการควบคุม
3. ข้อบังคับด้านอุปกรณ์
3.1. Leverless/Hitbox: ต้องเป็นไปตามกฎ SOCD (ขึ้น+ลง หรือ ขวา+ซ้าย ต้องหักล้างกัน ตัวละครไม่ขยับเท่านั้น)
3.2. เครื่อง PS5 จะปิด Bluetooth และต้องต่ออุปกรณ์ด้วยสายเท่านั้น`,

            "Free Fire": `FREE FIRE (ประเภททีม รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แบ่ง 2 กลุ่ม กลุ่มละ 12 ทีม แข่ง 4 รอบ แผนที่ เกาะสวรรค์, ทะเลทราย, แดนชำระบาป, นิคมรกร้าง อันดับ 1-6 เข้ารอบ Final)
1.2. รอบ Final (แข่ง 4 รอบ แผนที่เดียวกัน)
2. การนับคะแนน: อันดับ 1 = 10, อันดับ 2 = 7, อันดับ 3 = 5, อันดับ 4 = 3, อันดับ 5 = 2, อันดับ 6 = 1 คะแนน, อันดับ 7-12 = 0 คะแนน, 1 Kill = 1 คะแนน
3. กรณีคะแนนเท่ากัน: ดูจากจำนวน Booyah -> จำนวน Kill รวม -> อันดับในเกมสุดท้าย
4. การตั้งค่าเกม: เปิดใช้สถานะปืนจากสกิน, เปิดระบบชุบชีวิต, เปิดมุมกล้องหลังถูกสังหาร, ปิดชื่อตัวละคร, ปิด Kill Feed, ปิดโปรแกรมจำลองคอมพิวเตอร์`,

            "RoV": `ARENA OF VALOR (RoV)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แบ่งกลุ่ม แข่งแบบ BO2 ชนะได้ 2, เสมอ 1 คะแนน ตัดสินจาก Kill และ Time Rating อันดับ 1-2 เข้ารอบ Playoff)
1.2. รอบ Playoff (Single Elimination BO3)
2. กติกาการแข่งขัน
2.1. โหมด 5V5 ใช้ระบบ Global Ban/Pick
2.2. การเลือกฝั่ง: เกมที่ 1 ทอยเหรียญ, ตั้งแต่เกมที่ 2 ให้ทีมแพ้เลือกฝั่ง
2.3. ห้ามใช้ Hero ที่อัพเดทยังไม่ถึง 14 วัน / ห้ามใช้สกินที่มีปัญหาบั๊ก
2.4. ห้ามหยุดพักเกมระหว่าง Fight ทุกกรณี หากฝ่าฝืนเตือนหรือปรับแพ้ในเกมนั้น`,

            "Efootball": `EFOOTBALL MOBILE (ประเภทบุคคล รุ่น Open)
1. รูปแบบการแข่งขัน
1.1. รอบแบ่งกลุ่ม (แข่งแบบ Best of 1 ชนะได้ 3 คะแนน ตัดสินจากประตูได้เสีย, จำนวนประตู, Head to Head นำอันดับ 1-2 เข้ารอบ 16 ทีม)
1.2. รอบ 16 ทีมสุดท้ายถึงชิงชนะเลิศ (Single Elimination Best of 3)
2. กติกาการแข่งขัน
2.1. ใช้ทีมสโมสรลิขสิทธิ์แบบเกลี่ยพลัง (ห้ามใช้สโมสรไทยลีก)
2.2. ตั้งค่าเกม: Match Type: Standard, Match Time: 6 min, Injuries: Off, Extra Time: On, Penalties: On, Substitutions: 5
2.3. กรณีหลุด: ครึ่งแรกเริ่มใหม่ใช้สกอร์เดิม, ครึ่งหลังเริ่มแข่งใหม่ครึ่งเดียว, หลังนาทีที่ 80 ถ้านำหลุดให้แข่งใหม่ครึ่งเดียว ถ้าตามหลุดให้นับผลล่าสุดทันที`
        };

        function autoFillRules(selectElement, targetRulesId) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const gameName = selectedOption.text || '';
            const rulesTextarea = document.getElementById(targetRulesId);
            
            let matchedKey = Object.keys(gameRulesData).find(key => gameName.includes(key));
            if (matchedKey && gameRulesData[matchedKey]) {
                rulesTextarea.value = gameRulesData[matchedKey];
            } else {
                rulesTextarea.value = "";
            }
        }

        function openCreateModal() {
            document.getElementById('createModal').classList.remove('hidden');
            document.getElementById('createModal').classList.add('flex');
        }
        function closeCreateModal() {
            document.getElementById('createModal').classList.add('hidden');
            document.getElementById('createModal').classList.remove('flex');
        }

        function safeSetValue(id, value) {
            const el = document.getElementById(id);
            if (el) el.value = value;
        }

        function openEditModal(tournamentId) {
            try {
                const tournament = tournamentsList.find(t => t.tournament_id == tournamentId);
                if (!tournament) throw new Error("ไม่พบข้อมูลทัวร์นาเมนต์");

                safeSetValue('edit_tournament_id', tournament.tournament_id);
                safeSetValue('edit_name', tournament.name);
                safeSetValue('edit_game_id', tournament.game_id);
                safeSetValue('edit_format', tournament.format || 'single_elimination');
                safeSetValue('edit_best_of', tournament.best_of || '5');
                safeSetValue('edit_max_teams', tournament.max_teams);
                safeSetValue('edit_prize_pool', tournament.prize_pool || '');
                safeSetValue('edit_rules', tournament.rules || '');
                
                safeSetValue('edit_venue_address', tournament.venue_address || '');
                safeSetValue('edit_venue_lat_lng', tournament.venue_lat_lng || '');
                safeSetValue('edit_description', tournament.description || '');
                safeSetValue('edit_registration_start', tournament.registration_start ? tournament.registration_start.replace(' ', 'T') : '');
                safeSetValue('edit_registration_end', tournament.registration_end ? tournament.registration_end.replace(' ', 'T') : '');
                safeSetValue('edit_roster_lock_at', tournament.roster_lock_at ? tournament.roster_lock_at.replace(' ', 'T') : '');
                safeSetValue('edit_checkin_open_at', tournament.checkin_open_at ? tournament.checkin_open_at.replace(' ', 'T') : '');
                safeSetValue('edit_checkin_close_at', tournament.checkin_close_at ? tournament.checkin_close_at.replace(' ', 'T') : '');
                safeSetValue('edit_start_date', tournament.start_date ? tournament.start_date.replace(' ', 'T') : '');
                safeSetValue('edit_end_date', tournament.end_date ? tournament.end_date.replace(' ', 'T') : '');
                
                const previewContainer = document.getElementById('edit_image_preview');
                if (previewContainer) {
                    if (tournament.image_path) {
                        previewContainer.innerHTML = `<img src="../assets/${tournament.image_path}" class="h-20 w-auto rounded-lg border border-slate-200 object-cover mt-1">`;
                    } else {
                        previewContainer.innerHTML = `<span class="text-xs text-slate-400 italic">ยังไม่มีรูปภาพ</span>`;
                    }
                }

                const modal = document.getElementById('editModal');
                if (modal) {
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                }
                loadEditFormData(tournament.tournament_id);
            } catch (e) {
                console.error("เกิดข้อผิดพลาดในการโหลดข้อมูลทัวร์นาเมนต์: ", e);
                alert("ไม่สามารถเปิดหน้าต่างแก้ไขได้ โปรดตรวจสอบความถูกต้องของข้อมูล");
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
            document.getElementById('editModal').classList.remove('flex');
        }

        function addTournamentDay(containerId, day = {}) {
            const container = document.getElementById(containerId);
            if (!container) return;
            const row = document.createElement('div');
            row.className = 'grid grid-cols-1 sm:grid-cols-5 gap-2 items-end rounded-lg bg-slate-50 p-2';
            row.innerHTML = `<input data-day="event_date" type="date" value="${escapeHtml(day.event_date || '')}" class="rounded border border-slate-200 px-2 py-1.5 text-xs"><input data-day="start_time" type="time" value="${escapeHtml(day.start_time || '')}" class="rounded border border-slate-200 px-2 py-1.5 text-xs"><input data-day="end_time" type="time" value="${escapeHtml(day.end_time || '')}" class="rounded border border-slate-200 px-2 py-1.5 text-xs"><input data-day="venue_name" type="text" value="${escapeHtml(day.venue_name || '')}" placeholder="สนาม" class="rounded border border-slate-200 px-2 py-1.5 text-xs"><button type="button" class="rounded bg-rose-50 px-2 py-1.5 text-xs font-bold text-rose-700" onclick="this.parentElement.remove()"><i class="fa-solid fa-trash"></i></button><input data-day="notes" type="text" value="${escapeHtml(day.notes || '')}" placeholder="หมายเหตุ" class="sm:col-span-4 rounded border border-slate-200 px-2 py-1.5 text-xs">`;
            container.appendChild(row);
        }

        function collectTournamentDays(containerId, fieldId) {
            const rows = document.querySelectorAll(`#${containerId} > div`);
            const days = [];
            rows.forEach(row => {
                const day = {};
                row.querySelectorAll('[data-day]').forEach(input => { day[input.dataset.day] = input.value; });
                if (day.event_date) days.push(day);
            });
            const field = document.getElementById(fieldId);
            if (field) field.value = JSON.stringify(days);
        }

        function prepareTournamentDayForms() {
            const createForm = document.querySelector('#createModal form');
            const editForm = document.querySelector('#editModal form');
            if (createForm) createForm.addEventListener('submit', () => collectTournamentDays('createDays', 'create_tournament_days_json'));
            if (editForm) editForm.addEventListener('submit', () => collectTournamentDays('editDays', 'edit_tournament_days_json'));
            setupTournamentStepper(createForm, 'create');
            setupTournamentStepper(editForm, 'edit');
        }

        function rebuildStepperForm(form, type) {
            if (!form || form.dataset.stepPagesReady === '1') return;
            const legacyFormat = form.querySelector('[name="format"]');
            const legacyMax = form.querySelector('[name="max_teams"]');
            if (legacyFormat) legacyFormat.closest('div').hidden = true;
            if (legacyMax) legacyMax.closest('div').hidden = true;
            const formatPanel = form.querySelector('[id$="-format"]');
            if (formatPanel && !form.querySelector('[name="seed_method"]')) { const seed = document.createElement('label'); seed.className = 'block col-span-2 text-xs font-bold text-slate-700'; seed.innerHTML = 'วิธีจัด Seed/Bye<select name="seed_method" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm font-medium"><option value="ranking">Seed ตาม Ranking</option><option value="admin">ใช้ Seed ที่ Admin กำหนด</option><option value="random">สุ่มเมื่อไม่มี Seed/Ranking</option></select>'; formatPanel.appendChild(seed); }
            form.querySelectorAll('input[name^="category_required_roles["]').forEach(input => {
                const code = input.name.match(/\[([^\]]+)\]/)?.[1];
                if (!code || input.dataset.rolesReady) return;
                const wrapper = document.createElement('div'); wrapper.className = 'grid grid-cols-2 gap-1 mt-1 text-[10px]';
                [['captain', 'กัปตัน'], ['player', 'ผู้เล่น'], ['substitute', 'ตัวสำรอง'], ['coach', 'โค้ช'], ['manager', 'ผู้จัดการ']].forEach(role => { const label = document.createElement('label'); label.innerHTML = `<input type="checkbox" name="category_required_roles[${code}][]" value="${role[0]}" ${role[0] === 'player' ? 'checked' : ''}> ${role[1]}`; wrapper.appendChild(label); });
                input.replaceWith(wrapper); input.dataset.rolesReady = '1';
            });
            form.querySelectorAll('input[name="category_codes[]"]').forEach(input => {
                const card = input.closest('.rounded-lg');
                const toggle = () => { if (card) { card.hidden = false; const selector = input.closest('label'); [...card.children].filter(child => child !== selector).forEach(child => { child.hidden = !input.checked; child.querySelectorAll('input, select, textarea').forEach(field => { field.disabled = !input.checked; }); }); } };
                input.addEventListener('change', toggle); toggle();
            });
            form.querySelectorAll('select[name^="category_format["]').forEach(select => {
                const code = select.name.match(/\[([^\]]+)\]/)?.[1];
                const card = select.closest('.rounded-lg');
                const groupInputs = card ? card.querySelectorAll('[name^="category_group_size"], [name^="category_advance"]') : [];
                const toggleGroups = () => { const enabled = ['round_robin', 'group_playoff'].includes(select.value); groupInputs.forEach(input => { input.closest('label').hidden = !enabled; input.disabled = !enabled; if (!enabled) input.value = ''; }); };
                select.addEventListener('change', toggleGroups); toggleGroups();
            });
            const footer = form.querySelector('[data-step-footer]');
            const stepper = form.querySelector(`[data-stepper="${type}"]`);
            if (!footer || !stepper) return;
            const pages = [];
            for (let step = 1; step <= 5; step++) {
                const page = document.createElement('section');
                page.className = 'tournament-step-page space-y-4';
                page.dataset.formStep = String(step);
                page.hidden = step !== 1;
                pages[step] = page;
                form.insertBefore(page, footer);
            }
            const nodes = [...form.children].filter(node => node !== footer && node !== stepper && !node.matches('input[type="hidden"]') && !node.classList.contains('tournament-step-page'));
            const fields = node => [...node.querySelectorAll('input, select, textarea')].map(input => input.name || input.id || '');
            const targetStep = names => {
                if (names.some(name => name.includes('category_') || name === 'category_codes[]')) return 3;
                if (names.some(name => name.includes('registration_') || name.includes('checkin_') || name === 'start_date' || name === 'end_date' || name.includes('venue_') || name === 'tournament_days_json')) return 4;
                if (names.some(name => name === 'tournament_image')) return 1;
                if (names.some(name => name.includes('rules'))) return 5;
                if (names.some(name => name === 'game_id' || name === 'format' || name === 'best_of' || name === 'max_teams' || name === 'seed_method')) return 2;
                return 1;
            };
            const appendNode = (node, step) => { if (node) pages[step].appendChild(node); };
            nodes.forEach(node => {
                const nodeFields = fields(node);
                const directChildren = [...node.children];
                const childTargets = directChildren.map(child => targetStep(fields(child)));
                if (directChildren.length > 1 && new Set(childTargets).size > 1) {
                    directChildren.forEach((child, index) => appendNode(child, childTargets[index]));
                    return;
                }
                appendNode(node, targetStep(nodeFields));
            });
            form.dataset.stepPagesReady = '1';
        }

        function setupTournamentStepper(form, type) {
            if (!form) return;
            rebuildStepperForm(form, type);
            let currentStep = 1;
            const totalSteps = 5;
            const stepper = form.querySelector(`[data-stepper="${type}"]`);
            const submitButton = form.querySelector('button[type="submit"]');
            const footer = submitButton ? submitButton.parentElement : null;

            if (footer && !footer.querySelector('[data-step-back]')) {
                const back = document.createElement('button'); back.type = 'button'; back.dataset.stepBack = '1'; back.className = 'mr-auto rounded-xl bg-slate-100 px-5 py-2.5 text-xs font-semibold text-slate-700'; back.textContent = 'ย้อนกลับ'; back.onclick = () => { if (currentStep > 1) { currentStep--; renderStep(); } };
                const next = document.createElement('button'); next.type = 'button'; next.dataset.stepNext = '1'; next.className = 'rounded-xl bg-brand-orange px-5 py-2.5 text-xs font-bold text-white'; next.textContent = 'ถัดไป'; next.onclick = () => { if (validateStep()) { currentStep++; renderStep(); } };
                footer.insertBefore(back, footer.firstChild); footer.insertBefore(next, submitButton);
            }
            const summary = document.createElement('div');
            summary.dataset.stepSummary = '1';
            summary.className = 'hidden rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-700';
            form.querySelector('[data-form-step="5"]').appendChild(summary);
            setupScheduleValidation(form);

            function validateStep() {
                const activeInputs = [...form.querySelectorAll('.tournament-step-page[data-form-step="' + currentStep + '"] input, .tournament-step-page[data-form-step="' + currentStep + '"] select, .tournament-step-page[data-form-step="' + currentStep + '"] textarea')].filter(input => !input.disabled && input.type !== 'hidden');
                if (currentStep === 3 && !form.querySelector('input[name="category_codes[]"]:checked')) { alert('กรุณาเลือก Category อย่างน้อย 1 ประเภท'); return false; }
                if (currentStep === 3) {
                    let categoryValid = true;
                    form.querySelectorAll('input[name="category_codes[]"]:checked').forEach(category => {
                        const code = category.value;
                        const get = name => form.querySelector(`[name="${name}[${code}]"]`);
                        const max = get('category_max_participants'); const starters = get('category_starters'); const substitutes = get('category_substitutes'); const roles = [...form.querySelectorAll(`[name^="category_required_roles[${code}]"]:checked`)].map(input => input.value); const format = get('category_format'); const groupSize = get('category_group_size'); const advance = get('category_advance');
                        if (!max || Number(max.value) <= 0) { setTimelineError(max, 'จำนวนทีม/ผู้แข่งขันสูงสุดต้องมากกว่า 0'); categoryValid = false; }
                        if (!starters || Number(starters.value) <= 0) { setTimelineError(starters, 'ผู้เล่นตัวจริงต้องมากกว่า 0'); categoryValid = false; }
                        if (!substitutes || Number(substitutes.value) < 0) { setTimelineError(substitutes, 'ตัวสำรองต้องไม่ติดลบ'); categoryValid = false; }
                        if (!roles.includes('player')) { setTimelineError(form.querySelector(`[name="category_required_roles[${code}][]"][value="player"]`)?.parentElement, 'ต้องเลือกบทบาท Player'); categoryValid = false; }
                        if (format && ['round_robin', 'group_playoff'].includes(format.value)) { if (!groupSize || Number(groupSize.value) < 2) { setTimelineError(groupSize, 'ต้องกรอกจำนวนทีมต่อกลุ่ม'); categoryValid = false; } if (!advance || Number(advance.value) < 1 || Number(advance.value) >= Number(groupSize?.value || 0)) { setTimelineError(advance, 'ทีมที่ผ่านต่อกลุ่มต้องน้อยกว่าทีมต่อกลุ่ม'); categoryValid = false; } if (Number(groupSize?.value || 0) > Number(max?.value || 0)) { setTimelineError(groupSize, 'จำนวนทีมต่อกลุ่มต้องไม่เกินจำนวนสูงสุด'); categoryValid = false; } }
                    });
                    if (!categoryValid) return false;
                }
                if (currentStep === 4 && !validateSchedule(form)) return false;
                for (const input of activeInputs) { if (!input.checkValidity()) { input.reportValidity(); return false; } }
                return true;
            }

            function renderStep() {
                form.querySelectorAll('.tournament-step-page').forEach(panel => {
                    const visible = panel.dataset.formStep === String(currentStep);
                    panel.hidden = !visible;
                    panel.querySelectorAll('input, select, textarea').forEach(input => { if (input.type !== 'hidden') input.disabled = !visible; });
                });
                if (stepper) stepper.querySelectorAll('.tournament-step').forEach(step => { const number = Number(step.dataset.step); step.classList.toggle('is-active', number === currentStep); step.classList.toggle('is-complete', number < currentStep); const circle = step.querySelector('.tournament-step-circle'); if (circle) circle.textContent = number < currentStep ? '✓' : String(number); });
                const back = footer && footer.querySelector('[data-step-back]'); const next = footer && footer.querySelector('[data-step-next]');
                if (back) back.hidden = currentStep === 1; if (next) next.hidden = currentStep === totalSteps; if (submitButton) submitButton.hidden = currentStep !== totalSteps;
                if (currentStep === totalSteps) {
                    const value = name => form.querySelector(`[name="${name}"]`)?.value || '-';
                    const categories = [...form.querySelectorAll('input[name="category_codes[]"]:checked')].map(input => input.value.toUpperCase()).join(', ') || '-';
                    summary.classList.remove('hidden');
                    const categorySummary = [...form.querySelectorAll('input[name="category_codes[]"]:checked')].map(input => { const code = input.value; const get = name => form.querySelector(`[name="${name}[${code}]"]`); const roles = [...form.querySelectorAll(`[name^="category_required_roles[${code}]"]:checked`)].map(role => role.value).join(', ') || '-'; const format = get('category_format')?.selectedOptions[0]?.textContent || '-'; return `<div class="rounded-lg bg-white border border-slate-200 p-2"><b>${escapeHtml(code.toUpperCase())}</b> · สูงสุด ${escapeHtml(get('category_max_participants')?.value || '-')} · ตัวจริง ${escapeHtml(get('category_starters')?.value || '-')} · สำรอง ${escapeHtml(get('category_substitutes')?.value || '-')} · ${escapeHtml(format)} · บทบาท ${escapeHtml(roles)}</div>`; }).join('');
                    summary.innerHTML = `<b class="block mb-2 text-sm text-slate-900">สรุปก่อนบันทึก Tournament</b><div class="grid grid-cols-1 sm:grid-cols-2 gap-2"><span><b>ชื่อ:</b> ${escapeHtml(value('name'))}</span><span><b>เกม:</b> ${escapeHtml(form.querySelector('[name="game_id"] option:checked')?.textContent || '-')}</span><span><b>Best of:</b> ${escapeHtml(value('best_of'))}</span><span><b>Category:</b> ${escapeHtml(categories)}</span><span><b>รับสมัคร:</b> ${escapeHtml(value('registration_start'))} ถึง ${escapeHtml(value('registration_end'))}</span><span><b>Lock Roster:</b> ${escapeHtml(value('roster_lock_at'))}</span><span><b>แข่งขัน:</b> ${escapeHtml(value('start_date'))} ถึง ${escapeHtml(value('end_date'))}</span><span><b>Check-in:</b> ${escapeHtml(value('checkin_open_at'))} ถึง ${escapeHtml(value('checkin_close_at'))}</span><span><b>สถานที่:</b> ${escapeHtml(value('venue_address'))}</span><span><b>แผนที่:</b> ${escapeHtml(value('venue_lat_lng'))}</span></div><div class="mt-3 space-y-2"><b class="text-slate-900">รายละเอียดแต่ละ Category</b>${categorySummary}</div>`;
                } else summary.classList.add('hidden');
            }
            form.addEventListener('submit', event => { if (currentStep !== totalSteps) { event.preventDefault(); if (validateStep()) { currentStep = totalSteps; renderStep(); } } else { form.querySelectorAll('input, select, textarea').forEach(input => input.disabled = false); form.querySelectorAll('input[name="category_codes[]"]').forEach(input => { const card = input.closest('.rounded-lg'); if (card) card.querySelectorAll('input, select, textarea').forEach(field => { if (field !== input) field.disabled = !input.checked; }); }); collectTournamentDays(type === 'create' ? 'createDays' : 'editDays', type === 'create' ? 'create_tournament_days_json' : 'edit_tournament_days_json'); } });
            renderStep();
        }

        function setupScheduleValidation(form) {
            const field = name => form.querySelector(`[name="${name}"]`);
            const start = field('start_date');
            const checkinOpen = field('checkin_open_at');
            const checkinClose = field('checkin_close_at');
            const regStart = field('registration_start');
            const regEnd = field('registration_end');
            const lock = field('roster_lock_at');
            const end = field('end_date');
            if (end && !form.querySelector('[data-single-day]')) {
                const label = document.createElement('label'); label.dataset.singleDay = '1'; label.className = 'mt-1 block text-[10px] text-slate-500'; label.innerHTML = '<input type="checkbox" data-single-day> แข่งขันวันเดียว'; end.parentElement.appendChild(label);
            }
            const singleDay = form.querySelector('[data-single-day]');
            if (singleDay) singleDay.addEventListener('change', () => { if (singleDay.checked && start && end && start.value) { end.value = start.value; end.readOnly = true; } else if (end) end.readOnly = false; refresh(); validateSchedule(form); });
            const refresh = () => {
                if (start && start.value) {
                    const date = start.value.slice(0, 10);
                    [checkinOpen, checkinClose].forEach(input => { if (input) { const time = input.value.slice(11) || '09:00'; input.value = `${date}T${time}`; input.min = `${date}T00:00`; input.max = `${date}T23:59`; } });
                }
                if (regStart && regEnd) regEnd.min = regStart.value || '';
                if (regEnd && lock) lock.min = regEnd.value || '';
                if (lock && start) lock.max = start.value || '';
                if (lock && checkinOpen) checkinOpen.min = lock.value || '';
                if (checkinOpen && checkinClose) checkinClose.min = checkinOpen.value || '';
                if (start && end) end.min = start.value || '';
                if (start && checkinClose) checkinClose.max = start.value || '';
            };
            if (start) start.addEventListener('change', refresh);
            if (start) start.addEventListener('change', () => { if (singleDay && singleDay.checked && end) end.value = start.value; refresh(); validateSchedule(form); });
            [regStart, regEnd, lock, checkinOpen, checkinClose, end].forEach(input => { if (input) input.addEventListener('change', () => { refresh(); validateSchedule(form); }); });
            refresh();
        }

        function setTimelineError(input, message) {
            if (!input) return;
            let error = input.parentElement.querySelector('.timeline-error');
            if (!error) { error = document.createElement('span'); error.className = 'timeline-error block mt-1 text-[10px] font-semibold text-rose-600'; input.parentElement.appendChild(error); }
            error.textContent = message || '';
            error.hidden = !message;
            input.classList.toggle('border-rose-500', Boolean(message));
        }

        function validateSchedule(form) {
            const field = name => form.querySelector(`[name="${name}"]`);
            const values = {};
            ['registration_start', 'registration_end', 'roster_lock_at', 'start_date', 'end_date', 'checkin_open_at', 'checkin_close_at'].forEach(name => { values[name] = field(name)?.value || ''; setTimelineError(field(name), ''); });
            let valid = true;
            const fail = (name, message) => { setTimelineError(field(name), message); valid = false; };
            if (values.registration_start && values.registration_end && values.registration_start > values.registration_end) fail('registration_end', 'วันปิดรับสมัครต้องไม่ก่อนวันเปิดรับสมัคร');
            if (values.registration_end && values.roster_lock_at && values.roster_lock_at < values.registration_end) fail('roster_lock_at', 'วัน Lock Roster ต้องไม่ก่อนวันปิดรับสมัคร');
            if (values.roster_lock_at && values.start_date && values.roster_lock_at > values.start_date) fail('roster_lock_at', 'วัน Lock Roster ต้องไม่หลังวันเริ่มแข่งขัน');
            if (values.roster_lock_at && values.checkin_open_at && values.roster_lock_at > values.checkin_open_at) fail('checkin_open_at', 'วัน Lock Roster ต้องไม่หลังเวลาเปิด Check-in');
            if (values.start_date && values.end_date && values.end_date < values.start_date) fail('end_date', 'วันสิ้นสุดการแข่งขันต้องไม่ก่อนวันเริ่มแข่งขัน');
            if (values.checkin_open_at && values.start_date && values.checkin_open_at.slice(0, 10) !== values.start_date.slice(0, 10)) fail('checkin_open_at', 'Check-in ต้องอยู่ในวันเริ่มการแข่งขัน');
            if (values.checkin_close_at && values.start_date && values.checkin_close_at.slice(0, 10) !== values.start_date.slice(0, 10)) fail('checkin_close_at', 'Check-in ต้องอยู่ในวันเริ่มการแข่งขัน');
            if (values.checkin_open_at && values.checkin_close_at && values.checkin_open_at >= values.checkin_close_at) fail('checkin_close_at', 'เวลาเปิด Check-in ต้องก่อนเวลาปิด Check-in');
            if (values.checkin_close_at && values.start_date && values.checkin_close_at > values.start_date) fail('checkin_close_at', 'เวลาปิด Check-in ต้องไม่เกินเวลาเริ่มการแข่งขัน');
            return valid;
        }

        document.addEventListener('DOMContentLoaded', prepareTournamentDayForms);

        function loadEditFormData(tournamentId) {
            fetch(`?ajax_get_tournament_form_data=${tournamentId}`)
                .then(response => response.json())
                .then(data => {
                    document.querySelectorAll('#editModal input[name="category_codes[]"]').forEach(input => { input.checked = false; });
                    (data.categories || []).forEach(category => {
                        const code = category.category_code;
                        const checkbox = document.querySelector(`#editModal input[name="category_codes[]"][value="${code}"]`);
                        if (checkbox) checkbox.checked = true;
                        const set = (field, value) => { const input = document.querySelector(`#editModal [name="${field}[${code}]"]`); if (input) input.value = value || ''; };
                        set('category_max_participants', category.max_participants);
                        set('category_starters', category.starters_count);
                        set('category_substitutes', category.substitutes_count);
                        const roles = String(category.checkin_required_roles || '').split(',').map(role => role.trim()).filter(Boolean);
                        document.querySelectorAll(`#editModal [name^="category_required_roles[${code}]"]`).forEach(input => { input.checked = roles.includes(input.value); });
                        set('category_group_size', category.group_size);
                        set('category_advance', category.teams_advance_per_group);
                        const format = document.querySelector(`#editModal [name="category_format[${code}]"]`); if (format) format.value = category.format || 'single_elimination';
                        if (checkbox) checkbox.dispatchEvent(new Event('change'));
                        if (format) format.dispatchEvent(new Event('change'));
                    });
                    const editDays = document.getElementById('editDays');
                    if (editDays) { editDays.innerHTML = ''; (data.days || []).forEach(day => addTournamentDay('editDays', day)); }
                })
                .catch(() => {});
        }

        let currentResultTid = null;
        let currentResultName = '';
        let currentIsUnder18 = false;

        function openResultModal(tournamentId, tournamentName, gameName) {
            currentResultTid = tournamentId;
            currentResultName = tournamentName;
            currentIsUnder18 = (gameName.toLowerCase().includes('ต่ำกว่า 18') || tournamentName.toLowerCase().includes('ต่ำกว่า 18'));

            document.getElementById('modalTournamentTitle').innerText = 'สรุปคะแนน: ' + tournamentName;
            
            const categoryTabContainer = document.getElementById('resultCategoryTabs');
            if (!currentIsUnder18) {
                categoryTabContainer.style.display = 'none';
            } else {
                categoryTabContainer.style.display = 'flex';
            }

            loadResultData(currentIsUnder18 ? 'all' : 'open');

            document.getElementById('resultModal').classList.remove('hidden');
            document.getElementById('resultModal').classList.add('flex');
        }

        function filterResultCategory(category) {
            ['all', 'male', 'female', 'open'].forEach(cat => {
                const btn = document.getElementById('tab_res_' + cat);
                if (btn) {
                    if (cat === category) {
                        btn.className = "px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-orange text-white shadow-sm";
                    } else {
                        btn.className = "px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200";
                    }
                }
            });
            loadResultData(category);
        }

        function loadResultData(category) {
            const contentDiv = document.getElementById('resultContent');
            contentDiv.innerHTML = '<div class="p-8 text-center text-slate-400 text-xs"><i class="fa-solid fa-spinner fa-spin text-lg mb-2"></i> กำลังโหลดข้อมูล...</div>';

            fetch(`?ajax_get_results=${currentResultTid}&category=${category}`)
                .then(res => res.text())
                .then(html => {
                    contentDiv.innerHTML = html;
                });
        }

        function closeResultModal() {
            document.getElementById('resultModal').classList.add('hidden');
            document.getElementById('resultModal').classList.remove('flex');
            document.getElementById('resultContent').innerHTML = '';
        }

        let currentRegistrationTid = null;

        function openRegistrationModal(tournamentId, tournamentName, categoryId = null) {
            currentRegistrationTid = tournamentId;
            document.getElementById('registrationModalTitle').innerText = 'ผู้สมัคร: ' + tournamentName;
            document.getElementById('registrationContent').innerHTML = '<div class="p-8 text-center text-slate-400 text-sm"><i class="fa-solid fa-spinner fa-spin text-lg mb-2"></i> กำลังโหลดข้อมูลผู้สมัคร...</div>';
            document.getElementById('registrationModal').classList.remove('hidden');
            document.getElementById('registrationModal').classList.add('flex');
            const params = new URLSearchParams({ ajax_get_registrations: tournamentId });
            if (categoryId) params.set('category_id', categoryId);
            fetch(`?${params.toString()}`)
                .then(response => response.text())
                .then(html => { document.getElementById('registrationContent').innerHTML = html; })
                .catch(() => { document.getElementById('registrationContent').innerHTML = '<div class="p-8 text-center text-rose-600 text-sm">โหลดข้อมูลไม่สำเร็จ</div>'; });
        }

        function closeRegistrationModal() {
            document.getElementById('registrationModal').classList.add('hidden');
            document.getElementById('registrationModal').classList.remove('flex');
            document.getElementById('registrationContent').innerHTML = '';
        }

        function waiveMember(registrationId, playerId) {
            document.getElementById('waiveRegistrationId').value = registrationId;
            document.getElementById('waivePlayerId').value = playerId;
            document.getElementById('waiveReason').value = '';
            document.getElementById('waiveModal').classList.remove('hidden');
            document.getElementById('waiveModal').classList.add('flex');
        }

        function closeWaiveModal() {
            document.getElementById('waiveModal').classList.add('hidden');
            document.getElementById('waiveModal').classList.remove('flex');
        }

        let pendingStatusForm = null;
        function openStatusConfirm(form, title, message) {
            pendingStatusForm = form;
            document.getElementById('statusConfirmTitle').textContent = title;
            document.getElementById('statusConfirmMessage').textContent = message;
            document.getElementById('statusConfirmModal').classList.remove('hidden');
            document.getElementById('statusConfirmModal').classList.add('flex');
            return false;
        }
        function closeStatusConfirm() {
            pendingStatusForm = null;
            document.getElementById('statusConfirmModal').classList.add('hidden');
            document.getElementById('statusConfirmModal').classList.remove('flex');
        }
        function submitStatusConfirm() {
            if (pendingStatusForm) pendingStatusForm.submit();
        }

        function openTournamentDetail(tournamentId) {
            const tournament = tournamentsList.find(item => item.tournament_id == tournamentId);
            if (!tournament) return;
            document.getElementById('detailTitle').innerText = tournament.name;
            document.getElementById('detailContent').innerHTML = `
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div><span class="text-slate-400">เกม</span><p class="font-bold">${escapeHtml(tournament.game_name || '-')}</p></div>
                    <div><span class="text-slate-400">รูปแบบ</span><p class="font-bold">${escapeHtml(tournament.format || '-')}</p></div>
                    <div><span class="text-slate-400">สถานะ</span><p class="font-bold">${escapeHtml(tournament.status || '-')}</p></div>
                    <div><span class="text-slate-400">สถานที่</span><p class="font-bold">${escapeHtml(tournament.venue_address || '-')}</p></div>
                    <div><span class="text-slate-400">รับสมัคร</span><p class="font-bold">${escapeHtml(tournament.registration_start || '-')} ถึง ${escapeHtml(tournament.registration_end || '-')}</p></div>
                    <div><span class="text-slate-400">Check-in</span><p class="font-bold">${escapeHtml(tournament.checkin_open_at || '-')} ถึง ${escapeHtml(tournament.checkin_close_at || '-')}</p></div>
                    <div><span class="text-slate-400">วันแข่งขัน</span><p class="font-bold">${escapeHtml(tournament.start_date || '-')}</p></div>
                    <div><span class="text-slate-400">สมัครทั้งหมด</span><p class="font-bold">${tournament.total_registrations || 0}</p></div>
                    <div><span class="text-slate-400">Check-in ครบ</span><p class="font-bold text-emerald-600">${tournament.checkin_complete_count || 0}</p></div>
                    <div><span class="text-slate-400">จำนวน Group</span><p class="font-bold">${tournament.group_count || 0}</p></div>
                    <div><span class="text-slate-400">Match</span><p class="font-bold">${tournament.completed_matches_count || 0}/${tournament.total_matches_count || 0}</p></div>
                    <div><span class="text-slate-400">รอบปัจจุบัน</span><p class="font-bold">${tournament.current_round || 0}</p></div>
                </div>
                <div class="mt-4 border-t border-slate-100 pt-4"><h4 class="font-bold mb-2">รายละเอียด</h4><p class="text-sm whitespace-pre-line text-slate-600">${escapeHtml(tournament.description || '-')}</p></div>
                <div class="mt-4 border-t border-slate-100 pt-4"><h4 class="font-bold mb-2">กติกา</h4><p class="text-sm whitespace-pre-line text-slate-600">${escapeHtml(tournament.rules || '-')}</p></div>`;
            document.getElementById('detailModal').classList.remove('hidden');
            document.getElementById('detailModal').classList.add('flex');
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[character]));
        }

        function closeDetailModal() {
            document.getElementById('detailModal').classList.add('hidden');
            document.getElementById('detailModal').classList.remove('flex');
        }

        function openDrawModal(tournamentId, tournamentName) {
            const tournament = tournamentsList.find(item => item.tournament_id == tournamentId);
            if (!tournament) return;
            document.getElementById('drawTitle').innerText = 'จัดสาย: ' + tournamentName;
            document.getElementById('drawSummary').innerHTML = `<div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-center"><div class="rounded-xl bg-slate-100 p-3"><b class="block text-xl">${tournament.total_registrations || 0}</b><span class="text-xs text-slate-500">สมัครทั้งหมด</span></div><div class="rounded-xl bg-emerald-50 p-3"><b class="block text-xl text-emerald-700">${tournament.team_count || 0}</b><span class="text-xs text-slate-500">อนุมัติแล้ว</span></div><div class="rounded-xl bg-sky-50 p-3"><b class="block text-xl text-sky-700">${tournament.checkin_complete_count || 0}</b><span class="text-xs text-slate-500">Check-in ครบ</span></div><div class="rounded-xl bg-amber-50 p-3"><b class="block text-xl text-amber-700">${Math.max(0, (tournament.team_count || 0) - (tournament.checkin_complete_count || 0))}</b><span class="text-xs text-slate-500">Check-in ไม่ครบ</span></div><div class="rounded-xl bg-rose-50 p-3"><b class="block text-xl text-rose-700">${tournament.disqualified_count || 0}</b><span class="text-xs text-slate-500">ตัดสิทธิ์</span></div><div class="rounded-xl bg-cyan-50 p-3"><b class="block text-xl text-cyan-700">${tournament.qualified_count || 0}</b><span class="text-xs text-slate-500">นำไปจัดสาย</span></div></div><p class="mt-4 text-xs text-slate-500">ถอนตัว: ${tournament.withdrawn_count || 0} รายการ ระบบจะนำเฉพาะ Registration ที่อนุมัติและ Check-in Required ครบเข้าสู่การจัดสาย ห้ามอนุมัติผู้สมัครอัตโนมัติ</p>`;
            document.getElementById('drawConfirmLink').href = '?close_registration=' + tournamentId;
            document.getElementById('drawModal').classList.remove('hidden');
            document.getElementById('drawModal').classList.add('flex');
        }

        function closeDrawModal() {
            document.getElementById('drawModal').classList.add('hidden');
            document.getElementById('drawModal').classList.remove('flex');
        }

        function openCategoryModal(tournamentId, tournamentName) {
            document.getElementById('categoryTitle').innerText = 'Category: ' + tournamentName;
            document.getElementById('categoryContent').innerHTML = '<div class="p-6 text-center text-slate-400 text-sm">กำลังโหลด Category...</div>';
            document.getElementById('categoryModal').classList.remove('hidden');
            document.getElementById('categoryModal').classList.add('flex');
            fetch(`?ajax_get_categories=${tournamentId}`).then(response => response.text()).then(html => { document.getElementById('categoryContent').innerHTML = html; });
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.add('hidden');
            document.getElementById('categoryModal').classList.remove('flex');
        }

        function openBracketModal(tournamentId, tournamentName) {
            document.getElementById('bracketTitle').innerText = 'Group / Bracket: ' + tournamentName;
            document.getElementById('bracketContent').innerHTML = '<div class="p-8 text-center text-slate-400 text-sm"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลดสายการแข่งขัน...</div>';
            document.getElementById('bracketModal').classList.remove('hidden');
            document.getElementById('bracketModal').classList.add('flex');
            fetch(`?ajax_get_bracket=${tournamentId}`).then(response => response.text()).then(html => { document.getElementById('bracketContent').innerHTML = html; buildBracketCategoryFilters(); });
        }

        function closeBracketModal() {
            document.getElementById('bracketModal').classList.add('hidden');
            document.getElementById('bracketModal').classList.remove('flex');
        }

        function filterBracketCards(filter, button) {
            document.querySelectorAll('#bracketFilters button').forEach(item => item.className = 'rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600');
            button.className = 'rounded-lg bg-brand-orange px-3 py-1.5 text-xs font-bold text-white';
            document.querySelectorAll('#bracketContent .bracket-card').forEach(card => {
                const categoryMatch = filter.category === 'all' || card.dataset.bracketCategory === filter.category;
                const stageMatch = filter.stage === 'all' || card.dataset.bracketStage === filter.stage;
                card.classList.toggle('hidden', !(categoryMatch && stageMatch));
            });
        }

        function buildBracketCategoryFilters() {
            const filters = document.getElementById('bracketFilters');
            if (!filters) return;
            filters.querySelectorAll('[data-category-filter]').forEach(button => button.remove());
            const categories = [...new Set([...document.querySelectorAll('#bracketContent .bracket-card')].map(card => card.dataset.bracketCategory).filter(Boolean))];
            categories.forEach(category => {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.categoryFilter = category;
                button.className = 'rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600';
                button.textContent = category;
                button.onclick = () => filterBracketCards({category: category, stage: 'all'}, button);
                filters.appendChild(button);
            });
        }
    </script>
</head>
<body class="text-slate-800 font-sans min-h-screen flex antialiased">

    <!-- SIDEBAR -->
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
            <a href="manage-tournament.php" class="nav-item active flex items-center gap-3 px-4 py-3 rounded-r-xl text-white">
                <i class="fa-solid fa-trophy w-5 text-center text-brand-orange"></i>
                <span>จัดการทัวร์นาเมนต์</span>
            </a>
            <a href="manage-teams.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-people-group w-5 text-center"></i>
                <span>จัดการทีมสมัคร</span>
            </a>
            <a href="manage-members.php" class="nav-item flex items-center gap-3 px-4 py-3 rounded-r-xl text-slate-400 hover:text-white">
                <i class="fa-solid fa-users-gear w-5 text-center"></i>
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

    <div class="flex-1 ml-64 min-h-screen flex flex-col">
        <header class="bg-white border-b border-slate-200 px-8 py-4 flex items-center justify-between sticky top-0 z-40 shadow-sm">
            <div>
                <h1 class="text-xl font-extrabold font-display text-slate-900 tracking-wide uppercase flex items-center gap-2">
                    <span class="w-2 h-6 bg-brand-orange rounded-full inline-block"></span>
                    จัดการทัวร์นาเมนต์ <span class="text-brand-orange">(TOURNAMENT MANAGEMENT)</span>
                </h1>
                <p class="text-xs text-slate-500 mt-0.5">สร้าง ปิดรับสมัคร แก้ไข และจัดตารางการแข่งขัน</p>
            </div>
            <a href="../pages/index.php" target="_blank" class="text-xs font-semibold text-slate-600 hover:text-brand-orange transition-colors flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg">
                <i class="fa-solid fa-globe"></i> หน้าหลักเว็บไซต์
            </a>
        </header>

        <main class="p-8 space-y-8 flex-1">
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

            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                <?php foreach ([['Tournament ทั้งหมด', $summaryTournaments['total_count'], 'manage-tournament.php', 'slate'], ['เปิดรับสมัคร', $summaryTournaments['registration_open_count'], '?status=registration_open', 'emerald'], ['กำลัง Check-in', $summaryTournaments['checkin_open_count'], '?status=checkin_open', 'blue'], ['พร้อมจัดสาย', $summaryTournaments['bracket_generated_count'], '?status=bracket_generated', 'sky'], ['กำลังแข่งขัน', $summaryTournaments['ongoing_count'], '?status=ongoing', 'violet'], ['แข่งขันจบแล้ว', $summaryTournaments['completed_count'], '?status=completed', 'purple']] as $summary): ?>
                    <a href="<?php echo $summary[2]; ?>" class="bg-white border border-slate-200 rounded-2xl shadow-sm p-4 hover:border-brand-orange transition-colors"><span class="block text-[11px] font-bold text-slate-500"><?php echo $summary[0]; ?></span><strong class="block mt-2 text-2xl font-display text-<?php echo $summary[3]; ?>-600"><?php echo (int) $summary[1]; ?></strong><span class="text-[10px] text-slate-400">รายการ</span></a>
                <?php endforeach; ?>
            </div>

            <form method="GET" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-9 gap-3">
                <input type="search" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="ค้นหา Tournament" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                <select name="game_id" class="rounded-xl border border-slate-200 px-3 py-2 text-sm"><option value="0">ทุกเกม</option><?php foreach ($games as $game): ?><option value="<?php echo (int) $game['game_id']; ?>" <?php echo $filterGame === (int) $game['game_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($game['name']); ?></option><?php endforeach; ?></select>
                <select name="year" class="rounded-xl border border-slate-200 px-3 py-2 text-sm"><option value="0">ทุกปี</option><?php for ($year = (int) date('Y') + 1; $year >= 2020; $year--): ?><option value="<?php echo $year; ?>" <?php echo $filterYear === $year ? 'selected' : ''; ?>>ปี <?php echo $year; ?></option><?php endfor; ?></select>
                <select name="status" class="rounded-xl border border-slate-200 px-3 py-2 text-sm"><option value="">ทุกสถานะ</option><?php foreach (['registration_open' => 'เปิดรับสมัคร', 'checkin_open' => 'กำลัง Check-in', 'bracket_generated' => 'จัดสายแล้ว', 'ongoing' => 'กำลังแข่งขัน', 'completed' => 'จบแล้ว'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $filterStatus === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?><?php if ($supportsArchivedStatus): ?><option value="archived" <?php echo $filterStatus === 'archived' ? 'selected' : ''; ?>>เก็บเข้าคลัง</option><?php endif; ?></select>
                <select name="category" class="rounded-xl border border-slate-200 px-3 py-2 text-sm"><option value="">ทุก Category</option><option value="male" <?php echo $filterCategory === 'male' ? 'selected' : ''; ?>>ชาย</option><option value="female" <?php echo $filterCategory === 'female' ? 'selected' : ''; ?>>หญิง</option><option value="open" <?php echo $filterCategory === 'open' ? 'selected' : ''; ?>>Open</option></select>
                <select name="needs_action" class="rounded-xl border border-slate-200 px-3 py-2 text-sm"><option value="">ทุกงาน</option><option value="checkin" <?php echo $filterAction === 'checkin' ? 'selected' : ''; ?>>ต้องตรวจ Check-in</option><option value="draw" <?php echo $filterAction === 'draw' ? 'selected' : ''; ?>>พร้อมจัดสาย</option></select>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="วันที่เริ่มต้น">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="วันที่สิ้นสุด">
                <button class="rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm px-4 py-2"><i class="fa-solid fa-filter mr-1"></i>กรอง</button>
            </form>

            <div class="flex items-center justify-between bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <div>
                    <h2 class="text-base font-bold font-display text-slate-900">รายการทัวร์นาเมนต์ทั้งหมด</h2>
                    <p class="text-xs text-slate-500 mt-0.5">รุ่นอายุต่ำกว่า 18 ปี แสดงผลแยกชาย/หญิง | รุ่น Open แสดงผลแบบรวม</p>
                </div>
                <button onclick="openCreateModal()" 
                    class="px-6 py-3 rounded-xl bg-brand-orange hover:bg-brand-glow text-white font-bold text-sm uppercase tracking-wider transition-all duration-200 shadow-md flex items-center gap-2 cursor-pointer">
                    <i class="fa-solid fa-circle-plus"></i>
                    <span>สร้างทัวร์นาเมนต์ใหม่</span>
                </button>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-100/70 text-xs uppercase font-bold text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="p-4">รูปภาพ</th>
                                <th class="p-4">ชื่อทัวร์นาเมนต์</th>
                                <th class="p-4">เกม</th>
                                <th class="p-4 text-center">จำนวนผู้สมัคร</th>
                                <th class="p-4 text-center">Check-in</th>
                                <th class="p-4 text-center">แมตช์แข่งขัน</th>
                                <th class="p-4 text-center">สถานะ</th>
                                <th class="p-4 text-right">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if (empty($tournaments)): ?>
                                <tr>
                                    <td colspan="8" class="p-8 text-center text-slate-400 text-xs">ยังไม่มีรายการทัวร์นาเมนต์ในระบบ</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($tournaments as $t): 
                                $isUnder18 = (stripos($t['game_name'], 'ต่ำกว่า 18') !== false || stripos($t['name'], 'ต่ำกว่า 18') !== false);
                                $canDraw = $t['status'] === 'registration_open' && (empty($t['checkin_close_at']) || strtotime($t['checkin_close_at']) <= time());
                                $canDelete = (int) $t['total_registrations'] === 0 && (int) $t['total_matches_count'] === 0 && (int) $t['tournament_days_count'] === 0 && !in_array($t['status'], ['completed', 'cancelled'], true);
                            ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="p-4">
                                    <?php if (!empty($t['image_path'])): ?>
                                        <img src="../assets/<?php echo htmlspecialchars($t['image_path']); ?>" alt="Banner" class="w-14 h-10 object-cover rounded-lg border border-slate-200 shadow-sm">
                                    <?php else: ?>
                                        <div class="w-14 h-10 rounded-lg bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-400 text-xs">
                                            <i class="fa-solid fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 font-bold text-slate-900">
                                    <?php echo htmlspecialchars($t['name']); ?>
                                    <?php if (!empty($t['prize_pool'])): ?>
                                        <span class="ml-2 text-[10px] text-amber-600 font-bold bg-amber-50 px-2 py-0.5 rounded border border-amber-200">
                                            🏆 <?php echo htmlspecialchars($t['prize_pool']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-xs">
                                    <span class="px-2.5 py-1 rounded-lg bg-slate-100 border border-slate-200 font-semibold text-slate-700">
                                        <?php echo htmlspecialchars($t['game_name'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center text-xs">
                                    <?php if ($isUnder18): ?>
                                        <div class="flex items-center justify-center gap-1.5">
                                            <span class="px-2 py-0.5 rounded bg-blue-50 text-blue-700 font-bold border border-blue-100" title="ชาย">ชาย: <?php echo $t['count_male']; ?></span>
                                            <span class="px-2 py-0.5 rounded bg-pink-50 text-pink-700 font-bold border border-pink-100" title="หญิง">หญิง: <?php echo $t['count_female']; ?></span>
                                        </div>
                                        <div class="text-[10px] text-slate-400 mt-1">รวมทั้งหมด: <b class="text-slate-700"><?php echo $t['team_count']; ?></b> / <?php echo $t['max_teams']; ?></div>
                                    <?php else: ?>
                                        <div class="flex items-center justify-center">
                                            <span class="px-2.5 py-1 rounded bg-purple-50 text-purple-700 font-bold border border-purple-100">Open: <?php echo $t['team_count']; ?> / <?php echo $t['max_teams']; ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-[10px] text-slate-400 mt-1">
                                        สมัครทั้งหมด <?php echo (int) $t['total_registrations']; ?> / รออนุมัติ <?php echo (int) $t['pending_count']; ?> / อนุมัติ <?php echo (int) $t['team_count']; ?>
                                    </div>
                                </td>
                                <td class="p-4 text-center text-xs">
                                    <?php $checkinComplete = (int) $t['checkin_complete_count']; $approvedCount = (int) $t['team_count']; $checkinClass = $checkinComplete === 0 ? 'bg-slate-100 text-slate-600 border-slate-200' : ($checkinComplete >= $approvedCount && $approvedCount > 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-amber-50 text-amber-700 border-amber-200'); ?>
                                    <span class="px-2.5 py-1 rounded border font-bold <?php echo $checkinClass; ?>"><?php echo $checkinComplete === 0 ? 'ยังไม่มีทีม Check-in ครบ' : 'Check-in ครบ ' . $checkinComplete . '/' . $approvedCount . ' ทีม'; ?></span>
                                    <?php if ((int) $t['checkin_male_count'] > 0 || (int) $t['checkin_female_count'] > 0): ?>
                                        <div class="mt-1 space-x-1 text-[10px]"><span class="text-blue-700">ชาย <?php echo (int) $t['checkin_male_count']; ?></span><span class="text-pink-700">หญิง <?php echo (int) $t['checkin_female_count']; ?></span></div>
                                    <?php endif; ?>
                                    <?php if ((int) $t['disqualified_count'] > 0): ?>
                                        <div class="text-[10px] text-rose-600 font-semibold mt-1">ตัดสิทธิ์ <?php echo (int) $t['disqualified_count']; ?> ทีม</div>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center font-bold font-display text-slate-900 text-xs">
                                    <?php if ((int) $t['total_matches_count'] === 0): ?>
                                        <span class="text-slate-400 font-sans font-semibold">ยังไม่จัดสาย</span>
                                    <?php else: ?>
                                        <span class="<?php echo (int) $t['completed_matches_count'] === (int) $t['total_matches_count'] ? 'text-emerald-600' : 'text-amber-600'; ?>"><?php echo (int) $t['completed_matches_count']; ?></span> / <?php echo (int) $t['total_matches_count']; ?> Match
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-center">
                                    <?php if ($t['status'] == 'registration_open'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold">เปิดรับสมัคร</span>
                                    <?php elseif ($t['status'] == 'ongoing'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 border border-blue-200 text-xs font-bold">กำลังแข่งขัน</span>
                                    <?php elseif ($t['status'] == 'bracket_generated'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-200 text-xs font-bold">จัดสายแล้ว รอแข่ง</span>
                                    <?php elseif ($t['status'] == 'completed'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-purple-50 text-purple-700 border border-purple-200 text-xs font-bold">แข่งจบแล้ว</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full bg-slate-100 text-slate-600 border border-slate-200 text-xs font-bold"><?php echo htmlspecialchars($t['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-4 text-right min-w-[280px]">
                                    <div class="flex flex-wrap justify-end gap-1">
                                    <button type="button" onclick="openTournamentDetail(<?php echo $t['tournament_id']; ?>)"
                                        class="inline-flex h-9 items-center gap-2 rounded-lg bg-slate-100 px-3 text-xs font-semibold text-slate-700 transition-all hover:bg-slate-200">
                                        <i class="fa-solid fa-circle-info"></i> รายละเอียด
                                    </button>
                                    <div class="relative">
                                        <button type="button" class="admin-action-toggle inline-flex h-9 items-center gap-2 rounded-lg bg-brand-orange px-3 text-xs font-semibold text-white transition-all hover:bg-brand-glow" data-action-menu="tournament-menu-<?php echo (int) $t['tournament_id']; ?>" aria-expanded="false" aria-controls="tournament-menu-<?php echo (int) $t['tournament_id']; ?>">
                                            <i class="fa-solid fa-ellipsis"></i> จัดการ
                                        </button>
                                        <div id="tournament-menu-<?php echo (int) $t['tournament_id']; ?>" class="admin-action-menu admin-action-menu-panel fixed z-[70] hidden rounded-xl border border-slate-200 bg-white text-left shadow-xl" role="menu">
                                            <div class="admin-action-group">การตั้งค่า</div>
                                    <button type="button" 
                                        onclick="openEditModal(<?php echo $t['tournament_id']; ?>)" 
                                        title="แก้ไข"
                                        class="admin-action-item text-slate-700 hover:bg-slate-50 cursor-pointer">
                                        <i class="fa-solid fa-pen-to-square text-slate-400"></i> แก้ไข Tournament
                                    </button>
                                    <button type="button" onclick="openCategoryModal(<?php echo $t['tournament_id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>')"
                                        class="admin-action-item text-slate-700 hover:bg-slate-50">
                                        <i class="fa-solid fa-sliders text-slate-400"></i> จัดการ Category
                                    </button>

                                            <div class="my-1 border-t border-slate-100"></div>
                                            <div class="admin-action-group">ผู้สมัครและข้อมูล</div>
                                    <button type="button" onclick="openRegistrationModal(<?php echo $t['tournament_id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>')"
                                        class="admin-action-item text-slate-700 hover:bg-slate-50">
                                        <i class="fa-solid fa-users text-slate-400"></i> ดูสรุปผู้สมัคร
                                    </button>
                                    <a href="manage-teams.php?tournament_id=<?php echo (int) $t['tournament_id']; ?>" class="admin-action-item text-slate-700 hover:bg-slate-50">
                                        <i class="fa-solid fa-people-group text-slate-400"></i> จัดการผู้สมัคร/ทีมแข่งขัน
                                    </a>
                                    <a href="?export_results_csv=<?php echo $t['tournament_id']; ?>" title="ส่งออก CSV"
                                        class="admin-action-item text-slate-700 hover:bg-slate-50">
                                        <i class="fa-solid fa-file-csv text-slate-400"></i> ส่งออก CSV
                                    </a>
                                            <div class="my-1 border-t border-slate-100"></div>
                                    <?php if ($canDraw): ?>
                                        <button type="button" onclick="openDrawModal(<?php echo $t['tournament_id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>')"
                                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition-all shadow-sm">
                                            <i class="fa-solid fa-sitemap"></i> จัดสายอัตโนมัติ
                                        </button>
                                    <?php elseif ($t['status'] == 'bracket_generated' && $t['format'] == 'group_playoff'): ?>
                                        <a href="?generate_playoff=<?php echo $t['tournament_id']; ?>" onclick="return confirm('ยืนยันสร้างสาย Playoff จากอันดับ Group?')"
                                           class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold transition-all shadow-sm">
                                            <i class="fa-solid fa-forward"></i> สร้างสาย Playoff
                                        </a>
                                    <?php endif; ?>
                                    <?php if (in_array($t['status'], ['bracket_generated', 'ongoing', 'completed'], true)): ?>
                                        <?php if ((int) $t['total_matches_count'] > 0): ?>
                                            <button type="button" onclick="openBracketModal(<?php echo $t['tournament_id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>')" class="w-full text-left inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-cyan-50 hover:bg-cyan-100 text-cyan-700 text-xs font-semibold"><i class="fa-solid fa-sitemap"></i> ดู Group / Bracket</button>
                                        <?php endif; ?>
                                        <button onclick="openResultModal(<?php echo $t['tournament_id']; ?>, '<?php echo htmlspecialchars(addslashes($t['name'])); ?>', '<?php echo htmlspecialchars(addslashes($t['game_name'])); ?>')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-xs font-semibold transition-all cursor-pointer">
                                            <i class="fa-solid fa-ranking-star"></i> ดูผล
                                        </button>
                                        <?php if ($t['status'] == 'ongoing'): ?>
                                            <form method="POST" onsubmit="return confirm('ยืนยันจบการแข่งขัน? ระบบจะตรวจ Match ค้างและผู้ชนะก่อน')"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="complete_tournament"><input type="hidden" name="tournament_id" value="<?php echo (int) $t['tournament_id']; ?>"><button class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-purple-50 hover:bg-purple-100 text-purple-700 border border-purple-200 text-xs font-bold transition-all"><i class="fa-solid fa-flag-checkered"></i> จบการแข่งขัน</button></form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($supportsArchivedStatus && in_array($t['status'], ['completed', 'cancelled'], true)): ?>
                                        <form method="POST" onsubmit="return openStatusConfirm(this, 'เก็บ Tournament เข้าคลัง', 'ข้อมูลจะเป็นแบบอ่านอย่างเดียว และระบบจะเก็บ Registration, Roster, Match และผลการแข่งขันทั้งหมดไว้')">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="archive_tournament"><input type="hidden" name="tournament_id" value="<?php echo (int) $t['tournament_id']; ?>">
                                            <button class="w-full text-left inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold"><i class="fa-solid fa-box-archive"></i> เก็บเข้าคลัง</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                        <form method="POST" onsubmit="return confirm('ยืนยันลบ Tournament นี้ถาวร? ใช้ได้เฉพาะรายการที่ยังไม่มีข้อมูลเกี่ยวข้อง')">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>"><input type="hidden" name="action" value="delete_tournament"><input type="hidden" name="tournament_id" value="<?php echo (int) $t['tournament_id']; ?>">
                                            <button class="w-full text-left inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-semibold"><i class="fa-solid fa-trash"></i> ลบ Tournament ถาวร</button>
                                        </form>
                                    <?php endif; ?>
                                        </div>
                                    </details>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- CREATE MODAL -->
    <div id="createModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <h3 class="text-lg font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-circle-plus text-brand-orange"></i> สร้างทัวร์นาเมนต์ใหม่
                </h3>
                <button type="button" onclick="closeCreateModal()" class="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create">

                <nav class="tournament-stepper" data-stepper="create">
                    <?php foreach ([['ข้อมูลทั่วไป', 'create-general'], ['เกมและรูปแบบ', 'create-format'], ['Category/Roster', 'create-category'], ['วันเวลาและสถานที่', 'create-schedule'], ['กติกาและสรุป', 'create-rules']] as $stepIndex => $step): ?><div class="tournament-step" data-step="<?php echo $stepIndex + 1; ?>"><div><span class="tournament-step-circle"><?php echo $stepIndex + 1; ?></span><span class="tournament-step-label"><?php echo $step[0]; ?></span></div><?php if ($stepIndex < 4): ?><span class="tournament-step-line"></span><?php endif; ?></div><?php endforeach; ?>
                </nav>

                <div id="create-general" class="tournament-step-panel" data-form-step="1">
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ชื่อทัวร์นาเมนต์</label>
                    <input type="text" name="name" required placeholder="เช่น Korat Esport Championship"
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium">
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2 mt-4">รายละเอียด Tournament</label>
                    <textarea name="description" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" placeholder="รายละเอียดโดยย่อของ Tournament"></textarea>
                </div>

                <div id="create-format" class="tournament-step-panel grid grid-cols-2 gap-4" data-form-step="2">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เกมที่ใช้แข่งขัน</label>
                        <select name="game_id" id="create_game_id" onchange="autoFillRules(this, 'create_rules')" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <option value="">-- เลือกเกมการแข่งขัน --</option>
                            <optgroup label="เกมทีม / เกมเดี่ยวจากระบบ">
                                <?php foreach ($formGames as $game): ?><option value="<?php echo (int) $game['game_id']; ?>" data-game-name="<?php echo htmlspecialchars($game['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(displayGameName($game['name'])); ?><?php echo !empty($game['play_mode']) ? ' · ' . htmlspecialchars($game['play_mode']) : ''; ?></option><?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปแบบการแข่งขัน</label>
                        <select name="format" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <option value="single_elimination">Single Elimination (แพ้คัดออก)</option>
                            <option value="double_elimination">Double Elimination (Winners / Losers)</option>
                            <option value="round_robin">Round Robin</option>
                            <option value="group_playoff">Group Stage + Knockout</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ชื่อสถานที่แข่งขัน</label><input type="text" name="venue_address" placeholder="ชื่อสนาม" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div><div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ลิงก์แผนที่</label><input type="url" name="venue_lat_lng" placeholder="https://maps.google.com/..." class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div></div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">Best of (จำนวนเกมต่อแมตช์)</label>
                    <select name="best_of" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                        <option value="3">BO3 (ชนะ 2 ใน 3 เกม)</option>
                        <option value="5" selected>BO5 (ชนะ 3 ใน 5 เกม)</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">จำนวนทีมสูงสุด</label>
                        <input type="number" name="max_teams" min="2" value="8" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เงินรางวัลรวม</label>
                        <input type="text" name="prize_pool" placeholder="เช่น 50,000 บาท" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                </div>

                <div id="create-schedule" class="tournament-step-panel grid grid-cols-1 sm:grid-cols-5 gap-4" data-form-step="4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_start" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_end" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วัน Lock Tournament Roster</label><input type="datetime-local" name="roster_lock_at" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเริ่มแข่งขัน</label>
                        <input type="datetime-local" name="start_date" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันสิ้นสุดการแข่งขัน</label><input type="datetime-local" name="end_date" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เปิด Check-in</label><input type="datetime-local" name="checkin_open_at" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ปิด Check-in</label><input type="datetime-local" name="checkin_close_at" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                </div>

                <fieldset id="create-category" class="tournament-step-panel rounded-xl border border-slate-200 p-4 space-y-3" data-form-step="3">
                    <legend class="px-2 text-xs font-bold text-slate-700">Category และกติกา Roster</legend>
                    <?php foreach ([['male', 'ชาย'], ['female', 'หญิง'], ['open', 'Open']] as $categoryOption): ?><div class="rounded-lg bg-slate-50 border border-slate-200 p-3 space-y-2"><label class="text-xs font-bold"><input type="checkbox" name="category_codes[]" value="<?php echo $categoryOption[0]; ?>" <?php echo $categoryOption[0] === 'open' ? 'checked' : ''; ?>> <?php echo $categoryOption[1]; ?></label><div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px]"><label>สูงสุด<input type="number" name="category_max_participants[<?php echo $categoryOption[0]; ?>]" min="1" placeholder="ตาม Tournament" class="w-full rounded border p-1.5"></label><label>ตัวจริง<input type="number" name="category_starters[<?php echo $categoryOption[0]; ?>]" min="0" class="w-full rounded border p-1.5"></label><label>ตัวสำรอง<input type="number" name="category_substitutes[<?php echo $categoryOption[0]; ?>]" min="0" class="w-full rounded border p-1.5"></label><label>Required Roles<input name="category_required_roles[<?php echo $categoryOption[0]; ?>]" placeholder="player,coach" class="w-full rounded border p-1.5"></label></div><div class="grid grid-cols-3 gap-2 text-[11px]"><label>ทีมต่อ Group<input type="number" name="category_group_size[<?php echo $categoryOption[0]; ?>]" min="2" class="w-full rounded border p-1.5"></label><label>ผ่านต่อ Group<input type="number" name="category_advance[<?php echo $categoryOption[0]; ?>]" min="1" class="w-full rounded border p-1.5"></label><label>รูปแบบ<select name="category_format[<?php echo $categoryOption[0]; ?>]" class="w-full rounded border p-1.5"><option value="single_elimination">Single</option><option value="double_elimination">Double</option><option value="round_robin">Round Robin</option><option value="group_playoff">Group + Knockout</option></select></label></div></div><?php endforeach; ?>
                    <p class="text-[10px] text-slate-400">ติ๊กเฉพาะ Category ที่เปิดจริง ส่วน Category ที่ไม่ติ๊กจะไม่ถูกนำไปใช้สมัครหรือจัดสาย</p>
                </fieldset>

                <div class="rounded-xl border border-slate-200 p-4 space-y-3"><div class="flex items-center justify-between"><label class="text-xs font-bold text-slate-700">ตารางแข่งขันหลายวัน</label><button type="button" onclick="addTournamentDay('createDays')" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700"><i class="fa-solid fa-plus mr-1"></i>เพิ่มวัน</button></div><div class="hidden sm:grid grid-cols-5 gap-2 text-[10px] font-bold text-slate-400"><span>วันที่</span><span>เริ่ม</span><span>สิ้นสุด</span><span>สนาม</span><span></span></div><div id="createDays" class="space-y-2"></div><textarea name="tournament_days_json" id="create_tournament_days_json" class="hidden"></textarea><p class="text-[10px] text-slate-400">กำหนดวันที่ เวลา สนาม และหมายเหตุของแต่ละวัน</p></div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">กฎระเบียบและกติกาการแข่งขัน</label>
                    <textarea name="rules" id="create_rules" rows="10" placeholder="เลือกเกมด้านบนเพื่อใส่กฎอัตโนมัติ หรือพิมพ์เพิ่มเติม..."
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium leading-relaxed"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปภาพทัวร์นาเมนต์</label>
                    <input type="file" name="tournament_image" accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-xs">
                </div>

                <div data-step-footer class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeCreateModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-xs cursor-pointer">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-orange text-white font-bold text-sm uppercase cursor-pointer">สร้างทัวร์นาเมนต์</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <h3 class="text-lg font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-brand-orange"></i> แก้ไขข้อมูลทัวร์นาเมนต์
                </h3>
                <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="tournament_id" id="edit_tournament_id">

                <nav class="tournament-stepper" data-stepper="edit">
                    <?php foreach ([['ข้อมูลทั่วไป', 'edit-general'], ['เกมและรูปแบบ', 'edit-format'], ['Category/Roster', 'edit-category'], ['วันเวลาและสถานที่', 'edit-schedule'], ['กติกาและสรุป', 'edit_rules']] as $stepIndex => $step): ?><div class="tournament-step" data-step="<?php echo $stepIndex + 1; ?>"><div><span class="tournament-step-circle"><?php echo $stepIndex + 1; ?></span><span class="tournament-step-label"><?php echo $step[0]; ?></span></div><?php if ($stepIndex < 4): ?><span class="tournament-step-line"></span><?php endif; ?></div><?php endforeach; ?>
                </nav>

                <div id="edit-general" class="tournament-step-panel" data-form-step="1">
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ชื่อทัวร์นาเมนต์</label>
                    <input type="text" name="name" id="edit_name" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2 mt-4">รายละเอียด Tournament</label>
                    <textarea name="description" id="edit_description" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></textarea>
                </div>

                <div id="edit-format" class="tournament-step-panel grid grid-cols-2 gap-4" data-form-step="2">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เกมที่ใช้แข่งขัน</label>
                        <select name="game_id" id="edit_game_id" onchange="autoFillRules(this, 'edit_rules')" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <optgroup label="เกมทีม / เกมเดี่ยวจากระบบ">
                                <?php foreach ($formGames as $game): ?><option value="<?php echo (int) $game['game_id']; ?>" data-game-name="<?php echo htmlspecialchars($game['name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(displayGameName($game['name'])); ?><?php echo !empty($game['play_mode']) ? ' · ' . htmlspecialchars($game['play_mode']) : ''; ?></option><?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปแบบการแข่งขัน</label>
                        <select name="format" id="edit_format" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                            <option value="single_elimination">Single Elimination (แพ้คัดออก)</option>
                            <option value="double_elimination">Double Elimination (Winners / Losers)</option>
                            <option value="round_robin">Round Robin</option>
                            <option value="group_playoff">Group Stage + Knockout</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ชื่อสถานที่แข่งขัน</label><input type="text" name="venue_address" id="edit_venue_address" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div><div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ลิงก์แผนที่</label><input type="url" name="venue_lat_lng" id="edit_venue_lat_lng" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"></div></div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">Best of (จำนวนเกมต่อแมตช์)</label>
                    <select name="best_of" id="edit_best_of" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                        <option value="3">BO3 (ชนะ 2 ใน 3 เกม)</option>
                        <option value="5">BO5 (ชนะ 3 ใน 5 เกม)</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">จำนวนทีมสูงสุด</label>
                        <input type="number" name="max_teams" id="edit_max_teams" min="2" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เงินรางวัลรวม</label>
                        <input type="text" name="prize_pool" id="edit_prize_pool" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                </div>

                <div id="edit-schedule" class="tournament-step-panel grid grid-cols-1 sm:grid-cols-5 gap-4" data-form-step="4">
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_start" id="edit_registration_start" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันปิดรับสมัคร</label>
                        <input type="datetime-local" name="registration_end" id="edit_registration_end" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วัน Lock Tournament Roster</label><input type="datetime-local" name="roster_lock_at" id="edit_roster_lock_at" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                    <div>
                        <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันเริ่มแข่งขัน</label>
                        <input type="datetime-local" name="start_date" id="edit_start_date" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium">
                    </div>
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">วันสิ้นสุดการแข่งขัน</label><input type="datetime-local" name="end_date" id="edit_end_date" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">เปิด Check-in</label><input type="datetime-local" name="checkin_open_at" id="edit_checkin_open_at" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                    <div><label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">ปิด Check-in</label><input type="datetime-local" name="checkin_close_at" id="edit_checkin_close_at" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-medium"></div>
                </div>

                <fieldset id="edit-category" class="tournament-step-panel rounded-xl border border-slate-200 p-4 space-y-3" data-form-step="3">
                    <legend class="px-2 text-xs font-bold text-slate-700">Category และกติกา Roster</legend>
                    <?php foreach ([['male', 'ชาย'], ['female', 'หญิง'], ['open', 'Open']] as $categoryOption): ?><div class="rounded-lg bg-slate-50 border border-slate-200 p-3 space-y-2"><label class="text-xs font-bold"><input type="checkbox" name="category_codes[]" value="<?php echo $categoryOption[0]; ?>" <?php echo $categoryOption[0] === 'open' ? 'checked' : ''; ?>> <?php echo $categoryOption[1]; ?></label><div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px]"><label>สูงสุด<input type="number" name="category_max_participants[<?php echo $categoryOption[0]; ?>]" min="1" class="w-full rounded border p-1.5"></label><label>ตัวจริง<input type="number" name="category_starters[<?php echo $categoryOption[0]; ?>]" min="0" class="w-full rounded border p-1.5"></label><label>ตัวสำรอง<input type="number" name="category_substitutes[<?php echo $categoryOption[0]; ?>]" min="0" class="w-full rounded border p-1.5"></label><label>Required Roles<input name="category_required_roles[<?php echo $categoryOption[0]; ?>]" placeholder="player,coach" class="w-full rounded border p-1.5"></label></div><div class="grid grid-cols-3 gap-2 text-[11px]"><label>ทีมต่อ Group<input type="number" name="category_group_size[<?php echo $categoryOption[0]; ?>]" min="2" class="w-full rounded border p-1.5"></label><label>ผ่านต่อ Group<input type="number" name="category_advance[<?php echo $categoryOption[0]; ?>]" min="1" class="w-full rounded border p-1.5"></label><label>รูปแบบ<select name="category_format[<?php echo $categoryOption[0]; ?>]" class="w-full rounded border p-1.5"><option value="single_elimination">Single</option><option value="double_elimination">Double</option><option value="round_robin">Round Robin</option><option value="group_playoff">Group + Knockout</option></select></label></div></div><?php endforeach; ?>
                    <p class="text-[10px] text-slate-400">ติ๊กเฉพาะ Category ที่เปิดจริง ส่วน Category ที่ไม่ติ๊กจะไม่ถูกนำไปใช้สมัครหรือจัดสาย</p>
                </fieldset>

                <div class="rounded-xl border border-slate-200 p-4 space-y-3"><div class="flex items-center justify-between"><label class="text-xs font-bold text-slate-700">ตารางแข่งขันหลายวัน</label><button type="button" onclick="addTournamentDay('editDays')" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700"><i class="fa-solid fa-plus mr-1"></i>เพิ่มวัน</button></div><div class="hidden sm:grid grid-cols-5 gap-2 text-[10px] font-bold text-slate-400"><span>วันที่</span><span>เริ่ม</span><span>สิ้นสุด</span><span>สนาม</span><span></span></div><div id="editDays" class="space-y-2"></div><textarea name="tournament_days_json" id="edit_tournament_days_json" class="hidden"></textarea><p class="text-[10px] text-slate-400">กำหนดวันที่ เวลา สนาม และหมายเหตุของแต่ละวัน</p></div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">กฎระเบียบและกติกาการแข่งขัน</label>
                    <textarea name="rules" id="edit_rules" rows="10" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-xs text-slate-900 focus:bg-white focus:outline-none focus:border-brand-orange font-medium leading-relaxed"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase text-slate-700 tracking-wider mb-2">รูปภาพทัวร์นาเมนต์ปัจจุบัน</label>
                    <div id="edit_image_preview" class="mb-2"></div>
                    <input type="file" name="tournament_image" accept="image/*" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2 text-xs">
                </div>

                <div data-step-footer class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                    <button type="button" onclick="closeEditModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-xs cursor-pointer">ยกเลิก</button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand-orange text-white font-bold text-sm uppercase cursor-pointer">บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TOURNAMENT DETAIL MODAL -->
    <div id="categoryModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-4xl w-full p-6 sm:p-8 space-y-5 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4"><h3 id="categoryTitle" class="text-lg font-bold text-slate-900">Category Configuration</h3><button type="button" onclick="closeCategoryModal()" class="text-slate-400 p-1"><i class="fa-solid fa-xmark"></i></button></div>
            <div id="categoryContent" class="space-y-3"></div>
            <div class="flex justify-end"><button type="button" onclick="closeCategoryModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold">ปิด</button></div>
        </div>
    </div>

    <div id="detailModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 sm:p-8 space-y-5 shadow-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4"><h3 id="detailTitle" class="text-lg font-bold text-slate-900">รายละเอียด Tournament</h3><button type="button" onclick="closeDetailModal()" class="text-slate-400 p-1"><i class="fa-solid fa-xmark"></i></button></div>
            <div id="detailContent"></div>
            <div class="flex justify-end"><button type="button" onclick="closeDetailModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold">ปิด</button></div>
        </div>
    </div>

    <!-- DRAW SUMMARY MODAL -->
    <div id="drawModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-lg w-full p-6 sm:p-8 space-y-5 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4"><h3 id="drawTitle" class="text-lg font-bold text-slate-900"><i class="fa-solid fa-sitemap text-brand-orange mr-2"></i>จัดสายอัตโนมัติ</h3><button type="button" onclick="closeDrawModal()" class="text-slate-400 p-1"><i class="fa-solid fa-xmark"></i></button></div>
            <div id="drawSummary"></div>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" onclick="closeDrawModal()" class="px-4 py-2.5 rounded-xl bg-slate-100 text-slate-700 text-xs font-bold">ยกเลิก</button><a id="drawConfirmLink" href="#" class="px-4 py-2.5 rounded-xl bg-brand-orange text-white text-xs font-bold" onclick="return confirm('ยืนยันปิดรับสมัครและจัดสายจากผู้ที่ผ่าน Check-in เท่านั้น?')">ยืนยันจัดสาย</a></div>
        </div>
    </div>

    <!-- REGISTRATION MODAL -->
    <div id="registrationModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-5xl w-full p-6 sm:p-8 shadow-2xl border border-slate-100 max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <h3 id="registrationModalTitle" class="text-base font-bold font-display text-slate-900 flex items-center gap-2"><i class="fa-solid fa-users text-brand-orange"></i> ผู้สมัคร Tournament</h3>
                <button type="button" onclick="closeRegistrationModal()" class="text-slate-400 hover:text-slate-600 p-1"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div id="registrationContent" class="space-y-3 overflow-y-auto py-4"></div>
            <div class="flex justify-end pt-3 border-t border-slate-100 gap-2">
                <button type="button" onclick="closeRegistrationModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-xs">ปิด</button>
            </div>
        </div>
    </div>

    <!-- CHECK-IN WAIVER MODAL -->
    <div id="waiveModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-[60] hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-5 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="font-bold text-slate-900"><i class="fa-solid fa-hand-holding-heart text-sky-600 mr-2"></i>อนุโลม Check-in</h3>
                <button type="button" onclick="closeWaiveModal()" class="text-slate-400 p-1"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p class="text-xs text-slate-500">การอนุโลมจะถูกบันทึกเป็นสถานะ Waived พร้อมเหตุผลและผู้ดำเนินการ</p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="waive_member">
                <input type="hidden" name="registration_id" id="waiveRegistrationId">
                <input type="hidden" name="player_id" id="waivePlayerId">
                <textarea name="waive_reason" id="waiveReason" required rows="4" placeholder="เหตุผลการอนุโลม" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeWaiveModal()" class="px-4 py-2 rounded-lg bg-slate-100 text-slate-700 text-xs font-bold">ยกเลิก</button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-sky-600 text-white text-xs font-bold">บันทึกการอนุโลม</button>
                </div>
            </form>
        </div>
    </div>

    <!-- RESULT MODAL -->
    <div id="statusConfirmModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-[70] hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3"><h3 id="statusConfirmTitle" class="font-bold text-slate-900">ยืนยันการเปลี่ยนสถานะ</h3><button type="button" onclick="closeStatusConfirm()" class="text-slate-400 p-1"><i class="fa-solid fa-xmark"></i></button></div>
            <p id="statusConfirmMessage" class="text-sm leading-relaxed text-slate-600"></p>
            <div class="flex justify-end gap-2 border-t border-slate-100 pt-3"><button type="button" onclick="closeStatusConfirm()" class="rounded-xl bg-slate-100 px-4 py-2 text-xs font-bold text-slate-700">ยกเลิก</button><button type="button" onclick="submitStatusConfirm()" class="rounded-xl bg-brand-orange px-4 py-2 text-xs font-bold text-white">ยืนยัน</button></div>
        </div>
    </div>

    <div id="bracketModal" class="fixed inset-0 bg-slate-900/75 backdrop-blur-sm z-50 hidden items-center justify-center p-3 sm:p-5">
        <div class="bg-white rounded-2xl w-full max-w-[95vw] max-h-[92vh] p-5 sm:p-7 space-y-4 shadow-2xl overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4"><h3 id="bracketTitle" class="text-lg font-bold font-display text-slate-900"><i class="fa-solid fa-sitemap text-brand-orange mr-2"></i>Group / Bracket</h3><button type="button" onclick="closeBracketModal()" class="text-slate-400 p-1"><i class="fa-solid fa-xmark text-lg"></i></button></div>
            <div id="bracketFilters" class="flex gap-2 overflow-x-auto border-b border-slate-100 pb-3"><button type="button" onclick="filterBracketCards({category:'all',stage:'all'}, this)" class="rounded-lg bg-brand-orange px-3 py-1.5 text-xs font-bold text-white">ทุก Category</button><button type="button" onclick="filterBracketCards({category:'all',stage:'group'}, this)" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">Group Stage</button><button type="button" onclick="filterBracketCards({category:'all',stage:'knockout'}, this)" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-600">Knockout</button></div>
            <div id="bracketContent" class="space-y-3"></div>
            <div class="flex justify-end border-t border-slate-100 pt-3"><button type="button" onclick="closeBracketModal()" class="rounded-xl bg-slate-100 px-5 py-2.5 text-xs font-bold text-slate-700">ปิด</button></div>
        </div>
    </div>

    <div id="resultModal" class="fixed inset-0 bg-slate-900/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full p-6 sm:p-8 space-y-6 shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                <h3 id="modalTournamentTitle" class="text-base font-bold font-display text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-ranking-star text-brand-orange"></i> สรุปคะแนน
                </h3>
                <button onclick="closeResultModal()" class="text-slate-400 hover:text-slate-600 p-1 cursor-pointer"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            
            <!-- TAB กรองประเภทใน Modal ผลคะแนน -->
            <div id="resultCategoryTabs" class="flex items-center gap-2 border-b border-slate-100 pb-3">
                <span class="text-xs font-bold text-slate-500 uppercase mr-2">เลือกดูประเภท:</span>
                <button onclick="filterResultCategory('all')" id="tab_res_all" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-brand-orange text-white shadow-sm">ทั้งหมด</button>
                <button onclick="filterResultCategory('male')" id="tab_res_male" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200">ชาย</button>
                <button onclick="filterResultCategory('female')" id="tab_res_female" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200">หญิง</button>
                <button onclick="filterResultCategory('open')" id="tab_res_open" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200">Open</button>
            </div>

            <div id="resultContent" class="overflow-x-auto min-h-[150px]"></div>

            <div class="flex justify-end pt-2 border-t border-slate-100">
                <button onclick="closeResultModal()" class="px-5 py-2.5 rounded-xl bg-slate-100 text-slate-700 font-semibold text-xs cursor-pointer">ปิด</button>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggles = document.querySelectorAll('.admin-action-toggle');
            const menus = document.querySelectorAll('.admin-action-menu');

            function closeMenus() {
                menus.forEach(menu => {
                    menu.classList.add('hidden');
                    const toggle = document.querySelector(`[data-action-menu="${menu.id}"]`);
                    if (toggle) toggle.setAttribute('aria-expanded', 'false');
                });
            }

            function openMenu(toggle) {
                const menu = document.getElementById(toggle.dataset.actionMenu);
                if (!menu) return;
                const shouldOpen = menu.classList.contains('hidden');
                closeMenus();
                if (!shouldOpen) return;
                document.body.appendChild(menu);
                menu.classList.remove('hidden');
                const buttonRect = toggle.getBoundingClientRect();
                const menuWidth = menu.offsetWidth || 224;
                const menuHeight = menu.offsetHeight || 320;
                const left = Math.max(8, Math.min(buttonRect.right - menuWidth, window.innerWidth - menuWidth - 8));
                const top = buttonRect.bottom + 8 + menuHeight <= window.innerHeight - 8
                    ? buttonRect.bottom + 8
                    : Math.max(8, buttonRect.top - menuHeight - 8);
                menu.style.left = `${left}px`;
                menu.style.top = `${top}px`;
                toggle.setAttribute('aria-expanded', 'true');
            }

            toggles.forEach(toggle => {
                toggle.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopPropagation();
                    openMenu(toggle);
                });
                toggle.addEventListener('keydown', event => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    event.stopPropagation();
                    openMenu(toggle);
                });
            });
            menus.forEach(menu => menu.addEventListener('click', event => event.stopPropagation()));
            document.addEventListener('click', closeMenus);
            document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenus(); });
            window.addEventListener('resize', closeMenus);
            window.addEventListener('scroll', closeMenus, true);
        });
    </script>
</body>
</html>