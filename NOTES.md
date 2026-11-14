# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## Where we are (12 Nov 2026)

- **14 Nov, photos: shrink on the phone, keep the details, no location in files**
  (on `develop`, not pushed). `/post` shrinks JPEGs to 2560px before upload
  (`shrinkJpeg()` in uploadMedia: ~5 MB → ~1 MB), carrying the EXIF block across
  with orientation reset. New `Media\Photo_Metadata`: on upload adds make, lens,
  35mm focal length, exposure compensation/mode, metering, flash, white balance,
  time zone, software to `image_meta` (core already has camera, aperture, focal
  length, ISO, shutter, date, credit, copyright); location goes to protected meta
  `_nop_indieweb_photo_location` (not in REST); deletes the full-size original;
  strips EXIF/XMP/IPTC from every JPEG losslessly, ICC kept. Applies to every
  upload route (REST, Micropub media, importers). Tested: headless Chrome shrink
  (orientation 6 photo comes out upright) + local import of both sizes. Not yet
  tested on the iPhone's Safari. Live check 12 Nov: phone uploads arrive full
  size (up to 9 MB); one original (#24934) has GPS in a public file.
  **Open:** (1) a dry-run script for live's existing photos (pull the details
  and location into meta, strip, delete originals); (2) the Shortcut still
  sends full size (fine: the server does the rest).
- **14 Nov, iPhone test on dev passed** (post #24946: 1920×2560, 929 KB
  uploaded, details saved, files clean). Found: SiteGround Optimizer keeps a
  `.bak` of each upload (with EXIF) and re-compresses lossy (929 → 223 KB;
  dev settings: compression_level 2, quality_type lossy, backup_media 1,
  webp 85). **Then, for quality:** every size gets back the colour profile of
  the photo it was made from (GD drops it but keeps the pixel values, so P3
  showed flat); `.bak` copies are stripped too; Bluesky tries the 2048px size
  before 1000px (it shows up to 2000px). Proposed to Neil, not done: SG image
  compression off and backups off on dev, keep its WebP (85); pages already
  load the srcset sizes (GD q82) and WebP, so the 2560 main can stay q90.

- **12 Nov, Kind\Hashtags reworked (on `develop`, not pushed):** a hashtag now
  files a post only if it is one of a subject's names: `Hashtags::SUBJECTS`
  holds the 18 subjects from the theme's STRUCTURE.md (#UTB → Boro, #WebDesign →
  Web). Matching ignores case, spaces and punctuation; any other hashtag stays a
  word. Filter `nop_indieweb_subjects`. `bin/hashtags-to-tags.php` uses the same
  rule (local dry run: 9 posts gain Boro, nothing else). PHPUnit 77 + PHPStan
  clean. Dev has been running the old every-hashtag rule since the 12 Nov push,
  so posts saved there may carry stray tags; the merge script will clear them.
- **12 Nov, subject merge script built: `bin/merge-subjects.php`** (dry run
  unless given `apply`). A subject's names merge into its tag (BORO renamed
  Boro, UTB merged in); place tags are KEPT until Place is built; the drop
  list and tags on 2 posts or fewer are deleted; anything else on 3+ posts is
  kept and listed for you to sort. Then `hashtags-to-tags.php apply` and
  `wp transient delete --all`. Tested on local: second run finds nothing.
  **Local's tags were changed by that test** (37 tags left, from 56; test
  data). The `wp db export` before it failed and wasn't checked first, so
  there is no copy of local's tags from before the test. Nearest copy:
  `~/Studio/_backups/neilorangepeel-before-retire-categories-2026-11-07.sql`.
  State after the test: `~/Studio/_backups/neilorangepeel-after-subject-merge-2026-11-12.ht.sqlite`.
- **12 Nov, live dry run (read-only; a self-contained copy piped over SSH with
  apply hard-off, nothing uploaded):** 135 tags merge into the 18 subjects
  (1,266 post links), 1,390 deleted (1,349 on 2 posts or fewer, 41 on the drop
  list incl. twitter 11,977 and Facebook 1,587), 42 kept (23 places, 19
  unsorted). Unsorted, for Neil: clayton, gif, Halloween, HolyJubilE3,
  hprodeo, KyrgiosTsitsipas, lobo, Narable, Nature, NewYearsEve, picasso,
  retro, SINNEAD, Social Media, themac, THESLIDER, trex, Vault Artist Studios,
  Video. **Next:** Neil sorts those (subject name / place / drop) in
  STRUCTURE.md + SUBJECTS; then release + apply on live.
- **12 Nov, 50 variant names added** to SUBJECTS (and STRUCTURE.md) from live
  tags on 1-2 posts (adfringe, AnimalCros, Middesbrough, ponydanceco…).
  Re-run on live: 185 tags merge (1,324 links), 1,340 deleted, 42 kept.
  Left for Neil, not added (too broad as hashtags): Canon, Nikon, Tees, theme,
  coloursofnature, ponies, go busters.
  **From is safe:** every post tagged twitter (11,977), Facebook (1,587) or
  nikeplus (91) has `nop_indieweb_platform` on live. nikeplus posts are tweets,
  so they'll show as From: Twitter; "Nike+" itself isn't kept anywhere.

- **12 Nov:** the 7 Nov work from the other Mac (0.11.0 categories retired
  except Work, #hashtag → tag, 0.12.0 Match kind) is merged into `develop` on
  top of 0.10.2. Version is 0.12.0, minimum WordPress stays 7.1. Local `main`
  matches GitHub (live) again; the old local main is kept as branch
  `backup/mac-main-2026-11-08`; `wip/structure` is now inside develop.
  Pushing develop puts all of it on dev: categories aren't removed from dev's
  data until `bin/retire-categories-except-work.php apply` runs there, and the
  hashtag rule tags any post saved on dev.

- **RESUME HERE: 0.10.2 is live (11 Nov): Logbook performance and the audit
  fixes.** Live after the release: `/`, `/logbook/`, `?tag=belfast`,
  `?kind=note` all 200 in about 0.5 s. Timed on dev 11 Nov: `/logbook/`,
  `?tag=belfast`, `?kind=note`, `?tag=belfast+film` each 1.1 to 1.3 s on a
  first view after a cache purge, 0.27 s repeated. Orange Peel's tile crops
  regenerated on dev the same day; `bin/refresh-dev.sh` now does this itself
  after each refresh when dev runs Orange Peel (adds about 25 minutes).
  Next: (a) 85 KB of inline styles on the Logbook; (b) the "Open, your
  call" list in the audit section. The server checkout has two untracked
  files, `assets/css/nop-blocks-shared.min.css` and
  `nop-indieweb-browse.min.css` (not ours; left alone).
  Background: was first ?tag view 93 s on dev, because `Wall_Filters::toggle()` ran one
  count WP_Query per tag pill (1,609 tags). Now `Wall_Filters::counts()` gets
  every pill's count for a view in ONE grouped SQL query (the wall's post IDs →
  COUNT GROUP BY term slug), cached in a transient like count(). Local timings
  (Studio, cold cache): ?tag=belfast 16.9 s → 0.7 s, ?kind=note 8.9 s → 1.6 s,
  ?tag=belfast+film 20.5 s → 0.5 s; pill counts/empty states identical.
  Not trimming the Tags drawer
  (360 KB, all tags as pills): the new tagging system in progress should
  replace it (Neil, 10 Nov).

- Work on `develop`. Pushing it deploys to dev.neilorangepeel.com (subsite 28,
  live is 6). `main` is live; going live = the wp-deploy skill.
- On dev but not live: nothing.
- Live version: 0.10.2 (released 11 Nov).
- Dev has live's content (copied 9 Nov with `bin/refresh-dev.sh`; refresh any
  time: `ssh neil.rocks 'bash -s' < bin/refresh-dev.sh`).
- Dev runs the **Orange Peel** theme (switched 9 Nov), not `nop-dev`.
  refresh-dev.sh keeps whatever theme dev has. Back to nop on dev: set
  dev's `template`/`stylesheet` options to `nop-dev`, then delete the
  `alloptions` cache.
- On `develop`, not yet on main: deploys stop on a failed step (`set -e`);
  minimum WordPress lowered to 7.1. The live deploy and the dev deploy both
  read their workflow from `main`, so the `set -e` fix takes effect at the next release.
- Unmerged branches kept for you to decide: `claude/activitypub-indieweb-integration-nvvnQ`
  (local), and on GitHub `claude/hashtag-inline-mastodon-bsky-jkoo4k`,
  `park/look-filter`, `wip/structure`.

## Audit (10 Nov 2026): core first, DRY, performance, accessibility, UX

PHPUnit (58) and PHPStan clean before and after. Fixed (pushed to
`develop` 10 Nov, on dev; CI + Deploy green):
- **Six fewer queries on every page view.** Four finished one-time
  migrations each checked a "done" flag that was not autoloaded (a query
  apiece per request), as did the Logbook rewrite flag and the wall counts
  version. One autoloaded flag, `nop_indieweb_migrations_done`, now stands in
  front of the migrations, and the other two are autoloaded
  (`wp_set_options_autoload()`). Measured locally: 6 queries → 0. The first
  request after a deploy runs the old checks once more, then never again.
- **Maps load with their own page.** A check-in's or exercise's map was
  `loading="lazy"` everywhere, including its own page where it is the main
  picture. `nop_indieweb_map_loading()`: eager there, lazy in the overlay,
  lists and the photo strip.
- **uninstall.php** removes the eight options it used to leave behind.
- **Wall counts are stored for the plain views only** (`Wall_Filters::kept()`):
  no search, and either no tag (any kinds) or one real tag with at most one
  kind. Two or more tags, or a made-up tag, are counted afresh (about 0.5 s
  locally) and write nothing, so the address can no longer fill the options
  table.

Open, your call:
- **The four migrations can simply be deleted** once you are sure every copy
  (live, dev, both Macs) has run them: about 75 lines.
- **Patterns:** `Pattern_Registrar` builds ten patterns from PHP strings on
  every request (613 lines). `register_block_pattern()` takes a `filePath`
  since 6.5, which loads the content only when it is used.
- **Template contents** sit in one ~145 KB transient read on every request
  to save 34 small file reads; with no object cache the files may be quicker.
  Measure on the server before changing.

Left by design: `permission_callback => '__return_true'` on the 14 routes
that authenticate themselves; unprepared `$wpdb` calls have no variables in
them; `posts_per_page => -1` only in migrations and the cached counts.

## State (6 Nov 2026)

- Version 0.10.1, pushed and deployed 6 Nov (CI + Deploy green; live serves
  the Safari filter fix). The only local-only commit is this notes update.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

- **12 Nov 2026:** Logbook pill counts leave out match takes (`Wall_Filters::args()`
  adds `Logbook::not_a_take()`), so a filter's count matches the wall, which
  already hid them. Gap from the 7 Nov Match work, found while merging.
- **11 Nov 2026:** released 0.10.2 to live (develop merged into main):
  Logbook pill counts in one query, wall counts stored for plain views only,
  six fewer option queries, eager maps, uninstall, minimum WordPress 7.1,
  deploy `set -e`. Checked on dev by Neil first.
- **11 Nov 2026:** `bin/refresh-dev.sh` regenerates Orange Peel's tile crops
  at the end when dev runs Orange Peel: live's media records don't list them,
  so each refresh loses them. Not run yet since the change.
- **10 Nov 2026:** audit (core first, DRY, performance, accessibility, UX),
  see the audit section; and Logbook pill counts in one grouped query
  (`Wall_Filters::counts()`). Pushed to `develop`, deployed to dev, dev's
  Logbook returns 200. Not live.
- **9 Nov 2026:** dev switched to Orange Peel. `bin/refresh-dev.sh` keeps
  dev's current theme (only maps live's nop Site Editor changes when dev
  runs nop-dev) and clears dev's options cache at the end. A plain
  `wp cache flush` left stale options twice today.
- **9 Nov 2026:** deploy scripts get `set -e`, so a failed `cd`/`git pull`
  turns the run red. Minimum WordPress 7.2 → 7.1: the Icon API and bindings
  are core from 7.1; only the Icon block's `icon` binding needs 7.2 and is
  ignored before then. Deleted 5 merged local branches.
- **9 Nov 2026:** `bin/refresh-dev.sh` copies live (6) onto dev (28): core
  tables only, media hard-linked, addresses rewritten, dev keeps its own
  plugins/theme, title "DEV — Neil Orange Peel", noindex, syndication
  credentials stripped. Ran once: 18,000 posts on both, dev can't syndicate.
- **9 Nov 2026:** dev.neilorangepeel.com is live as subsite 28: parked domain
  in SiteGround (wildcard cert covers it), `NOP_INDIEWEB_LIVE_HOST` in the
  server wp-config (backup `~/wp-config.php.bak-20261109`), `plugins/nop-indieweb-dev`
  and `themes/nop-dev` on `develop`, active on dev only, noindex. Checked: dev
  can't syndicate (flag false), live can (true), both return 200.
- **9 Nov 2026:** dev environment groundwork. New `nop_indieweb_is_production()`
  (`includes/utils/functions.php`): when wp-config defines
  `NOP_INDIEWEB_LIVE_HOST`, only that host syndicates, sends webmentions or
  pings the hub. Unset = every site is live, so the deploy changes nothing on
  live until the constant is added. Syndication attempts off-production are
  journalled as "Skipped — this is not the live site". Local Studio wp-config
  now defines it. Added `deploy-dev.yml` (develop → `plugins/nop-indieweb-dev`)
  and the `develop` branch. Test: `tests/php/IsProductionTest.php`.
- **7 Nov 2026:** short names (Neil: always use a team's short name where one
  exists). API-Football's fixtures have no short-name field, so: a per-match
  `nop_indieweb_match_home_short` / `_away_short` meta wins (editor: Custom
  fields panel, or `wp post meta update`), else `Match_Clock::SHORT_NAMES`
  (now also Palace, MK Dons) via `nop_indieweb_match_short_names`, else the rule
  (leading AFC/FC and trailing club words dropped: "AFC Bournemouth" →
  "Bournemouth"). Tested a long name ("Peterborough", 10–2) on the scorecard:
  fits edge to edge on desktop and phone.

- **7 Nov 2026:** Entry_Fields `match_home_short` / `match_away_short` /
  `match_home_goals` / `match_away_goals` for the theme's scorecard (replacing
  `match_line`).

- **7 Nov 2026:** scoreboard as one line: `Match_Clock::short_name()` (tested;
  "Middlesbrough" → "Boro", club suffixes dropped, a few listed names; filter
  `nop_indieweb_match_short_names`) and Entry_Fields `match_line` ("Wrexham 1 2
  Boro", "Wrexham v Boro" before a score). Removed the five-column fields
  (`match_board_score`, `match_home_name`/`away_name`, `match_home_goals`/
  `away_goals`, `match_score_sep`).

- **7 Nov 2026:** match page polish (Neil said yes to all): the timeline hides a
  take's typed minute/score (`Match_Clock::strip_typed_lead()`, tested; social
  copies keep them) and shows the full-time take as just "Full time: <result>."
  (no link to itself); goals carry the running score under the minute and are
  bold, other events (cards, subs, VAR) quieter caps; goal text no longer
  repeats the score. `match_status_line` now also holds the ground and "KO
  19:45" (the theme's facts list is gone), `match_kicker` uses "Wed 4 Nov".

- **7 Nov 2026:** Entry_Fields `match_score_sep`: "v" before a score, empty
  after (the theme draws a rule in that cell) for the five-column score line.

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

- **0.11.0 and 0.12.0 are on `develop`** (merged 12 Nov with 0.10.2), not on
  main/live. Release only when Neil says go, and not before `Kind\Hashtags`
  is reworked (below) or switched off for the release. Look work: branch
  `park/look-filter`.
- **Live, when Neil says go:** release 0.11.0/0.12.0, then on the server
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
