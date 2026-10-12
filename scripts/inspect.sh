#!/usr/bin/env bash
#
# Print a short, password-free description of the server and the OJS
# installation (useful before deploying or when asking for help).
#
#   scripts/inspect.sh /path/to/ojs
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"
require_ojs "${1:-}"

step "Server"
info "  host:        $(hostname 2>/dev/null || echo '?')"
info "  user:        $(id -un) (uid $(id -u))"
info "  PHP CLI:     $("$PHP" -r 'echo PHP_VERSION;') ($(command -v "$PHP"))"
info "  extensions:  $("$PHP" -m | grep -ciE '^(bcmath|curl|gd|intl|mbstring|mysqli|pdo_mysql|xml|zip|fileinfo|gettext)$')/11 required present"
for ext in bcmath curl gd intl mbstring mysqli pdo_mysql xml zip fileinfo gettext; do
	"$PHP" -m | grep -qi "^$ext$" || warn "missing PHP extension: $ext"
done
for tool in mysql mysqldump curl rsync flock gzip tar crontab git; do
	command -v "$tool" >/dev/null 2>&1 && ok "$tool" || warn "$tool not found"
done

step "OJS"
info "  directory:   $OJS_DIR"
info "  version:     $(ojs_version)"
info "  base_url:    $(cfg general base_url)"
info "  installed:   $(cfg general installed)"
info "  files_dir:   $(files_dir)"
info "  db driver:   $(cfg database driver) / host $(cfg database host) / name $(cfg database name)"
info "  email:       $(cfg email default)"
info "  demo mode:   $(cfg demo enabled) (superusers: $(cfg demo superusers))"
info "  disk usage:  $(du -sh "$OJS_DIR" 2>/dev/null | cut -f1) code, $(du -sh "$(files_dir)" 2>/dev/null | cut -f1) files"
