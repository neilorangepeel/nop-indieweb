# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## Where we are (9 Nov 2026)

- Work on `develop`. Pushing it deploys to dev.neilorangepeel.com (once the dev
  subsite exists). `main` is live; going live = the wp-deploy skill.
- On dev but not live: nothing yet.
- Live version: 0.10.1.
- Next: set up the dev subsite (see Next below).

## State (6 Nov 2026)

- Version 0.10.1, pushed and deployed 6 Nov (CI + Deploy green; live serves
  the Safari filter fix). The only local-only commit is this notes update.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

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

- Dev environment, server side (Neil, via `!` or SiteGround):
  1. Push `main` (deploys the guard; harmless while the constant is unset).
  2. Add `define( 'NOP_INDIEWEB_LIVE_HOST', 'neilorangepeel.com' );` to the
     server's wp-config.php.
  3. Check nop-indieweb is activated per site, not network-wide.
  4. Create subsite dev.neilorangepeel.com + DNS; discourage search engines.
  5. On the server: `git clone -b develop` the repo into `plugins/nop-indieweb-dev`
     (and the theme into `themes/nop-dev`); activate them on dev only.
  6. Push `develop` and check Deploy to dev goes green.
- Hashtags: decide whether you want WP tags on Mastodon/Bluesky posts at all.
  If yes, rebuild on main (see ROADMAP.md).

## Open questions

- Delete the stale hashtag branch once the roadmap entry is settled?
