#!/usr/bin/env bash
#
# Security audit of an OJS installation: core file integrity against the
# official release, web shells / injected code, and (optionally) what is
# reachable over HTTP. Read-only: it never changes the installation.
#
#   scripts/security-audit.sh /path/to/ojs [--url https://demo.example.ru] [--days N] [--no-integrity]
#
#   --url URL        also probe the live site for exposed files and headers
#   --days N         list PHP files modified in the last N days (default 7)
#   --no-integrity   skip downloading the official release for comparison
#   --trust-prepend=FILE  an auto_prepend_file you installed yourself (e.g. a
#                    honeypot/tracer); directives pointing to it are not reported
#
# Exit code: 0 = clean, 1 = findings that need attention.
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"

URL=''; DAYS=7; INTEGRITY=1; ARGS=(); STATIC_LEAK=0; TRUSTED_PREPEND=()
for arg in "$@"; do
	case "$arg" in
		--url=*) URL="${arg#*=}" ;;
		--url) URL='__next__' ;;
		--days=*) DAYS="${arg#*=}" ;;
		--no-integrity) INTEGRITY=0 ;;
		--trust-prepend=*) TRUSTED_PREPEND+=("${arg#*=}") ;;
		-h|--help) sed -n '2,15p' "$0"; exit 0 ;;
		-*) die "Unknown option: $arg" ;;
		*) if [ "$URL" = '__next__' ]; then URL="$arg"; else ARGS+=("$arg"); fi ;;
	esac
done
require_ojs "${ARGS[0]:-}"
URL="${URL%%/index.php*}"
URL="${URL%/}"
FINDINGS=0
finding() { fail "$*"; FINDINGS=$((FINDINGS + 1)); }
FILES_DIR="$(files_dir)"
VERSION="$(ojs_version)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Paths that legitimately differ from the release
is_local_path() {
	case "$1" in
		config.inc.php|config.inc.php.bak-*|.htaccess|.htaccess.bak-*|.user.ini|.user.ini.bak-*|robots.txt|robots.txt.bak-*) return 0 ;;
		cache/*|public/*|files/*) return 0 ;;
		plugins/themes/academicJournal/*|plugins/generic/demoMode/*|plugins/generic/ruLocaleCompletion/*) return 0 ;;
	esac
	return 1
}

step "OJS $VERSION — $OJS_DIR"

# ---------------------------------------------------------------------------
if [ "$INTEGRITY" = 1 ]; then
	step "Core integrity (against the official ojs-$VERSION release)"
	TARBALL="ojs-$(printf '%s' "$VERSION" | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+)\.([0-9]+)$/\1-\2/').tar.gz"
	DL="https://pkp.sfu.ca/ojs/download/$TARBALL"
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL -o "$WORK/ojs.tgz" "$DL" || true
	elif command -v wget >/dev/null 2>&1; then
		wget -q -O "$WORK/ojs.tgz" "$DL" || true
	fi
	if [ -s "$WORK/ojs.tgz" ] && tar -xzf "$WORK/ojs.tgz" -C "$WORK" 2>/dev/null; then
		REF="$(find "$WORK" -mindepth 1 -maxdepth 1 -type d | head -1)"
		(cd "$REF" && find . -type f | sed 's#^\./##' | sort) > "$WORK/ref.list"
		(cd "$OJS_DIR" && find . -xdev -type f | sed 's#^\./##' | sort) > "$WORK/cur.list"

		# top-level entries and plugin directories that exist in the release
		cut -d/ -f1 "$WORK/ref.list" | sort -u > "$WORK/ref.top"
		awk -F/ '$1=="plugins" && NF>3 {print $1"/"$2"/"$3}' "$WORK/ref.list" | sort -u > "$WORK/ref.plugins"

		# 1) files that differ from the release
		: > "$WORK/mod.core"; : > "$WORK/mod.plugins"
		while IFS= read -r f; do
			is_local_path "$f" && continue
			[ -f "$OJS_DIR/$f" ] || continue
			cmp -s "$REF/$f" "$OJS_DIR/$f" && continue
			case "$f" in
				plugins/*) echo "$f" >> "$WORK/mod.plugins" ;;
				*) echo "$f" >> "$WORK/mod.core" ;;
			esac
		done < "$WORK/ref.list"
		if [ -s "$WORK/mod.core" ]; then
			while IFS= read -r f; do finding "modified core file: $f"; done < "$WORK/mod.core"
		else
			ok "no core file (outside plugins/) is modified"
		fi
		if [ -s "$WORK/mod.plugins" ]; then
			# one line per bundled plugin: usually a plugin updated from the gallery
			awk -F/ '{p=$1"/"$2"/"$3; n[p]++; if ($NF ~ /\.php$/) php[p]++} END {for (p in n) printf "%s\t%d\t%d\n", p, n[p], php[p]+0}' "$WORK/mod.plugins" | sort |
			while IFS=$'\t' read -r p n php; do
				warn "bundled plugin differs from the release: $p ($n files, $php PHP) — fine if it was updated from the Plugin Gallery, otherwise reinstall it"
			done
		fi

		# 2) executable files that are not part of the release
		comm -13 "$WORK/ref.list" "$WORK/cur.list" | while IFS= read -r f; do
			is_local_path "$f" && continue
			case "$f" in
				*.php|*.phtml|*.phar|*.php[0-9]|*.inc|*.pl|*.py|*.cgi|*.sh|.htaccess|*/.htaccess|.user.ini|*/.user.ini) echo "$f" ;;
			esac
		done > "$WORK/extra"
		: > "$WORK/extra.single"
		declare -A GROUP=()
		while IFS= read -r f; do
			top="${f%%/*}"
			if [ "$top" != "$f" ] && ! grep -qxF "$top" "$WORK/ref.top"; then
				GROUP["dir:$top"]=$(( ${GROUP["dir:$top"]:-0} + 1 ))
			elif [[ "$f" == plugins/*/*/* ]]; then
				pdir="$(printf '%s' "$f" | cut -d/ -f1-3)"
				if grep -qxF "$pdir" "$WORK/ref.plugins"; then
					echo "$f" >> "$WORK/extra.single"
				else
					GROUP["plugin:$pdir"]=$(( ${GROUP["plugin:$pdir"]:-0} + 1 ))
				fi
			else
				echo "$f" >> "$WORK/extra.single"
			fi
		done < "$WORK/extra"
		for key in "${!GROUP[@]}"; do
			case "$key" in
				dir:*) finding "directory not in the release: ${key#dir:}/ (${GROUP[$key]} executable files) — a copy/backup inside the web root? remove or move it out" ;;
				plugin:*) warn "third-party plugin: ${key#plugin:} (${GROUP[$key]} executable files) — keep only if you know where it came from" ;;
			esac
		done
		if [ -s "$WORK/extra.single" ]; then
			total="$(wc -l < "$WORK/extra.single" | tr -d ' ')"
			while IFS= read -r f; do finding "file not in the release: $f"; done < <(head -40 "$WORK/extra.single")
			[ "$total" -gt 40 ] && { warn "… and $((total - 40)) more"; FINDINGS=$((FINDINGS + total - 40)); }
		fi
		[ ! -s "$WORK/extra" ] && ok "no unexpected executable files"
	else
		warn "could not download $DL — integrity check skipped (use --no-integrity to silence)"
	fi
fi

# ---------------------------------------------------------------------------
step "Executable files in upload/cache directories"
# cache/ legitimately contains compiled Smarty templates and data caches (PHP)
SUSPECT="$(
	{
		find "$OJS_DIR/public" -xdev -type f \( -iname '*.php*' -o -iname '*.phtml' -o -iname '*.phar' -o -iname '*.pht' -o -iname '*.cgi' -o -iname '*.pl' -o -iname '*.py' -o -iname '*.sh' -o -name '.htaccess' -o -name '.user.ini' \) 2>/dev/null
		find "$OJS_DIR/cache" -xdev -type f \( -iname '*.phtml' -o -iname '*.phar' -o -name '.user.ini' \) 2>/dev/null
		find "$OJS_DIR/cache" -xdev -type f -iname '*.php' ! -path '*/t_compile/*' ! -path '*/t_cache/*' ! -name 'fc-*' ! -name 'wc-*' ! -path '*/_db/*' ! -path '*/opcache/*' 2>/dev/null
	} | grep -v -e '/public/\.htaccess$' -e '/cache/\.htaccess$' || true
)"
if [ -n "$SUSPECT" ]; then
	while IFS= read -r f; do finding "executable file in a writable directory: ${f#"$OJS_DIR"/}"; done <<< "$SUSPECT"
else
	ok "public/ and cache/ contain no executable files"
fi
if [ -d "$FILES_DIR" ]; then
	N="$(find "$FILES_DIR" -xdev -type f \( -iname '*.php' -o -iname '*.phtml' -o -iname '*.phar' \) 2>/dev/null | wc -l | tr -d ' ')"
	[ "$N" = 0 ] && ok "files_dir contains no PHP files" || warn "files_dir contains $N PHP file(s) uploaded as submission files (harmless only while files_dir is not web-accessible)"
fi

# ---------------------------------------------------------------------------
step "Malicious code signatures"
PATTERN='(eval|assert|create_function)[[:space:]]*\([[:space:]]*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev|rawurldecode|hex2bin)[[:space:]]*\(|(eval|assert)[[:space:]]*\([[:space:]]*\$_(POST|GET|REQUEST|COOKIE|SERVER)|\$_(POST|GET|REQUEST|COOKIE)[[:space:]]*\[[^]]{1,40}\][[:space:]]*\(|(shell_exec|passthru|system|proc_open|popen|pcntl_exec)[[:space:]]*\([[:space:]]*\$_(POST|GET|REQUEST|COOKIE)|preg_replace[[:space:]]*\([[:space:]]*["'"'"'][^"'"'"']*/[a-z]*e[a-z]*["'"'"'][[:space:]]*,|FilesMan|WSOsetcookie|b374k|r57shell|c99shell|IndoXploit|\\x65\\x76\\x61\\x6c'
HITS="$(grep -rlIE --include='*.php' --include='*.phtml' --include='*.inc' --include='*.js' --include='*.tpl' --include='.htaccess' --include='.user.ini' \
	--exclude-dir=node_modules "$PATTERN" "$OJS_DIR" "$FILES_DIR" 2>/dev/null | grep -v -e '/lib/pkp/lib/vendor/' -e '/cache/t_compile/' || true)"
if [ -n "$HITS" ]; then
	while IFS= read -r f; do finding "suspicious code pattern: ${f#"$OJS_DIR"/}"; done <<< "$HITS"
else
	ok "no known web shell / obfuscation patterns"
fi
# auto_prepend_file in .htaccess/.user.ini is a classic persistence trick
PREPEND="$(grep -rlsiE 'auto_(prepend|append)_file' --include='.htaccess' --include='.user.ini' --include='php.ini' "$OJS_DIR" 2>/dev/null || true)"
if [ -n "$PREPEND" ]; then
	UNTRUSTED=0
	while IFS= read -r f; do
		while IFS= read -r directive; do
			target="$(printf '%s' "$directive" | sed -E 's/.*auto_(prepend|append)_file[[:space:]=]*["'"'"']?([^"'"'"' ;]*).*/\2/')"
			trusted=0
			for t in ${TRUSTED_PREPEND[@]+"${TRUSTED_PREPEND[@]}"}; do [ "$target" = "$t" ] && trusted=1; done
			if [ "$trusted" = 1 ]; then
				ok "trusted auto_prepend in ${f#"$OJS_DIR"/}: $target"
			else
				finding "auto_prepend/append directive in ${f#"$OJS_DIR"/}: $directive"
				UNTRUSTED=1
			fi
		done < <(grep -iE 'auto_(prepend|append)_file' "$f" | sed 's/^[[:space:]]*//')
	done <<< "$PREPEND"
	[ "$UNTRUSTED" = 1 ] && warn "a prepended file runs before EVERY PHP request: if it is yours, pass --trust-prepend=PATH; otherwise investigate"
fi

step "PHP files changed in the last $DAYS day(s) (outside cache/)"
RECENT="$(find "$OJS_DIR" -xdev -type f \( -name '*.php' -o -name '.htaccess' -o -name '.user.ini' \) -mtime "-$DAYS" ! -path "$OJS_DIR/cache/*" 2>/dev/null | head -50)"
if [ -n "$RECENT" ]; then
	warn "review these (expected right after an update or deployment):"
	while IFS= read -r f; do printf '      %s\n' "${f#"$OJS_DIR"/}"; done <<< "$RECENT"
else
	ok "none"
fi

# ---------------------------------------------------------------------------
if [ -n "$URL" ]; then
	step "HTTP exposure ($URL)"
	command -v curl >/dev/null 2>&1 || die "curl is required for --url"
	code() { curl -s -o /dev/null -m 15 -w "%{http_code}" "$URL$1" || true; }
	for p in /config.inc.php /config.TEMPLATE.inc.php /cache/ /classes/core/Request.php /lib/pkp/classes/core/Core.php \
		/templates/frontend/pages/article.tpl /dbscripts/xml/version.xml /composer.json /lib/pkp/composer.json /.git/config \
		/tools/upgrade.php /lib/pkp/lib/vendor/autoload.php /plugins/ /public/ /docs/ /registry/ "/index.php?x=../../etc/passwd"; do
		c="$(code "$p")"
		case "$c" in
			4??) ok "$p → $c" ;;
			*) finding "$p is reachable (HTTP $c)"; STATIC_LEAK=1 ;;
		esac
	done
	FILES_REL="${FILES_DIR#"$OJS_DIR"/}"
	if [ "$FILES_REL" != "$FILES_DIR" ] && [ -n "$FILES_REL" ]; then
		c="$(code "/$FILES_REL/")"; case "$c" in 403|404) ok "/$FILES_REL/ → $c" ;; *) finding "files_dir is reachable over HTTP (/$FILES_REL/ → $c)" ;; esac
	fi
	c="$(code "/")"; case "$c" in 200|301|302|303) ok "home page → $c" ;; *) finding "home page returns HTTP $c" ;; esac

	HEADERS="$(curl -s -D - -o /dev/null -m 15 -L --max-redirs 5 -b "" "$URL/" | tr -d '\r' || true)"
	for h in X-Content-Type-Options X-Frame-Options Referrer-Policy; do
		printf '%s' "$HEADERS" | grep -qi "^$h:" && ok "header $h" || finding "missing header $h"
	done
	case "$URL" in https://*) printf '%s' "$HEADERS" | grep -qi '^Strict-Transport-Security:' && ok "header Strict-Transport-Security" || finding "missing header Strict-Transport-Security" ;; esac
	printf '%s' "$HEADERS" | grep -qi '^X-Powered-By:' && finding "X-Powered-By header discloses the PHP version" || ok "no X-Powered-By header"
	if [ "${STATIC_LEAK:-0}" = 1 ] && printf '%s' "$HEADERS" | grep -qi '^Server: *nginx'; then
		warn "nginx serves static files itself, so .htaccess rules do not apply to them. In the hosting panel switch off"
		warn "\"static files via nginx\" for this site (or ask support to deny *.tpl, *.xml, composer.json, /lib/pkp/ for it)."
	fi
	printf '%s' "$HEADERS" | grep -qiE '^Server:.*[0-9]' && warn "Server header discloses a version: $(printf '%s' "$HEADERS" | grep -i '^Server:' | head -1)" || true
	case "$URL" in
		https://*)
			c="$(curl -s -o /dev/null -m 15 -w '%{http_code}' "http://${URL#https://}/")"
			case "$c" in 301|302|307|308) ok "HTTP redirects to HTTPS ($c)" ;; *) warn "plain HTTP answers with $c (expected a redirect to HTTPS)" ;; esac
			;;
	esac
fi

# ---------------------------------------------------------------------------
step "Result"
if [ "$FINDINGS" = 0 ]; then
	ok "no findings"
	exit 0
fi
warn "$FINDINGS finding(s) — investigate each one before trusting the installation"
exit 1
