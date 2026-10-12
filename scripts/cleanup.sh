#!/usr/bin/env bash
#
# Remove installation leftovers and development files from the web root, and
# hide source files that the web server would otherwise hand out as plain text.
#
#   scripts/cleanup.sh /path/to/ojs                    # show what would be done
#   scripts/cleanup.sh /path/to/ojs --apply            # do it
#   scripts/cleanup.sh /path/to/ojs --apply --private-files --url https://site
#
# Options:
#   --apply           move the leftovers out of the web root (nothing is deleted:
#                     everything goes to ~/ojs-removed-<date>/, keeping its path)
#   --private-files   remove the "others" read permission from templates,
#                     translations, XML/JSON manifests, Markdown etc. PHP runs as
#                     the site owner and still reads them, while a front-end web
#                     server running as another user (nginx on shared hosting)
#                     answers 403 instead of serving the source. Needs --url:
#                     the site is checked afterwards and the change is rolled
#                     back automatically if a page stops working.
#   --url URL         site address used for the checks
#   --dest DIR        where removed files go (default: ~/ojs-removed-<date>)
#
# What is moved:
#   * directories/files in the web root that are not part of the OJS release
#     and look like copies or backups ("mv plugins plugins_old", public_html/…)
#   * OJS development files: CI/test configs, docs/, .github/, linters…
#   * test suites bundled with third-party libraries (vendor/*/*/tests)
#   * archives, SQL dumps, logs, phpinfo/test scripts
#   * backups of config.inc.php (they contain the database password),
#     .htaccess, .user.ini and robots.txt made by harden.sh
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"

APPLY=0; PRIVATE=0; URL=''; DEST=''; ARGS=()
for arg in "$@"; do
	case "$arg" in
		--apply) APPLY=1 ;;
		--private-files) PRIVATE=1 ;;
		--url=*) URL="${arg#*=}" ;;
		--dest=*) DEST="${arg#*=}" ;;
		-h|--help) sed -n '2,33p' "$0"; exit 0 ;;
		-*) die "Unknown option: $arg" ;;
		*) ARGS+=("$arg") ;;
	esac
done
require_ojs "${ARGS[0]:-}"
URL="${URL%%/index.php*}"; URL="${URL%/}"
DEST="${DEST:-$HOME/ojs-removed-$(date +%Y%m%d-%H%M%S)}"
case "$DEST/" in "$OJS_DIR"/*) die "--dest must be outside the web root." ;; esac
VERSION="$(ojs_version)"
FILES_DIR="$(files_dir)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

: > "$WORK/candidates"
add() { # PATH-relative-to-OJS_DIR REASON
	[ -e "$OJS_DIR/$1" ] || [ -L "$OJS_DIR/$1" ] || return 0
	printf '%s\t%s\n' "$1" "$2" >> "$WORK/candidates"
}

step "OJS $VERSION — $OJS_DIR"
[ "$APPLY" = 1 ] || warn "dry run: nothing is changed (use --apply)"

# ---------------------------------------------------------------------------
step "Looking for leftovers"

# 1. Top-level entries that are not part of the release
TARBALL="ojs-$(printf '%s' "$VERSION" | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+)\.([0-9]+)$/\1-\2/').tar.gz"
CACHE="$HOME/.cache/rads-ojsdemo"; mkdir -p "$CACHE"
if [ ! -s "$CACHE/$TARBALL" ]; then
	curl -fsSL -o "$CACHE/$TARBALL.part" "https://pkp.sfu.ca/ojs/download/$TARBALL" && mv "$CACHE/$TARBALL.part" "$CACHE/$TARBALL" || rm -f "$CACHE/$TARBALL.part"
fi
if [ -s "$CACHE/$TARBALL" ]; then
	tar -tzf "$CACHE/$TARBALL" | awk -F/ 'NF>1 && $2!="" {print $2}' | sort -u > "$WORK/release.top"
	while IFS= read -r entry; do
		case "$entry" in
			config.inc.php|cache|public|plugins|.htaccess|.user.ini|robots.txt|favicon.ico|.well-known) continue ;;
			config.inc.php.bak-*|.htaccess.bak-*|.user.ini.bak-*|robots.txt.bak-*) continue ;; # handled below
		esac
		[ "$OJS_DIR/$entry" = "$FILES_DIR" ] && continue
		grep -qxF "$entry" "$WORK/release.top" || add "$entry" "not part of the OJS $VERSION release (copy/backup?)"
	done < <(cd "$OJS_DIR" && ls -A)
else
	warn "could not download $TARBALL — skipping the comparison with the release"
	add "mv plugins plugins_old" "copy of the plugins directory"
	add "public_html" "nested copy of the site"
fi

# 2. Development files shipped in the release (not used at runtime)
for f in .eslintrc.cjs .github .husky .php-cs-fixer.php .prettierignore cypress.config.js cypress.travis.env.json \
	phpdoc.dist.xml jsconfig.json schemaspy.properties vite.config.js vite.config.frontend.js docs \
	tools/release.sh tools/startSubmodulesTRAVIS.sh tools/jsonlint-exclusions.txt tools/xmllint-exclusions.txt \
	lib/pkp/.github lib/pkp/.php-cs-fixer.php lib/pkp/.php_cs_rules lib/pkp/tests lib/pkp/cypress tests cypress; do
	add "$f" "development file"
done

# 3. Test suites of bundled libraries
while IFS= read -r d; do
	add "${d#"$OJS_DIR"/}" "test suite of a bundled library"
done < <(find "$OJS_DIR/lib/pkp/lib/vendor" "$OJS_DIR/plugins" -mindepth 3 -maxdepth 6 -type d \
	\( -name tests -o -name Tests -o -name test \) -path '*/vendor/*' 2>/dev/null | sort)

# 4. Archives, dumps, logs and debug scripts (outside the libraries)
while IFS= read -r f; do
	add "${f#"$OJS_DIR"/}" "archive/dump/log/debug script"
done < <(find "$OJS_DIR" -maxdepth 3 \( -path "$OJS_DIR/lib" -o -path "$OJS_DIR/cache" -o -path "$FILES_DIR" \) -prune -o -type f \
	\( -iname '*.zip' -o -iname '*.tar' -o -iname '*.tar.gz' -o -iname '*.tgz' -o -iname '*.rar' -o -iname '*.7z' \
	-o -iname '*.sql' -o -iname '*.sql.gz' -o -iname '*.dump' -o -iname 'error_log' -o -iname '*.log' \
	-o -iname 'phpinfo.php' -o -iname 'info.php' -o -iname 'test.php' -o -iname 'adminer*.php' -o -iname '*.swp' -o -iname '*~' \) -print 2>/dev/null)

# 5. Backups created by harden.sh / editors
while IFS= read -r f; do
	add "${f#"$OJS_DIR"/}" "backup (config backups contain the DB password)"
done < <(find "$OJS_DIR" -maxdepth 2 -type f \( -name '*.bak-*' -o -name '*.bak' -o -name '*.orig' -o -name '*.old' \) 2>/dev/null)

sort -t "$(printf '\t')" -k1,1 -u -o "$WORK/candidates" "$WORK/candidates"
if [ ! -s "$WORK/candidates" ]; then
	ok "nothing to remove"
else
	while IFS=$'\t' read -r path reason; do
		size="$(du -sh "$OJS_DIR/$path" 2>/dev/null | cut -f1)"
		info "  • $path  (${size:-?}) — $reason"
	done < "$WORK/candidates"
fi

if [ "$APPLY" = 1 ] && [ -s "$WORK/candidates" ]; then
	step "Moving to $DEST"
	mkdir -p "$DEST"; chmod 700 "$DEST"
	while IFS=$'\t' read -r path reason; do
		[ -e "$OJS_DIR/$path" ] || continue  # already moved with a parent
		mkdir -p "$DEST/$(dirname "$path")"
		mv "$OJS_DIR/$path" "$DEST/$path"
	done < "$WORK/candidates"
	ok "moved $(wc -l < "$WORK/candidates" | tr -d ' ') item(s); delete $DEST once the site is confirmed to work"
fi

# ---------------------------------------------------------------------------
check_site() { # → 0 if pages and static files answer normally
	local c p body
	# compiled templates hide unreadable sources: start from an empty cache
	clear_cache
	for p in "/" "/index.php/index/login"; do
		body="$(curl -s -L --max-redirs 5 -b "" -m 30 -w '\n%{http_code}' "$URL$p")"
		c="${body##*$'\n'}"
		if [ "$c" != 200 ] || printf '%s' "$body" | grep -qiE 'Fatal error|Unable to load template|SmartyException|failed to open stream'; then
			warn "$p answers $c or shows an error"
			return 1
		fi
	done
	c="$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$URL/lib/pkp/styles/fontawesome/fontawesome.css")"
	[ "$c" = 200 ] || { warn "static CSS answers $c"; return 1; }
	return 0
}

if [ "$PRIVATE" = 1 ]; then
	step "Hiding source files from the front-end web server"
	[ -n "$URL" ] || die "--private-files needs --url to verify the site."
	find "$OJS_DIR" -xdev \( -path "$OJS_DIR/public" -o -path "$OJS_DIR/cache" -o -path "$FILES_DIR" \) -prune -o -type f \
		\( -name '*.tpl' -o -name '*.po' -o -name '*.pot' -o -name '*.xml' -o -name '*.xsd' -o -name '*.dtd' -o -name '*.less' \
		-o -name '*.md' -o -name 'composer.json' -o -name 'composer.lock' -o -name 'installed.json' -o -name 'package.json' -o -name 'package-lock.json' \
		-o -name '*.neon' -o -name '*.yml' -o -name '*.yaml' -o -name '*.dist' -o -name '*.properties' -o -name '*.sh' \
		-o -name 'config.TEMPLATE.inc.php' -o -name '*.inc.php' \) -perm -o=r -print > "$WORK/private"
	n="$(wc -l < "$WORK/private" | tr -d ' ')"
	info "  $n file(s) readable by others"
	if [ "$APPLY" = 1 ] && [ "$n" -gt 0 ]; then
		check_site || die "the site does not answer normally even before the change; not touching permissions."
		tr '\n' '\0' < "$WORK/private" | xargs -0 chmod o-r
		if check_site; then
			c="$(curl -s -o /dev/null -m 20 -w '%{http_code}' "$URL/templates/frontend/pages/article.tpl")"
			ok "done; /templates/frontend/pages/article.tpl now answers $c (expected 403/404)"
			[ "$c" = 200 ] && warn "still served: the web server reads files through the group or as the owner — ask the hosting to deny *.tpl, *.xml, composer.* for this site"
			cp "$WORK/private" "$DEST.private-files.list" 2>/dev/null || true
		else
			tr '\n' '\0' < "$WORK/private" | xargs -0 chmod o+r
			fail "the site broke after the change — permissions restored"
		fi
	elif [ "$APPLY" != 1 ]; then
		info "  (would run chmod o-r on them and verify $URL)"
	fi
fi

step "Next"
info "  Verify: scripts/security-audit.sh $OJS_DIR${URL:+ --url $URL}"
