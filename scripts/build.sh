#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT_DIR/dist"
MOD="moovenipakstatus"
rm -rf "$DIST"
mkdir -p "$DIST/$MOD"
rsync -a "$ROOT_DIR/" "$DIST/$MOD/" \
  --exclude ".git/" \
  --exclude ".github/" \
  --exclude "dist/" \
  --exclude "build/" \
  --exclude "*.zip"
cd "$DIST"
zip -r "$MOD.zip" "$MOD"
echo "Built: $DIST/$MOD.zip"