# Full Test 128 V2 Rollback

Batch: `FULLTEST_128_V2_20260828`

1. Stop test traffic and preserve `fulltest-FULLTEST_128_V2_20260828-manifest.json`.
2. Run the V2 cleanup tool without `--commit` and inspect every count.
3. Confirm database, V2 batch, prefixes, email domain, and every Manifest ID.
4. Only after review, run V2 cleanup with `--commit`; it deletes child rows before parent rows in one transaction.
5. Preserve the V2 Manifest by renaming it to a dated cleaned-manifest file after successful cleanup.
6. Run V2 verification and confirm Baseline counts and no V2 prefixes remain.

Do not use the V1 Cleaned Manifest, ID ranges, broad prefix-only deletes, `FOREIGN_KEY_CHECKS`, or manual row updates. No rollback command is run while preparing V2.
