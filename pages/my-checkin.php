<?php
// pages/my-checkin.php
// หน้าให้กัปตันทีมดู QR code ของทีมตัวเอง เอาไปโชว์ตอนเช็คอินหน้างาน
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/tournament_roster.php';
require_once '../includes/tournament_categories.php';
require_once '../includes/tournament_workflow.php';
requireLogin();
ensureTournamentRosterTables($pdo);
ensureTournamentCategorySchema($pdo);

$stmt = $pdo->prepare("SELECT player_id FROM players WHERE user_id = :user_id");
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$myPlayerId = $stmt->fetchColumn();

if (!$myPlayerId) {
    header('Location: claim-profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'player_checkin') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $verify = $pdo->prepare('SELECT tr.tournament_registration_id,
                COALESCE(tc.checkin_open_at, tour.checkin_open_at) AS checkin_open_at,
                COALESCE(tc.checkin_deadline, tour.checkin_close_at) AS checkin_close_at
            FROM tournament_registration_members trm
            JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
            JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
            LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
            WHERE trm.tournament_registration_id = :registration_id AND trm.player_id = :player_id
              AND trm.roster_status = \'active\' AND tr.status = \'approved\'
                            AND tr.participation_status NOT IN (\'withdrawn\', \'disqualified\')');
        $verify->execute(['registration_id' => $registrationId, 'player_id' => $myPlayerId]);
        $verifiedRegistration = $verify->fetch();
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
        $checkinWindowOpen = $verifiedRegistration && $verifiedRegistration['checkin_open_at'] && $verifiedRegistration['checkin_close_at']
            && $now >= new DateTimeImmutable($verifiedRegistration['checkin_open_at'], new DateTimeZone('Asia/Bangkok'))
            && $now <= new DateTimeImmutable($verifiedRegistration['checkin_close_at'], new DateTimeZone('Asia/Bangkok'));
        if (!$verifiedRegistration) {
            $error = 'คุณไม่มีสิทธิ์เช็คอินในรายการนี้';
        } elseif (!$verifiedRegistration['checkin_open_at'] || !$verifiedRegistration['checkin_close_at']) {
            $error = 'ยังไม่ได้กำหนดเวลาเช็กอิน';
        } elseif (!$checkinWindowOpen || !canCheckinRegistration($pdo, $registrationId, $now)) {
            $error = $now < new DateTimeImmutable($verifiedRegistration['checkin_open_at'], new DateTimeZone('Asia/Bangkok')) ? 'ยังไม่เปิดเช็กอิน' : 'ขณะนี้อยู่นอกช่วงเวลาเช็กอิน';
        } else {
            markRosterPlayerCheckedIn($pdo, $registrationId, (int) $myPlayerId, (int) $_SESSION['user_id']);
            $success = 'เช็คอินเรียบร้อยแล้ว';
        }
    }
}

// แสดงเฉพาะ Tournament Roster ที่ผู้เล่นคนนี้มีสิทธิ์ Check-in
$stmt = $pdo->prepare("SELECT tr.tournament_registration_id, tr.qr_code_token, tr.checkin_status, tr.checkin_at,
           tr.category, tour.name AS tournament_name, tour.venue_address, tour.venue_lat_lng,
           COALESCE(tc.checkin_open_at, tour.checkin_open_at) AS checkin_open_at,
           COALESCE(tc.checkin_deadline, tour.checkin_close_at) AS checkin_close_at,
           COALESCE(t.name, 'การแข่งขันเดี่ยว') AS team_name,
           trm.checkin_status AS player_checkin_status,
           trm.checkin_at AS player_checkin_at,
           (SELECT COUNT(*) FROM tournament_registration_members req
            WHERE req.tournament_registration_id = tr.tournament_registration_id AND req.is_required_for_checkin = 1) AS required_count,
           (SELECT COUNT(*) FROM tournament_registration_members req
            WHERE req.tournament_registration_id = tr.tournament_registration_id AND req.is_required_for_checkin = 1 AND req.checkin_status IN ('checked_in', 'waived')) AS checked_count
    FROM tournament_registration_members trm
    JOIN tournament_registrations tr ON tr.tournament_registration_id = trm.tournament_registration_id
    JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
    LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
    LEFT JOIN teams t ON t.team_id = tr.team_id
    LEFT JOIN player_tournament_checkins ptc ON ptc.tournament_registration_id = trm.tournament_registration_id
        AND ptc.player_id = trm.player_id
    WHERE trm.player_id = :pid AND trm.roster_status = 'active' AND tr.status = 'approved'
    ORDER BY tour.created_at DESC");
$stmt->execute(['pid' => $myPlayerId]);
$checkins = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    setFlashMessage($error ? 'error' : 'success', $error ?: ($success ?? 'ดำเนินการเรียบร้อยแล้ว'));
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? 'my-checkin.php'), true, 303);
    exit;
}
$flash = consumeFlashMessage();
$flashAlert = renderFlashAlert($flash ?: ($error
    ? ['type' => 'error', 'message' => $error]
    : ($success ? ['type' => 'success', 'message' => $success] : null)));
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { orange: '#FF5500', glow: '#FF7700', cyber: '#00F0FF', dark: '#0A0A0C', panel: '#121318' } }, fontFamily: { sans: ['Kanit', 'sans-serif'], display: ['Orbitron', 'sans-serif'], mono: ['Share Tech Mono', 'monospace'] } } } };
    </script>    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เช็กอินของฉัน - Korat Esport</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/public_nav.php'; ?>

    <section class="content">
        <h1>เช็กอินของฉัน</h1>
        <p>เช็กอินตามไลน์อัปการแข่งขันของคุณ</p>

        <?php echo $flashAlert; ?>

        <?php if (count($checkins) == 0): ?>
            <p>ยังไม่มีทัวร์นาเมนต์ที่ทีมของคุณได้รับการอนุมัติเข้าร่วม</p>
        <?php endif; ?>

        <div class="card-grid">
            <?php foreach ($checkins as $c): ?>
                <div class="card">
                    <h3><?php echo htmlspecialchars($c['tournament_name']); ?></h3>
                    <p>ทีม: <?php echo htmlspecialchars($c['team_name']); ?></p>
                    <p>Category: <strong><?php echo htmlspecialchars(strtoupper($c['category'] ?: 'open')); ?></strong></p>

                    <?php if (in_array($c['player_checkin_status'], ['checked_in', 'waived'], true)): ?>
                        <span class="badge">เช็คอินของคุณแล้ว ✓</span>
                    <?php else: ?>
                        <?php $checkinNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')); $canPlayerCheckin = $c['checkin_open_at'] && $c['checkin_close_at'] && $checkinNow >= new DateTimeImmutable($c['checkin_open_at'], new DateTimeZone('Asia/Bangkok')) && $checkinNow <= new DateTimeImmutable($c['checkin_close_at'], new DateTimeZone('Asia/Bangkok')); ?>
                        <?php if ($canPlayerCheckin): ?>
                            <form method="POST" style="margin:0.8rem 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="player_checkin">
                                <input type="hidden" name="registration_id" value="<?php echo (int) $c['tournament_registration_id']; ?>">
                                <button type="submit">เช็กอิน</button>
                            </form>
                        <?php else: ?>
                            <span class="badge"><?php echo (!$c['checkin_open_at'] || !$c['checkin_close_at']) ? 'ยังไม่ได้กำหนดเวลาเช็กอิน' : ($checkinNow < new DateTimeImmutable($c['checkin_open_at'], new DateTimeZone('Asia/Bangkok')) ? 'ยังไม่เปิดเช็กอิน' : 'ปิดเช็กอินแล้ว'); ?></span>
                        <?php endif; ?>
                        <span class="badge">ยังไม่เช็กอิน</span>
                    <?php endif; ?>

                    <?php if ($c['player_checkin_at']): ?>
                        <p>เวลาเช็คอิน: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($c['player_checkin_at']))); ?></p>
                    <?php endif; ?>

                    <p>สถานะรายชื่อ: <strong><?php echo (int) $c['checked_count']; ?>/<?php echo (int) $c['required_count']; ?></strong>
                        <?php if ((int) $c['required_count'] > 0 && (int) $c['checked_count'] >= (int) $c['required_count']): ?>
                            <span style="color:#15803d;">✓ เช็กอินครบ</span>
                        <?php elseif ((int) $c['checked_count'] > 0): ?>
                            <span style="color:#b45309;">— เช็กอินไม่ครบ</span>
                        <?php else: ?>
                            <span style="color:#64748b;">— ยังไม่มีใครเช็กอิน</span>
                        <?php endif; ?>
                    </p>

                    <?php
                        $rosterStmt = $pdo->prepare('SELECT trm.player_id, trm.is_required_for_checkin, trm.member_roles,
                                trm.checkin_status, p.display_name, u.username
                            FROM tournament_registration_members trm
                            JOIN players p ON p.player_id = trm.player_id
                            LEFT JOIN users u ON u.user_id = p.user_id
                            WHERE trm.tournament_registration_id = :registration_id
                            ORDER BY trm.is_required_for_checkin DESC, trm.is_starter DESC, u.username');
                        $rosterStmt->execute(['registration_id' => $c['tournament_registration_id']]);
                        $roster = $rosterStmt->fetchAll();
                    ?>
                    <?php if ($roster): ?>
                        <div style="margin-top:0.8rem; border-top:1px solid #e2e8f0; padding-top:0.6rem;">
                            <strong>สมาชิกในไลน์อัปการแข่งขัน</strong>
                            <?php foreach ($roster as $member): ?>
                                <div style="display:flex; justify-content:space-between; gap:0.5rem; margin-top:0.35rem; font-size:0.9rem;">
                                    <span><?php echo htmlspecialchars($member['display_name'] ?: $member['username']); ?><?php echo $member['is_required_for_checkin'] ? ' *' : ''; ?></span>
                                    <span style="color:<?php echo in_array($member['checkin_status'], ['checked_in', 'waived'], true) ? '#15803d' : '#b91c1c'; ?>;">
                                        <?php echo in_array($member['checkin_status'], ['checked_in', 'waived'], true) ? ($member['checkin_status'] === 'waived' ? 'อนุโลมแล้ว' : 'เช็กอินแล้ว') : 'ยังไม่เช็กอิน'; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                            <small>* ผู้ที่ต้องเช็กอินตามกติกา</small>
                        </div>
                    <?php endif; ?>

                    <?php if ($c['checkin_open_at'] || $c['checkin_close_at']): ?>
                        <p>ช่วงเวลาเช็กอิน: <?php echo $c['checkin_open_at'] ? date('d/m/Y H:i', strtotime($c['checkin_open_at'])) : 'ไม่กำหนด'; ?> - <?php echo $c['checkin_close_at'] ? date('d/m/Y H:i', strtotime($c['checkin_close_at'])) : 'ไม่กำหนด'; ?></p>
                    <?php endif; ?>

                    <?php if ($c['venue_address']): ?>
                        <p style="margin-top:0.6rem;">สถานที่แข่งขัน: <?php echo htmlspecialchars($c['venue_address']); ?></p>
                    <?php endif; ?>
                    <?php if ($c['venue_lat_lng']): ?>
                        <p><a href="https://www.google.com/maps?q=<?php echo urlencode($c['venue_lat_lng']); ?>" target="_blank" rel="noopener">เปิดแผนที่</a></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<script src="../assets/js/flash-messages.js" defer></script>
</body>
</html>
