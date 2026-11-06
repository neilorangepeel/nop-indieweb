# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## State (6 Nov 2026)

- Version 0.10.1 is on GitHub `main`. Local `main` is **4 commits ahead
  (unpushed)**: two ROADMAP.md entries, this file, and the Safari filter fix.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

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

- Push `main` (needs your go-ahead).
- Hashtags: decide whether you want WP tags on Mastodon/Bluesky posts at all.
  If yes, rebuild on main (see ROADMAP.md).

## Open questions

- Delete the stale hashtag branch once the roadmap entry is settled?
- Other remote branches (`claude/nop-indieweb-rsvp-post-type-*`,
  `claude/post-location-refresh-*`, `experiment/halftone-fixed-shadows`,
  `pre-post-review`, `feature/interactivity-browsing`) are already merged into
  main and could be deleted.
