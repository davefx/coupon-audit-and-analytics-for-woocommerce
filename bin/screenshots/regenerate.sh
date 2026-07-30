#!/usr/bin/env bash
#
# Rebuild the four readme screenshots from nothing.
#
# The pictures go stale — a changed column, a reworded notice, a new finding —
# and a stale screenshot is worse than none: it advertises behaviour the plugin
# no longer has. This exists so that taking them again is one command, because a
# fifteen-step recipe is a recipe nobody follows.
#
# It touches no existing site. A disposable WordPress is installed against a
# throwaway MySQL container, seeded with a shop that exercises every finding and
# all three cost-coverage states, and photographed. Everything it creates, it
# removes again, including when it fails partway.
#
# The plugin under test is the *built distribution*, not the working tree: the
# pictures should show what somebody installs.

set -euo pipefail

readonly CONTAINER='dfxcaaw-shots'
readonly DB_PORT=3308
readonly HTTP_PORT=8088
readonly OUT="${1:-.wordpress-org}"

readonly REPO="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../.." && pwd )"
readonly SLUG='coupon-audit-and-analytics-for-woocommerce'

# Somewhere disposable, and outside the repository. TMPDIR so that a sandbox with
# its own scratch directory is honoured rather than worked around.
WP="$( mktemp -d "${TMPDIR:-/tmp}/dfxcaaw-shots-XXXXXX" )"
readonly WP

SERVER_PID=''

say() { printf '\033[0;34m==>\033[0m %s\n' "$1"; }
die() { printf '\033[0;31mError:\033[0m %s\n' "$1" >&2; exit 1; }

# Everything this script created, whether it finished or not. A left-behind
# container holds port 3308 and the next run fails for a reason that has nothing
# to do with screenshots.
cleanup() {
	local status=$?

	# The whole process group, not just the pid we started. `wp server` is a
	# wrapper around `php -S`, and killing the wrapper leaves the PHP process
	# holding the port — so the next run finds it occupied and fails to serve the
	# site it just built, which is a confusing way to learn this.
	if [[ -n "${SERVER_PID}" ]]; then
		kill -- -"${SERVER_PID}" 2>/dev/null || kill "${SERVER_PID}" 2>/dev/null || true
		wait "${SERVER_PID}" 2>/dev/null || true
	fi

	docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true
	rm -rf "${WP}"

	# Installed with --no-save, so there is no manifest to clean up — but the
	# directory is ours only if we were the ones who created it.
	if [[ -n "${PUPPETEER_INSTALLED:-}" ]]; then
		rm -rf "${REPO}/node_modules"
	fi

	if (( status != 0 )); then
		printf '\033[0;31mFailed.\033[0m The demo shop and container were removed.\n' >&2
	fi

	return "${status}"
}
trap cleanup EXIT

for tool in docker wp node npm; do
	command -v "${tool}" >/dev/null 2>&1 || die "${tool} is not installed."
done

# Exported rather than passed per command: the shoot scripts read it from the
# environment, and it cannot be readonly if it is also to be a command prefix.
CHROME="${CHROME:-/usr/bin/google-chrome}"
export CHROME
[[ -x "${CHROME}" ]] || die "No Chrome at ${CHROME}. Set CHROME to point at one."

[[ -d "${REPO}/${OUT}" ]] || die "Output directory ${OUT} does not exist."

say 'Building the distribution to photograph'
bash "${REPO}/bin/build-dist.sh" >/dev/null
[[ -d "${REPO}/build/${SLUG}" ]] || die 'The build produced no plugin directory.'

say "Starting a throwaway MySQL on ${DB_PORT}"
docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true
docker run -d --name "${CONTAINER}" \
	-e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=shots \
	-p "${DB_PORT}:3306" mysql:8.0 >/dev/null

# Waiting on a real query rather than on mysqladmin ping. The image answers a
# ping while it is still initialising, and WordPress then connects to a server
# that closes the socket mid-greeting — which surfaces as "MySQL server has gone
# away" and looks like anything except a container that is not ready yet.
mysql_ready() {
	docker exec "${CONTAINER}" mysql -uroot -proot -e 'SELECT 1' shots >/dev/null 2>&1 \
		&& ( exec 3<>"/dev/tcp/127.0.0.1/${DB_PORT}" ) >/dev/null 2>&1
}

for _ in $( seq 1 90 ); do
	if mysql_ready; then
		break
	fi
	sleep 1
done
mysql_ready || die "MySQL never came up. Try: docker logs ${CONTAINER}"

say 'Installing WordPress and WooCommerce'
wp --path="${WP}" core download --quiet
wp --path="${WP}" config create --quiet --force \
	--dbname=shots --dbuser=root --dbpass=root --dbhost="127.0.0.1:${DB_PORT}"
wp --path="${WP}" core install --quiet \
	--url="http://127.0.0.1:${HTTP_PORT}" --title='Demo Shop' \
	--admin_user=demo --admin_password=demo --admin_email=demo@example.test --skip-email
wp --path="${WP}" plugin install woocommerce --activate --quiet

wp --path="${WP}" option update woocommerce_currency EUR --quiet
# Off by default in core, and the margin screenshot needs cost data.
wp --path="${WP}" option update woocommerce_feature_cost_of_goods_sold_enabled yes --quiet

cp -r "${REPO}/build/${SLUG}" "${WP}/wp-content/plugins/"
wp --path="${WP}" plugin activate "${SLUG}" --quiet

say 'Seeding a shop with something to find'
# Quietly: WordPress tries to send mail on install and the failures are noise.
wp --path="${WP}" eval-file "${REPO}/bin/screenshots/seed.php" 2>/dev/null
wp --path="${WP}" eval-file "${REPO}/bin/screenshots/seed2.php" 2>/dev/null

say "Serving it on ${HTTP_PORT}"

# Refuse rather than fail obscurely: if something already holds the port, the
# screenshots would be taken of whatever that is.
if ( exec 3<>"/dev/tcp/127.0.0.1/${HTTP_PORT}" ) >/dev/null 2>&1; then
	die "Something is already listening on ${HTTP_PORT}."
fi

# setsid so the server leads its own process group and cleanup can take the whole
# group down, `php -S` child included.
setsid wp --path="${WP}" server --host=127.0.0.1 --port="${HTTP_PORT}" >"${WP}/server.log" 2>&1 &
SERVER_PID=$!

for _ in $( seq 1 30 ); do
	if curl -sf -o /dev/null "http://127.0.0.1:${HTTP_PORT}/wp-login.php"; then
		break
	fi
	sleep 1
done
if ! curl -sf -o /dev/null "http://127.0.0.1:${HTTP_PORT}/wp-login.php"; then
	# Printed rather than pointed at: cleanup is about to delete the file, and
	# telling somebody to go and read a path that no longer exists is worse than
	# saying nothing.
	printf -- '--- server log ---\n' >&2
	tail -20 "${WP}/server.log" >&2 || true
	die "Nothing answered on ${HTTP_PORT}."
fi

if [[ ! -d "${REPO}/node_modules/puppeteer-core" ]]; then
	say 'Installing puppeteer-core'
	# --no-save: this repository has neither a package.json nor a lock file, and
	# a screenshot run is no reason to give it one.
	( cd "${REPO}" && npm install --no-save --no-package-lock --silent puppeteer-core )
	PUPPETEER_INSTALLED=1
fi

say 'Taking the pictures'
( cd "${REPO}" && node bin/screenshots/shoot.js "${OUT}" )
( cd "${REPO}" && node bin/screenshots/shoot3.js "${OUT}" )

for n in 1 2 3 4; do
	[[ -s "${REPO}/${OUT}/screenshot-${n}.png" ]] || die "screenshot-${n}.png was not written."
done

say "Done. Four screenshots in ${OUT} — look at them before committing."
