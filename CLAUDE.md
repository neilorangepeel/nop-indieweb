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

## i18n

All user-facing strings in PHP — including button labels, aria-labels, status text, and any copy visible to users or assistive technology — must use `__()`, `_n()`, `_x()`, or their escaping equivalents (`esc_html__()`, `esc_attr_e()`, etc.) with text domain `'nop-indieweb'`. This applies to both the real render path and editor-preview branches.
