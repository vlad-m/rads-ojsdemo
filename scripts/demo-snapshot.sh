#!/usr/bin/env bash
#
# Save the current state of the demo installation (database, uploaded files,
# public files) as the "golden" snapshot that demo-reset.sh restores.
#
#   scripts/demo-snapshot.sh /path/to/ojs [SNAPSHOT_DIR]
#
# SNAPSHOT_DIR defaults to ~/ojs-demo-snapshot (keep it outside the web root).
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"

require_ojs "${1:-}"
SNAP="${2:-$HOME/ojs-demo-snapshot}"
case "$SNAP/" in "$OJS_DIR"/*) die "The snapshot directory must be outside the OJS directory." ;; esac
FILES_DIR="$(files_dir)"

mkdir -p "$SNAP"
chmod 700 "$SNAP"
TMP="$(mktemp -d "$SNAP/.tmp.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT
db_prepare "$TMP"

step "Snapshot → $SNAP"
"${DB_DUMP[@]}" | gzip -6 > "$TMP/db.sql.gz"
ok "database $DB_NAME [$DB_ENGINE] ($(du -h "$TMP/db.sql.gz" | cut -f1))"

# The GeoIP database (~120 MB) is kept in place by demo-reset.sh, no need to archive it
tar -czf "$TMP/files.tar.gz" -C "$FILES_DIR" --exclude=./usageStats/IPGeoDB.mmdb .
ok "files_dir $FILES_DIR ($(du -h "$TMP/files.tar.gz" | cut -f1))"

tar -czf "$TMP/public.tar.gz" -C "$OJS_DIR/public" --exclude='./.htaccess' --exclude='./index.html' .
ok "public/ ($(du -h "$TMP/public.tar.gz" | cut -f1))"

{
	echo "date=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	echo "ojs_version=$(ojs_version)"
	echo "db_engine=$DB_ENGINE"
	echo "ojs_dir=$OJS_DIR"
	echo "files_dir=$FILES_DIR"
} > "$TMP/snapshot.info"

rm -f "$TMP/my.cnf" "$TMP/pgpass"
for f in db.sql.gz files.tar.gz public.tar.gz snapshot.info; do mv -f "$TMP/$f" "$SNAP/$f"; done
chmod 600 "$SNAP"/*
ok "snapshot saved"
