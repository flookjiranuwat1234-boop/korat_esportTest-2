-- ตรวจ Record ที่ผิด: team_rankings ของเกมที่ play_mode = 'solo'
SELECT
    g.game_id,
    g.name AS game_name,
    g.play_mode,
    tr.team_id,
    tr.category,
    tr.points,
    tr.matches_played,
    tr.wins,
    tr.losses,
    COUNT(*) AS record_count
FROM games g
JOIN team_rankings tr ON tr.game_id = g.game_id
WHERE g.play_mode = 'solo'
GROUP BY g.game_id, g.name, g.play_mode, tr.team_id, tr.category, tr.points, tr.matches_played, tr.wins, tr.losses
ORDER BY g.game_id, tr.team_id;

-- Summary
SELECT
    g.game_id,
    g.name AS game_name,
    g.play_mode,
    COUNT(tr.team_id) AS invalid_team_ranking_rows
FROM games g
LEFT JOIN team_rankings tr ON tr.game_id = g.game_id
WHERE g.play_mode = 'solo'
GROUP BY g.game_id, g.name, g.play_mode
ORDER BY g.game_id;
