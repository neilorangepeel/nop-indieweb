# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## State (6 Nov 2026)

- Version 0.10.1, pushed and deployed 6 Nov (CI + Deploy green; live serves
  the Safari filter fix). The only local-only commit is this notes update.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

- **7 Nov 2026:** 0.12.0 adds the Match kind, data side only (ROADMAP "Match
  kind"; decided with Neil: openfootball fixtures, minute from the clock, score
  from the takes, API-Football as a later optional add-on). Code in
  `includes/football/` (pure clock and text parsing in `class-match-clock.php`,
  tested in `tests/php/MatchClockTest.php`; hooks in `class-match-day.php`),
  meta in the registry, `wp nop-indieweb match fixture|start`. Smoke-tested
  locally on Wrexham v Boro (4 Nov): match post created, takes stamped
  (pre-match, 26, HT, 88), score taken from "88' 1-1", nothing syndicated; test
  posts deleted. Neil decided: only #UTB notes become takes; others are left
  alone. Retested.
- **7 Nov 2026:** the match social thread. The match post now syndicates (opener
  text written at creation, editable) and each take replies to the previous one:
  `Syndicator_Base::thread_parent_url()` walks `Match_Day::thread_before()`
  (5 earlier takes, then the match post), sends a parent still waiting for its
  first attempt, skips failed ones. Bluesky threads through `build_reply_ref()`,
  Mastodon uses the stored status id. Tested locally with fake URLs only.
  Tumblr (if switched on) would still get takes as separate posts.

- **7 Nov 2026:** 0.11.0 retires categories except Work (step 2 of the theme's
  STRUCTURE.md): no kind→category defaults, services and `/post` stop filing
  categories, settings lose the Category fields, old `/category/<slug>/`
  addresses redirect. Local data cleaned with
  `bin/retire-categories-except-work.php apply` (15 categories, 2,033 links;
  backup `~/Studio/_backups/neilorangepeel-before-retire-categories-2026-11-07.sql`).
  Deleted the finished `bin/migrate-topics.php` and `bin/retire-categories.php`.
- **6 Nov 2026:** deleted merged branches (local + GitHub): feat/entry-fields,
  feat/flight-arcs, overlay-redesign, refactor/plugin-decomposition,
  claude/nop-indieweb-rsvp-post-type-*, claude/post-location-refresh-*,
  experiment/halftone-fixed-shadows, feature/interactivity-browsing,
  pre-post-review. Only the unmerged hashtag branch remains.
- **6 Nov 2026:** `assets/js/browse.js` `outsideFilters` ignores clicks on
  elements no longer in the page. In Safari, tapping a Logbook filter chip
  swapped the wall and left the filter drawer unable to reopen. Confirmed fixed
  in Safari by Neil.
- **6 Nov 2026:** ROADMAP.md gained "WordPress tags as hashtags on Mastodon and
  Bluesky". The June branch `claude/hashtag-inline-mastodon-bsky-jkoo4k` is
  stale and conflicts, so it's recorded as a prototype rather than merged.
  `#nobridge` isn't needed: no Bridgy Fed copy of the Mastodon account exists
  on Bluesky.

## Next

- **Match kind, next steps:** (1) watch the first real match thread on live
  (only testable there: every platform is off locally); (2) keep takes off the
  Logbook wall; (3) a Match timeline block and a
  Match template in the theme (design to be redone: Neil liked the mock-up's
  content, not its look); (4) a kind icon. Cup games aren't in openfootball's
  league files, so they need `match start` by hand (which also needs a fixture,
  so a manual-fixture option is still to do).

- **7 Nov 2026 work is NOT on GitHub main** (main deploys to live). It is on
  branch `wip/structure`; on another Mac: `git fetch && git switch main &&
  git merge --ff-only origin/wip/structure`. Push main only when Neil says go
  live. Look work: branch `park/look-filter`.
- **Live, when Neil says go:** deploy 0.11.0, then on the server
  `wp eval-file …/bin/retire-categories-except-work.php` (dry run, show Neil the
  counts) and only then `… apply`. Back up the live DB first. **Do not deploy
  `Kind\Hashtags` or run `bin/hashtags-to-tags.php` on live as built**: it turns
  every hashtag into a tag (thousands from the 11,977 imported tweets). Rework
  it first (next item).
- **Next: rework `Kind\Hashtags`** so a hashtag files a post only when it is one
  of a subject's names (the subject table in the theme's STRUCTURE.md: `#UTB` →
  Boro); any other hashtag stays a word. Then a merge script for the subject table
  (dry run against live counts first). The theme's STRUCTURE.md has the new
  steps (built from the live content on 7 Nov; the old Look work is parked on
  `park/look-filter`).

## Open questions


- Delete the stale hashtag branch once the roadmap entry is settled?
