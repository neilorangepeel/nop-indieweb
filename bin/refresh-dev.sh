#!/bin/bash
# Copies neilorangepeel.com (subsite 6) onto dev.neilorangepeel.com (subsite 28).
# Live is only read. Dev's own posts and settings are replaced.
# Run from the Mac:  ssh neil.rocks 'bash -s' < bin/refresh-dev.sh
#
# Copies the core WordPress tables only: no Jetpack CRM contacts, security logs
# or IndieAuth tokens. Media is hard-linked (no extra disk space; deleting on
# dev never touches live). Syndication credentials are stripped on dev.
set -euo pipefail

LIVE=neilorangepeel.com
DEV=dev.neilorangepeel.com
WP=~/www/neil.rocks/public_html
cd "$WP"
P=$(wp db prefix)
L="${P}6_"
D="${P}28_"
DUMP=~/refresh-dev.sql
q() { wp db query "$1" --skip-column-names 2>/dev/null; }

[ "$(wp site list --field=blog_id --domain=$LIVE)" = 6 ] || { echo "STOP: live isn't subsite 6" >&2; exit 1; }
[ "$(wp site list --field=blog_id --domain=$DEV)" = 28 ] || { echo "STOP: dev isn't subsite 28" >&2; exit 1; }

echo "== Keep dev's own plugins"
DEV_PLUGINS=$(q "SELECT option_value FROM ${D}options WHERE option_name='active_plugins'")
case "$DEV_PLUGINS" in *nop-indieweb-dev*) ;; *) echo "STOP: dev's plugin list looks wrong: $DEV_PLUGINS" >&2; exit 1 ;; esac
DEV_THEME=$(q "SELECT option_value FROM ${D}options WHERE option_name='stylesheet'")

echo "== Copy live's tables"
TABLES=""
for t in commentmeta comments links options postmeta posts term_relationships term_taxonomy termmeta terms; do
  TABLES="$TABLES,${L}$t"
done
wp db export "$DUMP" --tables="${TABLES#,}" --quiet
sed -i "s/\`${L}/\`${D}/g" "$DUMP"
if grep -q "\`${L}" "$DUMP"; then echo "STOP: dump still names live tables" >&2; exit 1; fi
wp db import "$DUMP" --quiet
rm "$DUMP"
q "UPDATE ${D}options SET option_name='${D}user_roles' WHERE option_name='${L}user_roles'"
# Before WordPress loads for dev again, so live's plugins (Jetpack) never run there.
q "UPDATE ${D}options SET option_value='${DEV_PLUGINS}' WHERE option_name='active_plugins'"
q "UPDATE ${D}options SET option_value='${DEV_THEME}' WHERE option_name IN ('template','stylesheet')"
q "UPDATE ${D}options SET option_value='DEV — Neil Orange Peel' WHERE option_name='blogname'"
q "UPDATE ${D}options SET option_value='0' WHERE option_name='blog_public'"
# The object cache still holds dev's old options; without this, WordPress
# compares against stale values and skips or clobbers writes.
wp cache flush --url=$DEV 2>/dev/null

echo "== Media (hard links)"
mkdir -p wp-content/uploads/sites/28
cp -aln wp-content/uploads/sites/6/. wp-content/uploads/sites/28/

echo "== Addresses"
wp search-replace "https://$LIVE" "https://$DEV" "${D}*" --url=$DEV --skip-columns=guid --report-changed-only
wp search-replace "uploads/sites/6/" "uploads/sites/28/" "${D}*" --url=$DEV --skip-columns=guid --report-changed-only

echo "== Dev settings back"
wp option update home "https://$DEV" --url=$DEV
wp option update siteurl "https://$DEV" --url=$DEV
wp option delete cron --url=$DEV || true
# Site Editor changes and theme mods belong to the theme by slug: carry live's
# nop ones to nop-dev (only when dev runs nop-dev; Orange Peel keeps its own).
[ "$DEV_THEME" = nop-dev ] && q "UPDATE ${D}terms t JOIN ${D}term_taxonomy tt ON tt.term_id=t.term_id SET t.slug='nop-dev', t.name='nop-dev' WHERE tt.taxonomy='wp_theme' AND t.slug='nop'"
if [ "$DEV_THEME" = nop-dev ]; then
  q "DELETE FROM ${D}options WHERE option_name='theme_mods_nop-dev'"
  q "UPDATE ${D}options SET option_name='theme_mods_nop-dev' WHERE option_name='theme_mods_nop'"
fi

echo "== Strip syndication credentials"
wp eval '
$s = get_blog_option( 6, "nop_indieweb_settings", [] );
foreach ( $s["syndicators"] ?? [] as $slug => $c ) {
	$c["enabled"] = false;
	$c["import_enabled"] = false;
	foreach ( [ "access_token", "app_password", "consumer_key", "consumer_secret", "refresh_token" ] as $k ) {
		unset( $c[ $k ] );
	}
	$s["syndicators"][ $slug ] = $c;
}
update_option( "nop_indieweb_settings", $s );
echo count( $s ), " settings groups copied, credentials stripped\n";
' --url=$DEV

echo "== Check"
wp rewrite flush --url=$DEV
wp cache flush --url=$DEV 2>/dev/null || true
# A full flush doesn't always clear dev's options cache; delete it directly.
wp cache delete alloptions options --url=$DEV 2>/dev/null || true
wp cache delete notoptions options --url=$DEV 2>/dev/null || true
wp sg purge --url=$DEV 2>/dev/null | tail -1 || true
echo "dev posts: $(wp post list --post_type=post --format=count --url=$DEV)  live posts: $(wp post list --post_type=post --format=count --url=$LIVE)"
wp eval 'var_dump( \NOP\IndieWeb\nop_indieweb_is_production() );' --url=$DEV
q "SELECT option_name, option_value FROM ${D}options WHERE option_name IN ('blogname','blog_public')"
curl -sS -o /dev/null -w "dev: %{http_code}\n" "https://$DEV/"
curl -sS -o /dev/null -w "live: %{http_code}\n" "https://$LIVE/"
