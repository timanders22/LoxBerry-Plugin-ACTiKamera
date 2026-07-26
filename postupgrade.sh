#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
[ -f "$ARGV1/actikamera.json" ] && cp -p "$ARGV1/actikamera.json" "$BASE/config/plugins/$PFOLDER/actikamera.json"
[ -f "$ARGV1/actikamera.log" ] && cp -p "$ARGV1/actikamera.log" "$BASE/log/plugins/$PFOLDER/actikamera.log"
BK="$BASE/config/plugins/$PFOLDER.backup.json"; CF="$BASE/config/plugins/$PFOLDER/actikamera.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then cp -p "$BK" "$CF"; fi
fi
exit 0
