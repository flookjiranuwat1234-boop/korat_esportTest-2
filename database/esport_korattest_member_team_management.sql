-- Korat Esport: member/team management migration
-- Target database: esport_korattest
-- Run once after backing up the database.

USE `esport_korattest`;

ALTER TABLE `users`
  MODIFY `status` ENUM('active','suspended','disabled') NOT NULL DEFAULT 'active',
  ADD COLUMN `reactivated_at` DATETIME NULL AFTER `suspension_reason`,
  ADD COLUMN `last_login_at` DATETIME NULL AFTER `reactivated_at`;

ALTER TABLE `teams`
  ADD COLUMN `status` ENUM('active','inactive','suspended','disbanded') NOT NULL DEFAULT 'active' AFTER `team_category`,
  ADD COLUMN `status_reason` VARCHAR(500) NULL AFTER `status`,
  ADD COLUMN `status_changed_at` DATETIME NULL AFTER `status_reason`,
  ADD COLUMN `status_changed_by` INT(10) UNSIGNED NULL AFTER `status_changed_at`,
  ADD KEY `teams_status_idx` (`status`),
  ADD KEY `teams_status_changed_by_idx` (`status_changed_by`),
  ADD CONSTRAINT `teams_status_changed_by_fk`
    FOREIGN KEY (`status_changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `team_members`
  ADD COLUMN `left_at` DATETIME NULL AFTER `joined_at`;

-- The current unique key (team_id, player_id) is retained. A former member is
-- reactivated in the same row, preventing duplicate active memberships.
