-- Query to get all team captains' login info
SELECT 
    t.team_id,
    t.tag AS 'Team Tag',
    t.name AS 'Team Name',
    t.team_category AS 'Category',
    u.username AS 'Captain Username',
    u.email AS 'Email',
    p.player_id AS 'Player ID'
FROM teams t
LEFT JOIN team_members tm ON t.team_id = tm.team_id AND tm.player_id = t.captain_player_id
LEFT JOIN players p ON t.captain_player_id = p.player_id
LEFT JOIN users u ON p.user_id = u.user_id
WHERE t.tag LIKE 'A64%'
ORDER BY t.tag;
