#!/usr/bin/env bash
# ============================================================
#  Vendor third-party front-end assets
# ------------------------------------------------------------
#  The dashboard charts are drawn by Chart.js, and by default it
#  is fetched from a CDN. A script loaded from someone else's
#  server runs with the full authority of the page that loaded
#  it: whoever controls that host can read every figure on the
#  dashboard and every token in the DOM.
#
#  This downloads it once, verifies it, and puts it in
#  assets/vendor/. After that:
#
#    * the dashboard serves it from this origin
#    * the content security policy stops naming any external host
#    * the dashboard works with no internet connection at all
#
#  Run from the project root:  bash deploy/vendor-assets.sh
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$ROOT/assets/vendor"
VERSION="4.4.1"
URL="https://cdn.jsdelivr.net/npm/chart.js@${VERSION}/dist/chart.umd.min.js"

mkdir -p "$DEST"

echo "Fetching Chart.js ${VERSION}…"
if ! curl -fsSL --proto '=https' --tlsv1.2 -o "$DEST/chart.umd.min.js.tmp" "$URL"; then
    echo "  Could not download it. If this machine has no outbound internet"
    echo "  access, fetch the file elsewhere and copy it to:"
    echo "    $DEST/chart.umd.min.js"
    rm -f "$DEST/chart.umd.min.js.tmp"
    exit 1
fi

# A truncated download is a broken dashboard, and a redirect to an
# error page is a file full of HTML. Both are caught here.
BYTES=$(wc -c < "$DEST/chart.umd.min.js.tmp")
if [ "$BYTES" -lt 100000 ]; then
    echo "  That is only ${BYTES} bytes — not Chart.js. Refusing to install it."
    rm -f "$DEST/chart.umd.min.js.tmp"
    exit 1
fi
if head -c 200 "$DEST/chart.umd.min.js.tmp" | grep -qi '<!doctype\|<html'; then
    echo "  That is an HTML page, not a script. Refusing to install it."
    rm -f "$DEST/chart.umd.min.js.tmp"
    exit 1
fi

mv "$DEST/chart.umd.min.js.tmp" "$DEST/chart.umd.min.js"
chmod 644 "$DEST/chart.umd.min.js"

SRI="sha384-$(openssl dgst -sha384 -binary "$DEST/chart.umd.min.js" | openssl base64 -A)"

cat <<EOF

Installed: assets/vendor/chart.umd.min.js  (${BYTES} bytes)

The dashboard will now load it from this server, and the content
security policy no longer allows any external script host. Nothing
else to configure.

If you ever go back to the CDN, pin it by putting this in .env so
the browser refuses a substituted file:

    CHART_JS_SRI=${SRI}

EOF
