#!/usr/bin/env bash
#
# Publish a release to the WordPress.org plugin directory.
#
# usage: bin/deploy-wporg.sh [--commit]
#
# Prepares everything and shows what would change. It commits nothing without
# --commit, because SVN is a release system rather than a place to try things:
# what lands in trunk is what a shop installs, immediately, and there is no
# rewriting it afterwards.
#
# What goes where:
#
#   trunk/   the plugin as built for distribution — the same tree that goes in
#            the zip, so what wp.org serves and what is attached to a GitHub
#            release cannot disagree.
#   tags/X/  a copy of trunk, made once per release and never touched again.
#   assets/  the banner, icon and screenshots. These live outside trunk and are
#            NOT part of what a shop downloads, which is why .distignore keeps
#            .wordpress-org out of the build.
#
# Credentials are never stored or passed on a command line. SVN asks, or takes
# SVN_USERNAME from the environment and asks for the password.

set -euo pipefail

readonly SLUG='coupon-audit-and-analytics-for-woocommerce'
readonly SVN_URL="https://plugins.svn.wordpress.org/${SLUG}"

readonly ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
readonly BUILD="${ROOT}/build"
readonly WC="${BUILD}/svn"
readonly ASSETS="${ROOT}/.wordpress-org"

COMMIT='no'
[[ "${1:-}" == '--commit' ]] && COMMIT='yes'

say()  { printf '\033[0;34m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[0;33m==>\033[0m %s\n' "$1"; }
die()  { printf '\033[0;31mError:\033[0m %s\n' "$1" >&2; exit 1; }

command -v svn >/dev/null 2>&1 || die 'svn is not installed.'
command -v rsync >/dev/null 2>&1 || die 'rsync is not installed.'

# ---------------------------------------------------------------------------
# The version, from the one place that decides it.
# ---------------------------------------------------------------------------

VERSION="$( sed -n 's/^ \* Version: *\([0-9][^ ]*\).*/\1/p' "${ROOT}/${SLUG}.php" | head -1 )"
[[ -n "${VERSION}" ]] || die "No Version header found in ${SLUG}.php."

# build-dist.sh refuses to run when the header and the readme's Stable tag
# disagree, so building here is also the check that they agree.
say "Building ${SLUG} ${VERSION}"
bash "${ROOT}/bin/build-dist.sh" >/dev/null
[[ -d "${BUILD}/${SLUG}" ]] || die 'The build produced no plugin directory.'

# ---------------------------------------------------------------------------
# The working copy.
# ---------------------------------------------------------------------------

if [[ -d "${WC}/.svn" ]]; then
	say 'Updating the existing working copy'
	svn update --quiet "${WC}"
else
	say "Checking out ${SVN_URL}"
	# Only the top level to begin with: tags/ holds every past release and
	# fetching all of them to publish one is a slow way to achieve nothing.
	svn checkout --quiet --depth immediates "${SVN_URL}" "${WC}"
	svn update --quiet --set-depth infinity "${WC}/trunk" "${WC}/assets"
fi

[[ -d "${WC}/trunk" ]] || die 'The repository has no trunk; is the slug right?'

if svn info "${SVN_URL}/tags/${VERSION}" >/dev/null 2>&1; then
	die "tags/${VERSION} already exists. A released tag is never rewritten — bump the version."
fi

# ---------------------------------------------------------------------------
# trunk, and the listing assets.
# ---------------------------------------------------------------------------

say 'Syncing trunk'
rsync -a --delete --exclude '.svn' "${BUILD}/${SLUG}/" "${WC}/trunk/"

if [[ -d "${ASSETS}" ]]; then
	say 'Syncing assets'
	mkdir -p "${WC}/assets"
	rsync -a --delete --exclude '.svn' \
		--include 'banner-*.png' --include 'icon-*.png' --include 'screenshot-*.png' \
		--exclude '*' "${ASSETS}/" "${WC}/assets/"
else
	warn "No ${ASSETS}; the listing will have no banner, icon or screenshots."
fi

# ---------------------------------------------------------------------------
# Tell SVN what changed. It does not notice on its own.
# ---------------------------------------------------------------------------

say 'Staging additions and deletions'

# Added: everything SVN does not know about. Removed: everything it knows about
# that is no longer there. Without both, a file dropped from the plugin stays in
# trunk for ever and keeps being shipped.
( cd "${WC}" && svn status | awk '/^\?/ { print $2 }' | xargs -r svn add --quiet --parents )
( cd "${WC}" && svn status | awk '/^!/  { print $2 }' | xargs -r svn delete --quiet )

say "Creating tags/${VERSION}"
svn copy --quiet "${WC}/trunk" "${WC}/tags/${VERSION}"

# ---------------------------------------------------------------------------
# What would happen.
# ---------------------------------------------------------------------------

printf '\n'
say 'Pending changes'
( cd "${WC}" && svn status | sed 's/^/    /' | head -40 )

CHANGES="$( cd "${WC}" && svn status | wc -l )"
printf '\n'
say "${CHANGES} paths to commit"

if [[ "${COMMIT}" != 'yes' ]]; then
	printf '\n'
	warn 'Nothing was committed. Re-run with --commit to publish.'
	warn "Working copy left at ${WC} if you would rather commit by hand."
	exit 0
fi

# ---------------------------------------------------------------------------
# Publish.
# ---------------------------------------------------------------------------

say "Committing ${VERSION} to the plugin directory"

SVN_ARGS=()
[[ -n "${SVN_USERNAME:-}" ]] && SVN_ARGS+=( --username "${SVN_USERNAME}" )

( cd "${WC}" && svn commit "${SVN_ARGS[@]}" -m "Release ${VERSION}" )

say "Done. https://wordpress.org/plugins/${SLUG} updates within a few minutes."
