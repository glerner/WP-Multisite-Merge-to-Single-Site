#!/usr/bin/env bash
# Generate an EDITABLE bash file that retags a site's stale
# wp_template / wp_template_part rows to the active theme.
#
# A stale row is a template still owned by a dormant theme
# (post_name "oldtheme//slug", wp_theme term = oldtheme) while the
# site runs another theme. Pages that resolve to it render nothing.
# Retagging the ROW -- not editing each page -- fixes every page that
# resolves to that slug at once:
#   1. post_name  oldtheme//slug  ->  activetheme//slug
#   2. its wp_theme term relationship -> the active theme's term
#   3. _wp_page_template meta rows that name the old slug explicitly
#
# Usage (run against the SOURCE site being repaired):
#   bin/retag-templates.sh --url=https://site.example.com
#   WP="lando wp" bin/retag-templates.sh --url=...
#
# It writes var/reports/retag-templates-<timestamp>.sh with one line
# per stale template row. REVIEW THE FILE BEFORE RUNNING IT -- each
# row is annotated:
#   CONFIRMED PRESENT : an activetheme//slug row already exists, so
#                       retagging creates a duplicate slug -- decide
#                       which template should win (or delete the line
#                       and keep the active-theme row).
#   UNCONFIRMED       : no activetheme//slug row exists, so this row
#                       becomes the live template for that slug.
# The script decides nothing; check the affected pages yourself.
#
# wp_global_styles rows are reported as comments only -- they are
# dormant theme customizations, not page templates.

set -euo pipefail

WP="${WP:-wp}"
OUT_DIR="$(cd "$(dirname "$0")/.." && pwd)/var/reports"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$OUT_DIR/retag-templates-$STAMP.sh"
URL_ARGS="$*"

mkdir -p "$OUT_DIR"

# The retag target: the site's ACTIVE theme (stylesheet option).
ACTIVE="$( $WP eval 'echo get_option( "stylesheet" );' "$@" )"
if [[ -z "$ACTIVE" ]]; then
	echo "Could not read the stylesheet option -- check the wp-cli args." >&2
	exit 1
fi

{
	echo '#!/usr/bin/env bash'
	echo "# Retag stale wp_template/wp_template_part rows to theme '$ACTIVE'."
	echo "# Generated $STAMP against: $WP $URL_ARGS"
	echo '#'
	echo '# EDIT BEFORE RUNNING: delete any line for a row that should stay'
	echo '# dormant. Annotations per row:'
	echo '#   CONFIRMED PRESENT - an '"$ACTIVE"'//slug row already exists;'
	echo '#     retagging makes a duplicate slug. Pick a winner first.'
	echo '#   UNCONFIRMED - no '"$ACTIVE"'//slug row exists; this row becomes'
	echo '#     the live template for that slug.'
	echo '# The "_wp_page_template refs" count is only pages that picked the'
	echo '# template explicitly; pages resolving via the template hierarchy'
	echo '# (slug fallback) are not counted but are fixed by the same retag.'
	echo 'set -euo pipefail'
	echo "WP=\"${WP}\""
	echo ''
} > "$OUT"

COUNT=0
# Tab-separated from wp eval (see schedule-future-posts.sh): each line is
# id<TAB>post_type<TAB>old-post_name<TAB>active-exists(0/1)<TAB>meta-ref-count.
while IFS=$'\t' read -r id type name exists refs; do
	slug="${name#*//}"
	new="$ACTIVE//$slug"

	{
		echo "# post $id \"$name\" ($type) -> \"$new\""
		if [[ "$exists" -gt 0 ]]; then
			echo "#   CONFIRMED PRESENT: a $new row already exists -- retagging"
			echo "#   makes a duplicate slug; decide which should win first."
		else
			echo "#   UNCONFIRMED: no $new row exists -- this row becomes the live"
			echo "#   template for slug \"$slug\"."
		fi
		echo "#   _wp_page_template refs to \"$name\": $refs"
		# shellcheck disable=SC2016 # the eval body must not be expanded here.
		echo "\$WP eval 'global \$wpdb; \$t = get_term_by( \"name\", \"$ACTIVE\", \"wp_theme\" ); if ( ! \$t ) { \$r = wp_insert_term( \"$ACTIVE\", \"wp_theme\" ); \$t = is_wp_error( \$r ) ? null : (object) \$r; } if ( ! \$t ) { fwrite( STDERR, \"no wp_theme term for $ACTIVE\n\" ); exit( 1 ); } \$wpdb->update( \$wpdb->posts, array( \"post_name\" => \"$new\" ), array( \"ID\" => $id ) ); foreach ( wp_get_object_terms( $id, \"wp_theme\", array( \"fields\" => \"tt_ids\" ) ) as \$tt ) { \$wpdb->update( \$wpdb->term_relationships, array( \"term_taxonomy_id\" => \$t->term_taxonomy_id ), array( \"object_id\" => $id, \"term_taxonomy_id\" => \$tt ) ); } \$wpdb->query( \$wpdb->prepare( \"UPDATE {\$wpdb->postmeta} SET meta_value = %s WHERE meta_key = %s AND meta_value = %s\", \"$new\", \"_wp_page_template\", \"$name\" ) );' $URL_ARGS"
		echo ''
	} >> "$OUT"
	COUNT=$(( COUNT + 1 ))
done < <( $WP eval 'global $wpdb; $a = get_option( "stylesheet" ); foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_name FROM {$wpdb->posts} WHERE post_type IN ( %s, %s ) AND post_name LIKE %s AND post_name NOT LIKE %s ORDER BY post_type, post_name", "wp_template", "wp_template_part", "%//%", $a . "//%" ) ) as $p ) { $slug = substr( $p->post_name, strpos( $p->post_name, "//" ) + 2 ); $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s", $p->post_type, $a . "//" . $slug ) ); $refs = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", "_wp_page_template", $p->post_name ) ); echo $p->ID . "\t" . $p->post_type . "\t" . $p->post_name . "\t" . $exists . "\t" . $refs . "\n"; }' "$@" )
# Previous line: "< <(...)" is process substitution -- the loop reads the
# wp eval output line-by-line without a subshell, so COUNT survives.

# Dormant global-styles rows are theme-scoped customizations, not page
# templates -- reported as comments so the owner knows they exist.
while IFS=$'\t' read -r gid gname; do
	{
		echo "# note: wp_global_styles post $gid \"$gname\" is dormant"
		echo "#   theme data, not a template -- NOT retagged by this script."
		echo ''
	} >> "$OUT"
done < <( $WP eval 'global $wpdb; $a = get_option( "stylesheet" ); foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = %s AND post_name <> %s ORDER BY post_name", "wp_global_styles", "wp-global-styles-" . $a ) ) as $g ) { echo $g->ID . "\t" . $g->post_name . "\n"; }' "$@" )

chmod +x "$OUT"
echo "Wrote $OUT ($COUNT template row(s)). Review it, then run: bash $OUT"
