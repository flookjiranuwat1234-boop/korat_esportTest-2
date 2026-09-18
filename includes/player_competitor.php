<?php
// Persistent player participation marker.

function ensurePlayerCompetitorColumn(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;

    $column = $pdo->query("SHOW COLUMNS FROM players LIKE 'ever_competed'")->fetch();
    if (!$column) {
        $pdo->exec("ALTER TABLE players ADD COLUMN ever_competed TINYINT(1) NOT NULL DEFAULT 0");
    }

    $pdo->exec("
        UPDATE players p
        SET p.ever_competed = 1
        WHERE p.ever_competed = 0
          AND EXISTS (
              SELECT 1
              FROM player_rankings pr
              WHERE pr.player_id = p.player_id
                AND pr.matches_played > 0
          )
    ");

    $ready = true;
}

function markPlayerAsCompetitor(PDO $pdo, int $playerId): void
{
    if ($playerId <= 0) return;
    ensurePlayerCompetitorColumn($pdo);
    $pdo->prepare('UPDATE players SET ever_competed = 1 WHERE player_id = :player_id')
        ->execute(['player_id' => $playerId]);
}
