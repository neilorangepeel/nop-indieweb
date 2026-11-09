# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## Where we are (9 Nov 2026)

- Work on `develop`. Pushing it deploys to dev.neilorangepeel.com (subsite 28,
  live is 6). `main` is live; going live = the wp-deploy skill.
- On dev but not live: nothing yet.
- Live version: 0.10.1.
- Dev has live's content (copied 9 Nov with `bin/refresh-dev.sh`; refresh any
  time: `ssh neil.rocks 'bash -s' < bin/refresh-dev.sh`).
- Mac crashed mid-session 9 Nov. Still to do from the dev list: make failed
  deploys go red, decide the WP 7.2 requirement (server is 7.1.4), switch the
  global CLAUDE.md to `develop` for these repos, tidy old merged branches.

## State (6 Nov 2026)

- Version 0.10.1, pushed and deployed 6 Nov (CI + Deploy green; live serves
  the Safari filter fix). The only local-only commit is this notes update.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

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

- Dev content: write and run a copy script (live subsite 6 → dev 28 tables,
  site address to dev but media left on live, clear API tokens on dev).
- The header says `Requires at least: 7.2` but the server runs 7.1.4 (latest
  stable), so WordPress refuses to *activate* the plugin there. Live and dev
  run fine; on dev it was activated via the `active_plugins` option. Lower it
  to 7.1, or leave it until 7.2 ships?
- The deploy workflows don't stop on a failed `cd`/`git pull`, so a broken
  deploy can still show green. Add `set -e` to the scripts?
- Hashtags: decide whether you want WP tags on Mastodon/Bluesky posts at all.
  If yes, rebuild on main (see ROADMAP.md).

## Open questions

- Delete the stale hashtag branch once the roadmap entry is settled?
