#!/usr/bin/env bash
# Usage: bash tools/verify_share_preview.sh https://balamod.store/login.php
# Fetches the page like a crawler (no cookies, no JavaScript), checks the <head> tags and the og:image response.
URL="${1:-https://balamod.store/login.php}"
UA="WhatsApp/2.23.20.0 A"
html="$(curl -sL -A "$UA" --max-time 20 "$URL")" || { echo "FAILED to fetch page"; exit 1; }
echo "== Raw <head> checks for $URL"
for k in 'name="description"' 'rel="canonical"' 'property="og:type"' 'property="og:url"' 'property="og:title"' 'property="og:description"' \
         'property="og:image"' 'property="og:image:secure_url"' 'property="og:image:type"' 'property="og:image:width"' 'property="og:image:height"' \
         'property="og:site_name"' 'name="twitter:card"' 'name="twitter:title"' 'name="twitter:description"' 'name="twitter:image"' 'rel="icon"'; do
  line="$(printf '%s' "$html" | grep -o "<[^>]*$k[^>]*>" | head -1)"
  [ -n "$line" ] && echo "OK   $line" || echo "MISSING  $k"
done
img="$(printf '%s' "$html" | grep -o '<meta property="og:image" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"$//')"
echo; echo "== og:image: $img"
case "$img" in https://*) echo "OK   absolute https";; *) echo "FAIL not an absolute https URL";; esac
curl -sL -A "$UA" -o /tmp/_og_img --max-time 20 -w "HTTP %{http_code}  Content-Type: %{content_type}  size: %{size_download} bytes\n" "$img"
command -v file >/dev/null && file /tmp/_og_img
echo "(Expect HTTP 200, image/jpeg, JPEG 1200x630, under ~300 KB. Then test sharing with a fresh URL such as ${URL}?v=2)"
