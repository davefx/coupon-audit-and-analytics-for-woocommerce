# The WordPress.org listing assets

The directory shows a banner above the plugin page and an icon in search results
and in a site's plugins list. Both are built from HTML here rather than drawn in
an editor, so changing the wording or the colour is a diff — and so that nobody
has to find the person with the source file.

```bash
composer run assets
```

Writes four PNGs into `.wordpress-org/`. **Look at them before committing.** They
are not shipped in the plugin zip; `.distignore` keeps `.wordpress-org` out of
the build, and wp.org reads them from the `assets/` directory in SVN.

| File | Where it shows |
|---|---|
| `banner-772x250.png` | the top of the plugin page |
| `banner-1544x500.png` | the same, on a retina display |
| `icon-128x128.png` | search results, the plugins list |
| `icon-256x256.png` | the same, on a retina display |

## How it is put together

Each design is one HTML file sized entirely in viewport units, rendered twice at
different window sizes. That is deliberate: two files, one per size, drift, and a
retina asset that no longer matches the one beside it is worse than not shipping
one.

The palette is the plugin's own — WordPress slate `#1d2327`, the amber `#dba617`
that the audit already uses for a warning. The icon carries one idea and no
words, because it is rendered at 32px in places: a notched ticket, a per cent
sign to say what it is, and the exclamation this plugin adds to it.

## Two things that will waste an afternoon

**Headless Chrome will not paint into a window as small as 128×128.** It returns
a tile of the background colour, which is a few hundred bytes of nothing and
looks like a working file until it is opened. The small icon is reduced from the
256 rather than rendered — which also gives cleaner strokes than hinting at that
size would.

**A percentage height resolves short of the viewport here.** The banner's wash
stopped in a visible seam 56 pixels from the bottom while the flat colour beneath
it reached the edge, because a body background propagates to the canvas but a
gradient is painted only over the element. Both dimensions are in `vw`/`vh` now.
If a band ever reappears along an edge, that is where to look.
