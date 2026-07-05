=== NOP IndieWeb ===
Contributors: neilorangepeel
Tags: indieweb, micropub, webmention, indieauth, posse
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.9.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

IndieWeb toolkit for WordPress: Micropub endpoint, IndieAuth server, Webmentions, post kinds, and POSSE syndication to Mastodon, Bluesky and Pixelfed.

== Description ==

NOP IndieWeb turns a WordPress site into a full IndieWeb citizen. It owns your content on your own domain and (optionally) syndicates it out to the social web — the POSSE pattern (Publish on your Own Site, Syndicate Elsewhere).

**Core features**

* **Micropub endpoint** — publish from any Micropub client (Quill, iA Writer, OwnYourSwarm, etc.).
* **IndieAuth server** — sign in to Micropub clients with your own domain; manage authorized apps and revoke tokens from the settings screen.
* **Webmentions** — send and receive Webmentions; a unified "Responses" block shows likes, reposts and replies as a facepile plus threaded conversation, alongside native WordPress comments.
* **Post kinds** — notes, replies, likes, reposts, bookmarks, quotes, videos, RSVPs, checkins, and film-diary "watch" entries, each with its own template and microformats2 markup.
* **POSSE syndication** — automatically cross-post to Mastodon, Bluesky and Pixelfed on publish, recording the syndication link back on the post.
* **Inbound import** — pull your own posts back from Mastodon, Bluesky, Pixelfed and Letterboxd on a schedule.
* **Check-in enrichment** — Swarm/Foursquare check-ins gain venue categories, a static map image, and a weather snapshot.
* **Block bindings** — bind venue, weather and syndication metadata to core blocks in the editor, with microformats2 classes injected at render time.

All microformats markup is injected at render time and stored nothing extra in your database; deactivating the plugin removes it cleanly.

== External services ==

This plugin connects to a number of third-party services. Each is optional and only contacted when you enable and configure the corresponding feature. No data is sent to any of them unless you opt in by entering credentials or enabling a feature.

**Mastodon (and Mastodon-compatible instances)**
Used to syndicate your posts to, and import your posts from, your Mastodon account. When enabled, the post content, media, and a link back to your site are sent to the instance URL you configure, authenticated with the access token you provide. Also used, unauthenticated, to fetch your public posts during import.
Terms vary by instance; see your instance's own policy. Example: https://mastodon.social/terms and https://mastodon.social/privacy-policy

**Bluesky (AT Protocol)**
Used to syndicate to and import from your Bluesky account via bsky.social / public.api.bsky.app. Post content, media, and a link back are sent when syndicating, authenticated with the app password you provide.
Terms: https://bsky.social/about/support/tos — Privacy: https://bsky.social/about/support/privacy-policy

**Pixelfed**
Used to syndicate to and import from your Pixelfed account. Behaves like Mastodon (same API). Data is sent to the instance URL you configure.
Terms vary by instance; see your instance's policy.

**Letterboxd**
Used to import your public film diary as posts, by fetching your public RSS feed at letterboxd.com. Only your username is sent (as part of the feed URL). No authentication.
Terms: https://letterboxd.com/terms-of-use/ — Privacy: https://letterboxd.com/privacy-policy/

**Foursquare / Swarm (OwnYourSwarm + Foursquare Places API)**
Check-ins arrive via the Micropub endpoint from OwnYourSwarm. If you supply a Foursquare API key, the plugin looks up each venue's categories at the Foursquare Places API. The venue ID is sent.
Terms: https://foursquare.com/legal/terms — Privacy: https://foursquare.com/legal/privacy

**Geoapify Static Maps**
If you supply a Geoapify API key, the plugin fetches a static map image for each check-in's coordinates from maps.geoapify.com and caches it locally. The latitude/longitude and your API key are sent.
Terms: https://www.geoapify.com/terms-and-conditions/ — Privacy: https://www.geoapify.com/privacy-policy/

**Pirate Weather**
If you supply a Pirate Weather API key, the plugin fetches the weather at each check-in's coordinates and time from pirateweather.net. The latitude/longitude, timestamp and your API key are sent.
Terms/Privacy: https://pirateweather.net/

**TMDB (The Movie Database)**
If you supply a TMDB API key, the in-editor film lookup for "watch" posts queries api.themoviedb.org for titles and poster images. Your search query and API key are sent.
Terms: https://www.themoviedb.org/terms-of-use — Privacy: https://www.themoviedb.org/privacy-policy

**Webmention recipients and Bridgy**
When you publish a post that links to other sites, the plugin discovers and sends Webmentions to those sites (the target URLs you linked to). If you use https://brid.gy, it relays reactions from Mastodon/Bluesky back to your site as Webmentions.
Bridgy: https://brid.gy/about

== Installation ==

1. Upload the plugin to `wp-content/plugins/nop-indieweb` (or install via the Plugins screen) and activate it.
2. Go to **Settings → IndieWeb**.
3. Work through the Quick Setup guide on the Overview tab: connect a Micropub client, enable the networks you want, and add any optional API keys.
4. Add the IndieWeb blocks (Responses, Like, Post Footer, Check-in Map, etc.) to your templates, or use the bundled block patterns.

== Frequently Asked Questions ==

= Do I need any of the third-party API keys? =
No. Everything that needs a key (maps, weather, venue categories, film lookups) is optional and degrades gracefully when the key is absent. The core Micropub, IndieAuth and Webmention features need no external keys.

= Does it work with a block theme? =
Yes. It ships block templates for each post kind and registers its blocks for Full Site Editing. It also works with classic themes that render post content and comments.

= Where are my syndication credentials stored? =
In the plugin's settings option, which is stored with autoloading disabled so the credentials are not loaded into memory on every request.

== Changelog ==

= 0.9.3 =
* Quality pass: the check-in syndication lead is now translatable (matching the other kind leads); an accessible label was added to the syndication-preview character budget; and unit coverage was added for the Micropub content normaliser behind the Markdown feature.

= 0.9.2 =
* Editor: a "Syndication preview" sidebar panel shows how the post will read on each target network — the real composed text (server-side, so it matches what actually posts), the per-network character budget, and the card/thread treatment (link card, quote, in-thread reply, unfurl). Refreshes when you save.

= 0.9.1 =
* /post composer: light Markdown formatting. ⌘/Ctrl+B and ⌘/Ctrl+I wrap the selection in bold/italic; on publish it's rendered as real `<strong>`/`<em>` on the blog (Micropub `content[html]`) while Mastodon and Bluesky receive plain text, so formatting enriches the canonical post without leaking markers to the silos.

= 0.9.0 =
* Conversations now render natively on the silos. Replies to a Bluesky or Mastodon post thread in place (Bluesky `record.reply` root/parent; Mastodon `in_reply_to_id`, resolved through the instance's search); quoting a Bluesky post embeds it as a real quote card (`app.bsky.embed.record`, or `recordWithMedia` with your own media) instead of a flat link; and `@handle` mentions become real Bluesky mentions (`facet#mention` resolved to a DID). Response posts — bookmarks, likes, reposts, quotes, replies — now lead with an emoji verb (🔖 Bookmarked, ⭐ Liked, 🔁 Reposted, 💬 Quoted) and build their preview card from the *linked source* (its own title, excerpt and image) rather than your permalink; on Mastodon the target URL is unfurled so the source shows, not your own site.
* Bookmarks syndicate with a 🔖 lead line, mirroring the 📍 check-in.
* /post composer: typing in the Tags field now live-searches your existing tags and offers them as tap-to-add chips, so you complete an existing tag instead of minting a near-duplicate.
* Fixed duplicate back-fed responses. The inbound Bridgy webmention receiver and the internal social-backfeed poller now share a dedup key, so the same silo interaction is stored once instead of twice; and neither stores the site owner's own syndicated copies as replies to the post that spawned them.

= 0.5.1 =
* Bluesky link cards now fall back to the site icon (your portrait/avatar) as the card thumbnail when a post has no photo, video, featured image, or map — matching the Open Graph image fallback that Mastodon and other unfurlers already use.

= 0.5.0 =
* Categories are now curated topics with kind-aware defaults. Each post kind maps to a default topic category (photo → Photography, checkin → Places & Travel, watch/listen → Media Diet, notes and social kinds → Journal) applied only when you haven't picked a category yourself — an explicit choice always wins, and articles never get a default. New quote and video post kinds with templates and microformats (u-quotation-of). A migration script (`bin/migrate-topics.php`) converts pre-kind categories to kinds, promotes topic tags to categories, and removes provenance tags.

= 0.4.0 =
* Syndication is now asynchronous and resilient. Publishing no longer waits on remote platform APIs — each platform gets its own background job that retries automatically on failure (after 5 minutes, 30 minutes, then 2 hours). Failures surface in the editor sidebar with the actual error message and a Retry button; successful sends show a link to the syndicated copy.

= 0.3.0 =
* Redesigned the Settings → IndieWeb Overview tab: Networks, then a Reactions dashboard (likes/comments/reposts with a per-network breakdown and pending-moderation link), then a merged "Identity & Endpoints" section gathering profile URLs and all discovery endpoints (Micropub, Webmention, IndieAuth, mf2). Removed the Quick-setup checklist; onboarding now lives in each service tab.

= 0.2.9 =
* Hardening pass ahead of public release: IndieAuth/OAuth CSRF protection, internationalization of the admin UI and front-end scripts, accessibility improvements to the settings UI, and WordPress.org coding-standards compliance.
