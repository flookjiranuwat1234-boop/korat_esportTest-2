-- Read-only post-migration verification for esport_korattest.
-- Run only after the approved migration has been reviewed and executed.
-- This file does not modify data or schema.

USE `esport_korattest`;

SELECT DATABASE() AS active_database;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('tournaments', 'tournament_categories', 'tournament_registrations', 'matches')
  AND COLUMN_NAME IN ('completed_at', 'completed_by', 'category_code', 'label', 'format', 'max_participants', 'tournament_category_id')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT 'Unexpected tournament statuses' AS check_name, tournament_id, name, status
FROM tournaments
WHERE status NOT IN ('draft', 'registration_open', 'registration_closed', 'ongoing', 'completed', 'cancelled');

SELECT 'Category duplicate candidates' AS check_name, tournament_id, code, COUNT(*) AS row_count
FROM tournament_categories
GROUP BY tournament_id, code
HAVING code IS NOT NULL AND TRIM(code) <> '' AND COUNT(*) > 1;

SELECT 'Canonical category duplicate candidates' AS check_name, tournament_id, category_code, COUNT(*) AS row_count
FROM tournament_categories
GROUP BY tournament_id, category_code
HAVING category_code IS NOT NULL AND TRIM(category_code) <> '' AND COUNT(*) > 1;

SELECT 'Registrations missing category id' AS check_name, COUNT(*) AS row_count
FROM tournament_registrations
WHERE tournament_category_id IS NULL OR tournament_category_id = 0;

SELECT 'Matches missing category id' AS check_name, COUNT(*) AS row_count
FROM matches
WHERE tournament_category_id IS NULL OR tournament_category_id = 0;

SELECT 'Orphan registration categories' AS check_name, COUNT(*) AS row_count
FROM tournament_registrations tr
LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
WHERE tr.tournament_category_id IS NOT NULL AND tc.tournament_category_id IS NULL;

SELECT 'Orphan match categories' AS check_name, COUNT(*) AS row_count
FROM matches m
LEFT JOIN tournament_categories tc ON tc.tournament_category_id = m.tournament_category_id
WHERE m.tournament_category_id IS NOT NULL AND tc.tournament_category_id IS NULL;

SELECT 'Match game duplicate candidates' AS check_name, match_id, game_number, COUNT(*) AS row_count
FROM match_games
GROUP BY match_id, game_number
HAVING COUNT(*) > 1;

SELECT 'Preserved row counts' AS check_name, 'tournaments' AS table_name, COUNT(*) AS row_count FROM tournaments
UNION ALL SELECT 'Preserved row counts', 'tournament_categories', COUNT(*) FROM tournament_categories
UNION ALL SELECT 'Preserved row counts', 'tournament_registrations', COUNT(*) FROM tournament_registrations
UNION ALL SELECT 'Preserved row counts', 'matches', COUNT(*) FROM matches
UNION ALL SELECT 'Preserved row counts', 'ranking_history', COUNT(*) FROM ranking_history
UNION ALL SELECT 'Preserved row counts', 'registration_status_history', COUNT(*) FROM registration_status_history;
