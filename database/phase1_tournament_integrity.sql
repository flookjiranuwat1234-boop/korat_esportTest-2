-- Korat Esport Phase 1 migration
-- Run after the existing base schema and member/team migrations.
-- This migration is additive: it preserves existing team, roster, ranking,
-- and check-in data while adding normalized records for future tournaments.

USE `esport_korattest`;

CREATE TABLE IF NOT EXISTS `team_member_roles` (
    `team_member_role_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `team_member_id` INT UNSIGNED NOT NULL,
    `role_code` VARCHAR(30) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`team_member_role_id`),
    UNIQUE KEY `team_member_roles_unique` (`team_member_id`, `role_code`),
    KEY `team_member_roles_member_idx` (`team_member_id`),
    CONSTRAINT `team_member_roles_member_fk`
        FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`team_member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `team_member_roles` (`team_member_id`, `role_code`)
SELECT `team_member_id`, LOWER(TRIM(`in_game_role`))
FROM `team_members`
WHERE `in_game_role` IS NOT NULL AND TRIM(`in_game_role`) <> '';

CREATE TABLE IF NOT EXISTS `tournament_registration_members` (
    `tournament_registration_member_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_registration_id` INT UNSIGNED NOT NULL,
    `player_id` INT UNSIGNED NOT NULL,
    `member_roles` VARCHAR(255) NULL,
    `is_starter` TINYINT(1) NOT NULL DEFAULT 1,
    `is_required_for_checkin` TINYINT(1) NOT NULL DEFAULT 1,
    `checkin_status` VARCHAR(30) NOT NULL DEFAULT 'not_checked_in',
    `checkin_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`tournament_registration_member_id`),
    UNIQUE KEY `registration_member_unique` (`tournament_registration_id`, `player_id`),
    KEY `registration_member_player_idx` (`player_id`),
    CONSTRAINT `registration_member_registration_fk`
        FOREIGN KEY (`tournament_registration_id`)
        REFERENCES `tournament_registrations` (`tournament_registration_id`) ON DELETE CASCADE,
    CONSTRAINT `registration_member_player_fk`
        FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `player_tournament_checkins` (
    `player_tournament_checkin_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_registration_id` INT UNSIGNED NOT NULL,
    `player_id` INT UNSIGNED NOT NULL,
    `checkin_status` VARCHAR(30) NOT NULL DEFAULT 'not_checked_in',
    `checked_in_at` DATETIME NULL,
    `checked_in_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`player_tournament_checkin_id`),
    UNIQUE KEY `player_registration_checkin_unique` (`tournament_registration_id`, `player_id`),
    KEY `player_checkin_player_idx` (`player_id`),
    CONSTRAINT `player_checkin_registration_fk`
        FOREIGN KEY (`tournament_registration_id`)
        REFERENCES `tournament_registrations` (`tournament_registration_id`) ON DELETE CASCADE,
    CONSTRAINT `player_checkin_player_fk`
        FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE RESTRICT,
    CONSTRAINT `player_checkin_user_fk`
        FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ranking_rules` (
    `ranking_rule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `tournament_id` INT UNSIGNED NULL,
    `win_points` DECIMAL(10,2) NOT NULL DEFAULT 3,
    `draw_points` DECIMAL(10,2) NOT NULL DEFAULT 1,
    `loss_points` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `placement_points_json` JSON NULL,
    `participation_points` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ranking_rule_id`),
    KEY `ranking_rules_game_idx` (`game_id`),
    KEY `ranking_rules_tournament_idx` (`tournament_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `registration_status_history` (
    `registration_status_history_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_registration_id` INT UNSIGNED NOT NULL,
    `old_status` VARCHAR(30) NULL,
    `new_status` VARCHAR(30) NOT NULL,
    `changed_by` INT UNSIGNED NULL,
    `change_note` VARCHAR(500) NULL,
    `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`registration_status_history_id`),
    KEY `registration_status_history_registration_idx` (`tournament_registration_id`),
    CONSTRAINT `registration_status_history_registration_fk`
        FOREIGN KEY (`tournament_registration_id`)
        REFERENCES `tournament_registrations` (`tournament_registration_id`) ON DELETE CASCADE,
    CONSTRAINT `registration_status_history_user_fk`
        FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tournament_days` (
    `tournament_day_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_id` INT UNSIGNED NOT NULL,
    `day_number` INT UNSIGNED NOT NULL,
    `event_date` DATE NOT NULL,
    `start_time` TIME NULL,
    `end_time` TIME NULL,
    `venue_name` VARCHAR(255) NULL,
    `notes` VARCHAR(500) NULL,
    PRIMARY KEY (`tournament_day_id`),
    UNIQUE KEY `tournament_day_unique` (`tournament_id`, `day_number`),
    CONSTRAINT `tournament_days_tournament_fk`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 2: multi-category tournaments, participation/DQ tracking, WO/bye matches, check-in waivers.

CREATE TABLE IF NOT EXISTS `tournament_categories` (
    `tournament_category_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournament_id` INT UNSIGNED NOT NULL,
    `category_code` VARCHAR(30) NOT NULL,
    `label` VARCHAR(100) NOT NULL,
    `max_participants` INT UNSIGNED NULL,
    `format` VARCHAR(30) NOT NULL DEFAULT 'single_elimination',
    `group_size` INT UNSIGNED NULL,
    `teams_advance_per_group` INT UNSIGNED NULL,
    `starters_count` INT UNSIGNED NULL,
    `substitutes_count` INT UNSIGNED NULL,
    `checkin_required_roles` VARCHAR(255) NULL,
    `seed_method` VARCHAR(30) NOT NULL DEFAULT 'ranking',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`tournament_category_id`),
    UNIQUE KEY `tournament_category_unique` (`tournament_id`, `category_code`),
    CONSTRAINT `tournament_categories_tournament_fk`
        FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `tournaments`
    ADD COLUMN IF NOT EXISTS `checkin_open_at` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `checkin_close_at` DATETIME NULL;

ALTER TABLE `tournament_registrations`
    ADD COLUMN IF NOT EXISTS `tournament_category_id` INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS `participation_status` VARCHAR(30) NOT NULL DEFAULT 'registered',
    ADD COLUMN IF NOT EXISTS `roster_locked_at` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `seed_no` INT UNSIGNED NULL;

ALTER TABLE `tournament_registration_members`
    ADD COLUMN IF NOT EXISTS `checkin_waived_reason` VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS `checkin_waived_by` INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS `checkin_waived_at` DATETIME NULL;

ALTER TABLE `matches`
    ADD COLUMN IF NOT EXISTS `result_type` VARCHAR(20) NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS `wo_reason` VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS `tournament_category_id` INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS `scheduled_at` DATETIME NULL,
    ADD COLUMN IF NOT EXISTS `venue_name` VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS `venue_area` VARCHAR(100) NULL;

ALTER TABLE `tournament_groups`
    ADD COLUMN IF NOT EXISTS `tournament_category_id` INT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS `ranking_history` (
    `ranking_history_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `game_id` INT UNSIGNED NOT NULL,
    `tournament_id` INT UNSIGNED NOT NULL,
    `tournament_category_id` INT UNSIGNED NULL,
    `match_id` INT UNSIGNED NULL,
    `player_id` INT UNSIGNED NULL,
    `team_id` INT UNSIGNED NULL,
    `result_code` VARCHAR(20) NOT NULL,
    `points` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ranking_history_id`),
    KEY `ranking_history_tournament_idx` (`tournament_id`),
    KEY `ranking_history_player_idx` (`player_id`),
    KEY `ranking_history_team_idx` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
