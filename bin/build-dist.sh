#!/usr/bin/env bash
#
# Build the zip that goes to WordPress.org.
#
# Two things make this necessary rather than a convenience. The plugin's
# dependencies must be installed without the development ones, or the shipped
# package carries PHPUnit and PHPStan. And everything in .distignore has to go,
# or Plugin Check reports the test harness as an application bundled inside a
# plugin.

set -euo pipefail

SLUG="coupon-audit-and-analytics-for-woocommerce"
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
BUILD="${ROOT}/build"
TARGET="${BUILD}/${SLUG}"

log() { printf '\033[0;34m==>\033[0m %s\n' "$1"; }
die() { printf '\033[0;31mError:\033[0m %s\n' "$1" >&2; exit 1; }

# ---------------------------------------------------------------------------
# The version comes from the plugin header, and the readme has to agree.
#
# WordPress serves whatever "Stable tag" points at, so a release where the two
# disagree publishes one version's code under another version's number — and
# does it silently, which is the worst way for that to happen. Checking here
# costs nothing and the alternative is finding out from a user.
# ---------------------------------------------------------------------------

VERSION="$( sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "${ROOT}/${SLUG}.php" | head -1 )"
STABLE="$( sed -n 's/^Stable tag: *\([0-9][^ ]*\).*/\1/p' "${ROOT}/readme.txt" | head -1 )"

[ -n "$VERSION" ] || die "No Version header found in ${SLUG}.php."
[ -n "$STABLE" ]  || die "No Stable tag found in readme.txt."

if [ "$VERSION" != "$STABLE" ]; then
	die "Version header is ${VERSION} but readme.txt Stable tag is ${STABLE}; they must match."
fi

ZIP="${BUILD}/${SLUG}-${VERSION}.zip"

log "Building ${SLUG} ${VERSION}"

rm -rf "$BUILD"
mkdir -p "$TARGET"

log "Copying the plugin"
rsync -a --exclude-from="${ROOT}/.distignore" --exclude '/vendor' --exclude '/build' "${ROOT}/" "${TARGET}/"

log "Installing runtime dependencies only"
composer install --no-dev --no-interaction --optimize-autoloader --working-dir="$ROOT" --quiet
rsync -a "${ROOT}/vendor/" "${TARGET}/vendor/"

# Composer leaves an empty vendor/bin behind when a --no-dev install has no
# binaries to link — sometimes, which is the awkward part: an empty directory in
# a shipped plugin is merely pointless, but one that comes and goes makes every
# SVN deploy differ from the last for no reason anybody can see. rmdir rather
# than rm -rf, so that a real binary would survive this.
rmdir "${TARGET}/vendor/bin" 2>/dev/null || true

log "Restoring the development dependencies"
composer install --no-interaction --working-dir="$ROOT" --quiet

log "Zipping"
( cd "$BUILD" && zip -qr "$( basename "$ZIP" )" "$SLUG" )

log "Built ${ZIP}"

# The zip is named for the version; the tag should carry the same number, so
# that a downloaded package can always be traced back to the commit that made it.
if ! git -C "$ROOT" rev-parse "refs/tags/${VERSION}" >/dev/null 2>&1; then
	log "Not tagged yet — git tag -a ${VERSION} -m 'Release ${VERSION}' && git push origin ${VERSION}"
fi
