-- Read-only verification after reset-and-seed-test-data.sql and seed-test-users.php.

SELECT DATABASE() AS active_database;

SELECT user_id, username, email, role, status
FROM users
WHERE role = 'admin'
ORDER BY user_id;

SELECT COUNT(*) AS athlete_account_count
FROM users
WHERE role = 'athlete'
  AND username REGEXP '^athlete(0[1-9]|[1-3][0-9]|40)$'
  AND email REGEXP '^athlete(0[1-9]|[1-3][0-9]|40)@test\\.local$';

SELECT COUNT(*) AS athlete_profile_count
FROM players p
JOIN users u ON u.user_id = p.user_id
WHERE u.role = 'athlete'
  AND u.username REGEXP '^athlete(0[1-9]|[1-3][0-9]|40)$';

SELECT COUNT(*) AS seed_team_count
FROM teams
WHERE name REGEXP '^Team (0[1-8])$';

SELECT t.name, COUNT(tm.team_member_id) AS member_count,
       SUM(tm.player_id = t.captain_player_id) AS captain_member_count
FROM teams t
LEFT JOIN team_members tm ON tm.team_id = t.team_id AND tm.is_active = 1
WHERE t.name REGEXP '^Team (0[1-8])$'
GROUP BY t.team_id, t.name, t.captain_player_id
ORDER BY t.name;

SELECT COUNT(*) AS seed_team_member_count
FROM team_members tm
JOIN teams t ON t.team_id = tm.team_id
JOIN players p ON p.player_id = tm.player_id
JOIN users u ON u.user_id = p.user_id
WHERE t.name REGEXP '^Team (0[1-8])$'
  AND u.username REGEXP '^athlete(0[1-9]|[1-3][0-9]|40)$'
  AND tm.is_active = 1;

SELECT u.username, p.player_id, t.name AS team_name,
       CASE WHEN p.player_id = t.captain_player_id THEN 'captain' ELSE 'player' END AS expected_role
FROM users u
JOIN players p ON p.user_id = u.user_id
LEFT JOIN team_members tm ON tm.player_id = p.player_id AND tm.is_active = 1
LEFT JOIN teams t ON t.team_id = tm.team_id
WHERE u.username IN ('athlete01','athlete06','athlete11','athlete16','athlete21','athlete26','athlete31','athlete36')
ORDER BY u.username;

SELECT p.player_id, p.user_id
FROM players p
LEFT JOIN users u ON u.user_id = p.user_id
WHERE u.user_id IS NULL;

SELECT tm.team_member_id, tm.team_id, tm.player_id
FROM team_members tm
LEFT JOIN teams t ON t.team_id = tm.team_id
LEFT JOIN players p ON p.player_id = tm.player_id
WHERE t.team_id IS NULL OR p.player_id IS NULL;

SELECT t.name, COUNT(tm.team_member_id) AS duplicate_members
FROM teams t
JOIN team_members tm ON tm.team_id = t.team_id AND tm.is_active = 1
WHERE t.name REGEXP '^Team (0[1-8])$'
GROUP BY t.team_id, t.name
HAVING COUNT(DISTINCT tm.player_id) <> COUNT(tm.player_id);

SELECT 'Admin preserved' AS check_name, COUNT(*) AS result
FROM users
WHERE role = 'admin';
SELECT 'Athlete seed count' AS check_name, COUNT(*) AS result
FROM users
WHERE username REGEXP '^athlete(0[1-9]|[1-3][0-9]|40)$';
SELECT 'Profile seed count' AS check_name, COUNT(*) AS result
FROM players p JOIN users u ON u.user_id = p.user_id
WHERE u.username REGEXP '^athlete(0[1-9]|[1-3][0-9]|40)$';
SELECT 'Team seed count' AS check_name, COUNT(*) AS result
FROM teams WHERE name REGEXP '^Team (0[1-8])$';
SELECT 'Team member seed count' AS check_name, COUNT(*) AS result
FROM team_members tm JOIN teams t ON t.team_id = tm.team_id
WHERE t.name REGEXP '^Team (0[1-8])$' AND tm.is_active = 1;

SELECT 'Match participant orphans' AS check_name, COUNT(*) AS result
FROM match_participants mp
LEFT JOIN matches m ON m.match_id = mp.match_id
WHERE m.match_id IS NULL;
