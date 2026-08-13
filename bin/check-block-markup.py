#!/usr/bin/env python3
"""
Validates hand-authored block markup.

Block templates are .html files that WordPress parses as blocks, and the editor
compares what is in the file against what Gutenberg would have written. Anything
it can't reconcile becomes "Block contains unexpected or invalid content" — a
warning box in place of the block, with an "Attempt recovery" button that
rewrites your file.

The failure mode is almost always mechanical: a container opened and never
closed, a block comment without its pair, a self-closing block given a closer.
Those are all checkable without WordPress, so they are checked here and run in
CI rather than discovered in the Site Editor.

Usage:  check-block-markup.py <dir> [<dir> …]
"""

import pathlib
import re
import sys

# Tags that must balance. Void elements and anything self-closed are skipped.
CONTAINERS = ("div", "main", "header", "footer", "nav", "section", "article",
              "aside", "figure", "ul", "ol", "li", "p", "h1", "h2", "h3", "blockquote")

BLOCK_OPEN = re.compile(r"<!--\s+wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)(\s+\{.*?\})?\s+(/)?-->", re.S)
BLOCK_CLOSE = re.compile(r"<!--\s+/wp:([a-z0-9-]+(?:/[a-z0-9-]+)?)\s+-->")


def check_blocks(text):
    """Block comments must nest like brackets; self-closing ones take no closer."""
    problems, stack = [], []
    for m in re.finditer(r"<!--\s+/?wp:.*?-->", text, re.S):
        token = m.group(0)
        line = text[: m.start()].count("\n") + 1
        close = BLOCK_CLOSE.match(token)
        if close:
            if not stack:
                problems.append((line, f"closing <!-- /wp:{close.group(1)} --> with nothing open"))
            elif stack[-1][0] != close.group(1):
                problems.append((line, f"closing <!-- /wp:{close.group(1)} --> but <!-- wp:{stack[-1][0]} --> is open (line {stack[-1][1]})"))
                stack.pop()
            else:
                stack.pop()
            continue
        opened = BLOCK_OPEN.match(token)
        if opened and not opened.group(3):          # group(3) is the "/" of a self-closing block
            stack.append((opened.group(1), line))
    for name, line in stack:
        problems.append((line, f"<!-- wp:{name} --> is never closed"))
    return problems


def check_tags(text):
    """Container tags must balance — the missing </div> case."""
    problems, stack = [], []
    pattern = re.compile(r"<(/?)(" + "|".join(CONTAINERS) + r")(\s[^>]*?)?(/?)>", re.I)
    for m in pattern.finditer(text):
        closing, tag, _attrs, selfclosed = m.groups()
        if selfclosed:
            continue
        line = text[: m.start()].count("\n") + 1
        if closing:
            if not stack:
                problems.append((line, f"</{tag}> with nothing open"))
            elif stack[-1][0].lower() != tag.lower():
                problems.append((line, f"</{tag}> but <{stack[-1][0]}> is open (line {stack[-1][1]})"))
                stack.pop()
            else:
                stack.pop()
        else:
            stack.append((tag, line))
    for tag, line in stack:
        problems.append((line, f"<{tag}> is never closed"))
    return problems


def main(dirs):
    files, failures = [], 0
    for d in dirs:
        files.extend(sorted(pathlib.Path(d).rglob("*.html")))
    if not files:
        print(f"No .html found in {', '.join(dirs)}")
        return 1
    for f in files:
        text = f.read_text()
        problems = sorted(check_blocks(text) + check_tags(text))
        if problems:
            failures += 1
            print(f"\n{f}")
            for line, msg in problems:
                print(f"  line {line}: {msg}")
    print(f"\n{len(files)} file(s) checked, {failures} with problems")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:] or ["templates"]))
