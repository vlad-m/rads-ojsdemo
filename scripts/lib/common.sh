# shellcheck shell=bash
# Shared helpers for the rads-ojsdemo scripts. Source it, do not run it.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP="${PHP:-php}"

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

# require_ojs DIR — validate an OJS installation directory, sets OJS_DIR
require_ojs() {
	[ -n "${1:-}" ] || die "Path to the OJS installation is required."
	OJS_DIR="$(cd "$1" 2>/dev/null && pwd)" || die "Directory not found: $1"
	[ -f "$OJS_DIR/config.inc.php" ] && [ -f "$OJS_DIR/lib/pkp/classes/core/Core.php" ] \
		|| die "$OJS_DIR does not look like an OJS installation."
	command -v "$PHP" >/dev/null 2>&1 || die "PHP CLI not found (set PHP=/path/to/php)."
	export OJS_DIR
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
