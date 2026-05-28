# WordPress.org Plugin Check — Remediation Plan

Working doc for getting **NOP IndieWeb** through the WordPress.org Plugin Check
(`plugin-check` / `wp plugin check`), which is the automated gate the wp.org
review team runs. Grouped into sprints; each task has enough context to start
cold in a fresh session.

> This file is a dev artifact. Add it to `.distignore` (or delete it) before
> packaging so it doesn't ship or trip `unexpected_markdown_file`.

---

## How to run the checker (Studio)

```bash
# one-time
studio wp plugin install plugin-check --activate

# full run (tabular)
studio wp plugin check nop-indieweb

# machine-readable, easier to diff/tally
studio wp plugin check nop-indieweb --format=csv > /tmp/pc.csv

# tally by severity + code
awk -F'\t' 'NF>=5 && ($3=="ERROR"||$3=="WARNING"){print $3" "$4}' /tmp/pc.txt | sort | uniq -c | sort -rn
```

Notes:
- The checker scans the **working directory**, not the distributable zip. Several
  findings (hidden files, markdown, `.github`, `.claude`) only exist because of
  that — they are already handled by `.distignore` and will not appear in the
  shipped package. Verify by building the zip and checking that instead (Sprint 6).
- There is no `php` CLI in this environment. Lint via
  `studio wp eval '... token_get_all(file_get_contents($f), TOKEN_PARSE) ...'`.
- After **any** edit that inserts code near the top of a PHP file, confirm
  `declare( strict_types=1 );` is still the first statement and the ABSPATH guard
  sits *after* it (a guard before `declare()`/`namespace` is a fatal — this bit us
  once already on `blocks/weather-icon/render.php`).

---

## Baseline (current state on branch `review-hardening`)

As of the last run after today's commits:

| Severity | Count |
|----------|-------|
| ERROR    | 145   |
| WARNING  | 94    |

By code (highest first):

| Count | Sev | Code | Sprint |
|------:|-----|------|--------|
| 108 | ERROR | `WordPress.Security.EscapeOutput.OutputNotEscaped` | 2 |
| 17 | WARN | `WordPress.DB.SlowDBQuery.slow_db_query_meta_query` | 5 |
| 16 | WARN | `WordPress.DB.DirectDatabaseQuery.DirectQuery` | 3 |
| 15 | WARN | `WordPress.DB.DirectDatabaseQuery.NoCaching` | 3 |
| 11 | ERROR | `missing_direct_file_access_protection` (all in `bin/`) | 1 |
| 8 | WARN | `WordPress.DB.SlowDBQuery.slow_db_query_tax_query` | 5 |
| 8 | WARN | `WordPress.DB.SlowDBQuery.slow_db_query_meta_key` | 5 |
| 8 | WARN | `WordPress.Security.NonceVerification.Recommended` | 5 |
| 6 | WARN | `WordPress.DB.SlowDBQuery.slow_db_query_meta_value` | 5 |
| 4 | ERROR | `WordPress.DB.PreparedSQL.NotPrepared` | 3 |
| 3 | WARN | `unexpected_markdown_file` (zip-only, `.distignore`) | 1 |
| 3 | WARN | `WordPress.Security.SafeRedirect.wp_redirect_wp_redirect` | 5 |
| 3 | ERROR | `WordPress.WP.AlternativeFunctions.file_system_operations_fwrite` | 4 |
| 3 | ERROR | `PluginCheck.CodeAnalysis.Offloading.OffloadedContent` | 4 |
| 2 | WARN | `hidden_files` (zip-only, `.distignore`) | 1 |
| 2 | WARN | `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | 3 |
| 1 | WARN | `github_directory` / `ai_instruction_directory` (zip-only) | 1 |
| 1 | WARN | `WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude` | 5 |
| 1 | WARN | `WordPress.PHP.DevelopmentFunctions.error_log_error_log` | 5 |
| 1 | WARN | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 3 |
| 1 | WARN | `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` | 5 |
| 1 | ERROR | `application_detected` | 4 |
| 1 | ERROR | `WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet` | 4 |
| 1 | ERROR | `WordPress.WP.AlternativeFunctions.file_system_operations_rmdir` | 4 |
| 1 | ERROR | `PluginCheck.CodeAnalysis.Heredoc.NotAllowed` | 4 |

### Already fixed today (on `review-hardening`, do not redo)

- `no_plugin_readme` → added `readme.txt` (with External Services disclosure).
- `outdated_tested_upto_header` → `Tested up to: 7.0`.
- `missing_direct_file_access_protection` on all shipped class/render/helper files
  (79 files) → ABSPATH guards added.
- `WordPress.WP.AlternativeFunctions.unlink_unlink` (15) → `wp_delete_file()`.
- `WordPress.WP.AlternativeFunctions.parse_url_parse_url` (2) → `wp_parse_url()`.
- `WordPress.Security.ValidatedSanitizedInput.*` (4) → `wp_unslash()` +
  `sanitize_text_field()` on `$_SERVER['REMOTE_ADDR']`.
- `WordPress.WP.I18n.MissingTranslatorsComment` (was 15) → translators comments
  added. **Re-run to confirm 0**; a couple of batch edits had to be re-applied,
  so verify none regressed.
- `.distignore` added (covers the zip-only findings in Sprint 1).

---

## Sprint 1 — Submission blockers & packaging (mostly DONE — verify)

Goal: the hard gates that stop a submission outright. Most are done; this sprint
is now mostly **verification**.

### 1.1 Confirm `readme.txt` passes
- `studio wp plugin check nop-indieweb | grep -i readme` → expect nothing.
- Validate against https://wordpress.org/plugins/developers/readme-validator/ when
  online (paste contents). Confirm Contributors slug `neilorangepeel` matches the
  intended wp.org account, or change it.

### 1.2 Confirm the zip-only findings disappear in the package
`hidden_files`, `unexpected_markdown_file`, `github_directory`,
`ai_instruction_directory` all come from scanning the working tree. They are
listed in `.distignore`. **Action:** build the dist zip and run the checker on the
*extracted zip*, not the repo (see Sprint 6). Expected: all four gone.

### 1.3 `application_detected` (1 ERROR)
- Find it: `grep -B5 application_detected /tmp/pc.txt` to get the FILE.
- Likely a stray binary/asset the scanner thinks is an application (candidate: a
  cached `.png`, a `.DS_Store` that regenerated, or something under `bin/`).
- If it's dev-only, ensure it's in `.distignore`. If it's a needed asset,
  investigate why it's flagged. Confirm `bin/fb-archive/` (untracked) is not being
  packaged.

**Exit criteria:** Plugin Check on the *zip* shows no `no_plugin_readme`,
`hidden_files`, `unexpected_markdown_file`, `github_directory`,
`ai_instruction_directory`, or `application_detected`.

---

## Sprint 2 — Output escaping (108 ERRORS) — the big one

`WordPress.Security.EscapeOutput.OutputNotEscaped`. This is the bulk of the work
and needs **per-line judgement** — do NOT bulk-sed. Three distinct patterns, each
with a different correct fix. Work file-by-file; re-run the checker after each file.

Get the per-file list:
```bash
awk -F'\t' '/^FILE:/{f=$0; sub(/^FILE: /,"",f)} NF>=5 && $4 ~ /OutputNotEscaped/{c[f]++} END{for(k in c)print c[k], k}' /tmp/pc.txt | sort -rn
```

### Pattern A — `get_block_wrapper_attributes()` (≈20 hits)
Lines like `<div <?php echo $wrapper_attrs; ?>>` where
`$wrapper_attrs = get_block_wrapper_attributes( [...] )`.
- This function **returns already-escaped** attribute markup. It is a known
  checker false-positive.
- **Fix (preferred, matches WP core blocks):** echo the call inline and add a
  scoped ignore with justification:
  ```php
  <div <?php echo wp_kses_data( $wrapper_attrs ); ?>>
  ```
  `wp_kses_data()` is the accepted escaper here and silences the sniff without a
  raw ignore. (Do **not** use `esc_attr()` — it would double-encode the quotes and
  break the markup.)
- Files: every `blocks/*/render.php` (checkin-map, film-meta, film-card,
  like-button, post-footer, post-source, rsvp-meta, syndication-panel,
  weather-icon, weather-temp), `blocks/webmentions/render.php` (2×),
  `blocks/webmentions/helpers.php` (`nop_wm_render_empty_state`).

### Pattern B — inline SVG / pre-built HTML strings (≈30 hits)
Lines echoing `$icon`, `$heart_icon`, `$comment_icon`, `$repost_icon`,
`$stars_html`, `$platform_tag`, `nop_wm_*()` helper returns.
- These are trusted, plugin-authored HTML (SVGs, microformat spans). They must be
  escaped with an SVG/HTML-aware allowlist, not `esc_html` (which would print the
  tags as text).
- **Fix:** define one shared `nop_indieweb_kses_svg()` allowlist
  (`svg, path, polyline, circle, line, rect, g` + attrs) and wrap icon echoes:
  `echo wp_kses( $heart_icon, nop_indieweb_kses_svg() );`. For mixed HTML helper
  output (`$platform_tag`, `nop_wm_liked_by()`), use `wp_kses_post()` or a tailored
  allowlist.
- Put the helper in `includes/utils/functions.php` so all render files share it.
- Files: `blocks/like-button/render.php`, `blocks/post-footer/render.php`,
  `blocks/film-meta/render.php`, `blocks/webmentions/render.php`,
  `blocks/webmentions/helpers.php`.

### Pattern C — pre-escaped scalars echoed without re-escaping (≈55 hits)
Concentrated in `includes/admin/class-settings.php` and
`includes/indieauth/class-auth-endpoint.php`. Two sub-cases:
1. URL vars already passed through `esc_url()` at assignment, then echoed raw —
   e.g. `$micropub_url = esc_url(...)` then `<a href="<?php echo $micropub_url ?>">`.
   The checker can't see the earlier escape. **Fix:** escape at the point of output
   instead and drop the early escape, or wrap inline:
   `href="<?php echo esc_url( $micropub_url ); ?>"`. Known lines in
   `class-settings.php`: ~452, 465, 510, 522, 673, 686, 760 (URLs), plus the
   `$auth_url`/`$token_url`/`$mf2_url`/`$endpoint`/`$moderated_url` echoes.
2. `echo self::OPTION_KEY` and `echo "{$prefix}[field]"` inside `name="..."`
   attributes — these are constant/derived strings but still need `esc_attr()`.
   Known lines in `class-settings.php`: ~533, 746, 778, 1018, 1027, 1183, 1209,
   1219, 1248, 1276, 1304, 1322, 1340. **Fix:** wrap each in `esc_attr()`.
   - Consider a small refactor: a `name_attr( $prefix, $field )` helper that returns
     `esc_attr( "{$prefix}[{$field}]" )` to make this uniform and not regress.
- `auth-endpoint.php`: `$client_label` echo (~line 203) — already `esc_html()`'d at
  assignment; move the escape to the echo or confirm and add a justified ignore.

**Approach:** do `class-settings.php` first (biggest single file), then
`auth-endpoint.php`, then the block render files (A + B together per file).
Re-run after each. Budget: this is the multi-hour item.

**Exit criteria:** `OutputNotEscaped` count = 0, and **manually confirm no
double-escaping** (visit Settings → IndieWeb and a checkin/note/film post; check
URLs, names, and SVGs render correctly, not as literal `&lt;svg&gt;` or
`%3A%2F%2F`).

---

## Sprint 3 — Database safety (SQL + caching)

### 3.1 `WordPress.DB.PreparedSQL.NotPrepared` (4 ERROR) + `InterpolatedNotPrepared` (2 WARN) + `UnescapedDBParameter` (1)
- `includes/indieauth/class-token-store.php` ~lines 110, 126: the custom table name
  `self::table_name()` is interpolated into the query string. Table/identifier names
  **cannot** be bound with `$wpdb->prepare()` placeholders. **Fix:** keep the
  interpolation but (a) ensure the table name is built only from
  `$wpdb->prefix . 'constant'` (it is), and (b) add
  `// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a constant identifier, not user input` with a one-line why. Any *values* in those queries must still go through `prepare()`.
- `uninstall.php` ~lines 48, 50: `DELETE ... WHERE comment_id IN ({$ids})` where
  `$ids` is built from `array_map( 'intval', ... )`. Safe but flagged. **Fix:**
  rebuild using `$wpdb->prepare()` with a generated placeholder list
  (`implode( ',', array_fill( 0, count($ids), '%d' ) )`) and spread the ints, OR
  add a justified `phpcs:ignore` noting the values are `intval`-cast.
- Re-read each flagged line; confirm no genuinely user-controlled value reaches SQL
  unprepared before suppressing.

### 3.2 `WordPress.DB.DirectDatabaseQuery.DirectQuery` (16 WARN) + `NoCaching` (15 WARN)
- These fire on the custom-table reads/writes (token store) and the venue-visit
  renumbering / dedup counting (`includes/class-plugin.php`,
  `includes/utils/functions.php`) and `uninstall.php`.
- DirectQuery on a custom table is legitimate (no WP API for it). NoCaching wants a
  comment or a `wp_cache_*` layer.
- **Fix:** for each, add
  `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table / one-off maintenance query`
  with a real justification. For hot-path reads (token lookup on every Micropub
  request) consider a genuine `wp_cache_get/set` around the lookup — optional, note
  it as a perf nicety, not a blocker.

**Exit criteria:** PreparedSQL ERRORs = 0 (fixed or justified-ignored); DirectDB
warnings each carry a justification comment.

---

## Sprint 4 — Filesystem & misc ERRORS

### 4.1 `file_system_operations_fwrite` (3) + `rmdir` (1)
- `includes/utils/functions.php`: `file_put_contents()` writing the cached map PNG,
  and `file_put_contents($tmp,'')` truncating the redirect body; `uninstall.php`:
  `@rmdir()` on the maps dir.
- The checker wants `WP_Filesystem` instead of direct writes. **Fix options:**
  (a) initialise `WP_Filesystem()` and use `$wp_filesystem->put_contents()` /
  `->rmdir()`, or (b) for the map cache, prefer `media_handle_sideload()`/attachment
  APIs (the map is already an upload — consider storing it as a proper attachment),
  or (c) justified `phpcs:ignore` for the tmp-truncate (it's a local temp file, not
  user content). Pick per-call; the map write is the one worth doing properly.

### 4.2 `PluginCheck.CodeAnalysis.Heredoc.NotAllowed` (1)
- `includes/class-plugin.php` `register_patterns()` uses `<<<'HTML'` nowdoc blocks
  for the block-pattern markup.
- The checker disallows heredoc/nowdoc (hard to scan for escaping). **Fix:** move
  each pattern's markup into a real pattern file under `patterns/` with the standard
  pattern header, and register via `register_block_pattern()` pointing at the file,
  or load from a `.php`/`.html` partial. This also de-bloats the bootstrap class.
  Larger refactor — scope it on its own.

### 4.3 `PluginCheck.CodeAnalysis.Offloading.OffloadedContent` (3)
- Static map image fetched/served from Geoapify, and possibly the TMDB poster /
  remote avatars referenced in markup.
- The checker flags loading assets from third parties. The map is already cached
  locally (good) — confirm the **rendered** `<img>` points at the local cached copy,
  not the remote URL. For remote avatars (webmention author photos) and TMDB
  posters, that's inherent to the feature; **document** in readme (already partly
  done) and add justified ignores where the remote reference is intentional.

### 4.4 `WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet` (1)
- `includes/indieauth/class-auth-endpoint.php` ~line 173 prints
  `<link rel="stylesheet" href=".../wp-admin/css/login.css">` directly in the
  standalone authorize page.
- This is a deliberately standalone HTML page (not a normal WP front-end view), so
  `wp_enqueue_style` doesn't naturally apply. **Fix:** either enqueue properly via
  `login_enqueue_scripts` if rendered through the login header, or add a justified
  `phpcs:ignore` explaining it's a self-contained consent screen. Lowest-risk:
  justified ignore.

**Exit criteria:** these 6 ERRORs fixed or each carrying a specific justification.

---

## Sprint 5 — Warnings cleanup (low risk, mostly justifications)

These are **WARNINGS** — they do not block submission but a clean run reads better
to reviewers. Most are justified-ignore or tiny changes.

### 5.1 `SlowDBQuery.*` (39 total: meta_query/meta_key/meta_value/tax_query)
- All on legitimate `get_posts`/`get_comments`/`WP_Query` meta+tax lookups used for
  dedup, venue-visit counting, network status, and the post-footer counts.
- **Fix:** justified `phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query`
  per call, noting these are admin/low-frequency or already memoised. Do NOT
  restructure data model for this.

### 5.2 `NonceVerification.Recommended` (8)
- `$_GET['context']`/`$_GET['post_id']` reads in block render files (already have
  `// phpcs:ignore WordPress.Security.NonceVerification` on most — find the 8 that
  slipped) and read-only admin `$_GET` reads in `class-debug.php` /
  `class-post-filter.php`.
- These are read-only display reads, not state changes. **Fix:** add the
  `phpcs:ignore` with `-- read-only display value, no state change` to the 8 flagged
  lines. Confirm none actually perform a mutation.

### 5.3 `SafeRedirect.wp_redirect_wp_redirect` (3)
- `includes/indieauth/class-auth-endpoint.php` (OAuth code redirect, ~line 135) and
  the Foursquare OAuth redirect in `class-plugin.php`.
- These are **intentional cross-origin** redirects (back to the Micropub client /
  to foursquare.com) where `wp_safe_redirect` would wrongly block them. The targets
  are validated against `client_id` / are a known host. **Fix:** justified
  `phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect` with the
  "cross-origin OAuth target, re-validated above" reason.

### 5.4 `error_log_error_log` (1)
- `includes/utils/functions.php` `nop_indieweb_log()` calls `error_log()`.
- It's already gated behind the debug-mode setting. **Fix:** wrap the call in
  `if ( defined('WP_DEBUG') && WP_DEBUG )` as well, or justified ignore noting it's
  opt-in debug logging only.

### 5.5 `DiscouragedFunctions.load_plugin_textdomainFound` (1)
- Since WP 4.6, wp.org-hosted plugins auto-load translations; an explicit
  `load_plugin_textdomain()` is flagged as unnecessary.
- **Fix:** once the plugin is wp.org-hosted you may simply remove the
  `load_plugin_textdomain()` call added in `nop-indieweb.php`. Until then it's
  harmless. Decide at submission time. (Keep it if you also distribute off-wp.org.)

### 5.6 `WPQueryParams.PostNotIn_exclude` (1)
- A query uses `post__not_in` / `exclude`. VIP-minimum perf nag. **Fix:** justified
  ignore, or filter results in PHP if the set is small.

**Exit criteria:** warnings either resolved or each carrying a specific, honest
`phpcs:ignore ... -- reason`. Avoid blanket file-level ignores.

---

## Sprint 6 — Package & final verification

1. Build the distributable zip honoring `.distignore`:
   ```bash
   # if wp-cli dist-archive is available
   studio wp dist-archive . /tmp/nop-indieweb.zip
   # else: rsync the tree excluding .distignore patterns, then zip
   ```
2. Extract to a clean dir and run the checker **on the extracted plugin**:
   ```bash
   studio wp plugin check /path/to/extracted/nop-indieweb
   ```
3. Confirm gone: `hidden_files`, `unexpected_markdown_file`, `github_directory`,
   `ai_instruction_directory`, the 11 `missing_direct_file_access_protection` (all
   in `bin/`, which `.distignore` excludes), and this `.md`.
4. Smoke test the built plugin on a clean WP: activate, open Settings → IndieWeb,
   publish a note, render a checkin + film post, confirm no PHP notices in
   `wp-content/debug.log` and no visible double-escaping.
5. Bump `Version:` / `Stable tag:` together, update the changelog in `readme.txt`.

**Exit criteria:** Plugin Check on the zip = 0 ERRORS; remaining WARNINGS all
justified. Ready to submit.

---

## Suggested order & sizing

| Sprint | What | Risk | Rough size |
|--------|------|------|-----------|
| 1 | Blockers/packaging verify | low | small (mostly done) |
| 2 | Escaping (108) | medium (double-escape risk) | **large** |
| 3 | SQL safety | low–medium | small |
| 4 | Filesystem/misc ERRORs | medium (WP_Filesystem, heredoc refactor) | medium |
| 5 | Warnings | low | small–medium |
| 6 | Package + verify | low | small |

Do them in number order. Sprint 2 is the long pole — consider splitting it across
sessions by file group (settings.php; auth-endpoint.php; block render files).

## Guardrails for whoever picks this up

- One concern per commit; re-run Plugin Check after each file in Sprint 2.
- Never place the ABSPATH guard before `declare()`/`namespace` (fatal).
- Prefer fixing over ignoring; when ignoring, always add `-- <reason>`.
- After escaping changes, **look at the rendered page** — the checker is satisfied
  by `esc_*`, but only a human catches double-encoding.
- `studio wp eval '... token_get_all(..., TOKEN_PARSE) ...'` is the lint substitute;
  there's no `php` CLI here.
