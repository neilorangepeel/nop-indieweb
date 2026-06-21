# nop-indieweb — Session Start

## Sync with GitHub on first invocation

At the start of every new session in this repo, before doing other work, sync with `origin` so changes from other machines are picked up. Run once per session:

1. `git fetch origin`
2. Inspect state:
   - `git status --porcelain` (working tree)
   - `git rev-list --left-right --count HEAD...@{u}` (ahead/behind)
3. Act based on state:
   - **Clean + behind only** → `git pull --ff-only` and report what changed (commit subjects).
   - **Clean + up to date** → report "in sync" in one line.
   - **Clean + ahead** → report ahead count; do not push (user pushes when ready).
   - **Dirty tree** → do not pull. Report uncommitted files so the user can decide.
   - **Diverged** (both ahead and behind) → do not pull or merge. Report and wait for instructions.

Never run `git reset --hard`, `git pull --rebase`, force-push, or stash-and-pull automatically. The goal is visibility, not automation past the safe fast-forward case.

Skip the sync if the user's first message clearly doesn't need repo state (e.g. a pure question about Claude Code itself).

## Model selection

At the start of each prompt, assess task complexity and recommend a model switch if warranted — say "this looks like an Opus task — consider `/model opus` first" before proceeding.

Recommend **Opus** when the task involves:
- Architectural decisions with genuinely open design space
- Debugging where the root cause is unclear and requires multi-step reasoning
- Writing a new system from scratch (not extending existing code)

Default to **Sonnet** for everything else: feature additions, bug fixes, edits, reviews, and any task with a clear known solution.

## Build step

The settings page React app lives in `src/settings/` and is compiled to `build/settings/` via `@wordpress/scripts`. Run `npm run build` from the plugin root before committing whenever you change any file under `src/`. The compiled output in `build/` is committed to git — the production server does `git pull` with no npm step.

```bash
npm run build       # one-off production build
npm start           # watch mode during development
```

## PHP quality tooling (dev-only)

`composer.json` declares **dev-only** dependencies — PHPUnit, PHPStan, and
`szepeviktor/phpstan-wordpress` (WordPress stubs). `vendor/` is gitignored and is
**never** present on the production server; deployment is `git pull` with no
composer step, so nothing here can reach runtime.

```bash
composer install            # once, to pull the dev toolchain
composer test               # PHPUnit — pure-logic unit tests in tests/php/
composer phpstan            # static analysis (level 5, WP stubs)
composer phpstan:baseline   # seed phpstan-baseline.neon from current findings
```

Notes:
- **No local PHP in Studio.** Studio runs WordPress through WASM, so there's no
  CLI `php`/`composer` here — these run in GitHub Actions (`.github/workflows/ci.yml`:
  lint, PHPUnit, and PHPStan are all **blocking**). To smoke-test PHP changes
  locally, use `studio wp eval` / `studio wp eval-file` (a fatal/parse error breaks
  `studio wp eval 'echo "ok";'`).
- **PHPStan is baselined.** `phpstan-baseline.neon` absorbs pre-existing findings,
  so CI fails only on NEW type errors. After fixing baseline entries, regenerate it
  by running the `phpstan-baseline` workflow (Actions tab → Run workflow) and
  committing the uploaded artifact, or `composer phpstan:baseline` where PHP exists.
- **Unit tests are WP-free.** `tests/php/bootstrap.php` stubs the handful of WP
  functions the code under test calls; only side-effect-free classes (parsers,
  formatters) belong in this suite. Anything needing the DB/network is out of scope.

## Server-side files not in git

The following files exist on the production server but are intentionally outside this repo. Recreate them manually after a fresh server provision.

**`wp-content/mu-plugins/nop-session.php`**
Extends the login session to one year for user ID 1 (neilhainsworth), network-wide including network admin. Safe in mu-plugins — never touched by WP core, plugin, or theme updates.

```php
<?php
/**
 * NOP — Persistent admin session
 *
 * Extends the WordPress login cookie to one year for the site owner
 * (user ID 1, neilhainsworth) so the admin stays logged in across all
 * sites in the Multisite network, including the network admin dashboard.
 *
 * WordPress default: 14 days with "Remember Me", 2 days without.
 * This filter overrides that for user ID 1 only — all other users keep
 * the default expiry.
 *
 * Safe to keep here: mu-plugins/ is never modified by WordPress core,
 * plugin, or theme updates. See CLAUDE.md in the nop-indieweb plugin
 * for the canonical copy of this file's contents.
 *
 * To recreate after a server reprovision, see:
 * wp-content/plugins/nop-indieweb/CLAUDE.md → "Server-side files not in git"
 */

add_filter( 'auth_cookie_expiration', function ( int $expiration, int $user_id ): int {
	return 1 === $user_id ? YEAR_IN_SECONDS : $expiration;
}, 10, 2 );
```

**Static ffmpeg binary + `wp-content/mu-plugins/nop-ffmpeg.php`**
The syndication video transcoder (`Video_Transcoder`) re-encodes iOS `.mov` Stories
to 1080p H.264 MP4 so Bluesky/Pixelfed/Mastodon accept them. The host's stock
`/usr/local/bin/ffmpeg` has **no software H.264 encoder** (no libx264), so a static
build is needed. Without it the transcoder falls back to lossless remux+trim (no
re-encode, no HEVC support, cruder size control), so the plugin still works — just
not as well. To recreate after a reprovision:

```bash
# 1. Static ffmpeg WITH libx264, in the account home (PHP exec reaches it; no open_basedir):
mkdir -p ~/bin && cd /tmp \
  && curl -sSL -o ff.tar.xz https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz \
  && tar xf ff.tar.xz && mv ffmpeg-*-static/ffmpeg ~/bin/nop-ffmpeg && chmod +x ~/bin/nop-ffmpeg
# 2. Point the plugin's filter at it (path is the REAL home, e.g. /home/u860-.../bin/nop-ffmpeg):
```
```php
<?php
// wp-content/mu-plugins/nop-ffmpeg.php
add_filter( 'nop_indieweb_ffmpeg_bin', function () {
	$bin = '/home/u860-mcfzdhara7gs/bin/nop-ffmpeg';
	return is_executable( $bin ) ? $bin : '';
} );
```
Notes: encoding runs **single-threaded** (`-threads 1`) — SiteGround's sandbox blocks
libx264's worker threads (`Generic error in an external library` otherwise). ffprobe
stays the stock binary (probing needs no encoder). The CRF default (23) and budgets
are filterable: `nop_indieweb_syndication_video_crf`, `..._video_max_bytes`.

## i18n

All user-facing strings in PHP — including button labels, aria-labels, status text, and any copy visible to users or assistive technology — must use `__()`, `_n()`, `_x()`, or their escaping equivalents (`esc_html__()`, `esc_attr_e()`, etc.) with text domain `'nop-indieweb'`. This applies to both the real render path and editor-preview branches.

## Styling architecture — one owner per concern

The plugin must stay self-contained and restyleable by any theme (it's intended to be opened up to others). Style lives in one place per concern:

1. **Brand VALUES → the active theme** (`theme.json`). The plugin only defines neutral/semantic *defaults* as `:root` custom properties (`assets/css/blocks-shared.css`). The neilorangepeel theme sets the real values in `theme.json`'s top-level `styles.css` (e.g. `:root{--nop-radius:4px}`).
2. **Theme-specific block tweaks → the theme's `theme.json` `styles.blocks.nop-indieweb/<block>.css`** (the core per-block `css` property). This is the WordPress-native seam any theme uses to re-brand a block — no plugin edits.
3. **User-adjustable styling → each `block.json` `supports`** (typography/colour/spacing).
4. **Structure & interaction → the plugin's CSS files** (`blocks-shared.css` + per-block `style.css`): layout, `:has()`, hover, `@media`, animations, facepile, microformats — everything declarative JSON can't express.

**Rules:**
- The plugin never hardcodes a brand value — always `var(--nop-token, <neutral default>)`.
- A token meant to be theme-overridable **must be defined at `:root`** in `blocks-shared.css`. A value set on a block root via `:where()` shadows a theme's `:root` override. Preset-derived (`--nop-divider-color` etc.) and structural (`--nop-pill-*`) tokens stay block-scoped.
- Don't migrate structural CSS into `theme.json` `css` strings — it fragments the source of truth. Platform brand colours (Mastodon/Bluesky/Pixelfed) and pure effects (box-shadows) stay hardcoded in the plugin CSS by design.
