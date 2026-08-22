# The WordPress.org listing assets

The directory shows a banner above the plugin page and an icon in search results
and in a site's plugins list. Both are drawn as SVG here and exported by
Inkscape, so the artwork is edited in a drawing program and what Inkscape shows
is what ships.

```bash
composer run assets
```

Writes into `.wordpress-org/`. **Look at them before committing** — the script
checks that the files were written at the right size, not that they show what you
meant.

| File | |
|---|---|
| `icon.svg` | The icon. Open it in Inkscape. |
| `banner.svg` | The banner, drawn at 1544×500. Open it in Inkscape. Its coupon comes from `icon.svg`. |
| `dfx-logo.svg` | The maker's logo as drawn, wordmark and all. Nothing renders it; it is the source. |
| `dfx-mark.svg` | The same logo with the text removed — derived, see below. |
| `generate.sh` | Exports everything at the sizes the directory expects. |

## What gets published

| Output | Where it shows |
|---|---|
| `banner-772x250.png` | the top of the plugin page |
| `banner-1544x500.png` | the same, on a retina display |
| `icon-128x128.png` | search results, the plugins list |
| `icon-256x256.png` | the same, on a retina display |
| `icon.svg` | preferred over the PNGs wherever the directory can use it |

None of it ships inside the plugin. `.distignore` keeps `.wordpress-org` out of
the build, and wp.org reads these from `assets/` in SVN.

## Editing them

Open `icon.svg` or `banner.svg` in Inkscape, change what you like, save, and run
`composer run assets`. Each design is drawn once and exported at both sizes it
needs, so the retina asset cannot drift from the one beside it.

**The banner's coupon is the icon's coupon.** Those paths are lifted from
`icon.svg` rather than copied by hand, so redrawing the coupon means editing
`icon.svg` and re-lifting them — otherwise the two drift, and a listing whose
banner and icon disagree about the product looks like two products.

Two more things to know first:

**The banner's text is live text, and SVG does not wrap.** The tagline is two
separate lines because it has to be. Re-break them by hand if the wording
changes.

**The type is Inter.** If it is missing, Inkscape substitutes silently and the
export looks like a design decision rather than a fault.

## Where the mark comes from

`dfx-mark.svg` is `dfx-logo.svg` with its two text objects removed and the canvas
cropped to what is left. Derived rather than redrawn, and reproducibly:

```bash
inkscape bin/wporg-assets/dfx-logo.svg \
	--actions="select-by-id:g2,text2;delete;select-clear;export-area-drawing;export-plain-svg;export-filename:bin/wporg-assets/dfx-mark.svg;export-do"
```

`g2` is the name across the top; `text2` is "(DaveFX)" beneath it. Rasterised at
362px the result is pixel-identical to the "sin texto" PNG export, which is how
it was checked.

`icon.svg` carries that mark **inlined** rather than referencing it. It has to:
wp.org serves `icon.svg` on its own, and a file pointing at a sibling would
render with a hole in it. So after changing the logo, re-derive `dfx-mark.svg`
and paste it back into `icon.svg` — or the icon keeps the old mark.

## Things that were learned the hard way

**The icon belongs to a family.** It follows `random-user-ids` and
`dfx-parish-retreat-letters`: white ground, navy rounded frame broken at the top
left, the maker's mark in that break, navy line-work over pale blue. The palette
is sampled from those two icons rather than guessed — `#0c2e5c` for the line,
`#a7d1ee` and `#5e9ac6` for the fills.

**The icon is rendered at 32px in a plugins list.** It carries one idea and no
words: a coupon, the per cent sign that says what it is, and the exclamation this
plugin adds to it. Two marks that touch become one shape at that size, which is
why the exclamation sits alone in its own third of the ticket, and why both are
inset from the ticket's border — a glyph resting on a line reads as part of the
line.

**The counters in the per cent sign are the ticket's blue, not white.** White
reads as a hole punched through the coupon rather than as part of the glyph.

**This was all rendered by headless Chrome from HTML once.** Chrome would not
paint into a window as small as 128×128: it returned a tile of the background
colour, a few hundred bytes of nothing that looks like a working file until it is
opened. Inkscape has no such limit, and it is also where the artwork is edited,
so there is now one renderer rather than two that can disagree.
