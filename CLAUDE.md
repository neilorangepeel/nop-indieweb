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

## i18n

All user-facing strings in PHP — including button labels, aria-labels, status text, and any copy visible to users or assistive technology — must use `__()`, `_n()`, `_x()`, or their escaping equivalents (`esc_html__()`, `esc_attr_e()`, etc.) with text domain `'nop-indieweb'`. This applies to both the real render path and editor-preview branches.
