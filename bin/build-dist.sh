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

rm -rf "$BUILD"
mkdir -p "$TARGET"

log "Copying the plugin"
rsync -a --exclude-from="${ROOT}/.distignore" --exclude '/vendor' --exclude '/build' "${ROOT}/" "${TARGET}/"

log "Installing runtime dependencies only"
composer install --no-dev --no-interaction --optimize-autoloader --working-dir="$ROOT" --quiet
rsync -a "${ROOT}/vendor/" "${TARGET}/vendor/"

log "Restoring the development dependencies"
composer install --no-interaction --working-dir="$ROOT" --quiet

log "Zipping"
( cd "$BUILD" && zip -qr "${SLUG}.zip" "$SLUG" )

log "Built ${BUILD}/${SLUG}.zip"
