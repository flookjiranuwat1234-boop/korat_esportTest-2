-- Read-only preflight for the esport_korattest test-data reset.
-- This file contains no DELETE, UPDATE, INSERT, ALTER, DROP, TRUNCATE, or COMMIT.

SELECT DATABASE() AS active_database;
SELECT 'esport_korattest' AS expected_database;

SELECT user_id, username, email, role, status
FROM users
WHERE role = 'admin'
ORDER BY user_id;

SELECT 'users' AS table_name, COUNT(*) AS record_count FROM users
UNION ALL SELECT 'players', COUNT(*) FROM players
UNION ALL SELECT 'teams', COUNT(*) FROM teams
UNION ALL SELECT 'team_members', COUNT(*) FROM team_members
UNION ALL SELECT 'team_member_roles', COUNT(*) FROM team_member_roles
UNION ALL SELECT 'tournament_registrations', COUNT(*) FROM tournament_registrations
UNION ALL SELECT 'tournament_registration_members', COUNT(*) FROM tournament_registration_members
UNION ALL SELECT 'player_tournament_checkins', COUNT(*) FROM player_tournament_checkins
UNION ALL SELECT 'player_checkin_history', COUNT(*) FROM player_checkin_history
UNION ALL SELECT 'matches', COUNT(*) FROM matches
UNION ALL SELECT 'match_participants', COUNT(*) FROM match_participants
UNION ALL SELECT 'match_games', COUNT(*) FROM match_games
UNION ALL SELECT 'bracket_edges', COUNT(*) FROM bracket_edges
UNION ALL SELECT 'tournament_groups', COUNT(*) FROM tournament_groups
UNION ALL SELECT 'group_teams', COUNT(*) FROM group_teams
UNION ALL SELECT 'player_rankings', COUNT(*) FROM player_rankings
UNION ALL SELECT 'team_rankings', COUNT(*) FROM team_rankings
UNION ALL SELECT 'ranking_history', COUNT(*) FROM ranking_history
UNION ALL SELECT 'registration_status_history', COUNT(*) FROM registration_status_history
UNION ALL SELECT 'news', COUNT(*) FROM news
UNION ALL SELECT 'gallery_albums', COUNT(*) FROM gallery_albums
UNION ALL SELECT 'gallery', COUNT(*) FROM gallery
UNION ALL SELECT 'accommodations', COUNT(*) FROM accommodations;

SELECT p.player_id, p.user_id, u.username, p.display_name
FROM players p
LEFT JOIN users u ON u.user_id = p.user_id
WHERE u.role IS NULL OR u.role <> 'admin'
ORDER BY p.player_id;

SELECT t.team_id, t.name, t.captain_player_id, t.game_id, t.status,
       COUNT(tm.team_member_id) AS member_count
FROM teams t
LEFT JOIN team_members tm ON tm.team_id = t.team_id
GROUP BY t.team_id, t.name, t.captain_player_id, t.game_id, t.status
ORDER BY t.team_id;

SELECT tr.tournament_registration_id, tr.tournament_id, tr.tournament_category_id,
       tr.team_id, tr.player_id, tr.status, tr.checkin_status
FROM tournament_registrations tr
WHERE tr.team_id IN (SELECT team_id FROM teams)
   OR tr.player_id IN (SELECT player_id FROM players)
ORDER BY tr.tournament_registration_id;

SELECT m.match_id, m.tournament_id, m.tournament_category_id,
       m.team1_id, m.team2_id, m.winner_team_id, m.status
FROM matches m
WHERE m.team1_id IN (SELECT team_id FROM teams)
   OR m.team2_id IN (SELECT team_id FROM teams)
   OR m.winner_team_id IN (SELECT team_id FROM teams)
ORDER BY m.match_id;

SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

SELECT username, email, COUNT(*) AS duplicate_count
FROM users
WHERE username LIKE 'athlete%'
   OR email LIKE 'athlete%@test.local'
GROUP BY username, email
HAVING COUNT(*) > 1;
