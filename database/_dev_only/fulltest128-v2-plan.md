# Full Test 128 V2 Plan

Batch: `FULLTEST_128_V2_20260828`

## Fixed entities

- 128 new central Teams, each with `game_id = NULL`.
- 1,152 new Users and Player Profiles.
- 1,152 active Team Membership rows per 9 members per team.
- Members 1-5: U18 starters; member 6: U18 substitute; member 7: Roblox/Solo player; member 8: Coach; member 9: Manager.
- Teams 001-064 use male U18 competitors; Teams 065-128 use female U18 competitors.
- Member 7 is aged 8-12 at the Roblox Tournament start date and is excluded from every Team Tournament Roster.

## Planned registrations

- Six Team Tournaments use the same 128 Team IDs: 640 registrations total.
- Four Solo Tournaments use member 7 from each Team: 512 registrations total.
- Team Roster: 640 x 8 = 5,120 rows.
- Solo Roster: 512 x 1 = 512 rows.
- Total registration members: 5,632.
- Draft U18 Tournament has no registrations.

## Status map

- RoV Open Completed: `completed`, 128
- Free Fire Open: `registration_open`, 128
- RoV U18 Closed: `registration_closed`, 128 split male/female 64/64
- RoV Open Ongoing: `ongoing`, 128
- Free Fire Cancelled: `cancelled`, 128
- RoV U18 Draft: `draft`, 0
- Tekken, Street Fighter, Efootball, Roblox: `registration_open`, 128 Solo registrations each

## Eligibility

U18 competitors are calculated with full birth date against the Tournament start date and must be 13 through 17 inclusive with `eligibility_status = verified`. Roblox players are calculated as 8 through 12 inclusive against the Roblox start date. Staff are not marked as competitive players and are not included in the U18 starter/substitute count.

The Seeder must resolve games from live records and stop on ambiguity. It must also verify Solo eligibility for every selected game before any transaction begins. It must not create additional Solo players.

## Safety

V2 uses a new Manifest path and prefixes. It is Dry Run by default; `--commit` requires the exact database name, exact batch, and a recent non-empty operator-provided backup. It does not call schema helpers, change existing rows, disable foreign keys, create Groups/Matches/Ranking, or touch V1 Cleaned Manifest files.
