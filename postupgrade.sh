#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
[ -f "$ARGV1/cam.json" ] && cp -p "$ARGV1/cam.json" "$BASE/config/plugins/$PFOLDER/cam.json"
[ -f "$ARGV1/cam.log" ] && cp -p "$ARGV1/cam.log" "$BASE/log/plugins/$PFOLDER/cam.log"
BK="$BASE/config/plugins/$PFOLDER.backup.json"; CF="$BASE/config/plugins/$PFOLDER/cam.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; fi
fi
exit 0
