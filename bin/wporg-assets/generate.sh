#!/usr/bin/env bash
#
# Render the WordPress.org listing assets.
#
# The directory shows a banner above the plugin page and an icon in search
# results and the plugins list. Both are drawn as SVG in this directory and
# exported here, so a change of wording or colour is a diff — and so that nobody
# has to find the person with the source file.
#
# The sources are plain SVG and open in Inkscape. That is the point of them: the
# artwork is edited in a drawing program, not in markup, and what Inkscape shows
# is what this exports, because Inkscape is what does the exporting.
#
# Each design is drawn once and exported twice. That is deliberate: two files,
# one per size, drift, and a retina asset that no longer matches the one beside
# it is worse than not shipping one.

set -euo pipefail

readonly ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"
readonly HERE="${ROOT}/bin/wporg-assets"
readonly OUT="${1:-${ROOT}/.wordpress-org}"

say() { printf '\033[0;34m==>\033[0m %s\n' "$1"; }
die() { printf '\033[0;31mError:\033[0m %s\n' "$1" >&2; exit 1; }

command -v inkscape >/dev/null 2>&1 || die 'Inkscape is not installed.'
[[ -d "${OUT}" ]] || die "Output directory ${OUT} does not exist."

# Export one SVG at an exact pixel size.
#
# Width and height are both given rather than a scale factor: the directory
# expects exact dimensions, and being a pixel out shows as a seam along an edge.
shoot() {
	local source="$1" target="$2" width="$3" height="$4"

	inkscape "${HERE}/${source}" \
		--export-type=png \
		--export-width="${width}" \
		--export-height="${height}" \
		--export-filename="${OUT}/${target}" \
		>/dev/null 2>&1

	[[ -s "${OUT}/${target}" ]] || die "${target} was not written."

	say "${target}  ${width}x${height}"
}

say 'Rendering the listing assets'

# wp.org serves the 1544 banner to retina displays and the 772 to everything
# else. Both are expected; only supplying one is a blurry page for half of it.
shoot banner.svg 'banner-772x250.png'   772  250
shoot banner.svg 'banner-1544x500.png' 1544  500

# 128 is what search results show; 256 is the retina pair.
shoot icon.svg 'icon-128x128.png' 128 128
shoot icon.svg 'icon-256x256.png' 256 256

# The directory takes a vector icon where there is one and falls back to the
# PNGs. It is served as-is, so it has to stand alone: icon.svg carries the
# maker's mark inlined rather than referencing dfx-mark.svg beside it.
cp "${HERE}/icon.svg" "${OUT}/icon.svg"
say 'icon.svg  vector'

say "Done. Look at them in ${OUT} before committing."
