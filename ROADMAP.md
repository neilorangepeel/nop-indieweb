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

**Foundation gaps to close before adding new features:**

1. **Comment form friendliness audit.** Open the standard WP comment form on a recent post through the eyes of a non-IndieWeb visitor. Does it look modern and inviting, or developer-built? Is the path to leaving a comment obvious? Adjust theme styling and field labels if needed.
2. **"Also happening on" panel under each post.** Read `nop_indieweb_syndication` meta and render deep-links to the Mastodon / Bluesky / Pixelfed thread for that post. Honest cross-platform visibility without auto-bridging. Highest-leverage single addition.
3. **Empty-state polish.** When a post has zero responses, the block currently returns nothing. Consider a small invitation ("Be the first to respond — comment below, or reply from your own site.") to reduce the "is this site even alive" feeling on older posts.
4. **Mobile rendering audit.** Verify the facepile, threaded replies, and platform tags render cleanly on narrow viewports.
5. **Provenance visibility audit.** Bridgy-backfed Mastodon replies are tagged as "Mastodon" — but a direct webmention from a blog and a Bridgy-backfed Mastodon reply currently look similar in the meta line. Consider a subtle visual cue ("via Bridgy" or a bridge icon) so the bridging chain is honest.

**Implication for the rest of this roadmap:** clever items below (Reply Router, polymorphic identity form, ActivityPub) all sit *downstream* of foundations being audited and the "also happening on" panel shipping. Don't build the clever surface until the basics serve everyone well.

## Architecture evolution

A phased rework of how post kinds, structured meta, and per-kind editor UX hang together. Motivated by an architecture review of the current plugin against a target model: Posts for stream content (notes, checkins, photos, watches, listens, bookmarks, collections), Pages for evergreen, a Portfolio CPT for work, and a `kind` taxonomy that drives URLs, templates, microformats, and editor sidebars.

### Phase 1 — Kind: meta → taxonomy

Today, post kind is stored three times — `nop_indieweb_post_kind` meta, the `status` post format, and (for some kinds) a category. Migrate to a single canonical source: a hierarchical `nop_kind` taxonomy. Status post format stays for theme aesthetics; categories stop being used for kind grouping.

**Authority model — derived denormalisation, not parallel writes:**

- The `nop_kind` taxonomy term is **canonical** (queries, archive URLs, admin filter UI, sub-taxonomy nesting).
- The `nop_indieweb_post_kind` meta is kept as a **derived read-cache** of the term, mirrored via a `set_object_terms` hook (~10 lines). Never written directly by services, the panel, or any code.
- Reasons to keep the meta: hot read paths (`Semantic_Markup::output_kind_links`, `inject_post_format_template`, the post-kinds panel JS) are materially faster than `wp_get_post_terms`; Block Bindings already read it; Micropub clients and the mf2 endpoint speak in scalar property values; external themes/plugins may read it.
- Drift is impossible by construction because there's only one write path (the term).

**Steps:**

1. Register `nop_kind` taxonomy, hierarchical, `show_in_rest: true`.
2. Add `set_object_terms` mirror hook that writes the term name into `nop_indieweb_post_kind` meta on every change.
3. Backfill migration: iterate posts with existing `nop_indieweb_post_kind` meta, call `wp_set_object_terms` for each (mirror hook re-writes meta, no-op for unchanged values).
4. Update services and the JS panel to write the **term**, never the meta directly.
5. Switch readers gradually: `Semantic_Markup`, `inject_post_format_template`, the JS panel. Meta remains the read interface for now — the term is what changes.
6. Drop the category-as-kind pattern: remove auto-categorisation from services, retire `category-{kind}` templates in favour of `taxonomy-nop_kind-{term}` templates.
7. Doc-comment the meta in `class-meta-registry.php` as "derived cache — do not write directly."

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

### Checkin world map

A world map on the checkins archive showing every Swarm checkin as a pin.

**Why it's tractable:** Every checkin post already stores `nop_indieweb_venue_lat` and `nop_indieweb_venue_lng` (see `includes/services/class-service-swarm.php`). No new data collection required.

**Stack:**
- Leaflet + OpenStreetMap tiles (no API key, no signup, free for personal traffic).
- `Leaflet.markercluster` for overlapping pins.
- No build step — self-host or CDN the two JS files.

**Shape:**
- `includes/checkins/class-checkins-geojson.php` — REST route `/wp-json/nop-indieweb/v1/checkins/geojson` returning a FeatureCollection. Cache as transient, invalidate on `save_post` for the checkin kind.
- `blocks/checkin-map/` — block with `block.json`, renders a placeholder div, enqueues Leaflet and init script.
- Drop the block into the checkins archive template via the editor.

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
- **Categories are user-supplied topics, not kind.** Once `nop_kind` exists, services stop auto-assigning categories. Categories belong to the user (Belfast, Photography) and must not double as a kind discriminator.
- **Post format `status` stays orthogonal to kind.** Kept for theme aesthetics; not a kind mechanism.

## Done

- **2026-05-12 — Editor panel is config-driven.** `Kind_Taxonomy::get_editor_panel_config()` is the single source of truth for the Post Kind sidebar (dropdown labels, fields, starter layout, title-from-URL behaviour). `admin/post-kinds-panel.js` is a generic renderer over that config. Adding a new kind = register the taxonomy term + add one entry to the config; no JS change. Service-created kinds (`checkin`, `watch`, `listen`) appear in the dropdown with empty fields so hand-authored posts can adopt them. The default Gutenberg taxonomy panel for `nop_kind` is hidden via `removeEditorPanel` to keep kind single-select.
