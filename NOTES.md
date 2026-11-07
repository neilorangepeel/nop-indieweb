# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## State (6 Nov 2026)

- Version 0.10.1, pushed and deployed 6 Nov (CI + Deploy green; live serves
  the Safari filter fix). The only local-only commit is this notes update.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

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

- **Live, when Neil says go:** deploy 0.11.0, then on the server
  `wp eval-file …/bin/retire-categories-except-work.php` (dry run, show Neil the
  counts) and only then `… apply`. Then `bin/hashtags-to-tags.php` the same way
  (dry run, show Neil, apply). Back up the live DB first.
- Structure steps 4–8 (see the theme's NOTES.md / STRUCTURE.md): next is 4,
  the Look taxonomy + applying the tag sort. Step 3 (`Kind\Hashtags`, #hashtag →
  tag, old posts backfilled locally) is done.
- Hashtags: decide whether you want WP tags on Mastodon/Bluesky posts at all.
  If yes, rebuild on main (see ROADMAP.md).

## Open questions

- Delete the stale hashtag branch once the roadmap entry is settled?
