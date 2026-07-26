#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$ARGV1" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/actikamera.json" "$ARGV1/actikamera.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/actikamera.log" "$ARGV1/actikamera.log" 2>/dev/null
exit 0
