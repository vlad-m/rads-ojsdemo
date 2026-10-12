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

# cfg SECTION KEY — read a value from config.inc.php exactly like OJS does
# (PKP\config\ConfigParser: quoted values are taken verbatim minus
# backslash escapes, unquoted values end at the first whitespace)
cfg() {
	"$PHP" -r '
		$section = null;
		foreach (file($argv[1], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
			$line = trim($line);
			if ($line === "" || $line[0] === ";") { continue; }
			if (preg_match("/^\\[(.+)\\]/", $line, $m)) { $section = $m[1]; continue; }
			if ($section !== $argv[2] || strpos($line, "=") === false) { continue; }
			[$k, $v] = explode("=", $line, 2);
			if (trim($k) !== $argv[3]) { continue; }
			$v = trim($v);
			if (preg_match("/^[\"\x27](.*)[\"\x27]$/", $v, $m)) { echo stripslashes($m[1]); }
			else { preg_match("/^(\\S*)/", $v, $m); echo $m[1]; }
			exit;
		}
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

# db_engine — mysql | pgsql (from [database] driver)
db_engine() {
	case "$(cfg database driver)" in
		mysql|mysqli|mariadb) echo mysql ;;
		postgres|postgres9|postgresql|pgsql) echo pgsql ;;
		*) die "Unsupported database driver: $(cfg database driver)" ;;
	esac
}

# db_prepare DIR — write private credential files into DIR and define
#   DB_ENGINE, DB_NAME and the arrays DB_CLI (interactive client) and DB_DUMP
db_prepare() {
	local dir="$1" host port
	DB_ENGINE="$(db_engine)"
	DB_NAME="$(cfg database name)"
	host="$(cfg database host)"
	port=''
	case "$host" in *:*) port="${host##*:}"; host="${host%:*}" ;; esac
	umask 077
	if [ "$DB_ENGINE" = mysql ]; then
		command -v mysql >/dev/null 2>&1 && command -v mysqldump >/dev/null 2>&1 || die "mysql/mysqldump client not found."
		{
			echo "[client]"
			echo "host=$host"
			[ -n "$port" ] && echo "port=$port"
			echo "user=$(cfg database username)"
			echo "password=\"$(cfg database password | sed 's/\\/\\\\/g; s/"/\\"/g')\""
			echo "default-character-set=utf8mb4"
		} > "$dir/my.cnf"
		DB_CLI=(mysql --defaults-extra-file="$dir/my.cnf" "$DB_NAME")
		DB_DUMP=(mysqldump --defaults-extra-file="$dir/my.cnf" --single-transaction --quick --no-tablespaces --skip-lock-tables --default-character-set=utf8mb4 "$DB_NAME")
	else
		command -v psql >/dev/null 2>&1 && command -v pg_dump >/dev/null 2>&1 || die "psql/pg_dump client not found."
		export PGOPTIONS="--client-min-messages=warning"
		PGPASSWORD="$(cfg database password)"
		export PGPASSWORD PGHOST="$host" PGPORT="${port:-5432}" PGUSER="$(cfg database username)" PGDATABASE="$DB_NAME"
		DB_CLI=(psql -X -q -v ON_ERROR_STOP=1)
		DB_DUMP=(pg_dump --no-owner --no-privileges)
	fi
	export DB_ENGINE DB_NAME
}

# db_sql "SQL" — run a statement with the prepared client
db_sql() {
	if [ "$DB_ENGINE" = mysql ]; then "${DB_CLI[@]}" -N -B -e "$1"; else "${DB_CLI[@]}" -At -c "$1"; fi
}

# db_drop_all — drop every table (and sequence) of the database
db_drop_all() {
	local tables t
	if [ "$DB_ENGINE" = mysql ]; then
		tables="$(db_sql 'SHOW TABLES')"
		[ -n "$tables" ] || return 0
		{
			echo 'SET FOREIGN_KEY_CHECKS=0;'
			while IFS= read -r t; do printf 'DROP TABLE IF EXISTS `%s`;\n' "$t"; done <<< "$tables"
			echo 'SET FOREIGN_KEY_CHECKS=1;'
		} | "${DB_CLI[@]}"
	else
		{
			db_sql "SELECT 'DROP TABLE IF EXISTS \"' || tablename || '\" CASCADE;' FROM pg_tables WHERE schemaname = current_schema()"
			db_sql "SELECT 'DROP SEQUENCE IF EXISTS \"' || sequencename || '\" CASCADE;' FROM pg_sequences WHERE schemaname = current_schema()"
		} | "${DB_CLI[@]}"
	fi
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
