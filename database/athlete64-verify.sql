-- athlete64-verify.sql
-- Batch: ATHLETE64_ROBLOX50_20260829
-- การตรวจสอบข้อมูลทั้งหมด

USE esport_korattest;

SELECT '===== TEAM STATISTICS =====' as report;
SELECT 
    'Male Teams (A64M)' as category,
    COUNT(*) as count
FROM teams 
WHERE tag LIKE 'A64M%'
UNION ALL
SELECT 'Female Teams (A64F)', COUNT(*) FROM teams WHERE tag LIKE 'A64F%'
UNION ALL
SELECT 'Mixed Teams (A64X)', COUNT(*) FROM teams WHERE tag LIKE 'A64X%'
UNION ALL
SELECT 'Total Teams (A64*)', COUNT(*) FROM teams WHERE tag LIKE 'A64%';

SELECT '===== MEMBER STATISTICS =====' as report;
SELECT 
    'Total Team Members' as category,
    COUNT(*) as count
FROM team_members;

SELECT 'Total Players' as category, COUNT(*) as count FROM players;
SELECT 'Total Users (ath64*)' as category, COUNT(*) as count FROM users WHERE username LIKE 'ath64%';
SELECT 'Roblox Players' as category, COUNT(*) as count FROM players WHERE display_name LIKE '%Roblox%';

SELECT '===== MEMBER ROLES DISTRIBUTION =====' as report;
SELECT 
    member_roles as role,
    COUNT(*) as count
FROM team_members
WHERE team_id IN (SELECT team_id FROM teams WHERE tag LIKE 'A64%')
GROUP BY member_roles
ORDER BY member_roles;

SELECT '===== CAPTAIN VERIFICATION =====' as report;
SELECT
    'Teams with Captain' as category,
    COUNT(*) as count
FROM teams
WHERE tag LIKE 'A64%' AND captain_player_id IS NOT NULL;

SELECT '===== GENDER DISTRIBUTION IN MIXED TEAMS =====' as report;
SELECT 
    t.tag,
    SUM(CASE WHEN p.gender = 'male' THEN 1 ELSE 0 END) as males,
    SUM(CASE WHEN p.gender = 'female' THEN 1 ELSE 0 END) as females
FROM teams t
LEFT JOIN team_members tm ON t.team_id = tm.team_id
LEFT JOIN players p ON tm.player_id = p.player_id
WHERE t.tag LIKE 'A64X%'
GROUP BY t.tag
ORDER BY t.tag;

SELECT '===== TEAM CATEGORY VERIFICATION =====' as report;
SELECT 
    team_category,
    COUNT(*) as count
FROM teams
WHERE tag LIKE 'A64%'
GROUP BY team_category
ORDER BY team_category;

SELECT '===== DUPLICATE CHECK =====' as report;
SELECT 
    'Duplicate usernames' as check_name,
    COUNT(*) as count
FROM (
    SELECT username FROM users WHERE username LIKE 'ath64%'
    GROUP BY username HAVING COUNT(*) > 1
) dup;

SELECT '===== DATA INTEGRITY =====' as report;
SELECT 
    'Orphan team_members (no player)' as check_name,
    COUNT(*) as count
FROM team_members
WHERE player_id NOT IN (SELECT player_id FROM players);

SELECT 
    'Orphan players (no user)' as check_name,
    COUNT(*) as count
FROM players
WHERE user_id NOT IN (SELECT user_id FROM users);

SELECT '===== SAMPLE DATA =====' as report;
SELECT 'Sample Teams:' as info;
SELECT team_id, name, tag, team_category, captain_player_id
FROM teams
WHERE tag LIKE 'A64%'
ORDER BY tag
LIMIT 5;

SELECT 'Sample Team Members (Team A64M01):' as info;
SELECT tm.team_member_id, p.display_name, p.gender, tm.member_roles, tm.is_active
FROM team_members tm
JOIN players p ON tm.player_id = p.player_id
WHERE tm.team_id = (SELECT team_id FROM teams WHERE tag = 'A64M01')
LIMIT 10;

SELECT 'Roblox Players:' as info;
SELECT player_id, display_name, gender, birth_date
FROM players
WHERE display_name LIKE '%Roblox%'
ORDER BY player_id
LIMIT 10;
