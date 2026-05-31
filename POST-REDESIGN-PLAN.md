# Redesign the `/post` Quick-Post page — Bauhaus / Paul Rand poster

> Working plan, committed to the repo so it's accessible from any machine.
> Dev-only doc (excluded from the wp.org dist via `.distignore`). Not yet implemented.

## Context

`/post` is a personal, standalone mobile Micropub client rendered from one self-contained PHP file (`includes/class-posting-page.php`, inline CSS + JS, no build step). It works and is fast (renders on `parse_request`, syndication config inlined, font preloaded), but it **feels flat and anonymous**: soft translucent glass + low-contrast hairlines flatten everything to one value, there's no identity, and it reads as an extension of the WordPress site rather than its own thing.

Neil wants it reimagined as its own bold object — explicitly *separate* from the website — in the spirit of the designers he follows: **Paul Rand, Saul Bass, Aaron Draplin, Hey Studio, Trent Walton, Simon Foster**. Chosen direction (confirmed):

- **Palette: Bauhaus primaries** — Red `#E63329`, Blue `#1E4FD6`, Yellow `#FFC400`, Ink `#141414`, Paper `#F4EFE6`.
- **Background: one fixed flat poster field** (no soft gradient), but **time-of-day is still represented** — see the open decision below.
- **Fonts: Brandon Text** (body) + **Brandon Text Condensed** (display). Multiple weights are fine — it's personal and they cache.
- **Performance stays paramount** — flat colour actually paints faster than today's backdrop-blur, so the aesthetic and the speed goal pull the same direction.

Guiding principle (Neil's words): *the best design is form and function — it must make sense.*

## OPEN DECISION — quirky time-of-day device

Neil: *"I like the idea of the time of day being represented somehow, it grounds you in the present — that's what the gradient was doing — but if we can find a way to incorporate that in a quirky way that would be fun."*

So: **keep** a sense of time-of-day, but **not** the old soft per-second gradient repaint. It must be flat, primary-coloured, computed in vanilla JS (no per-second `document.body.style.background` thrash), and on-aesthetic. Candidate motifs to pick from (decide before implementing):

- A bold geometric **sun/moon dot tracking an arc/band** across the masthead by hour (Bass-ish cut-paper).
- A **rotating primary-colour shape** whose angle maps to the hour.
- A **day/night cut-paper motif** that swaps form (and which primary is foregrounded) by time block — morning/afternoon/evening/night.
- A time-stamped poster element paired with the dynamic greeting.

## Design vision — modernist poster, figure-ground, wit

Treat the screen as a **Rand poster**: a flat paper field, decisive primary-colour blocks, hard geometric edges, generous grid whitespace, and big confident type. Colour is used sparingly and structurally (not decoratively), icons are bold cut-paper geometric forms (Bass), interactive blocks use **knockout** figure-ground (label/icon punched out of a solid primary), and depth comes from **hard offset shadows** (`4px 4px 0 ink`) that collapse on press — never soft blur. The writing surface is the hero; everything else is disclosed progressively.

### Colour tokens (CSS custom properties on `:root`, role-swapped for dark)
- `--paper:#F4EFE6` (field/bg) · `--ink:#141414` (text, 2px rules, knockouts) · `--red:#E63329` · `--blue:#1E4FD6` · `--yellow:#FFC400`.
- `--on-red / --on-blue` = paper; `--on-yellow` = ink (contrast).
- **Dark mode** (`prefers-color-scheme: dark`): swap roles — field becomes ink `#141414`, text becomes paper; the three primaries stay vivid (they pop hard on black — peak Rand/Bass).

### Per-type colour + icon (disciplined, repeats primaries — that's on-aesthetic)
Note→Yellow · Photo→Blue · Reply→Red · Like→Red (heart) · Bookmark→Yellow · Repost→Blue · Article→Ink · RSVP→Blue. Types sharing a hue are told apart by bold geometric icons. The selected type sets `--accent`/`--on-accent` on `.app`, retinting the active pill, focus rings, and Post button together.

## Concrete changes (all in `includes/class-posting-page.php`)

**1. Strip the soft layer (aesthetic + perf).** Remove `backdrop-filter` glass and the `color-mix` translucent surfaces. Replace the **time-of-day sky** JS (`SKY_STOPS`, `paintSky`, the per-second `document.body.style.background` writes) with the chosen quirky time-of-day device above — flat, cheap, no per-second background repaint. Background becomes one flat `--paper`/`--ink` field. Keep `updateClock` for the time display.

**2. Identity header ("what is this at a glance").** A bold poster masthead: a bespoke **inline geometric mark** (cut-paper primary shapes — e.g. a knockout "post/send" glyph, built as inline SVG or CSS so it costs no request), a **wordmark in Brandon Condensed 800 caps**, and a **dynamic time-of-day greeting** (JS, free) for warmth. Live clock in tabular Brandon at right. Header divided from the body by a 2px ink rule, not a faint hairline.

**3. Typography.** `@font-face` load Brandon Text **400/500/700/800** + **Brandon Text Condensed 700/800** from `get_theme_file_uri('assets/fonts/brandon-text')`; `font-display:swap`; preload the body 400 and the condensed display weight. Hierarchy: Condensed 800 caps for wordmark + section microlabels (CONTENT, TAGS, SYNDICATE); Brandon 700 for buttons; 400/500 for inputs/body. Expressive large type as a graphic element (Trent Walton). Tighten letter-spacing on the caps.

**4. Layout — Bauhaus grid, compose-first.**
- **Type selector**: a tidy grid/row of bold geometric **icon tiles**; inactive = paper tile + 2px ink edge + ink icon (with a small primary dot hinting its hue); active = solid primary block with the icon **knocked out** (figure-ground). Sharp corners (`--radius` → 0–2px). Scrolls/wraps to absorb the 8 types.
- **Compose = hero**: large inviting content textarea (~17–18px) with the rotating prompt. Conditional fields animate in above it per type: URL (reply/like/bookmark/repost), **Title** (article), **RSVP segmented control** (yes/no/maybe/interested — bold blocks), photo picker (photo).
- **Tags**: solid primary chips with knockout text + a square remove button. **Syndicate-to**: a collapsible row of toggles surfacing the per-platform character limits already computed in `currentLimit()`.
- **Post button**: full-width solid primary (the active type's colour) with knockout label and a **hard offset shadow that collapses on `:active`** (tactile, snappy). Clear dimmed disabled state. Keep `prefers-reduced-motion` guard.

**5. New post types (backend already supports — free, and they make sense).**
- **Article**: show a Title input for the article type; in `buildPayload()` set `props.name = [title]`. Note service treats `name` ≠ `content` as an article (`includes/services/class-service-note.php`).
- **RSVP**: add to `TYPE_CONFIG` (`urlProp:'in-reply-to'`, optional content) + a segmented yes/no/maybe/interested control; `buildPayload()` sets `props.rsvp = [value]`. Handled by `includes/services/class-service-rsvp.php`.
- (Listen has no backend; Checkin needs a nested h-card the simple UI can't build — both out of scope.)

**6. Progress + success.** Retint with the active accent. Make success a poster moment: a solid primary block, big Condensed "POSTED", knockout geometric check; elevate the existing **daily streak** ("Your 3rd post today") into a confident line. Keep permalink, Edit, Instagram-share, vibrate.

## Performance guardrails (do not regress)
- Keep the `parse_request` early render, inlined `NOP.syndicateTo` (no `?q=config` fetch), and explicit `nocache_headers()` + `Content-Type`.
- Everything stays **inline** (CSS/JS/SVG mark) — no framework, no build, no new requests except the cached fonts. Removing blur + the per-second sky repaint is a net rendering win.
- `font-display:swap` + preload the two critical faces; JS stays vanilla/event-delegated; transitions short; reduced-motion respected.
- All user-facing strings keep `esc_html_e()`/`esc_attr_e()` with text domain `nop-indieweb` (greeting + RSVP labels included).

## Files
- **Edit (only):** `includes/class-posting-page.php`.
- **Read for assets (inline/preload, not new code):** `themes/neilorangepeel/assets/fonts/brandon-text/` (woff2 weights `400/500/700/800` + condensed). The mark is bespoke/inline — no external logo file needed.
- **Reuse (no change):** Note service (article), RSVP service, Micropub/media endpoints, existing draft/streak/char-counter JS.

## Verification
- PHP-only file (not under `src/`) — **no `npm` build**. `php -l includes/class-posting-page.php` for syntax.
- Load `/post` logged-in; check mobile width and ≥600px desktop:
  - Reads instantly as its own bold object — mark + Condensed wordmark + greeting; high contrast, no glass/blur; flat paper field.
  - The quirky time-of-day device reflects the current hour and updates without a per-second background repaint.
  - **Dark mode** (`prefers-color-scheme: dark`): ink field, paper text, primaries pop. Check `prefers-reduced-motion`.
  - Each type tile shows its primary; selecting one knocks out the icon and retints focus rings + Post button; conditional fields appear correctly (URL, Title, RSVP segmented, photos).
  - Post each type end-to-end → 201 + permalink/Edit; **Article** creates a titled post (kind=article); **RSVP** stores the value (kind=rsvp); syndicate toggles + char limits work; draft persists across reload; streak increments.
  - Network tab: no `?q=config` request; no per-second background style thrash; fonts load once then cache.
