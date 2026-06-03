# Roadmap

Forward-looking notes for `nop-indieweb`. Items here are not promises — they're shaped ideas waiting for prioritisation. Move items to `## Done` when shipped.

## Foundations

Principles drawn from active IndieWeb practitioners — primarily Max Böck ([The IndieWeb for Everyone](https://mxb.dev/blog/the-indieweb-for-everyone/), [Using Webmentions in Eleventy](https://mxb.dev/blog/using-webmentions-on-static-sites/)) and Zach Leatherman ([Own Your Content on Social Media Using the IndieWeb](https://www.zachleat.com/web/own-your-content/)). Read in full before the next development session.

**Operating principles:**

- **Friction is the enemy.** The IndieWeb's losing position vs. silos is a friction problem, not a protocol problem. Any feature that needs explaining to the visitor has already lost the 95%.
- **Design before protocol.** A webmention rendered as raw mf2 data looks academic. Visual polish of the basic experience matters more than the next clever IndieWeb feature.
- **Aggregate, don't enumerate.** Facepile likes and reposts. Thread replies. Don't render 30 likes as 30 rows.
- **Show provenance.** Make it visible when a comment came via Mastodon-via-Bridgy vs. a direct webmention vs. a native WP comment.
- **Bridges over native federation.** Lean on Bridgy / webmention.io / Webfinger rather than reimplementing federation plumbing.
- **Treat the site as a publication, not a forum.** Conversations naturally happen on the platforms they originated on. The site is the canonical archive of those conversations, not the venue for new ones.
- **Clever features serve the curious. Display polish serves everyone.** Prioritise the second.

**Current state audit — already in place (`blocks/webmentions/render.php`):**

- Likes and reposts facepiled separately, with author photos from meta.
- Replies threaded, mixing webmentions + WP comments in one stream.
- Platform tags ("Mastodon", "Bluesky") visible per-reply.
- SVG fallback avatars; accessible markup; reply links integrated with native WP threaded UI.
- Original-platform URL linked from each reply.

**Foundation gaps still open:**

1. **Comment form friendliness audit.** Open the standard WP comment form on a recent post through the eyes of a non-IndieWeb visitor. Does it look modern and inviting, or developer-built? Is the path to leaving a comment obvious? Adjust theme styling and field labels if needed.
2. **Mobile rendering audit.** Verify the facepile, threaded replies, and platform tags render cleanly on narrow viewports.

**Implication for the rest of this roadmap:** clever items below (Reply Router, polymorphic identity form, ActivityPub) all sit *downstream* of these remaining audits. Don't build the clever surface until the basics serve everyone well.

## Architecture evolution

A phased rework of how post kinds, structured meta, and per-kind editor UX hang together. Motivated by an architecture review of the current plugin against a target model: Posts for stream content (notes, checkins, photos, watches, listens, bookmarks, collections), Pages for evergreen, a Portfolio CPT for work, and a `kind` taxonomy that drives URLs, templates, microformats, and editor sidebars.

Phase 1 has shipped — see **Done** for details. The authority model (term canonical, meta as derived read-cache) is enshrined in **Decisions and constraints** below.

### Phase 2 — Editor consolidation + `Lookup_Provider_Base`

Fold the **Venue** panel into the **Post Kind** panel as a kind-specific sub-view. End state: two sidebars total (Post Kind, Syndication). The Post Kind panel transforms based on selected kind — note shows just the selector, checkin shows venue display, watch/listen/collection show search UIs.

The editor panel is now config-driven: `Kind_Taxonomy::get_editor_panel_config()` is the single source of truth for per-kind dropdown labels, fields, starter layouts, and title behaviour. Phase 2 extends the per-kind config schema with a new `sub_panel` key (e.g. `'sub_panel' => 'venue'` for checkin, `'sub_panel' => 'lookup:tmdb'` for watch) and JS renders the matching sub-component. No more hardcoded kind branches in the panel JS.

Establish `includes/lookup/Lookup_Provider_Base` to mirror `Service_Base` but for **interactive editor lookups**:

- REST endpoint per provider: `/wp-json/nop-indieweb/v1/lookup/{provider}?q=…`
- Per-provider credential storage in plugin settings.
- One shared "lookup picker" JS component reusable across panels (search input → debounced fetch → results list → on-pick, write meta + sideload cover art).
- Server-side cover art sideloading via the existing `Service_Base::sideload_photos()` helper.

Ship with TMDB as the first provider (simplest API-key-only flow), wiring the Watch kind to interactive in-editor lookup.

### Phase 3 — Listen kind

Last.fm or MusicBrainz as the lookup provider. Listen kind, sub-panel, `u-listen-of` (or equivalent) microformat output. The `listen` slot already exists in `get_editor_panel_config()` with empty fields — Phase 3 fills it in with the sub-panel reference and any direct fields the lookup flow surfaces.

### Phase 4 — Collection kind

The big one. Multi-week build.

- `nop_kind` term `collection` (assumes Phase 1 done) + child terms `music`, `film`, `book` (already seeded in `HIERARCHICAL_KINDS`).
- `collection_format` taxonomy: music, film, book, game, etc.
- `collection_format_type` taxonomy: vinyl, cd, tape, bluray, dvd, hardback, ebook, etc.
- Meta fields per format, all `nop_indieweb_collection_*` (consistent with existing prefix, not `_collection_*`).
- Lookup providers: Discogs (music), TMDB (film, reusing existing), OpenLibrary (books).
- Collection sub-panels per format — registered as entries in `get_editor_panel_config()` so the existing dropdown picks them up. Decide before designing: do `music`/`film`/`book` get top-level dropdown entries, or does the panel reveal them as a second-level selector when `collection` is chosen? The current config is flat; the second option needs a small config-schema extension.
- Archive templates: `/collection/`, `/collection/music/vinyl/`, `/collection/film/bluray/`, etc.
- Microformat design decision: no standard "collection" mf2 — likely `h-cite` + `h-product` nested in `h-entry`. Write up before committing.

**Open question (resolve before starting):** catalogue model (many lightweight entries with same shape, optimised for filtering) or post model (full editorial entry per item with prose in the canvas)? May be both — canvas-for-prose preserves the option.

### Phase 5 — Photo kind

Photo as a first-class kind. EXIF extraction, `u-photo` output, photo-specific upload UX. Simplest of the new kinds; can slot in earlier if needed. The `photo` slot already exists in `get_editor_panel_config()` with empty fields and no layout — Phase 5 adds the photo-specific upload UX as a sub-panel (or as inline fields if simpler).

### Phase 6 — Portfolio CPT

Separate track. Different fields, different URL structure, doesn't belong in the stream. Independent of everything above.

### Open questions to resolve before Phase 1

1. **Comfort with the meta-to-taxonomy migration.** Reversible in principle, irreversible-feeling in practice. Decision: proceed (this section assumes yes).
2. **Collection as catalogue vs. posts.** Decide before designing meta schema in Phase 4.
3. **API key storage.** Discogs / TMDB / Last.fm need keys. Plugin settings are fine for a personal site; flag if a more secure store is wanted.

## Planned

### Checkin world map (archive)

A world map on the checkins archive showing every Swarm checkin as a pin. (Distinct from the per-post `checkin-map` block already in place, which is a static Geoapify thumbnail for one venue.)

**Why it's tractable:** Every checkin post already stores `nop_indieweb_venue_lat` and `nop_indieweb_venue_lng` (see `includes/services/class-service-swarm.php`). No new data collection required.

**Stack:**
- Leaflet + OpenStreetMap tiles (no API key, no signup, free for personal traffic).
- `Leaflet.markercluster` for overlapping pins.
- No build step — self-host or CDN the two JS files.

**Shape:**
- `includes/checkins/class-checkins-geojson.php` — REST route `/wp-json/nop-indieweb/v1/checkins/geojson` returning a FeatureCollection. Cache as transient, invalidate on `save_post` for the checkin kind.
- `blocks/checkins-world-map/` — block with `block.json`, renders a placeholder div, enqueues Leaflet and init script. Slot into `taxonomy-nop_kind-checkin.html`.

**Privacy controls (design these BEFORE building):**
- Exclude-radius setting: don't plot any pin within N metres of configured coordinates (home, partner, anywhere sensitive).
- Optional time delay: only show pins older than N days.
- Optional fuzz mode: round lat/lng to ~1km precision for recent pins, full precision for older ones.
- Default zoom should be country-level, not street-level.

**Nice-to-haves after v1:**
- Year filter chips.
- Country grouping in cluster popups ("47 checkins in Spain across 4 trips").
- Heatmap toggle (Leaflet.heat).
- Per-year archive pages with a small map alongside that year's checkins.
- Summary stats block (total checkins, countries, cities).

**Estimated effort:** afternoon for v1, day for polish.

## Path to public release

**For review — not committed.** Items from the June 2026 "what would a 10/10 plugin take" review. The syndication retry queue (the only item that pays off on a single-site install) shipped in 0.4.0 — see Done. Everything below only matters if/when the plugin is opened up to other people's sites, so it waits for that decision.

### Test suite + CI

The single biggest quality gap — and not one IndieWeb-ecosystem plugin has one, so it's also a differentiator.

- **PHPUnit on the pure-function surfaces first**: `compose_status()` truncation/suffix budgets, `build_full_text()` kind handling, Bluesky facet byte-offset math, Micropub request parsing. These are the highest test-value-per-line targets.
- **Protocol conformance**: run [micropub.rocks](https://micropub.rocks) and [webmention.rocks](https://webmention.rocks) against a live install; both publish checkable badges.
- **CI workflow**: GitHub Actions running PHPCS + Plugin Check + PHPUnit on every push. `.github/workflows/` currently only has `deploy.yml`. The existing `bin/test-*.php` smoke tests (incl. `bin/test-syndication-retry.php`) could run in CI via wp-env until real PHPUnit exists.

### Documented extensibility (HOOKS.md)

The right hooks already exist (`nop_indieweb_register_syndicators`, `nop_indieweb_register_services`, `nop_indieweb_register_lookup_providers`, plus ~15 filter points). A 10/10 plugin documents them so a stranger could add a `Syndicator_Threads` without reading the source. One HOOKS.md with signature, example, and when-it-fires for each.

### Second install that isn't ours

The plugin has only ever run on neilorangepeel.com (Multisite blog 6) and Studio. Before any public release it needs to survive a genuinely different environment: single-site WP, different PHP version, plain permalinks, no Pixelfed account, a host that blocks outbound HTTP. The Belfast editor-preview placeholders are fine; untested environmental assumptions are the real risk.

### Public release mechanics

Only after the above: wordpress.org listing (`.distignore` and readme.txt are already in shape) or GitHub releases + an IndieWeb wiki/Discord announcement. Then the unglamorous part — answering strangers' bug reports, which is the only thing that turns an 8/10 plugin into a 10/10 one.

## Under consideration

Discussed but not committed. Listed in rough priority order — top items have the highest leverage relative to effort. **All assume Foundations are audited first.**

### Reply Router (smart, identity-honest, venue-agnostic reply UX)

Replace the standard comment form with a single-textarea, single-identity-field interface whose submit button morphs based on what the visitor types. Routes to:

- Native WP comment (email or anonymous)
- Mastodon share intent with `in_reply_to` (fediverse handle entered)
- Bluesky compose intent (`@handle.bsky.social` entered)
- Micropub-to-their-site (IndieAuth URL)
- Webmention from their URL (mf2 detected on input)

Auto-detect `rel="me"` chains so a personal URL surfaces all the visitor's identities. Persist choice in localStorage. Pair with a `/respond` page explaining webmentions, linking to starter platforms (micro.blog, BearBlog, write.as, webmention.app), with a sandbox for the curious.

**Note:** this is a clever feature that primarily serves the IndieWeb-literate 5%. Foundations + "also happening on" panel must ship first, since they serve everyone. Build the Reply Router only once usage data justifies it.



### Federation strategy

ActivityPub fit for the plugin. Three real options:

1. **Bridgy Fed adoption** — lowest code cost, leverages existing mf2 + webmention stack. Lets us disable Mastodon and Pixelfed POSSE (they become duplicates for AP followers) and prune those branches from `class-social-backfeed.php`.
2. **Adopt `pfefferle/wordpress-activitypub`** — mature, but overlaps with our webmention, post-kind, and syndication layers. Two plugins fighting the same hooks.
3. **Native AP module in `includes/activitypub/`** — fits the "explicit, no magic" preference but is real work: HTTP Signatures, key rotation, fan-out queues, inbox abuse handling. Mostly reimplements what Bridgy Fed already does.

Note: Bluesky is ATProto, not AP — its syndicator and backfeed stay regardless.

Threads federates over AP since 2024, opt-in per user. Reachable via any AP path above.

### Loops syndication (federated short-form video)

[Loops](https://joinloops.org/) — the federated TikTok alternative from the Pixelfed team (Daniel Supernault). ActivityPub federation is in beta: Mastodon/Pixelfed/PeerTube users can follow Loops accounts directly.

Two blockers before this is buildable:

1. **No public API yet.** A third-party API is on [their roadmap](https://joinloops.org/roadmap) but unreleased. A `Syndicator_Loops` can't exist until it ships — re-check status before any work.
2. **No video post kind.** Loops is video-only. A `video` kind (upload UX, `u-video` mf2 output, probably alongside the Photo kind in Phase 5) has to exist first.

Same caveat as Mastodon/Pixelfed: Loops is AP-native, so if we ever federate (Bridgy Fed or native AP), a Loops syndicator becomes a duplicate for AP followers and gets pruned. If federation lands before their API does, skip this entirely — Loops users just follow the site.

### Time-based unified archives

Make `/YYYY/`, `/YYYY/MM/`, and `/YYYY/MM/DD/` real, browsable pages mixing every post kind (notes, films, checkins, replies, RSVPs, journal) into one chronological timeline. WordPress's default date archives only cover the default post type, so this needs a custom query layer.

Foundation for "On This Day", yearly review automation, and per-kind h-feeds.

### On This Day

Once unified archives exist, a `/on-this-day/` page (or homepage widget) querying everything posted on today's month/day across all years. Trivial once the archive query is right.

### Yearly review automation

Auto-generate the structural skeleton of an annual review post from post-kind counts ("48 checkins, 12 films, 6 articles, 130 notes") for hand-edited prose on top.

### Per-kind h-feeds

Separate RSS/JSON feeds for notes, articles, replies, etc., so readers (and AP followers if we federate natively) can subscribe selectively.

### Reply context inline

When rendering a reply post, fetch and display the parent post (author photo, content snippet, link) above our reply so the page stands alone as a readable conversation. We already store the target URL in `class-service-reply.php`; needs mf2 fetch + cache + template.

### rel="me" verification chain

`<link rel="me">` to Mastodon, Bluesky, Pixelfed, GitHub, with each profile linking back. Mastodon green checkmark and fediverse identity proof. Five-minute job.

### Bluesky DID-on-domain

Serve `/.well-known/atproto-did` (or DNS TXT) so `neilorangepeel.com` becomes the Bluesky handle directly — no `bsky.social` middleman. Update the Bluesky syndicator accordingly.

### AI crawler controls

`robots.txt` AI-bot blocklist, `noai` / `noimageai` meta tags, optional `llms.txt`. Small `includes/ai-policy/` module with a settings toggle.

### Microsub reader endpoint

The "read" side of the IndieWeb — turn the site into a personal reader for h-feeds, RSS, AP, and Bluesky via clients like Aperture/Monocle/Indigenous. Pairs with existing IndieAuth. Biggest single feature that would push us past the "WordPress version of adactio" line. 1–2 weekend project.

### Read and Listen post kinds

Extend the service-class pattern with `class-service-read.php` (books — Bookwyrm now federates over AP) and `class-service-listen.php` (Last.fm or ListenBrainz scrobbles). Slots into existing architecture cleanly.

### Vouch (webmention extension)

Spam-resistance extension to webmention — sender includes a `vouch` URL from a site the receiver already trusts. Quietly gaining traction as fediverse spam worsens. Small change to the webmention endpoint + sender.

### `/now`, `/colophon`, `/uses` pages

Standard IndieWeb meta-pages. Not really plugin features — likely just theme pages — but worth tracking here so they don't get forgotten.

### Site search

Internal search across all post kinds. Boring but high day-to-day value.

### JSON Feed

Publish JSON Feed alongside RSS for each h-feed.

### Speaking / events archive

`h-event` markup for any talks or conferences attended. Pairs with existing RSVP support.

## Known upstream bugs

Issues we live with — root cause is in WordPress core, the Gutenberg plugin, or a third-party service. Listed here so future sessions don't re-debug them.

- **WP 7.0+/7.1-alpha Block Bindings picker is broken.** Clicking the "Not connected" card on a paragraph's Attributes panel flips `aria-expanded="true"` but no menu DOM is ever rendered. Reproduced on a clean install with no plugins. Likely transitional state during the [WP 7.1 Block Bindings → Block Fields refactor](https://github.com/WordPress/gutenberg/issues/77199) — the core team is deliberately removing the old UI in favour of a "Content" tab driven by DataForms. **Workaround:** add bindings via the Code Editor (markup) until the new UI lands. Functional, just no point-and-click on current builds.
- **WP 6.7-6.9 Attributes panel hidden for paragraphs without an existing binding.** Chicken-and-egg: you can't add a first binding via the UI because the panel only renders for blocks that already have one. Markup-only is the path on these versions too.
- **WP REST templates controller calls `current_user_can(null)`.** Reaches the `map_meta_cap` filter chain with a null cap, fataling any filter with `string $cap` type hint. Caused a 500 on `/wp/v2/registered-templates` which left the Site Editor → Templates page empty. Mitigated by relaxing our filter's type hint to accept any value. Bug is in WP/Gutenberg core's templates controller.
- **OwnYourSwarm doesn't forward Foursquare venue categories.** [aaronpk/ownyourswarm#47](https://github.com/aaronpk/ownyourswarm/issues/47) — open since 2018, deliberate non-feature (Aaron pushed back on the semantics). The Foursquare Places API enrichment in `Foursquare_Enricher` is our workaround.
- **Gutenberg [#73618](https://github.com/WordPress/gutenberg/issues/73618).** Attributes UI not immediately visible on first block selection — populates after clicking another block and back. Known UX bug, no workaround needed beyond awareness.

## Decisions and constraints

- **POSSE vs federation duplication:** if we ever federate (Bridgy Fed or native AP), Mastodon and Pixelfed syndicators must be disabled or AP followers see duplicates. Bluesky stays.
- **No magic:** keep explicit `require_once` loading, no autoloader, no service-locator patterns.
- **Bridgy Fed before native AP:** prove the federation workflow with the bridge before scoping native ActivityPub work.
- **Privacy by default for location data:** any feature that publishes lat/lng must ship with exclusion controls, not add them later.
- **Kind taxonomy is canonical; meta is a derived cache.** `nop_kind` term is the single write target; `nop_indieweb_post_kind` meta is mirrored from it via a hook and treated as read-only by every other code path.
- **Single write path for kind.** Services, the editor panel, importers, and migrations all write the taxonomy term. Nothing writes the kind meta directly except the mirror hook.
- **Sub-taxonomies are hierarchical.** `nop_kind` is hierarchical so `collection > music > vinyl` nests cleanly; `collection_format` and `collection_format_type` are separate hierarchical taxonomies for sub-classification.
- **Meta naming stays `nop_indieweb_*`.** Public prefix, REST-exposed, consistent across every kind. Don't introduce single-underscore prefixes (`_collection_*`) — they're WP-private and break Block Bindings + REST.
- **`Lookup_Provider_Base` mirrors `Service_Base`.** Inbound Micropub flows and interactive editor lookups share conceptual structure (parse → validate → map → meta → after-insert hook) but live in separate abstractions because they run in different contexts.
- **Categories are curated topics; kinds suggest a default.** The six topic categories (Photography, Performance, Web & Development, Places & Travel, Media Diet, Journal — `Kind_Taxonomy::TOPIC_CATEGORIES`) answer "what's it about?"; the kind answers "what is it?". Each kind maps to a default topic (`KIND_DEFAULT_CATEGORIES`) applied only when the author hasn't picked a category — an explicit choice always wins, and articles get no default at all. Categories still must not double as a kind discriminator; services never assign categories directly.
- **Post formats are fully retired.** Neither the plugin nor the active theme uses `post_format` for anything. Kind taxonomy is the only post discriminator. Do not reintroduce post-format support or `single-post-format-*` templates.

## Done

- **2026-06-03 — Categories as curated topics + kind defaults + quote/video kinds (0.5.0).** Categories now answer "what's it about?" via six curated topics (`Kind_Taxonomy::TOPIC_CATEGORIES`: Photography, Performance, Web & Development, Places & Travel, Media Diet, Journal); kinds answer "what is it?". Each kind maps to a default topic (`KIND_DEFAULT_CATEGORIES`) applied by a new `apply_default_category()` hook on `set_object_terms` (priority 11, after the meta mirror) — but only when the author hasn't picked a category: the WP default category acts purely as a "picked nothing" sentinel, an explicit choice is never overridden, and articles get no default at all (the one kind where the topic is a deliberate per-post choice). The hook covers every creation path (editor, Micropub, importers, CLI) since kinds are always set via `wp_set_object_terms`. Two new kinds: **quote** (`nop_indieweb_quote_of` meta, `u-quotation-of` mf2, wired into cite-card/cite-enricher/webmention-sender/mf2-endpoint exactly like bookmark) and **video** (wp:video layout); both with single + archive templates cloned from bookmark/note. `bin/migrate-topics.php` (idempotent, dry-run support) migrated Studio's 1,698 posts: kind-shaped categories (Films/Links/Quotes/Videos/Development) → kinds + topics then deleted, topic tags promoted to categories (exact-duplicate tags deleted, more-specific tags kept), provenance tags (Swarm ×1411, Facebook ×221) deleted, "Articles" default category stripped and deleted with `default_category` repointed at the empty Uncategorized sentinel, and every post backfilled with its kind's topic. Swarm service no longer auto-tags 'Swarm'; Letterboxd no longer defaults to a 'Films' category. mf2 JSON endpoint now html-entity-decodes term names ("Places & Travel", not "Places &amp; Travel"). Production migration ran the same day (194 posts): all six topics live, Articles ×175 noise category gone, Article kind 0→33, Quote/Video kinds populated from the old categories, and the 24 substantive uncategorized articles hand-assigned topics by content (22 Web & Development, 2 Performance — Tanz.io series); the one empty "Hello" draft was deleted. All three creation paths (editor, /post Micropub, backfeed import) verified on prod with throwaway drafts. Six Studio test articles remain unassigned (test data only).
- **2026-06-03 — Async syndication with retry queue + failure surfacing (0.4.0).** Syndication no longer runs inline during the publish request and no longer swallows failures. `Syndication_Manager::syndicate()` now queues one cron event per (post, platform) via `wp_schedule_single_event` — the same pattern `Webmention_Sender` already used — so publish returns immediately and one platform's outage never blocks another. Failures retry automatically with backoff (5 min → 30 min → 2 h, 4 attempts total), then park as permanently failed. Every state transition is journalled in new `nop_indieweb_syndication_status` object meta (REST-exposed, keyed by syndicator slug: state/url/error/attempts/updated). `Syndicator_Base::do_syndicate()` and `syndicate()` return `string|WP_Error` instead of nullable-string, so the real platform error ("HTTP 401: The access token is invalid", cURL messages) reaches the journal; Mastodon-compatible and Bluesky syndicators updated accordingly, including Bluesky session-creation errors. The editor sidebar panel now has two modes: pre-publish checkboxes (unchanged) and a post-publish delivery view — ✓ sent (linked to the syndicated copy), spinner + "publishing…" (polled every 15 s while the initial send is in flight), or ✗ failed with the error message and a Retry button hitting new REST route `POST /nop-indieweb/v1/syndication/retry` (permission: `edit_post`). Race-hardening: syndication URL meta is re-read immediately before each write so two platforms' cron events can't clobber each other. End-to-end smoke test in `bin/test-syndication-retry.php` (22 checks: queue, failure→retry scheduling, max-attempts, REST retry, skip handling, mocked success path, dedup). Also restored the 0-shipped-Plugin-Check-errors standard: three `_n()` calls in post-footer's editor-preview branch had lost their translators comments.
- **2026-05-24 — Venue visit counter + i18n pass.** `nop_indieweb_venue_visit_number` integer meta (REST-exposed) stores a static ordinal snapshot of how many times the same venue has been visited. `nop_indieweb_ordinal()` produces "1st", "2nd", "3rd"… with 11th/12th/13th edge cases. `nop_indieweb_compute_venue_visit_number()` counts prior-dated published/draft/private checkins at the same venue and returns the next ordinal. Three lifecycle hooks in `Plugin::boot()` keep numbers self-healing without manual intervention: `before_delete_post` (exclude by ID, venue meta still readable), `trashed_post` (post already excluded by status filter), `untrashed_post` (re-included). Block Bindings source exposes `venue_visit_number` as a derived field returning e.g. "3rd Visit"; the editor panel (`post-kinds-panel.js`) shows the same value live; the Checkin Data Palette pattern updated with a sample binding. CLI `wp nop-indieweb backfill-venue-visits [--dry-run]` backfilled 1,764 posts across 520 venues (176 venues with multiple visits). Checkin template (`single-nop_kind-checkin.html`) wired up with separator + visit number paragraph in the weather row. Separately: full i18n pass across all `blocks/*/render.php` files and `blocks/webmentions/helpers.php` — every hardcoded user-facing string now uses `__()`, `_n()`, `esc_html_e()`, `esc_attr_e()`, or equivalents with text domain `nop-indieweb`; counts use `_n()` for proper singular/plural handling. CLAUDE.md rule added enforcing this for all future PHP copy. Map caption pill repositioned to 1rem from the bottom-right corner (was 6px). Checkin-map block: map image wrapped in an OSM link; hover effect on the caption pill (`nop-checkin-map:hover` → inverse pill, wider target).
- **2026-05-17 — Block Bindings migration + cleanup.** Seven SSR venue/checkin blocks replaced with core blocks + Block Bindings (paragraphs, post-terms, buttons). New `Block_Bindings` source registers `nop-indieweb/post-meta` with five derived fields (`full_address`, `locality_country`, `venue_coordinates`, `venue_url_host_label`, `checkin_url_host_label`) on top of the venue meta shorthand. `inject_mf2_classes` extended to cover paragraph/heading content, button URLs, and post-terms — preserving every `p-name` / `p-street-address` / `p-locality` / `p-region` / `p-country-name` / `p-postal-code` / `p-adr` / `p-category` / `u-url` class the old SSR blocks emitted. Editor-side `registerBlockBindingsSource` JS ships with `getFieldsList` + `getValues` so the picker dropdown lists our fields and the canvas previews real venue data live. `map_meta_cap` filter grants `edit_block_binding` to anyone who can `edit_blocks` (defaults don't land on every WP build). Active checkin template + starter `checkin-post` pattern migrated; five new patterns registered (Venue Address, Venue Link Button, Checkin Meta Strip, Weather Row, Checkin Data Palette — the palette is a labelled reference layout of every binding and kept-custom block, for design work). Seven blocks deleted entirely after a live-content audit found zero references on prod (1,057 lines removed). `bin/check-mf2.php` regression check asserts mf2 classes survive renders; cap-mapping filter relaxed to accept null `$cap` after a WP REST templates 500 (see Known upstream bugs). Two bug fixes shipped during the migration: (a) mf2 inject was emitting a duplicate `class=` attribute on bound paragraphs that wiped block-level styles like `has-small-font-size` — regex relaxed from `[^>]+\sclass="` to `[^>]*\sclass="`; (b) `checkin-map` returned empty in the editor when a post had no lat/lng — now renders a Belfast-default placeholder when `$is_editor`.
- **2026-05-17 — Bluesky link card uses the cached map image when no inline photo is present.** Bluesky embeds are mutually exclusive (image OR external link card, not both), and checkins with Swarm photos already win the image embed. For checkins without a photo — and any future kind whose body has no inline images — `upload_thumb()` now prefers `nop_indieweb_map_url` over the featured image. To make sure the map exists at syndication time (it normally generates lazily on first page view), `Service_Swarm::after_insert()` calls `nop_indieweb_get_or_cache_map_image()` proactively when a Geoapify key is configured. New `wp nop-indieweb backfill-checkin-maps [--dry-run] [--force]` CLI handles existing checkins.
- **2026-05-17 — Foursquare venue category enrichment.** OwnYourSwarm doesn't forward Foursquare's venue categories (see Known upstream bugs). New `Foursquare_Enricher` (in `includes/venue/`) calls the Places API v3 directly using the venue ID already in `nop_indieweb_venue_url`, caches the response per-venue for 30 days, and falls back to silent empty on auth/network failure so it never blocks the Swarm ingest. Hooked into `Service_Swarm::after_insert()` after the existing venue-categories check. New `wp nop-indieweb backfill-venue-categories [--dry-run] [--force]` CLI retro-tags existing checkins; the Places API tier limits at 99k req/month which is comfortably bigger than even a heavy POSSE flow. Capped at 3 categories per venue (`MAX_CATEGORIES`) since Foursquare's primary-first ordering means anything beyond that is long-tail noise. Settings UI under Swarm tab. Archive template `taxonomy-nop_venue_category.html` shipped so `/venue-category/yoga-studio/` etc. render properly.
- **2026-05-17 — Syndication polish.** Mastodon + Bluesky checkin status now prefixed with 📍 emoji (`📍 Checked in at {venue}` instead of `Checked in at {venue}`). Map-zoom default raised from 16 to 17 for tighter venue framing. Image-style block controls (aspect ratio, border, shadow, duotone filter) added to the `checkin-map` block via `block.json` supports + CSS hooks; attribution moved to a corner-overlay pill on the map image itself (Google/Apple/Mapbox convention) freeing up the caption row. `like-button` click handler was silently broken on the new card design — the shared `attachLikeAction` helper looked for the count inside the button (post-footer's layout) and found nothing on like-button (where count is a sibling); scoping the count lookup to the root element supports both layouts. Template-content cache in `class-plugin.php` now keys on a hash of template file mtimes alongside `NOP_INDIEWEB_VERSION` so editing a template file always invalidates the cache without bumping the plugin version.
- **2026-05-16 — Weather enrichment on checkins.** Pirate Weather time-machine endpoint (`timemachine.pirateweather.net`) wired into `Service_Swarm::after_insert()` via a new `Weather_Fetcher::enrich_post( $post_id, $lat, $lng, $timestamp )` in `includes/weather/`. Caller-agnostic signature so future workout-style kinds wire in with one line; single provider for now, no abstraction layer until a second one exists. Stores six `nop_indieweb_weather_*` meta keys (temp °C/°F, icon slug, summary, provider, fetched_at) — all REST-exposed. Fails silent so a weather miss never blocks the checkin import. Two new SSR blocks: `weather-icon` (10 MIT-licensed Phosphor SVGs covering the full Pirate Weather vocabulary, rendered inside a `wp-block-icon` wrapper so it inherits core/icon's color/size context; sleet and snow share `cloud-snow` since Phosphor has no distinct sleet glyph) and `weather-temp` (°C/°F unit toggle, optional symbol). Slotted into `single-nop_kind-checkin.html` as a small muted row beneath the venue address. Backfill via `bin/backfill-weather.php` (CLI-only, idempotent, 250ms spacing). Knock-on fix: `nop_indieweb_is_safe_url()` now short-circuits when running under Emscripten/WASM PHP (WordPress Studio, Playground) — the WASM DNS shim resolves every hostname to a 172.x sentinel that would otherwise trip the SSRF guard, blocking every outbound HTTP call locally. Polish pass: the editor-only placeholder pattern (render preview values in `context=edit`, render truly nothing on the front end when meta is missing) extended from venue-field / venue-coordinates / checkin-link / checkin-map / checkin-meta / rsvp-meta to the weather pair plus venue-categories, post-source, and syndication-panel — so the Site Editor template view doesn't show a wall of "Block rendered as empty" boxes for un-enriched posts.
- **2026-05-16 — Post author stamped on every IndieWeb insert.** `Service_Base::handle()` now resolves an author with a three-tier chain (explicit `$author_id` param → `get_current_user_id()` → filterable default `nop_indieweb_default_author_id`, user 1) and writes it to `post_author` on every insert. The Micropub endpoint passes the token's owning user explicitly, cron importers (Mastodon, Bluesky, Letterboxd) fall through to the default. Backfill script (`bin/backfill-post-authors.php`, idempotent) ran across 8 historical authorless posts and stamped them with user 1. Knock-on effect: the mf2 author h-card now renders for those posts too, `get_author_posts_url()` returns a real archive URL, and the editor "view as user" capability check has a real subject.
- **2026-05-15 — mf2 output completeness.** Three gaps closed for Bridgy + XRay + reader-endpoint readability. HTML: `p-name` now injected onto every `core/post-title` heading (and `u-url` onto its inner permalink link when present); a hidden `<a class="p-author h-card u-url">` author anchor and a hidden `<a class="u-url">` permalink anchor are emitted in `wp_footer` so parsers always find author + post URL inside the body's h-entry. JSON: `/wp-json/nop-indieweb/v1/mf2/{id}` endpoint now also serves `in-reply-to`, `bookmark-of`, `like-of`, `repost-of`, `rsvp`, and `photo` properties when the underlying meta exists, matching the hidden-anchor HTML. Photos prefer sideloaded attachment IDs, fall back to source URLs, then to the featured image. The mf2 surface is now consumer-complete for the kinds the plugin supports.
- **2026-05-15 — Integrated Responses section.** Comment form now renders inline inside the `webmentions` block at the bottom of the unified "Responses" section, so visitors see facepile likes, repost facepile, replies, and the form as one tight visual block. Removed the separate `<!-- wp:post-comments-form />` group from every kind template. The form drops the "Website" field (IndieWeb visitors use webmention, casual visitors don't need it), uses placeholder-as-label so it reads compact, and stacks name/email side-by-side on widths ≥ 480 px. Empty-state path renders the form too, so "Be the first to respond" sits directly above the form. Plus: reply link is now visible at 0.85 opacity on touch devices (was hover-only, invisible on phones); avatar size classes `--40` added; helpers.php drops inline style attributes in favour of the size class.
- **2026-05-15 — Checkin post redesign.** Venue rendering split out of the monolithic `checkin-meta` block into granular, individually styleable blocks: `venue-field` (any single venue meta), `venue-coordinates`, `venue-categories`, `venue-link`, `checkin-link`, `checkin-map`. New `single-nop_kind-checkin.html` template composes them with the redesigned magazine-style header. Foursquare venue categories migrated from a serialised meta array into a real `nop_venue_category` taxonomy (legacy meta migration script in `bin/migrate-venue-categories.php`). Geoapify static map is now fetched on first render and cached to `uploads/checkin-maps/checkin-map-{id}.png`, with the cached URL stored in `nop_indieweb_map_url` meta and cleaned up on post delete. The starter `nop-indieweb/checkin-post` pattern uses the granular blocks too, so inserting the pattern into a checkin post no longer double-renders venue data.
- **2026-05-15 — Security hardening sweep.** Five-commit pass over every outbound HTTP path and every authenticated entry point. Micropub now enforces required scopes (`create`/`update`/`delete`/`media`) on every write and rejects requests targeting a post the token's user can't edit. IndieAuth consent gate is now hard-capped (no infinite redirect loops, redirect_uri re-validated post-consent). All remote fetches go through SSRF/DoS-guarded helpers that re-validate every redirect hop, block cloud-metadata IP ranges (169.254/16 and friends), and cap response sizes. `nop_indieweb_settings` flipped to `autoload=false` so plaintext syndication credentials no longer sit in memory on every front-end request; debug logs run through a redactor; bin/* scripts refuse to execute over HTTP.
- **2026-05-15 — Shared block CSS/JS layer.** Eight blocks' duplicated front-end styles consolidated into `assets/css/blocks-shared.css` (registered once, declared as a style dep in each `block.json`). Editor-side, a shared `nopIndieweb.registerSSRBlock()` helper in `assets/js/ssr-block-helper.js` powers every SSR block's editor.js — eliminates ~700 lines of duplicated React boilerplate. Like-button + post-footer's interaction logic also unified into `assets/js/nop-like-action.js`.
- **2026-05-15 — `post-footer` block.** Replaces the standalone `like-button` + `post-source` blocks in the note template with a single compact interaction row (interactive like pill, comment count, repost count, inline syndication source) shown beneath imported social posts. Reply/repost pills always render so reposters and replyers can click through even when counts are zero.
- **2026-05-13 — Post formats fully retired.** Active theme no longer declares `post-formats` support, so the Post Format panel is gone from the editor sidebar. Removed the format pattern category, `neilorangepeel/format` block binding, helper function, `single-post-format-status.html` template, and `format-link` / `format-audio` / `binding-format` patterns from the theme. Plugin dropped the post-format fallback path in `inject_kind_template`, its own `single-post-format-status.html`, and the matching registered-template entry. Cleared `post_format` terms from every existing post via `wp_set_post_terms( $id, [], 'post_format' )`. Kind taxonomy is now the sole post discriminator end-to-end.
- **2026-05-13 — IndieWeb block categories.** Eight plugin blocks moved from generic `theme` / `widgets` into two dedicated inserter chips: **NOP · Conversations** (webmentions, like-button, syndication-panel, post-source) and **NOP · Kind meta** (checkin-meta, film-meta, film-card, rsvp-meta). Registered via `block_categories_all` in `Plugin::register_block_categories()`.
- **2026-05-13 — Foundation gap closed: "Also happening on" panel.** New server-rendered `blocks/syndication-panel/` block surfaces a post's syndication URLs with platform labels resolved through `Syndication_Manager` (so custom syndicators are honoured); hidden when there's nothing to show. Swarm checkins now also write `nop_indieweb_source_url` / `nop_indieweb_platform` on insert, with a one-time backfill migration for existing posts.
- **2026-05-13 — Foundation gap closed: empty-state invitation.** Posts with zero likes/reposts/replies/comments now render a single muted line inviting a response, wording adapted to whether comments are open. No more blank webmentions block on older posts.
- **2026-05-13 — Foundation gap closed: provenance visibility.** Bridged replies (detected from `brid.gy` in the webmention source URL) now show a muted "· via Bridgy" suffix next to the platform pill, with the aria-label updated symmetrically. Display-only — no ingest, schema, or migration changes.
- **2026-05-13 — Webmentions + like-button blocks survive multiple renders in one request.** Helpers extracted to `blocks/webmentions/helpers.php` (loaded via `require_once`); the like-button block now uses a local `$icon` variable rather than a top-level function. Prevents function-redeclaration fatals inside Query Loops.
- **2026-05-12 → 2026-05-13 — Architecture Phase 1: kind meta → `nop_kind` taxonomy.** Hierarchical `nop_kind` taxonomy registered and exposed in REST. `set_object_terms` mirror hook writes the term slug into `nop_indieweb_post_kind` meta — the meta is now a derived read-cache, doc-commented as such in `class-meta-registry.php`. Services and the editor panel write the term, never the meta. Backfill migration covers existing posts (`bin/migrate-kinds.php`). Category-as-kind retired (`bin/retire-categories.php`); post-format assignment dropped; single templates routed via `nop_kind`. Admin posts list gained a Kind column and filter. Rewrite rules flush on next page load.
- **2026-05-12 — Syndicator settings UX overhaul.** Tabs config-driven from a single source; inline credential warnings; last-sync timestamps per platform; reveal toggle on credential fields; Twitter Archive moved to its own tab.
- **2026-05-12 — Editor panel is config-driven.** `Kind_Taxonomy::get_editor_panel_config()` is the single source of truth for the Post Kind sidebar (dropdown labels, fields, starter layout, title-from-URL behaviour). `admin/post-kinds-panel.js` is a generic renderer over that config. Adding a new kind = register the taxonomy term + add one entry to the config; no JS change. Service-created kinds (`checkin`, `watch`, `listen`) appear in the dropdown with empty fields so hand-authored posts can adopt them. The default Gutenberg taxonomy panel for `nop_kind` is hidden via `removeEditorPanel` to keep kind single-select.
