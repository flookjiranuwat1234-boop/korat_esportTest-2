# Rollback Instructions

## Important

The proposed migration is additive and must not be run from a PHP page. No rollback command should delete data or drop columns.

## Before migration

1. Stop application writes.
2. Confirm the active database is `esport_korattest`.
3. Create a full backup including routines, triggers, and events:

```powershell
mysqldump -h localhost -u root --single-transaction --routines --triggers --events esport_korattest > esport_korattest_before_workflow_migration.sql
```

4. Verify the backup exists and is not empty.
5. Run `database/pre-migration-check.sql` and save its output.

## If migration has not started

Do not change anything. Leave the database unchanged and correct the migration file after review.

## If only additive columns were applied

The preferred rollback is restoring the full database backup. Do not use `DROP COLUMN` in the first migration cycle. The new columns may remain unused while the application compatibility layer is reviewed.

## If data backfill was applied

Restore the full backup. Do not reverse values with guessed SQL because legacy and canonical fields may have had different values before synchronization.

```powershell
mysql -h localhost -u root esport_korattest < esport_korattest_before_workflow_migration.sql
```

## After restore

1. Confirm `SELECT DATABASE();` returns `esport_korattest`.
2. Run the read-only pre-check again.
3. Compare table row counts and foreign keys with the saved pre-check output.
4. Do not run application pages that contain runtime schema mutation until the migration strategy is finalized.
