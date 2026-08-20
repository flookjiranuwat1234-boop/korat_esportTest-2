<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
requireRole('admin');

$teamId = (int) ($_GET['id'] ?? 0);
$error = '';
$success = '';
$csrfToken = generateCsrfToken();
$allowedRoles = ['manager', 'coach', 'player', 'substitute'];
$allowedStatuses = ['active', 'inactive', 'suspended', 'disbanded'];

if ($teamId <= 0) { header('Location: manage-members.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'คำขอไม่ถูกต้อง กรุณาลองใหม่';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'update_team') {
                $name = trim($_POST['name'] ?? '');
                $tag = trim($_POST['tag'] ?? '');
                $gameId = (int) ($_POST['game_id'] ?? 0);
                $status = $_POST['status'] ?? 'active';
                $reason = trim($_POST['status_reason'] ?? '');
                if ($name === '' || !in_array($status, $allowedStatuses, true)) throw new RuntimeException('ข้อมูลทีมไม่ถูกต้อง');
                $pdo->prepare('UPDATE teams SET name=:name, tag=:tag, game_id=:game_id, status=:status,
                    status_reason=:reason, status_changed_at=NOW(), status_changed_by=:admin WHERE team_id=:id')
                    ->execute(['name'=>$name,'tag'=>$tag ?: null,'game_id'=>$gameId ?: null,'status'=>$status,
                        'reason'=>$reason ?: null,'admin'=>$_SESSION['user_id'],'id'=>$teamId]);
                $success = 'บันทึกข้อมูลทีมเรียบร้อยแล้ว';
            } elseif ($action === 'add_member') {
                $playerId = (int) ($_POST['player_id'] ?? 0);
                $roles = array_values(array_intersect($allowedRoles, $_POST['member_roles'] ?? []));
                if (!$playerId || !$roles) throw new RuntimeException('กรุณาเลือกนักกีฬาและบทบาทอย่างน้อย 1 บทบาท');
                $stmt = $pdo->prepare('SELECT team_member_id FROM team_members WHERE team_id=:team_id AND player_id=:player_id');
                $stmt->execute(['team_id'=>$teamId,'player_id'=>$playerId]);
                $existingId = $stmt->fetchColumn();
                if ($existingId) {
                    $pdo->prepare('UPDATE team_members SET member_roles=:roles, is_active=1, left_at=NULL WHERE team_member_id=:id')
                        ->execute(['roles'=>implode(',', $roles),'id'=>$existingId]);
                } else {
                    $pdo->prepare('INSERT INTO team_members (team_id,player_id,member_roles,is_active) VALUES (:team_id,:player_id,:roles,1)')
                        ->execute(['team_id'=>$teamId,'player_id'=>$playerId,'roles'=>implode(',', $roles)]);
                }
                $success = 'เพิ่มสมาชิกเข้าทีมเรียบร้อยแล้ว';
            } elseif ($action === 'update_member') {
                $teamMemberId = (int) ($_POST['team_member_id'] ?? 0);
                $roles = array_values(array_intersect($allowedRoles, $_POST['member_roles'] ?? []));
                if (!$roles) throw new RuntimeException('ต้องมีบทบาทอย่างน้อย 1 บทบาท');
                $pdo->prepare('UPDATE team_members SET member_roles=:roles WHERE team_member_id=:id AND team_id=:team_id')
                    ->execute(['roles'=>implode(',', $roles),'id'=>$teamMemberId,'team_id'=>$teamId]);
                $success = 'แก้ไขบทบาทสมาชิกเรียบร้อยแล้ว';
            } elseif ($action === 'remove_member') {
                $teamMemberId = (int) ($_POST['team_member_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT player_id FROM team_members WHERE team_member_id=:id AND team_id=:team_id');
                $stmt->execute(['id'=>$teamMemberId,'team_id'=>$teamId]);
                $playerId = (int) $stmt->fetchColumn();
                $captainStmt = $pdo->prepare('SELECT captain_player_id FROM teams WHERE team_id=:id');
                $captainStmt->execute(['id'=>$teamId]);
                if ($playerId && $playerId === (int)$captainStmt->fetchColumn()) throw new RuntimeException('ต้องแต่งตั้งกัปตันคนใหม่ก่อนนำกัปตันปัจจุบันออก');
                $pdo->prepare('UPDATE team_members SET is_active=0, left_at=NOW() WHERE team_member_id=:id AND team_id=:team_id')
                    ->execute(['id'=>$teamMemberId,'team_id'=>$teamId]);
                $success = 'นำสมาชิกออกจากทีมแล้ว โดยยังเก็บประวัติไว้';
            } elseif ($action === 'set_captain') {
                $playerId = (int) ($_POST['player_id'] ?? 0);
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE team_id=:team_id AND player_id=:player_id AND is_active=1');
                $stmt->execute(['team_id'=>$teamId,'player_id'=>$playerId]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('กัปตันต้องเป็นสมาชิกปัจจุบันของทีม');
                $pdo->prepare('UPDATE teams SET captain_player_id=:player_id WHERE team_id=:team_id')
                    ->execute(['player_id'=>$playerId,'team_id'=>$teamId]);
                $success = 'แต่งตั้งกัปตันคนใหม่เรียบร้อยแล้ว';
            }
        } catch (Throwable $e) { $error = $e->getMessage() ?: 'ไม่สามารถบันทึกข้อมูลได้'; }
    }
}

$stmt = $pdo->prepare('SELECT t.*, g.name AS game_name, p.display_name AS captain_name FROM teams t
    LEFT JOIN games g ON g.game_id=t.game_id LEFT JOIN players p ON p.player_id=t.captain_player_id WHERE t.team_id=:id');
$stmt->execute(['id'=>$teamId]);
$team = $stmt->fetch();
if (!$team) { http_response_code(404); exit('ไม่พบทีม'); }

$games = $pdo->query('SELECT game_id,name FROM games WHERE is_active=1 ORDER BY name')->fetchAll();
$stmt = $pdo->prepare('SELECT tm.*, p.display_name,p.real_name,p.user_id,u.email,
    CASE WHEN t.captain_player_id=p.player_id THEN 1 ELSE 0 END AS is_captain
    FROM team_members tm JOIN players p ON p.player_id=tm.player_id LEFT JOIN users u ON u.user_id=p.user_id
    JOIN teams t ON t.team_id=tm.team_id WHERE tm.team_id=:id ORDER BY tm.is_active DESC,is_captain DESC,tm.joined_at');
$stmt->execute(['id'=>$teamId]); $members=$stmt->fetchAll();
$availableStmt=$pdo->prepare('SELECT p.player_id,p.display_name,p.real_name FROM players p WHERE NOT EXISTS
    (SELECT 1 FROM team_members tm WHERE tm.team_id=:team_id AND tm.player_id=p.player_id AND tm.is_active=1) ORDER BY p.display_name LIMIT 300');
$availableStmt->execute(['team_id'=>$teamId]); $availablePlayers=$availableStmt->fetchAll();
$regStmt=$pdo->prepare('SELECT tr.*,tn.name AS tournament_name,tc.name AS category_name FROM tournament_registrations tr
    JOIN tournaments tn ON tn.tournament_id=tr.tournament_id LEFT JOIN tournament_categories tc ON tc.tournament_category_id=tr.tournament_category_id
    WHERE tr.team_id=:team_id ORDER BY tr.registered_at DESC');
$regStmt->execute(['team_id'=>$teamId]); $registrations=$regStmt->fetchAll();
function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>จัดการทีม - Korat Esport</title><script src="https://cdn.tailwindcss.com"></script><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"></head>
<body class="bg-slate-100 text-slate-800"><main class="max-w-7xl mx-auto p-6 space-y-6">
<header class="flex justify-between gap-4"><div><a href="manage-members.php" class="text-orange-600 text-sm hover:underline"><i class="fa-solid fa-arrow-left"></i> กลับหน้าสมาชิก</a><h1 class="text-2xl font-bold mt-2"><?=h($team['name'])?></h1><p class="text-sm text-slate-500">จัดการทีมระดับระบบ ไม่แก้ Tournament Roster ที่ล็อกไว้แล้ว</p></div><a target="_blank" href="../pages/team-profile.php?id=<?=$teamId?>" class="h-fit px-4 py-2 rounded-lg bg-slate-800 text-white">ดูหน้าทีม</a></header>
<?php if($error):?><div class="p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg"><?=h($error)?></div><?php endif;?><?php if($success):?><div class="p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg"><?=h($success)?></div><?php endif;?>
<section class="bg-white p-6 border rounded-2xl shadow-sm"><h2 class="font-bold text-lg mb-4">ข้อมูลทั่วไปและสถานะทีม</h2><form method="post" class="grid md:grid-cols-3 gap-4"><input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>"><input type="hidden" name="action" value="update_team">
<label class="text-sm">ชื่อทีม<input required name="name" value="<?=h($team['name'])?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label><label class="text-sm">Tag<input name="tag" maxlength="10" value="<?=h($team['tag'])?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
<label class="text-sm">เกม<select name="game_id" class="mt-1 w-full border rounded-lg px-3 py-2"><option value="">ไม่ระบุ</option><?php foreach($games as $g):?><option value="<?=$g['game_id']?>" <?=$team['game_id']==$g['game_id']?'selected':''?>><?=h($g['name'])?></option><?php endforeach;?></select></label>
<label class="text-sm">สถานะ<select name="status" class="mt-1 w-full border rounded-lg px-3 py-2"><?php foreach($allowedStatuses as $s):?><option value="<?=$s?>" <?=$team['status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select></label><label class="text-sm md:col-span-2">เหตุผล<input name="status_reason" value="<?=h($team['status_reason'])?>" class="mt-1 w-full border rounded-lg px-3 py-2"></label><div class="md:col-span-3"><button class="px-5 py-2 bg-orange-600 text-white rounded-lg font-semibold">บันทึกข้อมูลทีม</button></div></form></section>
<section class="bg-white border rounded-2xl shadow-sm overflow-hidden"><div class="p-5 border-b flex justify-between"><div><h2 class="font-bold text-lg">สมาชิกทีม</h2><p class="text-xs text-slate-500">หนึ่งคนเลือกได้หลายบทบาท</p></div><span class="text-sm">กัปตัน: <strong><?=h($team['captain_name']?:'-')?></strong></span></div>
<div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="p-3 text-left">สมาชิก</th><th class="p-3 text-left">บทบาท</th><th class="p-3">สถานะ</th><th class="p-3 text-right">จัดการ</th></tr></thead><tbody><?php foreach($members as $m):?><tr class="border-t <?=$m['is_active']?'':'opacity-60'?>"><td class="p-3"><a href="member-detail.php?id=<?=(int)$m['user_id']?>" class="font-semibold text-orange-600 hover:underline"><?=h($m['display_name'])?></a><div class="text-xs text-slate-400"><?=h($m['real_name']?:$m['email'])?> <?=$m['is_captain']?'· Captain':''?></div></td><td class="p-3"><form method="post" class="flex flex-wrap items-center gap-2"><input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>"><input type="hidden" name="action" value="update_member"><input type="hidden" name="team_member_id" value="<?=$m['team_member_id']?>"><?php foreach($allowedRoles as $role):?><label class="text-xs"><input type="checkbox" name="member_roles[]" value="<?=$role?>" <?=in_array($role,explode(',',$m['member_roles']),true)?'checked':''?>> <?=$role?></label><?php endforeach;?><?php if($m['is_active']):?><button class="px-2 py-1 bg-blue-50 text-blue-700 rounded">บันทึก</button><?php endif;?></form></td><td class="p-3 text-center"><?=$m['is_active']?'ปัจจุบัน':'ออกแล้ว'?></td><td class="p-3 text-right whitespace-nowrap"><?php if($m['is_active']&&!$m['is_captain']):?><form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>"><input type="hidden" name="action" value="set_captain"><input type="hidden" name="player_id" value="<?=$m['player_id']?>"><button class="px-2 py-1 bg-amber-50 text-amber-700 rounded">ตั้งกัปตัน</button></form> <form method="post" class="inline" onsubmit="return confirm('นำสมาชิกออกจากทีมโดยเก็บประวัติไว้?')"><input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>"><input type="hidden" name="action" value="remove_member"><input type="hidden" name="team_member_id" value="<?=$m['team_member_id']?>"><button class="px-2 py-1 bg-red-50 text-red-700 rounded">นำออก</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<form method="post" class="p-5 border-t grid md:grid-cols-3 gap-3"><input type="hidden" name="csrf_token" value="<?=h($csrfToken)?>"><input type="hidden" name="action" value="add_member"><select required name="player_id" class="border rounded-lg px-3 py-2"><option value="">เลือกนักกีฬา</option><?php foreach($availablePlayers as $p):?><option value="<?=$p['player_id']?>"><?=h($p['display_name'])?><?= $p['real_name']?' ('.h($p['real_name']).')':''?></option><?php endforeach;?></select><div class="flex flex-wrap gap-3 items-center"><?php foreach($allowedRoles as $role):?><label class="text-xs"><input type="checkbox" name="member_roles[]" value="<?=$role?>" <?=$role==='player'?'checked':''?>> <?=$role?></label><?php endforeach;?></div><button class="bg-orange-600 text-white rounded-lg px-4 py-2">เพิ่มสมาชิก</button></form></section>
<section class="bg-white border rounded-2xl shadow-sm overflow-hidden"><div class="p-5 border-b"><h2 class="font-bold text-lg">รายการสมัคร Tournament</h2><p class="text-xs text-slate-500">เป็นข้อมูล Registration แยกจากสมาชิกทีมปัจจุบัน</p></div><table class="w-full text-sm"><thead class="bg-slate-50"><tr><th class="p-3 text-left">Tournament</th><th class="p-3">ประเภท</th><th class="p-3">อนุมัติ</th><th class="p-3">สถานะร่วมแข่งขัน</th><th class="p-3">วันที่สมัคร</th></tr></thead><tbody><?php foreach($registrations as $r):?><tr class="border-t"><td class="p-3 font-semibold"><?=h($r['tournament_name'])?></td><td class="p-3 text-center"><?=h($r['category_name']?:$r['category'])?></td><td class="p-3 text-center"><?=h($r['status'])?></td><td class="p-3 text-center"><?=h($r['participation_status'])?></td><td class="p-3 text-center"><?=h($r['registered_at'])?></td></tr><?php endforeach;?><?php if(!$registrations):?><tr><td colspan="5" class="p-6 text-center text-slate-400">ทีมนี้ยังไม่มีรายการสมัคร Tournament</td></tr><?php endif;?></tbody></table></section>
</main></body></html>
