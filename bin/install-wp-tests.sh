#!/usr/bin/env bash
#
# Build the environment the integration suite needs: a WordPress install, the
# WordPress core test library, a database, and WooCommerce.
#
# A developer with a working setup never has to run this — tests/bootstrap-integration.php
# reads WP_TESTS_DIR, WP_CORE_DIR and WC_PLUGIN_DIR from the environment and will
# happily use one that already exists. This script is what CI runs, and what a
# fresh machine runs once.
#
# usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [wc-version]

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [wc-version]" >&2
	exit 1
fi

DB_NAME="$1"
DB_USER="$2"
DB_PASS="$3"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"
WC_VERSION="${6:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WC_PLUGIN_DIR="${WC_PLUGIN_DIR:-$WP_CORE_DIR/wp-content/plugins/woocommerce}"

TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT

log() { printf '\033[0;34m==>\033[0m %s\n' "$1"; }

# ---------------------------------------------------------------------------
# Resolve the WordPress version.
# ---------------------------------------------------------------------------

if [ "$WP_VERSION" = "latest" ]; then
	log "Resolving the latest WordPress release"

	# Parsed with a JSON parser rather than a regex. The version-check response
	# lists an offer per supported branch, all of them carrying a "current" key,
	# and a greedy pattern happily returns the oldest one — which is how this
	# once installed WordPress 4.7 and failed several steps later with a PHPUnit
	# error that said nothing about versions.
	WP_VERSION="$(
		curl -fsSL https://api.wordpress.org/core/version-check/1.7/ |
			php -r '$d = json_decode( stream_get_contents( STDIN ), true ); echo $d["offers"][0]["current"] ?? "";'
	)"
	[ -n "$WP_VERSION" ] || { echo "Could not resolve the latest WordPress version." >&2; exit 1; }
fi

if [ "$WP_VERSION" = "rc" ]; then
	log "Resolving the current WordPress release candidate"

	# The beta channel offers the release under development, when there is one.
	# Between releases it answers with the current stable instead, which is a
	# fine thing to test against and not worth failing over.
	WP_VERSION="$(
		curl -fsSL "https://api.wordpress.org/core/version-check/1.7/?channel=beta" |
			php -r '$d = json_decode( stream_get_contents( STDIN ), true ); echo $d["offers"][0]["current"] ?? "";'
	)"
	[ -n "$WP_VERSION" ] || { echo "Could not resolve the WordPress release candidate." >&2; exit 1; }
fi

# The plugin requires WordPress 6.4. An older test library will fail in ways
# that point at PHPUnit rather than at the version, so refuse it here.
WP_MAJOR_MINOR="$( printf '%s' "$WP_VERSION" | cut -d. -f1,2 )"
if [ "$( printf '%s\n6.4\n' "$WP_MAJOR_MINOR" | sort -V | head -1 )" != "6.4" ]; then
	echo "This plugin requires WordPress 6.4 or newer; refusing to install $WP_VERSION." >&2
	exit 1
fi

log "WordPress $WP_VERSION"

# ---------------------------------------------------------------------------
# WordPress core.
# ---------------------------------------------------------------------------

if [ ! -f "$WP_CORE_DIR/wp-settings.php" ]; then
	log "Installing WordPress into $WP_CORE_DIR"
	mkdir -p "$WP_CORE_DIR"
	curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" -o "$TMPDIR/wordpress.tar.gz"
	tar --strip-components=1 -zxf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
else
	log "WordPress already present at $WP_CORE_DIR"
fi

# ---------------------------------------------------------------------------
# The core test library.
#
# Taken from the wordpress-develop tarball rather than over Subversion: svn is
# no longer guaranteed to be present on CI runners, and a tarball is one fetch.
# ---------------------------------------------------------------------------

if [ ! -f "$WP_TESTS_DIR/includes/bootstrap.php" ]; then
	log "Installing the WordPress test library into $WP_TESTS_DIR"
	mkdir -p "$WP_TESTS_DIR"

	# wordpress-develop tags final releases only. A release candidate — which is
	# when testing against the next version actually matters — lives on the branch
	# for its major.minor, so 7.1-RC3 is fetched from the "7.1" branch. The tag is
	# tried first so a final release still comes from an immutable ref.
	WP_DEVELOP_BRANCH="$( printf '%s' "$WP_VERSION" | cut -d- -f1 | cut -d. -f1,2 )"

	if ! curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz" \
		-o "$TMPDIR/wordpress-develop.tar.gz"
	then
		log "No tag ${WP_VERSION}; taking the test library from branch ${WP_DEVELOP_BRANCH}"
		curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/heads/${WP_DEVELOP_BRANCH}.tar.gz" \
			-o "$TMPDIR/wordpress-develop.tar.gz"
	fi
	mkdir -p "$TMPDIR/develop"
	tar --strip-components=1 -zxf "$TMPDIR/wordpress-develop.tar.gz" -C "$TMPDIR/develop"

	cp -r "$TMPDIR/develop/tests/phpunit/includes" "$WP_TESTS_DIR/"
	cp -r "$TMPDIR/develop/tests/phpunit/data" "$WP_TESTS_DIR/"
	cp "$TMPDIR/develop/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
else
	log "Test library already present at $WP_TESTS_DIR"
fi

# ---------------------------------------------------------------------------
# Test configuration. Rewritten every run, so a changed password takes effect.
# ---------------------------------------------------------------------------

log "Writing $WP_TESTS_DIR/wp-tests-config.php"

CONFIG="$WP_TESTS_DIR/wp-tests-config.php"
ABSPATH_VALUE="$WP_CORE_DIR/"

php -r '
$file = $argv[1];
$config = file_get_contents( $file );
$replacements = array(
	"youremptytestdbnamehere" => $argv[2],
	"yourusernamehere"        => $argv[3],
	"yourpasswordhere"        => $argv[4],
	"localhost"               => $argv[5],
);
foreach ( $replacements as $needle => $value ) {
	$config = str_replace( $needle, $value, $config );
}
$config = preg_replace(
	"#define\(\s*.ABSPATH.,.*?\);#s",
	sprintf( "define( \x27ABSPATH\x27, \x27%s\x27 );", $argv[6] ),
	$config
);
file_put_contents( $file, $config );
' "$CONFIG" "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$ABSPATH_VALUE"

# ---------------------------------------------------------------------------
# Database.
# ---------------------------------------------------------------------------

log "Creating the database $DB_NAME if it does not exist"

MYSQL_HOST="${DB_HOST%%:*}"
MYSQL_PORT="${DB_HOST#*:}"
MYSQL_ARGS=( --user="$DB_USER" --password="$DB_PASS" --host="$MYSQL_HOST" )
if [ "$MYSQL_PORT" != "$DB_HOST" ]; then
	MYSQL_ARGS+=( --port="$MYSQL_PORT" --protocol=TCP )
fi

mysqladmin "${MYSQL_ARGS[@]}" create "$DB_NAME" 2>/dev/null && log "Database created" || log "Database already exists"

# ---------------------------------------------------------------------------
# WooCommerce. The plugin cannot boot without it, so an integration suite
# lacking it would be a green suite that tested nothing.
# ---------------------------------------------------------------------------

if [ ! -f "$WC_PLUGIN_DIR/woocommerce.php" ]; then
	log "Installing WooCommerce into $WC_PLUGIN_DIR"
	mkdir -p "$(dirname "$WC_PLUGIN_DIR")"

	if [ "$WC_VERSION" = "latest" ]; then
		# Resolved through the plugin API rather than by fetching the unversioned
		# woocommerce.zip, which serves whatever is in the repository right now —
		# that has handed back a release candidate, and CI going red on upstream's
		# release schedule is not a signal about this plugin.
		log "Resolving the latest stable WooCommerce release"
		WC_VERSION="$(
			curl -fsSL 'https://api.wordpress.org/plugins/info/1.0/woocommerce.json' |
				php -r '$d = json_decode( stream_get_contents( STDIN ), true ); echo $d["version"] ?? "";'
		)"
		[ -n "$WC_VERSION" ] || { echo "Could not resolve the latest WooCommerce version." >&2; exit 1; }
	fi

	WC_MAJOR_MINOR="$( printf '%s' "$WC_VERSION" | cut -d. -f1,2 )"
	if [ "$( printf '%s\n10.3\n' "$WC_MAJOR_MINOR" | sort -V | head -1 )" != "10.3" ]; then
		echo "This plugin requires WooCommerce 10.3 or newer; refusing to install $WC_VERSION." >&2
		exit 1
	fi

	curl -fsSL "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip" -o "$TMPDIR/woocommerce.zip"
	unzip -qo "$TMPDIR/woocommerce.zip" -d "$(dirname "$WC_PLUGIN_DIR")"
else
	log "WooCommerce already present at $WC_PLUGIN_DIR"
fi

WC_INSTALLED_VERSION="$(sed -n 's/^ \* Version: *//p' "$WC_PLUGIN_DIR/woocommerce.php" | head -1)"
log "WooCommerce ${WC_INSTALLED_VERSION:-unknown}"

log "Done. Run: composer run test:integration"
