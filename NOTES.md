# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## State (6 Nov 2026)

- Version 0.10.1, pushed and deployed 6 Nov (CI + Deploy green; live serves
  the Safari filter fix). The only local-only commit is this notes update.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

- **7 Nov 2026:** Entry_Fields `match_score_sep` ("–" with a score, "v"
  before) for the theme's five-column score line.

- **7 Nov 2026:** scorers set newspaper-style ("T. Conway 33, H. Hackney 71",
  "57 pen", "90+3 og"); new Entry_Fields `match_kicker` ("English Championship ·
  Wednesday 4 November") and `match_status_line` ("Full time · Half-time 0–1")
  for the theme's programme-style scoreboard.

- **7 Nov 2026:** timeline: automated rows (events and the full-time take,
  marked `is-automated` by its `nop_indieweb_match_ft_take` id) are bold
  instead of italic, Neil's call; the takes stay regular.

- **7 Nov 2026:** Entry_Fields `match_home_goals` / `match_away_goals` for the
  theme's scoreboard rows; the timeline's minute aligns to the first line
  (`align-items: baseline`) and its first row has no rule.

- **7 Nov 2026:** match data moved from openfootball to API-Football, one
  source (Neil's call: openfootball has no events, lags on results and has no
  cups). `Match_Day::api()` / `fixture_on()` / `sync()` (the fixture-by-id call
  includes the events, so one request a sync, at most once a minute, on every
  take and at full time); `Match_Events` (pure, tested in
  `tests/php/MatchEventsTest.php`) normalises the events and gives the running
  score and scorers. New meta: `match_fixture_id`, `match_venue`,
  `match_ht_score`, `match_events`. The timeline shows events in italics (goals
  bold); the theme's scoreboard reads the new fields. Built against a
  hand-made sample in the API's documented shape; not yet run against the real
  API (no key yet). Removed `Match_Clock::season()` and `team_name()`.

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
- **7 Nov 2026:** full-time take: the full-time job (kick-off + 3h) posts a note
  as the last take ("Full time: … Every take from the match: <link> #UTB"),
  once per match (`nop_indieweb_match_ft_take`), skipped for matches over a day
  old. Bluesky now facets bare URLs in the body (`build_url_facets()`), which
  also fixes links in ordinary notes. Tested locally on today's fixture.
- **7 Nov 2026:** match page parts: `blocks/match-timeline` (SSR, structure CSS
  only; the theme styles the minute), Entry_Fields `match_*` plus `detail` and
  `subtitle` for matches, `Match_Day::takes()`, kind icon `kind-match.svg`
  (Phosphor soccer-ball), `match` in the Logbook kinds, takes left out of the
  Logbook main query (`Logbook::not_a_take()`). Tile, overlay and page are in
  Orange Peel.

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

- **Match kind, next steps:** (1) Neil is getting an API-Football key: add
  `define( 'NOP_API_FOOTBALL_KEY', '…' );` to wp-config.php (local, then live),
  then `wp nop-indieweb match start --date=2026-11-04` (or another past Boro
  game) to test against real data: check the team id lookup, events, subs'
  player/assist order and which team an own goal is filed under (assumed: the
  player's own team, so it counts for the other side). Check the free plan
  covers the 2026 season. (2) Watch the first real match thread on live (every
  platform is off locally).

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
