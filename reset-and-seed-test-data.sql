-- Destructive test-data reset for esport_korattest.
-- DO NOT RUN until check-before-reset.sql and a restorable backup have been reviewed.
-- Password hashes and all new test rows are created by seed-test-users.php.
-- This script preserves admin users, games, tournaments, categories, news, gallery,
-- and accommodations. It does not disable FOREIGN_KEY_CHECKS.

USE `esport_korattest`;

START TRANSACTION;

SET @database_is_valid = (SELECT DATABASE() = 'esport_korattest');
SELECT IF(@database_is_valid, 'Target database confirmed', 'STOP: wrong database') AS reset_guard;

-- MySQL has no portable transaction-safe conditional abort in every supported version.
-- The operator must stop if the previous result is not Target database confirmed.

DELETE FROM ranking_history;
DELETE FROM player_checkin_history;
DELETE FROM player_tournament_checkins;
DELETE FROM tournament_registration_members;
DELETE FROM registration_status_history;
DELETE FROM match_participants;
DELETE FROM match_games;
DELETE FROM bracket_edges;
DELETE FROM matches;
DELETE FROM group_teams;
DELETE FROM tournament_groups;
DELETE FROM tournament_days;
DELETE FROM tournament_registrations;
DELETE FROM team_member_roles;
DELETE FROM team_members;
DELETE FROM player_rankings;
DELETE FROM team_rankings;
DELETE FROM teams;
DELETE FROM players;

-- Preserve all admin rows; remove only non-admin test/member accounts.
DELETE FROM users WHERE role <> 'admin';

COMMIT;

-- These are safe only after the transaction above has committed and the tables are empty.
ALTER TABLE players AUTO_INCREMENT = 1;
ALTER TABLE teams AUTO_INCREMENT = 1;
ALTER TABLE team_members AUTO_INCREMENT = 1;
ALTER TABLE team_member_roles AUTO_INCREMENT = 1;
ALTER TABLE player_rankings AUTO_INCREMENT = 1;
ALTER TABLE team_rankings AUTO_INCREMENT = 1;
ALTER TABLE ranking_history AUTO_INCREMENT = 1;
ALTER TABLE player_checkin_history AUTO_INCREMENT = 1;

SELECT 'Reset complete. Run seed-test-users.php next.' AS result;
