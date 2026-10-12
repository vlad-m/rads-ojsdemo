#!/usr/bin/env bash
#
# Install the demo kit into an OJS 3.5 installation.
#
#   scripts/deploy.sh /path/to/ojs [options]
#
# Options:
#   --seed              fill the installation with demo content (seed/seed.php)
#   --journal=PATH      with --seed: journal to fill (default: jir); an existing
#                       journal is reused and its settings replaced by the demo ones
#   --force             with --seed: delete and recreate the journal
#   --demo              enable read-only demo mode in config.inc.php
#   --superuser=NAME    username that may still change the system (default: first admin)
#   --password=PASS     password of the demo accounts (default: demo)
#   --reset-hours=N     shown in the demo banner (default: 3)
#   --harden            apply scripts/harden.sh (htaccess, permissions, config)
#   --snapshot          take a demo snapshot afterwards (scripts/demo-snapshot.sh)
#
# Typical first run:
#   scripts/deploy.sh ~/ojsdemo_new --seed --demo --harden --snapshot
#
# shellcheck source=lib/common.sh
source "$(dirname "$0")/lib/common.sh"

SEED=0; FORCE=0; DEMO=0; HARDEN=0; SNAPSHOT=0
SUPERUSER=''; PASSWORD='demo'; RESET_HOURS=3; JOURNAL=''
ARGS=()
for arg in "$@"; do
	case "$arg" in
		--seed) SEED=1 ;;
		--force) FORCE=1 ;;
		--demo) DEMO=1 ;;
		--harden) HARDEN=1 ;;
		--snapshot) SNAPSHOT=1 ;;
		--superuser=*) SUPERUSER="${arg#*=}" ;;
		--password=*) PASSWORD="${arg#*=}" ;;
		--reset-hours=*) RESET_HOURS="${arg#*=}" ;;
		--journal=*) JOURNAL="${arg#*=}" ;;
		-h|--help) sed -n '2,24p' "$0"; exit 0 ;;
		-*) die "Unknown option: $arg" ;;
		*) ARGS+=("$arg") ;;
	esac
done
require_ojs "${ARGS[0]:-}"

VERSION="$(ojs_version)"
step "OJS $VERSION at $OJS_DIR"
case "$VERSION" in
	3.5.*) ok "supported version" ;;
	*) warn "this kit is built and tested for OJS 3.5.x; continuing anyway" ;;
esac

step "Plugins"
for plugin in themes/academicJournal generic/demoMode generic/ruLocaleCompletion; do
	src="$REPO_DIR/plugins/$plugin"
	dst="$OJS_DIR/plugins/$plugin"
	mkdir -p "$dst"
	if command -v rsync >/dev/null 2>&1; then
		rsync -a --delete "$src/" "$dst/"
	else
		rm -rf "$dst" && cp -R "$src" "$dst"
	fi
	(cd "$OJS_DIR" && "$PHP" lib/pkp/tools/installPluginVersion.php "plugins/$plugin/version.xml" >/dev/null)
	ok "$plugin"
done

step "Translations"
# Interface languages: journal.json uiLocales (installed by --seed)
LOCALES="$("$PHP" -r '$j = json_decode(file_get_contents($argv[1]), true); echo implode(" ", $j["journal"]["uiLocales"] ?? ["ru", "en"]);' "$REPO_DIR/seed/content/journal.json")"
# shellcheck disable=SC2086
"$PHP" "$REPO_DIR/scripts/lib/build-locale-fallback.php" "$OJS_DIR" "$OJS_DIR/plugins/generic/ruLocaleCompletion/fallback" $LOCALES

if [ "$DEMO" = 1 ]; then
	step "Demo mode (config.inc.php)"
	# Keep the superuser chosen on a previous run
	[ -n "$SUPERUSER" ] || SUPERUSER="$(cfg demo superusers)"
	if [ -z "$SUPERUSER" ]; then
		# first site administrator that is not one of the demo accounts
		SUPERUSER="$(cd "$OJS_DIR" && "$PHP" -r '
			$argv = ["x"]; require "tools/bootstrap.php";
			foreach (APP\facades\Repo::user()->getAdminUsers() as $u) {
				if (!preg_match("/^demo(-|$)/", $u->getUsername())) { echo $u->getUsername(); break; }
			}' 2>/dev/null | tail -1)"
		[ -n "$SUPERUSER" ] || die "Cannot detect the site administrator; pass --superuser=NAME."
	fi
	case ",$SUPERUSER," in *,demo,*|*,demo-editor,*|*,demo-reviewer,*|*,demo-author,*) die "The superuser must not be a demo account (got: $SUPERUSER); pass --superuser=NAME." ;; esac
	cfg_set demo enabled On
	cfg_set demo superusers "\"$SUPERUSER\""
	cfg_set demo password "\"$PASSWORD\""
	cfg_set demo accounts '"demo,demo-editor,demo-reviewer,demo-author"'
	cfg_set demo reset_hours "$RESET_HOURS"
	# Never send real e-mail from a public demo
	cfg_set email default log
	ok "superuser: $SUPERUSER (only this account can change the system)"
fi

if [ "$SEED" = 1 ]; then
	step "Demo content"
	seed_args=("$OJS_DIR" "--demo-password=$PASSWORD")
	[ "$FORCE" = 1 ] && seed_args+=(--force)
	[ -n "$JOURNAL" ] && seed_args+=("--journal=$JOURNAL")
	"$PHP" "$REPO_DIR/seed/seed.php" "${seed_args[@]}"
fi

step "Cache"
clear_cache
ok "compiled templates and CSS cleared"

if [ "$HARDEN" = 1 ]; then
	"$REPO_DIR/scripts/harden.sh" "$OJS_DIR" --apply --noindex
fi

if [ "$SNAPSHOT" = 1 ]; then
	"$REPO_DIR/scripts/demo-snapshot.sh" "$OJS_DIR"
fi

step "Done"
info "  Theme: Settings > Website > Appearance > Theme = «Академический журнал» (set automatically by --seed)"
[ "$DEMO" = 1 ] && info "  Demo accounts: demo, demo-editor, demo-reviewer, demo-author (password: $PASSWORD)"
exit 0
