#!/usr/bin/env bash
#
# Diagnose database access for the snapshot/reset scripts without printing
# any secret: compares the credentials as OJS reads them with what the
# scripts use, and tries PHP (PDO) and the command-line client.
#
#   scripts/db-check.sh /path/to/ojs
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
require_ojs "${1:-}"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

step "Credentials (no secrets shown)"
"$PHP" -r '
	chdir($argv[1]);
	require "lib/pkp/classes/config/ConfigParser.php";
	$c = PKP\config\ConfigParser::readConfig("config.inc.php")["database"] ?? [];
	$p = (string) ($c["password"] ?? "");
	printf("  as OJS reads it: driver=%s host=%s port=%s name=%s user=%s password: %d chars, sha1 %s\n",
		$c["driver"] ?? "?", $c["host"] ?? "?", $c["port"] ?? "-", $c["name"] ?? "?", $c["username"] ?? "?", strlen($p), substr(sha1($p), 0, 8));
' "$OJS_DIR"
P="$(cfg database password)"
info "  as the scripts read it: password: ${#P} chars, sha1 $(printf '%s' "$P" | sha1sum | cut -c1-8)"
db_prepare "$TMP"
[ "$DB_ENGINE" = pgsql ] && info "  scripts connect to: host=$PGHOST port=$PGPORT db=$PGDATABASE user=$PGUSER"

step "PHP (PDO) with the scripts' credentials"
"$PHP" -r '
	$e = getenv("DB_ENGINE");
	try {
		if ($e === "pgsql") {
			$dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("PGHOST"), getenv("PGPORT"), getenv("PGDATABASE"));
			$pdo = new PDO($dsn, getenv("PGUSER"), getenv("PGPASSWORD"));
		} else {
			$ini = parse_ini_file($argv[1], true, INI_SCANNER_RAW)["client"];
			$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s", $ini["host"], getenv("DB_NAME")), $ini["user"], trim($ini["password"], "\""));
		}
		echo "  ✔ connected: ", $pdo->query("select version()")->fetchColumn(), "\n";
	} catch (Throwable $t) { echo "  ✘ ", $t->getMessage(), "\n"; }
' "$TMP/my.cnf" 2>&1

step "Command-line client"
if [ "$DB_ENGINE" = pgsql ]; then
	info "  $(pg_dump --version 2>&1 | head -1) ($(command -v pg_dump))"
	info "  $(psql --version 2>&1 | head -1)"
	for c in /usr/bin/pg_dump* /usr/lib/postgresql/*/bin/pg_dump /usr/pgsql-*/bin/pg_dump /usr/local/pgsql*/bin/pg_dump; do
		[ -x "$c" ] && info "  available: $c ($("$c" --version 2>/dev/null))"
	done
	if out="$(psql -X -At -c 'select 1' 2>&1)"; then ok "psql connects"; else fail "psql: $out"; fi
else
	if out="$(db_sql 'select 1' 2>&1)"; then ok "mysql connects"; else fail "mysql: $out"; fi
fi
