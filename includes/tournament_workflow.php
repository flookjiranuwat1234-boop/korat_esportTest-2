<?php
// Shared tournament lifecycle decisions. This service does not mutate schema or data.

function getTournamentWorkflowState(PDO $pdo, int $tournamentId, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
    $stmt = $pdo->prepare('SELECT tournament_id, status, registration_start, registration_end,
            roster_lock_at, checkin_open_at, checkin_close_at, start_date, end_date
        FROM tournaments WHERE tournament_id = :tournament_id LIMIT 1');
    $stmt->execute(['tournament_id' => $tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tournament) {
        return ['exists' => false, 'status' => null, 'computed_status' => 'unknown', 'tournament' => null];
    }

    $categoryStmt = $pdo->prepare('SELECT tournament_category_id, format, is_active,
            checkin_open_at, checkin_deadline
        FROM tournament_categories
        WHERE tournament_id = :tournament_id AND is_active = 1
        ORDER BY tournament_category_id');
    $categoryStmt->execute(['tournament_id' => $tournamentId]);
    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

    $matchStmt = $pdo->prepare("SELECT COUNT(*) AS total_matches,
            SUM(CASE WHEN status IN ('completed', 'walkover') OR result_type = 'bye' THEN 1 ELSE 0 END) AS finished_matches,
            SUM(CASE WHEN status NOT IN ('completed', 'walkover') AND result_type <> 'bye'
                AND NOT (bracket_type LIKE 'double_grand_final_reset_%' AND EXISTS (
                    SELECT 1 FROM matches parent_match
                    WHERE parent_match.reset_match_id = matches.match_id
                        AND parent_match.status IN ('completed', 'walkover')
                        AND parent_match.winner_team_id = parent_match.team1_id
                )) THEN 1 ELSE 0 END) AS pending_matches
        FROM matches WHERE tournament_id = :tournament_id");
    $matchStmt->execute(['tournament_id' => $tournamentId]);
    $matches = $matchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalMatches = (int) ($matches['total_matches'] ?? 0);
    $finishedMatches = (int) ($matches['finished_matches'] ?? 0);
    $pendingMatches = (int) ($matches['pending_matches'] ?? 0);

    $computedStatus = (string) $tournament['status'];
    $checkinOpen = workflowTimeInWindow($now, $tournament['checkin_open_at'], $tournament['checkin_close_at']);
    if ($tournament['status'] === 'ongoing' && workflowTournamentReadyToComplete($pdo, $tournamentId, $categories, $totalMatches, $pendingMatches)) {
        $computedStatus = 'ready_to_close';
    } elseif ($checkinOpen && in_array($tournament['status'], ['registration_open', 'registration_closed'], true)) {
        $computedStatus = 'checkin_open';
    }

    return [
        'exists' => true,
        'status' => $tournament['status'],
        'computed_status' => $computedStatus,
        'tournament' => $tournament,
        'categories' => $categories,
        'total_matches' => $totalMatches,
        'finished_matches' => $finishedMatches,
        'pending_matches' => $pendingMatches,
        'checkin_open' => $checkinOpen,
    ];
}

function workflowTimeInWindow(DateTimeImmutable $now, ?string $openAt, ?string $closeAt): bool
{
    if (!$openAt || !$closeAt) return false;
    $timezone = new DateTimeZone('Asia/Bangkok');
    return $now >= new DateTimeImmutable($openAt, $timezone) && $now <= new DateTimeImmutable($closeAt, $timezone);
}

function workflowTournamentReadyToComplete(PDO $pdo, int $tournamentId, array $categories = [], int $totalMatches = 0, int $pendingMatches = 0): bool
{
    if ($totalMatches <= 0 || $pendingMatches > 0) return false;
    if (!$categories) return false;

    $stmt = $pdo->prepare("SELECT tournament_category_id, MAX(round_number) AS final_round,
            SUM(CASE WHEN status IN ('completed', 'walkover') OR result_type = 'bye' THEN 1 ELSE 0 END) AS finished_matches,
            COUNT(*) AS total_matches
        FROM matches
        WHERE tournament_id = :tournament_id
        GROUP BY tournament_category_id");
    $stmt->execute(['tournament_id' => $tournamentId]);
    $byCategory = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byCategory[(int) $row['tournament_category_id']] = $row;

    $winnerStmt = $pdo->prepare("SELECT winner_team_id, status, result_type
        FROM matches
        WHERE tournament_id = :tournament_id AND tournament_category_id = :category_id
        ORDER BY CASE
            WHEN bracket_type LIKE 'double_grand_final_reset_%' AND status IN ('completed', 'walkover') THEN 0
            WHEN bracket_type LIKE 'double_grand_final_%' AND status IN ('completed', 'walkover') THEN 1
            ELSE 2
        END, round_number DESC, match_index DESC LIMIT 1");
    foreach ($categories as $category) {
        $categoryId = (int) $category['tournament_category_id'];
        $row = $byCategory[$categoryId] ?? null;
        if (!$row || (int) $row['total_matches'] <= 0 || (int) $row['finished_matches'] !== (int) $row['total_matches']) return false;
        $winnerStmt->execute(['tournament_id' => $tournamentId, 'category_id' => $categoryId]);
        $final = $winnerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$final || (int) ($final['winner_team_id'] ?? 0) <= 0) return false;
    }
    return true;
}

function canOpenRegistration(PDO $pdo, int $tournamentId, ?DateTimeImmutable $now = null): bool
{
    $state = getTournamentWorkflowState($pdo, $tournamentId, $now);
    if (!$state['exists'] || $state['status'] !== 'draft') return false;
    $tournament = $state['tournament'];
    return !$tournament['registration_start'] || new DateTimeImmutable($tournament['registration_start'], new DateTimeZone('Asia/Bangkok')) <= ($now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')));
}

function canCloseRegistration(PDO $pdo, int $tournamentId, ?DateTimeImmutable $now = null): bool
{
    $state = getTournamentWorkflowState($pdo, $tournamentId, $now);
    if (!$state['exists'] || !in_array($state['status'], ['registration_open', 'registration_closed'], true)) return false;
    $end = $state['tournament']['registration_end'];
    return !$end || new DateTimeImmutable($end, new DateTimeZone('Asia/Bangkok')) <= ($now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')));
}

function canCheckin(PDO $pdo, int $tournamentId, ?DateTimeImmutable $now = null): bool
{
    $state = getTournamentWorkflowState($pdo, $tournamentId, $now);
    return $state['exists'] && $state['checkin_open'] && !in_array($state['status'], ['completed', 'cancelled'], true);
}

function canCheckinRegistration(PDO $pdo, int $registrationId, ?DateTimeImmutable $now = null): bool
{
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
    $stmt = $pdo->prepare('SELECT tr.status, tr.participation_status,
            COALESCE(tc.checkin_open_at, tour.checkin_open_at) AS checkin_open_at,
            COALESCE(tc.checkin_deadline, tour.checkin_close_at) AS checkin_close_at
        FROM tournament_registrations tr
        JOIN tournaments tour ON tour.tournament_id = tr.tournament_id
        LEFT JOIN tournament_categories tc ON tc.tournament_category_id = tr.tournament_category_id
        WHERE tr.tournament_registration_id = :registration_id LIMIT 1');
    $stmt->execute(['registration_id' => $registrationId]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    return $registration
        && $registration['status'] === 'approved'
        && !in_array($registration['participation_status'], ['withdrawn', 'disqualified'], true)
        && workflowTimeInWindow($now, $registration['checkin_open_at'], $registration['checkin_close_at']);
}

function canGenerateBracket(PDO $pdo, int $tournamentId): bool
{
    $state = getTournamentWorkflowState($pdo, $tournamentId);
    return $state['exists'] && in_array($state['status'], ['registration_closed', 'ongoing'], true) && $state['total_matches'] === 0;
}

function canRecordMatch(PDO $pdo, int $tournamentId, int $matchId): bool
{
    $state = getTournamentWorkflowState($pdo, $tournamentId);
    if (!$state['exists'] || in_array($state['status'], ['completed', 'cancelled'], true)) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM matches
        WHERE match_id = :match_id AND tournament_id = :tournament_id
          AND status NOT IN ('completed', 'walkover')");
    $stmt->execute(['match_id' => $matchId, 'tournament_id' => $tournamentId]);
    return (int) $stmt->fetchColumn() === 1;
}

function canCompleteTournament(PDO $pdo, int $tournamentId): bool
{
    $state = getTournamentWorkflowState($pdo, $tournamentId);
    return $state['exists'] && $state['status'] === 'ongoing' && $state['computed_status'] === 'ready_to_close';
}
