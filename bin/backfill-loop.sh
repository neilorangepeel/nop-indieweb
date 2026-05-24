#!/bin/bash
# Runs a backfill command in small batches until complete.
# Usage: bash bin/backfill-loop.sh <command> [--limit=N]
#   e.g. bash bin/backfill-loop.sh backfill-weather --limit=15
#        bash bin/backfill-loop.sh backfill-checkin-maps --limit=15

SITE_DIR="$(cd "$(dirname "$0")/../../../.." && pwd)"
CMD="${1:-backfill-weather}"
LIMIT="${2:---limit=15}"
BATCH=0

cd "$SITE_DIR" || { echo "Could not cd to $SITE_DIR"; exit 1; }

echo "Starting $CMD loop (${LIMIT}) from $SITE_DIR"
echo "---"

while true; do
    BATCH=$(( BATCH + 1 ))
    echo "[Batch $BATCH] $(date '+%H:%M:%S')"

    OUTPUT=$(studio wp nop-indieweb "$CMD" "$LIMIT" 2>&1)
    CLEAN=$(echo "$OUTPUT" | sed 's/\x1b\[[0-9;]*m//g')

    # Print meaningful lines only
    echo "$CLEAN" | grep -E "✓|✗|Success:|Found |--limit reached|Error" || true

    # Done when 0 enriched/generated
    if echo "$CLEAN" | grep -qE "Success: 0 (enriched|generated)"; then
        echo "---"
        echo "Done! $CMD complete after $BATCH batch(es)."
        break
    fi

    # Abort on unexpected errors (not timeout)
    if echo "$CLEAN" | grep -qE "Error:" && ! echo "$CLEAN" | grep -q "Timeout"; then
        echo "Error detected — stopping. Check output above."
        break
    fi

    sleep 2
done
