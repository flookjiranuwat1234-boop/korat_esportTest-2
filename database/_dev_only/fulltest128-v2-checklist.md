# Full Test 128 V2 Checklist

| Test ID | Module | Scenario | Expected Result | Actual | Pass/Fail | Tables |
|---|---|---|---|---|---|---|
| V2-01 | Pool | 128 central Teams | Exactly 128, all `game_id IS NULL` | | | teams |
| V2-02 | Membership | Nine active members | Five player, one substitute, one Roblox, one coach, one manager | | | team_members |
| V2-03 | Eligibility | U18 age/gender | Male/female Category members are 13-17 and verified | | | players, categories |
| V2-04 | Eligibility | Roblox age | One member per Team is 8-12 at Roblox start date | | | players |
| V2-05 | Roster | Team snapshot | Exactly eight members; Roblox member excluded | | | registration_members |
| V2-06 | Registration | RoV Open | 128 Teams reuse central IDs | | | registrations |
| V2-07 | Registration | Free Fire Open | Same Team IDs work across Game context | | | registrations |
| V2-08 | Registration | RoV U18 | 64 male and 64 female registrations; no mixing | | | categories, registrations |
| V2-09 | Solo | Four games | 128 Player registrations per game, `team_id IS NULL` | | | registrations |
| V2-10 | Solo | No wrapper | No V2 Solo Wrapper Team | | | teams |
| V2-11 | Integrity | Duplicate/orphan | No duplicate Registration, orphan, or cross-Tournament Category | | | all related |
| V2-12 | Workflow | UI roster selection | UI must not snapshot all nine active members | | | UI/roster |
| V2-13 | Workflow | Check-in | Required roles aggregate correctly | | | checkins |
| V2-14 | Workflow | Group/bracket/match/ranking | Test through real UI/Backend; Seeder does not fake results | | | groups/matches/rankings |
| V2-15 | Cleanup | Dry run | Only V2 Manifest IDs are candidates | | | manifest |
