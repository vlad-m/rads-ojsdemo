#!/usr/bin/env bash
#
# Security hardening for an OJS 3.5 installation on Apache shared hosting.
#
#   scripts/harden.sh /path/to/ojs            # report only, changes nothing
#   scripts/harden.sh /path/to/ojs --apply    # apply the fixes
#
# Options:
#   --apply        write .htaccess files, fix permissions, tune config.inc.php
#   --noindex      keep the site out of search engines (robots.txt + X-Robots-Tag)
#   --lock-code    make OJS code read-only (chmod a-w) except cache/ and public/;
#                  use --unlock-code before updating OJS or redeploying plugins
#   --unlock-code  restore owner write permission on the code
#
# Every changed file is backed up next to the original (*.bak-YYYYmmddHHMMSS).
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"

APPLY=0; NOINDEX=0; LOCK=0; UNLOCK=0; ARGS=()
for arg in "$@"; do
	case "$arg" in
		--apply) APPLY=1 ;;
		--noindex) NOINDEX=1 ;;
		--lock-code) LOCK=1 ;;
		--unlock-code) UNLOCK=1 ;;
		-h|--help) sed -n '2,18p' "$0"; exit 0 ;;
		-*) die "Unknown option: $arg" ;;
		*) ARGS+=("$arg") ;;
	esac
done
require_ojs "${ARGS[0]:-}"
TS="$(date +%Y%m%d%H%M%S)"
ISSUES=0
issue() { fail "$*"; ISSUES=$((ISSUES + 1)); }

backup() { [ -e "$1" ] && cp -p "$1" "$1.bak-$TS" && chmod go-rwx "$1.bak-$TS"; return 0; }

# install_block TEMPLATE TARGET — put our marked block into an .htaccess file,
# keeping any other directives already present (e.g. the host's PHP handler).
install_block() {
	local template="$1" target="$2" block tmp
	block="$(cat "$template")"
	if [ "$NOINDEX" = 1 ]; then
		block="${block//#NOINDEX# /}"
	fi
	if [ "$HTTPS" = 1 ]; then
		block="${block//#HSTS# /}"
	fi
	if [ -f "$target" ] && grep -q '# BEGIN rads-ojsdemo hardening' "$target"; then
		if [ "$(sed -n '/# BEGIN rads-ojsdemo hardening/,/# END rads-ojsdemo hardening/p' "$target")" = "$block" ]; then
			ok "$target is up to date"
			return
		fi
		backup "$target"
		tmp="$(mktemp)"
		sed '/# BEGIN rads-ojsdemo hardening/,/# END rads-ojsdemo hardening/d' "$target" > "$tmp"
		{ cat "$tmp"; printf '%s\n' "$block"; } > "$target"
		rm -f "$tmp"
		ok "updated $target"
	else
		backup "$target"
		{ [ -f "$target" ] && cat "$target" && echo; printf '%s\n' "$block"; } > "$target.new.$TS"
		mv "$target.new.$TS" "$target"
		ok "installed $target"
	fi
	chmod 644 "$target"
}

BASE_URL="$(cfg general base_url)"
HOST="$(printf '%s' "$BASE_URL" | sed -E 's#^[a-z]+://([^/:]+).*#\1#')"
HTTPS=0; case "$BASE_URL" in https://*) HTTPS=1 ;; esac
FILES_DIR="$(files_dir)"
DEMO_ENABLED="$(cfg demo enabled)"

step "OJS $(ojs_version) — $OJS_DIR"
info "  base_url: ${BASE_URL:-<empty>}"
[ "$APPLY" = 1 ] || warn "report mode: nothing is changed (use --apply)"

# ---------------------------------------------------------------------------
step "Web server rules (.htaccess)"
if [ "$APPLY" = 1 ]; then
	install_block "$REPO_DIR/security/htaccess/root.htaccess" "$OJS_DIR/.htaccess"
	install_block "$REPO_DIR/security/htaccess/public.htaccess" "$OJS_DIR/public/.htaccess"
	install_block "$REPO_DIR/security/htaccess/deny-all.htaccess" "$OJS_DIR/cache/.htaccess"
else
	for f in .htaccess public/.htaccess cache/.htaccess; do
		if grep -q 'rads-ojsdemo hardening' "$OJS_DIR/$f" 2>/dev/null; then ok "$f present"; else issue "$f missing"; fi
	done
fi

if [ "$NOINDEX" = 1 ] && [ "$APPLY" = 1 ]; then
	if ! cmp -s "$REPO_DIR/security/robots-noindex.txt" "$OJS_DIR/robots.txt"; then
		backup "$OJS_DIR/robots.txt"
		cp "$REPO_DIR/security/robots-noindex.txt" "$OJS_DIR/robots.txt"
		ok "robots.txt: Disallow all (demo site)"
	fi
fi

# ---------------------------------------------------------------------------
step "Uploaded files directory"
case "$FILES_DIR/" in
	"$OJS_DIR"/*)
		issue "files_dir ($FILES_DIR) is inside the web root — move it outside (e.g. $(dirname "$OJS_DIR")/ojs_files) and update [files] files_dir"
		if [ "$APPLY" = 1 ] && [ -d "$FILES_DIR" ]; then
			install_block "$REPO_DIR/security/htaccess/deny-all.htaccess" "$FILES_DIR/.htaccess"
			warn "added a deny-all .htaccess to it as a stop-gap"
		fi
		;;
	*) ok "files_dir is outside the web root: $FILES_DIR" ;;
esac
[ -d "$FILES_DIR" ] || issue "files_dir does not exist: $FILES_DIR"

# ---------------------------------------------------------------------------
step "Permissions"
CONF_MODE="$(stat -c '%a' "$OJS_DIR/config.inc.php" 2>/dev/null || stat -f '%Lp' "$OJS_DIR/config.inc.php")"
if [ "$CONF_MODE" = 600 ] || [ "$CONF_MODE" = 640 ] || [ "$CONF_MODE" = 400 ] || [ "$CONF_MODE" = 440 ]; then
	ok "config.inc.php mode $CONF_MODE"
elif [ "$APPLY" = 1 ]; then
	chmod 640 "$OJS_DIR/config.inc.php"; ok "config.inc.php: $CONF_MODE -> 640"
else
	issue "config.inc.php mode $CONF_MODE (should be 640 or stricter)"
fi

WW="$(find "$OJS_DIR" "$FILES_DIR" -xdev -perm -0002 ! -type l 2>/dev/null | head -20)"
if [ -n "$WW" ]; then
	if [ "$APPLY" = 1 ]; then
		find "$OJS_DIR" "$FILES_DIR" -xdev -perm -0002 ! -type l -exec chmod o-w {} + 2>/dev/null || true
		ok "removed world-write permission"
	else
		issue "world-writable files/directories:"; printf '      %s\n' $WW
	fi
else
	ok "no world-writable files"
fi
find "$OJS_DIR" -maxdepth 1 -name 'config.inc.php.bak-*' -exec chmod 600 {} + 2>/dev/null || true

if [ "$UNLOCK" = 1 ]; then
	find "$OJS_DIR" -xdev \( -path "$OJS_DIR/cache" -o -path "$OJS_DIR/public" \) -prune -o -exec chmod u+w {} + 2>/dev/null || true
	ok "code unlocked (owner can write again)"
elif [ "$LOCK" = 1 ]; then
	find "$OJS_DIR" -xdev \( -path "$OJS_DIR/cache" -o -path "$OJS_DIR/public" -o -name 'config.inc.php' \) -prune -o -type f -exec chmod a-w {} + 2>/dev/null || true
	find "$OJS_DIR" -xdev \( -path "$OJS_DIR/cache" -o -path "$OJS_DIR/public" \) -prune -o -type d -exec chmod 555 {} + 2>/dev/null || true
	chmod 755 "$OJS_DIR"
	ok "code is read-only (cache/, public/ and config.inc.php stay writable)"
fi

# ---------------------------------------------------------------------------
step "Leftovers in the web root"
LEFT="$(find "$OJS_DIR" -maxdepth 3 \( -iname '*.sql' -o -iname '*.sql.gz' -o -iname '*.zip' -o -iname '*.tar' -o -iname '*.tar.gz' -o -iname '*.tgz' \
	-o -iname 'phpinfo.php' -o -iname 'info.php' -o -iname 'test.php' -o -iname 'adminer*.php' -o -iname 'phpmyadmin' -o -name '.git' -o -name '.env' \) \
	! -path "$OJS_DIR/lib/*" ! -path "$OJS_DIR/plugins/*" 2>/dev/null | head -20)"
if [ -n "$LEFT" ]; then
	issue "remove these files from the web root (backups, dumps, debug scripts):"; printf '      %s\n' $LEFT
else
	ok "nothing found"
fi

# ---------------------------------------------------------------------------
step "config.inc.php"
check_set() { # SECTION KEY EXPECTED [DESCRIPTION]
	local cur; cur="$(cfg "$1" "$2")"
	if [ "${cur,,}" = "${3,,}" ] || { [ "${3,,}" = on ] && [ "${cur,,}" = 1 ]; } || { [ "${3,,}" = off ] && [ "${cur,,}" = 0 ]; }; then
		ok "$1.$2 = $3"
	elif [ "$APPLY" = 1 ]; then
		cfg_set "$1" "$2" "$3"
	else
		issue "$1.$2 = '${cur}' (recommended: $3)${4:+ — $4}"
	fi
}

[ "$(cfg general installed)" = On ] || [ "$(cfg general installed)" = 1 ] || issue "general.installed is not On"
[ -n "$(cfg general app_key)" ] && ok "general.app_key is set" || issue "general.app_key is empty — run: php lib/pkp/tools/appKey.php generate"
check_set general show_upgrade_warning Off "hides the version banner"
check_set general enable_beacon Off "no phone-home"
if [ -n "$HOST" ]; then
	cur_hosts="$(cfg general allowed_hosts)"
	if [ -n "$cur_hosts" ]; then
		ok "general.allowed_hosts = $cur_hosts"
	elif [ "$APPLY" = 1 ]; then
		cfg_set general allowed_hosts "'[\"$HOST\"]'"
	else
		issue "general.allowed_hosts is empty (Host header injection) — recommended: '[\"$HOST\"]'"
	fi
fi
if [ "$HTTPS" = 1 ]; then
	check_set security force_ssl On
	check_set security force_login_ssl On
else
	issue "base_url is not https:// — enable TLS (sweb: free Let's Encrypt in the control panel) and switch base_url"
fi
check_set security session_check_ip On
if [ -z "$(cfg security api_key_secret)" ]; then
	if [ "$APPLY" = 1 ]; then
		cfg_set security api_key_secret "\"$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')\""
	else
		issue "security.api_key_secret is empty"
	fi
else
	ok "security.api_key_secret is set"
fi
check_set debug display_errors Off
check_set debug show_stacktrace Off
check_set debug deprecation_warnings Off
check_set files umask 0022

# ALTCHA (built-in, privacy-friendly CAPTCHA) against registration/password spam
# and login brute force. On a demo site the login stays captcha-free so that
# the one-click demo sign-in works (the demo passwords are public anyway).
check_set captcha altcha on
if [ "$(cfg captcha altcha_hmackey)" = "Example key" ] || [ -z "$(cfg captcha altcha_hmackey)" ]; then
	if [ "$APPLY" = 1 ]; then
		cfg_set captcha altcha_hmackey "'$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')'"
	else
		issue "captcha.altcha_hmackey is the example value"
	fi
fi
check_set captcha altcha_on_register on
check_set captcha altcha_on_lost_password on
if [ "${DEMO_ENABLED,,}" = on ] || [ "$DEMO_ENABLED" = 1 ]; then
	check_set captcha altcha_on_login off "demo: one-click sign-in"
else
	check_set captcha altcha_on_login on
fi

# ---------------------------------------------------------------------------
step "PHP (per-directory .user.ini, effective with PHP-FPM/CGI)"
USER_INI="$OJS_DIR/.user.ini"
read -r -d '' INI_BODY <<EOF || true
; BEGIN rads-ojsdemo hardening
display_errors = Off
log_errors = On
allow_url_include = Off
session.cookie_httponly = 1
session.use_strict_mode = 1
session.cookie_samesite = "Lax"
$( [ "$HTTPS" = 1 ] && echo 'session.cookie_secure = 1' )
; END rads-ojsdemo hardening
EOF
if grep -q 'rads-ojsdemo hardening' "$USER_INI" 2>/dev/null; then
	ok ".user.ini present"
elif [ "$APPLY" = 1 ]; then
	backup "$USER_INI"
	{ [ -f "$USER_INI" ] && cat "$USER_INI" && echo; printf '%s\n' "$INI_BODY"; } > "$USER_INI.new.$TS" && mv "$USER_INI.new.$TS" "$USER_INI"
	ok "installed .user.ini"
else
	issue ".user.ini missing"
fi

# ---------------------------------------------------------------------------
step "Summary"
if [ "$APPLY" = 1 ]; then
	ok "hardening applied; verify with: scripts/security-audit.sh $OJS_DIR${BASE_URL:+ --url $BASE_URL}"
elif [ "$ISSUES" = 0 ]; then
	ok "no issues found"
else
	warn "$ISSUES issue(s) found; run again with --apply to fix what can be fixed automatically"
fi
