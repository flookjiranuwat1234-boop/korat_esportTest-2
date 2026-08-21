# Test Data Reset Backup Instructions

Database target from `config/db.php`:

- Host: `localhost`
- Database: `esport_korattest`
- User: `root`
- Password: configured locally in `config/db.php`

## Before running anything

1. Stop application writes and close the admin pages.
2. Confirm the active database:

```sql
SELECT DATABASE();
```

It must return `esport_korattest`.

3. Export the complete database, including structure, data, triggers, routines, and events. From a terminal with `mysqldump` available:

```powershell
mysqldump -h localhost -u root --single-transaction --routines --triggers --events esport_korattest > esport_korattest_before_test_reset.sql
```

If the local MySQL user has a password, add `-p` and enter it interactively.

4. Verify that the dump exists and is not empty before continuing.

## Restore

```powershell
mysql -h localhost -u root esport_korattest < esport_korattest_before_test_reset.sql
```

If the local MySQL user has a password, add `-p` and enter it interactively.

The reset scripts in this project are intentionally not executed by this change. Run the read-only check first, review it, then run the reset SQL and PHP seeder only after confirming the backup is restorable.
