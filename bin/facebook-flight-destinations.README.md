# Facebook flight-arc destinations

Facebook's data export records the airport you checked in at, but **not** the
"flying to" destination its travel feature showed. `facebook-flight-destinations.json`
supplies those destinations by hand (reconstructed from geo-tagged posts in the
archive). Each entry is the flight's destination; the departure is the post's own
check-in venue.

Entries are keyed by the check-in's **Facebook source URL**
(`nop_indieweb_source_url`, e.g. `.../tagged-place/<fbid>`) — a stable identifier
that resolves to the correct post on any site, since WordPress post IDs differ
between local and production. The command also accepts a numeric post-ID key. The
`note` field is human reference only and is ignored.

Run after importing the Facebook check-ins:

    wp nop-indieweb backfill-flight-arcs --service=facebook \
      --map=wp-content/plugins/nop-indieweb/bin/facebook-flight-destinations.json --dry-run

Drop `--dry-run` to write. The command also auto-pairs any two airport check-ins
made within 24h (e.g. long-haul trips where both ends were checked in), so those
need no entry here. The dry-run prints the resolved venue → destination for each,
so you can confirm the matches before writing.
