# shellcheck shell=bash
# Shared helpers for the rads-ojsdemo scripts. Source it, do not run it.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# php_version_id BINARY — prints e.g. 80312, or nothing if it cannot run
php_version_id() {
	"$1" -r 'echo PHP_VERSION_ID;' 2>/dev/null || true
}

# find_php — choose a PHP CLI >= 8.2 (OJS 3.5 requirement). Shared hosting
# often has an old /usr/bin/php plus versioned binaries (php8.2, php8.3 …).
find_php() {
	local candidate best='' best_id=0 id
	if [ -n "${PHP:-}" ]; then
		return 0
	fi
	for candidate in php php8.5 php8.4 php8.3 php8.2 \
		/usr/local/bin/php8* /usr/bin/php8* /opt/php8*/bin/php /opt/alt/php8*/usr/bin/php \
		/usr/local/php8*/bin/php /usr/local/php/8*/bin/php /opt/php/8*/bin/php; do
		command -v "$candidate" >/dev/null 2>&1 || [ -x "$candidate" ] || continue
		id="$(php_version_id "$candidate")"
		case "$id" in ''|*[!0-9]*) continue ;; esac
		if [ "$id" -gt "$best_id" ]; then best="$candidate"; best_id="$id"; fi
	done
	PHP="${best:-php}"
}
find_php

if [ -t 1 ]; then
	C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else
	C_RED=''; C_GREEN=''; C_YELLOW=''; C_BOLD=''; C_RESET=''
fi

info()  { printf '%s\n' "$*"; }
step()  { printf '\n%s» %s%s\n' "$C_BOLD" "$*" "$C_RESET"; }
ok()    { printf '  %s✔%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn()  { printf '  %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$*"; }
fail()  { printf '  %s✘%s %s\n' "$C_RED" "$C_RESET" "$*"; }
die()   { printf '%sERROR:%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; exit 1; }

is_ojs_dir() {
	[ -f "$1/config.inc.php" ] && [ -f "$1/lib/pkp/classes/core/Core.php" ]
}

# require_ojs DIR — validate an OJS installation directory, sets OJS_DIR
# (DIR may also be the site directory that contains public_html/)
require_ojs() {
	[ -n "${1:-}" ] || die "Path to the OJS installation is required."
	OJS_DIR="$(cd "$1" 2>/dev/null && pwd)" || die "Directory not found: $1"
	if ! is_ojs_dir "$OJS_DIR"; then
		# Hosting panels (sweb, ISPmanager, cPanel…) keep the site in a subdirectory
		local candidate found=''
		for candidate in "$OJS_DIR"/public_html "$OJS_DIR"/www "$OJS_DIR"/htdocs "$OJS_DIR"/*/ "$OJS_DIR"/*/*/; do
			candidate="${candidate%/}"
			if is_ojs_dir "$candidate"; then found="$candidate"; break; fi
		done
		if [ -z "$found" ]; then
			printf 'Contents of %s:\n' "$OJS_DIR" >&2
			ls -la "$OJS_DIR" >&2 || true
			die "$OJS_DIR does not look like an OJS installation (no config.inc.php + lib/pkp found up to two levels deep). Pass the directory that contains config.inc.php."
		fi
		info "OJS found in $found"
		OJS_DIR="$found"
	fi
	command -v "$PHP" >/dev/null 2>&1 || [ -x "$PHP" ] || die "PHP CLI not found (set PHP=/path/to/php)."
	local id; id="$(php_version_id "$PHP")"
	if [ -z "$id" ] || [ "$id" -lt 80200 ]; then
		die "PHP CLI '$PHP' is $("$PHP" -v 2>/dev/null | head -1 | cut -d' ' -f2) — OJS 3.5 needs PHP 8.2+. No newer binary was found automatically; run with PHP=/path/to/php8.x (ask the hosting which path matches the site's PHP version, on sweb e.g. ls /usr/local/bin/php*)."
	fi
	export OJS_DIR PHP
}

# cfg SECTION KEY — read a value from config.inc.php
cfg() {
	"$PHP" -r '
		$c = @parse_ini_file($argv[1], true, INI_SCANNER_RAW);
		$v = $c[$argv[2]][$argv[3]] ?? "";
		echo trim($v, "\"'"'"'");
	' "$OJS_DIR/config.inc.php" "$1" "$2"
}

# cfg_set SECTION KEY VALUE — set a value in config.inc.php (keeps comments)
cfg_set() {
	"$PHP" "$REPO_DIR/scripts/lib/ini-set.php" "$OJS_DIR/config.inc.php" "$1" "$2" "$3"
}

# ojs_version — e.g. 3.5.0.1
ojs_version() {
	sed -n 's:.*<release>\(.*\)</release>.*:\1:p' "$OJS_DIR/dbscripts/xml/version.xml" | head -1
}

# files_dir — absolute path of the uploaded files directory
files_dir() {
	local d
	d="$(cfg files files_dir)"
	case "$d" in
		/*) printf '%s' "$d" ;;
		*) printf '%s' "$OJS_DIR/$d" ;;
	esac
}

# mysql_defaults FILE — write a client option file with DB credentials (0600)
mysql_defaults() {
	local f="$1" driver
	driver="$(cfg database driver)"
	case "$driver" in
		mysql|mysqli|mariadb) ;;
		*) die "Only MySQL/MariaDB is supported by this script (driver: $driver)." ;;
	esac
	umask 077
	{
		echo "[client]"
		echo "host=$(cfg database host)"
		echo "user=$(cfg database username)"
		echo "password=\"$(cfg database password)\""
		echo "default-character-set=utf8mb4"
	} > "$f"
	DB_NAME="$(cfg database name)"
	export DB_NAME
}

# clear_cache — remove compiled templates, CSS and data caches
clear_cache() {
	find "$OJS_DIR/cache" -mindepth 1 -maxdepth 1 \
		\( -name '*.php' -o -name '*.css' -o -name 'fc-*' -o -name 'wc-*' -o -name '_db' \) \
		-exec rm -rf {} + 2>/dev/null || true
	find "$OJS_DIR/cache/t_compile" "$OJS_DIR/cache/t_cache" -mindepth 1 -maxdepth 1 \
		! -name '.htaccess' ! -name 'index.html' -exec rm -rf {} + 2>/dev/null || true
	find "$OJS_DIR/cache" -mindepth 1 -maxdepth 1 -type d -name 'opcache' -exec rm -rf {} + 2>/dev/null || true
}
