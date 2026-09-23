#!/usr/bin/env bash
# Snapshot config/ to ~/backups/merge-multisite/config-<timestamp>/
# Run occasionally; keeps each backup so older configs aren't lost.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)/config"
DEST="$HOME/backups/merge-multisite"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$DEST"
cp -a "$SRC" "$DEST/config-$STAMP"
echo "Backed up $SRC -> $DEST/config-$STAMP"
