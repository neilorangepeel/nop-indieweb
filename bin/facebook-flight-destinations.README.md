# Facebook flight-arc destinations

Facebook's data export records the airport you checked in at, but **not** the
"flying to" destination its travel feature showed. `facebook-flight-destinations.json`
supplies those destinations by hand (reconstructed from geo-tagged posts in the
archive), keyed by the WordPress post ID the arc renders on. Each entry is the
flight's destination; the departure is the post's own check-in venue.

Run after importing the Facebook check-ins:

    wp nop-indieweb backfill-flight-arcs --service=facebook \
      --map=wp-content/plugins/nop-indieweb/bin/facebook-flight-destinations.json --dry-run

Drop `--dry-run` to write. The command also auto-pairs any two airport check-ins
made within 24h (e.g. long-haul trips where both ends were checked in), so those
need no entry here. Post IDs are environment-specific — verify they match before
running on another site (the dry-run prints venue → destination for each).
