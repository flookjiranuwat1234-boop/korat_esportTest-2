-- Read-only pre-migration check for esport_korattest.
-- This file must be reviewed before any migration is executed.
-- It does not create, alter, delete, truncate, or update anything.

USE `esport_korattest`;

SELECT DATABASE() AS active_database;

SELECT TABLE_NAME, TABLE_ROWS AS estimated_rows
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'tournaments', 'tournament_categories', 'tournament_registrations',
    'tournament_registration_members', 'player_tournament_checkins',
    'matches', 'match_games', 'match_participants', 'bracket_edges',
    'tournament_groups', 'group_teams', 'ranking_history',
    'registration_status_history', 'player_checkin_history',
    'player_rankings', 'team_rankings'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME;

SELECT 'Category duplicate candidates by legacy code' AS check_name,
       tournament_id, code, COUNT(*) AS row_count
FROM tournament_categories
GROUP BY tournament_id, code
HAVING code IS NOT NULL AND TRIM(code) <> '' AND COUNT(*) > 1;

SELECT 'Category duplicate candidates by canonical code' AS check_name,
       tournament_id, category_code, COUNT(*) AS row_count
FROM tournament_categories
GROUP BY tournament_id, category_code
HAVING category_code IS NOT NULL AND TRIM(category_code) <> '' AND COUNT(*) > 1;

SELECT 'Registrations missing category id' AS check_name, COUNT(*) AS row_count
FROM tournament_registrations
WHERE tournament_category_id IS NULL OR tournament_category_id = 0;

SELECT 'Matches missing category id' AS check_name, COUNT(*) AS row_count
FROM matches
WHERE tournament_category_id IS NULL OR tournament_category_id = 0;

SELECT 'Match games duplicate candidates' AS check_name,
       match_id, game_number, COUNT(*) AS row_count
FROM match_games
GROUP BY match_id, game_number
HAVING COUNT(*) > 1;

SELECT 'Registration history rows' AS check_name, COUNT(*) AS row_count
FROM registration_status_history;

SELECT 'Ranking history rows' AS check_name, COUNT(*) AS row_count
FROM ranking_history;

SELECT 'Tournament status distribution' AS check_name, status, COUNT(*) AS row_count
FROM tournaments
GROUP BY status
ORDER BY status;

SELECT 'Tournament category activity' AS check_name,
       t.tournament_id, t.name, tc.tournament_category_id,
       tc.category_code, tc.code, tc.label, tc.name AS legacy_name, tc.is_active
FROM tournaments t
JOIN tournament_categories tc ON tc.tournament_id = t.tournament_id
ORDER BY t.tournament_id, tc.tournament_category_id;
