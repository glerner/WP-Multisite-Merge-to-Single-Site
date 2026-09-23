#!/usr/bin/env bash
# Generate an EDITABLE bash file that recreates publish_future_post
# cron events for posts that arrived on the destination with
# post_status=future.
#
# Cron events live in the 'cron' option and do NOT travel with the
# posts table, so migrated future posts would otherwise never publish.
#
# Usage (run against the DESTINATION, after migration):
#   bin/schedule-future-posts.sh --url=https://dest.example.com
#   WP="lando wp" bin/schedule-future-posts.sh --url=...
#
# It writes var/reports/schedule-future-posts-<timestamp>.sh with one
# line per post. REVIEW THE FILE BEFORE RUNNING IT:
#   - delete lines for posts that should stay unpublished
#   - a post whose post_date has ALREADY PASSED publishes on the next
#     cron run (wp-cron fires any event whose time is <= now)
#
# To review the site's cron afterwards:
#   wp cron event list --url=... | grep publish_future_post
#   wp cron event delete publish_future_post --url=...

set -euo pipefail

WP="${WP:-wp}"
OUT_DIR="$(cd "$(dirname "$0")/.." && pwd)/var/reports"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="$OUT_DIR/schedule-future-posts-$STAMP.sh"
URL_ARGS="$*"

mkdir -p "$OUT_DIR"

{
	echo '#!/usr/bin/env bash'
	echo '# Re-schedule publish_future_post for migrated posts (post_status=future).'
	echo "# Generated $STAMP against: $WP $URL_ARGS"
	echo '#'
	echo '# EDIT BEFORE RUNNING: delete any line for a post that should stay'
	echo '# unpublished. A post whose date already passed publishes on the'
	echo '# next cron run.'
	echo 'set -euo pipefail'
	echo "WP=\"${WP}\""
	echo ''
} > "$OUT"

COUNT=0
# "IFS=$'\t' " sets the Internal Field Separator to tab (\t) for the read command
# Tab-separated values from wp eval -- a post_title can contain commas, which would
# corrupt naive CSV parsing.
# Each echoed line is like 123<TAB>Coming Soon<TAB>2026-10-01 14:00:00.
while IFS=$'\t' read -r id title gmt; do
	case "$gmt" in
		0000* | "")
			echo "# post $id \"$title\": SKIPPED (no post_date_gmt)" >> "$OUT"
			continue
			;;
	esac
	{
		echo "# post $id \"$title\" -- scheduled for $gmt UTC"
		echo "\$WP eval 'wp_clear_scheduled_hook( \"publish_future_post\", array( $id ) ); wp_schedule_single_event( strtotime( \"$gmt UTC\" ), \"publish_future_post\", array( $id ) );' $URL_ARGS"
	} >> "$OUT"
	COUNT=$(( COUNT + 1 ))
done < <( $WP eval 'foreach ( get_posts( array( "post_status" => "future", "post_type" => "any", "posts_per_page" => -1 ) ) as $p ) { echo $p->ID . "\t" . str_replace( "\t", " ", $p->post_title ) . "\t" . $p->post_date_gmt . "\n"; }' "$@" )
# Previous line: "< <(...)" is process substitution — the loop reads the wp eval output line-by-line without a subshell, so COUNT increments survive outside the loop.

chmod +x "$OUT"
echo "Wrote $OUT ($COUNT event(s)). Review it, then run: bash $OUT"
