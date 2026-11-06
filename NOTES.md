# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## State (6 Nov 2026)

- Version 0.10.1 is on GitHub `main`. Local `main` is **2 commits ahead
  (unpushed)**: both are ROADMAP.md entries from 6 Nov.
- **Uncommitted:** `assets/js/browse.js`, a Safari fix in `outsideFilters`: it
  ignores clicks on elements no longer in the page, so the Logbook filter
  drawer can reopen after a chip swaps the wall. Not by Claude in the 6 Nov
  session; test in Safari, then commit.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

- **6 Nov 2026:** ROADMAP.md gained "WordPress tags as hashtags on Mastodon and
  Bluesky". The June branch `claude/hashtag-inline-mastodon-bsky-jkoo4k` is
  stale and conflicts, so it's recorded as a prototype rather than merged.
  `#nobridge` isn't needed: no Bridgy Fed copy of the Mastodon account exists
  on Bluesky.

## Next

- Commit the `browse.js` fix once it's checked in Safari, then push (needs your
  go-ahead).
- Hashtags: decide whether you want WP tags on Mastodon/Bluesky posts at all.
  If yes, rebuild on main (see ROADMAP.md).

## Open questions

- Delete the stale hashtag branch once the roadmap entry is settled?
- Other remote branches (`claude/nop-indieweb-rsvp-post-type-*`,
  `claude/post-location-refresh-*`, `experiment/halftone-fixed-shadows`,
  `pre-post-review`, `feature/interactivity-browsing`) are already merged into
  main and could be deleted.
