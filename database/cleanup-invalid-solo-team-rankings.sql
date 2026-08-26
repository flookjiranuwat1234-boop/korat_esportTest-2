-- WARNING: This file is intentionally not executed automatically.
-- Review and confirm before running in a controlled maintenance window.
-- Purpose: remove invalid team ranking rows for games whose play_mode = 'solo'.

DELETE tr
FROM team_rankings tr
JOIN games g ON g.game_id = tr.game_id
WHERE g.play_mode = 'solo';

-- Optional verification after cleanup:
SELECT g.game_id, g.name, g.play_mode, COUNT(tr.team_id) AS remaining_team_rows
FROM games g
LEFT JOIN team_rankings tr ON tr.game_id = g.game_id
WHERE g.play_mode = 'solo'
GROUP BY g.game_id, g.name, g.play_mode;
