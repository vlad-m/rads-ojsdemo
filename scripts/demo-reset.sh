#!/usr/bin/env bash
#
# Restore the demo installation from the snapshot made by demo-snapshot.sh:
# database, uploaded files and public files; then clear caches. Intended for
# cron, e.g. every 3 hours:
#
#   0 */3 * * * /bin/bash $HOME/rads-ojsdemo/scripts/demo-reset.sh $HOME/ojsdemo_new >> $HOME/logs/ojs-demo-reset.log 2>&1
#
#   scripts/demo-reset.sh /path/to/ojs [SNAPSHOT_DIR]
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"

require_ojs "${1:-}"
SNAP="${2:-$HOME/ojs-demo-snapshot}"
for f in db.sql.gz files.tar.gz public.tar.gz; do
	[ -s "$SNAP/$f" ] || die "Snapshot file missing: $SNAP/$f (run demo-snapshot.sh first)"
done
command -v mysql >/dev/null 2>&1 || die "mysql client not found."
FILES_DIR="$(files_dir)"
[ -n "$FILES_DIR" ] && [ "$FILES_DIR" != "/" ] && [ -d "$FILES_DIR" ] || die "Invalid files_dir: $FILES_DIR"

# One reset at a time
exec 9>"$SNAP/.reset.lock"
if command -v flock >/dev/null 2>&1; then
	flock -n 9 || die "Another reset is running."
fi

TMP="$(mktemp -d "$SNAP/.tmp.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
mysql_defaults "$TMP/my.cnf"
MYSQL=(mysql --defaults-extra-file="$TMP/my.cnf" "$DB_NAME")

step "$(date '+%Y-%m-%d %H:%M:%S') reset from $SNAP ($(sed -n 's/^date=//p' "$SNAP/snapshot.info" 2>/dev/null))"

# Database: drop every table (also ones created after the snapshot), then load
TABLES="$("${MYSQL[@]}" -N -B -e 'SHOW TABLES')"
if [ -n "$TABLES" ]; then
	{
		echo 'SET FOREIGN_KEY_CHECKS=0;'
		while IFS= read -r t; do printf 'DROP TABLE IF EXISTS `%s`;\n' "$t"; done <<< "$TABLES"
		echo 'SET FOREIGN_KEY_CHECKS=1;'
	} | "${MYSQL[@]}"
fi
gzip -dc "$SNAP/db.sql.gz" | "${MYSQL[@]}"
# Keep open review requests "fresh" so the demo reviewer never sees them overdue
"${MYSQL[@]}" -e "UPDATE review_assignments SET
	date_assigned = NOW() - INTERVAL 3 DAY, date_notified = NOW() - INTERVAL 3 DAY,
	date_response_due = NOW() + INTERVAL 4 DAY, date_due = NOW() + INTERVAL 25 DAY
	WHERE date_confirmed IS NULL AND date_completed IS NULL AND declined = 0 AND cancelled = 0" || true
ok "database restored"

# Files: extract next to the live directory, then swap
restore_dir() { # ARCHIVE TARGET_DIR [KEEP_FILE...]
	local archive="$1" target="$2"; shift 2
	local parent new old keep
	parent="$(dirname "$target")"
	new="$(mktemp -d "$parent/.demo-new.XXXXXX")"
	old="$parent/.demo-old.$$"
	tar -xzf "$archive" -C "$new"
	for keep in "$@"; do
		if [ -e "$target/$keep" ]; then
			mkdir -p "$new/$(dirname "$keep")"
			cp -p "$target/$keep" "$new/$keep"
		fi
	done
	chmod --reference="$target" "$new" 2>/dev/null || chmod 755 "$new"
	mv "$target" "$old"
	mv "$new" "$target"
	rm -rf "$old"
}
restore_dir "$SNAP/files.tar.gz" "$FILES_DIR" .htaccess usageStats/IPGeoDB.mmdb
ok "files_dir restored"
restore_dir "$SNAP/public.tar.gz" "$OJS_DIR/public" .htaccess index.html
ok "public/ restored"

clear_cache
ok "caches cleared"
