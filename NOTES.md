# nop-indieweb — notes

Read this first on any Mac. Newest entries at the top. Longer-term plans live
in ROADMAP.md, how to work here in CLAUDE.md.

## Where we are (9 Nov 2026)

- **RESUME HERE (10 Nov): Logbook performance, step 2 DONE locally.**
  Was: first ?tag view 93 s on dev, because `Wall_Filters::toggle()` ran one
  count WP_Query per tag pill (1,609 tags). Now `Wall_Filters::counts()` gets
  every pill's count for a view in ONE grouped SQL query (the wall's post IDs →
  COUNT GROUP BY term slug), cached in a transient like count(). Local timings
  (Studio, cold cache): ?tag=belfast 16.9 s → 0.7 s, ?kind=note 8.9 s → 1.6 s,
  ?tag=belfast+film 20.5 s → 0.5 s; pill counts/empty states identical.
  Next: (a) push develop and re-measure on dev; (b) images (44 imgs, no
  fetchpriority) and 85 KB of inline styles. Not trimming the Tags drawer
  (360 KB, all tags as pills): the new tagging system in progress should
  replace it (Neil, 10 Nov).

- Work on `develop`. Pushing it deploys to dev.neilorangepeel.com (subsite 28,
  live is 6). `main` is live; going live = the wp-deploy skill.
- On dev but not live: nothing yet.
- Live version: 0.10.1.
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

## State (6 Nov 2026)

- Version 0.10.1, pushed and deployed 6 Nov (CI + Deploy green; live serves
  the Safari filter fix). The only local-only commit is this notes update.
- Mastodon account: `https://mastodon.social/@neilorangepeel`.

## Changed

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

- Hashtags: decide whether you want WP tags on Mastodon/Bluesky posts at all.
  If yes, rebuild on main (see ROADMAP.md).

## Open questions

- Delete the stale hashtag branch once the roadmap entry is settled?
