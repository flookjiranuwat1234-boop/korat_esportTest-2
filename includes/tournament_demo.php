<?php
// Local-only demo mode. It bypasses clock windows, not workflow validation.
if (!defined('ENABLE_TOURNAMENT_DEMO_MODE')) {
    define('ENABLE_TOURNAMENT_DEMO_MODE', false);
}

function isTournamentDemoEnvironment(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        && (ENABLE_TOURNAMENT_DEMO_MODE || PHP_SAPI !== 'cli');
}

function isDemoTournament(array $tournament): bool
{
    return isTournamentDemoEnvironment() && str_starts_with(trim((string) ($tournament['name'] ?? '')), '[DEMO]');
}

function isDemoTournamentById(PDO $pdo, int $tournamentId): bool
{
    if (!isTournamentDemoEnvironment() || $tournamentId <= 0) return false;
    $stmt = $pdo->prepare('SELECT name FROM tournaments WHERE tournament_id = :tournament_id LIMIT 1');
    $stmt->execute(['tournament_id' => $tournamentId]);
    return isDemoTournament(['name' => $stmt->fetchColumn()]);
}

function demoClockAllows(array $tournament): bool
{
    return isDemoTournament($tournament);
}
