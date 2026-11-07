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
