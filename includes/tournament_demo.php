<?php
// Temporary hosted demo access for pre-launch testing. Disable after testing.
if (!defined('ALLOW_HOSTED_TOURNAMENT_DEMO')) {
    define('ALLOW_HOSTED_TOURNAMENT_DEMO', true);
}

if (!defined('ENABLE_TOURNAMENT_DEMO_MODE')) {
    define('ENABLE_TOURNAMENT_DEMO_MODE', false);
}

function isTournamentDemoEnvironment(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

    return ALLOW_HOSTED_TOURNAMENT_DEMO
        || ($isLocal && (ENABLE_TOURNAMENT_DEMO_MODE || PHP_SAPI !== 'cli'));
}

function isDemoTournament(array $tournament): bool
{
    return isTournamentDemoEnvironment() && (
        !empty($tournament['is_demo'])
        || str_starts_with(trim((string) ($tournament['name'] ?? '')), '[DEMO]')
    );
}

function isDemoTournamentById(PDO $pdo, int $tournamentId): bool
{
    if (!isTournamentDemoEnvironment() || $tournamentId <= 0) return false;
    $stmt = $pdo->prepare('SELECT name, is_demo FROM tournaments WHERE tournament_id = :tournament_id LIMIT 1');
    $stmt->execute(['tournament_id' => $tournamentId]);
    return isDemoTournament($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
}

function demoClockAllows(array $tournament): bool
{
    return isDemoTournament($tournament);
}
