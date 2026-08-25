<?php
function ensureRegistrationStatusHistoryTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS registration_status_history (
        registration_status_history_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tournament_registration_id INT UNSIGNED NOT NULL,
        old_status VARCHAR(30) NULL,
        new_status VARCHAR(30) NOT NULL,
        changed_by INT UNSIGNED NULL,
        change_note VARCHAR(500) NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (registration_status_history_id),
        KEY registration_status_history_registration_idx (tournament_registration_id),
        CONSTRAINT registration_status_history_registration_fk FOREIGN KEY (tournament_registration_id)
            REFERENCES tournament_registrations (tournament_registration_id) ON DELETE CASCADE,
        CONSTRAINT registration_status_history_user_fk FOREIGN KEY (changed_by)
            REFERENCES users (user_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}
function recordRegistrationStatus(PDO $pdo, int $registrationId, string $newStatus, ?int $changedBy, ?string $note = null): void
{
    ensureRegistrationStatusHistoryTable($pdo);
    $stmt = $pdo->prepare('SELECT status FROM tournament_registrations WHERE tournament_registration_id = :id');
    $stmt->execute(['id' => $registrationId]);
    $oldStatus = $stmt->fetchColumn();
    if ($oldStatus === false || $oldStatus === $newStatus) return;
    $pdo->prepare('INSERT INTO registration_status_history
        (tournament_registration_id, old_status, new_status, changed_by, change_note)
        VALUES (:registration_id, :old_status, :new_status, :changed_by, :note)')
        ->execute([
            'registration_id' => $registrationId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $changedBy,
            'note' => $note,
        ]);
    }