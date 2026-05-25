# NOP IndieWeb

A WordPress plugin that turns your site into an IndieWeb-first publishing hub: post once on your own domain, syndicate elsewhere, receive replies and reactions back.

Built for [neilorangepeel.com](https://neilorangepeel.com) and shared in case it's useful to someone else.

## What it does

- **Micropub endpoint** — accept posts from any [Micropub client](https://indieweb.org/Micropub/Clients) (Quill, Indigenous, Micropublish, Omnibear, OwnYourSwarm, etc.).
- **IndieAuth server** — issue OAuth-style Bearer tokens with PKCE so those clients can authenticate against your site without a shared secret.
- **POSSE syndication** — automatically cross-post Notes, Photos, Replies, Likes, Reposts, etc. to Bluesky, Mastodon, and Pixelfed.
- **PESOS import** — pull your Mastodon and Bluesky posts back into WordPress on an hourly cron, with original photos and video.
- **Webmentions** — send outbound webmentions when you publish, receive inbound ones (replies, likes, reposts) and display them as a unified conversation under each post.
- **Post kinds** — Notes, Articles, Photos, Bookmarks, Replies, Likes, Reposts, RSVPs, Checkins, Listens, and Film diary entries (Letterboxd import).
- **Native site likes** — a Bluesky-style like button readers can press without logging in; counted alongside webmention likes.
- **mf2 markup** — every post is published with proper [microformats2](https://microformats.org/wiki/microformats2) so other IndieWeb sites can parse it cleanly.

## Requirements

- WordPress **6.7+** (uses block-template hooks added in 6.7)
- PHP **8.0+**
- A block theme (the plugin ships block templates for each post kind)
- HTTPS — required for IndieAuth, strongly recommended for webmentions

## Installation

```bash
cd wp-content/plugins
git clone https://github.com/neilorangepeel/nop-indieweb.git
```

Then activate via **Plugins → Installed Plugins** in wp-admin.

No build step. No Composer dependencies. The plugin is plain PHP + a handful of JS files for the block editor and front-end interactions.

## First-time setup

1. **Activate the plugin.** On activation it creates the `nop_indieweb_tokens` table for IndieAuth.
2. **Visit Settings → IndieWeb.** The settings page walks through the syndicators, webmentions, and identity URLs.
3. **Add `rel="me"` links.** Settings → IndieWeb → General → Identity. List one URL per line for any external profile that links back to you (Mastodon, Bluesky, GitHub). The plugin automatically adds `<link rel="me">` tags to your site's `<head>`.
4. **Configure syndicators.** Each platform has its own toggle:
   - **Mastodon**: instance URL + access token (scope: `write:statuses write:media`; for inbound import also `read:statuses`).
   - **Bluesky**: handle + app password (generate from Bluesky → Settings → App Passwords).
   - **Pixelfed**: instance URL + access token (scope: `write`).
5. **Test the connection.** Each syndicator settings card has a *Test Connection* button. Green tick = credentials work.

## Using the Micropub endpoint

Once the plugin is active, your site exposes a Micropub endpoint at:

```
https://your-site.example/wp-json/nop-indieweb/v1/micropub
```

Discovery is automatic — Micropub clients find it via the `<link rel="micropub">` tag the plugin adds to every page.

### Authorizing a Micropub client

1. The client (e.g. Quill at `https://quill.p3k.io`) prompts you to enter your site URL.
2. Click *Sign In* — you'll be redirected to your site's IndieAuth authorize page.
3. WordPress shows you a consent screen with the requested permissions (`create`, `update`, `delete`, `media`).
4. Click *Authorize*. The client receives a Bearer token and can now post.

Tokens are listed under **Settings → IndieWeb → Sessions** and can be revoked individually.

### Supported post types

The plugin can create the following from a Micropub payload:

| Type | Detected from |
|---|---|
| Note | h-entry with `content` only |
| Article | h-entry with `name` + `content` |
| Bookmark | h-entry with `bookmark-of` |
| Reply | h-entry with `in-reply-to` |
| Like | h-entry with `like-of` |
| Repost | h-entry with `repost-of` |
| RSVP | h-entry with `in-reply-to` + `rsvp` |
| Checkin | h-entry with `checkin` (Swarm via OwnYourSwarm) |
| Photo | h-entry with `photo` |

Each maps to a `nop_kind` taxonomy term that controls which block template renders the post.

## Webmentions

### Receiving

Webmentions arrive at:

```
https://your-site.example/wp-json/nop-indieweb/v1/webmention
```

The endpoint:
- Verifies the source actually links to the target.
- Parses microformats2 to extract type (like / repost / reply / mention), author, content, and original URL.
- Stores as a `comment_type=webmention` row so it appears in the same conversation thread.
- Approval policy (Settings → IndieWeb → Webmentions): `bridgy_only` (default — only Bridgy auto-approved), `auto_all`, or `manual_all`.

[Bridgy](https://brid.gy) is the recommended way to receive webmentions from Mastodon and Bluesky.

### Sending

When you publish a post, the plugin scans `<a href>` links in the content and POSTs a webmention to each linked site's endpoint (discovered via `Link:` header or `<link rel="webmention">`).

## Importing (PESOS)

Inbound import runs hourly via `wp_schedule_event`. To enable:

1. Settings → IndieWeb → Mastodon (or Bluesky) → **Import enabled**.
2. The cron job pulls posts newer than the last cursor and creates Notes with original photos/video sideloaded into the media library.
3. Manual sync button on each platform card for testing.

Posts that originated on your WordPress site (and were syndicated outward) are detected and skipped, so you never reimport your own POSSEd content.

## Blocks

All blocks are namespaced `nop-indieweb/` and grouped under two editor categories: **NOP · Conversations** and **NOP · Kind meta**.

| Block | Purpose |
|---|---|
| `webmentions` | Unified facepile + thread of likes, reposts, replies, mentions, and native site likes. |
| `like-button` | Anonymous one-click like button (no auth, per-IP rate-limited). |
| `post-footer` | Compact interaction row: like, comments, repost count, source link. |
| `post-source` | Renders the canonical source URL for imported posts (Mastodon, Bluesky). |
| `checkin-meta` | Venue name + map + tags for Swarm checkins. |
| `film-meta` / `film-card` | Letterboxd diary entries — star rating, poster, watch date. |
| `rsvp-meta` | RSVP status (yes/no/maybe/interested) for an event reply. |
| `syndication-panel` | Editor sidebar panel showing which platforms a post was syndicated to. |

The plugin also ships block templates (`single-nop_kind-{slug}.html`, `taxonomy-nop_kind-{slug}.html`) for every kind — these are registered with the block-template system and discoverable in the Site Editor.

## REST endpoints

All under `/wp-json/nop-indieweb/v1/`:

| Route | Methods | Auth |
|---|---|---|
| `/micropub` | GET, POST | Bearer token (scope-enforced) |
| `/media` | POST | Bearer token (media or create scope) |
| `/token` | GET, POST | None on POST exchange; Bearer on GET introspect |
| `/authorize` | GET | WP cookie; capability gate |
| `/webmention` | POST | Public; rate-limited |
| `/like` | GET, POST | Public; per-IP rate-limited |
| `/mf2/{id}` | GET | Public for published posts; capability for drafts |

## CLI tools

`bin/` contains CLI-only WP-CLI scripts (refuse to run over HTTP):

```bash
# Migrate legacy categories → nop_kind taxonomy (one-shot, idempotent).
wp eval-file wp-content/plugins/nop-indieweb/bin/migrate-kinds.php

# Retire old "kind-as-category" categories.
wp eval-file wp-content/plugins/nop-indieweb/bin/retire-categories.php

# Smoke-test the syndication pipeline (no live API calls).
wp eval-file wp-content/plugins/nop-indieweb/bin/test-syndication.php

# Smoke-test the import path with synthetic Bluesky records.
wp eval-file wp-content/plugins/nop-indieweb/bin/test-import.php

# Benchmark the strict-redirect SSRF helper.
wp eval-file wp-content/plugins/nop-indieweb/bin/benchmark-strict-fetch.php
```

## Extending

A few useful filters:

| Filter | Default | Purpose |
|---|---|---|
| `nop_indieweb_register_services` | array of built-in services | Register a custom Micropub service handler. |
| `nop_indieweb_authorize_capability` | `manage_options` | Capability required to issue IndieAuth tokens. |
| `nop_indieweb_media_max_bytes` | 25 MB | Per-file cap on Micropub media uploads. |
| `nop_indieweb_media_allowed_mimes` | image/video/audio whitelist | Adjust which MIME types the media endpoint accepts. |
| `nop_indieweb_photo_size_cap` | 25 MB | Max bytes for inbound photo sideload. |
| `nop_indieweb_video_size_cap` | 100 MB | Max bytes for inbound video sideload. |
| `nop_indieweb_webmention_rate_limit` | 20 / 5 min | Per-IP webmention throttle. |
| `nop_indieweb_like_rate_limit` | 30 / min | Per-IP like-button throttle. |
| `nop_indieweb_before_post_insert` | `$post_args, $parsed, $service` | Mutate post args before insert. |
| `nop_indieweb_post_created` (action) | — | Fires after a Micropub-created post is saved. |

## Security

This plugin handles authenticated writes, third-party credentials, and outbound HTTP — all SSRF-and-XSS-relevant surfaces. The codebase has been hardened against the standard threat model:

- IndieAuth tokens stored as SHA-256 hashes, never plaintext; PKCE enforced when supplied.
- Every Micropub write path enforces both token scope and per-post `user_can`.
- Outbound HTTP to third-party services uses a strict helper (`nop_indieweb_strict_remote_get`) that re-validates every redirect hop and blocks private + reserved IP ranges (including `169.254.0.0/16` for cloud-metadata services).
- Inbound HTML from Mastodon/Bluesky passes through `wp_kses_post` before storage.
- Syndication credentials are stored with `autoload=false` so they aren't loaded into memory on every request.
- All HTML/XML parsers use `LIBXML_NONET`.

See the commit log around May 2026 for the full audit + remediation. New features in this plugin should match these defaults — the patterns are documented inline.

## Project structure

```
nop-indieweb/
├── blocks/                  # block.json + render.php for each block
├── includes/
│   ├── admin/               # Settings, metaboxes, debug screen
│   ├── importer/            # PESOS (Mastodon, Bluesky, Letterboxd)
│   ├── indieauth/           # Authorize + token endpoints, token store
│   ├── kind/                # nop_kind taxonomy
│   ├── micropub/            # Micropub endpoint, media endpoint, auth
│   ├── post-meta/           # Meta registry + block bindings
│   ├── semantic/            # mf2 output + mf2 endpoint
│   ├── services/            # Per-kind service handlers
│   ├── syndication/         # POSSE to Bluesky, Mastodon, Pixelfed
│   ├── utils/               # Shared functions, block-content parsing
│   └── webmention/          # Receiver, sender, mf2 parser, backfeed
├── templates/               # Block templates for each kind
├── bin/                     # WP-CLI scripts (smoke tests, migrations)
└── nop-indieweb.php         # Plugin bootstrap
```

## License

GPL-2.0-or-later. Same as WordPress itself.

## Credits

Inspired by the work of the [IndieWeb community](https://indieweb.org) — particularly the [Webmention](https://github.com/pfefferle/wordpress-webmention), [IndieAuth](https://github.com/indieweb/wordpress-indieauth), and [Post Kinds](https://github.com/dshanske/indieweb-post-kinds) plugins, whose conventions this one builds on.
