#!/usr/bin/env python3
"""
Hands brand typography in the block templates back to the active theme.

Font family, weight, tracking and leading are the theme's to set. Baked into a
plugin template they beat theme.json on every post of every kind, so the theme
cannot own its own rhythm — and 34 of the family references here name a font
slug no current theme even defines.

One exception, applied as a rule rather than case by case: an uppercase label
is an idiom, not a voice. Its casing, weight and tracking only read correctly
together, and theme.json has no way to reach those elements to restore them. So
where textTransform is present, weight and tracking stay with it.

The care this needs: a block with saved inner HTML carries each declaration
twice, once as a block attribute and once as an inline style or class. Removing
only the attribute leaves markup Gutenberg would not have written, which is the
"Block contains unexpected or invalid content" warning. Both sides move
together here, and bin/check-block-markup.py verifies the result.

Usage:  strip-template-typography.py [--apply] [dir]
"""

import json
import pathlib
import re
import sys

VOICE = {
    "fontStyle": "font-style",
    "fontWeight": "font-weight",
    "letterSpacing": "letter-spacing",
    "lineHeight": "line-height",
}
# Kept wherever textTransform is present — see the label rule above.
LABEL_KEEPS = ("fontWeight", "letterSpacing")

BLOCK = re.compile(r"<!--\s+wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)\s+(\{.*?\})\s+(/)?-->", re.S)
FIRST_TAG = re.compile(r"<([a-z0-9]+)(\s[^>]*?)(/?)>", re.I)


def strip_decls(tag_html, css_props, family_slug):
    """Removes the matching inline declarations and font-family class from one tag."""

    def fix_style(m):
        decls = [d.strip() for d in m.group(1).split(";") if d.strip()]
        kept = [d for d in decls if d.split(":")[0].strip() not in css_props]
        return f'style="{";".join(kept)}"' if kept else ""

    tag_html = re.sub(r'style="([^"]*)"', fix_style, tag_html)

    if family_slug:
        cls = f"has-{family_slug}-font-family"

        def fix_class(m):
            names = [c for c in m.group(1).split() if c != cls]
            return f'class="{" ".join(names)}"' if names else ""

        tag_html = re.sub(r'class="([^"]*)"', fix_class, tag_html)

    return re.sub(r"\s{2,}", " ", tag_html).replace(" >", ">").replace(" />", "/>")


def process(text):
    out, cursor, changed = [], 0, 0

    for m in BLOCK.finditer(text):
        attrs = json.loads(m.group(2))
        typo = attrs.get("style", {}).get("typography", {})
        is_label = "textTransform" in typo

        drop = [k for k in VOICE if k in typo and not (is_label and k in LABEL_KEEPS)]
        family = attrs.get("fontFamily")
        if not drop and not family:
            continue

        css_props = {VOICE[k] for k in drop}
        for k in drop:
            del typo[k]
        if family:
            del attrs["fontFamily"]

        if "typography" in attrs.get("style", {}) and not typo:
            del attrs["style"]["typography"]
        if "style" in attrs and not attrs["style"]:
            del attrs["style"]

        rebuilt = json.dumps(attrs, separators=(",", ":"), ensure_ascii=False)
        opening = f"<!-- wp:{m.group(1)} {rebuilt} {'/' if m.group(3) else ''}-->"

        out.append(text[cursor:m.start()])
        out.append(opening)
        cursor = m.end()
        changed += 1

        # A self-closing block has no saved markup to keep in step.
        if not m.group(3):
            tag = FIRST_TAG.search(text, cursor, cursor + 600)
            if tag:
                out.append(text[cursor:tag.start()])
                out.append(strip_decls(tag.group(0), css_props, family))
                cursor = tag.end()

    out.append(text[cursor:])
    return "".join(out), changed


def main(argv):
    apply = "--apply" in argv
    dirs = [a for a in argv if not a.startswith("--")] or ["templates"]
    total, touched = 0, 0
    for f in sorted(pathlib.Path(dirs[0]).glob("*.html")):
        original = f.read_text()
        new, n = process(original)
        if n and new != original:
            total += n
            touched += 1
            if apply:
                f.write_text(new)
    print(f"{'Rewrote' if apply else 'Would rewrite'} {touched} file(s), {total} block(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
