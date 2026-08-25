-- Proposed additive reconciliation migration for esport_korattest.
-- Review database/pre-migration-check.sql and create a restorable backup first.
-- DO NOT run this file automatically from PHP.
-- This migration does not create tables, drop tables, drop columns, or delete rows.
-- It intentionally preserves legacy columns while canonical values are synchronized.
-- MariaDB 10.4+ syntax is expected.

USE `esport_korattest`;

-- Operator gate: set to 1 only after reviewing the pre-migration report and backup.
SET @migration_approved = 0;
SELECT IF(@migration_approved = 1,
    'Migration approval flag is enabled; review each statement before execution',
    'STOP: set @migration_approved = 1 only after backup and pre-check review') AS migration_guard;

-- Required completion audit fields. These are additive and preserve existing rows.
SET @sql = IF(@migration_approved = 1,
    'ALTER TABLE `tournaments` ADD COLUMN IF NOT EXISTS `completed_at` DATETIME NULL AFTER `status`, ADD COLUMN IF NOT EXISTS `completed_by` INT(10) UNSIGNED NULL AFTER `completed_at`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Preserve legacy Category fields, but backfill the canonical fields only when empty.
UPDATE tournament_categories
SET category_code = NULLIF(TRIM(category_code), ''),
    label = NULLIF(TRIM(label), ''),
    format = NULLIF(TRIM(format), ''),
    group_size = NULLIF(group_size, 0),
    starters_count = NULLIF(starters_count, 0),
    max_participants = NULLIF(max_participants, 0)
WHERE @migration_approved = 1;

UPDATE tournament_categories
SET category_code = COALESCE(category_code, NULLIF(TRIM(code), '')),
    label = COALESCE(label, NULLIF(TRIM(name), '')),
    format = COALESCE(format,
        CASE competition_format
            WHEN 'group_only' THEN 'round_robin'
            WHEN 'group_then_single' THEN 'group_playoff'
            WHEN 'group_then_double' THEN 'group_playoff'
            WHEN 'multi_participant_points' THEN 'multi_participant_points'
            ELSE competition_format
        END),
    group_size = COALESCE(group_size, teams_per_group),
    starters_count = COALESCE(starters_count, required_starters),
    max_participants = COALESCE(max_participants, maximum_roster_size)
WHERE @migration_approved = 1;

-- Synchronize legacy aliases after the canonical values have been reviewed.
UPDATE tournament_categories
SET code = category_code,
    name = COALESCE(label, name),
    competition_format = CASE format
        WHEN 'round_robin' THEN 'group_only'
        WHEN 'group_playoff' THEN 'group_then_single'
        WHEN 'multi_participant_points' THEN 'multi_participant_points'
        ELSE format
    END,
    teams_per_group = group_size,
    required_starters = starters_count,
    maximum_roster_size = max_participants
WHERE @migration_approved = 1
  AND category_code IS NOT NULL
  AND TRIM(category_code) <> '';

-- Backfill Registration Category IDs only where the legacy category is unambiguous.
UPDATE tournament_registrations tr
JOIN tournament_categories tc
  ON tc.tournament_id = tr.tournament_id
 AND tc.category_code = LOWER(TRIM(tr.category))
SET tr.tournament_category_id = tc.tournament_category_id
WHERE @migration_approved = 1
  AND (tr.tournament_category_id IS NULL OR tr.tournament_category_id = 0)
  AND tc.is_active = 1;

-- Backfill Match Category IDs from the participant registration only when unique.
UPDATE matches m
JOIN (
    SELECT tr.tournament_id,
           COALESCE(tr.team_id, tr.player_id) AS competitor_id,
           tr.tournament_category_id
    FROM tournament_registrations tr
    WHERE tr.tournament_category_id IS NOT NULL
    GROUP BY tr.tournament_id, COALESCE(tr.team_id, tr.player_id), tr.tournament_category_id
) source
  ON source.tournament_id = m.tournament_id
 AND (source.competitor_id = m.team1_id OR source.competitor_id = m.team2_id)
SET m.tournament_category_id = source.tournament_category_id
WHERE @migration_approved = 1
  AND (m.tournament_category_id IS NULL OR m.tournament_category_id = 0);

-- No data is deleted by this file. Add Foreign Keys or new indexes only after
-- the post-check confirms there are no orphaned or duplicate candidates.
SELECT 'Review post-migration-verification.sql before adding any further constraint' AS next_step;
