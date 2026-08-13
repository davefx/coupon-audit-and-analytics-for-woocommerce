#!/usr/bin/env bash
#
# Render the WordPress.org listing assets.
#
# The directory shows a banner above the plugin page and an icon in search
# results and the plugins list. Both are built here from HTML rather than drawn
# in an editor, so a change of wording or colour is a diff rather than an
# afternoon and a file nobody can open.
#
# Each design is sized in viewport units and rendered twice, at the standard size
# and at twice it. That is why there is one banner file and not two: a retina
# asset that has drifted from the one beside it is worse than no retina asset.

set -euo pipefail

readonly REPO="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"
readonly HERE="${REPO}/bin/wporg-assets"
readonly OUT="${1:-${REPO}/.wordpress-org}"

CHROME="${CHROME:-/usr/bin/google-chrome}"

say() { printf '\033[0;34m==>\033[0m %s\n' "$1"; }
die() { printf '\033[0;31mError:\033[0m %s\n' "$1" >&2; exit 1; }

[[ -x "${CHROME}" ]] || die "No Chrome at ${CHROME}. Set CHROME to point at one."
[[ -d "${OUT}" ]] || die "Output directory ${OUT} does not exist."

PROFILE="$( mktemp -d "${TMPDIR:-/tmp}/dfxcaaw-assets-XXXXXX" )"
trap 'rm -rf "${PROFILE}"' EXIT

# Render one HTML file at an exact pixel size.
#
# --hide-scrollbars matters: a scrollbar would take pixels off the right edge and
# the asset would be silently the wrong width.
shoot() {
	local source="$1" target="$2" width="$3" height="$4"

	"${CHROME}" \
		--headless \
		--disable-gpu \
		--no-sandbox \
		--hide-scrollbars \
		--force-device-scale-factor=1 \
		--user-data-dir="${PROFILE}" \
		--window-size="${width},${height}" \
		--screenshot="${OUT}/${target}" \
		"file://${source}" >/dev/null 2>&1

	[[ -s "${OUT}/${target}" ]] || die "${target} was not written."

	say "${target}  ${width}x${height}"
}

# Reduce a rendered asset to a smaller pixel size.
#
# @param 1 source file, 2 target file, 3 edge length.
reduce() {
	local source="${OUT}/$1" target="${OUT}/$2" size="$3"

	# One line on purpose: a tab-stripping heredoc and Python's indentation do not
	# get along, and the failure is a syntax error rather than a bad image.
	python3 -c 'import sys; from PIL import Image; Image.open( sys.argv[1] ).convert( "RGBA" ).resize( ( int( sys.argv[3] ), ) * 2, Image.LANCZOS ).save( sys.argv[2] )' \
		"${source}" "${target}" "${size}"

	[[ -s "${target}" ]] || die "$2 was not written."

	say "$2  ${size}x${size}"
}

say 'Rendering the listing assets'

# wp.org serves the 1544 banner to retina displays and the 772 to everything
# else. Both are expected; only supplying one is a blurry page for half of it.
shoot "${HERE}/banner.html" 'banner-772x250.png'   772  250
shoot "${HERE}/banner.html" 'banner-1544x500.png' 1544  500

# 128 is what search results show. 256 is the retina pair.
#
# The small one is reduced from the large one rather than rendered at 128:
# headless Chrome will not paint into a window that small and hands back a blank
# tile of the background colour — which is 362 bytes of nothing, and looks like a
# working file until somebody opens it. Reducing also gives a better result than
# hinting the strokes at 128 would.
shoot "${HERE}/icon.html" 'icon-256x256.png' 256 256
reduce 'icon-256x256.png' 'icon-128x128.png' 128

say "Done. Look at them in ${OUT} before committing."
