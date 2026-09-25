# Full Test 128 V2 Gap Report

- Existing UI registration still snapshots all active Team Members. With nine members, the UI can include the Roblox player unless a roster selector or caller-level selection is added. This V2 Seeder uses explicit eight-member Team Roster IDs and does not claim UI registration is fixed.
- No schema changes are permitted. The V2 Seeder therefore records Batch IDs only through prefixes and Manifest IDs.
- Group, Bracket, Match, Result, and Ranking are not seeded. They must be exercised through the real UI/Backend after fixture verification.
- `match_participants` has no confirmed Battle Royale workflow and is not populated.
- `ranking_rules` is absent and is not created.
- RoV U18 game selection must be unambiguous. V2 chooses the unique candidate with the greatest existing Team count and stops on a tie.
- If any Solo game cannot accept the 8-12 Pool under its real Category rules, V2 stops before the first INSERT and reports the game rather than manufacturing extra players.
