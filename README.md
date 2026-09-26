# Central Warehouse (CW)

Standalone stock, catalogue-link, purchasing and accounting service shared by the
Vape and Go, Vape Big and Electrofag storefronts. Design: see `docs/plan.md`
(copy of the approved plan, 26 Sep 2026).

Rule: everything is built and tested on staging + the proto sites first; nothing
touches a live site until the proto rehearsal is signed off.

Layout: `public/` (API + staff UI), `src/` (Stock.php is the only stock writer),
`migrations/`, `bin/` (cron/worker scripts), `tests/`, `tools/first_match/`
(read-only catalogue exports for the first-time product match).
