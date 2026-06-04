#!/usr/bin/env bash
# Asambleaza frontend-ul static pentru deploy pe Cloudflare (binding ASSETS).
# Copiaza partile statice din ../depanauto in ./public/depanauto si rescrie
# API_BASE absolut -> relativ (same-origin), ca sa mearga pe orice domeniu.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/../depanauto"
DST="$HERE/public/depanauto"

rm -rf "$DST"
mkdir -p "$DST"

for d in client depanator admin istoric legal icons; do
  [ -d "$SRC/$d" ] && cp -r "$SRC/$d" "$DST/"
done
[ -f "$SRC/fcm-init.js" ] && cp "$SRC/fcm-init.js" "$DST/"
[ -f "$SRC/index.html" ] && cp "$SRC/index.html" "$DST/"

# API_BASE: din URL absolut hardcodat -> cale relativa same-origin
while IFS= read -r f; do
  sed -i 's#https://wsdlogistics.ro/depanauto/api#/depanauto/api#g' "$f"
done < <(grep -rl "https://wsdlogistics.ro/depanauto/api" "$DST" || true)

echo "Build OK -> $DST"
